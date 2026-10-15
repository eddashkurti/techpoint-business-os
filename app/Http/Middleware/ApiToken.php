<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class ApiToken
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $raw = $request->bearerToken();
        if (! is_string($raw) || ! str_starts_with($raw, 'tp_')) {
            return response()->json(['message' => 'Kërkohet token Bearer.'], 401);
        }
        $token = DB::table('api_tokens')->where('token_hash', hash('sha256', $raw))->first();
        if (! $token || $token->revoked_at || ($token->expires_at && now()->greaterThan($token->expires_at))) {
            return response()->json(['message' => 'Tokeni nuk është i vlefshëm.'], 401);
        }
        $abilities = json_decode($token->abilities, true, 512, JSON_THROW_ON_ERROR);
        if (! in_array($ability, $abilities, true)) {
            return response()->json(['message' => 'Tokeni nuk ka këtë leje.'], 403);
        }
        $user = User::find($token->user_id);
        if (! $user || ! $user->active) {
            return response()->json(['message' => 'Përdoruesi i tokenit nuk është aktiv.'], 401);
        }
        DB::table('api_tokens')->where('id', $token->id)->update(['last_used_at' => now(), 'updated_at' => now()]);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('api_token_id', $token->id);
        $request->attributes->set('api_token_abilities', $abilities);

        return $next($request);
    }
}
