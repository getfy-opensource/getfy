<?php

namespace Tests\Feature;

use App\Http\Controllers\CheckoutController;
use App\Http\Middleware\EnsureInstalled;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\ProductOrderBump;
use App\Models\User;
use ReflectionMethod;
use Tests\TestCase;

class CheckoutComboHidesOrderBumpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureInstalled::class);
    }

    public function test_checkout_hides_order_bump_when_target_is_in_offer_combo(): void
    {
        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $bonus = $this->createTestProduct(['name' => 'Bônus no combo', 'price' => 47]);
        $other = $this->createTestProduct(['name' => 'Outro bump', 'price' => 27]);
        $main = $this->createTestProduct(['name' => 'Principal', 'price' => 97]);

        $hiddenBump = ProductOrderBump::create([
            'product_id' => $main->id,
            'target_product_id' => $bonus->id,
            'title' => 'Bump do bônus',
            'cta_title' => 'Quero o bônus',
            'position' => 1,
        ]);
        $visibleBump = ProductOrderBump::create([
            'product_id' => $main->id,
            'target_product_id' => $other->id,
            'title' => 'Bump extra',
            'cta_title' => 'Adicionar',
            'position' => 2,
        ]);

        $offer = ProductOffer::create([
            'product_id' => $main->id,
            'name' => 'Pacote com combo',
            'price' => 79,
            'currency' => 'BRL',
            'checkout_slug' => ProductOffer::generateUniqueCheckoutSlug(),
            'position' => 1,
            'combo_product_ids' => [$bonus->id],
        ]);

        $withOffer = $this->get('/c/'.$main->checkout_slug.'?offer='.$offer->public_id);
        $withOffer->assertOk();
        $withOffer->assertInertia(fn ($page) => $page
            ->has('order_bumps', 1)
            ->where('order_bumps.0.id', $visibleBump->id)
            ->where('order_bumps.0.title', 'Bump extra')
        );

        $withoutOffer = $this->get('/c/'.$main->checkout_slug);
        $withoutOffer->assertOk();
        $withoutOffer->assertInertia(fn ($page) => $page
            ->has('order_bumps', 2)
            ->where('order_bumps', function ($bumps) use ($hiddenBump, $visibleBump) {
                $ids = collect($bumps)->pluck('id')->all();

                return in_array($hiddenBump->id, $ids, true)
                    && in_array($visibleBump->id, $ids, true);
            })
        );
    }

    public function test_checkout_hides_order_bump_when_target_is_in_product_combo(): void
    {
        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $bonus = $this->createTestProduct(['name' => 'Combo base', 'price' => 10]);
        $main = $this->createTestProduct([
            'name' => 'Principal com combo',
            'price' => 50,
            'combo_product_ids' => [$bonus->id],
        ]);

        ProductOrderBump::create([
            'product_id' => $main->id,
            'target_product_id' => $bonus->id,
            'title' => 'Não deve aparecer',
            'cta_title' => 'Quero',
            'position' => 1,
        ]);

        $this->get('/c/'.$main->checkout_slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('order_bumps', 0));
    }

    public function test_process_ignores_order_bump_ids_already_in_combo(): void
    {
        $bonus = $this->createTestProduct(['name' => 'Combo alvo']);
        $other = $this->createTestProduct(['name' => 'Bump permitido']);
        $main = $this->createTestProduct(['name' => 'Host']);

        $hiddenBump = ProductOrderBump::create([
            'product_id' => $main->id,
            'target_product_id' => $bonus->id,
            'title' => 'Oculto',
            'cta_title' => 'Quero',
            'position' => 1,
        ]);
        $allowedBump = ProductOrderBump::create([
            'product_id' => $main->id,
            'target_product_id' => $other->id,
            'title' => 'Permitido',
            'cta_title' => 'Adicionar',
            'position' => 2,
        ]);

        $offer = ProductOffer::create([
            'product_id' => $main->id,
            'name' => 'Oferta',
            'price' => 20,
            'currency' => 'BRL',
            'checkout_slug' => ProductOffer::generateUniqueCheckoutSlug(),
            'position' => 1,
            'combo_product_ids' => [$bonus->id],
        ]);

        $controller = app(CheckoutController::class);
        $method = new ReflectionMethod(CheckoutController::class, 'filterOrderBumpIdsExcludedByCombo');
        $method->setAccessible(true);

        $filtered = $method->invoke(
            $controller,
            $main,
            $offer,
            null,
            [$hiddenBump->id, $allowedBump->id]
        );

        $this->assertSame([$allowedBump->id], $filtered);
    }
}
