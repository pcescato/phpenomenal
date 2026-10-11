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

function checkFixture(string $label, string $source, array $expected, ?int $expectedExit = null, bool $validSyntax = true, bool $execute = false): void
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
    }
    if ($expectedExit === 0 || $execute) {
        // Self-contained fixtures must also execute on PHP 8.6.
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
        'scoped prefix' => '++Container::HOLDER->number;',
        'scoped postfix' => 'Container::HOLDER->number++;',
        'scoped assignment' => 'Container::HOLDER->number = 42;',
        'scoped compound assignment' => 'Container::HOLDER->number += 1;',
        'qualified class scope' => '--Vendor\Container::HOLDER->number;',
        'fully qualified class scope' => '\Container::HOLDER->number--;',
        'relative class scope' => 'namespace Example; ++namespace\Container::HOLDER->number;',
        'self scope' => 'class Container { public function run(): void { ++self::HOLDER->number; } }',
        'parent scope' => 'class Base {} class Container extends Base { public function run(): void { --parent::HOLDER->number; } }',
        'static scope' => 'class Container { public function run(): void { static::HOLDER->number++; } }',
        'object scope' => '++$object::HOLDER->number;',
        'variable class scope' => '$class::HOLDER->number = 42;',
        'scoped comments' => '++Container /* comment */ :: /* comment */ HOLDER->number;',
        'nested prefix' => '++HOLDER->child->number;',
        'nested postfix' => 'HOLDER->child->number++;',
        'nested decrement' => '--HOLDER->child->number;',
        'nested assignment' => 'HOLDER->child->number = 42;',
        'nested compound assignment' => 'HOLDER->child->number += 1;',
        'deep scoped prefix' => '++Container::HOLDER->child->child->number;',
        'nested scoped postfix' => '$object::HOLDER->child->number--;',
        'nested scoped assignment' => 'Container::HOLDER->child->number = 42;',
        'nested comments' => '++HOLDER->child /* comment */ -> /* comment */ number;',
        'prefix in call' => 'print(++HOLDER->child->number);',
        'dimension prefix' => '++HOLDER->items[0]->number;',
        'dimension scoped assignment' => 'Container::HOLDER->items[0]->number = 1;',
        'dimension nested postfix' => 'HOLDER->child->items[0]->number++;',
        'multiple dimensions' => 'HOLDER->items[0][1]->number += 1;',
        'nested dimension expression' => 'HOLDER->items[$indices[0]]->number--;',
        'bracket in string index' => '++HOLDER->items["]"]->number;',
        'dimension comments' => '++HOLDER->items[/* ] */ 0 /* [ */]->number;',
        'dimension with call index' => 'HOLDER->items[indexOf([0])]->number = 1;',
        'direct foreach target' => 'foreach ([1] as HOLDER->number) {}',
        'scoped foreach target' => 'foreach ([1] as Container::HOLDER->number) {}',
        'dimension foreach target' => 'foreach ([1] as HOLDER->items[0]->number) {}',
        'reference foreach target' => 'foreach ([1] as &HOLDER->number) {}',
        'foreach key target' => 'foreach ([1 => 2] as HOLDER->number => $value) {}',
        'reference binding' => '$r = &HOLDER->number;',
        'scoped reference binding' => '$r = &$object::HOLDER->number;',
        'dimension reference binding' => '$r = &HOLDER->items[0]->number;',
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
        'prefix array dimension' => '++HOLDER->items[0];',
        'construction' => 'new HOLDER->number++;',
        'ordinary expression' => '++$number;',
        'scoped read' => '$value = Container::HOLDER->number;',
        'nested read' => '$value = HOLDER->child->number;',
        'scoped nested read' => '$value = $object::HOLDER->child->number;',
        'nested variable assignment' => '$object->child->number = 42;',
        'static property' => 'Container::$HOLDER->number++;',
        'variable static property' => '$object::$holder->number++;',
        'class pseudo constant' => 'Container::class->number++;',
        'scoped method' => 'Container::getHolder()->number++;',
        'nested method' => 'HOLDER->getChild()->number++;',
        'nested final method' => 'HOLDER->child->increment();',
        'scoped final method' => 'Container::HOLDER->increment();',
        'nested nullsafe read' => '$value = HOLDER->child?->number;',
        'scoped nullsafe read' => '$value = Container::HOLDER?->number;',
        'nested nullsafe write' => 'HOLDER->child?->number++;',
        'scoped nullsafe write' => '++Container::HOLDER?->number;',
        'comparison' => 'HOLDER->number == 42;',
        'nested comparison' => 'HOLDER->child->number === 42;',
        'scoped comparison' => 'Container::HOLDER->child->number >= 42;',
        'object scoped comparison' => '$object::HOLDER->number != 42;',
        'scoped comment' => '// ++Container::HOLDER->child->number;',
        'scoped string' => '$text = "Container::HOLDER->child->number++";',
        'array element postfix' => 'HOLDER->items[0]++;',
        'array element assignment' => 'Container::HOLDER->items[0] = 1;',
        'nested array element assignment' => 'HOLDER->items[0]->items[0] = 1;',
        'array element foreach target' => 'foreach ([1] as HOLDER->items[0]) {}',
        'array element reference' => '$r = &HOLDER->items[0];',
        'dimension read' => '$value = HOLDER->items[0]->number;',
        'dimension comparison' => 'HOLDER->items[0]->number === 1;',
        'dimension variable root' => '++$object->items[0]->number;',
        'dimension method' => 'HOLDER->items[0]->increment();',
        'dimension method chain' => 'HOLDER->items[0]->getChild()->number++;',
        'dimension nullsafe read' => '$value = HOLDER->items[0]?->number;',
        'dimension nullsafe write' => '++HOLDER->items[0]?->number;',
        'unbalanced dimension' => '++HOLDER->items[0->number;',
        'keyword read' => '$value = Status::DEFAULT->number;',
        'keyword method' => 'Status::DEFAULT->increment();',
        'keyword method constant' => 'Container::LIST()->number++;',
        'keyword comparison' => 'Container::LIST->number == 1;',
        'literal constant name' => 'Status::123->number++;',
        'quoted constant name' => "Status::'DEFAULT'->number++;",
        'keyword comment' => '// Status::DEFAULT->number++;',
        'keyword string' => '$text = "Status::DEFAULT->number++";',
        'bitwise and read' => '$r = $mask & HOLDER->number;',
        'reference parameter read' => 'function read(int &$r = HOLDER->number): void {}',
        'foreach iterable read' => 'foreach ([HOLDER->number] as $value) {}',
        'array literal read' => '$values = [HOLDER->number, $x];',
        'array literal key read' => '$values = [HOLDER->number => 1];',
    ] as $label => $source) {
        checkFixture("writes / $label", $source, [$writes => false], null, !in_array($label, ['prefix method call', 'nullsafe chain', 'construction', 'nested nullsafe write', 'scoped nullsafe write', 'class pseudo constant', 'dimension nullsafe write', 'unbalanced dimension', 'literal constant name', 'quoted constant name'], true));
    }

    checkFixture('writes / reserved halt compiler constant', 'Container::__halt_compiler->number++;', [$writes => false], null, false);

    foreach (['DEFAULT', 'LIST', 'MATCH', 'PUBLIC', 'FUNCTION', 'NEW', 'ARRAY', 'NAMESPACE', '__CLASS__'] as $name) {
        checkFixture("writes / keyword constant $name", "Container::{$name}->number++;", [$writes => true]);
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

    $scoped = <<<'SOURCE'
class Node {
    public int $number = 0;
    public function __construct(public ?Node $child = null) {}
}
const HOLDER = new Node(new Node());
class Container {
    public const HOLDER = HOLDER;
    public function run(): void {
        ++self::HOLDER->number;
        --static::HOLDER->child->number;
    }
}
class ChildContainer extends Container {
    public function runParent(): void { ++parent::HOLDER->number; }
}
++Container::HOLDER->number;
++HOLDER->child->number;
HOLDER->child->number++;
Container::HOLDER->child->number += 1;
$object = new Container();
++$object::HOLDER->number;
$class = Container::class;
$class::HOLDER->child->number = 42;
$object->run();
(new ChildContainer())->runParent();
if (HOLDER->number !== 4 || HOLDER->child->number !== 41) {
    throw new RuntimeException('Unexpected scoped/nested property values');
}
SOURCE;
    checkFixture('executable scoped and nested writes', $scoped, [$writes => true], null, true, true);

    $writeSetup = <<<'SOURCE'
class Node {
    public int $number = 0;
    public function __construct(public array $items = [], public ?Node $child = null) {}
}
const HOLDER = new Node(
    [new Node(), [new Node()], 5, ']' => new Node(), 'key]' => new Node()],
    new Node([new Node()]),
);
class Container { public const HOLDER = HOLDER; public const LIST = HOLDER; public const ECHO = HOLDER; }
class Status { public const DEFAULT = HOLDER; }
SOURCE;
    // Each executable case checks its own mutation, without mixing detectors.
    foreach ([
        'dimension prefix' => ['++HOLDER->items[0]->number;', 'HOLDER->items[0]->number === 1'],
        'dimension scoped assignment' => ['Container::HOLDER->items[0]->number = 1;', 'HOLDER->items[0]->number === 1'],
        'dimension nested postfix' => ['HOLDER->child->items[0]->number++;', 'HOLDER->child->items[0]->number === 1'],
        'multiple dimensions' => ['HOLDER->items[1][0]->number += 2;', 'HOLDER->items[1][0]->number === 2'],
        'nested dimension expression' => ['HOLDER->items[[1][0]][0]->number++;', 'HOLDER->items[1][0]->number === 1'],
        'quoted closing bracket' => ['++HOLDER->items["]"]->number;', 'HOLDER->items["]"]->number === 1'],
        'interpolated closing bracket' => ['$key = "key"; ++HOLDER->items["$key]"]->number;', 'HOLDER->items["key]"]->number === 1'],
        'keyword DEFAULT' => ['Status::DEFAULT->number++;', 'HOLDER->number === 1'],
        'keyword LIST' => ['Container::LIST->number = 1;', 'HOLDER->number === 1'],
        'keyword ECHO postfix' => ['Container::ECHO->number++;', 'HOLDER->number === 1'],
        'keyword ECHO assignment' => ['Container::ECHO->number = 1;', 'HOLDER->number === 1'],
        'foreach target' => ['foreach ([1] as HOLDER->number) {}', 'HOLDER->number === 1'],
        'reference foreach target' => ['foreach ([1] as &HOLDER->number) {}', 'HOLDER->number === 1'],
        'foreach key target' => ['foreach ([4 => 5] as HOLDER->number => $value) {}', 'HOLDER->number === 4 && $value === 5'],
        'reference binding' => ['$r = &HOLDER->number; $r = 1;', 'HOLDER->number === 1'],
        'dimension reference binding' => ['$r = &HOLDER->items[0]->number; $r = 1;', 'HOLDER->items[0]->number === 1'],
    ] as $label => [$source, $condition]) {
        $verify = "\nif (!($condition)) { throw new RuntimeException('Unexpected property value'); }";
        checkFixture("executable writes / $label", $writeSetup . "\n" . $source . $verify, [$writes => true], null, true, true);
    }
    // Final array-element mutations are deliberately outside named-property detection.
    foreach ([
        'array element prefix' => ['++HOLDER->items[2];', 'HOLDER->items[2] === 6'],
        'array element assignment' => ['Container::HOLDER->items[2] = 1;', 'HOLDER->items[2] === 1'],
    ] as $label => [$source, $condition]) {
        $verify = "\nif (!($condition)) { throw new RuntimeException('Unexpected array element value'); }";
        checkFixture("executable excluded / $label", $writeSetup . "\n" . $source . $verify, [$writes => false], null, true, true);
    }
    // These are valid PHP property writes, but require wider target-group context.
    // Keep known limitations explicit rather than calling these ordinary reads.
    foreach ([
        'destructuring target' => '[HOLDER->number, $x] = [1, 2];',
        'list target' => 'list(HOLDER->number) = [1];',
        'keyed foreach value target' => 'foreach ([2 => 1] as $key => HOLDER->number) {}',
    ] as $label => $source) {
        $verify = "\nif (HOLDER->number !== 1) { throw new RuntimeException('Unexpected property value'); }";
        checkFixture("known lexical limitation / $label", $writeSetup . "\n" . $source . $verify, [$writes => false], null, true, true);
    }

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
