<?php
declare(strict_types=1);

namespace DBench86\Benchmark;

use DBench86\Statistics\Statistics;

/** Untimed accounting for one workload; latency samples contain measured successes only. */
final class WorkloadResult
{
    public int $performed = 0;
    public int $successful = 0;
    public int $failed = 0;
    public int $timedOut = 0;
    public int $warmupPerformed = 0;
    public int $warmupFailed = 0;
    public int $warmupTimedOut = 0;
    public array $durations = [];
    public array $errors = [];

    public function __construct(public readonly int $requested) {}

    public function record(WorkloadMeasurement $measurement, bool $warmup = false): void
    {
        if ($warmup) {
            $this->warmupPerformed++;
            if ($measurement->failed) {
                $this->warmupFailed++;
            } elseif ($measurement->timedOut) {
                $this->warmupTimedOut++;
            }
            return;
        }

        $this->performed++;
        // A thrown execution is a failure even if a synthetic measurement has both flags.
        if ($measurement->failed) {
            $this->failed++;
        } elseif ($measurement->timedOut) {
            $this->timedOut++;
        } else {
            $this->successful++;
            $this->durations[] = $measurement->durationMilliseconds();
        }
    }

    /** No latency statistic is defined when there are no successful measured samples. */
    public function statistics(): ?array
    {
        if ($this->durations === []) {
            return null;
        }
        return [
            'min' => Statistics::minimum($this->durations),
            'median' => Statistics::median($this->durations),
            'p95' => Statistics::p95($this->durations),
            'max' => Statistics::maximum($this->durations),
        ];
    }

    public function hasFailures(): bool
    {
        return $this->performed !== $this->requested || $this->failed > 0
            || $this->timedOut > 0 || $this->warmupFailed > 0
            || $this->warmupTimedOut > 0 || $this->errors !== [];
    }
}
