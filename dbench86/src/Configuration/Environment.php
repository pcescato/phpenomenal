<?php
declare(strict_types=1);

namespace DBench86\Configuration;

/** Load optional runtime configuration; its contents are never build inputs. */
final class Environment
{
    public static function load(string $directory): void
    {
        // Load environment variables from .env file
        $envFile = $directory . '/.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                // Skip comments
                if (strpos(trim($line), '#') === 0) {
                    continue;
                }

                // Parse KEY=VALUE
                if (strpos($line, '=') !== false) {
                    [$key, $value] = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);

                    // Only set if not already set in environment
                    if (!getenv($key)) {
                        putenv("{$key}={$value}");
                        $_ENV[$key] = $value;
                    }
                }
            }
        }
    }
}
