<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payment\CashInvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CashInvoiceController extends Controller
{
    public function confirm(Request $request, int $id, CashInvoiceService $cash): JsonResponse
    {
        return new JsonResponse(['data' => $cash->confirmCash($id, $request->user())]);
    }
}
