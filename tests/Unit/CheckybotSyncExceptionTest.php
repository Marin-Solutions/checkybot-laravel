<?php

use MarinSolutions\CheckybotLaravel\Exceptions\CheckybotSyncException;

it('can create exception with message', function () {
    $exception = new CheckybotSyncException('Sync failed');

    expect($exception)->toBeInstanceOf(CheckybotSyncException::class)
        ->and($exception->getMessage())->toBe('Sync failed');
});

it('can create exception with previous exception', function () {
    $previous = new Exception('Original error');
    $exception = new CheckybotSyncException('Sync failed', 422, $previous);

    expect($exception->getPrevious())->toBe($previous)
        ->and($exception->getCode())->toBe(422);
});
