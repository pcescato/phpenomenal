<?php
declare(strict_types=1);

namespace DBench86\Exception;

use DBench86\ExitCode;

abstract class DBench86Exception extends \Exception
{
    abstract public function exitCode(): ExitCode;
}
