<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProofDocument;
use App\Services\ProofOfDeliveryService;
use App\Services\ProofPdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use setasign\Fpdi\Fpdi;

class ProofExportsController extends Controller
{
    public function __construct(
        protected ProofOfDeliveryService $proofService,
        protected ProofPdfRenderer $pdfRenderer,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $tenantId = $request->user()?->tenant_id;
        $products = Product::forTenant($tenantId)->orderBy('name')->get(['id', 'name'])->toArray();

        return Inertia::render('Vendas/ProofExport', [
            'products' => $products,
            'filters' => [
                'date_from' => (string) $request->query('date_from', ''),
                'date_to' => (string) $request->query('date_to', ''),
                'product_id' => (string) $request->query('product_id', ''),
                'payment_method' => (string) $request->query('payment_method', ''),
                'status' => (string) $request->query('status', 'completed'),
            ],
        ]);
    }

    public function exportZip(Request $request): StreamedResponse
    {
        abort(404);
    }

    public function exportPdf(Request $request): Response
    {
        $tenantId = $request->user()?->tenant_id;
        $validated = $request->validate([
            'date_from' => ['required', 'string', 'max:32'],
            'date_to' => ['required', 'string', 'max:32'],
            'product_id' => ['nullable', 'string', 'max:36'],
            'payment_method' => ['nullable', 'string', 'in:pix,card,boleto'],
            'status' => ['nullable', 'string', 'in:completed,pending,disputed,cancelled,refunded,all'],
        ]);

        $from = $this->parseExportDate($validated['date_from'])->startOfDay();
        $to = $this->parseExportDate($validated['date_to'])->endOfDay();

        $query = Order::query()
            ->forTenant($tenantId)
            ->with(['user', 'product', 'orderItems.product'])
            ->whereBetween('created_at', [$from, $to]);

        $status = $validated['status'] ?? 'completed';
        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        $productId = trim((string) ($validated['product_id'] ?? ''));
        if ($productId !== '') {
            $query->where('product_id', $productId);
        }

        $method = trim((string) ($validated['payment_method'] ?? ''));
        if ($method !== '') {
            $m = strtolower($method);
            if ($m === 'pix') {
                $query->where(function ($q) {
                    $q->whereRaw("LOWER(gateway) LIKE '%pix%'")
                        ->orWhere(function ($q2) {
                            $q2->where('metadata->checkout_payment_method', 'pix')
                                ->orWhere('metadata->checkout_payment_method', 'pix_auto');
                        });
                });
            } elseif ($m === 'card') {
                $query->where(function ($q) {
                    $q->where('gateway', 'card')
                        ->orWhereRaw("LOWER(gateway) LIKE '%card%'")
                        ->orWhereRaw("LOWER(gateway) LIKE '%cartao%'")
                        ->orWhereRaw("LOWER(gateway) LIKE '%cartão%'")
                        ->orWhereRaw("LOWER(gateway) LIKE '%credito%'")
                        ->orWhereIn('metadata->checkout_payment_method', ['card', 'apple_pay', 'google_pay']);
                });
            } elseif ($m === 'boleto') {
                $query->where(function ($q) {
                    $q->where('gateway', 'boleto')
                        ->orWhereRaw("LOWER(gateway) LIKE '%boleto%'")
                        ->orWhere('metadata->checkout_payment_method', 'boleto');
                });
            }
        }

        $orders = $query->orderBy('id')->limit(200)->get();

        $pdf = new Fpdi('P', 'mm', 'A4');

        foreach ($orders as $order) {
            $doc = ProofDocument::query()->where('order_id', $order->id)->first();
            if (! $doc) {
                $doc = $this->proofService->issueForOrder($order, $request->user());
            }

            $this->pdfRenderer->renderOrderDossier($pdf, $order, $doc);
        }

        $content = $pdf->Output('S');
        $filename = 'comprovacoes-' . $from->format('Y-m-d') . '_ate_' . $to->format('Y-m-d') . '.pdf';

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    private function parseExportDate(string $value): \Illuminate\Support\Carbon
    {
        $v = trim($value);
        try {
            // Prefer ISO (YYYY-MM-DD) from the frontend
            if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $v)) {
                return \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $v);
            }
            // Accept pt-BR dd/mm/yyyy
            if (preg_match('/^\\d{2}\\/\\d{2}\\/\\d{4}$/', $v)) {
                return \Illuminate\Support\Carbon::createFromFormat('d/m/Y', $v);
            }
            return \Illuminate\Support\Carbon::parse($v);
        } catch (\Throwable) {
            abort(422, 'Data inválida. Use dd/mm/aaaa.');
        }
    }
}

