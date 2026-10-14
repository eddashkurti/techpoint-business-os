<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BusinessSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('techpoint.permissions') as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }
        foreach (config('techpoint.roles') as $name => $definition) {
            $role = Role::firstOrCreate(['name' => $name], ['label' => $definition['label']]);
            $names = $definition['permissions'] === ['*'] ? config('techpoint.permissions') : $definition['permissions'];
            if ($role->wasRecentlyCreated || $name === 'owner') {
                $role->permissions()->sync(Permission::whereIn('name', $names)->pluck('id'));
            }
        }
        $accounts = [['1000', 'Arka', 'asset'], ['1010', 'Banka', 'asset'], ['1100', 'Klientët / Korrierët', 'asset'], ['1200', 'Inventari', 'asset'], ['1210', 'Mallrat në transport', 'asset'], ['1300', 'TVSH e zbritshme', 'asset'], ['2100', 'Furnitorët', 'liability'], ['2150', 'Mallra të pranuara pa faturë', 'liability'], ['2200', 'TVSH e pagueshme', 'liability'], ['2300', 'Parapagimet e klientëve', 'liability'], ['3100', 'Kapitali', 'equity'], ['4000', 'Të ardhurat nga produktet', 'revenue'], ['4010', 'Të ardhurat nga dërgesat', 'revenue'], ['5000', 'Kostoja e mallrave të shitura', 'expense'], ['5100', 'Transporti dhe posta', 'expense'], ['6000', 'Reklamimi', 'expense'], ['6100', 'Shpenzime operative', 'expense'], ['6200', 'Tarifa bankare', 'expense'], ['6300', 'Dëmtime të inventarit', 'expense'], ['6900', 'Diferenca rrumbullakimi', 'expense']];
        foreach ($accounts as [$code,$name,$type]) {
            DB::table('accounts')->insertOrIgnore(['code' => $code, 'name' => $name, 'type' => $type, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
