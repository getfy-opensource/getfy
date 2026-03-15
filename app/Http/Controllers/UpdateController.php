<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

class UpdateController extends Controller
{
    private const GITHUB_RELEASES_LATEST = 'https://api.github.com/repos/getfy-opensource/getfy/releases/latest';
    private const GITHUB_TAGS = 'https://api.github.com/repos/getfy-opensource/getfy/tags';

    /**
     * Ensure string is valid UTF-8 for JSON (avoids "Malformed UTF-8" on Windows console output).
     */
    private static function toUtf8(string $str): string
    {
        if ($str === '') {
            return $str;
        }
        $utf8 = @mb_convert_encoding($str, 'UTF-8', 'UTF-8');
        if ($utf8 !== false) {
            return $utf8;
        }
        if (function_exists('iconv')) {
            $cleaned = @iconv('UTF-8', 'UTF-8//IGNORE', $str);
            if ($cleaned !== false) {
                return $cleaned;
            }
        }
        return preg_replace('/[^\x20-\x7E\x0A\x0D]/', '?', $str);
    }

    /**
     * Normalize version string (strip "v" prefix).
     */
    private static function normalizeVersion(string $tag): string
    {
        return ltrim(trim($tag), 'v');
    }

    /**
     * Get latest version from GitHub tags API (fallback when there are no releases).
     */
    private function getLatestFromTags(): ?string
    {
        $res = Http::timeout(10)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get(self::GITHUB_TAGS);

        if (! $res->successful()) {
            return null;
        }

        $tags = $res->json();
        if (! is_array($tags) || empty($tags)) {
            return null;
        }

        $latest = null;
        foreach ($tags as $tag) {
            $name = $tag['name'] ?? '';
            $ver = self::normalizeVersion((string) $name);
            if ($ver === '') {
                continue;
            }
            if (! preg_match('/^\d+\.\d+(\.\d+)?/', $ver)) {
                continue;
            }
            if ($latest === null || version_compare($ver, $latest, '>')) {
                $latest = $ver;
            }
        }

        return $latest;
    }

    /**
     * Check for updates (GitHub Releases API, fallback to Tags API).
     */
    public function check(): JsonResponse
    {
        $current = config('getfy.version');
        $response = [
            'current' => $current,
            'latest' => null,
            'available' => false,
            'error' => null,
            'changelog_remote' => null,
        ];

        try {
            $res = Http::timeout(10)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get(self::GITHUB_RELEASES_LATEST);

            if ($res->successful()) {
                $data = $res->json();
                $tagName = $data['tag_name'] ?? '';
                $latest = self::normalizeVersion((string) $tagName);
                $response['latest'] = $latest;
                $response['changelog_remote'] = $data['body'] ?? null;

                if ($latest !== '' && version_compare($latest, $current, '>')) {
                    $response['available'] = true;
                }

                return response()->json($response);
            }

            if ($res->status() === 404) {
                $latestFromTags = $this->getLatestFromTags();
                if ($latestFromTags !== null) {
                    $response['latest'] = $latestFromTags;
                    if (version_compare($latestFromTags, $current, '>')) {
                        $response['available'] = true;
                    }
                    $response['error'] = null;

                    return response()->json($response);
                }
                $response['error'] = 'Nenhuma release nem tag de versão encontrada. Crie uma Release ou uma tag (ex: v1.0.0) no GitHub.';

                return response()->json($response);
            }

            $response['error'] = 'Não foi possível verificar atualizações. Tente novamente mais tarde.';

            return response()->json($response);
        } catch (\Throwable $e) {
            $response['error'] = 'Erro ao verificar: ' . $e->getMessage();
        }

        return response()->json($response);
    }

    /**
     * Run update: git pull, composer, npm build, migrate.
     */
    public function run(Request $request): JsonResponse|RedirectResponse
    {
        if (! config('getfy.updates_enabled', true)) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => 'Atualizações pela interface estão desativadas.'], 403);
            }

            return redirect()->route('settings.index', ['tab' => 'update'])
                ->with('error', 'Atualizações pela interface estão desativadas.');
        }

        $basePath = base_path();
        $branch = config('getfy.update_branch', 'main');
        $expectedRepo = config('getfy.update_repository_url', 'https://github.com/getfy-opensource/getfy.git');
        $timeout = 300;
        $git = 'git -c safe.directory=' . escapeshellarg($basePath);

        // PHP executável (servidor web muitas vezes não tem PHP no PATH; usar caminho explícito ou GETFY_PHP_PATH)
        $phpBinary = null;
        if (defined('PHP_BINARY') && PHP_BINARY !== '') {
            $phpBinary = PHP_BINARY;
        } elseif (config('getfy.php_path')) {
            $phpPath = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, config('getfy.php_path')), DIRECTORY_SEPARATOR);
            $phpBinary = $phpPath . DIRECTORY_SEPARATOR . 'php.exe';
            if (! is_file($phpBinary)) {
                $phpBinary = $phpPath . DIRECTORY_SEPARATOR . 'php';
            }
        }
        $pathEnv = getenv('PATH') ?: '';
        if ($phpBinary !== null && $phpBinary !== '') {
            $phpDir = dirname($phpBinary);
            $pathEnv = $phpDir . PATH_SEPARATOR . $pathEnv;
        }
        $processEnv = ['PATH' => $pathEnv];

        // Check if .git exists
        if (! is_dir($basePath . DIRECTORY_SEPARATOR . '.git')) {
            $msg = 'Este diretório não é um repositório Git. Atualização automática indisponível.';
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $msg], 400);
            }

            return redirect()->route('settings.index', ['tab' => 'update'])->with('error', $msg);
        }

        $steps = [];
        $runStep = function (string $command, string $label) use ($basePath, $timeout, $processEnv, &$steps): bool {
            $result = Process::path($basePath)->timeout($timeout)->env($processEnv)->run($command);
            $steps[] = [
                'label' => $label,
                'ok' => $result->successful(),
                'output' => self::toUtf8($result->output()),
                'error' => self::toUtf8($result->errorOutput()),
            ];
            if (! $result->successful()) {
                return false;
            }
            return true;
        };

        // 0. Garantir identidade Git (evita "Committer identity unknown" ao fazer pull/merge)
        $runStep($git . ' config user.email "getfy-update@localhost" && ' . $git . ' config user.name "Getfy Update"', 'Git config');

        // 0.1. Guardar alterações locais (evita "your local changes would be overwritten by merge")
        $runStep($git . ' stash push -m "getfy-update"', 'Git stash');

        // 1. Git fetch + pull
        if (! $runStep($git . " fetch origin && " . $git . " pull origin {$branch}", 'Git pull')) {
            $runStep($git . ' stash pop', 'Git stash pop');
            $last = end($steps);
            $msg = 'Falha ao atualizar código: ' . self::toUtf8($last['error'] ?: $last['output'] ?: 'erro desconhecido');
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $msg, 'steps' => $steps], 422);
            }

            return redirect()->route('settings.index', ['tab' => 'update'])->with('error', $msg);
        }

        // 1.1. Reaplicar alterações locais (se havia algo no stash)
        $runStep($git . ' stash pop', 'Git stash pop');

        // 2. Composer install (usar PHP explícito quando disponível, para evitar "php não reconhecido" no servidor web)
        $composerCmd = 'composer install --no-interaction --no-dev';
        if ($phpBinary !== null && $phpBinary !== '' && is_file($phpBinary)) {
            $composerCmd = '"' . $phpBinary . '" vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'composer install --no-interaction --no-dev';
        }
        if (! $runStep($composerCmd, 'Composer install')) {
            $last = end($steps);
            $msg = 'Falha no Composer: ' . self::toUtf8($last['error'] ?: $last['output'] ?: 'erro desconhecido');
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $msg, 'steps' => $steps], 422);
            }

            return redirect()->route('settings.index', ['tab' => 'update'])->with('error', $msg);
        }

        // 3. NPM ci + build
        if (! $runStep('npm ci && npm run build', 'NPM build')) {
            $last = end($steps);
            $msg = 'Falha no build do frontend: ' . self::toUtf8($last['error'] ?: $last['output'] ?: 'erro desconhecido');
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $msg, 'steps' => $steps], 422);
            }

            return redirect()->route('settings.index', ['tab' => 'update'])->with('error', $msg);
        }

        // 4. Migrate
        try {
            \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
        } catch (\Throwable $e) {
            $msg = 'Falha nas migrations: ' . self::toUtf8($e->getMessage());
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $msg, 'steps' => $steps], 422);
            }

            return redirect()->route('settings.index', ['tab' => 'update'])->with('error', $msg);
        }

        // 5. Config cache
        try {
            \Illuminate\Support\Facades\Artisan::call('config:cache');
        } catch (\Throwable $e) {
            // Non-fatal
        }

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Atualização concluída com sucesso.', 'redirect' => route('settings.index', ['tab' => 'update']), 'steps' => $steps]);
        }

        return redirect()->route('settings.index', ['tab' => 'update'])->with('success', 'Atualização concluída com sucesso.');
    }
}
