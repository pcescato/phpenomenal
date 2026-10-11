<?php

declare(strict_types=1);

// Dependency-free integration tests. Every assertion invokes the real CLI.
// Individual fixtures normally exit 1 because other mandatory features are absent.
$checker = $argv[1] ?? dirname(__DIR__) . '/check-php86-features.php';
$fixtures = [];
$failures = 0;
$assertions = 0;

function runCommand(array $command): array
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start: ' . implode(' ', $command));
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout . $stderr];
}

function checkAssertion(bool $passed, string $label, string $detail = ''): void
{
    global $assertions, $failures;
    $assertions++;
    echo ($passed ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$passed) {
        $failures++;
        echo $detail . PHP_EOL;
    }
}

function checkFixture(string $label, string $source, array $expected, ?int $expectedExit = null, bool $validSyntax = true): void
{
    global $checker, $fixtures;
    $path = tempnam(sys_get_temp_dir(), 'php86-feature-');
    if ($path === false) {
        throw new RuntimeException('Could not create temporary fixture');
    }
    $fixtures[] = $path;
    if (file_put_contents($path, "<?php\n" . $source . "\n") === false) {
        throw new RuntimeException('Could not write fixture: ' . $label);
    }
    [$lintExit, $lintOutput] = runCommand([PHP_BINARY, '-l', $path]);
    checkAssertion(($lintExit === 0) === $validSyntax, "$label / " . ($validSyntax ? 'syntax' : 'expected PHP rejection (lexical fixture)'), $lintOutput);
    [$exit, $output] = runCommand([PHP_BINARY, $checker, $path]);
    $statuses = [];
    foreach (explode("\n", $output) as $line) {
        if (preg_match('/^  \[([x ])\] (.*?)\s+(?:\(line \d+\)|MISSING)/u', $line, $matches)) {
            $statuses[$matches[2]] = $matches[1] === 'x';
        }
    }
    checkAssertion(count($statuses) === 7, "$label / seven status lines", $output);
    foreach ($expected as $feature => $used) {
        checkAssertion(isset($statuses[$feature]) && $statuses[$feature] === $used, "$label / $feature", $output);
    }
    checkAssertion($exit === ($expectedExit ?? 1), "$label / CLI exit", "Exit: $exit\n$output");
    if ($expectedExit === 0) {
        checkAssertion(str_contains($output, 'All 7 PHP 8.6 features are used.'), "$label / success message", $output);
        // This complete fixture is self-contained and must also execute on PHP 8.6.
        [$executionExit, $executionOutput] = runCommand([PHP_BINARY, $path]);
        checkAssertion($executionExit === 0, "$label / execution", $executionOutput);
    }
}

$duration = 'Time\\Duration';
$override = '#[\\Override] on a constant';
$writes = 'writes on const-held objects';
$all = array_fill_keys([
    'Partial Function Application', $duration, 'clamp()', $override, $writes,
    'readonly property defaults', 'SortDirection enum',
], true);

try {
    foreach ([
        'qualified call' => 'Time\Duration::fromMilliseconds(50);',
        'fully qualified call' => '\Time\Duration::fromMilliseconds(50);',
        'case insensitive call' => '\tImE\dUrAtIoN::fromMilliseconds(50);',
        'qualified type' => 'function waitFor(Time\Duration $duration): void {}',
        'fully qualified type' => 'function waitFor(\Time\Duration $duration): void {}',
        'qualified construction' => '$duration = new Time\Duration();',
        'fully qualified construction' => '$duration = new \Time\Duration();',
    ] as $label => $source) {
        checkFixture("duration / $label", $source, [$duration => true]);
    }
    foreach ([
        'comment' => '// \Time\Duration::fromMilliseconds(50);',
        'string' => '$text = "\\\\Time\\\\Duration::fromMilliseconds(50)";',
        'similar prefix' => 'OtherTime\Duration::fromMilliseconds(50);',
        'vendor prefix' => 'Vendor\Time\Duration::fromMilliseconds(50);',
        'similar suffix' => '\Time\DurationExtra::fromMilliseconds(50);',
        'ordinary expression' => '$duration = 50;',
    ] as $label => $source) {
        checkFixture("duration / $label", $source, [$duration => false]);
    }

    foreach ([
        'original bare const' => '#[\Override] const LIMIT = 100;',
        'public untyped' => '#[\Override] public const LIMIT = 100;',
        'public typed' => '#[\Override] public const int LIMIT = 100;',
        'enum typed' => '#[\Override] public const CommandName COMMAND = CommandName::NumberPrinter;',
        'protected' => '#[\Override] protected const int LIMIT = 100;',
        'final public' => '#[\Override] final public const int LIMIT = 100;',
        'public final' => '#[\Override] public final const int LIMIT = 100;',
        'unqualified name' => '#[Override] public const int LIMIT = 100;',
        'case insensitive name' => '#[\oVeRrIdE] public const int LIMIT = 100;',
        'multiple groups after' => '#[\Override] #[Extra([1, 2])] public const int LIMIT = 100;',
        'multiple groups before' => '#[Extra] #[\Override] public const int LIMIT = 100;',
        'grouped attributes' => '#[Extra([1, 2]), \Override] public const int LIMIT = 100;',
        'comments between modifiers' => '#[\Override] /* comment */ final /* comment */ public const int LIMIT = 100;',
    ] as $label => $source) {
        $parent = 'enum CommandName { case NumberPrinter; } class ParentExample { protected const LIMIT = 1; public const CommandName COMMAND = CommandName::NumberPrinter; }';
        checkFixture("override / $label", "$parent class Example extends ParentExample { $source }", [$override => true]);
    }
    // This fixture intentionally tests lexical detection, not semantic PHP validity.
    // The compiler may reject #[\Override] private const depending on the inheritance context.
    checkFixture('override / private lexical modifier', 'class Example { #[\Override] private const int LIMIT = 100; }', [$override => true], null, false);
    foreach ([
        'comment' => 'class Example { /* #[\Override] */ public const LIMIT = 100; }',
        'string' => '$text = "#[\\\\Override] public const LIMIT = 100;";',
        'similar name' => 'class Example { #[MyOverride] public const LIMIT = 100; }',
        'qualified unrelated name' => 'class Example { #[Vendor\Override] public const LIMIT = 100; }',
        'argument name' => 'class Example { #[Extra(Override::class)] public const LIMIT = 100; }',
        'argument string' => 'class Example { #[Extra("Override")] public const LIMIT = 100; }',
        'argument nested array' => 'class Example { #[Extra([1, Override::class])] public const LIMIT = 100; }',
        'method' => 'class ParentExample { public function method(): void {} } class Example extends ParentExample { #[\Override] public function method(): void {} public const LIMIT = 100; }',
        'property' => 'class ParentExample { public int $number; } class Example extends ParentExample { #[\Override] public int $number; public const LIMIT = 100; }',
        'class' => '#[\Override] class Example { public const LIMIT = 100; }',
        'unrelated declaration' => '#[\Override] function example(): void {} const LIMIT = 100;',
        'ordinary constant' => 'class Example { public const LIMIT = 100; }',
    ] as $label => $source) {
        checkFixture("override / $label", $source, [$override => false], null, !in_array($label, ['class', 'unrelated declaration'], true));
    }

    foreach ([
        'postfix increment' => 'HOLDER->number++;',
        'postfix decrement' => 'HOLDER->number--;',
        'assignment' => 'HOLDER->number = 42;',
        'compound assignment' => 'HOLDER->number += 1;',
        'prefix increment' => '++HOLDER->number;',
        'prefix decrement' => '--HOLDER->number;',
        'assigned prefix' => '$next = ++NEXT_NUMBER_HOLDER->number;',
        'comments in prefix' => '++ /* comment */ HOLDER /* comment */ ->number;',
        'qualified constant' => '++Vendor\HOLDER->number;',
        'fully qualified constant' => '\HOLDER->number++;',
        'relative constant' => 'namespace Example; --namespace\HOLDER->number;',
    ] as $label => $source) {
        checkFixture("writes / $label", $source, [$writes => true]);
    }
    foreach ([
        'comment' => '// ++HOLDER->number;',
        'string' => '$text = "++HOLDER->number";',
        'read' => '$value = HOLDER->number;',
        'variable postfix' => '$object->number++;',
        'variable prefix' => '++$object->number;',
        'method call' => 'HOLDER->increment();',
        'prefix method call' => '++HOLDER->increment();',
        'variable chain' => '$object->HOLDER->number++;',
        'nullsafe chain' => '$object?->HOLDER->number++;',
        'static chain' => 'Example::HOLDER->number++;',
        'prefix property chain' => '++HOLDER->child->number;',
        'prefix array dimension' => '++HOLDER->items[0];',
        'construction' => 'new HOLDER->number++;',
        'ordinary expression' => '++$number;',
    ] as $label => $source) {
        checkFixture("writes / $label", $source, [$writes => false], null, !in_array($label, ['prefix method call', 'nullsafe chain', 'construction'], true));
    }

    foreach ([
        'partial application' => ['strlen(?);', 'Partial Function Application'],
        'clamp call' => ['clamp(5, 0, 10);', 'clamp()'],
        'readonly default' => ['class Example { public readonly int $number = 1; }', 'readonly property defaults'],
        'sort direction reference' => ['SortDirection::Ascending;', 'SortDirection enum'],
        'sort direction declaration' => ['namespace Example; enum SortDirection { case Asc; }', 'SortDirection enum'],
    ] as $label => [$source, $feature]) {
        checkFixture("other detectors / $label", $source, [$feature => true]);
    }
    checkFixture('other detectors / ordinary expressions', '$value = $condition ? 1 : 2; strlen("test"); class Example { public int $number = 1; }', array_fill_keys(array_keys($all), false));
    checkFixture('all features / comments only', '/* strlen(?); \Time\Duration::fromMilliseconds(50); clamp(5, 0, 10); #[\Override] public const LIMIT = 100; ++HOLDER->number; readonly int $number = 1; SortDirection::Ascending; */', array_fill_keys(array_keys($all), false));
    checkFixture('all features / strings only', <<<'SOURCE'
$text = 'strlen(?); \Time\Duration::fromMilliseconds(50); clamp(5, 0, 10); #[\Override] public const LIMIT = 100; ++HOLDER->number; readonly int $number = 1; SortDirection::Ascending;';
SOURCE, array_fill_keys(array_keys($all), false));

    $complete = <<<'SOURCE'
enum CommandName { case NumberPrinter; }
class BaseCommand { public const CommandName COMMAND = CommandName::NumberPrinter; }
class NumberPrinter extends BaseCommand {
    #[\Override]
    public const CommandName COMMAND = CommandName::NumberPrinter;
    public readonly int $limit = 100;
}
class NumberHolder { public int $number = 0; }
const NEXT_NUMBER_HOLDER = new NumberHolder();
$delay = 50;
\Time\Duration::fromMilliseconds($delay);
$next = ++NEXT_NUMBER_HOLDER->number;
$length = strlen(?);
$bounded = clamp($next, 0, 100);
$direction = SortDirection::Ascending;
SOURCE;
    checkFixture('complete seven-feature FizzBuzz syntax', $complete, $all, 0);
} catch (Throwable $error) {
    checkAssertion(false, 'runner exception', $error->getMessage());
} finally {
    foreach ($fixtures as $path) {
        unlink($path);
    }
}
echo "$assertions assertions, $failures failures." . PHP_EOL;
exit($failures === 0 ? 0 : 1);
