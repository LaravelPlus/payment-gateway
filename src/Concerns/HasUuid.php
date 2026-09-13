<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Concerns;

use Illuminate\Support\Str;

/**
 * Public identity that is not the primary key: uuid is generated on create and
 * used as the route key, and the auto-increment id never leaves the server.
 */
trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::creating(function ($model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function initializeHasUuid(): void
    {
        if (!in_array('id', $this->hidden, true)) {
            $this->hidden[] = 'id';
        }
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
