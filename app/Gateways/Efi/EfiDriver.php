<?php

namespace App\Gateways\Efi;

use App\Gateways\Contracts\GatewayDriver;
use Efi\EfiPay;
use Efi\Exception\EfiException;
use Illuminate\Support\Facades\Log;

class EfiDriver implements GatewayDriver
{
    private const SLOW_PIX_STEP_MS = 1000;

    /**
     * Build SDK options from credentials. Certificate path must be absolute and file must exist.
     *
     * @param  array<string, string>  $credentials
     * @return array<string, mixed>
     */
    private function buildOptions(array $credentials): array
    {
        $certPath = $credentials['certificate_path'] ?? '';
        if ($certPath !== '' && is_file($certPath)) {
            $certPath = realpath($certPath) ?: $certPath;
        } else {
            $certPath = null;
        }

        $sandbox = isset($credentials['sandbox'])
            ? filter_var($credentials['sandbox'], FILTER_VALIDATE_BOOLEAN)
            : false;

        return [
            'client_id' => $credentials['client_id'] ?? '',
            'client_secret' => $credentials['client_secret'] ?? '',
            'certificate' => $certPath,
            'pwdCertificate' => $credentials['pwd_certificate'] ?? '',
            'sandbox' => $sandbox,
        ];
    }

    /**
     * Normaliza telefone para o formato Efí: apenas dígitos, 10 ou 11 caracteres (^[1-9]{2}9?[0-9]{8}$).
     */
    private function normalizePhoneForEfi(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if ($digits === '') {
            return '11999999999';
        }
        if (strlen($digits) > 11 && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }
        $digits = ltrim($digits, '0');
        if (strlen($digits) > 11) {
            $digits = substr($digits, -11);
        }
        if (strlen($digits) < 10) {
            return '11999999999';
        }
        return $digits;
    }

    /**
     * @param  array<string, string>  $credentials
     */
    public function testConnection(array $credentials): bool
    {
        $options = $this->buildOptions($credentials);
        if (empty($options['client_id']) || empty($options['client_secret']) || empty($options['certificate'])) {
            return false;
        }

        try {
            $api = EfiPay::getInstance($options);
            $inicio = now()->subDays(7)->toIso8601String();
            $fim = now()->toIso8601String();
            $api->pixListCharges(['inicio' => $inicio, 'fim' => $fim]);
            return true;
        } catch (EfiException $e) {
            Log::debug('EfiDriver testConnection failed', ['code' => $e->code, 'error' => $e->error ?? null]);
            return false;
        } catch (\Throwable $e) {
            Log::debug('EfiDriver testConnection error', ['message' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * @param  array<string, string>  $credentials
     * @param  array{name: string, document: string, email: string}  $consumer
     * @return array{transaction_id: string, qrcode?: string, copy_paste?: string, raw?: array}
     */
    public function createPixPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $postbackUrl
    ): array {
        $flowStart = microtime(true);
        $options = $this->buildOptions($credentials);
        if (empty($options['certificate'])) {
            throw new \RuntimeException('Efí: certificado P12 não configurado.');
        }

        $pixKey = $credentials['pix_key'] ?? '';
        if ($pixKey === '') {
            throw new \RuntimeException('Efí: chave PIX não configurada.');
        }

        $sandbox = ! empty($options['sandbox']);

        $document = preg_replace('/\D/', '', $consumer['document'] ?? '');
        if (strlen($document) < 11) {
            $document = '00000000000';
        }

        $body = [
            'calendario' => [
                'expiracao' => 3600,
            ],
            'devedor' => [
                'cpf' => $document,
                'nome' => $consumer['name'] ?? '',
            ],
            'valor' => [
                'original' => number_format(round($amount, 2), 2, '.', ''),
            ],
            'chave' => $pixKey,
            'solicitacaoPagador' => 'Pedido #' . $externalId,
            'infoAdicionais' => [
                ['nome' => 'order_id', 'valor' => $externalId],
            ],
        ];

        $instanceMs = null;
        $chargeMs = null;

        try {
            $instanceStart = microtime(true);
            $api = EfiPay::getInstance($options);
            $instanceMs = (int) round((microtime(true) - $instanceStart) * 1000);

            $chargeStart = microtime(true);
            $pix = $api->pixCreateImmediateCharge([], $body);
            $chargeMs = (int) round((microtime(true) - $chargeStart) * 1000);
        } catch (EfiException $e) {
            Log::warning('EfiDriver createPixPayment failed', [
                'order_id' => $externalId,
                'code' => $e->code,
                'error' => $e->error ?? null,
            ]);
            throw new \RuntimeException('Efí: ' . ($e->errorDescription ?? $e->error ?? 'Erro ao gerar PIX.'));
        } catch (\Throwable $e) {
            Log::warning('EfiDriver createPixPayment error', ['order_id' => $externalId, 'message' => $e->getMessage()]);
            throw new \RuntimeException('Efí: não foi possível gerar o PIX. Tente novamente.');
        }

        $txid = $pix['txid'] ?? '';
        if ($txid === '') {
            throw new \RuntimeException('Efí: resposta sem identificador da cobrança.');
        }

        $qrcode = null;
        $copyPaste = null;
        $locId = null;
        $qrMs = null;

        if (! empty($pix['loc']['id'])) {
            $locId = (int) $pix['loc']['id'];
            try {
                $qrStart = microtime(true);
                $qrcodeResponse = $api->pixGenerateQRCode(['id' => $locId]);
                $qrMs = (int) round((microtime(true) - $qrStart) * 1000);
                $qrcode = $qrcodeResponse['imagemQrcode'] ?? null;
                $copyPaste = $qrcodeResponse['qrcode'] ?? $qrcodeResponse['copiaECola'] ?? null;
            } catch (\Throwable $e) {
                Log::warning('EfiDriver pixGenerateQRCode failed', ['txid' => $txid]);
            }
        }

        $totalMs = (int) round((microtime(true) - $flowStart) * 1000);
        if ($totalMs >= self::SLOW_PIX_STEP_MS) {
            Log::info('EfiDriver: slow PIX create', [
                'order_id' => $externalId,
                'txid' => $txid,
                'sandbox' => $sandbox,
                'duration_total_ms' => $totalMs,
                'duration_get_instance_ms' => $instanceMs ?? null,
                'duration_pix_create_immediate_charge_ms' => $chargeMs ?? null,
                'duration_pix_generate_qrcode_ms' => $qrMs,
                'loc_id' => $locId,
                'has_qrcode_image' => $qrcode !== null && $qrcode !== '',
                'has_copy_paste' => $copyPaste !== null && $copyPaste !== '',
            ]);
        }

        return [
            'transaction_id' => $txid,
            'qrcode' => $qrcode,
            'copy_paste' => $copyPaste,
            'raw' => $pix,
        ];
    }

    /**
     * Cria cobrança com cartão em one-step (API Cobranças Efí).
     *
     * @param  array<string, string>  $credentials
     * @param  array{name: string, document: string, email: string}  $consumer
     * @param  array{payment_token: string, card_mask?: string}  $card
     * @return array{transaction_id: string, status?: string}
     */
    public function createCardPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        array $card
    ): array {
        $options = $this->buildOptions($credentials);
        if (empty($options['certificate'])) {
            throw new \RuntimeException('Efí: certificado P12 não configurado.');
        }

        $paymentToken = trim($card['payment_token'] ?? '');
        if ($paymentToken === '') {
            throw new \RuntimeException('Efí: token do cartão não informado.');
        }

        $document = preg_replace('/\D/', '', $consumer['document'] ?? '');
        if (strlen($document) < 11) {
            $document = '00000000000';
        }
        $phone = $this->normalizePhoneForEfi($consumer['phone'] ?? '');

        $customer = [
            'name' => $consumer['name'] ?? '',
            'cpf' => $document,
            'phone_number' => $phone,
            'email' => $consumer['email'] ?? '',
        ];

        $valueCents = (int) round($amount * 100);
        $items = [
            [
                'name' => 'Pedido #' . $externalId,
                'amount' => 1,
                'value' => $valueCents,
            ],
        ];

        $installments = isset($card['installments']) ? max(1, min(12, (int) $card['installments'])) : 1;
        $creditCard = [
            'customer' => $customer,
            'installments' => $installments,
            'payment_token' => $paymentToken,
        ];

        $body = [
            'items' => $items,
            'payment' => [
                'credit_card' => $creditCard,
            ],
            'metadata' => [
                'custom_id' => $externalId,
            ],
        ];

        try {
            $api = EfiPay::getInstance($options);
            $response = $api->createOneStepCharge([], $body);
        } catch (EfiException $e) {
            Log::warning('EfiDriver createCardPayment failed', [
                'order_id' => $externalId,
                'code' => $e->code,
                'error' => $e->error ?? null,
            ]);
            throw new \RuntimeException('Efí: ' . ($e->errorDescription ?? $e->error ?? 'Pagamento com cartão recusado. Verifique os dados e tente novamente.'));
        } catch (\Throwable $e) {
            Log::warning('EfiDriver createCardPayment error', ['order_id' => $externalId, 'message' => $e->getMessage()]);
            throw new \RuntimeException('Efí: não foi possível processar o pagamento. Tente novamente.');
        }

        $chargeId = $response['data']['charge_id'] ?? null;
        $status = $response['data']['status'] ?? null;
        if (empty($chargeId)) {
            throw new \RuntimeException('Efí: resposta inválida da cobrança.');
        }

        return [
            'transaction_id' => (string) $chargeId,
            'status' => is_string($status) ? strtolower($status) : null,
        ];
    }

    /**
     * Cria cobrança por boleto em one-step (API Cobranças Efí).
     *
     * @param  array<string, string>  $credentials
     * @param  array{name: string, document: string, email: string}  $consumer
     * @return array{transaction_id: string, amount: float, expire_at: string, barcode: string, pdf_url: string, raw?: array}
     */
    public function createBoletoPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $notificationUrl
    ): array {
        $options = $this->buildOptions($credentials);
        if (empty($options['certificate'])) {
            throw new \RuntimeException('Efí: certificado P12 não configurado.');
        }

        $document = preg_replace('/\D/', '', $consumer['document'] ?? '');
        if (strlen($document) < 11) {
            $document = '00000000000';
        }
        $phone = $this->normalizePhoneForEfi($consumer['phone'] ?? '');

        $customer = [
            'name' => $consumer['name'] ?? '',
            'cpf' => $document,
            'email' => $consumer['email'] ?? '',
            'phone_number' => $phone,
        ];

        $valueCents = (int) round($amount * 100);
        $expireAt = now()->addDays(5)->format('Y-m-d');

        $bankingBillet = [
            'expire_at' => $expireAt,
            'customer' => $customer,
        ];

        $body = [
            'items' => [
                [
                    'name' => 'Pedido #' . $externalId,
                    'amount' => 1,
                    'value' => $valueCents,
                ],
            ],
            'metadata' => [
                'custom_id' => $externalId,
            ],
            'payment' => [
                'banking_billet' => $bankingBillet,
            ],
        ];

        try {
            $api = EfiPay::getInstance($options);
            $response = $api->createOneStepCharge([], $body);

            $data = $response['data'] ?? [];
            $chargeId = $data['charge_id'] ?? null;
            if (! empty($chargeId) && $notificationUrl !== '') {
                try {
                    $api->updateChargeMetadata(
                        ['id' => (int) $chargeId],
                        ['custom_id' => $externalId, 'notification_url' => $notificationUrl]
                    );
                } catch (\Throwable $e) {
                    Log::warning('EfiDriver createBoletoPayment updateChargeMetadata failed', [
                        'charge_id' => $chargeId,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        } catch (EfiException $e) {
            Log::warning('EfiDriver createBoletoPayment failed', [
                'order_id' => $externalId,
                'code' => $e->code,
                'error' => $e->error ?? null,
            ]);
            throw new \RuntimeException('Efí: ' . ($e->errorDescription ?? $e->error ?? 'Erro ao gerar boleto.'));
        } catch (\Throwable $e) {
            Log::warning('EfiDriver createBoletoPayment error', ['order_id' => $externalId, 'message' => $e->getMessage()]);
            throw new \RuntimeException('Efí: não foi possível gerar o boleto. Tente novamente.');
        }

        $data = $response['data'] ?? [];
        $chargeId = $data['charge_id'] ?? null;
        if (empty($chargeId)) {
            throw new \RuntimeException('Efí: resposta sem identificador da cobrança.');
        }

        $barcode = '';
        $pdfUrl = '';
        $expireAtReturn = $expireAt;

        $payment = $data['payment'] ?? [];
        if (! empty($payment['banking_billet'])) {
            $billet = $payment['banking_billet'];
            $barcode = $billet['linha_digitavel'] ?? $billet['barcode'] ?? '';
            $pdfUrl = $billet['pdf']['charge'] ?? $billet['pdf'] ?? '';
            if (is_array($pdfUrl)) {
                $pdfUrl = $pdfUrl['charge'] ?? $pdfUrl['url'] ?? '';
            }
            if (! empty($billet['expire_at'])) {
                $expireAtReturn = $billet['expire_at'];
            }
        }

        if ($barcode === '' || $pdfUrl === '') {
            try {
                $detail = $api->detailCharge(['id' => (int) $chargeId]);
                $detailData = $detail['data'] ?? [];
                $detailPayment = $detailData['payment'] ?? [];
                $detailBillet = $detailPayment['banking_billet'] ?? [];
                if ($barcode === '' && ! empty($detailBillet['linha_digitavel'])) {
                    $barcode = $detailBillet['linha_digitavel'];
                }
                if ($barcode === '' && ! empty($detailBillet['barcode'])) {
                    $barcode = $detailBillet['barcode'];
                }
                if ($pdfUrl === '' && ! empty($detailBillet['pdf']['charge'])) {
                    $pdfUrl = $detailBillet['pdf']['charge'];
                }
                if ($pdfUrl === '' && ! empty($detailBillet['pdf'])) {
                    $pdfUrl = is_string($detailBillet['pdf']) ? $detailBillet['pdf'] : ($detailBillet['pdf']['charge'] ?? '');
                }
                if (! empty($detailBillet['expire_at'])) {
                    $expireAtReturn = $detailBillet['expire_at'];
                }
            } catch (\Throwable $e) {
                Log::warning('EfiDriver createBoletoPayment detailCharge fallback failed', ['charge_id' => $chargeId]);
            }
        }

        return [
            'transaction_id' => (string) $chargeId,
            'amount' => $amount,
            'expire_at' => $expireAtReturn,
            'barcode' => $barcode,
            'pdf_url' => $pdfUrl,
            'raw' => $response,
        ];
    }

    /**
     * @param  array<string, string>  $credentials
     */
    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        $options = $this->buildOptions($credentials);
        if (empty($options['certificate'])) {
            return null;
        }

        $api = EfiPay::getInstance($options);

        // Boleto/cartão usam charge_id (numérico); PIX usa txid (string).
        if (is_numeric($transactionId)) {
            try {
                $detail = $api->detailCharge(['id' => (int) $transactionId]);
            } catch (\Throwable $e) {
                return null;
            }
            $status = $detail['data']['status'] ?? null;
            if (! is_string($status)) {
                return null;
            }
            $status = strtoupper($status);
            if (in_array($status, ['PAID', 'SETTLED', 'CONCLUIDA', 'LIQUIDADO'], true)) {
                return 'paid';
            }
            if (in_array($status, ['NEW', 'WAITING', 'ACTIVE', 'PENDING', 'PENDENTE'], true)) {
                return 'pending';
            }
            if (in_array($status, ['CANCELED', 'CANCELLED', 'CANCELADA', 'REMOVIDA_PELO_USUARIO_RECEBEDOR', 'REMOVIDA_PELO_PSP'], true)) {
                return 'cancelled';
            }
            return 'pending';
        }

        try {
            $detail = $api->pixDetailCharge(['txid' => $transactionId]);
        } catch (\Throwable $e) {
            return null;
        }

        $status = $detail['status'] ?? null;
        if (! is_string($status)) {
            return null;
        }

        $status = strtoupper($status);
        if ($status === 'CONCLUIDA') {
            return 'paid';
        }
        if (in_array($status, ['ATIVA', 'PENDENTE'], true)) {
            return 'pending';
        }
        if (in_array($status, ['REMOVIDA_PELO_USUARIO_RECEBEDOR', 'REMOVIDA_PELO_PSP', 'CANCELADA'], true)) {
            return 'cancelled';
        }

        return 'pending';
    }
}
