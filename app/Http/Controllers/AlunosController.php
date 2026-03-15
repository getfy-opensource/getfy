<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\User;
use App\Services\AccessEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class AlunosController extends Controller
{
    private const FILTER_OPTIONS = ['todos', 'novos_30'];

    private function tenantProductIds(?int $tenantId): array
    {
        return Product::forTenant($tenantId)->pluck('id')->toArray();
    }

    private function baseAlunosQuery(?int $tenantId)
    {
        return User::where('role', User::ROLE_ALUNO)
            ->whereHas('products', fn ($q) => $q->forTenant($tenantId));
    }

    public function index(Request $request): Response
    {
        $tenantId = auth()->user()->tenant_id;
        $filter = $request->query('filter', 'todos');
        if (! in_array($filter, self::FILTER_OPTIONS, true)) {
            $filter = 'todos';
        }

        $search = $request->query('q');
        $search = is_string($search) ? trim($search) : '';
        $search = $search !== '' ? $search : null;

        $productIdsFilter = $request->query('product_ids');
        $productIdsFilter = is_array($productIdsFilter)
            ? $productIdsFilter
            : (is_string($productIdsFilter) ? array_filter(explode(',', $productIdsFilter)) : []);

        $tenantProductIds = $this->tenantProductIds($tenantId);
        $baseAlunosQuery = $this->baseAlunosQuery($tenantId);

        if ($filter === 'novos_30') {
            $baseAlunosQuery->whereExists(function ($q) use ($tenantId) {
                $q->select(DB::raw(1))
                    ->from('product_user')
                    ->join('products', 'products.id', '=', 'product_user.product_id')
                    ->whereColumn('product_user.user_id', 'users.id')
                    ->where('product_user.created_at', '>=', now()->subDays(30));
                if ($tenantId === null) {
                    $q->whereNull('products.tenant_id');
                } else {
                    $q->where('products.tenant_id', $tenantId);
                }
            });
        }

        if (! empty($productIdsFilter)) {
            $validProductIds = array_intersect($productIdsFilter, $tenantProductIds);
            if (! empty($validProductIds)) {
                $baseAlunosQuery->whereHas('products', fn ($q) => $q->whereIn('products.id', $validProductIds));
            }
        }

        if ($search !== null) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $baseAlunosQuery->where(function ($q) use ($like) {
                $q->where('users.name', 'like', $like)->orWhere('users.email', 'like', $like);
            });
        }

        $alunos = (clone $baseAlunosQuery)
            ->with(['products' => fn ($q) => $q->forTenant($tenantId)->select('products.id', 'products.name')])
            ->withCount(['products as products_count' => function ($q) use ($tenantId) {
                if ($tenantId === null) {
                    $q->whereNull('tenant_id');
                } else {
                    $q->where('tenant_id', $tenantId);
                }
            }])
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'products_count' => $u->products_count,
                'products' => $u->products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name]),
            ]);

        $produtos = Product::forTenant($tenantId)->withCount('users')->orderBy('name')->get();

        $totalAlunos = User::where('role', User::ROLE_ALUNO)
            ->whereHas('products', fn ($q) => $q->forTenant($tenantId))
            ->count();

        $totalInscricoes = empty($tenantProductIds)
            ? 0
            : DB::table('product_user')->whereIn('product_id', $tenantProductIds)->count();

        $produtosAtivos = Product::forTenant($tenantId)->whereHas('users')->count();

        $alunosNovos30dias = User::where('role', User::ROLE_ALUNO)
            ->whereExists(function ($q) use ($tenantId) {
                $q->select(DB::raw(1))
                    ->from('product_user')
                    ->join('products', 'products.id', '=', 'product_user.product_id')
                    ->whereColumn('product_user.user_id', 'users.id')
                    ->where('product_user.created_at', '>=', now()->subDays(30));
                if ($tenantId === null) {
                    $q->whereNull('products.tenant_id');
                } else {
                    $q->where('products.tenant_id', $tenantId);
                }
            })
            ->count();

        $stats = [
            'total_alunos' => $totalAlunos,
            'total_inscricoes' => $totalInscricoes,
            'produtos_ativos' => $produtosAtivos,
            'alunos_novos_30dias' => $alunosNovos30dias,
        ];

        return Inertia::render('Alunos/Index', [
            'alunos' => $alunos,
            'produtos' => $produtos,
            'stats' => $stats,
            'filter' => $filter,
            'product_ids_filter' => $productIdsFilter,
            'q' => $search,
        ]);
    }

    public function show(User $aluno): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        if ($aluno->role !== User::ROLE_ALUNO) {
            abort(404);
        }
        if (! $aluno->products()->forTenant($tenantId)->exists()) {
            abort(404);
        }
        $aluno->load(['products' => fn ($q) => $q->forTenant($tenantId)->select('products.id', 'products.name')]);
        return response()->json([
            'id' => $aluno->id,
            'name' => $aluno->name,
            'email' => $aluno->email,
            'products' => $aluno->products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name]),
        ]);
    }

    public function store(Request $request, AccessEmailService $accessEmailService): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6', 'max:255'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['string', 'exists:products,id'],
            'send_access_email' => ['nullable', 'boolean'],
        ]);
        $productIds = $validated['product_ids'] ?? [];
        $tenantProductIds = $this->tenantProductIds($tenantId);
        $productIds = array_values(array_intersect($productIds, $tenantProductIds));
        $sendAccessEmail = (bool) ($validated['send_access_email'] ?? true);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => User::ROLE_ALUNO,
            'tenant_id' => $tenantId,
        ]);

        foreach ($productIds as $pid) {
            $user->products()->syncWithoutDetaching([$pid]);
        }

        $emailsSent = 0;
        if ($sendAccessEmail && ! empty($productIds)) {
            $products = Product::whereIn('id', $productIds)->get();
            foreach ($products as $product) {
                if ($accessEmailService->sendForUserProduct($user, $product)) {
                    $emailsSent++;
                }
            }
        }

        $message = 'Aluno cadastrado com sucesso.';
        if ($sendAccessEmail && $emailsSent > 0) {
            $message .= " E-mail de acesso enviado para {$emailsSent} produto(s).";
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'aluno' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'products_count' => count($productIds)],
        ]);
    }

    public function update(Request $request, User $aluno): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        if ($aluno->role !== User::ROLE_ALUNO) {
            abort(404);
        }
        if (! $aluno->products()->forTenant($tenantId)->exists()) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $aluno->id],
            'password' => ['nullable', 'string', 'min:6', 'max:255'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['string', 'exists:products,id'],
        ]);

        $aluno->name = $validated['name'];
        $aluno->email = $validated['email'];
        if (! empty($validated['password'])) {
            $aluno->password = Hash::make($validated['password']);
        }
        $aluno->save();

        $tenantProductIds = $this->tenantProductIds($tenantId);
        $productIds = $validated['product_ids'] ?? [];
        $productIds = array_values(array_intersect($productIds, $tenantProductIds));
        $currentIds = $aluno->products()->forTenant($tenantId)->pluck('products.id')->toArray();
        $aluno->products()->detach($currentIds);
        $aluno->products()->attach($productIds);

        return response()->json([
            'success' => true,
            'message' => 'Aluno atualizado com sucesso.',
            'aluno' => [
                'id' => $aluno->id,
                'name' => $aluno->name,
                'email' => $aluno->email,
                'products_count' => count($productIds),
                'products' => Product::forTenant($tenantId)->whereIn('id', $productIds)->get(['id', 'name'])->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()->all(),
            ],
        ]);
    }

    public function destroy(User $aluno): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        if ($aluno->role !== User::ROLE_ALUNO) {
            abort(404);
        }
        if (! $aluno->products()->forTenant($tenantId)->exists()) {
            abort(404);
        }
        $aluno->products()->detach();
        $aluno->delete();
        return response()->json(['success' => true, 'message' => 'Aluno excluído com sucesso.']);
    }

    public function downloadImportExample(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $filename = 'alunos_exemplo_' . date('Y-m-d') . '.csv';
        $content = "nome;email;senha\nJoão Silva;joao@exemplo.com;senha123\nMaria Santos;maria@exemplo.com;\nPedro Oliveira;pedro@exemplo.com;minhasenha456";

        return response()->streamDownload(function () use ($content) {
            echo "\xEF\xBB\xBF";
            echo $content;
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function import(Request $request, AccessEmailService $accessEmailService): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['string', 'exists:products,id'],
            'send_access_email' => ['nullable', 'boolean'],
        ]);

        $productIds = $request->input('product_ids', []);
        $tenantProductIds = $this->tenantProductIds($tenantId);
        $productIds = array_values(array_intersect((array) $productIds, $tenantProductIds));
        if (empty($productIds)) {
            return response()->json(['success' => false, 'message' => 'Selecione ao menos um produto para dar acesso.'], 422);
        }
        $sendAccessEmail = (bool) ($request->input('send_access_email', true));

        $file = $request->file('file');
        $content = file_get_contents($file->getRealPath());
        $lines = preg_split('/\r\n|\r|\n/', trim($content));
        if (empty($lines)) {
            return response()->json(['success' => false, 'message' => 'Arquivo vazio.'], 422);
        }

        $rows = [];
        foreach ($lines as $i => $line) {
            $cols = str_getcsv($line, $this->detectDelimiter($line));
            if (! empty(array_filter(array_map('trim', $cols)))) {
                $rows[] = array_map('trim', $cols);
            }
        }
        if (empty($rows)) {
            return response()->json(['success' => false, 'message' => 'Nenhuma linha válida no arquivo.'], 422);
        }

        $header = array_map(fn ($h) => mb_strtolower(trim($h)), $rows[0]);
        $nameCol = $this->findColumn($header, ['nome', 'name', 'nome_completo']);
        $emailCol = $this->findColumn($header, ['email', 'e-mail', 'mail']);
        $passCol = $this->findColumn($header, ['senha', 'password', 'senha_acesso']);

        $hasHeader = $emailCol !== null || $nameCol !== null || $passCol !== null;
        if ($emailCol === null) {
            if (count($rows[0] ?? []) >= 2 && filter_var(trim($rows[0][1] ?? ''), FILTER_VALIDATE_EMAIL)) {
                $emailCol = 1;
                $nameCol = 0;
                $hasHeader = false;
            } else {
                return response()->json(['success' => false, 'message' => 'Coluna "email" ou "e-mail" não encontrada. Use cabeçalho: nome;email;senha'], 422);
            }
        }

        $dataRows = $hasHeader ? array_slice($rows, 1) : $rows;
        if (empty($dataRows)) {
            return response()->json(['success' => false, 'message' => 'Nenhum dado para importar.'], 422);
        }

        $created = 0;
        $skipped = 0;
        $errors = [];
        $emailsSent = 0;

        foreach ($dataRows as $idx => $row) {
            $email = isset($emailCol) && isset($row[$emailCol]) ? $row[$emailCol] : ($row[1] ?? $row[0] ?? '');
            $email = trim($email);
            if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Linha " . ($idx + 2) . ": e-mail inválido ou vazio.";
                $skipped++;
                continue;
            }

            if (User::where('email', $email)->exists()) {
                $errors[] = "Linha " . ($idx + 2) . ": e-mail {$email} já cadastrado.";
                $skipped++;
                continue;
            }

            $name = isset($nameCol) && isset($row[$nameCol]) ? $row[$nameCol] : explode('@', $email)[0];
            $name = trim($name) ?: 'Aluno';
            $password = (isset($passCol) && isset($row[$passCol]) && strlen(trim($row[$passCol] ?? '')) >= 6)
                ? trim($row[$passCol])
                : Str::random(12);

            try {
                $user = User::create([
                    'name' => mb_substr($name, 0, 255),
                    'email' => $email,
                    'password' => Hash::make($password),
                    'role' => User::ROLE_ALUNO,
                    'tenant_id' => $tenantId,
                ]);

                foreach ($productIds as $pid) {
                    $user->products()->syncWithoutDetaching([$pid]);
                }

                if ($sendAccessEmail && ! empty($productIds)) {
                    $products = Product::whereIn('id', $productIds)->get();
                    foreach ($products as $product) {
                        if ($accessEmailService->sendForUserProduct($user, $product)) {
                            $emailsSent++;
                        }
                    }
                }
                $created++;
            } catch (\Throwable $e) {
                $errors[] = "Linha " . ($idx + 2) . ": " . $e->getMessage();
                $skipped++;
            }
        }

        $message = "{$created} aluno(s) importado(s) com sucesso.";
        if ($skipped > 0) {
            $message .= " {$skipped} linha(s) ignorada(s).";
        }
        if ($sendAccessEmail && $emailsSent > 0) {
            $message .= " E-mail de acesso enviado.";
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'created' => $created,
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 10),
        ]);
    }

    private function detectDelimiter(string $line): string
    {
        return str_contains($line, ';') ? ';' : ',';
    }

    private function findColumn(array $header, array $names): ?int
    {
        foreach ($names as $n) {
            $i = array_search($n, $header, true);
            if ($i !== false) {
                return (int) $i;
            }
        }
        return null;
    }

    public function removeProduct(User $aluno, Product $produto): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        if ($aluno->role !== User::ROLE_ALUNO) {
            abort(404);
        }
        if ($produto->tenant_id !== $tenantId) {
            abort(403);
        }
        $aluno->products()->detach($produto->id);
        $remaining = $aluno->products()->where(fn ($q) => $q->forTenant($tenantId))->count();
        return response()->json([
            'success' => true,
            'message' => 'Acesso ao produto removido.',
            'products_count' => $remaining,
        ]);
    }
}
