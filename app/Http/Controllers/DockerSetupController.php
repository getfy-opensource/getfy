<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DockerSetupController extends Controller
{
    private function resolveRequestScheme(Request $request): string
    {
        $forwardedProto = strtolower((string) $request->headers->get('x-forwarded-proto', ''));
        if (str_contains($forwardedProto, 'https')) {
            return 'https';
        }

        $cfVisitor = strtolower((string) $request->headers->get('cf-visitor', ''));
        if (str_contains($cfVisitor, 'https')) {
            return 'https';
        }

        return $request->isSecure() ? 'https' : 'http';
    }

    public function show(Request $request): View
    {
        $scheme = $this->resolveRequestScheme($request);

        $forwardedHost = (string) $request->headers->get('x-forwarded-host', '');
        $host = trim(explode(',', $forwardedHost)[0] ?? '');
        $host = $host !== '' ? $host : $request->getHost();

        $suggestedUrl = $scheme . '://' . $host;

        return view('docker-setup', [
            'suggested_url' => $suggestedUrl,
            'host' => $host,
            'scheme' => $scheme,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
        ]);

        $scheme = $this->resolveRequestScheme($request);

        $host = strtolower(trim((string) $validated['domain']));
        $host = preg_replace('#^https?://#', '', $host);
        $host = explode('/', $host)[0] ?? $host;
        $host = explode('?', $host)[0] ?? $host;
        if (substr_count($host, ':') === 1) {
            $host = explode(':', $host)[0] ?? $host;
        }
        $host = rtrim(trim($host), '.');
        $host = preg_replace('/\s+/', '', $host);
        $host = $host ?: $request->getHost();

        $url = $scheme . '://' . $host;

        $cronSecret = null;
        $envPath = base_path('.env');
        if (is_file($envPath)) {
            $env = (string) file_get_contents($envPath);
            if (preg_match('/^\s*CRON_SECRET\s*=\s*(.*)\s*$/mi', $env, $m)) {
                $cronSecret = trim((string) ($m[1] ?? ''), " \t\n\r\0\x0B\"'");
            }
        }
        if ($cronSecret === null || $cronSecret === '') {
            $cronSecret = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        }

        $this->setEnv([
            'APP_URL' => $url,
            'DOCKER_SETUP_DONE' => 'true',
            'APP_INSTALLED' => 'true',
            'CRON_SECRET' => $cronSecret,
        ]);

        $dockerDir = base_path('.docker');
        if (! is_dir($dockerDir)) {
            mkdir($dockerDir, 0777, true);
        }
        file_put_contents($dockerDir . DIRECTORY_SEPARATOR . 'app.url', $url);
        file_put_contents($dockerDir . DIRECTORY_SEPARATOR . 'setup.done', 'true');
        file_put_contents($dockerDir . DIRECTORY_SEPARATOR . 'Caddyfile.domains', $host . " {\n\treverse_proxy app:80\n}\n");

        return redirect('/login')->with('success', 'Configuração inicial salva.');
    }

    private function setEnv(array $vars): void
    {
        $envPath = base_path('.env');
        if (! is_file($envPath)) {
            copy(base_path('.env.example'), $envPath);
        }

        $content = (string) file_get_contents($envPath);
        foreach ($vars as $key => $value) {
            $value = (string) $value;
            $needsQuotes = (bool) preg_match("/\\s|#|\"|\\x27/", $value);
            $line = $key . '=' . ($needsQuotes ? ('"' . str_replace('"', '\\"', $value) . '"') : $value);
            $pattern = '/^\s*' . preg_quote($key, '/') . '\s*=.*$/m';
            if (preg_match($pattern, $content)) {
                $content = (string) preg_replace($pattern, $line, $content);
            } else {
                $content = rtrim($content, "\r\n") . "\n" . $line . "\n";
            }
        }

        $content = str_replace("\r\n", "\n", $content);
        file_put_contents($envPath, $content);
    }
}
