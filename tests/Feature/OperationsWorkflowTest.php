<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Reports;
use Database\Seeders\BusinessSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OperationsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $policy = 'dispatch'): array
    {
        $this->seed(BusinessSeeder::class);
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'owner')->firstOrFail());
        $this->actingAs($user);
        DB::table('settings')->insert(['key' => 'accounting', 'value' => json_encode(['configured' => true, 'vat_registered' => false, 'fiscal_required' => false, 'recognition_policy' => $policy, 'delivery_tax_rate' => '0']), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('accounting_periods')->insert(['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'created_at' => now(), 'updated_at' => now()]);
        $supplier = DB::table('parties')->insertGetId(['kind' => 'supplier', 'name' => 'Furnitor Test', 'created_at' => now(), 'updated_at' => now()]);
        $p = $this->postJson('/products', ['sku' => 'OPS-01', 'name' => 'Produkt Test', 'unit' => 'copë', 'purchase_unit' => 'pako', 'unit_conversion' => '3', 'purchase_currency' => 'EUR', 'purchase_price' => '1', 'selling_price' => '5', 'minimum_stock' => '1', 'tax_rate' => '0'])->assertCreated()->json('id');
        $po = $this->postJson('/purchases', ['reference' => 'OPS-PO', 'supplier_id' => $supplier, 'ordered_on' => '2026-10-01', 'currency' => 'EUR', 'exchange_rate' => '1', 'exchange_rate_source' => 'Invoice', 'lines' => [['product_id' => $p, 'quantity' => '4', 'unit_price' => '6', 'weight' => '1', 'volume' => '1']]])->assertCreated()->json('id');
        $line = DB::table('purchase_lines')->where('purchase_order_id', $po)->first();
        $this->assertSame('12.000000', $line->quantity);
        $this->assertSame('24.00000000', $line->invoice_value);
        $this->postJson("/purchases/$po/receive", ['operation_key' => 'OPS-RECEIPT-001', 'reference' => 'OPS-RC', 'received_on' => '2026-10-02', 'lines' => [['purchase_line_id' => $line->id, 'quantity' => '12']]])->assertOk();

        return ['product' => $p, 'purchase' => $po, 'purchase_line' => $line->id, 'cash' => DB::table('accounts')->where('code', '1000')->value('id'), 'bank' => DB::table('accounts')->where('code', '1010')->value('id'), 'expense' => DB::table('accounts')->where('code', '6100')->value('id'), 'channel' => DB::table('sales_channels')->where('code', 'direct')->value('id')];
    }

    private function sale(array $f, string $quantity = '2'): int
    {
        return $this->postJson('/sales', ['operation_key' => 'OPS-SALE-001', 'reference' => 'OPS-SALE', 'channel_id' => $f['channel'], 'ordered_on' => '2026-10-03', 'delivery_charge' => '0', 'lines' => [['product_id' => $f['product'], 'quantity' => $quantity, 'unit_price' => '5', 'discount' => '0']]])->assertCreated()->json('id');
    }

    public function test_stock_count_condition_and_supplier_return_are_auditable_and_replay_safe(): void
    {
        $f = $this->fixture();
        $d = ['operation_key' => 'OPS-CONDITION-001', 'quantity' => '2', 'from' => 'sellable', 'to' => 'damaged', 'accounting_date' => '2026-10-03', 'reason' => 'Inspected damaged packaging'];
        $this->postJson('/products/'.$f['product'].'/condition', $d)->assertOk();
        $this->postJson('/products/'.$f['product'].'/condition', $d)->assertOk();
        $this->assertSame('4.00000000', DB::table('stock_balances')->where('condition', 'damaged')->value('value'));
        $count = $this->postJson('/stock-counts', ['reference' => 'OPS-COUNT', 'counted_on' => '2026-10-03', 'reason' => 'Physical warehouse count', 'lines' => [['product_id' => $f['product'], 'quantity' => '9']]])->assertCreated()->json('id');
        $this->postJson("/stock-counts/$count/confirm", ['operation_key' => 'OPS-COUNT-CONFIRM'])->assertOk();
        $return = ['operation_key' => 'OPS-SUPPLIER-RETURN', 'reference' => 'OPS-SR', 'returned_on' => '2026-10-04', 'reason' => 'Damaged goods returned to supplier', 'condition' => 'damaged', 'lines' => [['purchase_line_id' => $f['purchase_line'], 'quantity' => '2']]];
        $this->postJson('/purchases/'.$f['purchase'].'/supplier-return', $return)->assertOk();
        $this->postJson('/purchases/'.$f['purchase'].'/supplier-return', $return)->assertOk();
        $this->assertSame(1, DB::table('supplier_returns')->count());
        $this->assertSame('4.00', DB::table('supplier_returns')->value('claim_value'));
        $this->assertSame('0.000000', DB::table('stock_balances')->where('condition', 'damaged')->value('on_hand'));
        $this->artisan('techpoint:reconcile --json')->assertExitCode(0);
        $this->get('/counts')->assertOk();
        $this->get('/supplier-returns')->assertOk();
    }

    public function test_recurring_confirmation_drafts_and_duplicate_null_supplier_invoices(): void
    {
        $f = $this->fixture();
        $id = $this->postJson('/recurring', ['name' => 'Hosting invoice', 'account_id' => $f['expense'], 'amount' => '8.00', 'next_on' => '2026-10-01'])->assertCreated()->json('id');
        $d = ['operation_key' => 'OPS-RECURRING-001', 'reference' => 'HOST-2026-10', 'accounting_date' => '2026-10-03', 'vat_amount' => '0', 'recoverable_vat' => '0'];
        $this->postJson("/recurring/$id/confirm", $d)->assertOk();
        $this->postJson("/recurring/$id/confirm", $d)->assertOk();
        $this->assertSame(1, DB::table('bills')->count());
        $this->assertSame('2026-11-01', DB::table('recurring_expenses')->value('next_on'));
        $bill = ['operation_key' => 'OPS-BILL-OTHER', 'reference' => 'HOST-2026-10', 'accounting_date' => '2026-10-03', 'account_id' => $f['expense'], 'net_amount' => '8', 'vat_amount' => '0', 'recoverable_vat' => '0', 'category' => 'hosting', 'description' => 'Duplicate invoice'];
        $this->postJson('/bills', $bill)->assertUnprocessable();
        $draft = ['reference' => 'CAPITAL', 'accounting_date' => '2026-10-03', 'description' => 'Opening capital', 'lines' => [['account_id' => $f['cash'], 'debit' => '10', 'credit' => '0'], ['account_id' => DB::table('accounts')->where('code', '3100')->value('id'), 'debit' => '0', 'credit' => '9']]];
        $id = $this->postJson('/journal-drafts', $draft)->assertOk()->json('id');
        $this->postJson("/drafts/$id/post", ['operation_key' => 'OPS-DRAFT-POST'])->assertUnprocessable();
        $draft['lines'][1]['credit'] = '10';
        $this->postJson("/drafts/$id/edit", $draft)->assertOk();
        $this->postJson("/drafts/$id/post", ['operation_key' => 'OPS-DRAFT-POST'])->assertOk();
        $this->postJson("/drafts/$id/post", ['operation_key' => 'OPS-DRAFT-POST'])->assertOk();
        $this->postJson("/drafts/$id/edit", $draft)->assertUnprocessable();
        $this->assertSame('posted', DB::table('journal_drafts')->value('status'));
    }

    public function test_partial_fulfillment_payment_and_filtered_margin_use_recognized_revenue(): void
    {
        $f = $this->fixture();
        $sale = $this->sale($f);
        $line = DB::table('sale_lines')->where('sale_id', $sale)->first();
        $this->postJson("/sales/$sale/dispatch", ['operation_key' => 'OPS-DISPATCH-001', 'dispatched_on' => '2026-10-03', 'lines' => [['sale_line_id' => $line->id, 'quantity' => '1']]])->assertOk();
        $payment = ['operation_key' => 'OPS-PAYMENT-001', 'account_id' => $f['cash'], 'amount' => '3.00', 'paid_on' => '2026-10-03', 'method' => 'cash', 'reference' => 'CASH-01'];
        $this->postJson("/sales/$sale/payments", $payment)->assertOk();
        $payment['operation_key'] = 'OPS-PAYMENT-002';
        $this->postJson("/sales/$sale/payments", $payment)->assertUnprocessable();
        $metrics = app(Reports::class)->metrics(['from' => '2026-10-01', 'to' => '2026-10-31', 'channel' => 'direct', 'product_id' => $f['product']]);
        $this->assertSame('5.00', $metrics['revenue']);
        $this->assertSame('3.00', $metrics['gross_profit']);
        $this->assertCount(12, app(Reports::class)->monthly());
        $this->get('/dashboard')->assertOk();
        $this->get('/reports?channel=direct')->assertOk();
        $this->get('/reports/profit-loss/export?channel=direct')->assertOk();
    }

    public function test_courier_shortfall_remains_receivable_and_bank_match_does_not_duplicate_cash(): void
    {
        $f = $this->fixture('delivery');
        $courier = DB::table('parties')->insertGetId(['kind' => 'courier', 'name' => 'Courier Test', 'created_at' => now(), 'updated_at' => now()]);
        $sale = $this->sale($f);
        $line = DB::table('sale_lines')->where('sale_id', $sale)->first();
        $fulfillment = $this->postJson("/sales/$sale/dispatch", ['operation_key' => 'OPS-DISPATCH-001', 'courier_id' => $courier, 'dispatched_on' => '2026-10-03', 'lines' => [['sale_line_id' => $line->id, 'quantity' => '2']]])->assertOk()->json('id');
        $this->assertSame('0.00', app(Reports::class)->metrics()['revenue']);
        $this->postJson("/fulfillments/$fulfillment/deliver", ['operation_key' => 'OPS-DELIVER-001', 'delivered_on' => '2026-10-04'])->assertOk();
        $delivery = DB::table('deliveries')->value('id');
        $this->postJson("/deliveries/$delivery/collect", ['operation_key' => 'OPS-COLLECT-001', 'amount' => '10.00', 'accounting_date' => '2026-10-04', 'reference' => 'COD-001'])->assertOk();
        $settlement = ['operation_key' => 'OPS-SETTLE-001', 'courier_id' => $courier, 'reference' => 'COURIER-REPORT-01', 'settled_on' => '2026-10-05', 'account_id' => $f['bank'], 'fees' => '1.00', 'remitted' => '7.00', 'lines' => [['delivery_id' => $delivery, 'amount' => '10.00']]];
        $this->postJson('/courier-settlements', $settlement)->assertOk()->assertJsonPath('difference', '2.00');
        $this->postJson('/courier-settlements', $settlement)->assertOk();
        $m = app(Reports::class)->metrics();
        $this->assertSame('2.00', $m['receivable']);
        $this->assertSame('7.00', $m['cash_flow']);
        $this->assertSame('1.00', $m['delivery']);
        $bank = $this->postJson('/bank', ['account_id' => $f['bank'], 'external_reference' => 'BANK-001', 'date' => '2026-10-05', 'amount' => '7.00', 'description' => 'Courier deposit'])->assertCreated()->json('id');
        $journal = DB::table('journals')->where('source_type', 'courier_settlement')->value('id');
        $this->postJson("/bank/$bank/match", ['operation_key' => 'OPS-BANK-MATCH', 'journal_id' => $journal, 'reason' => 'Verified bank statement'])->assertOk();
        $this->assertSame('7.00', app(Reports::class)->metrics()['cash_flow']);
        $this->artisan('techpoint:reconcile --json')->assertExitCode(0);
    }

    public function test_posted_journal_and_stock_records_cannot_be_changed_even_by_direct_database_update(): void
    {
        $this->fixture();
        foreach (['journals', 'stock_movements'] as $table) {
            try {
                DB::table($table)->where('id', DB::table($table)->value('id'))->delete();
                $this->fail('Immutable record was deleted');
            } catch (QueryException $e) {
                $this->assertStringContainsString('immutable', strtolower($e->getMessage()));
            }
        }
    }

    public function test_production_settings_cannot_disable_required_fiscalization_without_verified_exemption(): void
    {
        $this->fixture();
        $this->withSession(['_token' => 'fiscal-policy-test-token'])->withHeader('X-CSRF-TOKEN', 'fiscal-policy-test-token');
        $this->app->instance('env', 'production');
        config(['techpoint.fiscal_exemption_verified' => false]);
        $this->postJson('/business-settings', ['vat_registered' => false, 'fiscal_required' => false, 'recognition_policy' => 'dispatch', 'delivery_tax_rate' => '0', 'reason' => 'Unverified exemption request'])->assertUnprocessable();
        $this->app->instance('env', 'testing');
    }

    public function test_partial_deliveries_do_not_mark_the_entire_order_delivered(): void
    {
        $f = $this->fixture('delivery');
        $sale = $this->sale($f);
        $line = DB::table('sale_lines')->where('sale_id', $sale)->first();
        $first = $this->postJson("/sales/$sale/dispatch", ['operation_key' => 'OPS-PARTIAL-001', 'dispatched_on' => '2026-10-03', 'lines' => [['sale_line_id' => $line->id, 'quantity' => '1']]])->assertOk()->json('id');
        $this->postJson("/fulfillments/$first/deliver", ['operation_key' => 'OPS-DELIVERY-001', 'delivered_on' => '2026-10-04'])->assertOk();
        $this->assertSame('partially_delivered', DB::table('sales')->where('id', $sale)->value('delivery_status'));
        $second = $this->postJson("/sales/$sale/dispatch", ['operation_key' => 'OPS-PARTIAL-002', 'dispatched_on' => '2026-10-05', 'lines' => [['sale_line_id' => $line->id, 'quantity' => '1']]])->assertOk()->json('id');
        $this->postJson("/fulfillments/$second/deliver", ['operation_key' => 'OPS-DELIVERY-002', 'delivered_on' => '2026-10-06'])->assertOk();
        $this->assertSame('delivered', DB::table('sales')->where('id', $sale)->value('delivery_status'));
        $this->postJson("/sales/$sale/cancel", ['operation_key' => 'OPS-CANCEL-001', 'reason' => 'Invalid cancellation of shipped goods'])->assertUnprocessable();
    }

    public function test_private_product_photos_and_labels_require_document_permissions(): void
    {
        $f = $this->fixture();
        $this->get('/products/'.$f['product'].'/label')->assertOk()->assertSee('<svg', false);
        Storage::fake('local');
        $image = UploadedFile::fake()->createWithContent('photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6Vn8AAAAASUVORK5CYII='));
        $id = $this->postJson('/documents', ['document_type' => 'product', 'document_id' => $f['product'], 'file' => $image])->assertCreated()->json('id');
        Storage::disk('local')->assertExists(DB::table('attachments')->where('id', $id)->value('path'));
        $this->get('/documents/'.$id)->assertOk();
        $this->actingAs(User::factory()->create());
        $this->get('/documents/'.$id)->assertForbidden();
        $this->get('/products/'.$f['product'].'/label')->assertForbidden();
    }
}
