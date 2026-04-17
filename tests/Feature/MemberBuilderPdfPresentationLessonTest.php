<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

class MemberBuilderPdfPresentationLessonTest extends TestCase
{
    public function test_store_lesson_pdf_presentation_persists_type_and_content_files(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'pdfpres'.substr(uniqid('', true), -8),
        ]);

        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Seção',
            'position' => 1,
            'cover_mode' => 'vertical',
            'section_type' => 'courses',
        ]);

        $module = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Módulo',
            'position' => 1,
        ]);

        $pdfUrl = 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf';

        $response = $this->actingAs($owner)->postJson(
            route('member-builder.lessons.store', ['produto' => $product, 'module' => $module]),
            [
                'title' => 'Apresentação teste',
                'type' => MemberLesson::TYPE_PDF_PRESENTATION,
                'content_url' => '',
                'content_files' => [
                    ['url' => $pdfUrl, 'name' => 'dummy.pdf'],
                ],
                'link_title' => '',
                'release_after_days' => null,
                'release_at_date' => null,
                'content_text' => '',
                'duration_seconds' => 0,
                'is_free' => false,
                'watermark_enabled' => false,
            ]
        );

        $response->assertStatus(200);
        $lesson = MemberLesson::where('member_module_id', $module->id)->first();
        $this->assertNotNull($lesson);
        $this->assertSame(MemberLesson::TYPE_PDF_PRESENTATION, $lesson->type);
        $this->assertSame($pdfUrl, $lesson->content_url);
        $this->assertIsArray($lesson->content_files);
        $this->assertSame($pdfUrl, $lesson->content_files[0]['url'] ?? null);
        $this->assertSame('dummy.pdf', $lesson->content_files[0]['name'] ?? null);
    }
}
