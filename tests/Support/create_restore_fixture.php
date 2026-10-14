<?php

use App\Models\User;
use App\Services\Purchasing;
use Database\Seeders\BusinessSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

// Run only from the isolated restore-drill test wrapper, never a production seeder.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (app()->environment() !== 'testing' || config('database.connections.mysql.database') !== 'techpoint_testing') {
    throw new RuntimeException('Refusing fixture outside test database.');
}
(new BusinessSeeder)->run();
$u = User::create(['name' => 'Restore fixture', 'email' => 'restore-fixture@example.invalid', 'password' => bin2hex(random_bytes(24))]);
auth()->login($u);
DB::table('settings')->insert(['key' => 'accounting', 'value' => json_encode(['configured' => true, 'vat_registered' => false, 'fiscal_required' => false, 'recognition_policy' => 'dispatch', 'delivery_tax_rate' => '0']), 'updated_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
DB::table('accounting_periods')->insert(['name' => 'Restore test', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
$supplier = DB::table('parties')->insertGetId(['kind' => 'supplier', 'name' => 'Furnitori i testit ë ç', 'created_at' => now(), 'updated_at' => now()]);
$product = DB::table('products')->insertGetId(['sku' => 'RESTORE-FIXTURE', 'name' => 'Produkt i testit ë ç', 'selling_price' => '5', 'unit_conversion' => '1', 'created_at' => now(), 'updated_at' => now()]);
$purchase = app(Purchasing::class)->create(['reference' => 'RESTORE-PO', 'supplier_id' => $supplier, 'ordered_on' => '2026-10-01', 'currency' => 'EUR', 'exchange_rate' => '1', 'exchange_rate_source' => 'test-only', 'lines' => [['product_id' => $product, 'quantity' => '10', 'unit_price' => '2', 'weight' => '1', 'volume' => '1']]]);
$line = DB::table('purchase_lines')->where('purchase_order_id', $purchase)->first();
app(Purchasing::class)->receive($purchase, ['operation_key' => 'restore-fixture-receipt', 'reference' => 'RESTORE-RC', 'received_on' => '2026-10-02', 'lines' => [['purchase_line_id' => $line->id, 'quantity' => '10']]]);
DB::table('integration_connections')->insert(['type' => 'woocommerce', 'name' => 'Encrypted test-only fixture', 'base_url' => 'https://example.invalid', 'credentials' => Crypt::encryptString('test-only-encrypted-value'), 'status' => 'disabled', 'created_at' => now(), 'updated_at' => now()]);
echo "Nonempty accounting/stock/encryption fixture created in the isolated test database.\n";
