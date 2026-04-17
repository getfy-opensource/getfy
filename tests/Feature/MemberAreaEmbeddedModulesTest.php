<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

class MemberAreaEmbeddedModulesTest extends TestCase
{
    public function test_embedded_modules_open_in_host_area_and_complete_lesson(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $hostSlug = 'host'.substr(uniqid('', true), -8);
        $sourceSlug = 'src'.substr(uniqid('', true), -8);

        $sourceProduct = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $sourceSlug,
            'name' => 'Curso origem',
        ]);

        $sourceSection = MemberSection::create([
            'product_id' => $sourceProduct->id,
            'title' => 'Cursos',
            'position' => 1,
            'section_type' => 'courses',
        ]);

        $sourceModule = MemberModule::create([
            'member_section_id' => $sourceSection->id,
            'product_id' => $sourceProduct->id,
            'title' => 'Módulo origem',
            'position' => 1,
        ]);

        $sourceLesson = MemberLesson::create([
            'member_module_id' => $sourceModule->id,
            'product_id' => $sourceProduct->id,
            'title' => 'Aula embed',
            'position' => 1,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => '<p>Conteúdo</p>',
        ]);

        $hostProduct = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $hostSlug,
            'name' => 'Hub',
        ]);

        $productsSection = MemberSection::create([
            'product_id' => $hostProduct->id,
            'title' => 'Outros',
            'position' => 1,
            'section_type' => 'products',
        ]);

        $wrapper = MemberModule::create([
            'member_section_id' => $productsSection->id,
            'product_id' => $hostProduct->id,
            'title' => $sourceModule->title,
            'position' => 1,
            'related_product_id' => $sourceProduct->id,
            'source_member_module_id' => $sourceModule->id,
            'access_type' => 'paid',
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $hostProduct->users()->attach($aluno->id);
        $sourceProduct->users()->attach($aluno->id);

        $show = $this->actingAs($aluno)->get('/m/'.$hostSlug);
        $show->assertStatus(200);
        $show->assertInertia(fn ($page) => $page->where('sections.0.modules.0.embed', true));

        $mod = $this->actingAs($aluno)->get('/m/'.$hostSlug.'/modulo/'.$wrapper->id);
        $mod->assertStatus(200);
        $mod->assertInertia(fn ($page) => $page->where('current_lesson.title', 'Aula embed'));

        $complete = $this->actingAs($aluno)->postJson('/m/'.$hostSlug.'/aula/'.$sourceLesson->id.'/complete');
        $complete->assertOk();
        $complete->assertJsonPath('success', true);

        $this->assertDatabaseHas('member_lesson_progress', [
            'user_id' => $aluno->id,
            'member_lesson_id' => $sourceLesson->id,
        ]);
    }

    public function test_store_module_imports_wrappers_for_member_area_product(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $sourceProduct = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'srcimp'.substr(uniqid('', true), -6),
            'name' => 'Origem import',
        ]);

        $sec = MemberSection::create([
            'product_id' => $sourceProduct->id,
            'title' => 'Aulas',
            'position' => 1,
            'section_type' => 'courses',
        ]);

        $m1 = MemberModule::create([
            'member_section_id' => $sec->id,
            'product_id' => $sourceProduct->id,
            'title' => 'M1',
            'position' => 1,
        ]);
        $m2 = MemberModule::create([
            'member_section_id' => $sec->id,
            'product_id' => $sourceProduct->id,
            'title' => 'M2',
            'position' => 2,
        ]);

        $hostProduct = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'hostimp'.substr(uniqid('', true), -6),
            'name' => 'Destino',
        ]);

        $productsSection = MemberSection::create([
            'product_id' => $hostProduct->id,
            'title' => 'Produtos',
            'position' => 1,
            'section_type' => 'products',
        ]);

        $this->actingAs($owner)->postJson(
            route('member-builder.modules.store', ['produto' => $hostProduct, 'section' => $productsSection]),
            [
                'title' => 'placeholder',
                'related_product_id' => $sourceProduct->id,
                'access_type' => 'free',
            ]
        )->assertStatus(200)
            ->assertJsonPath('message', 'Módulos importados.')
            ->assertJsonCount(2, 'modules');

        $this->assertSame(2, MemberModule::where('member_section_id', $productsSection->id)->count());
        $this->assertSame(1, MemberModule::where('source_member_module_id', $m1->id)->count());
        $this->assertSame(1, MemberModule::where('source_member_module_id', $m2->id)->count());
    }
}
