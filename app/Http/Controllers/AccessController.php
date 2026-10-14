<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class AccessController extends Controller
{
    public function role(Request $r, int $id)
    {
        Gate::authorize('users.manage');
        $d = $r->validate(['permissions' => 'present|array', 'permissions.*' => ['required', 'string', Rule::in(config('techpoint.permissions'))], 'reason' => 'required|string|min:10|max:1000']);
        DB::transaction(function () use ($id, $d) {
            $role = Role::where('id', $id)->lockForUpdate()->firstOrFail();
            if ($role->name === 'owner') {
                throw new \DomainException('Lejet e rolit të pronarit nuk zvogëlohen përmes kësaj rrjedhe.');
            } $role->permissions()->sync(Permission::whereIn('name', $d['permissions'])->pluck('id'));
            Audit::record('role.permissions_changed', 'role', $id, ['permissions' => $d['permissions'], 'reason' => $d['reason']]);
        });

        return back()->with('success', 'Lejet e rolit u përditësuan.');
    }

    public function user(Request $r, int $id)
    {
        Gate::authorize('users.manage');
        $d = $r->validate(['role_id' => 'required|integer|exists:roles,id', 'active' => 'required|boolean', 'reason' => 'required|string|min:10|max:1000']);
        DB::transaction(function () use ($id, $d) {
            $u = User::where('id', $id)->lockForUpdate()->firstOrFail();
            $newRole = Role::findOrFail($d['role_id']);
            if ($u->roles()->where('name', 'owner')->exists() && (! $d['active'] || $newRole->name !== 'owner')) {
                $others = User::where('id', '!=', $id)->where('active', true)->whereHas('roles', fn ($q) => $q->where('name', 'owner'))->lockForUpdate()->count();
                if (! $others) {
                    throw new \DomainException('Duhet të mbetet të paktën një pronar aktiv.');
                }
            } $u->forceFill(['active' => $d['active']])->save();
            $u->roles()->sync([$newRole->id]);
            if (! $d['active']) {
                DB::table('sessions')->where('user_id', $id)->delete();
            } Audit::record('user.access_changed', 'user', $id, ['role_id' => $newRole->id, 'active' => $d['active'], 'reason' => $d['reason']]);
        });

        return back()->with('success','Qasja u përditësua.');
    }
}
