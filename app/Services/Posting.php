<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Accounting\JournalLine;
use App\Domain\Accounting\JournalValidator;
use App\Domain\Finance\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

final class Posting
{
    public static function decimal(string|int $value): BigDecimal
    {
        return BigDecimal::of((string) $value);
    }

    public static function cents(BigDecimal|string $value): string
    {
        return (string) self::decimal((string) $value)->toScale(2, RoundingMode::HALF_UP);
    }

    public function once(string $scope, array $data, callable $work): array
    {
        $key = $data['operation_key'] ?? null;
        if (! is_string($key) || strlen($key) < 8 || strlen($key) > 100) {
            throw new \DomainException('Çelësi i veprimit është i detyrueshëm.');
        }
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($scope, $key, $hash, $work) {
            DB::table('idempotency_records')->insertOrIgnore(['scope' => $scope, 'operation_key' => $key, 'request_hash' => $hash, 'created_at' => now(), 'updated_at' => now()]);
            $r = DB::table('idempotency_records')->where('scope', $scope)->where('operation_key', $key)->lockForUpdate()->first();
            if ($r->request_hash !== $hash) {
                throw new \DomainException('Ky çelës është përdorur me të dhëna të tjera.');
            }
            if ($r->completed_at) {
                return json_decode($r->result, true, 512, JSON_THROW_ON_ERROR);
            }
            $result = $work();
            DB::table('idempotency_records')->where('id', $r->id)->update(['result' => json_encode($result, JSON_THROW_ON_ERROR), 'completed_at' => now(), 'updated_at' => now()]);

            return $result;
        }, 5);
    }

    public function journal(string $source, string|int $sourceId, string $date, string $description, array $rows, ?int $reverses = null): int
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Posting requires a database transaction.');
        }
        $periods = DB::table('accounting_periods')->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->lockForUpdate()->get();
        if ($periods->count() !== 1 || $periods->first()->status !== 'open') {
            throw new \DomainException('Data nuk i përket një periudhe të hapur kontabël.');
        }
        $prepared = [];
        $values = [];
        foreach ($rows as $row) {
            $debit = Money::of($row['debit'] ?? '0');
            $credit = Money::of($row['credit'] ?? '0');
            if ($debit->isZero() && $credit->isZero()) {
                continue;
            }
            $account = isset($row['account_id']) ? DB::table('accounts')->where('id', $row['account_id'])->where('active', true)->first() : DB::table('accounts')->where('code', $row['account'])->where('active', true)->first();
            if (! $account) {
                throw new \DomainException('Llogari kontabël e pavlefshme.');
            }
            $values[] = new JournalLine($account->code, $debit, $credit);
            $prepared[] = ['account_id' => $account->id, 'debit' => $debit->amount(), 'credit' => $credit->amount(), 'party_id' => $row['party_id'] ?? null, 'product_id' => $row['product_id'] ?? null, 'channel' => $row['channel'] ?? null, 'document_type' => $row['document_type'] ?? $source, 'document_id' => $row['document_id'] ?? (ctype_digit((string) $sourceId) ? (int) $sourceId : null)];
        }
        $totals = (new JournalValidator)->validate($values);
        $id = DB::table('journals')->insertGetId(['accounting_date' => $date, 'reference' => strtoupper($source).'-'.$sourceId, 'description' => $description, 'source_type' => $source, 'source_id' => (string) $sourceId, 'debit_total' => $totals->debits->amount(), 'credit_total' => $totals->credits->amount(), 'reverses_id' => $reverses, 'user_id' => auth()->id(), 'created_at' => now()]);
        foreach ($prepared as $row) {
            DB::table('journal_lines')->insert(['journal_id' => $id, ...$row]);
        }
        Audit::record('journal.posted', 'journal', $id, ['source' => $source, 'source_id' => $sourceId]);

        return $id;
    }

    public function configured(): array
    {
        $row = DB::table('settings')->where('key', 'accounting')->first();
        $settings = $row ? json_decode($row->value, true, 512, JSON_THROW_ON_ERROR) : [];
        if (! ($settings['configured'] ?? false)) {
            throw new \DomainException('Plotësoni konfigurimin tatimor dhe kontabël përpara veprimeve financiare.');
        }

        return $settings;
    }

    public function fiscalGate(int $saleId, array $payload = []): void
    {
        $sale = DB::table('sales')->where('id', $saleId)->first();
        if ($sale->fiscal_required && (! isset($payload['operation_key']) || ! DB::table('fiscal_documents')->where('sale_id', $saleId)->where('operation_key', $payload['operation_key'])->where('status', 'confirmed')->whereNotNull('external_reference')->exists())) {
            throw new \DomainException('Lëshimi fiskal kërkon konfirmim për këtë veprim nga ofruesi i autorizuar.');
        }
    }

    public function reverse(int $journalId, array $data): array
    {
        return $this->once('journal.reverse', $data + ['journal_id' => $journalId], function () use ($journalId, $data) {
            $original = DB::table('journals')->where('id', $journalId)->lockForUpdate()->first();
            if (! $original || $original->reverses_id || DB::table('journals')->where('reverses_id', $journalId)->exists()) {
                throw new \DomainException('Regjistrimi nuk mund të anulohet përsëri.');
            }
            if ($original->source_type !== 'manual') {
                throw new \DomainException('Korrigjoni dokumentin burimor përmes rrjedhës së tij.');
            }
            $rows = DB::table('journal_lines')->where('journal_id', $journalId)->get()->map(fn ($r) => ['account_id' => $r->account_id, 'debit' => $r->credit, 'credit' => $r->debit, 'party_id' => $r->party_id, 'channel' => $r->channel, 'product_id' => $r->product_id])->all();
            $id = $this->journal('reversal',$journalId,$data['accounting_date'],$data['reason'],$rows,$journalId);

            return ['id' => $id];
        });
    }
}
