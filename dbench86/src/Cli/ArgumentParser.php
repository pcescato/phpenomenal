<?php
declare(strict_types=1);

namespace DBench86\Cli;

class ArgumentParser
{
    private const DEFAULT_DATABASE = 'all';
    private const DEFAULT_ROWS = 100_000;
    private const DEFAULT_RUNS = 30;
    private const DEFAULT_WARMUP = 5;
    private const DEFAULT_BATCH_SIZE = 100;
    private const DEFAULT_TIMEOUT = 30;
    private const DEFAULT_SORT = 'median';
    private const DEFAULT_DIRECTION = 'asc';

    public function parse(array $argv): array
    {
        $parsed = [
            'command' => null,
            'database' => self::DEFAULT_DATABASE,
            'rows' => self::DEFAULT_ROWS,
            'runs' => self::DEFAULT_RUNS,
            'warmup' => self::DEFAULT_WARMUP,
            'batch_size' => self::DEFAULT_BATCH_SIZE,
            'timeout' => self::DEFAULT_TIMEOUT,
            'sort' => self::DEFAULT_SORT,
            'direction' => self::DEFAULT_DIRECTION,
            'debug' => false,
            'help' => false,
        ];

        // Skip program name
        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];

            if ($arg === '--help' || $arg === '-h') {
                $parsed['help'] = true;
                continue;
            }

            if ($arg === '--debug') {
                $parsed['debug'] = true;
                continue;
            }

            if (strpos($arg, '--') === 0) {
                if (strpos($arg, '=') === false) {
                    throw new \InvalidArgumentException("Option sans valeur : {$arg}");
                }

                [$name, $value] = explode('=', $arg, 2);
                $name = substr($name, 2);

                if ($name === '') {
                    throw new \InvalidArgumentException("Option invalide : {$arg}");
                }

                $keyName = strtr($name, '-', '_');

                if (!in_array($keyName, ['database', 'rows', 'runs', 'warmup', 'batch_size', 'timeout', 'sort', 'direction'], true)) {
                    throw new \InvalidArgumentException("Option inconnue : --{$name}");
                }

                $parsed[$keyName] = $value;
            } else {
                // Positional argument (command)
                if ($parsed['command'] !== null || $arg !== 'run') {
                    throw new \InvalidArgumentException("Commande inconnue : {$arg}");
                }
                $parsed['command'] = $arg;
            }
        }

        return $parsed;
    }
}
