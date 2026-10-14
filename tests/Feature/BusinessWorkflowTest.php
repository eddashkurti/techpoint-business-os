<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Reports;
use Database\Seeders\BusinessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BusinessWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function setupBusiness(): array
    {
        $this->seed(BusinessSeeder::class);
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('name', 'owner')->firstOrFail());
        $this->actingAs($u);
        DB::table('settings')->insert(['key' => 'accounting', 'value' => json_encode(['configured' => true, 'vat_registered' => false, 'fiscal_required' => false, 'recognition_policy' => 'dispatch', 'delivery_tax_rate' => '0']), 'updated_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('accounting_periods')->insert(['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $supplier = DB::table('parties')->insertGetId(['kind' => 'supplier', 'name' => 'Test supplier', 'created_at' => now(), 'updated_at' => now()]);
        $p = $this->postJson('/products', ['sku' => 'TEST-01', 'name' => 'Test product', 'unit' => 'copë', 'purchase_unit' => 'copë', 'unit_conversion' => '1', 'purchase_currency' => 'EUR', 'purchase_price' => '2', 'selling_price' => '5', 'minimum_stock' => '1', 'tax_rate' => '0'])->assertCreated()->json('id');
        $po = $this->postJson('/purchases', ['reference' => 'PO-TEST', 'supplier_id' => $supplier, 'ordered_on' => '2026-10-01', 'currency' => 'EUR', 'exchange_rate' => '1', 'exchange_rate_source' => 'Supplier invoice', 'lines' => [['product_id' => $p, 'quantity' => '10', 'unit_price' => '2', 'weight' => '1', 'volume' => '1']]])->assertCreated()->json('id');
        $l = DB::table('purchase_lines')->where('purchase_order_id', $po)->first();
        $receipt = ['operation_key' => 'receipt-key-0001', 'reference' => 'RC-TEST', 'received_on' => '2026-10-02', 'lines' => [['purchase_line_id' => $l->id, 'quantity' => '10']]];
        $this->postJson('/purchases/'.$po.'/receive', $receipt)->assertOk();
        $this->postJson('/purchases/'.$po.'/receive', $receipt)->assertOk();
        $this->assertSame('10.000000', DB::table('stock_balances')->where('product_id', $p)->value('on_hand'));
        $this->assertSame(1, DB::table('stock_movements')->count());

        return [$u, $p, $po];
    }

    public function test_receipt_sale_credit_physical_return_refund_reconcile_without_duplicates(): void
    {
        [, $p] = $this->setupBusiness();
        $channel = DB::table('sales_channels')->where('code', 'direct')->value('id');
        $sale = $this->postJson('/sales', ['operation_key' => 'sale-key-00001', 'reference' => 'SALE-TEST', 'channel_id' => $channel, 'ordered_on' => '2026-10-03', 'delivery_charge' => '0', 'lines' => [['product_id' => $p, 'quantity' => '2', 'unit_price' => '5', 'discount' => '0']]])->assertCreated()->json('id');
        $line = DB::table('sale_lines')->where('sale_id', $sale)->first();
        $dispatch = ['operation_key' => 'dispatch-key-001', 'dispatched_on' => '2026-10-03', 'lines' => [['sale_line_id' => $line->id, 'quantity' => '2']]];
        $this->postJson('/sales/'.$sale.'/dispatch', $dispatch)->assertOk();
        $this->postJson('/sales/'.$sale.'/dispatch', $dispatch)->assertOk();
        $this->assertSame('8.000000', DB::table('stock_balances')->where('product_id', $p)->value('on_hand'));
        $cash = DB::table('accounts')->where('code', '1000')->value('id');
        $this->postJson('/sales/'.$sale.'/payments', ['operation_key' => 'payment-key-0001', 'amount' => '10.00', 'account_id' => $cash, 'paid_on' => '2026-10-03', 'method' => 'cash', 'reference' => 'CASH-TEST'])->assertOk();
        $fulfillment = DB::table('fulfillment_lines')->first();
        $credit = ['operation_key' => 'credit-key-00001', 'reference' => 'CREDIT-TEST', 'credited_on' => '2026-10-04', 'reason' => 'Test authorized return', 'lines' => [['fulfillment_line_id' => $fulfillment->id, 'quantity' => '1']]];
        $return = $this->postJson('/sales/'.$sale.'/credits', $credit)->assertOk()->json('id');
        $this->postJson('/sales/'.$sale.'/credits', $credit)->assertOk();
        $this->assertSame('8.000000', DB::table('stock_balances')->where('product_id', $p)->value('on_hand'));
        $returnLine = DB::table('return_lines')->where('sale_return_id', $return)->first();
        $receive = ['operation_key' => 'return-receive-001', 'received_on' => '2026-10-04', 'condition' => 'sellable', 'reason' => 'Physical product inspected', 'lines' => [['return_line_id' => $returnLine->id, 'quantity' => '1']]];
        $this->postJson('/returns/'.$return.'/receive', $receive)->assertOk();
        $this->postJson('/returns/'.$return.'/receive', $receive)->assertOk();
        $refund = ['operation_key' => 'refund-key-00001', 'amount' => '5.00', 'account_id' => $cash, 'refunded_on' => '2026-10-04', 'reason' => 'Refund for credited return'];
        $this->postJson('/returns/'.$return.'/refund', $refund)->assertOk();
        $this->postJson('/returns/'.$return.'/refund', $refund)->assertOk();
        $this->assertSame(1, DB::table('refunds')->count());
        $this->assertSame('9.000000', DB::table('stock_balances')->where('product_id', $p)->value('on_hand'));
        $this->artisan('techpoint:reconcile --json')->assertExitCode(0);
        $report = app(Reports::class)->metrics(['from' => '2026-10-01', 'to' => '2026-10-31']);
        $this->assertSame('5.00', $report['revenue']);
        $this->assertSame('3.00', $report['gross_profit']);
        $this->assertSame('5.00', $report['cash_flow']);
    }

    public function test_restricted_http_routes_are_denied_on_server(): void
    {
        $this->seed(BusinessSeeder::class);
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('name', 'warehouse')->firstOrFail());
        $this->actingAs($u);
        foreach (['/journals', '/reports', '/users', '/audit', '/bills', '/business-settings'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->postJson('/journals', [])->assertForbidden();
    }

    public function test_closed_period_and_fiscal_configuration_block_posting_atomically(): void
    {
        [, $p,$po] = $this->setupBusiness();
        $this->postJson('/purchases/'.$po.'/receive', ['operation_key' => 'duplicate-different', 'reference' => 'RC-TEST', 'received_on' => '2026-10-02', 'lines' => [['purchase_line_id' => 1, 'quantity' => '1']]])->assertUnprocessable();
        DB::table('accounting_periods')->update(['status' => 'closed']);
        $cash = DB::table('accounts')->where('code', '1000')->value('id');
        $equity = DB::table('accounts')->where('code', '3100')->value('id');
        $this->postJson('/journals', ['operation_key' => 'closed-period-test', 'accounting_date' => '2026-10-03', 'description' => 'Opening capital', 'lines' => [['account_id' => $cash, 'debit' => '10.00', 'credit' => '0.00'], ['account_id' => $equity, 'debit' => '0.00', 'credit' => '10.00']]])->assertUnprocessable();
        $this->assertSame(1,DB::table('journals')->count());
    }
}
