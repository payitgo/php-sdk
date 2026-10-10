<?php

declare(strict_types=1);

namespace Payitgo\Exception;

/** The API refused the request. getCode() is the HTTP status, or 0 for a network error. */
class PayitgoException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message, $status);
    }
}
