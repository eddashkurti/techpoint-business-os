<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ApiTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class ApiTokenController extends Controller
{
    public function index()
    {
        Gate::authorize('users.manage');

        return Inertia::render('business/ApiTokens', ['tokens' => DB::table('api_tokens as t')->join('users as u', 'u.id', '=', 't.user_id')->select('t.id', 't.name', 't.token_prefix', 't.abilities', 't.expires_at', 't.last_used_at', 't.revoked_at', 'u.name as user_name', 'u.email as user_email')->orderByDesc('t.id')->limit(200)->get(), 'users' => User::where('active', true)->select('id', 'name', 'email')->orderBy('name')->get(), 'abilities' => config('techpoint.api_abilities')]);
    }

    public function store(Request $request, ApiTokenService $tokens)
    {
        Gate::authorize('users.manage');
        $data = $request->validate(['user_id' => 'required|integer|exists:users,id', 'name' => 'required|string|max:100', 'abilities' => 'required|array|min:1', 'abilities.*' => ['required', Rule::in(config('techpoint.api_abilities'))], 'expires_at' => 'nullable|date|after:today']);
        $result = $tokens->create(User::findOrFail($data['user_id']), $data['name'], $data['abilities'], $data['expires_at'] ?? null);
        if ($request->expectsJson()) {
            return response()->json($result, 201);
        }

        return redirect('/api-tokens')->with('success', 'Tokeni u krijua. Ruajeni tani; nuk shfaqet përsëri.')->with('api_token', $result['token']);
    }

    public function revoke(Request $request, int $id, ApiTokenService $tokens)
    {
        Gate::authorize('users.manage');
        $data = $request->validate(['reason' => 'required|string|min:5|max:1000']);
        DB::transaction(fn () => $tokens->revoke($id, $data['reason']));

        return back()->with('success', 'Tokeni u revokua.');
    }
}
