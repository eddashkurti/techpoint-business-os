<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ApiTokenService
{
    /** @return array{id:int,token:string,prefix:string} */
    public function create(User $user, string $name, array $abilities, ?string $expiresAt): array
    {
        if (! $user->active) {
            throw new \DomainException('Nuk mund të krijohet token për përdorues joaktiv.');
        }
        $raw = 'tp_'.bin2hex(random_bytes(32));
        $prefix = substr($raw, 0, 19);
        $id = DB::table('api_tokens')->insertGetId(['user_id' => $user->id, 'name' => $name, 'token_prefix' => $prefix, 'token_hash' => hash('sha256', $raw), 'abilities' => json_encode(array_values($abilities), JSON_THROW_ON_ERROR), 'expires_at' => $expiresAt, 'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
        Audit::record('api_token.created', 'api_token', $id, ['user_id' => $user->id, 'abilities' => $abilities, 'expires_at' => $expiresAt]);

        return ['id' => $id, 'token' => $raw, 'prefix' => $prefix];
    }

    public function revoke(int $id, string $reason): void
    {
        $token = DB::table('api_tokens')->where('id', $id)->lockForUpdate()->firstOrFail();
        if ($token->revoked_at) {
            throw new \DomainException('Tokeni është revokuar tashmë.');
        }
        DB::table('api_tokens')->where('id', $id)->update(['revoked_at' => now(), 'updated_at' => now()]);
        Audit::record('api_token.revoked', 'api_token', $id, ['reason' => $reason]);
    }
}
