<?php

namespace App\Services;

use Symfony\Component\Clock\ClockInterface;

final class CueChatDifyTurn
{
    private const DEADLINE_MICROSECONDS = 14_000_000;

    private const RETRY_DELAY_MICROSECONDS = 250_000;

    private int $startedAt;

    private bool $retried = false;

    public function __construct(private ClockInterface $clock)
    {
        $this->startedAt = $this->now();
    }

    public function remaining(): float
    {
        return max(0, self::DEADLINE_MICROSECONDS - $this->now() + $this->startedAt) / 1_000_000;
    }

    public function waitForRetry(): bool
    {
        if ($this->retried || $this->remaining() < 2 + self::RETRY_DELAY_MICROSECONDS / 1_000_000) {
            return false;
        }

        $this->retried = true;
        $this->clock->sleep(self::RETRY_DELAY_MICROSECONDS / 1_000_000);

        return $this->remaining() >= 2;
    }

    private function now(): int
    {
        return (int) $this->clock->now()->format('Uu');
    }
}
