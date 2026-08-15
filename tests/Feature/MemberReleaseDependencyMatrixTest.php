<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberLesson;
use App\Models\MemberLessonProgress;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

class MemberReleaseDependencyMatrixTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(EnsureInstalled::class);
    }

    public function test_lesson_schedule_and_previous_lesson_completion_are_both_required(): void
    {
        [$product, $section, $student] = $this->course();
        $module = $this->module($product, $section, 'Aulas', 1, 2);
        [$first, $second] = $module->lessons->all();
        $second->update(['release_at_date' => now()->addDay()->format('Y-m-d')]);
        $second->releaseDependencies()->create(['required_member_lesson_id' => $first->id]);
        $this->complete($product, $student, $first);

        $this->actingAs($student)->get('/m/'.$product->checkout_slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sections.0.modules.0.lessons.1.is_locked', true)
                ->where('sections.0.modules.0.lessons.1.lock_message', fn ($message) => str_contains((string) $message, 'Disponível em')));

        $second->update(['release_at_date' => now()->subDay()->format('Y-m-d')]);

        $this->actingAs($student)->get('/m/'.$product->checkout_slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('sections.0.modules.0.lessons.1.is_locked', false));
    }

    public function test_reordering_lessons_removes_dependencies_that_are_no_longer_previous(): void
    {
        [$product, $section, $owner] = $this->course(owner: true);
        $module = $this->module($product, $section, 'Módulo', 1, 2);
        [$first, $second] = $module->lessons->all();
        $second->releaseDependencies()->create(['required_member_lesson_id' => $first->id]);

        $this->actingAs($owner)->postJson(route('member-builder.reorder', $product), [
            'scope' => 'lessons',
            'module_id' => $module->id,
            'ordered_ids' => [$second->id, $first->id],
        ])->assertOk();

        $this->assertDatabaseMissing('member_lesson_release_dependencies', [
            'member_lesson_id' => $second->id,
            'required_member_lesson_id' => $first->id,
        ]);
    }

    /** @return array{Product, MemberSection, User} */
    private function course($accessStartedAt = null, bool $owner = false): array
    {
        $user = User::factory()->create([
            'role' => $owner ? User::ROLE_INFOPRODUTOR : User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'matrix-'.substr(uniqid('', true), -8),
        ]);
        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Trilha',
            'position' => 1,
            'section_type' => 'courses',
        ]);
        if (! $owner) {
            $timestamp = $accessStartedAt ?? now();
            $product->users()->attach($user->id, ['created_at' => $timestamp, 'updated_at' => $timestamp]);
        }

        return [$product, $section, $user];
    }

    private function module(
        Product $product,
        MemberSection $section,
        string $title,
        int $position,
        int $lessonCount,
        ?int $releaseAfterDays = null,
    ): MemberModule {
        $module = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => $title,
            'position' => $position,
            'release_after_days' => $releaseAfterDays,
        ]);
        for ($position = 1; $position <= $lessonCount; $position++) {
            MemberLesson::create([
                'member_module_id' => $module->id,
                'product_id' => $product->id,
                'title' => $title.' - Aula '.$position,
                'position' => $position,
                'type' => MemberLesson::TYPE_TEXT,
                'content_text' => '<p>Conteúdo</p>',
            ]);
        }

        return $module->load('lessons');
    }

    private function complete(Product $product, User $student, MemberLesson $lesson): void
    {
        MemberLessonProgress::updateOrCreate(
            ['user_id' => $student->id, 'member_lesson_id' => $lesson->id],
            ['product_id' => $product->id, 'completed_at' => now(), 'progress_percent' => 100],
        );
    }

    private function assertModuleLocked(
        Product $product,
        User $student,
        int $moduleIndex,
        bool $locked,
        ?string $messageContains = null,
    ): void {
        $assertion = fn ($page) => $page->where("sections.0.modules.{$moduleIndex}.is_locked", $locked);
        if ($messageContains !== null) {
            $assertion = fn ($page) => $page
                ->where("sections.0.modules.{$moduleIndex}.is_locked", $locked)
                ->where("sections.0.modules.{$moduleIndex}.lock_message", fn ($message) => str_contains((string) $message, $messageContains));
        }

        $this->actingAs($student)
            ->get('/m/'.$product->checkout_slug)
            ->assertOk()
            ->assertInertia($assertion);
    }
}
