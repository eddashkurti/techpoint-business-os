<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! preg_match('/^techpoint_restore_drill_[0-9_]+$/D', config('database.connections.mysql.database'))) {
    exit(2);
}
$value = DB::table('integration_connections')->value('credentials');
if (Crypt::decryptString($value) !== 'test-only-encrypted-value') {
    exit(1);
}
echo "Encrypted fixture survives restoration.\n";
