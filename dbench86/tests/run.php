<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/TestRunner.php';
require __DIR__ . '/TestCase.php';
require __DIR__ . '/DatabaseCredentials.php';

use Tests\TestRunner;

$runner = TestRunner::getInstance();
exit($runner->run());
