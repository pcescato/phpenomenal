<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

exit(\DBench86\Cli\Launcher::run($argv, __DIR__));
