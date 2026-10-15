<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\BusinessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationalExtensionsTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $recognition = 'dispatch'): array
    {
        $this->seed(BusinessSeeder::class);
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'owner')->firstOrFail());
        $this->actingAs($user);
        DB::table('settings')->insert(['key' => 'accounting', 'value' => json_encode(['configured' => true, 'vat_registered' => false, 'fiscal_required' => false, 'recognition_policy' => $recognition, 'delivery_tax_rate' => '0']), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('accounting_periods')->insert(['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'created_at' => now(), 'updated_at' => now()]);
        $supplier = DB::table('parties')->insertGetId(['kind' => 'supplier', 'name' => 'Supplier extensions', 'created_at' => now(), 'updated_at' => now()]);
        $customer = DB::table('parties')->insertGetId(['kind' => 'customer', 'name' => 'Customer extensions', 'created_at' => now(), 'updated_at' => now()]);
        $product = $this->postJson('/products', ['sku' => 'EXT-01', 'name' => 'Extension product', 'unit' => 'piece', 'purchase_unit' => 'piece', 'unit_conversion' => '1', 'purchase_currency' => 'EUR', 'purchase_price' => '2', 'selling_price' => '5', 'minimum_stock' => '1', 'tax_rate' => '0'])->assertCreated()->json('id');
        $purchase = $this->postJson('/purchases', ['reference' => 'EXT-PO', 'supplier_id' => $supplier, 'ordered_on' => '2026-10-01', 'currency' => 'EUR', 'exchange_rate' => '1', 'exchange_rate_source' => 'Supplier invoice', 'lines' => [['product_id' => $product, 'quantity' => '12', 'unit_price' => '2', 'weight' => '1', 'volume' => '1']]])->assertCreated()->json('id');
        $purchaseLine = DB::table('purchase_lines')->where('purchase_order_id', $purchase)->value('id');
        $this->postJson('/purchases/'.$purchase.'/receive', ['operation_key' => 'EXT-RECEIPT-0001', 'reference' => 'EXT-RC', 'received_on' => '2026-10-02', 'lines' => [['purchase_line_id' => $purchaseLine, 'quantity' => '12']]])->assertOk();

        return ['user' => $user, 'supplier' => $supplier, 'customer' => $customer, 'product' => $product, 'purchase' => $purchase, 'cash' => DB::table('accounts')->where('code', '1000')->value('id'), 'bank' => DB::table('accounts')->where('code', '1010')->value('id'), 'expense' => DB::table('accounts')->where('code', '6100')->value('id'), 'channel' => DB::table('sales_channels')->where('code', 'direct')->value('id')];
    }

    private function dispatchedSale(array $f, string $reference, string $delivery = '0'): int
    {
        $sale = $this->postJson('/sales', ['operation_key' => 'CREATE-'.$reference, 'reference' => $reference, 'channel_id' => $f['channel'], 'customer_id' => $f['customer'], 'ordered_on' => '2026-10-03', 'delivery_charge' => $delivery, 'lines' => [['product_id' => $f['product'], 'quantity' => '2', 'unit_price' => '5', 'discount' => '0']]])->assertCreated()->json('id');
        $line = DB::table('sale_lines')->where('sale_id', $sale)->first();
        $this->postJson('/sales/'.$sale.'/dispatch', ['operation_key' => 'DISPATCH-'.$reference, 'dispatched_on' => '2026-10-03', 'lines' => [['sale_line_id' => $line->id, 'quantity' => '2']]])->assertOk();

        return $sale;
    }

    public function test_customer_deposit_allocates_only_to_recognized_receivable_and_refunds_the_remainder(): void
    {
        $f = $this->fixture();
        $sale = $this->dispatchedSale($f, 'EXT-SALE-DEPOSIT');
        $deposit = $this->postJson('/customer-deposits', ['operation_key' => 'EXT-DEPOSIT-0001', 'customer_id' => $f['customer'], 'account_id' => $f['cash'], 'reference' => 'DEP-001', 'amount' => '20.00', 'method' => 'cash', 'received_on' => '2026-10-03', 'reason' => 'Customer deposit received'])->assertCreated()->json('id');
        $this->postJson('/customer-deposits/'.$deposit.'/allocate', ['operation_key' => 'EXT-DEPOSIT-ALLOC-1', 'sale_id' => $sale, 'amount' => '10.00', 'allocated_on' => '2026-10-03', 'reason' => 'Apply to recognized order'])->assertCreated();
        $this->assertSame('paid', DB::table('sales')->where('id', $sale)->value('payment_status'));
        $this->postJson('/customer-deposits/'.$deposit.'/refund', ['operation_key' => 'EXT-DEPOSIT-REFUND1', 'account_id' => $f['cash'], 'amount' => '10.00', 'refunded_on' => '2026-10-04', 'reason' => 'Unused balance returned'])->assertCreated();
        $this->postJson('/customer-deposits/'.$deposit.'/allocate', ['operation_key' => 'EXT-DEPOSIT-ALLOC-2', 'sale_id' => $sale, 'amount' => '0.01', 'allocated_on' => '2026-10-04', 'reason' => 'Must not exceed balance'])->assertUnprocessable();
        $this->assertSame(1, DB::table('customer_deposit_allocations')->count());
        $this->assertSame(1, DB::table('customer_deposit_refunds')->count());
    }

    public function test_supplier_credit_caps_a_bill_and_reduces_its_payable_amount(): void
    {
        $f = $this->fixture();
        $bill = $this->postJson('/bills', ['operation_key' => 'EXT-BILL-000001', 'supplier_id' => $f['supplier'], 'reference' => 'SUP-BILL-001', 'accounting_date' => '2026-10-03', 'account_id' => $f['expense'], 'net_amount' => '10.00', 'vat_amount' => '0', 'recoverable_vat' => '0', 'category' => 'service', 'description' => 'Supplier service invoice'])->assertCreated()->json('id');
        $credit = $this->postJson('/supplier-credits', ['operation_key' => 'EXT-CREDIT-0001', 'supplier_id' => $f['supplier'], 'bill_id' => $bill, 'account_id' => $f['expense'], 'reference' => 'SUP-CR-001', 'credited_on' => '2026-10-04', 'net_amount' => '3.00', 'vat_amount' => '0', 'recoverable_vat' => '0', 'reason' => 'Approved supplier credit'])->assertCreated()->json('id');
        $this->assertSame('3.00', DB::table('supplier_credit_allocations')->where('supplier_credit_id', $credit)->value('amount'));
        $this->postJson('/bills/'.$bill.'/pay', ['operation_key' => 'EXT-PAY-TOO-MUCH', 'amount' => '7.01', 'account_id' => $f['cash'], 'paid_on' => '2026-10-04', 'reference' => 'PAY-OVER', 'method' => 'cash'])->assertUnprocessable();
        $this->postJson('/bills/'.$bill.'/pay', ['operation_key' => 'EXT-PAY-VALID001', 'amount' => '7.00', 'account_id' => $f['cash'], 'paid_on' => '2026-10-04', 'reference' => 'PAY-OK', 'method' => 'cash'])->assertOk();
    }

    public function test_late_landed_cost_splits_current_stock_and_already_sold_goods(): void
    {
        $f = $this->fixture();
        $this->dispatchedSale($f, 'EXT-SALE-COST');
        $adjustment = $this->postJson('/purchases/'.$f['purchase'].'/post-receipt-cost', ['operation_key' => 'EXT-LATE-COST-001', 'credit_account_id' => $f['cash'], 'document_reference' => 'FREIGHT-LATE-001', 'category' => 'freight', 'allocation_basis' => 'quantity', 'amount' => '6.00', 'accounting_date' => '2026-10-04', 'reason' => 'Late freight document received'])->assertCreated()->json('id');
        $line = DB::table('post_receipt_cost_adjustment_lines')->where('post_receipt_cost_adjustment_id', $adjustment)->first();
        $this->assertSame('10.000000', $line->inventory_quantity);
        $this->assertSame('2.000000', $line->sold_quantity);
        $this->assertSame('5.00', $line->inventory_amount);
        $this->assertSame('1.00', $line->cogs_amount);
        $this->assertSame('25.00000000', DB::table('stock_balances')->where('product_id', $f['product'])->where('condition', 'sellable')->value('value'));
    }

    public function test_promotion_is_applied_once_and_redeemed_with_the_sale(): void
    {
        $f = $this->fixture();
        $this->postJson('/promotions', ['code' => 'SAVE2', 'name' => 'Two euro offer', 'discount_type' => 'fixed', 'discount_value' => '2.00', 'minimum_order_amount' => '5.00', 'max_redemptions' => 1, 'per_customer_limit' => 1, 'active' => true])->assertCreated();
        $sale = $this->postJson('/sales', ['operation_key' => 'EXT-PROMO-SALE01', 'reference' => 'EXT-PROMO-1', 'channel_id' => $f['channel'], 'customer_id' => $f['customer'], 'ordered_on' => '2026-10-03', 'promotion_code' => 'save2', 'delivery_charge' => '0', 'lines' => [['product_id' => $f['product'], 'quantity' => '2', 'unit_price' => '5', 'discount' => '0']]])->assertCreated()->json('id');
        $this->assertSame('2.00', DB::table('sales')->where('id', $sale)->value('promotion_discount'));
        $this->assertSame('8.00', DB::table('sales')->where('id', $sale)->value('net_total'));
        $this->assertSame(1, DB::table('promotion_redemptions')->count());
        $this->postJson('/sales', ['operation_key' => 'EXT-PROMO-SALE02', 'reference' => 'EXT-PROMO-2', 'channel_id' => $f['channel'], 'customer_id' => $f['customer'], 'ordered_on' => '2026-10-03', 'promotion_code' => 'SAVE2', 'delivery_charge' => '0', 'lines' => [['product_id' => $f['product'], 'quantity' => '1', 'unit_price' => '5', 'discount' => '0']]])->assertUnprocessable();
    }

    public function test_delivery_charge_credit_and_refund_are_capped_by_cash_received(): void
    {
        $f = $this->fixture();
        $sale = $this->dispatchedSale($f, 'EXT-SALE-DELIVERY', '4.00');
        $this->postJson('/sales/'.$sale.'/payments', ['operation_key' => 'EXT-DELIVERY-PAY', 'amount' => '2.00', 'account_id' => $f['cash'], 'paid_on' => '2026-10-03', 'method' => 'cash', 'reference' => 'CASH-DELIVERY'])->assertOk();
        $fulfillment = DB::table('fulfillments')->where('sale_id', $sale)->value('id');
        $credit = $this->postJson('/sales/'.$sale.'/delivery-credits', ['operation_key' => 'EXT-DELIVERY-CR01', 'fulfillment_id' => $fulfillment, 'reference' => 'DEL-CR-001', 'credited_on' => '2026-10-04', 'amount' => '2.00', 'reason' => 'Partial delivery charge credit'])->assertCreated()->json('id');
        $this->postJson('/delivery-credits/'.$credit.'/refund', ['operation_key' => 'EXT-DELIVERY-RF01', 'account_id' => $f['cash'], 'amount' => '2.00', 'refunded_on' => '2026-10-04', 'reason' => 'Cash refund for delivery credit'])->assertCreated();
        $this->postJson('/delivery-credits/'.$credit.'/refund', ['operation_key' => 'EXT-DELIVERY-RF02', 'account_id' => $f['cash'], 'amount' => '0.01', 'refunded_on' => '2026-10-04', 'reason' => 'Must not exceed delivery credit'])->assertUnprocessable();
    }

    public function test_csv_imports_are_atomic_and_reports_export_real_xlsx_and_pdf(): void
    {
        $f = $this->fixture();
        $csv = "sku,name,unit,purchase_unit,unit_conversion,purchase_currency,purchase_price,selling_price,tax_rate,minimum_stock\nIM-01,Imported product,piece,piece,1,EUR,2,5,0,1\n";
        $this->get('/imports/products/template')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->assertSee('sku,name,unit', false);
        $preview = UploadedFile::fake()->createWithContent('products.csv', $csv);
        $this->postJson('/imports/products/preview', ['file' => $preview])->assertOk()->assertJsonPath('total', 1)->assertJsonPath('rows.0.sku', 'IM-01');
        $file = UploadedFile::fake()->createWithContent('products.csv', $csv);
        $this->withHeader('Accept', 'application/json')->post('/imports/products', ['file' => $file])->assertCreated()->assertJsonPath('rows', 1);
        $this->assertDatabaseHas('products', ['sku' => 'IM-01']);
        $again = UploadedFile::fake()->createWithContent('products.csv', $csv);
        $this->withHeader('Accept', 'application/json')->post('/imports/products', ['file' => $again])->assertUnprocessable();
        $this->dispatchedSale($f, 'EXT-SALE-EXPORT');
        $xlsx = $this->get('/reports/trial-balance/export?format=xlsx')->assertOk();
        $this->assertStringStartsWith("PK\x03\x04", $xlsx->getContent());
        $pdf = $this->get('/reports/trial-balance/export?format=pdf')->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
    }

    public function test_scoped_api_token_expires_by_revocation_and_never_returns_the_secret_again(): void
    {
        $f = $this->fixture();
        $created = $this->postJson('/api-tokens', ['user_id' => $f['user']->id, 'name' => 'Catalog integration', 'abilities' => ['catalog.read'], 'expires_at' => now()->addDay()->toDateString()])->assertCreated()->json();
        $this->assertArrayHasKey('token', $created);
        $this->withHeader('Authorization', 'Bearer '.$created['token'])->getJson('/api/v1/products')->assertOk()->assertJsonPath('data.0.sku', 'EXT-01');
        $this->withHeader('Authorization', 'Bearer '.$created['token'])->getJson('/api/v1/sales')->assertForbidden();
        $this->post('/api-tokens/'.$created['id'].'/revoke', ['reason' => 'Integration no longer approved'])->assertRedirect();
        $this->withHeader('Authorization', 'Bearer '.$created['token'])->getJson('/api/v1/products')->assertUnauthorized();
        $this->assertDatabaseMissing('api_tokens', ['token_hash' => $created['token']]);
    }
}
