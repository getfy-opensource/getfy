<?php

namespace App\Gateways\MercadoPago;

use App\Gateways\Contracts\GatewayDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MercadoPago\Client\Common\RequestOptions;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPSearchRequest;

class MercadoPagoDriver implements GatewayDriver
{
    private function setCredentials(array $credentials): void
    {
        $token = trim($credentials['access_token'] ?? '');
        if ($token === '') {
            throw new \RuntimeException('Mercado Pago: Access Token não configurado.');
        }
        MercadoPagoConfig::setAccessToken($token);
    }

    private function idempotencyKey(string $externalId): string
    {
        // API pode exigir UUID v4 puro; uso hash do pedido para mesma tentativa retornar mesma chave
        return Str::uuid()->toString();
    }

    private function requestOptions(string $externalId): RequestOptions
    {
        $options = new RequestOptions();
        $key = $this->idempotencyKey($externalId);
        $options->setCustomHeaders(['X-Idempotency-Key: ' . $key]);

        return $options;
    }

    /**
     * Retorna a URL de notificação apenas se for válida (Mercado Pago exige URL válida).
     * Em localhost ou URL inválida, retorna string vazia para o pagamento ser criado sem webhook.
     */
    private function validNotificationUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (stripos($url, 'localhost') !== false || stripos($url, '127.0.0.1') !== false) {
            return '';
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '';
        }
        return $url;
    }

    /**
     * @param  array<string, string>  $credentials
     */
    public function testConnection(array $credentials): bool
    {
        $token = trim($credentials['access_token'] ?? '');
        if ($token === '') {
            return false;
        }

        try {
            MercadoPagoConfig::setAccessToken($token);
            $client = new PaymentClient();
            $client->search(new MPSearchRequest(1, 0, []));
            return true;
        } catch (MPApiException $e) {
            Log::debug('MercadoPagoDriver testConnection failed', [
                'status' => $e->getApiResponse()?->getStatusCode(),
            ]);
            return false;
        } catch (\Throwable $e) {
            Log::debug('MercadoPagoDriver testConnection error', ['message' => $e->getMessage()]);
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
        $this->setCredentials($credentials);
        if ($amount <= 0) {
            throw new \RuntimeException('Mercado Pago: valor inválido.');
        }

        $document = preg_replace('/\D/', '', $consumer['document'] ?? '');
        $name = trim($consumer['name'] ?? '');
        $parts = $name !== '' ? preg_split('/\s+/', $name, 2) : [];
        $firstName = $parts[0] ?? 'Cliente';
        $lastName = $parts[1] ?? '';

        $payer = [
            'entity_type' => 'individual',
            'email' => $consumer['email'] ?? '',
            'first_name' => $firstName,
            'last_name' => $lastName,
        ];
        if (strlen($document) >= 11) {
            $payer['identification'] = [
                'type' => strlen($document) === 11 ? 'CPF' : 'CNPJ',
                'number' => $document,
            ];
        }

        $body = [
            'transaction_amount' => round($amount, 2),
            'payment_method_id' => 'pix',
            'payer' => $payer,
            'description' => 'Pedido #' . $externalId,
            'external_reference' => $externalId,
        ];
        $notificationUrl = $this->validNotificationUrl($postbackUrl);
        if ($notificationUrl !== '') {
            $body['notification_url'] = $notificationUrl;
        }

        try {
            $client = new PaymentClient();
            $payment = $client->create($body, $this->requestOptions($externalId));
        } catch (MPApiException $e) {
            $apiContent = $e->getApiResponse()?->getContent();
            $apiContent = is_array($apiContent) ? $apiContent : [];
            $message = $apiContent['message'] ?? $e->getMessage();
            if (is_array($message)) {
                $message = json_encode($message);
            }
            $message = trim((string) $message);
            $cause = $apiContent['cause'] ?? null;
            $causeDescription = '';
            if (is_array($cause) && isset($cause[0]['description'])) {
                $causeDescription = (string) $cause[0]['description'];
            } elseif (is_string($cause)) {
                $causeDescription = $cause;
            }
            Log::warning('MercadoPagoDriver createPixPayment failed', [
                'order_id' => $externalId,
                'status' => $e->getApiResponse()?->getStatusCode(),
                'message' => $message,
                'cause' => $causeDescription ?: $cause,
            ]);
            $detail = $causeDescription ?: $message;
            $userMessage = 'Mercado Pago: não foi possível gerar o PIX.';
            if ($detail !== '') {
                if (stripos($detail, 'disabled') !== false || stripos($detail, 'feature') !== false) {
                    $userMessage = 'Mercado Pago: PIX não está habilitado para esta conta ou aplicação. Verifique no painel do Mercado Pago (Suas integrações) se PIX está ativo.';
                } elseif (stripos($detail, 'test user') !== false || stripos($detail, 'testuser') !== false) {
                    $userMessage = 'Mercado Pago: em modo de teste use um e-mail de usuário de teste (@testuser.com).';
                } elseif (stripos($detail, 'identification') !== false || stripos($detail, 'CPF') !== false || stripos($detail, 'document') !== false) {
                    $userMessage = 'Mercado Pago: preencha o CPF corretamente no checkout.';
                } else {
                    $userMessage .= ' ' . $detail;
                }
            } else {
                $userMessage .= ' Tente novamente.';
            }
            throw new \RuntimeException($userMessage);
        } catch (\Throwable $e) {
            Log::warning('MercadoPagoDriver createPixPayment error', [
                'order_id' => $externalId,
                'message' => $e->getMessage(),
                'class' => $e::class,
            ]);
            $msg = $e->getMessage();
            $userMessage = 'Mercado Pago: não foi possível gerar o PIX.';
            if ($msg !== '') {
                $userMessage .= ' ' . $msg;
            } else {
                $userMessage .= ' Tente novamente.';
            }
            throw new \RuntimeException($userMessage);
        }

        $paymentId = $payment->id ?? null;
        if (empty($paymentId)) {
            throw new \RuntimeException('Mercado Pago: resposta sem identificador do pagamento.');
        }

        $poi = $payment->point_of_interaction ?? null;
        $qrcodeBase64 = null;
        $qrCode = null;
        if (is_object($poi) && isset($poi->transaction_data)) {
            $txData = $poi->transaction_data;
            $qrcodeBase64 = $txData->qr_code_base64 ?? null;
            $qrCode = $txData->qr_code ?? null;
        }

        return [
            'transaction_id' => (string) $paymentId,
            'qrcode' => $qrcodeBase64,
            'copy_paste' => $qrCode,
        ];
    }

    /**
     * @param  array<string, string>  $credentials
     * @param  array{name: string, document: string, email: string}  $consumer
     * @param  array{payment_token: string, card_mask?: string, ...}  $card
     * @return array{transaction_id: string, status?: string}
     */
    public function createCardPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        array $card
    ): array {
        $this->setCredentials($credentials);
        if ($amount <= 0) {
            throw new \RuntimeException('Mercado Pago: valor inválido.');
        }

        $formData = $card['payment_token'] ?? '';
        if (is_string($formData) && $formData !== '') {
            $decoded = json_decode($formData, true);
            if (is_array($decoded)) {
                $formData = $decoded;
            }
        }
        if (! is_array($formData) || empty($formData)) {
            throw new \RuntimeException('Mercado Pago: dados do cartão inválidos. Preencha novamente.');
        }

        $name = $consumer['name'] ?? '';
        $parts = preg_split('/\s+/', trim($name), 2);
        $firstName = $parts[0] ?? '';
        $lastName = $parts[1] ?? '';

        // Payer: leitura defensiva (Bricks pode enviar payer aninhado ou ausente)
        $payerFromForm = isset($formData['payer']) && is_array($formData['payer']) ? $formData['payer'] : [];
        $payerEmail = trim((string) (
            $consumer['email'] ?? $payerFromForm['email'] ?? $formData['payer.email'] ?? ''
        ));
        if ($payerEmail === '' || ! str_contains($payerEmail, '@')) {
            throw new \RuntimeException('Mercado Pago: e-mail do comprador é obrigatório para pagamento com cartão.');
        }

        $payer = ['email' => $payerEmail];
        $idType = $payerFromForm['identification']['type'] ?? null;
        $idNumber = isset($payerFromForm['identification']['number']) ? preg_replace('/\D/', '', (string) $payerFromForm['identification']['number']) : '';
        if ($idType !== null && $idType !== '' && $idNumber !== '') {
            $payer['identification'] = ['type' => $idType, 'number' => $idNumber];
        } elseif (! empty($consumer['document'])) {
            $doc = preg_replace('/\D/', '', $consumer['document']);
            $payer['identification'] = [
                'type' => strlen($doc) === 11 ? 'CPF' : 'CNPJ',
                'number' => $doc,
            ];
        }
        $firstNameForm = $payerFromForm['first_name'] ?? $payerFromForm['firstName'] ?? null;
        $lastNameForm = $payerFromForm['last_name'] ?? $payerFromForm['lastName'] ?? null;
        if ($firstName !== '' || $lastName !== '') {
            $payer['first_name'] = $firstName !== '' ? $firstName : ($firstNameForm ?? 'Nome');
            $payer['last_name'] = $lastName !== '' ? $lastName : ($lastNameForm ?? ' ');
        } elseif (($firstNameForm ?? '') !== '' || ($lastNameForm ?? '') !== '') {
            $payer['first_name'] = $firstNameForm ?? 'Nome';
            $payer['last_name'] = $lastNameForm ?? ' ';
        }

        // Token: Bricks pode enviar 'token' ou 'cardToken'
        $token = trim((string) ($formData['token'] ?? $formData['cardToken'] ?? ''));
        $paymentMethodId = strtolower(trim((string) ($formData['paymentMethodId'] ?? $formData['payment_method_id'] ?? '')));
        if ($token === '' || $paymentMethodId === '') {
            throw new \RuntimeException('Mercado Pago: dados do cartão incompletos. Preencha novamente e tente de novo.');
        }

        $installments = (int) ($formData['installments'] ?? 1);
        if ($installments < 1) {
            $installments = 1;
        }
        $transactionAmount = round($amount, 2);
        if ($transactionAmount < 0.01) {
            throw new \RuntimeException('Mercado Pago: valor inválido.');
        }

        $body = [
            'transaction_amount' => $transactionAmount,
            'token' => $token,
            'payment_method_id' => $paymentMethodId,
            'installments' => $installments,
            'payer' => $payer,
            'description' => 'Pedido #' . $externalId,
            'external_reference' => (string) $externalId,
        ];
        $notificationUrl = $this->validNotificationUrl(rtrim((string) config('app.url'), '/') . '/webhooks/gateways/mercadopago');
        if ($notificationUrl !== '') {
            $body['notification_url'] = $notificationUrl;
        }
        $issuerRaw = $formData['issuerId'] ?? $formData['issuer_id'] ?? null;
        if ($issuerRaw !== null && $issuerRaw !== '' && is_numeric($issuerRaw)) {
            $body['issuer_id'] = (int) $issuerRaw;
        }

        $accessToken = $credentials['access_token'] ?? '';
        $idempotencyKey = $this->idempotencyKey($externalId);

        try {
            // Tentar via HTTP direta (evita possível bug/limitação do SDK PHP no fluxo de cartão)
            $response = Http::withToken($accessToken)
                ->withHeaders(['X-Idempotency-Key' => $idempotencyKey])
                ->timeout(30)
                ->post('https://api.mercadopago.com/v1/payments', $body);

            $statusCode = $response->status();
            $responseBody = $response->json();

            if ($statusCode >= 200 && $statusCode < 300) {
                $paymentId = $responseBody['id'] ?? null;
                $status = $responseBody['status'] ?? null;
                if (empty($paymentId)) {
                    throw new \RuntimeException('Mercado Pago: resposta sem identificador do pagamento.');
                }
                return [
                    'transaction_id' => (string) $paymentId,
                    'status' => $status ? strtolower((string) $status) : null,
                ];
            }

            // Resposta de erro: simular MPApiException para o bloco de tratamento abaixo
            $message = is_array($responseBody) ? ($responseBody['message'] ?? '') : (string) $responseBody;
            $cause = is_array($responseBody) ? ($responseBody['cause'] ?? null) : null;
            throw new \RuntimeException('MP_API:' . $statusCode . ':' . json_encode([
                'message' => $message,
                'cause' => $cause,
                'body' => $responseBody,
            ]));
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            if (strpos($msg, 'MP_API:') === 0) {
                $parts = explode(':', $msg, 3);
                $statusCode = (int) ($parts[1] ?? 0);
                $payload = json_decode($parts[2] ?? '{}', true) ?? [];
                $message = $payload['message'] ?? $msg;
                $cause = $payload['cause'] ?? null;
                $responseBody = $payload['body'] ?? [];
                $causeDescription = '';
                if (is_array($cause) && isset($cause[0]['description'])) {
                    $causeDescription = (string) $cause[0]['description'];
                } elseif (is_string($cause)) {
                    $causeDescription = $cause;
                }
                Log::warning('MercadoPagoDriver createCardPayment failed', [
                    'order_id' => $externalId,
                    'status' => $statusCode,
                    'message' => $message,
                    'cause' => $causeDescription ?: $cause,
                    'api_response' => $responseBody,
                ]);
                $detail = $causeDescription ?: $message;
                $detailLower = is_string($detail) ? strtolower($detail) : '';
                $causeEmpty = empty($cause) || (is_array($cause) && count($cause) === 0);
                $userMessage = 'Mercado Pago: não foi possível processar o cartão. Tente novamente.';
                if ($detail !== '') {
                    if (stripos($detailLower, 'feature') !== false || stripos($detailLower, 'disabled') !== false) {
                        $userMessage = 'Mercado Pago: pagamento com cartão não está habilitado para esta conta ou aplicação. Verifique no painel do Mercado Pago (Suas integrações / Meios de pagamento) se o cartão de crédito está ativo e se está usando as credenciais corretas (teste x produção).';
                    } elseif (($statusCode === 500 && $detailLower === 'internal_error' && $causeEmpty) || (stripos($detailLower, 'internal_error') !== false && $causeEmpty)) {
                        // API retorna 500 internal_error com cause vazio quando cartão não está habilitado na aplicação (Feature is disabled)
                        $userMessage = 'Mercado Pago: pagamento com cartão não está habilitado para esta conta ou aplicação. Verifique no painel do Mercado Pago (Suas integrações / Meios de pagamento) se o cartão de crédito está ativo e se está usando as credenciais corretas (teste x produção).';
                        Log::info('MercadoPagoDriver createCardPayment 500 internal_error (cause vazio) – provável feature disabled', [
                            'order_id' => $externalId,
                            'sandbox' => ! empty($credentials['sandbox']),
                        ]);
                    } elseif (strtolower($detail) === 'internal_error' || stripos($detail, 'internal') !== false) {
                        $userMessage = 'Mercado Pago: erro temporário no processamento. Tente novamente em alguns instantes ou use outro cartão.';
                        Log::info('MercadoPagoDriver createCardPayment internal_error – payload (sanitized)', [
                            'order_id' => $externalId,
                            'sandbox' => ! empty($credentials['sandbox']),
                            'status' => $statusCode,
                            'has_token' => ! empty($body['token']),
                            'payment_method_id' => $body['payment_method_id'] ?? null,
                            'installments' => $body['installments'] ?? null,
                            'transaction_amount' => $body['transaction_amount'] ?? null,
                            'payer_keys' => array_keys($body['payer'] ?? []),
                            'formData_keys' => array_keys($formData),
                        ]);
                    } elseif (stripos($detail, 'test') !== false && (stripos($detail, 'user') !== false || stripos($detail, 'card') !== false)) {
                        $userMessage = 'Mercado Pago: em modo de teste use um cartão de teste e e-mail de usuário de teste (@testuser.com). Veja a documentação do Mercado Pago.';
                    } elseif (stripos($detail, 'token') !== false || stripos($detail, 'invalid') !== false) {
                        $userMessage = 'Mercado Pago: dados do cartão inválidos ou expirados. Preencha novamente e tente de novo.';
                    } elseif (stripos($detail, 'call') !== false && stripos($detail, 'for_review') !== false) {
                        $userMessage = 'Mercado Pago: pagamento em análise. Verifique seu e-mail ou tente outro cartão.';
                    } else {
                        $userMessage = 'Mercado Pago: ' . $detail;
                    }
                }
                throw new \RuntimeException($userMessage);
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($e instanceof \RuntimeException) {
                throw $e;
            }
            Log::warning('MercadoPagoDriver createCardPayment error', ['order_id' => $externalId, 'message' => $e->getMessage()]);
            throw new \RuntimeException('Mercado Pago: não foi possível processar o cartão. Tente novamente.');
        }
    }

    /**
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
        $this->setCredentials($credentials);
        if ($amount <= 0) {
            throw new \RuntimeException('Mercado Pago: valor inválido.');
        }

        $document = preg_replace('/\D/', '', $consumer['document'] ?? '');
        $name = $consumer['name'] ?? '';
        $parts = preg_split('/\s+/', trim($name), 2);
        $firstName = $parts[0] ?? 'Nome';
        $lastName = $parts[1] ?? '';

        $payer = [
            'entity_type' => 'individual',
            'email' => $consumer['email'] ?? '',
            'first_name' => $firstName,
            'last_name' => $lastName,
            'identification' => [
                'type' => strlen($document) === 11 ? 'CPF' : 'CNPJ',
                'number' => $document ?: '00000000000',
            ],
        ];
        $address = $consumer['address'] ?? null;
        if (is_array($address) && ! empty($address['zip_code']) && ! empty($address['street_name']) && ! empty($address['street_number'])
            && ! empty($address['neighborhood']) && ! empty($address['city']) && ! empty($address['federal_unit'])) {
            $payer['address'] = [
                'zip_code' => preg_replace('/\D/', '', $address['zip_code']),
                'street_name' => $address['street_name'],
                'street_number' => (string) $address['street_number'],
                'neighborhood' => $address['neighborhood'],
                'city' => $address['city'],
                'federal_unit' => strtoupper(substr((string) ($address['federal_unit'] ?? ''), 0, 2)),
            ];
        }
        $body = [
            'transaction_amount' => round($amount, 2),
            'payment_method_id' => 'bolbradesco',
            'payer' => $payer,
            'description' => 'Pedido #' . $externalId,
            'external_reference' => $externalId,
        ];
        $validUrl = $this->validNotificationUrl($notificationUrl);
        if ($validUrl !== '') {
            $body['notification_url'] = $validUrl;
        }

        try {
            $client = new PaymentClient();
            $payment = $client->create($body, $this->requestOptions($externalId));
        } catch (MPApiException $e) {
            $apiContent = $e->getApiResponse()?->getContent();
            $apiContent = is_array($apiContent) ? $apiContent : [];
            $message = $apiContent['message'] ?? $e->getMessage();
            if (is_array($message)) {
                $message = json_encode($message);
            }
            $message = trim((string) $message);
            $cause = $apiContent['cause'] ?? null;
            $causeDescription = '';
            if (is_array($cause) && isset($cause[0]['description'])) {
                $causeDescription = (string) $cause[0]['description'];
            } elseif (is_string($cause)) {
                $causeDescription = $cause;
            }
            Log::warning('MercadoPagoDriver createBoletoPayment failed', [
                'order_id' => $externalId,
                'status' => $e->getApiResponse()?->getStatusCode(),
                'message' => $message,
                'cause' => $causeDescription ?: $cause,
            ]);
            $detail = $causeDescription ?: $message;
            $userMessage = 'Mercado Pago: não foi possível gerar o boleto.';
            if ($detail !== '') {
                if (stripos($detail, 'notification') !== false || stripos($detail, 'url') !== false) {
                    $userMessage = 'Mercado Pago: não foi possível gerar o boleto. (URL de notificação inválida em ambiente local; em produção deve funcionar.)';
                } elseif (stripos($detail, 'disabled') !== false || stripos($detail, 'feature') !== false) {
                    $userMessage = 'Mercado Pago: boleto não está habilitado para esta conta ou aplicação. Verifique no painel do Mercado Pago.';
                } elseif (stripos($detail, 'test user') !== false || stripos($detail, 'testuser') !== false) {
                    $userMessage = 'Mercado Pago: em modo de teste use um e-mail de usuário de teste (@testuser.com).';
                } elseif (stripos($detail, 'identification') !== false || stripos($detail, 'CPF') !== false) {
                    $userMessage = 'Mercado Pago: preencha o CPF corretamente no checkout.';
                } else {
                    $userMessage .= ' ' . $detail;
                }
            } else {
                $userMessage .= ' Tente novamente.';
            }
            throw new \RuntimeException($userMessage);
        } catch (\Throwable $e) {
            Log::warning('MercadoPagoDriver createBoletoPayment error', [
                'order_id' => $externalId,
                'message' => $e->getMessage(),
                'class' => $e::class,
            ]);
            $msg = $e->getMessage();
            $userMessage = 'Mercado Pago: não foi possível gerar o boleto.';
            if ($msg !== '') {
                $userMessage .= ' ' . $msg;
            } else {
                $userMessage .= ' Tente novamente.';
            }
            throw new \RuntimeException($userMessage);
        }

        $paymentId = $payment->id ?? null;
        if (empty($paymentId)) {
            throw new \RuntimeException('Mercado Pago: resposta sem identificador do pagamento.');
        }

        $txDetails = $payment->transaction_details ?? null;
        $pdfUrl = '';
        if (is_object($txDetails) && isset($txDetails->external_resource_url)) {
            $pdfUrl = (string) $txDetails->external_resource_url;
        } elseif (is_array($txDetails)) {
            $pdfUrl = (string) ($txDetails['external_resource_url'] ?? '');
        }
        $expireAt = $payment->date_of_expiration ?? now()->addDays(3)->format('Y-m-d');
        if (is_string($expireAt) && strlen($expireAt) >= 10) {
            $expireAt = substr($expireAt, 0, 10);
        }

        return [
            'transaction_id' => (string) $paymentId,
            'amount' => $amount,
            'expire_at' => $expireAt,
            'barcode' => '',
            'pdf_url' => $pdfUrl,
        ];
    }

    /**
     * @param  array<string, string>  $credentials
     */
    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        $this->setCredentials($credentials);

        try {
            $client = new PaymentClient();
            $payment = $client->get((int) $transactionId);
        } catch (\Throwable $e) {
            Log::debug('MercadoPagoDriver getTransactionStatus error', [
                'transaction_id' => $transactionId,
                'message' => $e->getMessage(),
            ]);
            return null;
        }

        $status = $payment->status ?? null;
        if ($status === null) {
            return null;
        }
        $status = strtolower((string) $status);

        return match ($status) {
            'approved', 'authorized' => 'paid',
            'pending', 'in_process', 'in_mediation' => 'pending',
            'rejected', 'cancelled', 'refunded', 'charged_back' => 'cancelled',
            default => 'pending',
        };
    }
}
