<?php

namespace App\Http\Controllers;

use App\Models\MemberCertificateIssued;
use App\Models\MemberComment;
use App\Models\MemberCommunityPage;
use App\Models\MemberCommunityPost;
use App\Models\MemberCommunityPostComment;
use App\Models\MemberCommunityPostLike;
use App\Models\MemberInternalProduct;
use App\Models\MemberLesson;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MemberAreaAppController extends Controller
{
    public function __construct(
        protected MemberProgressService $progressService,
        protected MemberAreaResolver $resolver,
        protected GamificationService $gamificationService
    ) {}

    public function show(Request $request, string $slug): Response
    {
        $product = $this->getProduct($request);
        $user = $request->user();
        $accessStartAt = $this->userAccessStartAt($product, $user);
        $now = now();
        $config = $product->member_area_config;
        $sections = $product->memberSections()->with(['modules.lessons', 'modules.relatedProduct'])->orderBy('position')->get();
        $progressPercent = $this->progressService->completionPercent($product, $user);
        $continueWatching = $this->getContinueWatching($product, $user);
        $internalProducts = $product->memberInternalProducts()->with('relatedProduct')->orderBy('position')->get();
        $baseUrl = $this->baseUrlForRequest($product, $request);
        $userProductIds = $user->products()->pluck('products.id')->flip()->all();
        $push = $this->pushProps($product);

        return Inertia::render('MemberAreaApp/Show', [
            'product' => $this->productToArray($product),
            'config' => $config,
            'sections' => $sections->map(fn (MemberSection $s) => [
                'id' => $s->id,
                'title' => $s->title,
                'cover_mode' => $s->cover_mode ?? 'vertical',
                'section_type' => $s->section_type ?? 'courses',
                'modules' => $s->modules->map(fn ($m) => $this->mapModuleForMemberArea($m, $s, $product, $user, $userProductIds, $baseUrl, $accessStartAt, $now))->values()->all(),
            ])->values()->all(),
            'progress_percent' => $progressPercent,
            'continue_watching' => $continueWatching,
            'internal_products' => $internalProducts->map(fn (MemberInternalProduct $ip) => [
                'id' => $ip->related_product_id,
                'name' => $ip->relatedProduct?->name,
                'image_url' => $ip->relatedProduct?->image ? (new StorageService($product->tenant_id))->url($ip->relatedProduct->image) : null,
                'checkout_slug' => $ip->relatedProduct?->checkout_slug,
                'has_access' => $user->products()->where('products.id', $ip->related_product_id)->exists(),
            ])->values()->all(),
            'community_enabled' => (bool) ($config['community_enabled'] ?? false),
            'certificate_enabled' => (bool) (($config['certificate'] ?? [])['enabled'] ?? false),
            'can_issue_certificate' => $this->progressService->canIssueCertificate($product, $user),
            'base_url' => $baseUrl,
            'slug' => $slug,
            'push_enabled' => $push['push_enabled'],
            'vapid_public' => $push['vapid_public'],
        ] + $this->gamificationProps($product, $user));
    }

    public function modulos(Request $request, string $slug): Response
    {
        $product = $this->getProduct($request);
        $user = $request->user();
        $accessStartAt = $this->userAccessStartAt($product, $user);
        $now = now();
        $sections = $product->memberSections()->with(['modules.lessons'])->orderBy('position')->get();

        return Inertia::render('MemberAreaApp/Modulos', [
            'product' => $this->productToArray($product),
            'config' => $product->member_area_config,
            'sections' => $sections->map(fn (MemberSection $s) => [
                'id' => $s->id,
                'title' => $s->title,
                'cover_mode' => $s->cover_mode ?? 'vertical',
                'modules' => $s->modules->map(function (MemberModule $m) use ($accessStartAt, $now, $user) {
                    $effective = ($m->source_member_module_id)
                        ? $this->resolveContentModuleForWrapper($m)
                        : $m;

                    return [
                        'id' => $m->id,
                        'title' => $m->title,
                        'thumbnail' => $m->thumbnail,
                        'show_title_on_cover' => $m->show_title_on_cover ?? true,
                        ...$this->moduleLockPayload($effective, $accessStartAt, $now),
                        'lessons' => $effective->lessons->map(fn (MemberLesson $l) => [
                            'id' => $l->id,
                            'title' => $l->title,
                            'type' => $l->type,
                            'duration_seconds' => $l->duration_seconds,
                            'is_completed' => $this->isLessonCompleted($user->id, $l->id),
                            ...$this->lessonLockPayload($l, $effective, $accessStartAt, $now),
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
        if ($module->source_member_module_id) {
            $redirect = $this->assertEmbeddedProductLinkAccess($module, $user);
            if ($redirect !== null) {
                return $redirect;
            }
        }
        $module->load('section');
        $effectiveModule = $this->resolveContentModuleForWrapper($module);
        $moduleLock = $this->moduleLockPayload($effectiveModule, $accessStartAt, $now);
        if (($moduleLock['is_locked'] ?? false) === true) {
            return redirect()->route($this->memberAreaModulosRouteName($request), ['slug' => $slug])
                ->with('error', $moduleLock['lock_message'] ?? 'Módulo ainda não liberado.');
        }
        $lessons = $effectiveModule->lessons->map(fn (MemberLesson $l) => [
            'id' => $l->id,
            'title' => $l->title,
            'type' => $l->type,
            'position' => $l->position,
            'duration_seconds' => $l->duration_seconds,
            'is_completed' => $this->isLessonCompleted($user->id, $l->id),
            ...$this->lessonLockPayload($l, $effectiveModule, $accessStartAt, $now),
        ])->values()->all();

        $lessonId = $request->query('aula');
        $currentLesson = $lessonId
            ? $effectiveModule->lessons->firstWhere('id', (int) $lessonId)
            : $effectiveModule->lessons->first();
        if ($currentLesson) {
            $lock = $this->lessonLockPayload($currentLesson, $effectiveModule, $accessStartAt, $now);
            if (($lock['is_locked'] ?? false) === true) {
                $firstUnlocked = $effectiveModule->lessons->first(function (MemberLesson $l) use ($effectiveModule, $accessStartAt, $now) {
                    return ($this->lessonLockPayload($l, $effectiveModule, $accessStartAt, $now)['is_locked'] ?? false) !== true;
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
        }

        $progressPercent = $this->progressService->completionPercent($product, $user);

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
                ...$this->moduleLockPayload($m, $accessStartAt, $now),
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
                'section' => $module->section ? ['id' => $module->section->id, 'title' => $module->section->title] : null,
            ],
            'lessons' => $lessons,
            'current_lesson' => $currentLessonData,
            'progress_percent' => $progressPercent,
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
        $effectiveModule = $wrapper !== null
            ? $this->resolveContentModuleForWrapper($wrapper)
            : $lesson->module;
        if ($effectiveModule) {
            $moduleLock = $this->moduleLockPayload($effectiveModule, $accessStartAt, $now);
            if (($moduleLock['is_locked'] ?? false) === true) {
                return redirect()->route($this->memberAreaModulosRouteName($request), ['slug' => $slug])
                    ->with('error', $moduleLock['lock_message'] ?? 'Módulo ainda não liberado.');
            }
        }
        $lessonLock = $this->lessonLockPayload($lesson, $effectiveModule, $accessStartAt, $now);
        if (($lessonLock['is_locked'] ?? false) === true) {
            $moduleRouteId = $wrapper?->id ?? $lesson->module?->id;
            if ($moduleRouteId) {
                return redirect()->route($this->memberAreaModuleRouteName($request), ['slug' => $slug, 'module' => $moduleRouteId])
                    ->with('error', $lessonLock['lock_message'] ?? 'Aula ainda não liberada.');
            }
            return redirect()->route($this->memberAreaModulosRouteName($request), ['slug' => $slug])
                ->with('error', $lessonLock['lock_message'] ?? 'Aula ainda não liberada.');
        }
        $this->progressService->ensureLessonStarted($lesson, $user);

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
        $this->progressService->markLessonCompleted($lesson->id, $user);

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
        $userProductIds = $user->products()->pluck('products.id')->flip()->all();

        $items = $internalProducts->map(fn (MemberInternalProduct $ip) => [
            'id' => $ip->related_product_id,
            'name' => $ip->relatedProduct?->name,
            'description' => $ip->relatedProduct?->description,
            'image_url' => $ip->relatedProduct?->image ? (new StorageService($product->tenant_id))->url($ip->relatedProduct->image) : null,
            'checkout_slug' => $ip->relatedProduct?->checkout_slug,
            'price' => $ip->relatedProduct?->price,
            'has_access' => isset($userProductIds[$ip->related_product_id]),
        ])->values()->all();

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
            'pages' => $pages->map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'icon' => $p->icon,
                'slug' => $p->slug,
                'banner_url' => $p->banner ? (new StorageService($product->tenant_id))->url($p->banner) : null,
            ])->values()->all(),
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
        $posts->getCollection()->transform(function (MemberCommunityPost $post) use ($user) {
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
            'pages' => $pages->map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'icon' => $p->icon,
                'slug' => $p->slug,
                'banner_url' => $p->banner ? (new StorageService($product->tenant_id))->url($p->banner) : null,
            ])->values()->all(),
            'page' => [
                'id' => $page->id,
                'title' => $page->title,
                'icon' => $page->icon,
                'slug' => $page->slug,
                'banner_url' => $page->banner ? (new StorageService($product->tenant_id))->url($page->banner) : null,
                'is_public_posting' => $page->is_public_posting,
            ],
            'posts' => $posts,
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
            'image' => ['nullable', 'file', 'image', 'max:5120'],
        ]);
        $imagePath = null;
        if ($request->hasFile('image')) {
            $storage = new StorageService($product->tenant_id);
            $imagePath = $storage->putFile('member-area-posts/'.$product->id, $request->file('image'));
        }
        MemberCommunityPost::create([
            'member_community_page_id' => $page->id,
            'user_id' => $request->user()->id,
            'content' => $validated['content'],
            'image' => $imagePath,
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
            'endpoint' => ['required', 'string', 'max:500'],
            'keys' => ['required', 'array'],
            'keys.auth' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
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
                $moduleThumbnail = str_starts_with($moduleForMeta->thumbnail, 'http') ? $moduleForMeta->thumbnail : (new StorageService($product->tenant_id))->url($moduleForMeta->thumbnail);
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

    private function assertEmbeddedProductLinkAccess(MemberModule $wrapper, User $user): ?RedirectResponse
    {
        if (! $wrapper->related_product_id) {
            return null;
        }
        $hasAccess = $user->products()->where('products.id', $wrapper->related_product_id)->exists();
        if (($wrapper->access_type ?? 'paid') === 'paid' && ! $hasAccess) {
            $related = Product::find($wrapper->related_product_id);
            if ($related?->checkout_slug) {
                return redirect()->route('checkout.show', ['slug' => $related->checkout_slug])
                    ->with('error', 'Você não tem acesso a este conteúdo.');
            }
            abort(403);
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

    private function mapModuleForMemberArea(MemberModule $m, MemberSection $s, Product $product, $user, array $userProductIds, string $baseUrl, Carbon $accessStartAt, Carbon $now): array
    {
        $sectionType = $s->section_type ?? 'courses';

        if ($sectionType === 'courses') {
            return [
                'id' => $m->id,
                'title' => $m->title,
                'thumbnail' => $m->thumbnail,
                'show_title_on_cover' => $m->show_title_on_cover ?? true,
                ...$this->moduleLockPayload($m, $accessStartAt, $now),
                'lessons' => $m->lessons->map(fn (MemberLesson $l) => [
                    'id' => $l->id,
                    'title' => $l->title,
                    'type' => $l->type,
                    'duration_seconds' => $l->duration_seconds,
                    'is_completed' => $this->progressService->completedLessonsCount($product, $user) > 0
                        ? $this->isLessonCompleted($user->id, $l->id)
                        : false,
                    ...$this->lessonLockPayload($l, $m, $accessStartAt, $now),
                ])->values()->all(),
            ];
        }

        if ($sectionType === 'products') {
            $related = $m->relatedProduct;
            $hasAccess = $m->related_product_id ? isset($userProductIds[$m->related_product_id]) : false;
            $embed = $m->source_member_module_id
                && $related
                && $related->type === Product::TYPE_AREA_MEMBROS;
            $accessType = $m->access_type ?? 'paid';
            $isFree = $accessType === 'free';
            $canOpenEmbed = $embed && ($isFree || $hasAccess);

            return [
                'id' => $m->id,
                'title' => $m->title,
                'thumbnail' => $m->thumbnail,
                'show_title_on_cover' => $m->show_title_on_cover ?? true,
                'related_product_id' => $m->related_product_id,
                'source_member_module_id' => $m->source_member_module_id,
                'access_type' => $accessType,
                'embed' => $canOpenEmbed,
                'related_product' => $related ? [
                    'id' => $related->id,
                    'name' => $related->name,
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
            'thumbnail' => $m->thumbnail,
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

    private function lockPayload(?Carbon $availableAt, Carbon $now, ?string $mode): array
    {
        if (! $availableAt) {
            return ['is_locked' => false, 'available_at' => null, 'lock_message' => null];
        }
        if ($availableAt->lessThanOrEqualTo($now)) {
            return ['is_locked' => false, 'available_at' => $availableAt->toIso8601String(), 'lock_message' => null];
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
        return ['is_locked' => true, 'available_at' => $availableAt->toIso8601String(), 'lock_message' => $message];
    }

    private function moduleLockPayload(MemberModule $module, Carbon $accessStartAt, Carbon $now): array
    {
        $meta = $this->scheduleMeta($module->release_after_days, $module->release_at_date, $accessStartAt);
        return $this->lockPayload($meta['available_at'], $now, $meta['mode']);
    }

    private function lessonLockPayload(MemberLesson $lesson, ?MemberModule $module, Carbon $accessStartAt, Carbon $now): array
    {
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
        return $this->lockPayload($availableAt, $now, $mode);
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
}
