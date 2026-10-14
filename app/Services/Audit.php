<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class Audit
{
    public static function record(string $action, ?string $type = null, string|int|null $id = null, array $details = []): void
    {
        DB::table('audit_events')->insert(['user_id' => auth()->id(), 'action' => $action, 'subject_type' => $type, 'subject_id' => $id === null ? null : (string) $id, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'ip_address' => app()->runningInConsole() ? null : request()->ip(), 'created_at' => now()]);
    }
}
