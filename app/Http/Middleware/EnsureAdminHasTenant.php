<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class EnsureAdminHasTenant
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && $user->role === User::ROLE_ADMIN) {
            if ($user->tenant_id === null) {
                $user->update(['tenant_id' => $user->id]);
            }

            // Migração automática 1x: dados antigos criados pelo admin tinham tenant_id = null
            // (quando o admin ainda não tinha tenant). Depois que tenant_id muda, esses dados “somem” do painel.
            $cacheKey = 'admin_tenant_backfill_done:' . $user->id;
            if (! Cache::get($cacheKey)) {
                DB::transaction(function () use ($user) {
                    $tenantId = $user->tenant_id ?? $user->id;

                    DB::table('products')->whereNull('tenant_id')->update(['tenant_id' => $tenantId]);
                    DB::table('webhooks')->whereNull('tenant_id')->update(['tenant_id' => $tenantId]);
                    DB::table('settings')->whereNull('tenant_id')->update(['tenant_id' => $tenantId]);
                    DB::table('gateway_credentials')->whereNull('tenant_id')->update(['tenant_id' => $tenantId]);
                    DB::table('coupons')->whereNull('tenant_id')->update(['tenant_id' => $tenantId]);
                    DB::table('api_applications')->whereNull('tenant_id')->update(['tenant_id' => $tenantId]);
                });

                Cache::put($cacheKey, true, now()->addDays(365));
            }
        }

        return $next($request);
    }
}

