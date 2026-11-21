<?php

namespace App\Exceptions;

use RuntimeException;

class ApiException extends RuntimeException
{
    /**
     * @param  array<string, string>  $errors
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    public static function validation(string $field, string $messageKey, array $replace = []): self
    {
        $msg = __($messageKey, $replace);

        return new self(422, 'VALIDATION', $msg, [$field => $msg]);
    }

    public static function forbidden(string $messageKey = 'api.forbidden', array $replace = []): self
    {
        return new self(403, 'FORBIDDEN', __($messageKey, $replace));
    }

    public static function notFound(string $messageKey = 'api.not_found', array $replace = []): self
    {
        return new self(404, 'NOT_FOUND', __($messageKey, $replace));
    }

    public static function badState(string $messageKey, array $replace = []): self
    {
        return new self(409, 'BAD_STATE', __($messageKey, $replace));
    }

    public static function rateLimited(string $messageKey = 'api.rate_limited', array $replace = []): self
    {
        return new self(429, 'RATE_LIMITED', __($messageKey, $replace));
    }
}
