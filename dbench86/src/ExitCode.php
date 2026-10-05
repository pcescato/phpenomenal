<?php
declare(strict_types=1);

namespace DBench86;

enum ExitCode: int
{
    case Success = 0;
    case GenericFailure = 1;
    case InvalidArguments = 2;
    case RuntimePrerequisitesFailed = 3;
    case DatabaseConnectionFailed = 4;
    case BenchmarkPreparationFailed = 5;
    case BenchmarkExecutionFailed = 6;
}
