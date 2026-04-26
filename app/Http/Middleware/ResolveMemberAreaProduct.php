<?php

namespace App\Http\Middleware;

use App\Services\MemberAreaResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveMemberAreaProduct
{
    public function __construct(
        protected MemberAreaResolver $resolver
    ) {}

    /**
     * @param  \Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $resolved = $this->resolver->resolve($request);
        if (! $resolved) {
            // Fallback for magic access links: if the slug in the path is outdated/wrong,
            // try resolving by product id from the signed URL and continue so the next
            // middleware/controller can redirect gracefully.
            $routeName = $request->route()?->getName();
            if (in_array($routeName, ['member-area.magic-access'], true)) {
                $productId = (int) $request->query('p', 0);
                $product = $productId > 0 ? \App\Models\Product::find($productId) : null;
                if ($product && $product->type === \App\Models\Product::TYPE_AREA_MEMBROS) {
                    $slug = $product->checkout_slug;
                    try {
                        $base = app(\App\Services\MemberAreaResolver::class)->baseUrlForProduct($product);
                        $basePath = parse_url($base, PHP_URL_PATH);
                        if (is_string($basePath) && $basePath !== '') {
                            $segments = explode('/', trim($basePath, '/'));
                            if (($segments[0] ?? null) === 'm' && ! empty($segments[1])) {
                                $slug = (string) $segments[1];
                            }
                        }
                    } catch (\Throwable $e) {
                        // ignore and keep fallback slug
                    }
                    $resolved = [
                        'product' => $product,
                        'access_type' => 'path',
                        'slug' => $slug,
                    ];
                }
            }
            if (! $resolved) {
                abort(404, 'Área de membros não encontrada.');
            }
        }
        $request->attributes->set('member_area_product', $resolved['product']);
        $request->attributes->set('member_area_access_type', $resolved['access_type']);
        $request->attributes->set('member_area_slug', $resolved['slug']);
        $request->route()?->setParameter('product', $resolved['product']);
        $request->route()?->setParameter('slug', $resolved['slug']);

        return $next($request);
    }
}
