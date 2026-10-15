<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Finance\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class CsvImportService
{
    public function __construct(private Posting $posting, private Finance $finance) {}

    /** @return array{id:int,rows:int} */
    public function import(string $kind, UploadedFile $file, array $options = []): array
    {
        $rows = $this->rows($file);
        if (count($rows) > 5000) {
            throw new \DomainException('Një import mund të përmbajë deri në 5,000 rreshta.');
        }
        $hash = hash_file('sha256', $file->getRealPath());
        if (! $hash) {
            throw new \DomainException('Skedari i importit nuk mund të lexohet.');
        }

        return DB::transaction(function () use ($kind, $file, $options, $rows, $hash) {
            if (DB::table('import_batches')->where('kind', $kind)->where('content_hash', $hash)->exists()) {
                throw new \DomainException('Ky skedar është importuar tashmë.');
            }
            $batch = DB::table('import_batches')->insertGetId(['kind' => $kind, 'filename' => $file->getClientOriginalName(), 'content_hash' => $hash, 'row_count' => count($rows), 'user_id' => auth()->id(), 'created_at' => now()]);
            match ($kind) {
                'products' => $this->products($rows),
                'customers' => $this->parties($rows, 'customer'),
                'suppliers' => $this->parties($rows, 'supplier'),
                'opening-balances' => $this->journals($rows, 'opening_balance', $batch),
                'historical-records' => $this->journals($rows, 'historical_import', $batch),
                'bank' => $this->bank($rows, $batch, $options),
                default => throw new \DomainException('Lloji i importit nuk njihet.'),
            };
            Audit::record('import.completed', 'import_batch', $batch, ['kind' => $kind, 'rows' => count($rows)]);

            return ['id' => $batch, 'rows' => count($rows)];
        }, 5);
    }

    /** @return array{headers:array<int,string>,rows:array<int,array<string,string>>,total:int} */
    public function preview(UploadedFile $file): array
    {
        $rows = $this->rows($file);
        if (count($rows) > 5000) {
            throw new \DomainException('Një import mund të përmbajë deri në 5,000 rreshta.');
        }

        return [
            'headers' => array_values(array_filter(array_keys($rows[0]), fn (string $key) => $key !== '_line')),
            'rows' => array_slice($rows, 0, 20),
            'total' => count($rows),
        ];
    }

    /** @return array<int,string> */
    public function templateColumns(string $kind): array
    {
        return match ($kind) {
            'products' => ['sku', 'name', 'unit', 'purchase_unit', 'unit_conversion', 'purchase_currency', 'purchase_price', 'selling_price', 'tax_rate', 'minimum_stock'],
            'customers', 'suppliers' => ['name', 'email', 'phone', 'country', 'tax_number', 'address'],
            'opening-balances', 'historical-records' => ['accounting_date', 'reference', 'description', 'account_code', 'debit', 'credit'],
            'bank' => ['date', 'external_reference', 'amount', 'description'],
            default => throw new \DomainException('Lloji i importit nuk njihet.'),
        };
    }

    /** @return array<int,array<string,string>> */
    private function rows(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if (! $handle) {
            throw new \DomainException('Skedari CSV nuk mund të hapet.');
        }
        $header = fgetcsv($handle, 0, ',', '"', '');
        if (! is_array($header)) {
            throw new \DomainException('Skedari CSV duhet të përmbajë një rresht titujsh.');
        }
        $header = array_map(fn ($value) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $value))), $header);
        if (count($header) !== count(array_unique($header)) || in_array('', $header, true)) {
            throw new \DomainException('Titujt e kolonave CSV nuk janë të vlefshëm.');
        }
        $rows = [];
        $line = 1;
        while (($record = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $line++;
            if ($record === [null] || $record === []) {
                continue;
            }
            if (count($record) !== count($header)) {
                throw new \DomainException("Rreshti {$line} nuk përputhet me kolonat e titullit.");
            }
            $row = array_combine($header, array_map(fn ($value) => trim((string) $value), $record));
            if ($row === false) {
                throw new \DomainException("Rreshti {$line} nuk mund të lexohet.");
            }
            $row['_line'] = (string) $line;
            $rows[] = $row;
        }
        fclose($handle);
        if ($rows === []) {
            throw new \DomainException('Skedari CSV nuk përmban rreshta për import.');
        }

        return $rows;
    }

    /** @param array<int,array<string,string>> $rows */
    private function products(array $rows): void
    {
        $this->requireColumns($rows, ['sku', 'name', 'unit', 'purchase_unit', 'unit_conversion', 'purchase_currency', 'purchase_price', 'selling_price', 'tax_rate', 'minimum_stock']);
        $seen = [];
        foreach ($rows as $row) {
            $sku = $this->required($row, 'sku', 100);
            if (isset($seen[$sku]) || DB::table('products')->where('sku', $sku)->exists()) {
                throw new \DomainException("SKU i përsëritur në rreshtin {$row['_line']}.");
            }
            $seen[$sku] = true;
            $this->required($row, 'name', 255);
            $this->required($row, 'unit', 30);
            $this->required($row, 'purchase_unit', 30);
            $this->decimal($row, 'unit_conversion', 6, false);
            $this->currency($row['purchase_currency'], $row['_line']);
            $this->decimal($row, 'purchase_price', 8);
            $this->decimal($row, 'selling_price', 8);
            $this->decimal($row, 'tax_rate', 4);
            $this->decimal($row, 'minimum_stock', 6);
            if (! Posting::decimal($row['unit_conversion'])->isPositive() || Posting::decimal($row['tax_rate'])->compareTo('100') > 0) {
                throw new \DomainException("Konvertimi ose TVSH nuk është i vlefshëm në rreshtin {$row['_line']}.");
            }
            if (($row['barcode'] ?? '') !== '' && (strlen($row['barcode']) > 100 || DB::table('products')->where('barcode', $row['barcode'])->exists())) {
                throw new \DomainException("Barkodi është i përsëritur ose i pavlefshëm në rreshtin {$row['_line']}.");
            }
            DB::table('products')->insert(['sku' => $sku, 'name' => $row['name'], 'barcode' => ($row['barcode'] ?? '') ?: null, 'unit' => $row['unit'], 'purchase_unit' => $row['purchase_unit'], 'unit_conversion' => $row['unit_conversion'], 'purchase_currency' => strtoupper($row['purchase_currency']), 'purchase_price' => $row['purchase_price'], 'selling_price' => $row['selling_price'], 'tax_rate' => $row['tax_rate'], 'minimum_stock' => $row['minimum_stock'], 'origin_country' => ($row['origin_country'] ?? '') ?: null, 'hs_code' => ($row['hs_code'] ?? '') ?: null, 'notes' => ($row['notes'] ?? '') ?: null, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** @param array<int,array<string,string>> $rows */
    private function parties(array $rows, string $kind): void
    {
        $this->requireColumns($rows, ['name']);
        $seen = [];
        foreach ($rows as $row) {
            $name = $this->required($row, 'name', 255);
            $identity = strtolower($name).'|'.strtolower($row['email'] ?? '');
            if (isset($seen[$identity]) || DB::table('parties')->where('kind', $kind)->where('name', $name)->exists()) {
                throw new \DomainException("Palë e përsëritur në rreshtin {$row['_line']}.");
            }
            $seen[$identity] = true;
            if (($row['email'] ?? '') !== '' && (! filter_var($row['email'], FILTER_VALIDATE_EMAIL) || strlen($row['email']) > 255)) {
                throw new \DomainException("Email i pavlefshëm në rreshtin {$row['_line']}.");
            }
            foreach (['country' => 2, 'tax_number' => 60, 'phone' => 50, 'address' => 1000] as $field => $maximum) {
                if (strlen($row[$field] ?? '') > $maximum) {
                    throw new \DomainException("{$field} është shumë i gjatë në rreshtin {$row['_line']}.");
                }
            }
            DB::table('parties')->insert(['kind' => $kind, 'name' => $name, 'country' => ($row['country'] ?? '') ?: null, 'tax_number' => ($row['tax_number'] ?? '') ?: null, 'email' => ($row['email'] ?? '') ?: null, 'phone' => ($row['phone'] ?? '') ?: null, 'address' => ($row['address'] ?? '') ?: null, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** @param array<int,array<string,string>> $rows */
    private function journals(array $rows, string $source, int $batch): void
    {
        $this->requireColumns($rows, ['accounting_date', 'reference', 'description', 'account_code', 'debit', 'credit']);
        $groups = [];
        foreach ($rows as $row) {
            $this->date($row, 'accounting_date');
            $this->required($row, 'reference', 100);
            $this->required($row, 'description', 1000);
            $this->decimal($row, 'debit');
            $this->decimal($row, 'credit');
            $debit = Money::of($row['debit']);
            $credit = Money::of($row['credit']);
            if (($debit->isZero() && $credit->isZero()) || (! $debit->isZero() && ! $credit->isZero())) {
                throw new \DomainException("Debiti dhe krediti nuk janë të vlefshëm në rreshtin {$row['_line']}.");
            }
            $account = DB::table('accounts')->where('code', $row['account_code'])->where('active', true)->first();
            if (! $account) {
                throw new \DomainException("Llogaria nuk njihet në rreshtin {$row['_line']}.");
            }
            $key = $row['accounting_date'].'|'.$row['reference'].'|'.$row['description'];
            $groups[$key] ??= ['date' => $row['accounting_date'], 'reference' => $row['reference'], 'description' => $row['description'], 'lines' => []];
            $groups[$key]['lines'][] = ['account_id' => $account->id, 'debit' => $debit->amount(), 'credit' => $credit->amount()];
        }
        foreach (array_values($groups) as $index => $group) {
            $this->posting->journal($source, $batch.'-'.($index + 1), $group['date'], $group['description'].' · '.$group['reference'], $group['lines']);
        }
    }

    /** @param array<int,array<string,string>> $rows */
    private function bank(array $rows, int $batch, array $options): void
    {
        $this->requireColumns($rows, ['date', 'external_reference', 'amount', 'description']);
        $accountId = (int) ($options['account_id'] ?? 0);
        $this->finance->cashAccount($accountId);
        $seen = [];
        foreach ($rows as $row) {
            $this->date($row, 'date');
            $reference = $this->required($row, 'external_reference', 100);
            $this->required($row, 'description', 1000);
            $this->decimal($row, 'amount', 2, true);
            if (Posting::decimal($row['amount'])->isZero() || isset($seen[$reference]) || DB::table('bank_transactions')->where('account_id', $accountId)->where('external_reference', $reference)->exists()) {
                throw new \DomainException("Referenca bankare është e përsëritur ose shuma është zero në rreshtin {$row['_line']}.");
            }
            $seen[$reference] = true;
            DB::table('bank_transactions')->insert(['import_batch_id' => $batch, 'account_id' => $accountId, 'external_reference' => $reference, 'date' => $row['date'], 'amount' => $row['amount'], 'description' => $row['description'], 'status' => 'unmatched', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** @param array<int,array<string,string>> $rows @param array<int,string> $columns */
    private function requireColumns(array $rows, array $columns): void
    {
        foreach ($columns as $column) {
            if (! array_key_exists($column, $rows[0])) {
                throw new \DomainException('Mungon kolona e kërkuar: '.$column.'.');
            }
        }
    }

    /** @param array<string,string> $row */
    private function required(array $row, string $key, int $maximum): string
    {
        $value = trim($row[$key] ?? '');
        if ($value === '' || strlen($value) > $maximum) {
            throw new \DomainException("{$key} mungon ose është i pavlefshëm në rreshtin {$row['_line']}.");
        }

        return $value;
    }

    /** @param array<string,string> $row */
    private function decimal(array $row, string $key, int $scale = 2, bool $negative = false): void
    {
        $value = $row[$key] ?? '';
        if (preg_match('/^'.($negative ? '-?' : '').'\d{1,15}(\.\d{1,'.$scale.'})?$/', $value) !== 1) {
            throw new \DomainException("{$key} nuk është numër i vlefshëm në rreshtin {$row['_line']}.");
        }
    }

    private function currency(string $value, string $line): void
    {
        if (preg_match('/^[A-Z]{3}$/', strtoupper($value)) !== 1) {
            throw new \DomainException("Monedha nuk është e vlefshme në rreshtin {$line}.");
        }
    }

    /** @param array<string,string> $row */
    private function date(array $row, string $key): void
    {
        $value = $row[$key] ?? '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (! $date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            throw new \DomainException("{$key} nuk është datë e vlefshme në rreshtin {$row['_line']}.");
        }
    }
}
