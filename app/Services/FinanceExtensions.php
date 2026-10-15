<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Finance\Money;
use App\Domain\Purchasing\LandedCostAllocator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/** Source-document workflows that extend the core receivable, payable, and inventory controls. */
final class FinanceExtensions
{
    public function __construct(private Posting $posting, private Finance $finance, private Selling $selling, private Stock $stock) {}

    public function receiveDeposit(array $data): array
    {
        return $this->posting->once('customer.deposit', $data, function () use ($data) {
            $this->posting->configured();
            $customer = DB::table('parties')->where('id', $data['customer_id'])->where('kind', 'customer')->lockForUpdate()->firstOrFail();
            $this->finance->cashAccount($data['account_id']);
            $amount = Money::of($data['amount']);
            if (! $amount->decimal()->isPositive()) {
                throw new \DomainException('Parapagimi duhet të jetë pozitiv.');
            }
            if (DB::table('customer_deposits')->where('reference', $data['reference'])->exists()) {
                throw new \DomainException('Kjo referencë parapagimi ekziston tashmë.');
            }
            $id = DB::table('customer_deposits')->insertGetId(['customer_id' => $customer->id, 'account_id' => $data['account_id'], 'reference' => $data['reference'], 'amount' => $amount->amount(), 'method' => $data['method'], 'received_on' => $data['received_on'], 'operation_key' => $data['operation_key'], 'reason' => $data['reason'], 'user_id' => auth()->id(), 'created_at' => now()]);
            $this->posting->journal('customer_deposit', $id, $data['received_on'], $data['reason'], [['account_id' => $data['account_id'], 'debit' => $amount->amount(), 'party_id' => $customer->id], ['account' => '2300', 'credit' => $amount->amount(), 'party_id' => $customer->id]]);
            Audit::record('customer.deposit_received', 'customer_deposit', $id, ['customer_id' => $customer->id, 'amount' => $amount->amount()]);

            return ['id' => $id];
        });
    }

    public function allocateDeposit(int $depositId, array $data): array
    {
        return $this->posting->once('customer.deposit_allocate', $data + ['deposit_id' => $depositId], function () use ($depositId, $data) {
            $deposit = DB::table('customer_deposits')->where('id', $depositId)->lockForUpdate()->firstOrFail();
            $sale = DB::table('sales')->where('id', $data['sale_id'])->lockForUpdate()->firstOrFail();
            if ((int) $sale->customer_id !== (int) $deposit->customer_id) {
                throw new \DomainException('Parapagimi dhe shitja duhet t’i përkasin të njëjtit klient.');
            }
            $amount = Money::of($data['amount']);
            $allocated = Posting::decimal((string) DB::table('customer_deposit_allocations')->where('customer_deposit_id', $depositId)->sum('amount'));
            $refunded = Posting::decimal((string) DB::table('customer_deposit_refunds')->where('customer_deposit_id', $depositId)->sum('amount'));
            if (! $amount->decimal()->isPositive() || $allocated->plus($refunded)->plus($amount->decimal())->compareTo($deposit->amount) > 0) {
                throw new \DomainException('Shpërndarja tejkalon parapagimin e papërdorur.');
            }
            if ($amount->decimal()->compareTo($this->saleReceivableOpen($sale->id)) > 0) {
                throw new \DomainException('Shpërndarja tejkalon borxhin e njohur të klientit.');
            }
            $id = DB::table('customer_deposit_allocations')->insertGetId(['customer_deposit_id' => $depositId, 'sale_id' => $sale->id, 'amount' => $amount->amount(), 'allocated_on' => $data['allocated_on'], 'operation_key' => $data['operation_key'], 'user_id' => auth()->id(), 'created_at' => now()]);
            $this->posting->journal('customer_deposit_allocation', $id, $data['allocated_on'], $data['reason'], [['account' => '2300', 'debit' => $amount->amount(), 'party_id' => $deposit->customer_id], ['account' => '1100', 'credit' => $amount->amount(), 'party_id' => $deposit->customer_id]]);
            $this->refreshSalePaymentStatus($sale->id);
            Audit::record('customer.deposit_allocated', 'customer_deposit', $depositId, ['sale_id' => $sale->id, 'amount' => $amount->amount()]);

            return ['id' => $id];
        });
    }

    public function refundDeposit(int $depositId, array $data): array
    {
        return $this->posting->once('customer.deposit_refund', $data + ['deposit_id' => $depositId], function () use ($depositId, $data) {
            $deposit = DB::table('customer_deposits')->where('id', $depositId)->lockForUpdate()->firstOrFail();
            $this->finance->cashAccount($data['account_id']);
            $amount = Money::of($data['amount']);
            $allocated = Posting::decimal((string) DB::table('customer_deposit_allocations')->where('customer_deposit_id', $depositId)->sum('amount'));
            $refunded = Posting::decimal((string) DB::table('customer_deposit_refunds')->where('customer_deposit_id', $depositId)->sum('amount'));
            if (! $amount->decimal()->isPositive() || $allocated->plus($refunded)->plus($amount->decimal())->compareTo($deposit->amount) > 0) {
                throw new \DomainException('Rimbursimi tejkalon parapagimin e papërdorur.');
            }
            $id = DB::table('customer_deposit_refunds')->insertGetId(['customer_deposit_id' => $depositId, 'account_id' => $data['account_id'], 'amount' => $amount->amount(), 'refunded_on' => $data['refunded_on'], 'operation_key' => $data['operation_key'], 'reason' => $data['reason'], 'user_id' => auth()->id(), 'created_at' => now()]);
            $this->posting->journal('customer_deposit_refund', $id, $data['refunded_on'], $data['reason'], [['account' => '2300', 'debit' => $amount->amount(), 'party_id' => $deposit->customer_id], ['account_id' => $data['account_id'], 'credit' => $amount->amount(), 'party_id' => $deposit->customer_id]]);
            Audit::record('customer.deposit_refunded', 'customer_deposit', $depositId, ['amount' => $amount->amount()]);

            return ['id' => $id];
        });
    }

    public function supplierCredit(array $data): array
    {
        return $this->posting->once('supplier.credit', $data, function () use ($data) {
            $settings = $this->posting->configured();
            $supplier = DB::table('parties')->where('id', $data['supplier_id'])->where('kind', 'supplier')->lockForUpdate()->firstOrFail();
            $account = DB::table('accounts')->where('id', $data['account_id'])->where('active', true)->firstOrFail();
            if (! in_array($account->type, ['expense'], true) && $account->code !== '2150') {
                throw new \DomainException('Kreditimi duhet të lidhet me një shpenzim ose mallra pa faturë.');
            }
            $net = Money::of($data['net_amount']);
            $vat = Money::of($data['vat_amount']);
            $recoverable = Money::of($data['recoverable_vat']);
            $gross = $net->add($vat);
            if (! $gross->decimal()->isPositive() || $recoverable->compare($vat) > 0 || $net->isNegative() || $vat->isNegative() || $recoverable->isNegative()) {
                throw new \DomainException('Shumat e kreditimit nuk janë të vlefshme.');
            }
            if (! ($settings['vat_registered'] ?? false) && ! $recoverable->isZero()) {
                throw new \DomainException('TVSH e zbritshme kërkon regjistrim të konfirmuar për TVSH.');
            }
            $bill = null;
            if (! empty($data['bill_id'])) {
                $bill = DB::table('bills')->where('id', $data['bill_id'])->lockForUpdate()->firstOrFail();
                if ((int) $bill->supplier_id !== (int) $supplier->id || Posting::decimal((string) DB::table('supplier_credits')->where('bill_id', $bill->id)->sum('gross_amount'))->plus($gross->decimal())->compareTo($bill->gross_amount) > 0) {
                    throw new \DomainException('Kreditimi tejkalon faturën e furnitorit.');
                }
            }
            if (! empty($data['purchase_order_id']) && (int) DB::table('purchase_orders')->where('id', $data['purchase_order_id'])->value('supplier_id') !== (int) $supplier->id) {
                throw new \DomainException('Blerja nuk i përket furnitorit të zgjedhur.');
            }
            if (! empty($data['supplier_return_id'])) {
                $return = DB::table('supplier_returns')->where('id', $data['supplier_return_id'])->lockForUpdate()->firstOrFail();
                if ((int) $return->supplier_id !== (int) $supplier->id || Posting::decimal((string) DB::table('supplier_credits')->where('supplier_return_id', $return->id)->sum('gross_amount'))->plus($gross->decimal())->compareTo($return->claim_value) > 0) {
                    throw new \DomainException('Kreditimi tejkalon kërkesën për mallrat e kthyera.');
                }
            }
            $id = DB::table('supplier_credits')->insertGetId(['supplier_id' => $supplier->id, 'bill_id' => $bill?->id, 'purchase_order_id' => $data['purchase_order_id'] ?? null, 'supplier_return_id' => $data['supplier_return_id'] ?? null, 'account_id' => $account->id, 'reference' => $data['reference'], 'credited_on' => $data['credited_on'], 'net_amount' => $net->amount(), 'vat_amount' => $vat->amount(), 'recoverable_vat' => $recoverable->amount(), 'gross_amount' => $gross->amount(), 'operation_key' => $data['operation_key'], 'reason' => $data['reason'], 'user_id' => auth()->id(), 'created_at' => now()]);
            $this->posting->journal('supplier_credit', $id, $data['credited_on'], $data['reason'], [['account' => '2100', 'debit' => $gross->amount(), 'party_id' => $supplier->id], ['account_id' => $account->id, 'credit' => $net->add($vat->subtract($recoverable))->amount()], ['account' => '1300', 'credit' => $recoverable->amount()]]);
            if ($bill) {
                DB::table('supplier_credit_allocations')->insert(['supplier_credit_id' => $id, 'bill_id' => $bill->id, 'amount' => $gross->amount(), 'allocated_on' => $data['credited_on'], 'operation_key' => $data['operation_key'].'-bill', 'user_id' => auth()->id(), 'created_at' => now()]);
            }
            Audit::record('supplier.credit_issued', 'supplier_credit', $id, ['supplier_id' => $supplier->id, 'amount' => $gross->amount()]);

            return ['id' => $id];
        });
    }

    public function allocateSupplierCredit(int $creditId, array $data): array
    {
        return $this->posting->once('supplier.credit_allocate', $data + ['supplier_credit_id' => $creditId], function () use ($creditId, $data) {
            $credit = DB::table('supplier_credits')->where('id', $creditId)->lockForUpdate()->firstOrFail();
            $bill = DB::table('bills')->where('id', $data['bill_id'])->lockForUpdate()->firstOrFail();
            if ((int) $credit->supplier_id !== (int) $bill->supplier_id) {
                throw new \DomainException('Fatura nuk i përket furnitorit të kreditimit.');
            }
            $amount = Money::of($data['amount']);
            $allocated = Posting::decimal((string) DB::table('supplier_credit_allocations')->where('supplier_credit_id', $creditId)->sum('amount'));
            $billAllocated = Posting::decimal((string) DB::table('supplier_credit_allocations')->where('bill_id', $bill->id)->sum('amount'));
            $paid = Posting::decimal((string) DB::table('payments')->where('bill_id', $bill->id)->sum('amount'));
            if (! $amount->decimal()->isPositive() || $allocated->plus($amount->decimal())->compareTo($credit->gross_amount) > 0 || $billAllocated->plus($paid)->plus($amount->decimal())->compareTo($bill->gross_amount) > 0) {
                throw new \DomainException('Shpërndarja tejkalon kreditimin ose faturën e hapur.');
            }
            $id = DB::table('supplier_credit_allocations')->insertGetId(['supplier_credit_id' => $creditId, 'bill_id' => $bill->id, 'amount' => $amount->amount(), 'allocated_on' => $data['allocated_on'], 'operation_key' => $data['operation_key'], 'user_id' => auth()->id(), 'created_at' => now()]);
            Audit::record('supplier.credit_allocated', 'supplier_credit', $creditId, ['bill_id' => $bill->id, 'amount' => $amount->amount()]);

            return ['id' => $id];
        });
    }

    public function postReceiptCostAdjustment(int $purchaseId, array $data): array
    {
        return $this->posting->once('purchase.post_receipt_cost', $data + ['purchase_id' => $purchaseId], function () use ($purchaseId, $data) {
            $this->posting->configured();
            $purchase = DB::table('purchase_orders')->where('id', $purchaseId)->lockForUpdate()->firstOrFail();
            if (! in_array($purchase->status, ['partially_received', 'received'], true)) {
                throw new \DomainException('Korrigjimi i kostos kërkon së paku një pranim të konfirmuar.');
            }
            $creditAccount = DB::table('accounts')->where('id', $data['credit_account_id'])->where('active', true)->firstOrFail();
            if (! in_array($creditAccount->type, ['asset', 'liability'], true) || in_array($creditAccount->code, ['1200', '1210', '1300'], true)) {
                throw new \DomainException('Zgjidhni arkën, bankën ose një detyrim si burim të kostos.');
            }
            if ($creditAccount->code === '2100' && empty($data['supplier_id'])) {
                throw new \DomainException('Detyrimi ndaj furnitorit kërkon furnitor.');
            }
            if (! empty($data['supplier_id']) && (int) DB::table('parties')->where('id', $data['supplier_id'])->where('kind', 'supplier')->value('id') !== (int) $data['supplier_id']) {
                throw new \DomainException('Furnitori i kostos nuk është i vlefshëm.');
            }
            $amount = Money::of($data['amount']);
            if (! $amount->decimal()->isPositive()) {
                throw new \DomainException('Kostoja e vonuar duhet të jetë pozitive.');
            }
            $receipts = DB::table('receipt_lines as r')->join('purchase_lines as l', 'l.id', '=', 'r.purchase_line_id')->where('l.purchase_order_id', $purchaseId)->select('r.*', 'l.invoice_value', 'l.weight', 'l.volume')->orderBy('r.id')->lockForUpdate()->get();
            if ($receipts->isEmpty()) {
                throw new \DomainException('Nuk ka rreshta të pranuar për shpërndarje.');
            }
            $weights = [];
            foreach ($receipts as $line) {
                $weights[$line->id] = match ($data['allocation_basis']) {
                    'value' => (string) Posting::decimal($line->landed_value),
                    'quantity' => (string) Posting::decimal($line->quantity),
                    'weight' => (string) Posting::decimal($line->quantity)->multipliedBy($line->weight),
                    'volume' => (string) Posting::decimal($line->quantity)->multipliedBy($line->volume),
                };
            }
            $allocation = (new LandedCostAllocator)->allocate($amount, $weights);
            $id = DB::table('post_receipt_cost_adjustments')->insertGetId(['purchase_order_id' => $purchaseId, 'supplier_id' => $data['supplier_id'] ?? null, 'credit_account_id' => $creditAccount->id, 'document_reference' => $data['document_reference'], 'category' => $data['category'], 'allocation_basis' => $data['allocation_basis'], 'amount' => $amount->amount(), 'accounting_date' => $data['accounting_date'], 'operation_key' => $data['operation_key'], 'reason' => $data['reason'], 'user_id' => auth()->id(), 'created_at' => now()]);
            $available = [];
            $journal = [];
            foreach ($receipts as $line) {
                $onHand = $available[$line->product_id] ??= Posting::decimal((string) (DB::table('stock_balances')->where('warehouse_id', 1)->where('product_id', $line->product_id)->where('condition', 'sellable')->lockForUpdate()->value('on_hand') ?? '0'));
                $receiptQuantity = Posting::decimal($line->quantity);
                $inventoryQuantity = $onHand->compareTo($receiptQuantity) < 0 ? $onHand : $receiptQuantity;
                $available[$line->product_id] = $onHand->minus($inventoryQuantity);
                $soldQuantity = $receiptQuantity->minus($inventoryQuantity);
                $lineAmount = $allocation[$line->id]->decimal();
                $valuePart = $inventoryQuantity->isZero() ? Posting::decimal('0') : ($soldQuantity->isZero() ? $lineAmount : $lineAmount->multipliedBy($inventoryQuantity)->dividedBy($receiptQuantity, 2, RoundingMode::HALF_UP));
                $inventoryPosting = $valuePart;
                if ($valuePart->isPositive()) {
                    $move = $this->stock->revalue($line->product_id, (string) $valuePart, 'sellable', 'post_receipt_cost', $id.':'.$line->id, $data['reason'], $data['accounting_date']);
                    $inventoryPosting = Posting::decimal($move->ledgerMovementValue());
                    if ($inventoryPosting->isPositive()) {
                        $journal[] = ['account' => '1200', 'debit' => (string) $inventoryPosting, 'product_id' => $line->product_id];
                    }
                }
                $cogs = $lineAmount->minus($inventoryPosting);
                if ($cogs->isPositive()) {
                    $journal[] = ['account' => '5000', 'debit' => (string) $cogs, 'product_id' => $line->product_id];
                }
                DB::table('post_receipt_cost_adjustment_lines')->insert(['post_receipt_cost_adjustment_id' => $id, 'receipt_line_id' => $line->id, 'product_id' => $line->product_id, 'receipt_quantity' => (string) $receiptQuantity, 'inventory_quantity' => (string) $inventoryQuantity, 'sold_quantity' => (string) $soldQuantity, 'amount' => (string) $lineAmount, 'inventory_amount' => (string) $inventoryPosting, 'cogs_amount' => (string) $cogs]);
            }
            $journal[] = ['account_id' => $creditAccount->id, 'credit' => $amount->amount(), 'party_id' => $data['supplier_id'] ?? null];
            $this->posting->journal('post_receipt_cost', $id, $data['accounting_date'], $data['reason'], $journal);
            Audit::record('purchase.post_receipt_cost_posted', 'purchase', $purchaseId, ['adjustment_id' => $id, 'amount' => $amount->amount()]);

            return ['id' => $id];
        });
    }

    public function creditDelivery(int $saleId, array $data): array
    {
        return $this->posting->once('delivery.credit', $data + ['sale_id' => $saleId], function () use ($saleId, $data) {
            $sale = DB::table('sales')->where('id', $saleId)->lockForUpdate()->firstOrFail();
            $this->posting->fiscalGate($saleId, $data);
            $fulfillment = DB::table('fulfillments')->where('id', $data['fulfillment_id'])->where('sale_id', $saleId)->lockForUpdate()->firstOrFail();
            if (! $fulfillment->recognized) {
                throw new \DomainException('Tarifa mund të kreditohet vetëm pas njohjes së dërgesës.');
            }
            $grossTotal = Posting::decimal($fulfillment->delivery_charge)->plus($fulfillment->delivery_tax);
            $prior = Posting::decimal((string) DB::table('delivery_credits')->where('fulfillment_id', $fulfillment->id)->sum('gross_amount'));
            $amount = Money::of($data['amount']);
            if (! $grossTotal->isPositive() || ! $amount->decimal()->isPositive() || $prior->plus($amount->decimal())->compareTo($grossTotal) > 0) {
                throw new \DomainException('Kreditimi tejkalon tarifën e papërdorur të dërgesës.');
            }
            $last = $prior->plus($amount->decimal())->compareTo($grossTotal) === 0;
            $net = $last ? Posting::decimal($fulfillment->delivery_charge)->minus((string) DB::table('delivery_credits')->where('fulfillment_id', $fulfillment->id)->sum('net_amount')) : $amount->decimal()->multipliedBy($fulfillment->delivery_charge)->dividedBy($grossTotal, 2, RoundingMode::HALF_UP);
            $tax = $amount->decimal()->minus($net);
            $id = DB::table('delivery_credits')->insertGetId(['sale_id' => $saleId, 'fulfillment_id' => $fulfillment->id, 'reference' => $data['reference'], 'credited_on' => $data['credited_on'], 'net_amount' => (string) $net, 'tax_amount' => (string) $tax, 'gross_amount' => $amount->amount(), 'operation_key' => $data['operation_key'], 'reason' => $data['reason'], 'user_id' => auth()->id(), 'created_at' => now()]);
            $channel = DB::table('sales_channels')->where('id', $sale->channel_id)->value('code');
            $this->posting->journal('delivery_credit', $id, $data['credited_on'], $data['reason'], [['account' => '4010', 'debit' => (string) $net, 'channel' => $channel], ['account' => '2200', 'debit' => (string) $tax, 'channel' => $channel], ['account' => '1100', 'credit' => $amount->amount(), 'party_id' => $sale->customer_id, 'channel' => $channel]]);
            $this->refreshSalePaymentStatus($saleId);
            Audit::record('delivery.credit_issued', 'delivery_credit', $id, ['sale_id' => $saleId, 'amount' => $amount->amount()]);

            return ['id' => $id];
        });
    }

    public function refundDeliveryCredit(int $creditId, array $data): array
    {
        return $this->posting->once('delivery.refund', $data + ['delivery_credit_id' => $creditId], function () use ($creditId, $data) {
            $credit = DB::table('delivery_credits')->where('id', $creditId)->lockForUpdate()->firstOrFail();
            $sale = DB::table('sales')->where('id', $credit->sale_id)->lockForUpdate()->firstOrFail();
            $this->posting->fiscalGate($sale->id, $data);
            $this->finance->cashAccount($data['account_id']);
            $amount = Money::of($data['amount']);
            $prior = Posting::decimal((string) DB::table('delivery_refunds')->where('delivery_credit_id', $creditId)->sum('amount'));
            $cashReceived = Posting::decimal((string) DB::table('payments')->where('sale_id', $sale->id)->sum('amount'))->plus((string) DB::table('customer_deposit_allocations')->where('sale_id', $sale->id)->sum('amount'));
            $allRefunds = Posting::decimal((string) DB::table('refunds')->join('sale_returns', 'sale_returns.id', '=', 'refunds.sale_return_id')->where('sale_returns.sale_id', $sale->id)->sum('refunds.amount'))->plus((string) DB::table('delivery_refunds as r')->join('delivery_credits as c', 'c.id', '=', 'r.delivery_credit_id')->where('c.sale_id', $sale->id)->sum('r.amount'));
            if (! $amount->decimal()->isPositive() || $prior->plus($amount->decimal())->compareTo($credit->gross_amount) > 0 || $allRefunds->plus($amount->decimal())->compareTo($cashReceived) > 0) {
                throw new \DomainException('Rimbursimi tejkalon kreditimin ose fondet e marra për porosinë.');
            }
            $id = DB::table('delivery_refunds')->insertGetId(['delivery_credit_id' => $creditId, 'account_id' => $data['account_id'], 'amount' => $amount->amount(), 'refunded_on' => $data['refunded_on'], 'operation_key' => $data['operation_key'], 'reason' => $data['reason'], 'user_id' => auth()->id(), 'created_at' => now()]);
            $this->posting->journal('delivery_refund', $id, $data['refunded_on'], $data['reason'], [['account' => '1100', 'debit' => $amount->amount(), 'party_id' => $sale->customer_id], ['account_id' => $data['account_id'], 'credit' => $amount->amount()]]);
            $this->refreshSalePaymentStatus($sale->id);
            Audit::record('delivery.refunded', 'delivery_credit', $creditId, ['amount' => $amount->amount()]);

            return ['id' => $id];
        });
    }

    private function saleReceivableOpen(int $saleId): BigDecimal
    {
        $recognized = Posting::decimal($this->selling->recognizedGross($saleId));
        $productCredits = Posting::decimal((string) DB::table('sale_returns')->where('sale_id', $saleId)->sum('gross_total'));
        $deliveryCredits = Posting::decimal((string) DB::table('delivery_credits')->where('sale_id', $saleId)->sum('gross_amount'));
        $payments = Posting::decimal((string) DB::table('payments')->where('sale_id', $saleId)->sum('amount'));
        $allocations = Posting::decimal((string) DB::table('customer_deposit_allocations')->where('sale_id', $saleId)->sum('amount'));
        $productRefunds = Posting::decimal((string) DB::table('refunds as r')->join('sale_returns as s', 's.id', '=', 'r.sale_return_id')->where('s.sale_id', $saleId)->sum('r.amount'));
        $deliveryRefunds = Posting::decimal((string) DB::table('delivery_refunds as r')->join('delivery_credits as c', 'c.id', '=', 'r.delivery_credit_id')->where('c.sale_id', $saleId)->sum('r.amount'));

        return $recognized->minus($productCredits)->minus($deliveryCredits)->minus($payments)->minus($allocations)->plus($productRefunds)->plus($deliveryRefunds);
    }

    private function refreshSalePaymentStatus(int $saleId): void
    {
        $sale = DB::table('sales')->where('id', $saleId)->firstOrFail();
        $open = $this->saleReceivableOpen($saleId);
        $status = $open->isZero() ? 'paid' : ($open->compareTo(Posting::decimal($this->selling->recognizedGross($saleId))) < 0 ? 'partially_paid' : 'unpaid');
        DB::table('sales')->where('id', $saleId)->update(['payment_status' => $status, 'updated_at' => now()]);
    }
}
