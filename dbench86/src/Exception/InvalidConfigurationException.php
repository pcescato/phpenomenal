<?php
declare(strict_types=1);

namespace DBench86\Exception;

use DBench86\ExitCode;

class InvalidConfigurationException extends DBench86Exception
{
    public function exitCode(): ExitCode
    {
        return ExitCode::InvalidArguments;
    }
}
