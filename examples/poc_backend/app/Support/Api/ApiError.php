<?php

namespace App\Support\Api;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Unified API error envelope:
 * {"error": {"code", "message", "fields"?, "request_id"}}.
 *
 * Internal exception messages are never returned to the client.
 */
final class ApiError
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->dontReport(ApiException::class);

        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*') || $e instanceof HttpResponseException) {
                return null;
            }

            return self::fromThrowable($e, $request);
        });
    }

    public static function fromThrowable(Throwable $e, Request $request): JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => self::response($request, $e->status, $e->errorCode, $e->getMessage(), $e->fields)
                ->withHeaders($e->headers),
            $e instanceof ValidationException => self::response($request, 422, 'VALIDATION_FAILED', '輸入資料有誤。', $e->errors()),
            $e instanceof AuthenticationException => self::response($request, 401, 'UNAUTHENTICATED', '請先登入。'),
            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => self::response($request, 403, 'FORBIDDEN', '沒有權限執行此操作。'),
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => self::response($request, 404, 'NOT_FOUND', '找不到指定的資源。'),
            $e instanceof MethodNotAllowedHttpException => self::response($request, 405, 'METHOD_NOT_ALLOWED', '不支援此請求方式。'),
            $e instanceof ThrottleRequestsException => self::response($request, 429, 'RATE_LIMITED', '請求過於頻繁，請稍後再試。')
                ->withHeaders($e->getHeaders()),
            $e instanceof HttpExceptionInterface => self::response($request, $e->getStatusCode(), 'HTTP_ERROR', '請求無法完成。')
                ->withHeaders($e->getHeaders()),
            default => self::response($request, 500, 'SERVER_ERROR', '伺服器發生錯誤，請稍後再試。'),
        };
    }

    /**
     * @param  array<string, array<int, string>>  $fields
     */
    public static function response(Request $request, int $status, string $code, string $message, array $fields = []): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        $error['request_id'] = AssignRequestId::for($request);

        return new JsonResponse(['error' => $error], $status);
    }
}
