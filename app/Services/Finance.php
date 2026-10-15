<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Finance\Money;
use Illuminate\Support\Facades\DB;

final class Finance
{
    public function __construct(private Posting $posting) {}

    public function bill(array $data): array
    {
        return $this->posting->once('bill.post', $data, function () use ($data) {
            $settings = $this->posting->configured();
            $account = DB::table('accounts')->where('id', $data['account_id'])->where('active', true)->firstOrFail();
            $duplicate = DB::table('bills')->where('reference', $data['reference']);
            isset($data['supplier_id']) ? $duplicate->where('supplier_id', $data['supplier_id']) : $duplicate->whereNull('supplier_id');
            if ($duplicate->exists()) {
                throw new \DomainException('Kjo faturë është regjistruar më parë.');
            }
            if (! in_array($account->type, ['expense'], true) && $account->code !== '2150') {
                throw new \DomainException('Përdorni shpenzim operativ ose llogarinë e mallrave pa faturë.');
            }
            $net = Money::of($data['net_amount']);
            $vat = Money::of($data['vat_amount'] ?? '0');
            $recover = Money::of($data['recoverable_vat'] ?? '0');
            if ($net->isNegative() || $vat->isNegative() || $recover->isNegative() || $recover->compare($vat) > 0) {
                throw new \DomainException('Vlerat e faturës janë të pavlefshme.');
            }
            if (! ($settings['vat_registered'] ?? false) && ! $recover->isZero()) {
                throw new \DomainException('TVSH e rikuperueshme kërkon regjistrim të konfirmuar për TVSH.');
            }
            $gross = $net->add($vat);
            if ($gross->isZero()) {
                throw new \DomainException('Fatura duhet të ketë vlerë.');
            }
            $id = DB::table('bills')->insertGetId(['supplier_id' => $data['supplier_id'] ?? null, 'purchase_order_id' => $data['purchase_order_id'] ?? null, 'reference' => $data['reference'], 'accounting_date' => $data['accounting_date'], 'account_id' => $account->id, 'net_amount' => $net->amount(), 'vat_amount' => $vat->amount(), 'recoverable_vat' => $recover->amount(), 'gross_amount' => $gross->amount(), 'category' => $data['category'], 'description' => $data['description'], 'user_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
            $this->posting->journal('bill', $id, $data['accounting_date'], $data['description'], [['account_id' => $account->id, 'debit' => $net->add($vat->subtract($recover))->amount()], ['account' => '1300', 'debit' => $recover->amount()], ['account' => '2100', 'credit' => $gross->amount(), 'party_id' => $data['supplier_id'] ?? null]]);

            return ['id' => $id];
        });
    }

    public function payBill(int $billId, array $data): array
    {
        return $this->posting->once('bill.payment', $data + ['bill_id' => $billId], function () use ($billId, $data) {
            $bill = DB::table('bills')->where('id', $billId)->lockForUpdate()->firstOrFail();
            $this->cashAccount($data['account_id']);
            $amount = Money::of($data['amount']);
            $paid = Money::of((string) DB::table('payments')->where('bill_id', $billId)->sum('amount'));
            $credited = Money::of((string) DB::table('supplier_credit_allocations')->where('bill_id', $billId)->sum('amount'));
            if ($amount->isNegative() || $amount->isZero() || $paid->add($credited)->add($amount)->compare(Money::of($bill->gross_amount)) > 0) {
                throw new \DomainException('Pagesa tejkalon shumën e hapur.');
            }
            $id = DB::table('payments')->insertGetId(['kind' => 'supplier', 'bill_id' => $billId, 'account_id' => $data['account_id'], 'amount' => $amount->amount(), 'method' => $data['method'], 'reference' => $data['reference'], 'operation_key' => $data['operation_key'], 'paid_on' => $data['paid_on'], 'user_id' => auth()->id(), 'created_at' => now()]);
            $this->posting->journal('supplier_payment', $id, $data['paid_on'], 'Pagesë furnitori', [['account' => '2100', 'debit' => $amount->amount(), 'party_id' => $bill->supplier_id], ['account_id' => $data['account_id'], 'credit' => $amount->amount()]]);

            return ['id' => $id];
        });
    }

    public function cashAccount(int $id): object
    {
        $a = DB::table('accounts')->where('id', $id)->where('active', true)->first();
        if (! $a || ! in_array($a->code, ['1000', '1010'], true)) {
            throw new \DomainException('Zgjidhni llogarinë e arkës ose bankës.');
        }

        return $a;
    }

    public function transfer(array $data): array
    {
        return $this->posting->once('account.transfer', $data, function () use ($data) {
            $this->cashAccount($data['from_account_id']);
            $this->cashAccount($data['to_account_id']);
            $a = Money::of($data['amount']);
            if ($data['from_account_id'] == $data['to_account_id'] || $a->isNegative() || $a->isZero()) {
                throw new \DomainException('Transferim i pavlefshëm.');
            }
            $id = $this->posting->journal('transfer', $data['operation_key'], $data['accounting_date'], $data['reason'], [['account_id' => $data['from_account_id'], 'credit' => $a->amount()], ['account_id' => $data['to_account_id'], 'debit' => $a->amount()]]);

            return ['id' => $id];
        });
    }
}
