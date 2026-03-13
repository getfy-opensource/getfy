<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\TenantMailConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

class EmailTestController extends Controller
{
    public function __construct(
        protected TenantMailConfigService $mailConfig
    ) {}

    public function test(Request $request)
    {
        $data = $request->validate([
            'test_to' => ['required', 'email'],
        ]);

        $tenantId = auth()->user()->tenant_id;
        $provider = Setting::get('email_provider', 'smtp', $tenantId);
        $this->mailConfig->applyMailerConfigForTenant($tenantId, [], $provider);
        Mail::purge('smtp');

        try {
            Mail::mailer('smtp')->to($data['test_to'])->send(new \App\Mail\TestEmail('Teste de conexão SMTP', '<p>Teste de conexão SMTP</p>'));
            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function sendTest(Request $request)
    {
        $validated = $request->validate([
            'test_to' => ['required', 'email'],
            'email_provider' => ['nullable', 'string', 'in:smtp,hostinger,sendgrid'],
            'smtp_host' => ['nullable', 'string'],
            'smtp_port' => ['nullable', 'string'],
            'smtp_username' => ['nullable', 'string'],
            'smtp_password' => ['nullable', 'string'],
            'smtp_encryption' => ['nullable', 'string', 'in:tls,ssl'],
            'hostinger_smtp_username' => ['nullable', 'string'],
            'hostinger_smtp_password' => ['nullable', 'string'],
            'sendgrid_api_key' => ['nullable', 'string'],
            'sendgrid_mail_from_address' => ['nullable', 'email', 'max:255'],
            'sendgrid_mail_from_name' => ['nullable', 'string', 'max:255'],
        ]);

        $tenantId = auth()->user()->tenant_id;
        $provider = $validated['email_provider'] ?? Setting::get('email_provider', 'smtp', $tenantId);
        $overrides = $this->buildMailOverridesFromRequest($validated, $provider);

        $this->mailConfig->applyMailerConfigForTenant($tenantId, $overrides, $provider);
        Mail::purge('smtp');

        try {
            $appName = Setting::get('app_name', config('getfy.app_name'), $tenantId);
            $body = "<p>Este é um e-mail de teste enviado por {$appName}.</p>";
            Mail::mailer('smtp')->to($validated['test_to'])->send(new \App\Mail\TestEmail('E‑mail de teste - '.$appName, $body));
            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function connectionTest(Request $request)
    {
        $tenantId = auth()->user()->tenant_id;

        $validated = $request->validate([
            'email_provider' => ['nullable', 'string', 'in:smtp,hostinger,sendgrid'],
            'smtp_host' => ['nullable', 'string'],
            'smtp_port' => ['nullable', 'string'],
            'smtp_username' => ['nullable', 'string'],
            'smtp_password' => ['nullable', 'string'],
            'smtp_encryption' => ['nullable', 'string', 'in:tls,ssl'],
            'hostinger_smtp_username' => ['nullable', 'string'],
            'hostinger_smtp_password' => ['nullable', 'string'],
            'sendgrid_api_key' => ['nullable', 'string'],
            'sendgrid_mail_from_address' => ['nullable', 'email', 'max:255'],
            'sendgrid_mail_from_name' => ['nullable', 'string', 'max:255'],
        ]);

        $provider = $validated['email_provider'] ?? Setting::get('email_provider', 'smtp', $tenantId);
        $overrides = $this->buildMailOverridesFromRequest($validated, $provider);
        $config = $this->mailConfig->getMailConfigForProvider($tenantId, $overrides, $provider);

        try {
            $this->smtpConnectionCheck(
                (string) $config['host'],
                (int) $config['port'],
                $config['encryption'],
                $config['username'],
                $config['password']
            );
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /** Build overrides for applyMailerConfig from request (smtp_* or hostinger_* or sendgrid_*). */
    protected function buildMailOverridesFromRequest(array $validated, string $provider): array
    {
        $overrides = [];
        if ($provider === 'sendgrid') {
            if (isset($validated['sendgrid_api_key']) && $validated['sendgrid_api_key'] !== null && $validated['sendgrid_api_key'] !== '') {
                $overrides['sendgrid_api_key'] = $validated['sendgrid_api_key'];
            }
            if (! empty($validated['sendgrid_mail_from_address'])) {
                $overrides['sendgrid_mail_from_address'] = $validated['sendgrid_mail_from_address'];
            }
            if (isset($validated['sendgrid_mail_from_name'])) {
                $overrides['sendgrid_mail_from_name'] = $validated['sendgrid_mail_from_name'];
            }
        } elseif ($provider === 'hostinger') {
            if (! empty($validated['hostinger_smtp_username'])) {
                $overrides['smtp_username'] = $validated['hostinger_smtp_username'];
            }
            if (! empty($validated['hostinger_smtp_password'])) {
                $overrides['smtp_password'] = $validated['hostinger_smtp_password'];
            }
            // host/port/encryption are fixed, no overrides
        } else {
            foreach (['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption'] as $k) {
                if (isset($validated[$k]) && $validated[$k] !== null && $validated[$k] !== '') {
                    $overrides[$k] = $validated[$k];
                }
            }
        }
        return $overrides;
    }

    protected function smtpConnectionCheck(string $host, int $port, ?string $encryption, ?string $username, ?string $password): void
    {
        $timeout = 10;
        // for SSL, connect using ssl:// wrapper so TLS handshake happens on connect
        if ($encryption === 'ssl') {
            $remote = sprintf('ssl://%s:%d', $host, $port);
        } else {
            $remote = sprintf('%s:%d', $host, $port);
        }
        $errno = null;
        $errstr = null;
        $context = stream_context_create();
        $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (! $socket) {
            throw new \RuntimeException('Não foi possível conectar ao servidor SMTP: '.$errstr.' ('.$errno.')');
        }
        stream_set_timeout($socket, $timeout);

        $this->smtpGetResponse($socket); // server greeting
        $this->smtpSend($socket, "EHLO localhost\r\n");
        $resp = $this->smtpGetResponse($socket);

        // STARTTLS if requested and server supports it (and not already SSL)
        if ($encryption === 'tls') {
            // Only attempt STARTTLS if server advertises it
            if (stripos($resp, 'STARTTLS') !== false) {
                $this->smtpSend($socket, "STARTTLS\r\n");
                $r = $this->smtpGetResponse($socket);
                if (strpos($r, '220') === 0) {
                    // enable crypto
                    $crypto_enabled = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                    if (! $crypto_enabled) {
                        throw new \RuntimeException('Falha ao iniciar STARTTLS.');
                    }
                    // EHLO again after TLS to refresh capabilities
                    $this->smtpSend($socket, "EHLO localhost\r\n");
                    $resp = $this->smtpGetResponse($socket);
                } else {
                    throw new \RuntimeException('Servidor não aceitou STARTTLS: '.$r);
                }
            } else {
                throw new \RuntimeException('Servidor não anuncia STARTTLS.');
            }
        } elseif ($encryption === 'ssl') {
            // For SSL we connected using the ssl:// wrapper above, so TLS is already active.
            // Do not call stream_socket_enable_crypto again to avoid "SSL/TLS already configured" warnings.
        }

        // If username/password provided, attempt an auth method supported by the server
        if ($username && $password) {
            // parse server capabilities for AUTH methods
            $authMethods = [];
            if (preg_match('/^250[-\\s](.*)$/mi', $resp, $m)) {
                // collect all lines after EHLO; find AUTH line
                if (preg_match_all('/^250[-\\s](.*)$/mi', $resp, $lines)) {
                    foreach ($lines[1] as $linePart) {
                        if (stripos($linePart, 'AUTH') !== false) {
                            $parts = preg_split('/\\s+/', trim($linePart));
                            foreach ($parts as $p) {
                                if (strtoupper($p) === 'AUTH') continue;
                                $authMethods[] = strtoupper($p);
                            }
                        }
                    }
                }
            }

            // prefer LOGIN, then PLAIN
            if (in_array('LOGIN', $authMethods, true)) {
                $this->smtpSend($socket, "AUTH LOGIN\r\n");
                $r = $this->smtpGetResponse($socket);
                if (strpos($r, '334') !== 0) {
                    throw new \RuntimeException('Servidor não aceita AUTH LOGIN: '.$r);
                }
                $this->smtpSend($socket, base64_encode($username)."\r\n");
                $r = $this->smtpGetResponse($socket);
                if (strpos($r, '334') !== 0) {
                    throw new \RuntimeException('Usuário não aceito: '.$r);
                }
                $this->smtpSend($socket, base64_encode($password)."\r\n");
                $r = $this->smtpGetResponse($socket);
                if (strpos($r, '235') !== 0) {
                    throw new \RuntimeException('Autenticação falhou: '.$r);
                }
            } elseif (in_array('PLAIN', $authMethods, true)) {
                // AUTH PLAIN <base64(\0user\0pass)>
                $auth = base64_encode("\0{$username}\0{$password}");
                $this->smtpSend($socket, "AUTH PLAIN {$auth}\r\n");
                $r = $this->smtpGetResponse($socket);
                if (strpos($r, '235') !== 0) {
                    throw new \RuntimeException('Autenticação falhou (PLAIN): '.$r);
                }
            } elseif (! empty($authMethods)) {
                throw new \RuntimeException('Servidor oferece métodos AUTH não suportados: '.implode(',', $authMethods));
            } else {
                throw new \RuntimeException('Servidor não anuncia métodos de autenticação (AUTH).');
            }
        }

        // QUIT
        $this->smtpSend($socket, "QUIT\r\n");
        fclose($socket);
    }

    protected function smtpSend($socket, string $cmd): void
    {
        fwrite($socket, $cmd);
    }

    protected function smtpGetResponse($socket): string
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            // lines that don't start with 3-digit code followed by '-' indicate end of response
            if (preg_match('/^\d{3}\s/', $line)) {
                break;
            }
        }
        return $response;
    }
}

