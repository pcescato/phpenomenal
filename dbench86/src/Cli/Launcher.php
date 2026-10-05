<?php
declare(strict_types=1);

namespace DBench86\Cli;

use DBench86\Application;
use DBench86\Build\MonolithBuilder;

/** Separate build dispatch from benchmark argument parsing and execution. */
final class Launcher
{
    public static function run(array $argv, string $projectDirectory): int
    {
        if (in_array('--build-monolith', array_slice($argv, 1), true)) {
            if (count($argv) !== 2) {
                fwrite(STDERR, "Error: --build-monolith must be used alone.\n");
                return 1;
            }
            try {
                (new MonolithBuilder($projectDirectory))->write($projectDirectory . '/dist/dbench86-monolith.php');
                echo "Built dist/dbench86-monolith.php\n";
                return 0;
            } catch (\Throwable $error) {
                fwrite(STDERR, 'Build error: ' . $error->getMessage() . "\n");
                return 1;
            }
        }
        return (new Application())->run($argv);
    }
}
