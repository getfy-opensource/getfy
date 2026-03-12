<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DockerSetupController extends Controller
{
    public function show(Request $request): View
    {
        $forwardedProto = (string) $request->headers->get('x-forwarded-proto', '');
        $scheme = str_contains(strtolower($forwardedProto), 'https') ? 'https' : ($request->isSecure() ? 'https' : 'http');

        $forwardedHost = (string) $request->headers->get('x-forwarded-host', '');
        $host = trim(explode(',', $forwardedHost)[0] ?? '');
        $host = $host !== '' ? $host : $request->getHost();

        $forwardedPort = (string) $request->headers->get('x-forwarded-port', '');
        $port = ctype_digit($forwardedPort) ? (int) $forwardedPort : $request->getPort();
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $suggestedUrl = $scheme . '://' . $host . (($port !== $defaultPort && $port !== 0) ? (':' . $port) : '');

        return view('docker-setup', [
            'suggested_url' => $suggestedUrl,
            'host' => $host,
            'scheme' => $scheme,
            'port' => $port,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'scheme' => ['required', 'in:http,https'],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ]);

        $scheme = $validated['scheme'];
        $host = strtolower(trim((string) $validated['host']));
        $host = preg_replace('#^https?://#', '', $host);
        $host = explode('/', $host)[0] ?? $host;
        $host = rtrim(trim($host), '.');
        $host = preg_replace('/\s+/', '', $host);
        $host = $host ?: $request->getHost();

        $port = $validated['port'] ?? null;
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $url = $scheme . '://' . $host;
        if ($port !== null && (int) $port !== $defaultPort) {
            $url .= ':' . (int) $port;
        }

        $this->setEnv([
            'APP_URL' => $url,
            'DOCKER_SETUP_DONE' => 'true',
            'APP_INSTALLED' => 'true',
        ]);

        $dockerDir = base_path('.docker');
        if (! is_dir($dockerDir)) {
            mkdir($dockerDir, 0777, true);
        }
        file_put_contents($dockerDir . DIRECTORY_SEPARATOR . 'app.url', $url);
        file_put_contents($dockerDir . DIRECTORY_SEPARATOR . 'setup.done', 'true');

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
