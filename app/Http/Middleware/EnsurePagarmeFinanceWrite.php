<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Ações de dinheiro na conta Pagar.me só existem com GETFY_PAGARME_FINANCE_WRITE=true. */
class EnsurePagarmeFinanceWrite
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless((bool) config('getfy.pagarme_finance_write'), 404);

        return $next($request);
    }
}
