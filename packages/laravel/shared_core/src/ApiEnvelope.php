<?php

declare(strict_types=1);

namespace EachCore\SharedCore;

final class ApiEnvelope
{
    /** @return array<string, mixed> */
    public static function success(mixed $data, ?array $meta = null): array
    {
        $result = ['data' => $data];
        if ($meta !== null) {
            $result['meta'] = $meta;
        }
        return $result;
    }

    /** @return array<string, mixed> */
    public static function failure(string $code, string $message, string $requestId, ?array $fields = null): array
    {
        $error = ['code' => $code, 'message' => $message, 'request_id' => $requestId];
        if ($fields !== null) {
            $error['fields'] = $fields;
        }
        return ['error' => $error];
    }
}
