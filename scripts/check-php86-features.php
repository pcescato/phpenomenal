<?php

declare(strict_types=1);

/*
 * Token-based checker: verifies that a source file actually exercises
 * every PHP 8.6 feature listed in task-queue.php's header comment.
 *
 * Uses PhpToken::tokenize() (lexical analysis only) rather than string
 * matching, so e.g. a feature name mentioned only in a comment or string
 * literal doesn't count as "used".
 *
 * Usage:
 *   php check-php86-features.php [path/to/file.php]
 *   (defaults to task-queue.php in the same directory)
 */

final class FeatureCheck
{
    /** @param callable(list<PhpToken>): ?int $detector Returns the line number of the first match, or null. */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public $detector,
    ) {
    }
}

/** Index of the next token, skipping whitespace and comments, or null if none. */
function nextSignificant(array $tokens, int $from, int $step = 1): ?int
{
    $i = $from + $step;
    while (isset($tokens[$i])) {
        if (!$tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            return $i;
        }
        $i += $step;
    }
    return null;
}

const ASSIGN_OPERATORS = [
    '=', '+=', '-=', '*=', '/=', '.=', '%=', '**=',
    '??=', '&=', '|=', '^=', '<<=', '>>=', '++', '--',
];

$checks = [];

// Partial Function Application: a bare `?` placeholder inside a call's
// argument list, e.g. sprintf('%s', ?, ?). Distinguished from a ternary's
// `?` by requiring '(' or ',' right before it and ',' or ')' right after.
$checks[] = new FeatureCheck(
    'Partial Function Application',
    "a `?` argument placeholder, e.g. strlen(?)",
    function (array $tokens): ?int {
        foreach ($tokens as $i => $token) {
            if ($token->text !== '?') {
                continue;
            }
            $prev = nextSignificant($tokens, $i, -1);
            $next = nextSignificant($tokens, $i, 1);
            if ($prev === null || $next === null) {
                continue;
            }
            $prevOk = in_array($tokens[$prev]->text, ['(', ','], true);
            $nextOk = in_array($tokens[$next]->text, [',', ')'], true);
            if ($prevOk && $nextOk) {
                return $token->line;
            }
        }
        return null;
    },
);

// Exact class-name matching, including a leading namespace separator.
// This is lexical: imports/aliases and the current namespace are not resolved.
$checks[] = new FeatureCheck(
    'Time\\Duration',
    'a reference to the Time\\Duration class',
    function (array $tokens): ?int {
        foreach ($tokens as $token) {
            if ($token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING])
                && strcasecmp(ltrim($token->text, '\\'), 'Time\\Duration') === 0
            ) {
                return $token->line;
            }
        }
        return null;
    },
);

// clamp(): a call to a function literally named clamp.
$checks[] = new FeatureCheck(
    'clamp()',
    'a call to clamp(...)',
    function (array $tokens): ?int {
        foreach ($tokens as $i => $token) {
            if ($token->is(T_STRING) && strtolower($token->text) === 'clamp') {
                $next = nextSignificant($tokens, $i, 1);
                if ($next !== null && $tokens[$next]->text === '(') {
                    return $token->line;
                }
            }
        }
        return null;
    },
);

/** Inspect one attribute group, matching names only (never arguments). */
function inspectAttributeGroup(array $tokens, int $start): ?array
{
    $depth = 1;
    $expectName = true;
    $sawOverride = false;
    for ($i = $start + 1; isset($tokens[$i]); $i++) {
        $token = $tokens[$i];
        if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            continue;
        }
        if ($depth === 1 && $expectName) {
            if ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED])
                && strcasecmp(ltrim($token->text, '\\'), 'Override') === 0
            ) {
                $sawOverride = true;
            }
            $expectName = false;
        }
        if ($token->is(T_ATTRIBUTE) || in_array($token->text, ['[', '(', '{'], true)) {
            $depth++;
        } elseif (in_array($token->text, [']', ')', '}'], true)) {
            $depth--;
            if ($depth === 0) {
                return [$i, $sawOverride];
            }
        } elseif ($depth === 1 && $token->text === ',') {
            $expectName = true;
        }
    }
    return null;
}

// Attribute groups may precede visibility/final modifiers on a constant.
// Attribute aliases and namespace resolution remain outside this lexical check.
$checks[] = new FeatureCheck(
    '#[\\Override] on a constant',
    'an #[\\Override] attribute immediately before a const declaration',
    function (array $tokens): ?int {
        foreach ($tokens as $i => $token) {
            if (!$token->is(T_ATTRIBUTE)) {
                continue;
            }
            $after = $i;
            $sawOverride = false;
            do {
                $group = inspectAttributeGroup($tokens, $after);
                if ($group === null) {
                    break;
                }
                [$end, $override] = $group;
                $sawOverride = $sawOverride || $override;
                $after = nextSignificant($tokens, $end);
            } while ($after !== null && $tokens[$after]->is(T_ATTRIBUTE));
            if ($group === null || !$sawOverride) {
                continue;
            }
            while ($after !== null && $tokens[$after]->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_FINAL])) {
                $after = nextSignificant($tokens, $after);
            }
            if ($after !== null && $tokens[$after]->is(T_CONST)) {
                return $token->line;
            }
        }
        return null;
    },
);

// Writes on a const-held object: NAME->prop with assignment or prefix/postfix
// ++/--. Qualified constant names are supported; declarations are not resolved.
// Exclude chains and construction, and require a direct property write.
$checks[] = new FeatureCheck(
    'writes on const-held objects',
    'CONST->prop = ... or CONST->prop++',
    function (array $tokens): ?int {
        foreach ($tokens as $i => $token) {
            if (!$token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])) {
                continue;
            }
            $prev = nextSignificant($tokens, $i, -1);
            if ($prev !== null && ($tokens[$prev]->is(T_NEW) || in_array($tokens[$prev]->text, ['->', '?->', '::', '\\'], true))) {
                continue; // part of a chain, not a fresh identifier
            }
            $arrow = nextSignificant($tokens, $i, 1);
            if ($arrow === null || $tokens[$arrow]->text !== '->') {
                continue;
            }
            $prop = nextSignificant($tokens, $arrow, 1);
            if ($prop === null || !$tokens[$prop]->is(T_STRING)) {
                continue;
            }
            $op = nextSignificant($tokens, $prop, 1);
            $postfixOrAssignment = $op !== null && in_array($tokens[$op]->text, ASSIGN_OPERATORS, true);
            $prefix = $prev !== null && $tokens[$prev]->is([T_INC, T_DEC])
                && $op !== null && !in_array($tokens[$op]->text, ['(', '[', '->', '?->', '::'], true);
            if ($postfixOrAssignment || $prefix) {
                return $token->line;
            }
        }
        return null;
    },
);

// readonly property with a default: `readonly` ... TYPE ... $var = value.
$checks[] = new FeatureCheck(
    'readonly property defaults',
    'a readonly property or promoted param with a default value',
    function (array $tokens): ?int {
        foreach ($tokens as $i => $token) {
            if (!$token->is(T_READONLY)) {
                continue;
            }
            $j = $i;
            while (($j = nextSignificant($tokens, $j, 1)) !== null && !$tokens[$j]->is(T_VARIABLE)) {
                // skip type declarations between `readonly` and the variable
            }
            if ($j === null) {
                continue;
            }
            $after = nextSignificant($tokens, $j, 1);
            if ($after !== null && $tokens[$after]->text === '=') {
                return $token->line;
            }
        }
        return null;
    },
);

// SortDirection enum: either its declaration or a Case::access.
$checks[] = new FeatureCheck(
    'SortDirection enum',
    'enum SortDirection { ... } or a SortDirection::Case reference',
    function (array $tokens): ?int {
        foreach ($tokens as $i => $token) {
            if ($token->text !== 'SortDirection') {
                continue;
            }
            $prev = nextSignificant($tokens, $i, -1);
            $next = nextSignificant($tokens, $i, 1);
            $declared = $prev !== null && $tokens[$prev]->is(T_ENUM);
            $used = $next !== null && $tokens[$next]->text === '::';
            if ($declared || $used) {
                return $token->line;
            }
        }
        return null;
    },
);

// --- run the checks ---------------------------------------------------

$path = $argv[1] ?? __DIR__ . '/task-queue.php';

if (!is_file($path)) {
    fwrite(STDERR, "No such file: {$path}" . PHP_EOL);
    exit(2);
}

$source = file_get_contents($path);
$tokens = PhpToken::tokenize($source);

echo "Checking " . basename($path) . " against the PHP 8.6 feature list:" . PHP_EOL . PHP_EOL;

$missing = 0;
foreach ($checks as $check) {
    $line = ($check->detector)($tokens);
    if ($line !== null) {
        printf("  [x] %-32s (line %d)%s", $check->name, $line, PHP_EOL);
    } else {
        printf("  [ ] %-32s MISSING — expected %s%s", $check->name, $check->description, PHP_EOL);
        $missing++;
    }
}

echo PHP_EOL;
if ($missing === 0) {
    echo "All " . count($checks) . " PHP 8.6 features are used." . PHP_EOL;
    exit(0);
}

echo "{$missing} of " . count($checks) . " feature(s) missing." . PHP_EOL;
exit(1);
