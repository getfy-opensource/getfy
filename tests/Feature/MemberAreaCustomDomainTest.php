<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberAreaDomain;
use App\Models\Product;
use Illuminate\Support\Str;
use Tests\TestCase;

class MemberAreaCustomDomainTest extends TestCase
{
    public function test_manifest_resolves_by_custom_domain_host(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $slug = strtolower('ab'.Str::random(4));
        $product = new Product([
            'tenant_id' => 1,
            'name' => 'Área de Membros',
            'slug' => $slug,
            'checkout_slug' => $slug,
            'type' => Product::TYPE_AREA_MEMBROS,
            'price' => 0,
            'is_active' => true,
        ]);
        $product->id = 1;
        $product->save();

        MemberAreaDomain::create([
            'product_id' => $product->id,
            'type' => MemberAreaDomain::TYPE_CUSTOM,
            'value' => 'curso.test',
        ]);

        $res = $this->get('http://curso.test/manifest.json');

        $res->assertStatus(200);
        $res->assertJsonFragment([
            'id' => '/m/'.$slug,
            'start_url' => 'http://curso.test',
        ]);
    }

    public function test_www_host_resolves_to_custom_domain_without_www(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $slug = strtolower('ab'.Str::random(4));
        $product = new Product([
            'tenant_id' => 1,
            'name' => 'Área de Membros',
            'slug' => $slug,
            'checkout_slug' => $slug,
            'type' => Product::TYPE_AREA_MEMBROS,
            'price' => 0,
            'is_active' => true,
        ]);
        $product->id = 1;
        $product->save();

        MemberAreaDomain::create([
            'product_id' => $product->id,
            'type' => MemberAreaDomain::TYPE_CUSTOM,
            'value' => 'curso.test',
        ]);

        $res = $this->get('http://www.curso.test/manifest.json');

        $res->assertStatus(200);
        $res->assertJsonFragment([
            'id' => '/m/'.$slug,
            'start_url' => 'http://www.curso.test',
        ]);
    }

    public function test_guest_on_custom_domain_redirects_to_login_on_same_host(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $slug = strtolower('ab'.Str::random(4));
        $product = new Product([
            'tenant_id' => 1,
            'name' => 'Área de Membros',
            'slug' => $slug,
            'checkout_slug' => $slug,
            'type' => Product::TYPE_AREA_MEMBROS,
            'price' => 0,
            'is_active' => true,
        ]);
        $product->id = 1;
        $product->save();

        MemberAreaDomain::create([
            'product_id' => $product->id,
            'type' => MemberAreaDomain::TYPE_CUSTOM,
            'value' => 'curso.test',
        ]);

        $res = $this->get('http://curso.test/');

        $res->assertRedirect('/login');
    }
}
