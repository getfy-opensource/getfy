<?php

namespace App\Http\Middleware;

use App\Models\Product;
use App\Services\MemberAreaResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Routing\Middleware\ValidateSignature;
use Symfony\Component\HttpFoundation\Response;

class SignedOrMemberAreaRedirect
{
    public function __construct(
        protected MemberAreaResolver $memberAreaResolver,
    ) {}

    /**
     * Behaves like Laravel's "signed" middleware, but when the signature is invalid
     * we gracefully redirect the user to the member area (instead of showing 403).
     *
     * @param  \Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$args): Response
    {
        try {
            return app(ValidateSignature::class)->handle($request, $next, ...$args);
        } catch (InvalidSignatureException $e) {
            $product = $request->route('product')
                ?? $request->attributes->get('member_area_product');

            if (! $product instanceof Product) {
                $productId = (int) $request->query('p', 0);
                $product = $productId > 0 ? Product::find($productId) : null;
            }

            if ($product instanceof Product && $product->type === Product::TYPE_AREA_MEMBROS) {
                $base = rtrim($this->memberAreaResolver->baseUrlForProduct($product), '/');
                return redirect()->to($base.'/login');
            }

            // Last resort: avoid exposing signature error
            return redirect()->to('/login');
        }
    }
}

