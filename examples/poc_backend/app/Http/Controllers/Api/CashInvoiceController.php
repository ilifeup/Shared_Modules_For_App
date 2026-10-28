<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payment\CashInvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CashInvoiceController extends Controller
{
    public function refund(Request $request, int $id, CashInvoiceService $cash): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        return new JsonResponse(['data' => $cash->recordManualRefund($id, $request->user(), $data['reason'])]);
    }

    public function addRange(Request $request, \App\Services\Payment\InvoiceNumberAllocator $numbers): JsonResponse
    {
        $data = $request->validate([
            'start' => ['required', 'string', 'max:10'],
            'end' => ['required', 'string', 'max:10'],
            'period' => ['required', 'string', 'size:6'],
        ]);
        return new JsonResponse(['data' => ['id' => $numbers->addRange($data['start'], $data['end'], $data['period'], $request->user())]], 201);
    }

    public function allocate(Request $request, int $id, \App\Services\Payment\InvoiceNumberAllocator $numbers): JsonResponse
    {
        return new JsonResponse(['data' => ['invoice_no' => $numbers->allocate($id, $request->user())]]);
    }

    public function confirm(Request $request, int $id, CashInvoiceService $cash): JsonResponse
    {
        return new JsonResponse(['data' => $cash->confirmCash($id, $request->user())]);
    }
}
