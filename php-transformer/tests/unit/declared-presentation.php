<?php
declare(strict_types=1);

/**
 * Unit tests for the shared declared-presentation set.
 *
 * Plain-PHP test script — no PHPUnit. A property an author states per viewport
 * is a set of values, not one value. Carriers used to resolve that set at a
 * single reference viewport and return a scalar, each with its own copy of the
 * resolution, which is why the same flattening defect kept reappearing in
 * unrelated features. These tests pin the distinctions a carrier has to be able
 * to make so that dropping a breakpoint is not something it can do by accident.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\DeclaredPresentation;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$entry = static function (string $value, array $queries = array(), ?int $layer = null, bool $applies = true, ?array $conditions = null): array {
    return array(
        'value' => $value,
        'conditions' => $conditions ?? $queries,
        'queries' => $queries,
        'layer' => $layer,
        'applies' => $applies,
    );
};

$assert(DeclaredPresentation::none()->isEmpty(), 'an undeclared property is empty');
$assert('' === DeclaredPresentation::none()->base(), 'an undeclared property has no base value');
$assert('' === DeclaredPresentation::none()->resolvedValue(), 'an undeclared property resolves to nothing');

// Base and conditional are distinct readings of the same set.
$responsive = DeclaredPresentation::fromEntries(array(
    $entry('3rem'),
    $entry('4.5rem', array( '@media (width>=64rem)' )),
));
$assert('3rem' === $responsive->base(), 'the unconditional declaration is the base value');
$assert($responsive->isConditional(), 'a set containing a query is conditional');
$assert(
    array( '@media (width>=64rem)' => '4.5rem' ) === $responsive->conditional(),
    'conditional entries are keyed by their query chain'
);

// Source order is cascade order: the last applying entry wins.
$ordered = DeclaredPresentation::fromEntries(array(
    $entry('1rem'),
    $entry('2rem', array( '@media (width>=48rem)' ), null, true),
    $entry('3rem', array( '@media (width>=90rem)' ), null, false),
));
$assert('2rem' === $ordered->resolvedValue(), 'the last applying entry wins at the reference viewport');
$assert('1rem' === $ordered->base(), 'a non-applying breakpoint does not change the base value');

// The distinction that decides whether a value may be baked: a conditioned
// declaration winning is not the same as something winning while an unrelated
// breakpoint happens to exist. Conflating them bakes the base value and the
// author's own breakpoints stop resolving.
$baseWinsAlongsideBreakpoint = DeclaredPresentation::fromEntries(array(
    $entry('clamp(5rem, 13vw, 13rem)'),
    $entry('clamp(4rem, 18vw, 7rem)', array( '@media (max-width: 768px)' ), null, false),
));
$assert(
    '' === $baseWinsAlongsideBreakpoint->conditionalOnly()->resolvedValue(),
    'no conditioned declaration wins when the only breakpoint does not apply'
);
$assert(
    'clamp(5rem, 13vw, 13rem)' === $baseWinsAlongsideBreakpoint->resolvedValue(),
    'the whole set still resolves to the base value'
);

$conditionalWins = DeclaredPresentation::fromEntries(array(
    $entry('1rem'),
    $entry('2rem', array( '@media (width>=48rem)' ), null, true),
));
$assert('2rem' === $conditionalWins->conditionalOnly()->resolvedValue(), 'an applying conditioned declaration is reported');

// A cascade `@layer` scopes a declaration without conditioning it on the
// viewport, so it is not a query a carrier can re-emit — but it still separates
// the set, because layered and unlayered declarations reach the runtime cascade
// differently.
$layered = DeclaredPresentation::fromEntries(array(
    $entry('1rem', array(), null),
    $entry('3rem', array(), 2, true, array( '@layer utilities' )),
));
$assert('1rem' === $layered->unlayered()->resolvedValue(), 'the unlayered subset excludes layered declarations');
$assert('3rem' === $layered->layered()->resolvedValue(), 'the layered subset excludes unlayered declarations');
$assert(array() === $layered->conditional(), 'a cascade layer is not a query a carrier can restate');
$assert($layered->layered()->isConditional(), 'a layer still scopes the declaration for cascade purposes');

if ( $failures > 0 ) {
    fwrite(STDERR, "DeclaredPresentation unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "DeclaredPresentation unit tests: {$passes} passed\n");
