<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Exceptions;

use InvalidArgumentException;

/**
 * A webhook body that is authentic but not a well-formed provider event.
 *
 * Permanent: redelivering the same bytes cannot fix it, so the controller
 * answers 4xx instead of 5xx and the provider stops retrying.
 */
final class InvalidWebhookPayloadException extends InvalidArgumentException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
