<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Services\StorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class MeusPedidosController extends Controller
{
    public function index(Request $request): Response
    {
        $user = auth()->user();

        $pedidos = Order::where('user_id', $user->id)
            ->with(['product:id,name,type,image,checkout_slug', 'productOffer:id,name', 'subscriptionPlan:id,name'])
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString()
            ->through(function (Order $o) {
                $storage = app(StorageService::class);
                return [
                    'id' => $o->id,
                    'status' => $o->status,
                    'amount' => $o->amount,
                    'gateway' => $this->gatewayLabel($o->gateway),
                    'payment_method' => $this->paymentMethodLabel($o->payment_method),
                    'created_at' => $o->created_at?->format('d/m/Y H:i'),
                    'product_name' => $o->product?->name ?? '—',
                    'product_type' => $o->product?->type ?? '—',
                    'product_image' => $o->product?->image ? $storage->url($o->product->image) : null,
                    'offer_name' => $o->productOffer?->name,
                    'plan_name' => $o->subscriptionPlan?->name,
                    'coupon_code' => $o->coupon_code,
                ];
            });

        $hasAreaMembros = $user->products()
            ->where('type', Product::TYPE_AREA_MEMBROS)
            ->exists();

        return Inertia::render('MemberArea/MeusPedidos', [
            'pedidos' => $pedidos,
            'hasAreaMembros' => $hasAreaMembros,
        ]);
    }

    /**
     * Gera recibo PDF do pedido para o aluno.
     */
    public function recibo(int $orderId): HttpResponse
    {
        $user = auth()->user();
        $order = Order::with(['product', 'productOffer', 'subscriptionPlan'])
            ->where('user_id', $user->id)
            ->where('id', $orderId)
            ->firstOrFail();

        $tenantId = $order->tenant_id;
        $appName = Setting::get('app_name', config('getfy.app_name'), $tenantId);
        $themePrimary = Setting::get('theme_primary', config('getfy.theme_primary'), $tenantId);
        $logoUrl = Setting::get('app_logo_dark', config('getfy.app_logo_dark'), $tenantId);

        // Parse primary color to RGB
        [$pr, $pg, $pb] = $this->hexToRgb($themePrimary);

        $pdf = new \FPDF('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        // ── Logo ──
        $logoPath = $this->downloadTempImage($logoUrl);
        if ($logoPath) {
            try {
                $pdf->Image($logoPath, 15, 12, 50);
            } catch (\Throwable $e) {
                // Logo failed, just skip
            }
            @unlink($logoPath);
        }

        // ── Header bar ──
        $pdf->SetFillColor($pr, $pg, $pb);
        $pdf->Rect(0, 35, 210, 1.5, 'F');
        $pdf->Ln(30);

        // ── Title ──
        $pdf->SetFont('Helvetica', 'B', 20);
        $pdf->SetTextColor(50, 50, 50);
        $pdf->Cell(0, 12, mb_convert_encoding('RECIBO DE COMPRA', 'ISO-8859-1', 'UTF-8'), 0, 1, 'C');
        $pdf->Ln(4);

        // ── Order info box ──
        $pdf->SetFillColor(248, 248, 248);
        $pdf->Rect(15, $pdf->GetY(), 180, 38, 'F');
        $y = $pdf->GetY() + 5;

        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->SetXY(20, $y);
        $pdf->Cell(85, 6, mb_convert_encoding('Nº do Pedido', 'ISO-8859-1', 'UTF-8'), 0, 0);
        $pdf->Cell(85, 6, 'Data', 0, 1);
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->SetTextColor(50, 50, 50);
        $pdf->SetX(20);
        $pdf->Cell(85, 7, '#' . $order->id, 0, 0);
        $pdf->Cell(85, 7, $order->created_at?->format('d/m/Y H:i') ?? '—', 0, 1);

        $pdf->Ln(2);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->SetX(20);
        $pdf->Cell(85, 6, 'Status', 0, 0);
        $pdf->Cell(85, 6, mb_convert_encoding('Método de Pagamento', 'ISO-8859-1', 'UTF-8'), 0, 1);
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->SetTextColor(50, 50, 50);
        $pdf->SetX(20);
        $pdf->Cell(85, 7, mb_convert_encoding($this->statusLabel($order->status), 'ISO-8859-1', 'UTF-8'), 0, 0);
        $methodStr = $this->gatewayLabel($order->gateway);
        if ($order->payment_method) {
            $methodStr .= ' — ' . mb_strtoupper($this->paymentMethodLabel($order->payment_method));
        }
        $pdf->Cell(85, 7, mb_convert_encoding($methodStr, 'ISO-8859-1', 'UTF-8'), 0, 1);

        $pdf->Ln(10);

        // ── Divider ──
        $pdf->SetDrawColor(230, 230, 230);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(6);

        // ── Customer info ──
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->SetTextColor($pr, $pg, $pb);
        $pdf->Cell(0, 8, mb_convert_encoding('DADOS DO COMPRADOR', 'ISO-8859-1', 'UTF-8'), 0, 1);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(80, 80, 80);
        $this->pdfRow($pdf, 'Nome', $user->name ?? '—');
        $this->pdfRow($pdf, 'E-mail', $order->email ?? $user->email ?? '—');
        if ($order->cpf) {
            $this->pdfRow($pdf, 'CPF', $order->cpf);
        }
        if ($order->phone) {
            $this->pdfRow($pdf, 'Telefone', $order->phone);
        }

        $pdf->Ln(6);
        $pdf->SetDrawColor(230, 230, 230);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(6);

        // ── Product details ──
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->SetTextColor($pr, $pg, $pb);
        $pdf->Cell(0, 8, mb_convert_encoding('DETALHES DO PRODUTO', 'ISO-8859-1', 'UTF-8'), 0, 1);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(80, 80, 80);
        $this->pdfRow($pdf, 'Produto', $order->product?->name ?? '—');
        $this->pdfRow($pdf, 'Tipo', $this->typeLabel($order->product?->type));
        if ($order->productOffer) {
            $this->pdfRow($pdf, 'Oferta', $order->productOffer->name);
        }
        if ($order->subscriptionPlan) {
            $this->pdfRow($pdf, 'Plano', $order->subscriptionPlan->name);
        }
        if ($order->coupon_code) {
            $this->pdfRow($pdf, 'Cupom', $order->coupon_code);
        }

        $pdf->Ln(8);

        // ── Total box ──
        $pdf->SetFillColor($pr, $pg, $pb);
        $pdf->Rect(15, $pdf->GetY(), 180, 18, 'F');
        $pdf->SetFont('Helvetica', 'B', 14);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(20, $pdf->GetY() + 4);
        $pdf->Cell(85, 10, 'TOTAL', 0, 0);
        $pdf->Cell(80, 10, 'R$ ' . number_format((float) $order->amount, 2, ',', '.'), 0, 0, 'R');

        $pdf->Ln(26);

        // ── Footer ──
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(160, 160, 160);
        $siteUrl = config('app.url', '');
        $pdf->Cell(0, 5, mb_convert_encoding('Este recibo foi gerado automaticamente por ' . $appName . '.', 'ISO-8859-1', 'UTF-8'), 0, 1, 'C');
        if ($siteUrl) {
            $pdf->Cell(0, 5, mb_convert_encoding($siteUrl, 'ISO-8859-1', 'UTF-8'), 0, 1, 'C');
        }
        $pdf->Cell(0, 5, mb_convert_encoding('Documento não fiscal — apenas para controle pessoal.', 'ISO-8859-1', 'UTF-8'), 0, 1, 'C');

        $content = $pdf->Output('S');

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="recibo-pedido-' . $order->id . '.pdf"',
        ]);
    }

    private function pdfRow(\FPDF $pdf, string $label, string $value): void
    {
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(40, 7, mb_convert_encoding($label . ':', 'ISO-8859-1', 'UTF-8'), 0, 0);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(0, 7, mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8'), 0, 1);
    }

    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    private function downloadTempImage(?string $url): ?string
    {
        if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        try {
            $contents = @file_get_contents($url, false, stream_context_create([
                'http' => ['timeout' => 5],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
            ]));
            if (! $contents) {
                return null;
            }
            $ext = 'png';
            if (str_contains($url, '.jpg') || str_contains($url, '.jpeg')) {
                $ext = 'jpg';
            }
            $tmp = tempnam(sys_get_temp_dir(), 'logo_') . '.' . $ext;
            file_put_contents($tmp, $contents);
            return $tmp;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function statusLabel(?string $status): string
    {
        return match ($status) {
            'completed' => 'Pago',
            'pending' => 'Pendente',
            'disputed' => 'Em disputa',
            'cancelled' => 'Cancelado',
            'refunded' => 'Reembolsado',
            default => $status ?? '—',
        };
    }

    private function typeLabel(?string $type): string
    {
        return match ($type) {
            'area_membros' => 'Área de Membros',
            'link' => 'Link',
            'link_pagamento' => 'Link de Pagamento',
            'aplicativo' => 'Aplicativo',
            default => $type ?? '—',
        };
    }

    private function gatewayLabel(?string $gateway): string
    {
        return match ($gateway) {
            'stripe' => 'Stripe',
            'mercadopago' => 'Mercado Pago',
            'asaas' => 'Asaas',
            'efi' => 'Efí',
            'pushinpay' => 'PushinPay',
            'spacepag' => 'SpacePag',
            'manual' => 'Manual',
            default => $gateway ?? '—',
        };
    }

    private function paymentMethodLabel(?string $method): string
    {
        return match ($method) {
            'pix' => 'PIX',
            'card' => 'Cartão',
            'boleto' => 'Boleto',
            'manual' => 'Manual',
            default => $method ?? '—',
        };
    }
}
