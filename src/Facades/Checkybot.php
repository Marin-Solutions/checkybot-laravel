<?php

namespace MarinSolutions\CheckybotLaravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \MarinSolutions\CheckybotLaravel\Checkybot
 */
class Checkybot extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \MarinSolutions\CheckybotLaravel\Checkybot::class;
    }
}
