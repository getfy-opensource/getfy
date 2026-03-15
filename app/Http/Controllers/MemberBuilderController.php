<?php

namespace App\Http\Controllers;

use App\Models\MemberAreaDomain;
use App\Models\MemberComment;
use App\Models\MemberCommunityPage;
use App\Models\MemberCommunityPost;
use App\Models\MemberInternalProduct;
use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\MemberTurma;
use App\Models\Product;
use App\Models\User;
use App\Models\MemberNotification;
use App\Models\MemberPushSubscription;
use Illuminate\Support\Facades\Hash;
use App\Services\MemberAreaResolver;
use App\Services\MemberCommentService;
use App\Services\StorageService;
use App\Services\GamificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

class MemberBuilderController extends Controller
{
    private function normalizeLessonContentFiles(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        $out = [];
        foreach ($input as $item) {
            if (is_string($item)) {
                $url = trim($item);
                if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
                    $out[] = ['url' => $url, 'name' => 'Material'];
                }
                continue;
            }
            if (! is_array($item)) {
                continue;
            }
            $url = isset($item['url']) ? trim((string) $item['url']) : '';
            $name = isset($item['name']) ? trim((string) $item['name']) : '';
            if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }
            $out[] = [
                'url' => $url,
                'name' => $name !== '' ? mb_substr($name, 0, 255) : 'Material',
            ];
        }

        return array_slice($out, 0, 30);
    }

    public function __construct(
        protected MemberCommentService $commentService,
        protected GamificationService $gamificationService
    ) {}

    public function index(Product $produto): View|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($produto->type !== Product::TYPE_AREA_MEMBROS) {
            return redirect()->route('produtos.edit', $produto)->with('error', 'Member Builder só está disponível para produtos do tipo Área de membros.');
        }

        $produto->refresh();
        $produto->load([
            'memberAreaDomain',
            'memberSections.modules.lessons',
            'memberSections.modules.relatedProduct',
            'memberInternalProducts.relatedProduct',
            'memberTurmas.users:id,name,email',
            'memberCommunityPages',
        ]);

        // Garantir que exista ao menos a "Turma padrão"
        if ($produto->memberTurmas->isEmpty()) {
            MemberTurma::create([
                'product_id' => $produto->id,
                'name' => 'Turma padrão',
                'position' => 1,
            ]);
            $produto->load('memberTurmas.users:id,name,email');
        }

        $memberAreaUrl = app(MemberAreaResolver::class)->baseUrlForProduct($produto);

        $appUrl = rtrim(config('app.url'), '/');
        $appHost = parse_url($appUrl, PHP_URL_HOST) ?: request()->getHost();
        $dnsTargetHost = $appHost;
        $dnsTargetIp = env('MEMBER_AREA_IP');
        if (empty($dnsTargetIp) && ! empty($dnsTargetHost)) {
            if (filter_var($dnsTargetHost, FILTER_VALIDATE_IP)) {
                $dnsTargetIp = $dnsTargetHost;
            } else {
                $resolved = gethostbyname($dnsTargetHost);
                $resolvedIp = ($resolved !== $dnsTargetHost) ? $resolved : null;
                $dnsTargetIp = $resolvedIp && ! $this->isCloudflareIp($resolvedIp) ? $resolvedIp : null;
            }
        }

        $memberAreaConfigForFront = $produto->member_area_config;
        if (isset($memberAreaConfigForFront['pwa'])) {
            unset($memberAreaConfigForFront['pwa']['vapid_private']);
        }

        $produtoPayload = [
            'id' => $produto->id,
            'name' => $produto->name,
            'checkout_slug' => $produto->checkout_slug,
            'type' => $produto->type,
            'member_area_config' => $memberAreaConfigForFront,
            'member_area_url' => $memberAreaUrl,
            'member_area_domain' => $produto->memberAreaDomain ? [
                'type' => $this->memberAreaDomainTypeForFront($produto->memberAreaDomain),
                'value' => $this->memberAreaDomainValueForFront($produto->memberAreaDomain, $produto),
            ] : null,
            'sections' => $produto->memberSections->map(fn (MemberSection $s) => [
                'id' => $s->id,
                'title' => $s->title,
                'position' => $s->position,
                'cover_mode' => $s->cover_mode ?? 'vertical',
                'section_type' => $s->section_type ?? 'courses',
                'modules' => $s->modules->map(function (MemberModule $m) {
                    $base = [
                        'id' => $m->id,
                        'title' => $m->title,
                        'position' => $m->position,
                        'thumbnail' => $m->thumbnail,
                        'show_title_on_cover' => $m->show_title_on_cover ?? true,
                        'release_after_days' => $m->release_after_days,
                        'release_at_date' => $m->release_at_date?->format('Y-m-d'),
                        'lessons' => $m->lessons->map(fn (MemberLesson $l) => [
                            'id' => $l->id,
                            'title' => $l->title,
                            'position' => $l->position,
                            'type' => $l->type,
                            'content_url' => $l->content_url,
                            'link_title' => $l->link_title,
                            'content_files' => $l->content_files,
                            'release_after_days' => $l->release_after_days,
                            'release_at_date' => $l->release_at_date?->format('Y-m-d'),
                            'content_text' => \App\Support\HtmlSanitizer::sanitize($l->content_text),
                            'duration_seconds' => $l->duration_seconds,
                            'is_free' => $l->is_free,
                            'watermark_enabled' => (bool) ($l->watermark_enabled ?? false),
                        ])->values()->all(),
                    ];
                    $extra = array_filter([
                        'related_product_id' => $m->related_product_id,
                        'access_type' => $m->access_type,
                        'external_url' => $m->external_url,
                        'related_product' => $m->relatedProduct ? [
                            'id' => $m->relatedProduct->id,
                            'name' => $m->relatedProduct->name,
                            'image_url' => $m->relatedProduct->image ? app(StorageService::class)->url($m->relatedProduct->image) : null,
                        ] : null,
                    ]);
                    return array_merge($base, $extra);
                })->values()->all(),
            ])->values()->all(),
            'internal_products' => $produto->memberInternalProducts->map(fn (MemberInternalProduct $ip) => [
                'id' => $ip->id,
                'related_product_id' => $ip->related_product_id,
                'position' => $ip->position,
                'related_product' => $ip->relatedProduct ? [
                    'id' => $ip->relatedProduct->id,
                    'name' => $ip->relatedProduct->name,
                    'image_url' => $ip->relatedProduct->image ? app(StorageService::class)->url($ip->relatedProduct->image) : null,
                ] : null,
            ])->values()->all(),
            'turmas' => $produto->memberTurmas->map(fn (MemberTurma $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'description' => $t->description,
                'start_date' => $t->start_date?->format('Y-m-d'),
                'end_date' => $t->end_date?->format('Y-m-d'),
                'position' => $t->position,
                'users_count' => $t->users()->count(),
                'users' => $t->users->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])->values()->all(),
            ])->values()->all(),
            'product_users' => $produto->users()->select('users.id', 'users.name', 'users.email')->orderBy('users.name')->get()->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])->values()->all(),
            'community_pages' => $produto->memberCommunityPages->map(fn (MemberCommunityPage $p) => [
                'id' => $p->id,
                'title' => $p->title,
                'icon' => $p->icon,
                'slug' => $p->slug,
                'banner' => $p->banner,
                'banner_url' => $p->banner ? app(StorageService::class)->url($p->banner) : null,
                'position' => $p->position,
                'is_public_posting' => $p->is_public_posting,
                'is_default' => (bool) ($p->is_default ?? false),
            ])->values()->all(),
            'push_subscribers_count' => MemberPushSubscription::where('product_id', $produto->id)->count(),
            'comments' => MemberComment::forProduct($produto->id)
                ->with(['user:id,name,email', 'lesson:id,title', 'reviewer:id,name'])
                ->latest()
                ->limit(100)
                ->get()
                ->map(fn (MemberComment $c) => [
                    'id' => $c->id,
                    'content' => $c->content,
                    'status' => $c->status,
                    'created_at' => $c->created_at->format('d/m/Y H:i'),
                    'user' => $c->user ? ['id' => $c->user->id, 'name' => $c->user->name, 'email' => $c->user->email] : null,
                    'lesson' => $c->lesson ? ['id' => $c->lesson->id, 'title' => $c->lesson->title] : null,
                    'reviewer' => $c->reviewer ? ['id' => $c->reviewer->id, 'name' => $c->reviewer->name] : null,
                ])
                ->values()
                ->all(),
        ];

        $tenant_products = Product::forTenant($produto->tenant_id)
            ->where('id', '!=', $produto->id)
            ->orderBy('name')
            ->get(['id', 'name', 'image'])
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'image_url' => $p->image ? app(StorageService::class)->url($p->image) : null,
            ])->values()->all();

        return view('member-builder', [
            'produto' => $produtoPayload,
            'tenant_products' => $tenant_products,
            'app_url' => rtrim(config('app.url'), '/'),
            'dns_target_host' => $dnsTargetHost,
            'dns_target_ip' => $dnsTargetIp,
        ]);
    }

    public function updateConfig(Request $request, Product $produto): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($produto->type !== Product::TYPE_AREA_MEMBROS) {
            abort(403);
        }

        $validated = $request->validate([
            'member_area_config' => ['required', 'array'],
            'member_area_config.login.password_mode' => ['nullable', 'string', 'in:auto,default'],
            'member_area_config.login.default_password' => ['nullable', 'string', 'max:255'],
            'member_area_config.login.login_without_password' => ['nullable', 'boolean'],
            'domain_type' => ['nullable', 'string', 'in:path,custom'],
            'domain_value' => ['nullable', 'string', 'max:255'],
        ]);

        $domainType = $request->input('domain_type'); // ler direto para não perder quando enviado pelo front
        $domainValue = $request->input('domain_value');
        if ($domainType !== null && $domainType !== '') {
            $domainType = (string) $domainType;
        } else {
            $domainType = $validated['domain_type'] ?? null;
            $domainValue = $validated['domain_value'] ?? null;
        }

        if ($domainType === 'path' && $domainValue !== null) {
            $pathSegment = trim((string) $domainValue) !== '' ? trim($domainValue) : $produto->checkout_slug;
            $pathSegment = strtolower($pathSegment);
            if (! preg_match('/^[a-z0-9]{6,16}$/', $pathSegment)) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'O segmento do path deve ter entre 6 e 16 caracteres (apenas letras minúsculas e números).',
                    ], 422);
                }
                return back()->with('error', 'O segmento do path deve ter entre 6 e 16 caracteres (apenas letras minúsculas e números).');
            }
            $conflictProduct = Product::where('checkout_slug', $pathSegment)
                ->where('type', Product::TYPE_AREA_MEMBROS)
                ->where('id', '!=', $produto->id)
                ->exists();
            $conflictDomain = MemberAreaDomain::where('type', MemberAreaDomain::TYPE_PATH)
                ->where('value', $pathSegment)
                ->where('product_id', '!=', $produto->id)
                ->exists();
            if ($conflictProduct || $conflictDomain) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'Este segmento de URL já está em uso por outra área de membros.',
                    ], 422);
                }
                return back()->with('error', 'Este segmento de URL já está em uso por outra área de membros.');
            }
        }

        // Usar input() para garantir o payload completo (validated() em alguns contextos pode devolver só chaves validadas)
        $incoming = $request->input('member_area_config', []);
        if (! is_array($incoming)) {
            $incoming = [];
        }
        // Mesclar config atual com a enviada (preserva vapid_private que não vem do front)
        $config = array_replace_recursive($produto->member_area_config ?? [], $incoming);
        // sidebar.items: substituir por completo (array_replace_recursive mantém índices antigos ao remover itens)
        if (isset($incoming['sidebar']['items']) && is_array($incoming['sidebar']['items'])) {
            $config['sidebar'] = $config['sidebar'] ?? [];
            $config['sidebar']['items'] = array_values($incoming['sidebar']['items']);
        }
        // gamification.achievements: substituir por completo
        if (isset($incoming['gamification']['achievements']) && is_array($incoming['gamification']['achievements'])) {
            $config['gamification'] = $config['gamification'] ?? ['enabled' => false, 'achievements' => []];
            $config['gamification']['achievements'] = array_values($incoming['gamification']['achievements']);
        }
        $pwa = $config['pwa'] ?? [];
        $vapidWarning = null;
        if (! empty($pwa['push_enabled'])) {
            if (empty($pwa['vapid_public'] ?? null) || empty($pwa['vapid_private'] ?? null)) {
                try {
                    $keys = VAPID::createVapidKeys();
                    $config['pwa']['vapid_public'] = $keys['publicKey'];
                    $config['pwa']['vapid_private'] = $keys['privateKey'];
                } catch (\Throwable $e) {
                    // Fallback: no Windows/XAMPP o openssl_pkey_new pode falhar por falta de openssl.cnf; gerar via CLI
                    $keys = $this->createVapidKeysViaOpensslCli();
                    if ($keys !== null) {
                        $config['pwa']['vapid_public'] = $keys['publicKey'];
                        $config['pwa']['vapid_private'] = $keys['privateKey'];
                    } else {
                        $existing = $produto->member_area_config['pwa'] ?? [];
                        $config['pwa']['vapid_public'] = $config['pwa']['vapid_public'] ?? $existing['vapid_public'] ?? null;
                        $config['pwa']['vapid_private'] = $existing['vapid_private'] ?? null;
                        $vapidWarning = 'Configuração salva, mas não foi possível gerar chaves para notificações push. Verifique se a extensão OpenSSL do PHP está habilitada e suporta chaves EC (P-256), ou se o binário openssl está no PATH.';
                    }
                }
            } else {
                $config['pwa']['vapid_private'] = $produto->member_area_config['pwa']['vapid_private'] ?? $config['pwa']['vapid_private'];
            }
        }
        $produto->update(['member_area_config' => $config]);

        \Illuminate\Support\Facades\Log::info('MemberBuilder updateConfig', [
            'product_id' => $produto->id,
            'updated' => true,
        ]);

        if ($domainType !== null) {
            $value = $domainType === 'path'
                ? (trim((string) ($domainValue ?? '')) !== '' ? strtolower(trim($domainValue)) : $produto->checkout_slug)
                : $domainValue;
            if ($domainType === 'path' && ($value === null || $value === '')) {
                $value = $produto->checkout_slug;
            }
            if ($domainType === 'custom' && $value !== null && $value !== '') {
                $value = MemberAreaDomain::normalizeCustomHost($value);
                if ($value === null || ! filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                    if ($request->expectsJson()) {
                        return response()->json([
                            'message' => 'Informe um domínio ou subdomínio válido (apenas o host, sem https:// e sem /path).',
                        ], 422);
                    }
                    return back()->with('error', 'Informe um domínio ou subdomínio válido (apenas o host, sem https:// e sem /path).');
                }
                $conflictCustom = MemberAreaDomain::where('type', MemberAreaDomain::TYPE_CUSTOM)
                    ->where('value', $value)
                    ->where('product_id', '!=', $produto->id)
                    ->exists();
                if ($conflictCustom) {
                    if ($request->expectsJson()) {
                        return response()->json([
                            'message' => 'Este domínio já está vinculado a outra área de membros.',
                        ], 422);
                    }
                    return back()->with('error', 'Este domínio já está vinculado a outra área de membros.');
                }
            }
            MemberAreaDomain::updateOrCreate(
                ['product_id' => $produto->id],
                ['type' => $domainType, 'value' => $value]
            );
        }

        if ($request->expectsJson()) {
            $json = ['message' => 'Configuração salva.'];
            if ($vapidWarning !== null) {
                $json['warning'] = $vapidWarning;
            }
            return response()->json($json);
        }

        return back()->with('success', 'Configuração salva.');
    }

    public function uploadImage(Request $request, Product $produto): JsonResponse
    {
        $this->authorizeProduct($produto);
        $request->validate([
            'file' => ['required', 'file', 'image', 'max:4096'],
        ], [
            'file.required' => 'Nenhum arquivo enviado.',
            'file.image' => 'O arquivo deve ser uma imagem (JPG, PNG, GIF ou WebP).',
            'file.max' => 'A imagem deve ter no máximo 4 MB.',
        ]);
        $storage = app(StorageService::class);
        $path = $storage->putFile('member-area/' . $produto->id, $request->file('file'));
        return response()->json(['url' => $storage->url($path), 'path' => $path]);
    }

    public function uploadPdf(Request $request, Product $produto): JsonResponse
    {
        $this->authorizeProduct($produto);
        $request->validate([
            'file' => ['required', 'file', 'mimetypes:application/pdf', 'max:20480'],
        ], [
            'file.required' => 'Nenhum arquivo enviado.',
            'file.mimetypes' => 'O arquivo deve ser um material em formato PDF.',
            'file.max' => 'O material deve ter no máximo 20 MB.',
        ]);
        $file = $request->file('file');
        $name = $file->getClientOriginalName();
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($name, PATHINFO_FILENAME)) . '.pdf';
        $storage = app(StorageService::class);
        $path = $storage->putFileAs('member-area/' . $produto->id, $file, $safeName);
        return response()->json(['url' => $storage->url($path), 'path' => $path]);
    }

    public function uploadBadge(Request $request, Product $produto): JsonResponse
    {
        $this->authorizeProduct($produto);
        if ($produto->type !== Product::TYPE_AREA_MEMBROS) {
            abort(403);
        }
        $request->validate([
            'file' => ['required', 'file', 'image', 'max:2048'],
        ], [
            'file.required' => 'Nenhum arquivo enviado.',
            'file.image' => 'O arquivo deve ser uma imagem (JPG, PNG, GIF ou WebP).',
            'file.max' => 'A imagem da badge deve ter no máximo 2 MB.',
        ]);
        $storage = app(StorageService::class);
        $path = $storage->putFile('member-area-gamification/' . $produto->id . '/badges', $request->file('file'));
        return response()->json(['url' => $storage->url($path), 'path' => $path]);
    }

    // Sections
    public function storeSection(Request $request, Product $produto): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'cover_mode' => ['nullable', 'string', 'in:vertical,horizontal'],
            'section_type' => ['nullable', 'string', 'in:courses,products,external_links'],
        ]);
        $max = MemberSection::where('product_id', $produto->id)->max('position') ?? 0;
        MemberSection::create([
            'product_id' => $produto->id,
            'title' => $validated['title'],
            'position' => $max + 1,
            'cover_mode' => $validated['cover_mode'] ?? 'vertical',
            'section_type' => $validated['section_type'] ?? 'courses',
        ]);
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Seção criada.']);
        }
        return back()->with('success', 'Seção criada.');
    }

    public function updateSection(Request $request, Product $produto, MemberSection $section): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($section->product_id !== $produto->id) {
            abort(404);
        }
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'cover_mode' => ['sometimes', 'string', 'in:vertical,horizontal'],
            'section_type' => ['sometimes', 'string', 'in:courses,products,external_links'],
        ]);
        $section->update($validated);
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Seção atualizada.']);
        }
        return back()->with('success', 'Seção atualizada.');
    }

    public function destroySection(Request $request, Product $produto, MemberSection $section): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($section->product_id !== $produto->id) {
            abort(404);
        }
        $section->delete();
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Seção removida.']);
        }
        return back()->with('success', 'Seção removida.');
    }

    // Modules
    public function storeModule(Request $request, Product $produto, MemberSection $section): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($section->product_id !== $produto->id) {
            abort(404);
        }
        $sectionType = $section->section_type ?? 'courses';

        if ($sectionType === 'courses') {
            $validated = $request->validate([
                'title' => ['required', 'string', 'max:255'],
                'show_title_on_cover' => ['nullable', 'boolean'],
                'release_after_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
                'release_at_date' => ['nullable', 'date_format:Y-m-d'],
            ]);
            if (! empty($validated['release_at_date'] ?? null)) {
                $validated['release_after_days'] = null;
            } elseif (empty($validated['release_after_days'] ?? null)) {
                $validated['release_after_days'] = null;
                $validated['release_at_date'] = null;
            } else {
                $validated['release_at_date'] = null;
            }
            $max = MemberModule::where('member_section_id', $section->id)->max('position') ?? 0;
            $module = MemberModule::create([
                'member_section_id' => $section->id,
                'product_id' => $produto->id,
                'title' => $validated['title'],
                'position' => $max + 1,
                'show_title_on_cover' => $validated['show_title_on_cover'] ?? true,
                'release_after_days' => $validated['release_after_days'] ?? null,
                'release_at_date' => $validated['release_at_date'] ?? null,
            ]);
        } elseif ($sectionType === 'products') {
            $validated = $request->validate([
                'title' => ['required', 'string', 'max:255'],
                'related_product_id' => ['required', 'exists:products,id'],
                'access_type' => ['required', 'string', 'in:paid,free'],
                'thumbnail' => ['nullable', 'string', 'max:500'],
                'show_title_on_cover' => ['nullable', 'boolean'],
            ]);
            $related = Product::find($validated['related_product_id']);
            if ($related->tenant_id !== $produto->tenant_id) {
                abort(403);
            }
            if ((int) $validated['related_product_id'] === $produto->id) {
                if ($request->expectsJson()) {
                    return response()->json(['message' => 'Não é possível referenciar o próprio produto.'], 422);
                }
                return back()->with('error', 'Não é possível referenciar o próprio produto.');
            }
            $max = MemberModule::where('member_section_id', $section->id)->max('position') ?? 0;
            $module = MemberModule::create([
                'member_section_id' => $section->id,
                'product_id' => $produto->id,
                'title' => $validated['title'],
                'position' => $max + 1,
                'related_product_id' => $validated['related_product_id'],
                'access_type' => $validated['access_type'],
                'thumbnail' => $validated['thumbnail'] ?? null,
                'show_title_on_cover' => $validated['show_title_on_cover'] ?? true,
            ]);
        } else {
            // external_links
            $validated = $request->validate([
                'title' => ['required', 'string', 'max:255'],
                'external_url' => ['required', 'url', 'max:500'],
                'thumbnail' => ['nullable', 'string', 'max:500'],
                'show_title_on_cover' => ['nullable', 'boolean'],
            ]);
            $max = MemberModule::where('member_section_id', $section->id)->max('position') ?? 0;
            $module = MemberModule::create([
                'member_section_id' => $section->id,
                'product_id' => $produto->id,
                'title' => $validated['title'],
                'position' => $max + 1,
                'external_url' => $validated['external_url'],
                'thumbnail' => $validated['thumbnail'] ?? null,
                'show_title_on_cover' => $validated['show_title_on_cover'] ?? true,
            ]);
        }

        if ($request->expectsJson()) {
            $payload = [
                'id' => $module->id,
                'title' => $module->title,
                'position' => $module->position,
                'thumbnail' => $module->thumbnail,
                'show_title_on_cover' => $module->show_title_on_cover ?? true,
                'release_after_days' => $module->release_after_days,
                'release_at_date' => $module->release_at_date?->format('Y-m-d'),
                'lessons' => $module->relationLoaded('lessons') ? $module->lessons->map(fn (MemberLesson $l) => [
                    'id' => $l->id,
                    'title' => $l->title,
                    'position' => $l->position,
                    'type' => $l->type,
                    'content_url' => $l->content_url,
                    'content_text' => \App\Support\HtmlSanitizer::sanitize($l->content_text),
                    'duration_seconds' => $l->duration_seconds,
                    'is_free' => $l->is_free,
                    'watermark_enabled' => (bool) ($l->watermark_enabled ?? false),
                ])->values()->all() : [],
            ];
            if ($module->related_product_id) {
                $module->load('relatedProduct');
                $payload['related_product_id'] = $module->related_product_id;
                $payload['access_type'] = $module->access_type;
                $payload['related_product'] = $module->relatedProduct ? [
                    'id' => $module->relatedProduct->id,
                    'name' => $module->relatedProduct->name,
                    'image_url' => $module->relatedProduct->image ? app(StorageService::class)->url($module->relatedProduct->image) : null,
                ] : null;
            }
            if ($module->external_url) {
                $payload['external_url'] = $module->external_url;
            }
            return response()->json(['message' => 'Módulo criado.', 'module' => $payload]);
        }
        return back()->with('success', 'Módulo criado.');
    }

    public function updateModule(Request $request, Product $produto, MemberModule $module): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($module->product_id !== $produto->id) {
            abort(404);
        }
        $section = $module->section;
        $sectionType = $section->section_type ?? 'courses';

        if ($sectionType === 'courses') {
            $validated = $request->validate([
                'title' => ['sometimes', 'string', 'max:255'],
                'position' => ['sometimes', 'integer', 'min:0'],
                'thumbnail' => ['nullable', 'string', 'max:500'],
                'show_title_on_cover' => ['sometimes', 'boolean'],
                'release_after_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
                'release_at_date' => ['nullable', 'date_format:Y-m-d'],
            ]);
            if (array_key_exists('release_at_date', $validated) || array_key_exists('release_after_days', $validated)) {
                $date = $validated['release_at_date'] ?? null;
                $days = $validated['release_after_days'] ?? null;
                if (! empty($date)) {
                    $validated['release_at_date'] = $date;
                    $validated['release_after_days'] = null;
                } elseif (! empty($days)) {
                    $validated['release_after_days'] = (int) $days;
                    $validated['release_at_date'] = null;
                } else {
                    $validated['release_after_days'] = null;
                    $validated['release_at_date'] = null;
                }
            }
        } elseif ($sectionType === 'products') {
            $validated = $request->validate([
                'title' => ['sometimes', 'string', 'max:255'],
                'position' => ['sometimes', 'integer', 'min:0'],
                'related_product_id' => ['sometimes', 'exists:products,id'],
                'access_type' => ['sometimes', 'string', 'in:paid,free'],
                'thumbnail' => ['nullable', 'string', 'max:500'],
                'show_title_on_cover' => ['sometimes', 'boolean'],
            ]);
            if (isset($validated['related_product_id'])) {
                $related = Product::find($validated['related_product_id']);
                if ($related->tenant_id !== $produto->tenant_id) {
                    abort(403);
                }
                if ((int) $validated['related_product_id'] === $produto->id) {
                    if ($request->expectsJson()) {
                        return response()->json(['message' => 'Não é possível referenciar o próprio produto.'], 422);
                    }
                    return back()->with('error', 'Não é possível referenciar o próprio produto.');
                }
            }
        } else {
            $validated = $request->validate([
                'title' => ['sometimes', 'string', 'max:255'],
                'position' => ['sometimes', 'integer', 'min:0'],
                'external_url' => ['sometimes', 'url', 'max:500'],
                'thumbnail' => ['nullable', 'string', 'max:500'],
                'show_title_on_cover' => ['sometimes', 'boolean'],
            ]);
        }
        $module->update($validated);
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Módulo atualizado.']);
        }
        return back()->with('success', 'Módulo atualizado.');
    }

    public function destroyModule(Request $request, Product $produto, MemberModule $module): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($module->product_id !== $produto->id) {
            abort(404);
        }
        $module->delete();
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Módulo removido.']);
        }
        return back()->with('success', 'Módulo removido.');
    }

    // Lessons
    public function storeLesson(Request $request, Product $produto, MemberModule $module): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($module->product_id !== $produto->id) {
            abort(404);
        }
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:video,link,pdf,text'],
            'content_url' => ['nullable', 'string', 'max:2000'],
            'link_title' => ['nullable', 'string', 'max:255'],
            'content_files' => ['nullable', 'array', 'max:30'],
            'content_files.*.url' => ['nullable', 'string', 'url', 'max:2000'],
            'content_files.*.name' => ['nullable', 'string', 'max:255'],
            'release_after_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'release_at_date' => ['nullable', 'date_format:Y-m-d'],
            'content_text' => ['nullable', 'string'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'is_free' => ['boolean'],
            'watermark_enabled' => ['boolean'],
        ]);
        if (! empty($validated['release_at_date'] ?? null)) {
            $validated['release_after_days'] = null;
        } elseif (empty($validated['release_after_days'] ?? null)) {
            $validated['release_after_days'] = null;
            $validated['release_at_date'] = null;
        } else {
            $validated['release_at_date'] = null;
        }
        $contentFiles = $this->normalizeLessonContentFiles($request->input('content_files'));
        if (($validated['type'] ?? null) === 'pdf' && empty($validated['content_url']) && count($contentFiles) > 0) {
            $validated['content_url'] = $contentFiles[0]['url'];
        }
        $max = MemberLesson::where('member_module_id', $module->id)->max('position') ?? 0;
        MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $produto->id,
            'title' => $validated['title'],
            'position' => $max + 1,
            'type' => $validated['type'],
            'content_url' => $validated['content_url'] ?? null,
            'link_title' => $validated['link_title'] ?? null,
            'content_files' => $validated['type'] === 'pdf' ? ($contentFiles !== [] ? $contentFiles : null) : null,
            'release_after_days' => $validated['release_after_days'] ?? null,
            'release_at_date' => $validated['release_at_date'] ?? null,
            'content_text' => $validated['content_text'] ?? null,
            'duration_seconds' => $validated['duration_seconds'] ?? null,
            'is_free' => $request->boolean('is_free', false),
            'watermark_enabled' => $request->boolean('watermark_enabled', false),
        ]);
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Aula criada.']);
        }
        return back()->with('success', 'Aula criada.');
    }

    public function updateLesson(Request $request, Product $produto, MemberLesson $lesson): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($lesson->product_id !== $produto->id) {
            abort(404);
        }
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'type' => ['sometimes', 'string', 'in:video,link,pdf,text'],
            'content_url' => ['nullable', 'string', 'max:2000'],
            'link_title' => ['nullable', 'string', 'max:255'],
            'content_files' => ['nullable', 'array', 'max:30'],
            'content_files.*.url' => ['nullable', 'string', 'url', 'max:2000'],
            'content_files.*.name' => ['nullable', 'string', 'max:255'],
            'release_after_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'release_at_date' => ['nullable', 'date_format:Y-m-d'],
            'content_text' => ['nullable', 'string'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'is_free' => ['boolean'],
            'watermark_enabled' => ['boolean'],
        ]);
        if (isset($validated['is_free'])) {
            $validated['is_free'] = $request->boolean('is_free');
        }
        if (array_key_exists('watermark_enabled', $validated)) {
            $validated['watermark_enabled'] = $request->boolean('watermark_enabled');
        }
        if (array_key_exists('release_at_date', $validated) || array_key_exists('release_after_days', $validated)) {
            $date = $validated['release_at_date'] ?? null;
            $days = $validated['release_after_days'] ?? null;
            if (! empty($date)) {
                $validated['release_at_date'] = $date;
                $validated['release_after_days'] = null;
            } elseif (! empty($days)) {
                $validated['release_after_days'] = (int) $days;
                $validated['release_at_date'] = null;
            } else {
                $validated['release_after_days'] = null;
                $validated['release_at_date'] = null;
            }
        }
        $type = $validated['type'] ?? $lesson->type;
        $contentFiles = $this->normalizeLessonContentFiles($request->input('content_files'));
        if ($type === 'pdf') {
            if (count($contentFiles) > 0) {
                $validated['content_files'] = $contentFiles;
                if (empty($validated['content_url'])) {
                    $validated['content_url'] = $contentFiles[0]['url'];
                }
            } elseif (array_key_exists('content_files', $validated)) {
                $validated['content_files'] = null;
            }
        } else {
            if (array_key_exists('content_files', $validated)) {
                $validated['content_files'] = null;
            }
        }
        $lesson->update($validated);
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Aula atualizada.']);
        }
        return back()->with('success', 'Aula atualizada.');
    }

    public function destroyLesson(Request $request, Product $produto, MemberLesson $lesson): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($lesson->product_id !== $produto->id) {
            abort(404);
        }
        $lesson->delete();
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Aula removida.']);
        }
        return back()->with('success', 'Aula removida.');
    }

    // Internal products
    public function storeInternalProduct(Request $request, Product $produto): RedirectResponse
    {
        $this->authorizeProduct($produto);
        $validated = $request->validate(['related_product_id' => ['required', 'exists:products,id']]);
        if ((int) $validated['related_product_id'] === $produto->id) {
            return back()->with('error', 'Não é possível adicionar o próprio produto.');
        }
        $related = Product::find($validated['related_product_id']);
        if ($related->tenant_id !== $produto->tenant_id) {
            abort(403);
        }
        $max = MemberInternalProduct::where('product_id', $produto->id)->max('position') ?? 0;
        MemberInternalProduct::firstOrCreate(
            ['product_id' => $produto->id, 'related_product_id' => $validated['related_product_id']],
            ['position' => $max + 1]
        );
        return back()->with('success', 'Produto adicionado à loja interna.');
    }

    public function destroyInternalProduct(Product $produto, MemberInternalProduct $internalProduct): RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($internalProduct->product_id !== $produto->id) {
            abort(404);
        }
        $internalProduct->delete();
        return back()->with('success', 'Produto removido da loja interna.');
    }

    // Turmas
    public function storeTurma(Request $request, Product $produto): RedirectResponse
    {
        $this->authorizeProduct($produto);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);
        $max = MemberTurma::where('product_id', $produto->id)->max('position') ?? 0;
        MemberTurma::create([
            'product_id' => $produto->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
            'position' => $max + 1,
        ]);
        return back()->with('success', 'Turma criada.');
    }

    public function updateTurma(Request $request, Product $produto, MemberTurma $turma): RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($turma->product_id !== $produto->id) {
            abort(404);
        }
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);
        $turma->update($validated);
        return back()->with('success', 'Turma atualizada.');
    }

    public function destroyTurma(Product $produto, MemberTurma $turma): RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($turma->product_id !== $produto->id) {
            abort(404);
        }
        $turma->delete();
        return back()->with('success', 'Turma removida.');
    }

    public function attachTurmaUser(Request $request, Product $produto, MemberTurma $turma): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($turma->product_id !== $produto->id) {
            abort(404);
        }
        $validated = $request->validate(['user_id' => ['required', 'exists:users,id']]);
        $turma->users()->syncWithoutDetaching([$validated['user_id']]);
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Aluno adicionado à turma.']);
        }
        return back()->with('success', 'Aluno adicionado à turma.');
    }

    public function detachTurmaUser(Product $produto, MemberTurma $turma, int $userId): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($turma->product_id !== $produto->id) {
            abort(404);
        }
        $turma->users()->detach($userId);
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Aluno removido da turma.']);
        }
        return back()->with('success', 'Aluno removido da turma.');
    }

    /**
     * Criar novo aluno (nome, email, senha), dar acesso ao produto e opcionalmente adicionar à turma.
     */
    public function storeNewAluno(Request $request, Product $produto): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6', 'max:255'],
            'turma_id' => ['nullable', 'integer', 'exists:member_turmas,id'],
        ]);
        $turmaId = $validated['turma_id'] ?? null;
        if ($turmaId) {
            $turma = MemberTurma::find($turmaId);
            if (! $turma || $turma->product_id !== $produto->id) {
                if ($request->expectsJson()) {
                    return response()->json(['message' => 'Turma inválida.'], 422);
                }
                return back()->with('error', 'Turma inválida.');
            }
        }
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => User::ROLE_ALUNO,
            'tenant_id' => $produto->tenant_id,
        ]);
        $produto->users()->attach($user->id);
        if ($turmaId) {
            MemberTurma::find($turmaId)->users()->syncWithoutDetaching([$user->id]);
        }
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Aluno criado e adicionado.', 'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]]);
        }
        return back()->with('success', 'Aluno criado e adicionado.');
    }

    // Comments
    public function commentsIndex(Request $request, Product $produto): Response
    {
        $this->authorizeProduct($produto);
        $status = $request->query('status', 'pending');
        $comments = MemberComment::forProduct($produto->id)
            ->when($status !== 'all', fn ($q) => $q->status($status))
            ->with(['user:id,name,email', 'lesson:id,title', 'reviewer:id,name'])
            ->latest()
            ->paginate(20);
        return Inertia::render('Produtos/MemberBuilder/Comments', [
            'produto' => ['id' => $produto->id, 'name' => $produto->name],
            'comments' => $comments,
            'status_filter' => $status,
        ]);
    }

    public function updateComment(Request $request, Product $produto, MemberComment $comment): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($comment->product_id !== $produto->id) {
            abort(404);
        }
        $validated = $request->validate(['status' => ['required', 'string', 'in:approved,rejected']]);
        if ($validated['status'] === 'approved') {
            $this->commentService->approve($comment, $request->user());
            $comment->user && $this->gamificationService->checkAndUnlock($produto, $comment->user);
        } else {
            $this->commentService->reject($comment, $request->user());
        }
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Comentário atualizado.']);
        }
        return back()->with('success', 'Comentário atualizado.');
    }

    // Community pages
    public function storeCommunityPage(Request $request, Product $produto): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:50'],
            'slug' => ['nullable', 'string', 'max:255'],
            'banner' => ['nullable', 'string', 'max:500'],
            'is_public_posting' => ['boolean'],
            'is_default' => ['boolean'],
        ]);
        if ($request->boolean('is_default')) {
            MemberCommunityPage::where('product_id', $produto->id)->update(['is_default' => false]);
        }
        $max = MemberCommunityPage::where('product_id', $produto->id)->max('position') ?? 0;
        MemberCommunityPage::create([
            'product_id' => $produto->id,
            'title' => $validated['title'],
            'icon' => $validated['icon'] ?? null,
            'slug' => $validated['slug'] ?? null,
            'banner' => $validated['banner'] ?? null,
            'position' => $max + 1,
            'is_public_posting' => $request->boolean('is_public_posting', true),
            'is_default' => $request->boolean('is_default', false),
        ]);
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Página da comunidade criada.',
                'community_pages' => $this->buildCommunityPagesPayload($produto),
            ]);
        }
        return back()->with('success', 'Página da comunidade criada.');
    }

    public function updateCommunityPage(Request $request, Product $produto, MemberCommunityPage $page): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($page->product_id !== $produto->id) {
            abort(404);
        }
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:50'],
            'slug' => ['nullable', 'string', 'max:255'],
            'banner' => ['nullable', 'string', 'max:500'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'is_public_posting' => ['boolean'],
            'is_default' => ['boolean'],
        ]);
        if ($request->boolean('is_default')) {
            MemberCommunityPage::where('product_id', $produto->id)->where('id', '!=', $page->id)->update(['is_default' => false]);
        }
        $page->update($validated);
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Página atualizada.',
                'community_pages' => $this->buildCommunityPagesPayload($produto),
            ]);
        }
        return back()->with('success', 'Página atualizada.');
    }

    public function destroyCommunityPage(Request $request, Product $produto, MemberCommunityPage $page): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($produto);
        if ($page->product_id !== $produto->id) {
            abort(404);
        }
        $page->delete();
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Página removida.',
                'community_pages' => $this->buildCommunityPagesPayload($produto),
            ]);
        }
        return back()->with('success', 'Página removida.');
    }

    /**
     * @return array<int, array{id: int, title: string, icon: string|null, slug: string|null, banner: string|null, banner_url: string|null, position: int, is_public_posting: bool}>
     */
    private function buildCommunityPagesPayload(Product $produto): array
    {
        $produto->load('memberCommunityPages');
        return $produto->memberCommunityPages->map(fn (MemberCommunityPage $p) => [
            'id' => $p->id,
            'title' => $p->title,
            'icon' => $p->icon,
            'slug' => $p->slug,
            'banner' => $p->banner,
            'banner_url' => $p->banner ? app(StorageService::class)->url($p->banner) : null,
            'position' => $p->position,
            'is_public_posting' => $p->is_public_posting,
            'is_default' => (bool) ($p->is_default ?? false),
        ])->values()->all();
    }

    public function sendPushNotification(Request $request, Product $produto): JsonResponse
    {
        $this->authorizeProduct($produto);
        if ($produto->type !== Product::TYPE_AREA_MEMBROS) {
            abort(403);
        }
        $config = $produto->member_area_config;
        $pwa = $config['pwa'] ?? [];
        if (! ((bool) ($pwa['push_enabled'] ?? false))) {
            return response()->json(['message' => 'Notificações push não estão habilitadas para esta área.'], 403);
        }
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'body' => ['required', 'string', 'max:200'],
        ]);
        $vapidPublic = $produto->member_area_config['pwa']['vapid_public'] ?? null;
        $vapidPrivate = $produto->member_area_config['pwa']['vapid_private'] ?? null;
        if (! $vapidPublic || ! $vapidPrivate) {
            return response()->json(['message' => 'Gere as chaves VAPID: ative as notificações push e salve a configuração.'], 400);
        }
        $subscriptions = MemberPushSubscription::where('product_id', $produto->id)->get();
        $sent = 0;
        $subject = 'mailto:' . (config('mail.from.address') ?: 'noreply@' . parse_url(config('app.url'), PHP_URL_HOST));
        $auth = [
            'VAPID' => [
                'subject' => $subject,
                'publicKey' => $vapidPublic,
                'privateKey' => $vapidPrivate,
            ],
        ];
        $payload = json_encode([
            'title' => $validated['title'],
            'body' => $validated['body'],
        ]);
        $userIdsSent = [];
        try {
            $webPush = new WebPush($auth);
            foreach ($subscriptions as $sub) {
                $keys = $sub->keys ?? [];
                $authKey = trim((string) ($keys['auth'] ?? ''));
                $p256dh = trim((string) ($keys['p256dh'] ?? ''));
                if (! $sub->endpoint || ! $authKey || ! $p256dh) {
                    continue;
                }
                $subscription = Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'keys' => [
                        'auth' => $this->normalizeBase64KeyForPush($authKey),
                        'p256dh' => $this->normalizeBase64KeyForPush($p256dh),
                    ],
                ]);
                $report = $webPush->sendOneNotification($subscription, $payload);
                if ($report->isSuccess()) {
                    $sent++;
                    if ($sub->user_id) {
                        $userIdsSent[$sub->user_id] = true;
                    }
                }
            }
            foreach (array_keys($userIdsSent) as $userId) {
                MemberNotification::create([
                    'product_id' => $produto->id,
                    'user_id' => $userId,
                    'type' => 'push',
                    'title' => $validated['title'],
                    'body' => $validated['body'],
                ]);
            }
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Erro ao enviar: ' . $e->getMessage()], 500);
        }
        return response()->json([
            'success' => true,
            'sent' => $sent,
            'message' => $sent === 0
                ? 'Nenhum destinatário inscrito ou falha no envio.'
                : "Notificação enviada para {$sent} destinatário(s).",
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

    private function authorizeProduct(Product $produto): void
    {
        $tenantId = auth()->user()->tenant_id;
        if ($produto->tenant_id !== $tenantId) {
            abort(403);
        }
    }

    private function isCloudflareIp(string $ip): bool
    {
        $cidrs = [
            '173.245.48.0/20',
            '103.21.244.0/22',
            '103.22.200.0/22',
            '103.31.4.0/22',
            '141.101.64.0/18',
            '108.162.192.0/18',
            '190.93.240.0/20',
            '188.114.96.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
            '162.158.0.0/15',
            '104.16.0.0/13',
            '104.24.0.0/14',
            '172.64.0.0/13',
            '131.0.72.0/22',
        ];

        foreach ($cidrs as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        if (! is_string($subnet) || ! is_string($bits)) {
            return false;
        }
        if (! filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $bitsInt = (int) $bits;
        if ($bitsInt < 0 || $bitsInt > 32) {
            return false;
        }

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = $bitsInt === 0 ? 0 : (-1 << (32 - $bitsInt));

        return (($ipLong & $mask) === ($subnetLong & $mask));
    }

    /** Para o front: exibe só "path" ou "custom". Subdomínio vira custom com valor = host completo. */
    private function memberAreaDomainTypeForFront(MemberAreaDomain $domain): string
    {
        return $domain->type === MemberAreaDomain::TYPE_SUBDOMAIN ? 'custom' : $domain->type;
    }

    /** Para o front: no caso subdomain, valor = slug + "." + subdomain_base (host completo). */
    private function memberAreaDomainValueForFront(MemberAreaDomain $domain, Product $product): string
    {
        if ($domain->type === MemberAreaDomain::TYPE_SUBDOMAIN && config('members.subdomain_enabled')) {
            $base = config('members.subdomain_base', '');
            $slug = $domain->value ?: $product->checkout_slug;
            return $base !== '' ? $slug . '.' . $base : (string) $domain->value;
        }
        return (string) ($domain->value ?? '');
    }

    /**
     * Gera chaves VAPID via binário openssl (CLI). Útil quando openssl_pkey_new falha no PHP (ex.: Windows sem openssl.cnf).
     *
     * @return array{publicKey: string, privateKey: string}|null
     */
    private function createVapidKeysViaOpensslCli(): ?array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'vapid_');
        if ($tmp === false) {
            return null;
        }
        $pemFile = $tmp . '.pem';
        if (! @rename($tmp, $pemFile)) {
            @unlink($tmp);
            return null;
        }
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $cmd = 'openssl ecparam -name prime256v1 -genkey -noout -out ' . escapeshellarg($pemFile) . ' 2>' . $null;
        exec($cmd, $out, $code);
        if ($code !== 0 || ! is_readable($pemFile)) {
            // No Windows, tentar openssl do XAMPP se não estiver no PATH
            if (PHP_OS_FAMILY === 'Windows' && is_file('C:\\xampp\\apache\\bin\\openssl.exe')) {
                $cmd = 'C:\\xampp\\apache\\bin\\openssl.exe ecparam -name prime256v1 -genkey -noout -out ' . escapeshellarg($pemFile) . ' 2>' . $null;
                exec($cmd, $out2, $code);
            }
            if ($code !== 0 || ! is_readable($pemFile)) {
                @unlink($pemFile);
                return null;
            }
        }
        $pem = file_get_contents($pemFile);
        @unlink($pemFile);
        if ($pem === false || $pem === '') {
            return null;
        }
        try {
            $jwkArray = \Jose\Component\KeyManagement\KeyConverter\ECKey::createFromPEM($pem)->toArray();
            $jwk = new \Jose\Component\Core\JWK($jwkArray);
            $binaryPublicKey = hex2bin(\Minishlink\WebPush\Utils::serializePublicKeyFromJWK($jwk));
            if (! $binaryPublicKey || strlen($binaryPublicKey) !== 65) {
                return null;
            }
            $d = \Base64Url\Base64Url::decode($jwk->get('d'));
            $binaryPrivateKey = str_pad($d, 32, "\0", STR_PAD_LEFT);
            if (strlen($binaryPrivateKey) !== 32) {
                return null;
            }
            return [
                'publicKey' => \Base64Url\Base64Url::encode($binaryPublicKey),
                'privateKey' => \Base64Url\Base64Url::encode($binaryPrivateKey),
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
