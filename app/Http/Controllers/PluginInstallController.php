<?php

namespace App\Http\Controllers;

use App\Plugins\PluginRegistry;
use App\Services\PluginStoreService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PluginInstallController extends Controller
{
    private function pluginsIndexRedirect(array $flash = []): RedirectResponse
    {
        $response = redirect()->route('plugins.index', ['tab' => 'installed']);
        if ($flash !== []) {
            $response->with($flash);
        }

        return $response;
    }

    /**
     * Destino persistente das instalações (ZIP/loja), fora de {@see PluginRegistry::bundledPluginsPath()}.
     *
     * @return array{0: string, 1: string}|null [caminho lógico, realpath]
     */
    private function persistentPluginInstallPaths(): ?array
    {
        try {
            $pluginsPath = PluginRegistry::ensureUserInstallRoot();
            $real = realpath($pluginsPath);

            return [$pluginsPath, $real !== false ? $real : $pluginsPath];
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public function __invoke(Request $request, string $slug): RedirectResponse
    {
        if (! preg_match('/^[a-z0-9\-]+$/i', $slug)) {
            return $this->pluginsIndexRedirect(['error' => 'Slug inválido.']);
        }

        if (! class_exists('ZipArchive')) {
            return $this->pluginsIndexRedirect([
                'error' => 'A extensão PHP Zip não está habilitada. Baixe o plugin e instale manualmente ou habilite a extensão no php.ini.',
                'zip_unavailable' => true,
            ]);
        }

        $store = app(PluginStoreService::class);
        $useUploadedFile = $request->hasFile('plugin_zip');
        if (! $useUploadedFile && ! $store->isConfigured()) {
            return $this->pluginsIndexRedirect(['error' => 'Loja de plugins não configurada. Envie o ZIP em “Instalar plugin (ZIP)” na aba Instalados.']);
        }

        $tempFile = null;

        if ($useUploadedFile) {
            $upload = $request->file('plugin_zip');
            $ext = strtolower($upload->getClientOriginalExtension() ?? '');
            $mime = $upload->getMimeType();
            $zipMimes = ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'];
            if ($ext !== 'zip' && ! in_array($mime, $zipMimes, true)) {
                return $this->pluginsIndexRedirect(['error' => 'Arquivo do plugin deve ser um ZIP.']);
            }
            $tempFile = $this->persistUploadedZipToLocalTemp($upload);
            if ($tempFile === null) {
                return $this->pluginsIndexRedirect(['error' => 'Não foi possível salvar o arquivo enviado. Verifique permissões de storage/app.']);
            }
        } else {
            $downloadUrl = $request->input('download_url');
            if ($downloadUrl && is_string($downloadUrl)) {
                $storeBase = $store->getBaseUrl();
                if ($storeBase === '' || (! str_starts_with($downloadUrl, $storeBase . '/') && ! str_starts_with($downloadUrl, $storeBase . '?'))) {
                    return $this->pluginsIndexRedirect(['error' => 'Link de download inválido.']);
                }
                $download = ['download_url' => $downloadUrl, 'expires_at' => ''];
            } else {
                $purchaseToken = $request->input('purchase_token');
                $platformId = config('app.name') . '-' . md5(config('app.key'));
                $download = $store->requestDownloadUrl($slug, $purchaseToken ?: null, $platformId);
                if (! $download || empty($download['download_url'])) {
                    $reason = $store->getLastError();
                    $message = $reason
                        ? 'Não foi possível obter o link de download: ' . $reason
                        : 'Não foi possível obter o link de download. Verifique se o plugin é gratuito ou se o token de compra é válido.';
                    return $this->pluginsIndexRedirect(['error' => $message]);
                }
            }

            $resolvedEarly = $this->persistentPluginInstallPaths();
            if ($resolvedEarly === null || ! is_dir($resolvedEarly[1])) {
                return $this->pluginsIndexRedirect(['error' => 'Pasta de plugins indisponível.']);
            }

            $tempFile = tempnam($this->writableTempDirectory(), 'plugin_' . $slug . '_');
            if ($tempFile === false) {
                return $this->pluginsIndexRedirect(['error' => 'Erro ao criar arquivo temporário.']);
            }

            try {
                $response = Http::timeout(60)->withOptions(['sink' => $tempFile])->get($download['download_url']);
                if (! $response->successful()) {
                    @unlink($tempFile);
                    return $this->pluginsIndexRedirect(['error' => 'Falha ao baixar o plugin.']);
                }
            } catch (\Throwable $e) {
                @unlink($tempFile);
                report($e);
                return $this->pluginsIndexRedirect(['error' => 'Erro ao baixar o plugin.']);
            }
        }

        $resolved = $this->persistentPluginInstallPaths();
        if ($resolved === null || ! is_dir($resolved[1])) {
            if ($tempFile && is_file($tempFile)) {
                @unlink($tempFile);
            }
            return $this->pluginsIndexRedirect(['error' => 'Pasta de plugins indisponível.']);
        }
        [$pluginsPath, $realPluginsPath] = $resolved;

        $error = $this->extractZipToPlugins($tempFile, $slug, $pluginsPath, $realPluginsPath);
        if ($error) {
            return $error;
        }

        return $this->registerAndRunMigrations($slug)
            ?? $this->pluginsIndexRedirect(['success' => 'Plugin instalado com sucesso.']);
    }

    /**
     * POST /gerenciar-plugins/install-from-zip - Instala a partir apenas do ZIP; slug inferido pela pasta raiz.
     */
    public function installFromZip(Request $request): RedirectResponse
    {
        if (! class_exists('ZipArchive')) {
            return $this->pluginsIndexRedirect([
                'error' => 'A extensão PHP Zip não está habilitada.',
                'zip_unavailable' => true,
            ]);
        }

        if (! $request->hasFile('plugin_zip')) {
            return $this->pluginsIndexRedirect(['error' => 'Envie o arquivo ZIP do plugin.']);
        }

        $upload = $request->file('plugin_zip');
        $ext = strtolower($upload->getClientOriginalExtension() ?? '');
        $mime = $upload->getMimeType();
        $zipMimes = ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'];
        if ($ext !== 'zip' && ! in_array($mime, $zipMimes, true)) {
            return $this->pluginsIndexRedirect(['error' => 'Arquivo do plugin deve ser um ZIP.']);
        }

        $tempFile = $this->persistUploadedZipToLocalTemp($upload);
        if ($tempFile === null) {
            return $this->pluginsIndexRedirect(['error' => 'Erro ao salvar o arquivo. Verifique permissões de storage/app.']);
        }

        $zip = new \ZipArchive;
        if ($zip->open($tempFile) !== true) {
            @unlink($tempFile);
            return $this->pluginsIndexRedirect(['error' => 'Arquivo do plugin inválido.']);
        }

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_contains($name, '..')) {
                $zip->close();
                @unlink($tempFile);
                return $this->pluginsIndexRedirect(['error' => 'Arquivo do plugin inválido.']);
            }
            $entries[] = $name;
        }
        $zip->close();

        $baseInZip = null;
        foreach ($entries as $name) {
            $parts = explode('/', str_replace('\\', '/', trim($name, '/')));
            $first = $parts[0] ?? '';
            if ($first === '' || $first === '.' || $first === '..') {
                continue;
            }
            if ($baseInZip === null) {
                $baseInZip = $first;
            } elseif ($baseInZip !== $first) {
                $baseInZip = '';
                break;
            }
        }

        if (! $baseInZip || $baseInZip === '') {
            @unlink($tempFile);
            return $this->pluginsIndexRedirect(['error' => 'O ZIP deve conter uma única pasta raiz (ex: meu-plugin/plugin.json).']);
        }

        $slug = strtolower(preg_replace('/[^a-z0-9\-]/', '', str_replace('_', '-', $baseInZip)));
        if ($slug === '') {
            @unlink($tempFile);
            return $this->pluginsIndexRedirect(['error' => 'Não foi possível identificar o nome do plugin a partir do ZIP.']);
        }

        $resolvedZip = $this->persistentPluginInstallPaths();
        if ($resolvedZip === null || ! is_dir($resolvedZip[1])) {
            @unlink($tempFile);
            return $this->pluginsIndexRedirect(['error' => 'Pasta de plugins indisponível.']);
        }
        [$pluginsPath, $realPluginsPath] = $resolvedZip;

        $error = $this->extractZipToPlugins($tempFile, $slug, $pluginsPath, $realPluginsPath);
        if ($error) {
            return $error;
        }

        return $this->registerAndRunMigrations($slug)
            ?? $this->pluginsIndexRedirect(['success' => 'Plugin instalado com sucesso.']);
    }

    /**
     * Register plugin in DB and run its migrations. Returns RedirectResponse on error, null on success.
     * $slug is the folder name we used (e.g. from URL); plugin.json may have a different slug, so we find by path if needed.
     */
    private function registerAndRunMigrations(string $slug): ?RedirectResponse
    {
        $all = PluginRegistry::installed();
        $pluginsPath = PluginRegistry::ensureUserInstallRoot();
        $targetDir = $pluginsPath.DIRECTORY_SEPARATOR.$slug;
        $targetReal = is_dir($targetDir) ? realpath($targetDir) : null;

        $plugin = collect($all)->first(fn ($p) => $p['slug'] === $slug);
        if (! $plugin && $targetReal) {
            $plugin = collect($all)->first(function ($p) use ($targetReal) {
                $pReal = is_dir($p['path']) ? realpath($p['path']) : null;
                return $pReal && $pReal === $targetReal;
            });
        }
        if (! $plugin) {
            return $this->pluginsIndexRedirect(['error' => 'Plugin não encontrado após extração.']);
        }

        $pluginSlug = $plugin['slug'];
        PluginRegistry::register($pluginSlug);
        $migrationsPath = $plugin['migrations'] ?? null;
        if (is_string($migrationsPath) && $migrationsPath !== '') {
            $fullPath = $plugin['path'].DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $migrationsPath);
            if (is_dir($fullPath)) {
                $base = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, base_path()), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
                $full = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fullPath);
                $relativePath = str_replace('\\', '/', Str::after($full, $base));
                try {
                    Artisan::call('migrate', ['--path' => $relativePath, '--force' => true]);
                } catch (\Throwable $e) {
                    report($e);
                    return $this->pluginsIndexRedirect(['error' => 'Plugin instalado, mas as migrations falharam: '.$e->getMessage()]);
                }
            }
        }
        return null;
    }

    /**
     * @return RedirectResponse|null null em sucesso
     */
    private function extractZipToPlugins(string $tempFile, string $slug, string $pluginsPath, ?string $realPluginsPath): ?RedirectResponse
    {
        $zip = new \ZipArchive;
        if ($zip->open($tempFile) !== true) {
            @unlink($tempFile);
            return $this->pluginsIndexRedirect(['error' => 'Arquivo do plugin inválido.']);
        }

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_contains($name, '..')) {
                $zip->close();
                @unlink($tempFile);
                return $this->pluginsIndexRedirect(['error' => 'Arquivo do plugin inválido.']);
            }
            $entries[] = $name;
        }

        $extractTo = $this->writableTempDirectory() . DIRECTORY_SEPARATOR . 'plugin_extract_' . $slug . '_' . Str::random(8);
        if (is_dir($extractTo)) {
            File::deleteDirectory($extractTo);
        }
        if (! File::makeDirectory($extractTo, 0755, true)) {
            $zip->close();
            @unlink($tempFile);
            return $this->pluginsIndexRedirect(['error' => 'Erro ao preparar extração.']);
        }

        $zip->extractTo($extractTo);
        $zip->close();
        @unlink($tempFile);

        $baseInZip = null;
        foreach ($entries as $name) {
            $parts = explode('/', str_replace('\\', '/', trim($name, '/')));
            $first = $parts[0] ?? '';
            if ($first === '' || $first === '.' || $first === '..') {
                continue;
            }
            if ($baseInZip === null) {
                $baseInZip = $first;
            } elseif ($baseInZip !== $first) {
                $baseInZip = '';
                break;
            }
        }
        $sourceDir = ($baseInZip && is_dir($extractTo . DIRECTORY_SEPARATOR . $baseInZip))
            ? $extractTo . DIRECTORY_SEPARATOR . $baseInZip
            : $extractTo;

        $targetDir = $pluginsPath . DIRECTORY_SEPARATOR . $slug;
        if (is_dir($targetDir)) {
            File::deleteDirectory($targetDir);
        }
        $pluginsDir = dirname($targetDir);
        if (! is_dir($pluginsDir)) {
            File::makeDirectory($pluginsDir, 0755, true);
        }

        if (! $this->moveExtractedPluginToTarget($sourceDir, $targetDir)) {
            File::deleteDirectory($extractTo);
            if (is_dir($targetDir)) {
                File::deleteDirectory($targetDir);
            }

            return $this->pluginsIndexRedirect(['error' => 'Não foi possível mover o plugin para a pasta de destino (disco ou permissões).']);
        }

        if (is_dir($extractTo)) {
            File::deleteDirectory($extractTo);
        }

        $targetReal = realpath($targetDir);
        if (! $targetReal || ! Str::startsWith($targetReal, $realPluginsPath)) {
            if (is_dir($targetDir)) {
                File::deleteDirectory($targetDir);
            }

            return $this->pluginsIndexRedirect(['error' => 'Erro de segurança ao instalar o plugin.']);
        }

        return null;
    }

    /**
     * Diretório gravável para ZIP temporário e extração (sys temp, storage/app/tmp ou storage/framework/tmp).
     */
    private function writableTempDirectory(): string
    {
        $candidates = [
            rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR),
            storage_path('app'.DIRECTORY_SEPARATOR.'tmp'),
            storage_path('framework'.DIRECTORY_SEPARATOR.'tmp'),
        ];

        foreach ($candidates as $dir) {
            if ($dir === '' || $dir === false) {
                continue;
            }
            try {
                if (! is_dir($dir)) {
                    File::makeDirectory($dir, 0755, true);
                }
                if (is_dir($dir) && is_writable($dir)) {
                    return $dir;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    }

    /**
     * Grava o upload em storage/app/tmp-plugins (sempre no mesmo volume que o projeto — evita falhas de move_uploaded_file entre discos).
     */
    private function persistUploadedZipToLocalTemp(\Illuminate\Http\UploadedFile $upload): ?string
    {
        try {
            Storage::disk('local')->makeDirectory('tmp-plugins');
        } catch (\Throwable) {
            // ignore
        }

        try {
            $relative = Storage::disk('local')->putFile('tmp-plugins', $upload);
            if ($relative === false || $relative === null) {
                return null;
            }

            return Storage::disk('local')->path($relative);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * rename() falha entre volumes (Docker, Windows, /tmp vs projeto). Usa cópia + remoção como fallback.
     */
    private function moveExtractedPluginToTarget(string $sourceDir, string $targetDir): bool
    {
        if (! is_dir($sourceDir)) {
            return false;
        }

        if (@rename($sourceDir, $targetDir)) {
            return true;
        }

        try {
            File::copyDirectory($sourceDir, $targetDir);
            File::deleteDirectory($sourceDir);

            return is_dir($targetDir);
        } catch (\Throwable) {
            return false;
        }
    }
}