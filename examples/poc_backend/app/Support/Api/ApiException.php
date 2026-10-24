<?php

namespace App\Support\Api;

use RuntimeException;

/**
 * An expected, client-facing API error rendered by ApiError as
 * {"error": {"code", "message", "fields"?, "request_id"}}.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>  $fields
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $fields = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}
