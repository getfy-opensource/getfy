<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

class MemberLessonReleaseDependencyTest extends TestCase
{
    public function test_immediate_lesson_can_require_a_previous_lesson_to_be_completed(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $student = User::factory()->create(['role' => User::ROLE_ALUNO, 'tenant_id' => 1]);
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'lesson-rel-'.substr(uniqid('', true), -5),
        ]);
        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Aulas',
            'position' => 1,
            'section_type' => 'courses',
        ]);
        $module = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Módulo imediato',
            'position' => 1,
        ]);
        $firstLesson = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Primeira aula',
            'position' => 1,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => '<p>Conteúdo</p>',
        ]);
        $secondLesson = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Segunda aula',
            'position' => 2,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => '<p>Conteúdo</p>',
        ]);

        $this->actingAs($owner)->putJson(
            route('member-builder.lessons.update', ['produto' => $product, 'lesson' => $secondLesson]),
            ['release_dependency_lesson_ids' => [$firstLesson->id]],
        )->assertOk();

        $this->assertDatabaseHas('member_lesson_release_dependencies', [
            'member_lesson_id' => $secondLesson->id,
            'required_member_lesson_id' => $firstLesson->id,
        ]);

        $product->users()->attach($student->id);
        $locked = $this->actingAs($student)->get('/m/'.$product->checkout_slug);
        $locked->assertOk()->assertInertia(fn ($page) => $page
            ->where('sections.0.modules.0.lessons.1.is_locked', true)
            ->where('sections.0.modules.0.lessons.1.lock_message', 'Conclua a aula Primeira aula para liberar esta aula.'));

        $this->actingAs($student)->postJson('/m/'.$product->checkout_slug.'/aula/'.$firstLesson->id.'/complete')
            ->assertOk()
            ->assertJsonPath('success', true);

        $unlocked = $this->actingAs($student)->get('/m/'.$product->checkout_slug);
        $unlocked->assertOk()->assertInertia(fn ($page) => $page
            ->where('sections.0.modules.0.lessons.1.is_locked', false));
    }

    public function test_lesson_cannot_depend_on_itself_or_a_later_lesson(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['type' => Product::TYPE_AREA_MEMBROS]);
        $section = MemberSection::create(['product_id' => $product->id, 'title' => 'Aulas', 'position' => 1, 'section_type' => 'courses']);
        $module = MemberModule::create(['member_section_id' => $section->id, 'product_id' => $product->id, 'title' => 'Módulo', 'position' => 1]);
        $firstLesson = MemberLesson::create(['member_module_id' => $module->id, 'product_id' => $product->id, 'title' => 'Primeira', 'position' => 1, 'type' => MemberLesson::TYPE_TEXT]);
        $secondLesson = MemberLesson::create(['member_module_id' => $module->id, 'product_id' => $product->id, 'title' => 'Segunda', 'position' => 2, 'type' => MemberLesson::TYPE_TEXT]);

        $this->actingAs($owner)->putJson(
            route('member-builder.lessons.update', ['produto' => $product, 'lesson' => $firstLesson]),
            ['release_dependency_lesson_ids' => [$secondLesson->id]],
        )->assertUnprocessable()->assertJsonValidationErrors('release_dependency_lesson_ids');
    }
}
