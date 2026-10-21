<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\TransactionBeginning;

/**
 * For test processes that race on one SQLite file.
 *
 * A deferred transaction reads first and upgrades to a write lock later. When
 * two processes do that at once, SQLite answers one of them with "database is
 * locked" at once, without waiting, and Laravel's queue (pop, delete) or an
 * action loses its work. Taking the write lock right after BEGIN makes the
 * second process wait for the first, like row locks on MySQL. A write that
 * changes nothing takes the lock on every PHP and Laravel version.
 */
final class SqliteWriteLockOnBegin
{
    private const BUSY_TIMEOUT_MILLISECONDS = 15_000;

    public static function register(Application $app, string $table = 'jobs'): void
    {
        $app['events']->listen(TransactionBeginning::class, static function (TransactionBeginning $event) use ($table): void {
            $connection = $event->connection;
            if ($connection->getDriverName() !== 'sqlite' || $connection->transactionLevel() !== 1) {
                return;
            }
            $pdo = $connection->getPdo();
            $pdo->exec('PRAGMA busy_timeout = '.self::BUSY_TIMEOUT_MILLISECONDS);
            $pdo->exec('DELETE FROM "'.$table.'" WHERE 0');
        });
    }
}
