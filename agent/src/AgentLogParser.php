<?php

declare(strict_types=1);

namespace Checkybot\AgentV2;

use DateTimeImmutable;

final class AgentLogParser
{
    /**
     * @param  list<string>  $statusLines  Lines in `pool|active|max_children` form.
     * @param  list<string>  $logLines  Lines containing an ISO timestamp, pool name and `server reached pm.max_children`.
     * @return list<array{pool:string,active_workers:int,max_children:int,max_children_reached_5m:int}>
     */
    public function phpFpmPools(array $statusLines, array $logLines, DateTimeImmutable $now): array
    {
        $reached = [];
        $cutoff = $now->modify('-300 seconds');
        foreach ($logLines as $line) {
            if (! preg_match('/^(\S+)\s+\[pool\s+([^\]]+)]\s+.*server reached pm\.max_children/iu', $line, $matches)) {
                continue;
            }
            try {
                $observedAt = new DateTimeImmutable($matches[1]);
            } catch (\Throwable) {
                continue;
            }
            if ($observedAt >= $cutoff && $observedAt <= $now) {
                $reached[$matches[2]] = ($reached[$matches[2]] ?? 0) + 1;
            }
        }

        $pools = [];
        foreach ($statusLines as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) !== 3 || $parts[0] === '' || ! ctype_digit($parts[1]) || ! ctype_digit($parts[2])) {
                continue;
            }
            $pools[] = [
                'pool' => $parts[0],
                'active_workers' => (int) $parts[1],
                'max_children' => (int) $parts[2],
                'max_children_reached_5m' => $reached[$parts[0]] ?? 0,
            ];
        }

        return $pools;
    }

    /**
     * @param  list<string>  $accessLines  Lines in `RFC3339|status` form.
     * @param  list<string>  $errorLines  Lines prefixed by RFC3339.
     * @return array{total_requests:int,five_xx_count:int,upstream_timeout_count:int}
     */
    public function nginxWindow(array $accessLines, array $errorLines, DateTimeImmutable $now): array
    {
        $cutoff = $now->modify('-300 seconds');
        $total = 0;
        $fiveXx = 0;
        foreach ($accessLines as $line) {
            $parts = explode('|', $line, 3);
            if (count($parts) < 2 || ! ctype_digit(trim($parts[1]))) {
                continue;
            }
            try {
                $observedAt = new DateTimeImmutable(trim($parts[0]));
            } catch (\Throwable) {
                continue;
            }
            if ($observedAt < $cutoff || $observedAt > $now) {
                continue;
            }
            $total++;
            $status = (int) trim($parts[1]);
            if ($status >= 500 && $status <= 599) {
                $fiveXx++;
            }
        }

        $timeouts = 0;
        foreach ($errorLines as $line) {
            if (! str_contains(strtolower($line), 'upstream timed out')) {
                continue;
            }
            $timestamp = strtok($line, ' ');
            try {
                $observedAt = new DateTimeImmutable((string) $timestamp);
            } catch (\Throwable) {
                continue;
            }
            if ($observedAt >= $cutoff && $observedAt <= $now) {
                $timeouts++;
            }
        }

        return [
            'total_requests' => $total,
            'five_xx_count' => $fiveXx,
            'upstream_timeout_count' => $timeouts,
        ];
    }
}
