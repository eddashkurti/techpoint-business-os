<?php

namespace App\Integrations\Fiscal;

use Illuminate\Support\Facades\DB;
use Throwable;

final class FiscalWorkflow
{
    public function __construct(private readonly FiscalProvider $provider) {}

    /** The payload freezes at preparation; corrections use a separate linked document. */
    public function prepare(int $saleId, array $payload, string $operationKey): int
    {
        if ($operationKey === '' || strlen($operationKey) > 128) {
            throw new FiscalIntegrationException('A stable fiscal operation key is required.');
        }
        $json = $this->canonicalPayload($payload);

        return DB::transaction(function () use ($saleId, $json, $operationKey): int {
            DB::table('fiscal_documents')->insertOrIgnore([
                'sale_id' => $saleId, 'operation_key' => $operationKey, 'provider' => $this->provider->name(),
                'status' => 'pending', 'request_payload' => $json, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('fiscal_documents')->where('operation_key', $operationKey)->lockForUpdate()->first();
            if ($row === null || (int) $row->sale_id !== $saleId
                || $this->canonicalPayload(json_decode($row->request_payload, true, 512, JSON_THROW_ON_ERROR)) !== $json
                || $row->provider !== $this->provider->name()) {
                throw new FiscalIntegrationException('Fiscal operation key already belongs to a different immutable request.');
            }

            return (int) $row->id;
        }, 3);
    }

    public function submit(int $documentId): object
    {
        if (config('techpoint.outbound_paused')) {
            throw new FiscalIntegrationException('Outbound operations are paused for recovery reconciliation.');
        }
        // This check precedes persisted submission: the default provider never simulates issuance.
        $this->provider->assertReady();
        [$request, $attemptId] = DB::transaction(function () use ($documentId): array {
            $row = DB::table('fiscal_documents')->where('id', $documentId)->lockForUpdate()->first();
            if ($row === null || $row->provider !== $this->provider->name()) {
                throw new FiscalIntegrationException('Fiscal document/provider was not found.');
            }
            if ($row->status !== 'pending') {
                throw new FiscalIntegrationException('This fiscal request cannot be resubmitted. Reconcile an unknown or submitted outcome first.');
            }
            DB::table('fiscal_documents')->where('id', $documentId)->update([
                'status' => 'submitted', 'submitted_at' => now(), 'error' => null, 'updated_at' => now(),
            ]);
            $attemptId = DB::table('fiscal_attempts')->insertGetId([
                'document_id' => $documentId, 'operation_key' => $row->operation_key,
                'status' => 'submitted', 'request_hash' => hash('sha256', $row->request_payload),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return [$this->request($row), $attemptId];
        }, 3);
        try {
            $result = $this->provider->submit($request);
        } catch (Throwable) {
            // An exception after dispatch may have lost the external confirmation, including a timeout.
            $result = new FiscalResult('outcome_unknown');
        }

        return $this->saveResult($documentId, $attemptId, $result);
    }

    public function reconcile(int $documentId): object
    {
        if (config('techpoint.outbound_paused')) {
            throw new FiscalIntegrationException('Outbound operations are paused for recovery reconciliation.');
        }
        $this->provider->assertReady();
        [$request, $attemptId] = DB::transaction(function () use ($documentId): array {
            $row = DB::table('fiscal_documents')->where('id', $documentId)->lockForUpdate()->first();
            if ($row === null || $row->provider !== $this->provider->name()) {
                throw new FiscalIntegrationException('Fiscal document/provider was not found.');
            }
            if (! in_array($row->status, ['submitted', 'outcome_unknown'], true)) {
                throw new FiscalIntegrationException('Only unresolved fiscal submissions require status reconciliation.');
            }
            $attemptId = DB::table('fiscal_attempts')->insertGetId([
                'document_id' => $documentId, 'operation_key' => $row->operation_key,
                'status' => 'lookup', 'request_hash' => hash('sha256', $row->request_payload),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return [$this->request($row), $attemptId];
        }, 3);
        try {
            $result = $this->provider->lookup($request);
        } catch (Throwable) {
            $result = new FiscalResult('outcome_unknown');
        }

        return $this->saveResult($documentId, $attemptId, $result);
    }

    private function saveResult(int $documentId, int $attemptId, FiscalResult $result): object
    {
        return DB::transaction(function () use ($documentId, $attemptId, $result): object {
            $row = DB::table('fiscal_documents')->where('id', $documentId)->lockForUpdate()->first();
            if (in_array($row->status, ['confirmed', 'rejected'], true)) {
                return $row;
            }
            $status = in_array($result->status, ['confirmed', 'rejected'], true) ? $result->status : 'outcome_unknown';
            $error = $status === 'outcome_unknown' ? 'External fiscal outcome is unresolved; blind retry is blocked.'
                : ($status === 'rejected' ? 'External fiscal provider rejected the document.' : null);
            DB::table('fiscal_documents')->where('id', $documentId)->update([
                'status' => $status, 'external_reference' => $result->reference,
                'confirmed_at' => $status === 'confirmed' ? now() : null, 'error' => $error, 'updated_at' => now(),
            ]);
            DB::table('fiscal_attempts')->where('id', $attemptId)->update([
                'status' => $status, 'response' => json_encode(['status' => $status, 'reference' => $result->reference,
                    'evidence' => $result->evidence], JSON_THROW_ON_ERROR),
                'error' => $error, 'updated_at' => now(),
            ]);

            return DB::table('fiscal_documents')->where('id', $documentId)->first();
        }, 3);
    }

    private function request(object $row): FiscalRequest
    {
        return new FiscalRequest((int) $row->id, $row->operation_key,
            json_decode($row->request_payload, true, 512, JSON_THROW_ON_ERROR));
    }

    private function canonicalPayload(array $payload): string
    {
        $normalize = function (array $value) use (&$normalize): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $normalize($item);
                }
            }

            return $value;
        };

        return json_encode($normalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
