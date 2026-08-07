<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Models\Concerns;

use Illuminate\Support\Str;

trait HasPublicUuid
{
    protected static function bootHasPublicUuid(): void
    {
        static::creating(static function ($model): void {
            $model->public_id ??= (string) Str::uuid();
        });
    }
}
