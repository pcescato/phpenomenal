<?php
declare(strict_types=1);

namespace DBench86\Configuration;

use SortDirection;
use InvalidArgumentException;

class BenchmarkConfiguration
{
    public readonly string $database;
    public readonly int $rows;
    public readonly int $runs;
    public readonly int $warmup;
    public readonly int $batchSize;
    public readonly int $timeout;
    public readonly string $sort;
    public readonly SortDirection $direction;
    public readonly bool $debug;

    public function __construct(array $parsed)
    {
        $this->database = $this->validateDatabase($parsed['database']);
        $this->rows = $this->validatePositiveInt($parsed['rows'], 'rows');
        $this->runs = $this->validatePositiveInt($parsed['runs'], 'runs');
        $this->warmup = $this->validateNonNegativeInt($parsed['warmup'], 'warmup');
        if ($this->warmup > PHP_INT_MAX - $this->runs) {
            throw new InvalidArgumentException('Combined warmup + runs exceeds the supported integer execution count');
        }
        $this->batchSize = $this->validatePositiveInt($parsed['batch_size'], 'batch-size');
        $this->timeout = $this->validateTimeout($parsed['timeout']);
        $this->sort = $this->validateSort($parsed['sort']);
        $this->direction = $this->validateDirection($parsed['direction']);
        $this->debug = $parsed['debug'];
    }

    private function validateDatabase(string $value): string
    {
        $allowed = ['all', 'mariadb', 'postgresql', 'sqlite'];
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(
                "Valeur invalide pour --database : {$value}. Valeurs acceptées : " . implode(', ', $allowed)
            );
        }
        return $value;
    }

    private function validateTimeout(mixed $value): int
    {
        $raw = trim((string) $value);
        if ((!is_int($value) && !is_string($value)) || preg_match('/\A[0-9]+\z/', $raw) !== 1) {
            throw new InvalidArgumentException(
                "--timeout doit être un nombre entier de secondes, valeur reçue : '{$raw}'"
            );
        }

        // Check the range as text before casting, so oversized integers cannot be saturated.
        $digits = ltrim($raw, '0');
        $maximum = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($maximum)
            || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            throw new InvalidArgumentException(
                "--timeout dépasse le nombre maximal de secondes ({$maximum}), valeur reçue : '{$raw}'"
            );
        }

        return $this->validatePositiveInt($raw, 'timeout');
    }

    private function validatePositiveInt(mixed $value, string $name): int
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException("--{$name} doit être numérique, reçu : {$value}");
        }
        $int = (int)$value;
        if ($int <= 0) {
            throw new InvalidArgumentException("--{$name} doit être positif (> 0), reçu : {$value}");
        }
        return $int;
    }

    private function validateNonNegativeInt(mixed $value, string $name): int
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException("--{$name} doit être numérique, reçu : {$value}");
        }
        $int = (int)$value;
        if ($int < 0) {
            throw new InvalidArgumentException("--{$name} doit être >= 0, reçu : {$value}");
        }
        return $int;
    }

    private function validateSort(string $value): string
    {
        if (!in_array($value, ['min', 'median', 'p95', 'max'], true)) {
            throw new InvalidArgumentException("Valeur invalide pour --sort : {$value}. Valeurs acceptées : min, median, p95, max");
        }
        return $value;
    }

    private function validateDirection(string $value): SortDirection
    {
        return match ($value) {
            'asc' => SortDirection::Ascending,
            'desc' => SortDirection::Descending,
            default => throw new InvalidArgumentException(
                "Valeur invalide pour --direction : {$value}. Valeurs acceptées : asc, desc"
            ),
        };
    }
}
