<?php

namespace App\Http\Controllers;

use App\Models\MemberCertificateIssued;
use App\Models\MemberComment;
use App\Models\MemberCommunityPage;
use App\Models\MemberCommunityPost;
use App\Models\MemberCommunityPostComment;
use App\Models\MemberCommunityPostLike;
use App\Models\MemberActivityLog;
use App\Models\MemberInternalProduct;
use App\Models\MemberLesson;
use App\Models\MemberLessonLike;
use App\Models\MemberLessonPdfAnnotation;
use App\Models\MemberLessonProgress;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\GamificationService;
use App\Services\MemberAreaResolver;
use App\Services\MemberCommentService;
use App\Services\MemberProgressService;
use App\Services\StorageService;
use App\Services\UserProductAccessService;
use App\Support\MemberLessonPdfContentResolver;
use App\Plugins\PluginHookBus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class MemberAreaAppController extends Controller
{
    public function __construct(
        protected MemberProgressService $progressService,
        protected MemberAreaResolver $resolver,
        protected GamificationService $gamificationService,
        protected UserProductAccessService $accessService
    ) {}

    /**
     * Best-effort activity log for proof/compliance. Must never break student UX.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function logMemberActivity(Request $request, Product $product, ?User $user, string $event, array $metadata = []): void
    {
        try {
            MemberActivityLog::create([
                'tenant_id' => $product->tenant_id ?? $user?->tenant_id,
                'user_id' => $user?->id,
                'product_id' => $product->id,
                'event' => $event,
                'metadata' => $metadata,
                'ip' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);
        } catch (\Throwable) {
            // ignore (best-effort)
        }
    }

    public function show(Request $request, string $slug): Response
    {
        $product = $this->getProduct($request);
        $user = $request->user();

        $this->logMemberActivity($request, $product, $user, 'member_area.open', [
            'path' => '/' . ltrim($request->path(), '/'),
        ]);

        $accessStartAt = $this->userAccessStartAt($product, $user);
        $now = now();
        $completedLessonIds = $this->progressService->completedLessonIdSet($product, $user);
        $releaseCtx = $this->makeReleaseContext($product, $user);
        $config = $product->member_area_config;
        $sections = $product->memberSections()->with(['modules.lessons', 'modules.relatedProduct', 'modules.sourceModule'])->orderBy('position')->get();
        $progressPercent = $this->progressService->completionPercent($product, $user);
        $continueWatching = $this->getContinueWatching($product, $user);
        $internalProducts = $product->memberInternalProducts()->with('relatedProduct')->orderBy('position')->get();
        $baseUrl = $this->baseUrlForRequest($product, $request);
        $userProductIds = $this->accessService->ownedProductIdSet($user);
        $push = $this->pushProps($product);

        $payload = [
            'product' => $this->productToArray($product),
            'config' => $config,
            'sections' => $sections->map(fn (MemberSection $s) => [
                'id' => $s->id,
                'title' => $s->title,
                'cover_mode' => $s->cover_mode ?? 'vertical',
                'section_type' => $s->section_type ?? 'courses',
                'modules' => $s->modules->map(fn ($m) => $this->mapModuleForMemberArea($m, $s, $product, $user, $userProductIds, $baseUrl, $accessStartAt, $now, $completedLessonIds, $releaseCtx))->values()->all(),
            ])->values()->all(),
            'progress_percent' => $progressPercent,
            'continue_watching' => $continueWatching,
            'internal_products' => $internalProducts->map(function (MemberInternalProduct $ip) use ($product, $user, $baseUrl) {
                $related = $ip->relatedProduct;
                $hasAccess = $this->accessService->userOwnsProduct($user, $ip->related_product_id);
                $open = ($hasAccess && $related)
                    ? $this->accessService->resolveMemberAreaRelatedOpenUrl($product, $related, $baseUrl)
                    : null;

                return [
                    'id' => $ip->related_product_id,
                    'name' => $related?->name,
                    'type' => $related?->type,
                    'image_url' => $related?->image ? (new StorageService($product->tenant_id))->url($related->image) : null,
                    'checkout_slug' => $related?->checkout_slug,
                    'has_access' => $hasAccess,
                    'open_url' => $open['url'] ?? null,
                    'access_label' => $open['label'] ?? 'Acessar',
                ];
            })->values()->all(),
            'community_enabled' => (bool) ($config['community_enabled'] ?? false),
            'certificate_enabled' => (bool) (($config['certificate'] ?? [])['enabled'] ?? false),
            'can_issue_certificate' => $this->progressService->canIssueCertificate($product, $user),
            'base_url' => $baseUrl,
            'slug' => $slug,
            'push_enabled' => $push['push_enabled'],
            'vapid_public' => $push['vapid_public'],
        ] + $this->gamificationProps($product, $user);

        PluginHookBus::doAction('member_area.show', $product, $user, $request);
        $payload = PluginHookBus::applyFilters('member_area.payload', $payload, $product, $user, $request);

        return Inertia::render('MemberAreaApp/Show', $payload);
    }

    public function modulos(Request $request, string $slug): Response
    {
        $product = $this->getProduct($request);
        $user = $request->user();
        $accessStartAt = $this->userAccessStartAt($product, $user);
        $now = now();
        $completedLessonIds = $this->progressService->completedLessonIdSet($product, $user);
        $releaseCtx = $this->makeReleaseContext($product, $user);
        $sections = $product->memberSections()->with(['modules.lessons'])->orderBy('position')->get();

        return Inertia::render('MemberAreaApp/Modulos', [
            'product' => $this->productToArray($product),
            'config' => $product->member_area_config,
            'sections' => $sections->map(fn (MemberSection $s) => [
                'id' => $s->id,
                'title' => $s->title,
                'cover_mode' => $s->cover_mode ?? 'vertical',
                'modules' => $s->modules->map(function (MemberModule $m) use ($accessStartAt, $now, $completedLessonIds, $releaseCtx) {
                    $effective = ($m->source_member_module_id)
                        ? $this->resolveContentModuleForWrapper($m)
                        : $m;

                    return [
                        'id' => $m->id,
                        'title' => $m->title,
                        'thumbnail' => $m->thumbnail,
                        'show_title_on_cover' => $m->show_title_on_cover ?? true,
                        ...$this->moduleLockPayload($m, $accessStartAt, $now, $releaseCtx),
                        'lessons' => $effective->lessons->map(fn (MemberLesson $l) => [
                            'id' => $l->id,
                            'title' => $l->title,
                            'type' => $l->type,
                            'duration_seconds' => $l->duration_seconds,
                            'is_completed' => isset($completedLessonIds[$l->id]),
                            ...$this->lessonLockPayload($l, $m, $accessStartAt, $now, $releaseCtx),
                        ])->values()->all(),
                    ];
                })->values()->all(),
            ])->values()->all(),
            'base_url' => $this->baseUrlForRequest($product, $request),
            'slug' => $slug,
            ...$this->pushProps($product),
        ] + $this->gamificationProps($product, $user));
    }

    public function moduleContent(Request $request, string $slug, MemberModule $module): Response|RedirectResponse
    {
        $product = $this->getProduct($request);
        if ($module->product_id !== $product->id) {
            abort(404);
        }
        $user = $request->user();
        $accessStartAt = $this->userAccessStartAt($product, $user);
        $now = now();
        $releaseCtx = $this->makeReleaseContext($product, $user);
        if ($module->source_member_module_id) {
            $redirect = $this->assertEmbeddedProductLinkAccess($module, $user);
            if ($redirect !== null) {
                return $redirect;
            }
        }
        $module->load('section');
        $effectiveModule = $this->resolveContentModuleForWrapper($module);
        $moduleLock = $this->moduleLockPayload($module, $accessStartAt, $now, $releaseCtx);
        if (($moduleLock['is_locked'] ?? false) === true) {
            return redirect()->route($this->memberAreaModulosRouteName($request), ['slug' => $slug])
                ->with('error', $moduleLock['lock_message'] ?? 'Módulo ainda não liberado.');
        }
        $completedLessonIds = $this->progressService->completedLessonIdSet($product, $user);
        $lessons = $effectiveModule->lessons->map(fn (MemberLesson $l) => [
            'id' => $l->id,
            'title' => $l->title,
            'type' => $l->type,
            'position' => $l->position,
            'duration_seconds' => $l->duration_seconds,
            'is_completed' => isset($completedLessonIds[$l->id]),
            ...$this->lessonLockPayload($l, $module, $accessStartAt, $now, $releaseCtx),
        ])->values()->all();

        $lessonId = $request->query('aula');
        $currentLesson = $lessonId
            ? $effectiveModule->lessons->firstWhere('id', (int) $lessonId)
            : $effectiveModule->lessons->first();
        if ($currentLesson) {
            $lock = $this->lessonLockPayload($currentLesson, $module, $accessStartAt, $now, $releaseCtx);
            if (($lock['is_locked'] ?? false) === true) {
                $firstUnlocked = $effectiveModule->lessons->first(function (MemberLesson $l) use ($module, $accessStartAt, $now, $releaseCtx) {
                    return ($this->lessonLockPayload($l, $module, $accessStartAt, $now, $releaseCtx)['is_locked'] ?? false) !== true;
                });
                if ($firstUnlocked) {
                    return redirect()->route($this->memberAreaModuleRouteName($request), ['slug' => $slug, 'module' => $module->id, 'aula' => $firstUnlocked->id])
                        ->with('error', $lock['lock_message'] ?? 'Aula ainda não liberada.');
                }
                $request->session()->flash('error', $lock['lock_message'] ?? 'Aulas ainda não liberadas.');
                $currentLesson = null;
            }
        }

        $currentLessonData = null;
        if ($currentLesson) {
            $this->progressService->ensureLessonStarted($currentLesson, $user);
            $currentLessonData = [
                'id' => $currentLesson->id,
                'title' => $currentLesson->title,
                'type' => $currentLesson->type,
                'content_url' => $currentLesson->content_url,
                'content_files' => $currentLesson->content_files,
                'support_files' => $currentLesson->support_files,
                'useful_links' => $currentLesson->useful_links,
                'link_title' => $currentLesson->link_title,
                'content_text' => \App\Support\HtmlSanitizer::sanitize($currentLesson->content_text),
                'duration_seconds' => $currentLesson->duration_seconds,
                'is_completed' => $this->isLessonCompleted($user->id, $currentLesson->id),
                'module' => ['id' => $module->id, 'title' => $module->title],
                'section' => $module->section ? ['id' => $module->section->id, 'title' => $module->section->title] : null,
                'watermark_enabled' => (bool) ($currentLesson->watermark_enabled ?? false),
            ];
            if ($currentLessonData['watermark_enabled']) {
                $currentLessonData['student'] = $this->getStudentWatermarkData($user, $product);
            }
            $currentLessonData = array_merge($currentLessonData, $this->lessonEngagementExtras($currentLesson, $user));
        }

        $progressPercent = $this->progressService->completionPercent($product, $user);
        $releaseCtx = $this->makeReleaseContext($product, $user);
        $nextModules = $this->nextModulesPayload($product, $module, $user, $accessStartAt, $now, $releaseCtx);
        $lessonNavigation = $this->lessonNavigationPayload(
            $effectiveModule,
            $currentLesson,
            $accessStartAt,
            $now,
            $nextModules,
            $releaseCtx,
            $module
        );

        $sections = $product->memberSections()->with('modules')->orderBy('position')->get();
        $sectionsPayload = $sections->map(fn (MemberSection $s) => [
            'id' => $s->id,
            'title' => $s->title,
            'cover_mode' => $s->cover_mode ?? 'vertical',
            'modules' => $s->modules->map(fn ($m) => [
                'id' => $m->id,
                'title' => $m->title,
                'thumbnail' => $m->thumbnail,
                'show_title_on_cover' => $m->show_title_on_cover ?? true,
                ...$this->moduleLockPayload($m, $accessStartAt, $now, $releaseCtx),
            ])->values()->all(),
        ])->values()->all();

        $config = $product->member_area_config;
        $commentsEnabled = (bool) ($config['comments_enabled'] ?? false);
        $commentsRequireApproval = (bool) ($config['comments_require_approval'] ?? true);
        $lessonComments = [];
        if ($commentsEnabled && $currentLesson) {
            $lessonComments = MemberComment::forProduct($product->id)
                ->where('member_lesson_id', $currentLesson->id)
                ->status(MemberComment::STATUS_APPROVED)
                ->with('user:id,name,avatar')
                ->latest()
                ->get()
                ->map(fn (MemberComment $c) => [
                    'id' => $c->id,
                    'content' => $c->content,
                    'user' => $c->user ? [
                        'id' => $c->user->id,
                        'name' => $c->user->name,
                        'avatar_url' => $c->user->avatar ? (new StorageService($product->tenant_id))->url($c->user->avatar) : null,
                    ] : null,
                    'created_at' => $c->created_at->toIso8601String(),
                ])
                ->values()
                ->all();
        }

        return Inertia::render('MemberAreaApp/ModuleContent', [
            'product' => $this->productToArray($product),
            'config' => $product->member_area_config,
            'base_url' => $this->baseUrlForRequest($product, $request),
            'slug' => $slug,
            'module' => [
                'id' => $module->id,
                'title' => $module->title,
                'thumbnail' => $this->moduleThumbnailUrl($module, $product, $effectiveModule),
                'section' => $module->section ? ['id' => $module->section->id, 'title' => $module->section->title] : null,
                ...$moduleLock,
            ],
            'lessons' => $lessons,
            'current_lesson' => $currentLessonData,
            'lesson_navigation' => $lessonNavigation,
            'next_modules' => $nextModules,
            'progress_percent' => $progressPercent,
            'course_lesson_progress' => [
                'completed' => $this->progressService->completedLessonsCount($product, $user),
                'total' => $this->progressService->totalLessonsCount($product),
            ],
            'sections' => $sectionsPayload,
            'comments_enabled' => $commentsEnabled,
            'comments_require_approval' => $commentsRequireApproval,
            'lesson_comments' => $lessonComments,
            ...$this->pushProps($product),
        ]);
    }

    public function lesson(Request $request, string $slug, MemberLesson $lesson): Response|RedirectResponse
    {
        $product = $this->getProduct($request);
        $user = $request->user();
        $lesson->load('module');
        $wrapper = $this->findWrapperForEmbeddedLesson($lesson, $product);
        if ((string) $lesson->product_id !== (string) $product->id && $wrapper === null) {
            abort(404);
        }
        if ($wrapper !== null) {
            $redirect = $this->assertEmbeddedProductLinkAccess($wrapper, $user);
            if ($redirect !== null) {
                return $redirect;
            }
        }
        $accessStartAt = $this->userAccessStartAt($product, $user);
        $now = now();
        $releaseCtx = $this->makeReleaseContext($product, $user);
        $effectiveModule = $wrapper !== null
            ? $this->resolveContentModuleForWrapper($wrapper)
            : $lesson->module;
        $lockModule = $wrapper ?? $effectiveModule;
        if ($lockModule) {
            $moduleLock = $this->moduleLockPayload($lockModule, $accessStartAt, $now, $releaseCtx);
            if (($moduleLock['is_locked'] ?? false) === true) {
                return redirect()->route($this->memberAreaModulosRouteName($request), ['slug' => $slug])
                    ->with('error', $moduleLock['lock_message'] ?? 'Módulo ainda não liberado.');
            }
        }
        $lessonLock = $this->lessonLockPayload($lesson, $lockModule, $accessStartAt, $now, $releaseCtx);
        if (($lessonLock['is_locked'] ?? false) === true) {
            $moduleRouteId = $wrapper?->id ?? $lesson->module?->id;
            if ($moduleRouteId) {
                return redirect()->route($this->memberAreaModuleRouteName($request), ['slug' => $slug, 'module' => $moduleRouteId])
                    ->with('error', $lessonLock['lock_message'] ?? 'Aula ainda não liberada.');
            }
            return redirect()->route($this->memberAreaModulosRouteName($request), ['slug' => $slug])
                ->with('error', $lessonLock['lock_message'] ?? 'Aula ainda não liberada.');
        }
        $moduleRouteId = $wrapper?->id ?? $lesson->module?->id;
        if ($moduleRouteId) {
            return redirect()->route($this->memberAreaModuleRouteName($request), [
                'slug' => $slug,
                'module' => $moduleRouteId,
                'aula' => $lesson->id,
            ]);
        }

        $this->progressService->ensureLessonStarted($lesson, $user);

        $this->logMemberActivity($request, $product, $user, 'member_area.lesson_view', [
            'lesson_id' => $lesson->id,
            'lesson_product_id' => $lesson->product_id,
            'module_id' => $lesson->module?->id,
            'embedded' => $wrapper !== null,
        ]);

        $sectionPayload = null;
        if ($wrapper !== null) {
            $wrapper->loadMissing('section');
            $sectionPayload = $wrapper->section ? ['id' => $wrapper->section->id, 'title' => $wrapper->section->title] : null;
        } elseif ($lesson->module && $lesson->module->section) {
            $sectionPayload = ['id' => $lesson->module->section->id, 'title' => $lesson->module->section->title];
        }

        $lessonPayload = [
            'id' => $lesson->id,
            'title' => $lesson->title,
            'type' => $lesson->type,
            'content_url' => $lesson->content_url,
            'content_files' => $lesson->content_files,
            'support_files' => $lesson->support_files,
            'useful_links' => $lesson->useful_links,
            'link_title' => $lesson->link_title,
            'content_text' => \App\Support\HtmlSanitizer::sanitize($lesson->content_text),
            'duration_seconds' => $lesson->duration_seconds,
            'is_completed' => $this->isLessonCompleted($user->id, $lesson->id),
            'module' => $wrapper !== null
                ? ['id' => $wrapper->id, 'title' => $wrapper->title]
                : ($lesson->module ? ['id' => $lesson->module->id, 'title' => $lesson->module->title] : null),
            'section' => $sectionPayload,
            'watermark_enabled' => (bool) ($lesson->watermark_enabled ?? false),
        ];
        if ($lessonPayload['watermark_enabled']) {
            $lessonPayload['student'] = $this->getStudentWatermarkData($user, $product);
        }
        $lessonPayload = array_merge($lessonPayload, $this->lessonEngagementExtras($lesson, $user));
        $config = $product->member_area_config;
        $commentsEnabled = (bool) ($config['comments_enabled'] ?? false);
        $commentsRequireApproval = (bool) ($config['comments_require_approval'] ?? true);
        $lessonComments = [];
        if ($commentsEnabled) {
            $lessonComments = MemberComment::forProduct($product->id)
                ->where('member_lesson_id', $lesson->id)
                ->status(MemberComment::STATUS_APPROVED)
                ->with('user:id,name,avatar')
                ->latest()
                ->get()
                ->map(fn (MemberComment $c) => [
                    'id' => $c->id,
                    'content' => $c->content,
                    'user' => $c->user ? [
                        'id' => $c->user->id,
                        'name' => $c->user->name,
                        'avatar_url' => $c->user->avatar ? (new StorageService($product->tenant_id))->url($c->user->avatar) : null,
                    ] : null,
                    'created_at' => $c->created_at->toIso8601String(),
                ])
                ->values()
                ->all();
        }

        return Inertia::render('MemberAreaApp/Lesson', [
            'product' => $this->productToArray($product),
            'config' => $product->member_area_config,
            'lesson' => $lessonPayload,
            'base_url' => $this->baseUrlForRequest($product, $request),
            'slug' => $slug,
            'comments_enabled' => $commentsEnabled,
            'comments_require_approval' => $commentsRequireApproval,
            'lesson_comments' => $lessonComments,
            ...$this->pushProps($product),
        ] + $this->gamificationProps($product, $user));
    }

    /**
     * Proxy para PDFs de apresentação (mesma origem): o pdf.js usa fetch; URLs no R2 sem CORS falham no browser.
     */
    public function presentationPdf(Request $request, string $slug, MemberLesson $lesson, int $fileIndex): SymfonyResponse
    {
        $this->assertLessonViewable($request, $lesson);
        if (! in_array($lesson->type, [MemberLesson::TYPE_PDF_PRESENTATION, MemberLesson::TYPE_PDF_READER], true)) {
            abort(404);
        }
        $urls = $this->pdfPresentationSourceUrls($lesson);
        if ($fileIndex < 0 || $fileIndex >= count($urls)) {
            abort(404);
        }
        $url = $urls[$fileIndex];
        $product = $this->getProduct($request);

        $this->progressService->ensureLessonStarted($lesson, $request->user());

        $resolver = new MemberLessonPdfContentResolver(new StorageService($product->tenant_id));
        $file = $resolver->fetch($url);

        return response($file['body'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$file['filename'].'"',
            'Cache-Control' => 'private, max-age=120',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * GET — marcações do usuário no leitor PDF (por arquivo).
     */
    public function getLessonPdfAnnotations(Request $request, string $slug, MemberLesson $lesson): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }
        $this->assertLessonViewable($request, $lesson);
        if ($lesson->type !== MemberLesson::TYPE_PDF_READER) {
            abort(404);
        }

        $rows = MemberLessonPdfAnnotation::query()
            ->where('user_id', $user->id)
            ->where('member_lesson_id', $lesson->id)
            ->get(['file_index', 'payload']);

        $byFile = [];
        foreach ($rows as $row) {
            $byFile[(string) $row->file_index] = is_array($row->payload) ? $row->payload : [];
        }

        return response()->json(['annotations_by_file' => $byFile]);
    }

    /**
     * PUT — salva marcações de um arquivo PDF (lista de highlights).
     *
     * @param  array<string, mixed>  $payload
     */
    public function putLessonPdfAnnotations(Request $request, string $slug, MemberLesson $lesson): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }
        $this->assertLessonViewable($request, $lesson);
        if ($lesson->type !== MemberLesson::TYPE_PDF_READER) {
            abort(404);
        }

        $validated = $request->validate([
            'file_index' => ['required', 'integer', 'min:0'],
            'highlights' => ['required', 'array', 'max:500'],
            'highlights.*.id' => ['required', 'string', 'max:64'],
            'highlights.*.page' => ['required', 'integer', 'min:1'],
            'highlights.*.color' => ['required', 'string', 'in:yellow,green,pink'],
            'highlights.*.x' => ['required', 'numeric', 'min:0', 'max:1'],
            'highlights.*.y' => ['required', 'numeric', 'min:0', 'max:1'],
            'highlights.*.width' => ['required', 'numeric', 'min:0', 'max:1'],
            'highlights.*.height' => ['required', 'numeric', 'min:0', 'max:1'],
        ]);

        MemberLessonPdfAnnotation::updateOrCreate(
            [
                'user_id' => $user->id,
                'member_lesson_id' => $lesson->id,
                'file_index' => $validated['file_index'],
            ],
            [
                'payload' => $validated['highlights'],
            ]
        );

        return response()->json(['success' => true]);
    }

    /**
     * POST — alterna curtida na aula.
     */
    public function toggleLessonLike(Request $request, string $slug, MemberLesson $lesson): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }
        $this->assertLessonViewable($request, $lesson);

        $liked = false;
        $count = (int) ($lesson->likes_count ?? 0);

        DB::transaction(function () use ($lesson, $user, &$liked, &$count): void {
            $lessonRow = MemberLesson::query()->whereKey($lesson->id)->lockForUpdate()->first();
            if (! $lessonRow) {
                return;
            }
            $existing = MemberLessonLike::query()
                ->where('user_id', $user->id)
                ->where('member_lesson_id', $lesson->id)
                ->first();
            if ($existing) {
                $existing->delete();
                $lessonRow->decrement('likes_count');
                $liked = false;
            } else {
                MemberLessonLike::create([
                    'user_id' => $user->id,
                    'member_lesson_id' => $lesson->id,
                ]);
                $lessonRow->increment('likes_count');
                $liked = true;
            }
            $count = (int) $lessonRow->fresh()->likes_count;
        });

        return response()->json([
            'liked' => $liked,
            'likes_count' => $count,
        ]);
    }

    public function putLessonNote(Request $request, string $slug, MemberLesson $lesson): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }
        $this->assertLessonViewable($request, $lesson);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->progressService->ensureLessonStarted($lesson, $user);

        MemberLessonProgress::query()
            ->where('user_id', $user->id)
            ->where('member_lesson_id', $lesson->id)
            ->update(['notes' => $validated['notes'] ?? null]);

        return response()->json([
            'success' => true,
            'notes' => $validated['notes'] ?? '',
        ]);
    }

    /**
     * @return array{likes_count: int, user_liked: bool, user_note: string}
     */
    private function lessonEngagementExtras(MemberLesson $lesson, User $user): array
    {
        $progress = MemberLessonProgress::query()
            ->where('user_id', $user->id)
            ->where('member_lesson_id', $lesson->id)
            ->first();

        return [
            'likes_count' => (int) ($lesson->likes_count ?? 0),
            'user_liked' => MemberLessonLike::query()
                ->where('user_id', $user->id)
                ->where('member_lesson_id', $lesson->id)
                ->exists(),
            'user_note' => (string) ($progress?->notes ?? ''),
        ];
    }

    /**
     * @return '1:1'|'4:5'|'9:16'
     */
    private function resolveCommunityMediaAspect(?string $requested, ?\Illuminate\Http\UploadedFile $file = null): string
    {
        if (in_array($requested, ['1:1', '4:5', '9:16'], true)) {
            return $requested;
        }
        if ($file === null) {
            return '4:5';
        }
        if (str_starts_with((string) $file->getMimeType(), 'video/')) {
            return '9:16';
        }

        return $this->detectCommunityImageAspect($file);
    }

    /**
     * @return '1:1'|'4:5'
     */
    private function detectCommunityImageAspect(\Illuminate\Http\UploadedFile $file): string
    {
        $size = @getimagesize($file->getPathname());
        if (! is_array($size) || empty($size[0]) || empty($size[1])) {
            return '4:5';
        }
        $ratio = (float) $size[0] / (float) $size[1];

        return abs($ratio - 1.0) <= abs($ratio - 0.8) ? '1:1' : '4:5';
    }

    /**
     * @return array<string, mixed>
     */
    private function communityPostSidebarPayload(MemberCommunityPost $post, Product $product): array
    {
        $content = trim(strip_tags((string) ($post->content ?? '')));
        $excerpt = mb_strlen($content) > 120 ? mb_substr($content, 0, 120).'…' : $content;
        $payload = [
            'id' => $post->id,
            'content' => $post->content,
            'excerpt' => $excerpt,
            'image_url' => $post->image_url,
            'video_url' => $post->video_url,
            'media_aspect' => $post->media_aspect,
            'likes_count' => (int) ($post->likes_count ?? $post->likes()->count()),
            'comments_count' => (int) ($post->comments_count ?? $post->comments()->count()),
            'created_at' => $post->created_at?->toIso8601String(),
            'user' => $post->user ? [
                'id' => $post->user->id,
                'name' => $post->user->name,
                'avatar_url' => $post->user->avatar ? (new StorageService($product->tenant_id))->url($post->user->avatar) : null,
            ] : null,
        ];
        if ($post->relationLoaded('page') && $post->page) {
            $payload['page'] = [
                'id' => $post->page->id,
                'title' => $post->page->title,
                'slug' => $post->page->slug,
            ];
        }

        return $payload;
    }

    /**
     * Top posts by engagement (likes + comments) on one page or across the product.
     *
     * @return array<int, array<string, mixed>>
     */
    private function communityFeaturedPostsPayload(Product $product, ?MemberCommunityPage $page = null, int $limit = 5): array
    {
        $query = MemberCommunityPost::query()
            ->when($page, fn ($q) => $q->where('member_community_page_id', $page->id))
            ->when(! $page, fn ($q) => $q->whereHas('page', fn ($pq) => $pq->where('product_id', $product->id)))
            ->with(['user:id,name,avatar', 'page:id,title,slug'])
            ->withCount(['likes', 'comments'])
            ->orderByRaw('(likes_count + comments_count) DESC')
            ->orderByDesc('created_at')
            ->limit($limit);

        return $query->get()
            ->map(fn (MemberCommunityPost $post) => $this->communityPostSidebarPayload($post, $product))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function communityActiveMembersPayload(Product $product, int $limit = 5): array
    {
        $stats = MemberCommunityPost::query()
            ->join('member_community_pages', 'member_community_posts.member_community_page_id', '=', 'member_community_pages.id')
            ->where('member_community_pages.product_id', $product->id)
            ->whereNotNull('member_community_posts.user_id')
            ->select('member_community_posts.user_id', DB::raw('COUNT(*) as posts_count'))
            ->groupBy('member_community_posts.user_id')
            ->orderByDesc('posts_count')
            ->limit($limit)
            ->get();

        if ($stats->isEmpty()) {
            return [];
        }

        $users = User::query()
            ->whereIn('id', $stats->pluck('user_id'))
            ->get(['id', 'name', 'avatar'])
            ->keyBy('id');

        $storage = new StorageService($product->tenant_id);

        return $stats->map(function ($row) use ($users, $storage) {
            $user = $users->get($row->user_id);
            if (! $user) {
                return null;
            }

            return [
                'id' => $user->id,
                'name' => $user->name,
                'avatar_url' => $user->avatar ? $storage->url($user->avatar) : null,
                'posts_count' => (int) $row->posts_count,
            ];
        })->filter()->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function communityPageNavPayload(\Illuminate\Support\Collection $pages, Product $product): array
    {
        $storage = new StorageService($product->tenant_id);

        return $pages->map(fn (MemberCommunityPage $p) => [
            'id' => $p->id,
            'title' => $p->title,
            'icon' => $p->icon,
            'slug' => $p->slug,
            'banner_url' => $p->banner ? $storage->url($p->banner) : null,
            'is_featured' => (bool) ($p->is_featured ?? false),
        ])->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function communityFeaturedPagesPayload(Product $product, ?string $excludeSlug = null): array
    {
        $storage = new StorageService($product->tenant_id);

        return $product->memberCommunityPages()
            ->where('is_featured', true)
            ->orderBy('position')
            ->get()
            ->when($excludeSlug, fn ($pages) => $pages->filter(fn (MemberCommunityPage $p) => $p->slug !== $excludeSlug))
            ->map(fn (MemberCommunityPage $p) => [
                'id' => $p->id,
                'title' => $p->title,
                'icon' => $p->icon,
                'slug' => $p->slug,
                'banner_url' => $p->banner ? $storage->url($p->banner) : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function communityCrossPagePostsPayload(Product $product, ?MemberCommunityPage $excludePage = null, int $limit = 5): array
    {
        return MemberCommunityPost::query()
            ->whereHas('page', function ($q) use ($product, $excludePage) {
                $q->where('product_id', $product->id);
                if ($excludePage) {
                    $q->where('id', '!=', $excludePage->id);
                }
            })
            ->with(['user:id,name,avatar', 'page:id,title,slug'])
            ->withCount(['likes', 'comments'])
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (MemberCommunityPost $post) => $this->communityPostSidebarPayload($post, $product))
            ->values()
            ->all();
    }

    public function completeLesson(Request $request, string $slug, MemberLesson $lesson): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Não autenticado.'], 401);
        }
        $product = $this->getProduct($request);
        $wrapper = $this->findWrapperForEmbeddedLesson($lesson, $product);
        if ((string) $lesson->product_id !== (string) $product->id && $wrapper === null) {
            abort(404);
        }
        if ($wrapper !== null) {
            $redirect = $this->assertEmbeddedProductLinkAccess($wrapper, $user);
            if ($redirect !== null) {
                return $redirect;
            }
        }
        $this->assertLessonViewable($request, $lesson);
        $this->progressService->markLessonCompleted($lesson->id, $user);

        $this->logMemberActivity($request, $product, $user, 'member_area.lesson_complete', [
            'lesson_id' => $lesson->id,
            'lesson_product_id' => $lesson->product_id,
            'embedded' => $wrapper !== null,
        ]);

        $newlyUnlocked = $this->gamificationService->checkAndUnlock($product, $user);
        if ($newlyUnlocked !== []) {
            $request->session()->flash('newly_unlocked_achievements', $newlyUnlocked);
        }

        if ($request->header('X-Inertia')) {
            return redirect()->back();
        }
        $percent = $this->progressService->completionPercent($product, $user);

        return response()->json(['success' => true, 'progress_percent' => $percent, 'newly_unlocked_achievements' => $newlyUnlocked]);
    }

    public function storeLessonComment(Request $request, string $slug, MemberLesson $lesson): JsonResponse|RedirectResponse
    {
        $product = $this->getProduct($request);
        $user = $request->user();
        $wrapper = $this->findWrapperForEmbeddedLesson($lesson, $product);
        if ((string) $lesson->product_id !== (string) $product->id && $wrapper === null) {
            abort(404);
        }
        if ($wrapper !== null) {
            $redirect = $this->assertEmbeddedProductLinkAccess($wrapper, $user);
            if ($redirect !== null) {
                return $redirect;
            }
        }
        $this->assertLessonViewable($request, $lesson);
        $config = $product->member_area_config;
        if (empty($config['comments_enabled'])) {
            abort(403, 'Comentários desativados para este produto.');
        }
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:2000'],
        ]);
        $requireApproval = ! empty($config['comments_require_approval']);
        $initialStatus = $requireApproval ? MemberComment::STATUS_PENDING : MemberComment::STATUS_APPROVED;
        $commentService = app(MemberCommentService::class);
        $commentService->create(
            $product,
            $request->user(),
            $validated['content'],
            $lesson->id,
            null,
            $initialStatus
        );
        $message = $requireApproval ? 'Comentário enviado e aguardando aprovação.' : 'Comentário publicado.';
        if (! $requireApproval) {
            $newlyUnlocked = $this->gamificationService->checkAndUnlock($product, $request->user());
            if ($newlyUnlocked !== []) {
                $request->session()->flash('newly_unlocked_achievements', $newlyUnlocked);
            }
        }
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => $message]);
        }

        return redirect()->back()->with('success', $message);
    }

    public function loja(Request $request, string $slug): Response
    {
        $product = $this->getProduct($request);
        $user = $request->user();
        $internalProducts = $product->memberInternalProducts()->with('relatedProduct')->orderBy('position')->get();
        $baseUrl = $this->baseUrlForRequest($product, $request);

        $items = $internalProducts->map(function (MemberInternalProduct $ip) use ($product, $user, $baseUrl) {
            $related = $ip->relatedProduct;
            $hasAccess = $this->accessService->userOwnsProduct($user, $ip->related_product_id);
            $open = ($hasAccess && $related)
                ? $this->accessService->resolveMemberAreaRelatedOpenUrl($product, $related, $baseUrl)
                : null;

            return [
                'id' => $ip->related_product_id,
                'name' => $related?->name,
                'description' => $related?->description,
                'image_url' => $related?->image ? (new StorageService($product->tenant_id))->url($related->image) : null,
                'checkout_slug' => $related?->checkout_slug,
                'price' => $related?->price,
                'has_access' => $hasAccess,
                'open_url' => $open['url'] ?? null,
                'access_label' => $open['label'] ?? 'Acessar',
            ];
        })->values()->all();

        return Inertia::render('MemberAreaApp/Loja', [
            'product' => $this->productToArray($product),
            'config' => $product->member_area_config,
            'items' => $items,
            'base_url' => $this->baseUrlForRequest($product, $request),
            'slug' => $slug,
            ...$this->pushProps($product),
        ] + $this->gamificationProps($product, $user));
    }

    public function comunidade(Request $request, string $slug): Response|\Illuminate\Http\RedirectResponse
    {
        $product = $this->getProduct($request);
        $user = $request->user();
        $pages = $product->memberCommunityPages()->orderBy('position')->get();
        $defaultPage = $pages->firstWhere('is_default', true);
        if ($defaultPage) {
            $routeName = $request->route()->getName();
            $pageRouteName = str_ends_with($routeName, '.host') ? 'member-area-app.comunidade.page.host' : 'member-area-app.comunidade.page';

            return redirect()->route($pageRouteName, ['slug' => $slug, 'pageSlug' => $defaultPage->slug]);
        }

        return Inertia::render('MemberAreaApp/Comunidade', [
            'product' => $this->productToArray($product),
            'config' => $product->member_area_config,
            'pages' => $this->communityPageNavPayload($pages, $product),
            'featured_posts' => $this->communityFeaturedPostsPayload($product),
            'featured_pages' => $this->communityFeaturedPagesPayload($product),
            'cross_page_posts' => $this->communityCrossPagePostsPayload($product),
            'active_members' => $this->communityActiveMembersPayload($product),
            'base_url' => $this->baseUrlForRequest($product, $request),
            'slug' => $slug,
            ...$this->pushProps($product),
        ] + $this->gamificationProps($product, $user));
    }

    public function comunidadePage(Request $request, string $slug, string $pageSlug): Response
    {
        $product = $this->getProduct($request);
        $user = $request->user();
        $config = $product->member_area_config;
        $pages = $product->memberCommunityPages()->orderBy('position')->get();
        $page = $pages->firstWhere('slug', $pageSlug);
        if (! $page) {
            abort(404);
        }
        $postsQuery = $page->posts()->with(['user:id,name,email,avatar', 'likes', 'comments.user:id,name,avatar'])->latest();
        $posts = $postsQuery->paginate(20);
        $canDeleteAny = $user->canAccessPanel() && $user->tenant_id === $product->tenant_id;
        $usersCanDeleteOwn = (bool) ($config['community_users_can_delete_own_posts'] ?? true);
        $posts->getCollection()->transform(function (MemberCommunityPost $post) use ($user, $product) {
            $comments = $post->comments->map(fn (MemberCommunityPostComment $c) => [
                'id' => $c->id,
                'content' => $c->content,
                'created_at' => $c->created_at->format('d/m/Y H:i'),
                'user' => $c->user ? [
                    'id' => $c->user->id,
                    'name' => $c->user->name,
                    'avatar_url' => $c->user->avatar ? (new StorageService($product->tenant_id))->url($c->user->avatar) : null,
                ] : null,
            ])->values()->all();

            return array_merge($post->toArray(), [
                'user' => $post->user ? [
                    'id' => $post->user->id,
                    'name' => $post->user->name,
                    'avatar_url' => $post->user->avatar ? (new StorageService($product->tenant_id))->url($post->user->avatar) : null,
                ] : null,
                'likes_count' => $post->likes->count(),
                'user_has_liked' => $post->likes->contains('user_id', $user->id),
                'comments' => $comments,
            ]);
        });

        return Inertia::render('MemberAreaApp/ComunidadePage', [
            'product' => $this->productToArray($product),
            'config' => $config,
            'auth_user_id' => $user->id,
            'can_delete_any_post' => $canDeleteAny,
            'community_users_can_delete_own_posts' => $usersCanDeleteOwn,
            'pages' => $this->communityPageNavPayload($pages, $product),
            'page' => [
                'id' => $page->id,
                'title' => $page->title,
                'icon' => $page->icon,
                'slug' => $page->slug,
                'banner_url' => $page->banner ? (new StorageService($product->tenant_id))->url($page->banner) : null,
                'is_public_posting' => $page->is_public_posting,
            ],
            'posts' => $posts,
            'featured_posts' => $this->communityFeaturedPostsPayload($product, $page),
            'featured_pages' => $this->communityFeaturedPagesPayload($product, $page->slug),
            'cross_page_posts' => $this->communityCrossPagePostsPayload($product, $page),
            'active_members' => $this->communityActiveMembersPayload($product),
            'base_url' => $this->baseUrlForRequest($product, $request),
            'slug' => $slug,
            ...$this->pushProps($product),
        ] + $this->gamificationProps($product, $user));
    }

    public function storeCommunityPost(Request $request, string $slug, string $pageSlug): RedirectResponse
    {
        $product = $this->getProduct($request);
        $page = MemberCommunityPage::where('product_id', $product->id)->where('slug', $pageSlug)->firstOrFail();
        if (! $page->is_public_posting) {
            abort(403, 'Apenas o instrutor pode postar nesta página.');
        }
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
            'media_aspect' => ['nullable', 'string', 'in:1:1,4:5,9:16'],
            'image' => ['nullable', 'file', 'image', 'max:5120'],
            'video' => ['nullable', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime', 'max:51200'],
        ], [
            'video.mimetypes' => 'O vídeo deve ser MP4, WebM ou MOV.',
            'video.max' => 'O vídeo deve ter no máximo 50 MB.',
        ]);
        if ($request->hasFile('image') && $request->hasFile('video')) {
            return back()->withErrors(['image' => 'Envie apenas imagem ou vídeo, não os dois.']);
        }
        $storage = new StorageService($product->tenant_id);
        $imagePath = null;
        $videoPath = null;
        $mediaAspect = $validated['media_aspect'] ?? null;
        if ($request->hasFile('video')) {
            $videoPath = $storage->putFile('member-area-posts/'.$product->id.'/videos', $request->file('video'));
            $mediaAspect = '9:16';
        } elseif ($request->hasFile('image')) {
            $imagePath = $storage->putFile('member-area-posts/'.$product->id, $request->file('image'));
            $mediaAspect = $this->resolveCommunityMediaAspect($mediaAspect, $request->file('image'));
        }
        MemberCommunityPost::create([
            'member_community_page_id' => $page->id,
            'user_id' => $request->user()->id,
            'content' => $validated['content'],
            'image' => $imagePath,
            'video' => $videoPath,
            'media_aspect' => $mediaAspect,
        ]);

        return back()->with('success', 'Post publicado.');
    }

    public function destroyCommunityPost(Request $request, string $slug, string $pageSlug, MemberCommunityPost $post): RedirectResponse
    {
        $product = $this->getProduct($request);
        $page = MemberCommunityPage::where('product_id', $product->id)->where('slug', $pageSlug)->firstOrFail();
        if ($post->member_community_page_id !== $page->id) {
            abort(404);
        }
        $user = $request->user();
        $config = $product->member_area_config;
        $canDeleteAny = $user->canAccessPanel() && $user->tenant_id === $product->tenant_id;
        $usersCanDeleteOwn = (bool) ($config['community_users_can_delete_own_posts'] ?? true);
        $isAuthor = $post->user_id === $user->id;
        if (! $canDeleteAny && ! ($isAuthor && $usersCanDeleteOwn)) {
            abort(403, 'Você não pode excluir esta postagem.');
        }
        $post->delete();

        return back()->with('success', 'Postagem excluída.');
    }

    public function likeCommunityPost(Request $request, string $slug, string $pageSlug, MemberCommunityPost $post): JsonResponse
    {
        $product = $this->getProduct($request);
        $page = MemberCommunityPage::where('product_id', $product->id)->where('slug', $pageSlug)->firstOrFail();
        if ($post->member_community_page_id !== $page->id) {
            abort(404);
        }
        MemberCommunityPostLike::firstOrCreate(
            ['member_community_post_id' => $post->id, 'user_id' => $request->user()->id]
        );

        return response()->json([
            'likes_count' => $post->likes()->count(),
            'user_has_liked' => true,
        ]);
    }

    public function unlikeCommunityPost(Request $request, string $slug, string $pageSlug, MemberCommunityPost $post): JsonResponse
    {
        $product = $this->getProduct($request);
        $page = MemberCommunityPage::where('product_id', $product->id)->where('slug', $pageSlug)->firstOrFail();
        if ($post->member_community_page_id !== $page->id) {
            abort(404);
        }
        MemberCommunityPostLike::where('member_community_post_id', $post->id)
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json([
            'likes_count' => $post->likes()->count(),
            'user_has_liked' => false,
        ]);
    }

    public function storeCommunityPostComment(Request $request, string $slug, string $pageSlug, MemberCommunityPost $post): JsonResponse|RedirectResponse
    {
        $product = $this->getProduct($request);
        $page = MemberCommunityPage::where('product_id', $product->id)->where('slug', $pageSlug)->firstOrFail();
        if ($post->member_community_page_id !== $page->id) {
            abort(404);
        }
        $validated = $request->validate(['content' => ['required', 'string', 'max:2000']]);
        $comment = MemberCommunityPostComment::create([
            'member_community_post_id' => $post->id,
            'user_id' => $request->user()->id,
            'content' => $validated['content'],
        ]);
        $comment->load('user:id,name,avatar');
        if ($request->expectsJson()) {
            return response()->json([
                'comment' => [
                    'id' => $comment->id,
                    'content' => $comment->content,
                    'created_at' => $comment->created_at->format('d/m/Y H:i'),
                    'user' => $comment->user ? [
                        'id' => $comment->user->id,
                        'name' => $comment->user->name,
                        'avatar_url' => $comment->user->avatar ? (new StorageService($product->tenant_id))->url($comment->user->avatar) : null,
                    ] : null,
                ],
            ]);
        }

        return back()->with('success', 'Comentário adicionado.');
    }

    public function certificado(Request $request, string $slug): Response|RedirectResponse
    {
        $product = $this->getProduct($request);
        $user = $request->user();
        $config = $product->member_area_config;
        $certConfig = $config['certificate'] ?? [];

        if (empty($certConfig['enabled'])) {
            return redirect()->route('member-area-app.show', $slug)
                ->with('error', 'O certificado não está habilitado para este curso.');
        }

        $progressPercent = $this->progressService->completionPercent($product, $user);
        $requiredPercent = (int) ($certConfig['completion_percent'] ?? 100);
        $issued = MemberCertificateIssued::where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->first();

        if ($progressPercent >= $requiredPercent && ! $issued) {
            $issued = $this->progressService->issueCertificate($product, $user);
        }

        $newlyUnlocked = [];
        if ($issued !== null) {
            $newlyUnlocked = $this->gamificationService->checkAndUnlock($product, $user);
        }

        $certificateAvailable = $issued !== null;
        $certTitle = ! empty($certConfig['title']) ? $certConfig['title'] : $product->name;

        $certificatePayload = [
            'title' => $certTitle,
            'issued_at' => $issued ? $issued->issued_at->format('d/m/Y') : null,
            'issued_at_full' => $issued ? $issued->issued_at->format('d/m/Y H:i') : null,
            'completion_percent' => $issued ? $issued->completion_percent : $progressPercent,
            'signature_text' => $certConfig['signature_text'] ?? '',
            'duration_text' => $certConfig['duration_text'] ?? '',
            'font_family' => $certConfig['font_family'] ?? 'sans-serif',
            'platform_name' => ! empty($certConfig['platform_name']) ? $certConfig['platform_name'] : config('app.name'),
            'primary_color' => $certConfig['primary_color'] ?? null,
            'background_image_url' => $certConfig['background_image_url'] ?? null,
            'background_overlay_enabled' => (bool) ($certConfig['background_overlay_enabled'] ?? false),
            'background_overlay_color' => $certConfig['background_overlay_color'] ?? '#000000',
            'background_overlay_opacity' => isset($certConfig['background_overlay_opacity']) ? (float) $certConfig['background_overlay_opacity'] : 50,
            'text_color' => $certConfig['text_color'] ?? null,
            'title_color' => $certConfig['title_color'] ?? null,
            'signature_font_family' => $certConfig['signature_font_family'] ?? 'Dancing Script',
            'print_format' => $certConfig['print_format'] ?? 'A4',
        ];

        return Inertia::render('MemberAreaApp/Certificado', [
            'product' => $this->productToArray($product),
            'config' => $product->member_area_config,
            'recipient_name' => $user->name,
            'certificate_available' => $certificateAvailable,
            'progress_percent' => $progressPercent,
            'completion_required_percent' => $requiredPercent,
            'certificate' => $certificatePayload,
            'base_url' => $this->baseUrlForRequest($product, $request),
            'slug' => $slug,
            'newly_unlocked_achievements' => $newlyUnlocked,
            ...$this->pushProps($product),
        ] + $this->gamificationProps($product, $user));
    }

    public function pushSubscribe(Request $request, string $slug): JsonResponse
    {
        $product = $this->getProduct($request);
        $config = $product->member_area_config;
        $pwa = $config['pwa'] ?? [];
        if (! ((bool) ($pwa['push_enabled'] ?? false))) {
            return response()->json(['message' => 'Notificações push não estão habilitadas para esta área.'], 403);
        }
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
            'keys' => ['required', 'array'],
            'keys.auth' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'renewed' => ['sometimes', 'boolean'],
        ]);
        $keys = $validated['keys'];
        $keys['auth'] = $this->normalizeBase64KeyForPush((string) ($keys['auth'] ?? ''));
        $keys['p256dh'] = $this->normalizeBase64KeyForPush((string) ($keys['p256dh'] ?? ''));
        $subscription = \App\Models\MemberPushSubscription::updateOrCreate(
            [
                'endpoint' => $validated['endpoint'],
            ],
            [
                'user_id' => $request->user()->id,
                'product_id' => $product->id,
                'keys' => $keys,
                'user_agent' => $request->userAgent(),
            ]
        );

        return response()->json([
            'success' => true,
            'subscribed' => true,
            'subscription_id' => $subscription->id,
            'renewed' => (bool) ($validated['renewed'] ?? false),
            'updated_at' => $subscription->updated_at?->toISOString(),
        ]);
    }

    private function normalizeBase64KeyForPush(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return $key;
        }
        if (str_contains($key, '+') || str_contains($key, '/')) {
            return strtr($key, ['+' => '-', '/' => '_']);
        }

        return $key;
    }

    private function getProduct(Request $request): Product
    {
        $product = $request->route('product') ?? $request->attributes->get('member_area_product');
        if (! $product instanceof Product) {
            abort(404);
        }

        return $product;
    }

    /** @return array{push_enabled: bool, vapid_public: string|null} */
    private function pushProps(Product $product): array
    {
        $config = $product->member_area_config;
        $pwa = $config['pwa'] ?? [];
        $pushEnabled = (bool) ($pwa['push_enabled'] ?? false);

        return [
            'push_enabled' => $pushEnabled,
            'vapid_public' => $pushEnabled ? ($pwa['vapid_public'] ?? null) : null,
        ];
    }

    /** @return array{gamification_achievements: array} */
    private function gamificationProps(Product $product, User $user): array
    {
        $config = $product->member_area_config;
        $gamification = $config['gamification'] ?? [];
        if (empty($gamification['enabled'])) {
            return ['gamification_achievements' => []];
        }

        return [
            'gamification_achievements' => $this->gamificationService->getAchievementsForUser($product, $user),
        ];
    }

    private function productToArray(Product $product): array
    {
        $config = $product->member_area_config;
        $logos = $config['logos'] ?? [];

        return [
            'id' => $product->id,
            'name' => $product->name,
            'checkout_slug' => $product->checkout_slug,
            'logo_light' => $logos['logo_light'] ?? '',
            'logo_dark' => $logos['logo_dark'] ?? '',
        ];
    }

    /**
     * Retorna lista de "continuar assistindo": um item por seção (o último progresso de cada seção).
     */
    private function getContinueWatching(Product $product, $user): array
    {
        $lessonIds = $this->progressService->lessonIdsForMemberAreaHost($product);
        if ($lessonIds === []) {
            return [];
        }

        $progresses = MemberLessonProgress::query()
            ->forUser($user->id)
            ->whereNull('completed_at')
            ->whereIn('member_lesson_id', $lessonIds)
            ->with('lesson.module')
            ->latest('updated_at')
            ->get();

        $wrappers = MemberModule::query()
            ->where('product_id', $product->id)
            ->whereNotNull('source_member_module_id')
            ->get()
            ->keyBy('source_member_module_id');

        $bySection = [];
        foreach ($progresses as $p) {
            if (! $p->lesson) {
                continue;
            }
            $lesson = $p->lesson;
            $wrapper = $wrappers->get($lesson->member_module_id);
            $sectionId = $wrapper?->member_section_id ?? $lesson->module?->member_section_id;
            if ($sectionId === null) {
                continue;
            }
            if (isset($bySection[$sectionId])) {
                continue;
            }
            $bySection[$sectionId] = $p;
        }

        $items = [];
        foreach ($bySection as $p) {
            $lesson = $p->lesson;
            $wrapper = $wrappers->get($lesson->member_module_id);
            $moduleForMeta = $wrapper ?? $lesson->module;
            $moduleThumbnail = null;
            if ($moduleForMeta && $moduleForMeta->thumbnail) {
                $moduleThumbnail = $this->moduleThumbnailUrl($moduleForMeta, $product);
            }
            $items[] = [
                'lesson_id' => $lesson->id,
                'module_id' => $wrapper?->id ?? $lesson->module?->id,
                'title' => $lesson->title,
                'module_title' => $moduleForMeta?->title,
                'module_thumbnail' => $moduleThumbnail,
            ];
        }

        return $items;
    }

    private function memberAreaModuleRouteName(Request $request): string
    {
        $name = $request->route()?->getName() ?? '';

        return str_ends_with($name, '.host') ? 'member-area-app.module.host' : 'member-area-app.module';
    }

    private function memberAreaModulosRouteName(Request $request): string
    {
        $name = $request->route()?->getName() ?? '';

        return str_ends_with($name, '.host') ? 'member-area-app.modulos.host' : 'member-area-app.modulos';
    }

    /**
     * Abertura de "outros produtos" mantendo o contexto da área atual.
     *
     * - Se o produto relacionado é "Link": redireciona para o endpoint deliverable.
     * - Se for área de membros: redireciona para o primeiro módulo embutido (wrapper) dentro do produto host.
     */
    public function openRelatedProduct(Request $request): RedirectResponse
    {
        $relatedProduct = (string) $request->route('relatedProduct', '');
        if ($relatedProduct === '') {
            abort(404);
        }

        $host = $this->getProduct($request);
        $user = $request->user();

        $slug = (string) ($request->attributes->get('member_area_slug') ?? $request->route('slug') ?? '');
        $slug = $slug !== '' ? $slug : (string) ($host->checkout_slug ?? '');

        if (! $user instanceof User) {
            // In host-based member areas, GET /login is handled by the platform login controller.
            $isHost = str_ends_with(($request->route()?->getName() ?? ''), '.host');
            return $isHost
                ? redirect()->to('/login')->with('error', 'Faça login para acessar a área de membros.')
                : redirect()->route('member-area.login', ['slug' => $slug])->with('error', 'Faça login para acessar a área de membros.');
        }

        $related = Product::query()->whereKey($relatedProduct)->first();
        if (! $related) {
            return redirect()->to($this->baseUrlForRequest($host, $request))
                ->with('error', 'Produto relacionado não encontrado ou indisponível.');
        }

        if ($related->type === Product::TYPE_LINK) {
            return redirect()->route($this->memberAreaProductsDeliverableRouteName($request), $this->memberAreaProductsRouteParams($request, $slug, $relatedProduct));
        }

        $wrapper = MemberModule::query()
            ->where('product_id', $host->id)
            ->where('related_product_id', $related->id)
            ->whereNotNull('source_member_module_id')
            ->orderBy('position')
            ->first();

        if (! $wrapper) {
            return redirect()->to($this->baseUrlForRequest($host, $request))
                ->with('error', 'Este produto ainda não foi embutido nesta área. No Member Builder, adicione/importa os módulos do produto para esta seção.');
        }

        $redirect = $this->assertEmbeddedProductLinkAccess($wrapper, $user);
        if ($redirect !== null) {
            return $redirect;
        }

        return redirect()->route(
            $this->memberAreaModuleRouteName($request),
            $this->memberAreaModuleRouteParams($request, $slug, (string) $wrapper->id)
        );
    }

    /**
     * Endpoint dedicado para abrir o deliverable de produtos do tipo "Link" a partir da área de membros.
     * Deve ser usado com target=_blank no front.
     */
    public function openRelatedProductDeliverable(Request $request): RedirectResponse
    {
        $relatedProduct = (string) $request->route('relatedProduct', '');
        if ($relatedProduct === '') {
            abort(404);
        }

        $host = $this->getProduct($request);
        $user = $request->user();

        $slug = (string) ($request->attributes->get('member_area_slug') ?? $request->route('slug') ?? '');
        $slug = $slug !== '' ? $slug : (string) ($host->checkout_slug ?? '');

        if (! $user instanceof User) {
            $isHost = str_ends_with(($request->route()?->getName() ?? ''), '.host');
            return $isHost
                ? redirect()->to('/login')->with('error', 'Faça login para acessar a área de membros.')
                : redirect()->route('member-area.login', ['slug' => $slug])->with('error', 'Faça login para acessar a área de membros.');
        }

        // Normaliza chaves numéricas: em SQLite products.id pode ser inteiro enquanto a rota envia string,
        // e whereKey/where em FK podem falhar de forma inconsistente entre colunas inteiras vs modelo keyType string.
        $relatedIdCandidates = array_values(array_unique(array_filter(
            [(string) $relatedProduct, is_numeric($relatedProduct) ? (int) $relatedProduct : null],
            static fn ($v) => $v !== null && $v !== ''
        )));
        // 1) Tenta resolver via card/wrapper do próprio host (fonte mais confiável do contexto "paid/free")
        $anyWrapperOrCard = MemberModule::query()
            ->with('relatedProduct')
            ->where('product_id', $host->getKey())
            ->whereIn('related_product_id', $relatedIdCandidates)
            ->orderBy('position')
            ->first();

        $related = $anyWrapperOrCard?->relatedProduct;

        // 2) Fallback: resolver pelo id diretamente (não depende de ter card importado)
        if (! $related) {
            $related = Product::query()->whereIn('id', $relatedIdCandidates)->first();
        }

        if (! $related) {
            return redirect()->to($this->baseUrlForRequest($host, $request))
                ->with('error', 'Produto relacionado não encontrado ou indisponível.');
        }

        // Gate de acesso: se existir card/wrapper no host, respeita paid/free e acesso do usuário.
        if ($anyWrapperOrCard) {
            $redirect = $this->assertEmbeddedProductLinkAccess($anyWrapperOrCard, $user);
            if ($redirect !== null) {
                return $redirect;
            }
        }

        // Abrir deliverable (produto tipo Link)
        if ($related->type === Product::TYPE_LINK) {
            $link = $this->resolveDeliverableLinkForLinkProduct($related);
            if ($link !== '') {
                return str_starts_with($link, 'http://') || str_starts_with($link, 'https://')
                    ? redirect()->away($link)
                    : redirect('/' . ltrim($link, '/'));
            }
            return redirect()->to($this->baseUrlForRequest($host, $request))
                ->with('error', 'Este produto está como tipo Link, mas o link de entrega não foi configurado.');
        }

        return redirect()->route(
            $this->memberAreaProductsOpenRouteName($request),
            $this->memberAreaProductsRouteParams($request, $slug, $relatedProduct)
        );
    }

    private function memberAreaProductsOpenRouteName(Request $request): string
    {
        $name = $request->route()?->getName() ?? '';
        return str_ends_with($name, '.host') ? 'member-area-app.products.open.host' : 'member-area-app.products.open';
    }

    private function memberAreaProductsDeliverableRouteName(Request $request): string
    {
        $name = $request->route()?->getName() ?? '';
        return str_ends_with($name, '.host') ? 'member-area-app.products.deliverable.host' : 'member-area-app.products.deliverable';
    }

    /**
     * @return array<string, mixed>
     */
    private function memberAreaProductsRouteParams(Request $request, string $slug, string $productId): array
    {
        $isHost = str_ends_with(($request->route()?->getName() ?? ''), '.host');
        return $isHost ? ['relatedProduct' => $productId] : ['slug' => $slug, 'relatedProduct' => $productId];
    }

    /**
     * @return array<string, mixed>
     */
    private function memberAreaModuleRouteParams(Request $request, string $slug, string $moduleId): array
    {
        $isHost = str_ends_with(($request->route()?->getName() ?? ''), '.host');
        return $isHost ? ['module' => $moduleId] : ['slug' => $slug, 'module' => $moduleId];
    }

    /**
     * Lê deliverable_link a partir de products.checkout_config (merge com default).
     * Prioriza o array já cast no modelo; só consulta a tabela bruta se o link continuar vazio
     * (ex.: id string vs inteiro no SQLite, ou relação carregada sem o JSON).
     */
    private function resolveDeliverableLinkForLinkProduct(Product $related): string
    {
        $fromModel = $related->checkout_config;
        $stored = is_array($fromModel) ? $fromModel : [];
        $merged = array_replace_recursive(Product::defaultCheckoutConfig(), $stored);
        $link = trim((string) ($merged['deliverable_link'] ?? ''));
        if ($link !== '') {
            return $link;
        }

        $ids = array_values(array_unique(array_filter(
            [(string) $related->getKey(), is_numeric($related->getKey()) ? (int) $related->getKey() : null],
            static fn ($v) => $v !== null && $v !== ''
        )));
        $row = DB::table('products')->whereIn('id', $ids)->first();
        $stored = [];
        if ($row && isset($row->checkout_config) && $row->checkout_config !== null) {
            $raw = $row->checkout_config;
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                $stored = is_array($decoded) ? $decoded : [];
            } elseif (is_array($raw)) {
                $stored = $raw;
            } elseif (is_object($raw)) {
                $decoded = json_decode(json_encode($raw), true);
                $stored = is_array($decoded) ? $decoded : [];
            }
        }
        $merged = array_replace_recursive(Product::defaultCheckoutConfig(), $stored);

        return trim((string) ($merged['deliverable_link'] ?? ''));
    }

    private function assertEmbeddedProductLinkAccess(MemberModule $wrapper, User $user): ?RedirectResponse
    {
        if (! $wrapper->related_product_id) {
            return null;
        }
        $relatedFkIds = array_values(array_unique(array_filter(
            [(string) $wrapper->related_product_id, is_numeric($wrapper->related_product_id) ? (int) $wrapper->related_product_id : null],
            static fn ($v) => $v !== null && $v !== ''
        )));
        $hasAccess = $user->products()->whereIn('products.id', $relatedFkIds)->exists();
        if (($wrapper->access_type ?? 'paid') === 'paid' && ! $hasAccess) {
            $related = Product::query()->whereIn('id', $relatedFkIds)->first();
            if ($related?->checkout_slug) {
                return redirect()->route('checkout.show', ['slug' => $related->checkout_slug])
                    ->with('error', 'Você não tem acesso a este conteúdo.');
            }
            abort(403);
        }

        // If the embedded product is a "Link" deliverable, open the deliverable link instead of
        // trying to render it as member-area content (which would 404).
        $related = Product::query()->whereIn('id', $relatedFkIds)->first();
        if ($related?->type === Product::TYPE_LINK) {
            $link = $this->resolveDeliverableLinkForLinkProduct($related);
            if ($link !== '') {
                return str_starts_with($link, 'http://') || str_starts_with($link, 'https://')
                    ? redirect()->away($link)
                    : redirect('/' . ltrim($link, '/'));
            }
        }

        return null;
    }

    /**
     * Módulo de origem (com aulas) quando o registro é um wrapper de embed; senão o próprio módulo.
     */
    private function resolveContentModuleForWrapper(MemberModule $wrapper): MemberModule
    {
        if (! $wrapper->source_member_module_id) {
            if (! $wrapper->relationLoaded('lessons')) {
                $wrapper->load(['lessons' => fn ($q) => $q->orderBy('position')]);
            }

            return $wrapper;
        }
        $source = MemberModule::query()
            ->whereKey($wrapper->source_member_module_id)
            ->where('product_id', $wrapper->related_product_id)
            ->with(['lessons' => fn ($q) => $q->orderBy('position')])
            ->first();
        if (! $source) {
            abort(404);
        }

        return $source;
    }

    private function findWrapperForEmbeddedLesson(MemberLesson $lesson, Product $host): ?MemberModule
    {
        if ((string) $lesson->product_id === (string) $host->id) {
            return null;
        }

        return MemberModule::query()
            ->where('product_id', $host->id)
            ->where('source_member_module_id', $lesson->member_module_id)
            ->where('related_product_id', $lesson->product_id)
            ->first();
    }

    /**
     * @param  array<int|string, true>  $completedLessonIds
     * @param  array{bypass?: bool, product?: Product, user?: User, course_percent?: int, completed_set?: array}  $releaseCtx
     */
    private function mapModuleForMemberArea(MemberModule $m, MemberSection $s, Product $product, $user, array $userProductIds, string $baseUrl, Carbon $accessStartAt, Carbon $now, array $completedLessonIds, array $releaseCtx = []): array
    {
        $sectionType = $s->section_type ?? 'courses';

        if ($sectionType === 'courses') {
            return [
                'id' => $m->id,
                'title' => $m->title,
                'thumbnail' => $this->moduleThumbnailUrl($m, $product),
                'show_title_on_cover' => $m->show_title_on_cover ?? true,
                ...$this->moduleLockPayload($m, $accessStartAt, $now, $releaseCtx),
                'lessons' => $m->lessons->map(fn (MemberLesson $l) => [
                    'id' => $l->id,
                    'title' => $l->title,
                    'type' => $l->type,
                    'duration_seconds' => $l->duration_seconds,
                    'is_completed' => isset($completedLessonIds[$l->id]),
                    ...$this->lessonLockPayload($l, $m, $accessStartAt, $now, $releaseCtx),
                ])->values()->all(),
            ];
        }

        if ($sectionType === 'products') {
            $related = $m->relatedProduct;
            $hasAccess = $m->related_product_id ? $this->accessService->hasOwnedProductId($userProductIds, $m->related_product_id) : false;
            $embed = $m->source_member_module_id
                && $related
                && $related->type === Product::TYPE_AREA_MEMBROS;
            $accessType = $m->access_type ?? 'paid';
            $isFree = $accessType === 'free';
            $canOpenEmbed = $embed && ($isFree || $hasAccess);
            $deliverableLink = null;
            if ($related && $related->type === Product::TYPE_LINK && ($isFree || $hasAccess)) {
                $raw = $this->resolveDeliverableLinkForLinkProduct($related);
                $deliverableLink = $raw !== '' ? $raw : null;
            }

            return [
                'id' => $m->id,
                'title' => $m->title,
                'thumbnail' => $this->moduleThumbnailUrl($m, $product, $m->sourceModule),
                'show_title_on_cover' => $m->show_title_on_cover ?? true,
                'related_product_id' => $m->related_product_id,
                'source_member_module_id' => $m->source_member_module_id,
                'access_type' => $accessType,
                'embed' => $canOpenEmbed,
                'related_product' => $related ? [
                    'id' => $related->id,
                    'name' => $related->name,
                    'type' => $related->type,
                    'deliverable_link' => $deliverableLink,
                    'image_url' => $related->image ? (new StorageService($product->tenant_id))->url($related->image) : null,
                    'checkout_slug' => $related->checkout_slug,
                    'checkout_url' => url('/c/'.$related->checkout_slug),
                    'member_area_slug' => $related->checkout_slug,
                ] : null,
                'has_access' => $hasAccess,
            ];
        }

        // external_links
        return [
            'id' => $m->id,
            'title' => $m->title,
            'thumbnail' => $this->moduleThumbnailUrl($m, $product),
            'show_title_on_cover' => $m->show_title_on_cover ?? true,
            'external_url' => $m->external_url,
        ];
    }

    private function userAccessStartAt(Product $product, User $user): Carbon
    {
        if ($user->canAccessPanel() && $user->tenant_id === $product->tenant_id) {
            return now()->subYears(20);
        }
        $createdAt = DB::table('product_user')
            ->where('product_id', $product->id)
            ->where('user_id', $user->id)
            ->value('created_at');
        if ($createdAt) {
            return Carbon::parse($createdAt);
        }
        return now();
    }

    private function scheduleMeta(?int $afterDays, mixed $atDate, Carbon $accessStartAt): array
    {
        if ($atDate instanceof Carbon) {
            return ['available_at' => $atDate->copy()->startOfDay(), 'mode' => 'date'];
        }
        if (is_string($atDate) && $atDate !== '') {
            return ['available_at' => Carbon::createFromFormat('Y-m-d', $atDate)->startOfDay(), 'mode' => 'date'];
        }
        if (is_int($afterDays) && $afterDays > 0) {
            return ['available_at' => $accessStartAt->copy()->addDays($afterDays), 'mode' => 'days'];
        }
        return ['available_at' => null, 'mode' => null];
    }

    private function accessExpiresAt(?int $durationDays, Carbon $accessStartAt): ?Carbon
    {
        if (! is_int($durationDays) || $durationDays <= 0 || auth()->user()?->canAccessPanel()) {
            return null;
        }

        return $accessStartAt->copy()->addDays($durationDays);
    }

    private function lockPayload(?Carbon $availableAt, ?Carbon $expiresAt, Carbon $now, ?string $mode): array
    {
        $payload = [
            'is_locked' => false,
            'available_at' => $availableAt?->toIso8601String(),
            'expires_at' => $expiresAt?->toIso8601String(),
            'lock_message' => null,
            'lock_reason' => null,
        ];

        if ($expiresAt && $expiresAt->lessThanOrEqualTo($now)) {
            return [
                ...$payload,
                'is_locked' => true,
                'lock_message' => 'Acesso encerrado em '.$expiresAt->format('d/m/Y'),
                'lock_reason' => 'expired',
            ];
        }
        if (! $availableAt || $availableAt->lessThanOrEqualTo($now)) {
            return $payload;
        }
        $message = null;
        if ($mode === 'date') {
            $message = 'Disponível em '.$availableAt->format('d/m/Y');
        } elseif ($mode === 'days') {
            // Carbon 3: diffInDays() retorna float (interpolação entre dias); exibir número inteiro.
            $days = max(1, (int) round($now->diffInDays($availableAt, true)));
            $message = $days === 1
                ? 'Disponível em 1 dia'
                : 'Disponível em '.$days.' dias';
        } else {
            $message = 'Disponível em '.$availableAt->format('d/m/Y H:i');
        }
        return [
            ...$payload,
            'is_locked' => true,
            'lock_message' => $message,
            'lock_reason' => 'scheduled',
        ];
    }

    /**
     * Contexto de progresso para liberação de módulos (reutilizado nas listagens).
     *
     * @return array{
     *     bypass: bool,
     *     product?: Product,
     *     user?: User,
     *     course_percent?: int,
     *     completed_set?: array<int|string, true>
     * }
     */
    private function makeReleaseContext(Product $product, User $user): array
    {
        if ($user->canAccessPanel() && $user->tenant_id === $product->tenant_id) {
            return ['bypass' => true];
        }

        return [
            'bypass' => false,
            'product' => $product,
            'user' => $user,
            'course_percent' => $this->progressService->completionPercent($product, $user),
            'completed_set' => $this->progressService->completedLessonIdSet($product, $user),
        ];
    }

    /**
     * Lock por progresso / pré-requisitos (null = sem trava por progresso).
     *
     * @param  array{bypass?: bool, product?: Product, user?: User, course_percent?: int, completed_set?: array}  $releaseCtx
     * @return array{is_locked: bool, available_at: null, expires_at: null, lock_message: string, lock_reason: string}|null
     */
    private function moduleProgressLockPayload(MemberModule $module, array $releaseCtx): ?array
    {
        if (! empty($releaseCtx['bypass'])) {
            return null;
        }

        $product = $releaseCtx['product'] ?? null;
        $user = $releaseCtx['user'] ?? null;
        if (! $product instanceof Product || ! $user instanceof User) {
            return null;
        }

        $progressPercent = $module->release_progress_percent;
        if (is_int($progressPercent) && $progressPercent > 0) {
            $current = (int) ($releaseCtx['course_percent'] ?? $this->progressService->completionPercent($product, $user));
            if ($current < $progressPercent) {
                return [
                    'is_locked' => true,
                    'available_at' => null,
                    'expires_at' => null,
                    'lock_message' => 'Disponível ao concluir '.$progressPercent.'% do curso (seu progresso: '.$current.'%)',
                    'lock_reason' => 'progress',
                ];
            }

            return null;
        }

        $requiredIds = $module->release_required_module_ids;
        if (! is_array($requiredIds) || $requiredIds === []) {
            return null;
        }

        $completedSet = $releaseCtx['completed_set'] ?? null;
        $missing = $this->progressService->incompleteRequiredModuleTitles(
            $requiredIds,
            $product,
            $user,
            is_array($completedSet) ? $completedSet : null
        );
        if ($missing === []) {
            return null;
        }

        $list = implode(', ', $missing);

        return [
            'is_locked' => true,
            'available_at' => null,
            'expires_at' => null,
            'lock_message' => count($missing) === 1
                ? 'Conclua o módulo: '.$list
                : 'Conclua os módulos: '.$list,
            'lock_reason' => 'modules',
        ];
    }

    /**
     * @param  array{bypass?: bool, product?: Product, user?: User, course_percent?: int, completed_set?: array}  $releaseCtx
     * @return array{is_locked: bool, available_at: ?string, expires_at: ?string, lock_message: ?string, lock_reason: ?string}
     */
    private function moduleLockPayload(MemberModule $module, Carbon $accessStartAt, Carbon $now, array $releaseCtx = []): array
    {
        $expiresAt = $this->accessExpiresAt($module->access_duration_days, $accessStartAt);
        if ($expiresAt && $expiresAt->lessThanOrEqualTo($now)) {
            return [
                'is_locked' => true,
                'available_at' => null,
                'expires_at' => $expiresAt->toIso8601String(),
                'lock_message' => 'Acesso encerrado em '.$expiresAt->format('d/m/Y'),
                'lock_reason' => 'expired',
            ];
        }

        $progressLock = $this->moduleProgressLockPayload($module, $releaseCtx);
        if ($progressLock !== null) {
            return [
                ...$progressLock,
                'expires_at' => $expiresAt?->toIso8601String(),
            ];
        }

        $meta = $this->scheduleMeta($module->release_after_days, $module->release_at_date, $accessStartAt);

        return $this->lockPayload($meta['available_at'], $expiresAt, $now, $meta['mode']);
    }

    /**
     * @param  array{bypass?: bool, product?: Product, user?: User, course_percent?: int, completed_set?: array}  $releaseCtx
     * @return array{is_locked: bool, available_at: ?string, expires_at: ?string, lock_message: ?string, lock_reason: ?string}
     */
    private function lessonLockPayload(MemberLesson $lesson, ?MemberModule $module, Carbon $accessStartAt, Carbon $now, array $releaseCtx = []): array
    {
        $lessonExpiresAt = $this->accessExpiresAt($lesson->access_duration_days, $accessStartAt);
        $moduleExpiresAt = $module ? $this->accessExpiresAt($module->access_duration_days, $accessStartAt) : null;
        $expiresAt = null;
        if ($lessonExpiresAt && $moduleExpiresAt) {
            $expiresAt = $lessonExpiresAt->lessThanOrEqualTo($moduleExpiresAt) ? $lessonExpiresAt : $moduleExpiresAt;
        } else {
            $expiresAt = $lessonExpiresAt ?? $moduleExpiresAt;
        }

        if ($expiresAt && $expiresAt->lessThanOrEqualTo($now)) {
            return [
                'is_locked' => true,
                'available_at' => null,
                'expires_at' => $expiresAt->toIso8601String(),
                'lock_message' => 'Acesso encerrado em '.$expiresAt->format('d/m/Y'),
                'lock_reason' => 'expired',
            ];
        }

        if ($module) {
            $progressLock = $this->moduleProgressLockPayload($module, $releaseCtx);
            if ($progressLock !== null) {
                return [
                    ...$progressLock,
                    'expires_at' => $expiresAt?->toIso8601String(),
                ];
            }
        }

        $lessonMeta = $this->scheduleMeta($lesson->release_after_days, $lesson->release_at_date, $accessStartAt);
        $moduleMeta = $module ? $this->scheduleMeta($module->release_after_days, $module->release_at_date, $accessStartAt) : ['available_at' => null, 'mode' => null];

        $lessonAt = $lessonMeta['available_at'];
        $moduleAt = $moduleMeta['available_at'];
        $availableAt = null;
        $mode = null;
        if ($lessonAt && $moduleAt) {
            if ($lessonAt->greaterThanOrEqualTo($moduleAt)) {
                $availableAt = $lessonAt;
                $mode = $lessonMeta['mode'];
            } else {
                $availableAt = $moduleAt;
                $mode = $moduleMeta['mode'];
            }
        } else {
            if ($lessonAt) {
                $availableAt = $lessonAt;
                $mode = $lessonMeta['mode'];
            } elseif ($moduleAt) {
                $availableAt = $moduleAt;
                $mode = $moduleMeta['mode'];
            }
        }

        return $this->lockPayload($availableAt, $expiresAt, $now, $mode);
    }

    private function moduleThumbnailUrl(MemberModule $module, Product $product, ?MemberModule $fallback = null): ?string
    {
        $raw = trim((string) ($module->thumbnail ?: $fallback?->thumbnail ?: ''));
        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            return $raw;
        }

        if (str_starts_with($raw, '/storage/')) {
            return $raw;
        }

        if (str_starts_with($raw, 'storage/')) {
            return '/'.ltrim($raw, '/');
        }

        return (new StorageService($product->tenant_id))->url($raw);
    }

    /**
     * @return list<array{id: int, title: string, thumbnail: ?string, section_title: ?string, first_lesson: array{id: int, title: string}}>
     * @param  array{bypass?: bool, product?: Product, user?: User, course_percent?: int, completed_set?: array}  $releaseCtx
     */
    private function nextModulesPayload(Product $product, MemberModule $currentRouteModule, User $user, Carbon $accessStartAt, Carbon $now, array $releaseCtx = [], int $limit = 6): array
    {
        $sections = $product->memberSections()
            ->with(['modules' => fn ($q) => $q->orderBy('position')])
            ->orderBy('position')
            ->get();

        $flat = [];
        foreach ($sections as $section) {
            foreach ($section->modules as $routeModule) {
                $flat[] = ['module' => $routeModule, 'section' => $section];
            }
        }

        $currentIndex = null;
        foreach ($flat as $i => $item) {
            if ((int) $item['module']->id === (int) $currentRouteModule->id) {
                $currentIndex = $i;
                break;
            }
        }

        if ($currentIndex === null) {
            return [];
        }

        $result = [];
        for ($i = $currentIndex + 1; $i < count($flat) && count($result) < $limit; $i++) {
            $routeModule = $flat[$i]['module'];
            $section = $flat[$i]['section'];

            if (! $this->userCanAccessRouteModule($routeModule, $user)) {
                continue;
            }

            $lock = $this->moduleLockPayload($routeModule, $accessStartAt, $now, $releaseCtx);
            if (($lock['is_locked'] ?? false) === true) {
                continue;
            }

            $effective = $this->resolveContentModuleForWrapper($routeModule);
            $firstLesson = $effective->lessons->first(function (MemberLesson $lesson) use ($routeModule, $accessStartAt, $now, $releaseCtx) {
                return ($this->lessonLockPayload($lesson, $routeModule, $accessStartAt, $now, $releaseCtx)['is_locked'] ?? false) !== true;
            });

            if (! $firstLesson) {
                continue;
            }

            $result[] = [
                'id' => $routeModule->id,
                'title' => $routeModule->title,
                'thumbnail' => $this->moduleThumbnailUrl($routeModule, $product, $effective),
                'section_title' => $section->title,
                'first_lesson' => [
                    'id' => $firstLesson->id,
                    'title' => $firstLesson->title,
                ],
            ];
        }

        return $result;
    }

    private function userCanAccessRouteModule(MemberModule $routeModule, User $user): bool
    {
        if (! $routeModule->related_product_id) {
            return true;
        }

        $relatedFkIds = array_values(array_unique(array_filter(
            [(string) $routeModule->related_product_id, is_numeric($routeModule->related_product_id) ? (int) $routeModule->related_product_id : null],
            static fn ($v) => $v !== null && $v !== ''
        )));

        $hasAccess = $user->products()->whereIn('products.id', $relatedFkIds)->exists();
        if (($routeModule->access_type ?? 'paid') === 'paid' && ! $hasAccess) {
            return false;
        }

        $related = Product::query()->whereIn('id', $relatedFkIds)->first();

        return $related?->type !== Product::TYPE_LINK;
    }

    /**
     * @param  list<array{id: int, title: string, thumbnail: ?string, section_title: ?string, first_lesson: array{id: int, title: string}}>  $nextModules
     * @param  array{bypass?: bool, product?: Product, user?: User, course_percent?: int, completed_set?: array}  $releaseCtx
     * @return array{
     *     prev: array{id: int, title: string}|null,
     *     next: array{id: int, title: string}|null,
     *     next_module: array{id: int, title: string, first_lesson_id: int, first_lesson_title: string}|null
     * }
     */
    private function lessonNavigationPayload(
        MemberModule $contentModule,
        ?MemberLesson $currentLesson,
        Carbon $accessStartAt,
        Carbon $now,
        array $nextModules = [],
        array $releaseCtx = [],
        ?MemberModule $lockModule = null,
    ): array {
        if (! $currentLesson) {
            return ['prev' => null, 'next' => null, 'next_module' => null];
        }

        $lockModule = $lockModule ?? $contentModule;
        $unlocked = $contentModule->lessons->filter(function (MemberLesson $lesson) use ($lockModule, $accessStartAt, $now, $releaseCtx) {
            return ($this->lessonLockPayload($lesson, $lockModule, $accessStartAt, $now, $releaseCtx)['is_locked'] ?? false) !== true;
        })->values();

        $index = $unlocked->search(fn (MemberLesson $lesson) => $lesson->id === $currentLesson->id);
        if ($index === false) {
            return ['prev' => null, 'next' => null, 'next_module' => null];
        }

        $prev = null;
        $next = null;
        if ($index > 0) {
            $p = $unlocked[$index - 1];
            $prev = ['id' => $p->id, 'title' => $p->title];
        }
        if ($index < $unlocked->count() - 1) {
            $n = $unlocked[$index + 1];
            $next = ['id' => $n->id, 'title' => $n->title];
        }

        $nextModule = null;
        if ($next === null && $nextModules !== []) {
            $first = $nextModules[0];
            $nextModule = [
                'id' => $first['id'],
                'title' => $first['title'],
                'first_lesson_id' => $first['first_lesson']['id'],
                'first_lesson_title' => $first['first_lesson']['title'],
            ];
        }

        return ['prev' => $prev, 'next' => $next, 'next_module' => $nextModule];
    }

    private function isLessonCompleted(int $userId, int $lessonId): bool
    {
        return \App\Models\MemberLessonProgress::where('user_id', $userId)
            ->where('member_lesson_id', $lessonId)
            ->whereNotNull('completed_at')
            ->exists();
    }

    /**
     * Dados do aluno para marca d'água (nome, email, cpf se houver no pedido).
     */
    private function getStudentWatermarkData(User $user, Product $product): array
    {
        $cpf = Order::where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->where('status', 'completed')
            ->latest()
            ->value('cpf');
        if (is_string($cpf) && trim($cpf) !== '') {
            return ['name' => $user->name ?? '', 'email' => $user->email ?? '', 'cpf' => trim($cpf)];
        }

        return ['name' => $user->name ?? '', 'email' => $user->email ?? '', 'cpf' => null];
    }

    public function manifest(Request $request, ?string $slug = null): \Illuminate\Http\JsonResponse
    {
        $product = $request->route('product') ?? $request->attributes->get('member_area_product');
        if (! $product instanceof Product || $product->type !== Product::TYPE_AREA_MEMBROS) {
            abort(404);
        }
        $slug = $slug ?? $request->route('slug') ?? $request->attributes->get('member_area_slug');
        $baseUrl = rtrim($this->baseUrlForRequest($product, $request), '/');
        $config = $product->member_area_config;
        $pwa = $config['pwa'] ?? [];
        $logos = $config['logos'] ?? [];
        $name = $pwa['name'] ?: $product->name;
        $shortName = $pwa['short_name'] ?: $name;
        $themeColor = $pwa['theme_color'] ?? '#0ea5e9';

        $icons = [];
        $faviconUrl = $logos['favicon'] ?? $pwa['favicon'] ?? null;
        if ($faviconUrl) {
            $iconUrl = str_starts_with($faviconUrl, 'http') ? $faviconUrl : (str_starts_with($faviconUrl, '/') ? $request->getSchemeAndHttpHost().$faviconUrl : $request->getSchemeAndHttpHost().'/'.ltrim($faviconUrl, '/'));
            $icons[] = ['src' => $iconUrl, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'];
            $icons[] = ['src' => $iconUrl, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'];
        }
        if (isset($pwa['icons']) && is_array($pwa['icons'])) {
            foreach ($pwa['icons'] as $icon) {
                $src = $icon['src'] ?? null;
                if ($src && ! str_starts_with($src, 'http')) {
                    $src = $request->getSchemeAndHttpHost().(str_starts_with($src, '/') ? $src : '/'.ltrim($src, '/'));
                }
                if ($src) {
                    $icons[] = [
                        'src' => $src,
                        'sizes' => $icon['sizes'] ?? '192x192',
                        'type' => $icon['type'] ?? 'image/png',
                        'purpose' => $icon['purpose'] ?? 'any maskable',
                    ];
                }
            }
        }
        if (empty($icons)) {
            $icons[] = [
                'src' => $request->getSchemeAndHttpHost().'/images/gateways/pix.svg',
                'sizes' => '192x192',
                'type' => 'image/svg+xml',
                'purpose' => 'any maskable',
            ];
        }

        // `id` único para o Android tratar como app separado do painel (mesmo origin); evita "já está instalado"
        $manifestId = $slug ? '/m/'.$slug : ($baseUrl ? parse_url($baseUrl, PHP_URL_PATH) : '/m/member-area');

        $manifest = [
            'id' => $manifestId,
            'name' => $name,
            'short_name' => $shortName,
            'start_url' => $baseUrl,
            'scope' => $baseUrl.'/',
            'display' => 'standalone',
            'background_color' => $config['theme']['background'] ?? '#18181b',
            'theme_color' => $themeColor,
            'icons' => $icons,
        ];

        return response()->json($manifest)->header('Content-Type', 'application/manifest+json');
    }

    private function baseUrlForRequest(Product $product, Request $request): string
    {
        $accessType = $request->attributes->get('member_area_access_type');
        if (in_array($accessType, ['subdomain', 'custom'], true)) {
            return rtrim($request->getSchemeAndHttpHost(), '/');
        }

        return $this->resolver->baseUrlForProduct($product);
    }

    private function assertLessonViewable(Request $request, MemberLesson $lesson): void
    {
        $product = $this->getProduct($request);
        $user = $request->user();
        if (! $user) {
            abort(401);
        }
        $lesson->loadMissing('module');
        $wrapper = $this->findWrapperForEmbeddedLesson($lesson, $product);
        if ((string) $lesson->product_id !== (string) $product->id && $wrapper === null) {
            abort(404);
        }
        if ($wrapper !== null) {
            $redirect = $this->assertEmbeddedProductLinkAccess($wrapper, $user);
            if ($redirect !== null) {
                abort(403, 'Sem acesso a este conteúdo.');
            }
        }
        $accessStartAt = $this->userAccessStartAt($product, $user);
        $now = now();
        $releaseCtx = $this->makeReleaseContext($product, $user);
        $effectiveModule = $wrapper !== null
            ? $this->resolveContentModuleForWrapper($wrapper)
            : $lesson->module;
        $lockModule = $wrapper ?? $effectiveModule;
        if ($lockModule) {
            $moduleLock = $this->moduleLockPayload($lockModule, $accessStartAt, $now, $releaseCtx);
            if (($moduleLock['is_locked'] ?? false) === true) {
                abort(403, $moduleLock['lock_message'] ?? 'Módulo ainda não liberado.');
            }
        }
        $lessonLock = $this->lessonLockPayload($lesson, $lockModule, $accessStartAt, $now, $releaseCtx);
        if (($lessonLock['is_locked'] ?? false) === true) {
            abort(403, $lessonLock['lock_message'] ?? 'Aula ainda não liberada.');
        }
    }

    /**
     * Mesma ordem que `normalizePdfFiles` no frontend (MemberAreaApp).
     *
     * @return list<string>
     */
    private function pdfPresentationSourceUrls(MemberLesson $lesson): array
    {
        $urls = [];
        $files = $lesson->content_files;
        if (is_array($files)) {
            foreach ($files as $it) {
                if (is_string($it)) {
                    $u = trim($it);
                    if ($u !== '') {
                        $urls[] = $u;
                    }
                } elseif (is_array($it)) {
                    $u = trim((string) ($it['url'] ?? ''));
                    if ($u !== '') {
                        $urls[] = $u;
                    }
                }
            }
        }
        if ($urls === [] && $lesson->content_url) {
            $u = trim((string) $lesson->content_url);
            if ($u !== '') {
                $urls[] = $u;
            }
        }

        return $urls;
    }
}
