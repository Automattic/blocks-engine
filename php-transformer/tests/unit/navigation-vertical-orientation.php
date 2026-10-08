<?php
declare(strict_types=1);

/**
 * A source menu whose cascade stacks its links in a column keeps that stack on
 * core/navigation: the emitted block carries the vertical flex orientation and
 * the source cross-axis alignment as justifyContent, and a print-scoped rule
 * hiding nav chrome never downgrades the resolved display to a flow layout.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' === $detail ? '' : ' - ' . $detail ) . PHP_EOL);
};
$transform = static function (string $html): array {
    return ( new HtmlTransformer() )->transform($html)->toArray();
};
$navigation = static function (array $result): ?array {
    foreach ( $result['blocks'] ?? array() as $block ) {
        if ( is_array($block) && 'core/navigation' === ($block['blockName'] ?? '') ) {
            return $block;
        }
    }

    return null;
};

$links = '';
foreach ( array( 'Home', 'About', 'Podcasts', 'Blog', 'Contact', 'Speaking' ) as $label ) {
    $links .= '<a href="/' . strtolower($label) . '/">' . $label . '</a>';
}

// The reported shape: a class-authored `flex flex-col` menu. Without the
// orientation the generated container renders core's row default.
$utilities = '.flex{display:flex}.flex-col{flex-direction:column}.gap-3{gap:0.75rem}'
    . '.items-center{align-items:center}.items-end{align-items:flex-end}';
$columnResult = $transform('<style>' . $utilities . '</style><nav class="flex flex-col gap-3">' . $links . '</nav>');
$column = $navigation($columnResult);
$layout = is_array($column['attrs']['layout'] ?? null) ? $column['attrs']['layout'] : array();
$assert(
    array( 'type' => 'flex', 'orientation' => 'vertical', 'justifyContent' => 'left' ) === $layout,
    'a class-authored flex-column navigation emits the vertical orientation with left justification',
    json_encode($layout)
);
$assert(
    str_contains((string) ($columnResult['serialized_blocks'] ?? ''), '"layout":{"type":"flex","orientation":"vertical","justifyContent":"left"}'),
    'the vertical orientation survives canonical navigation serialization',
    substr((string) ($columnResult['serialized_blocks'] ?? ''), 0, 300)
);

// The source cross-axis alignment is the justification core reads horizontally.
$endColumn = $navigation($transform('<style>' . $utilities . '</style><nav class="flex flex-col items-end">' . $links . '</nav>'));
$assert(
    'right' === ($endColumn['attrs']['layout']['justifyContent'] ?? null),
    'a right-aligned source column justifies right'
);
$centerColumn = $navigation($transform('<style>' . $utilities . '</style><nav class="flex flex-col items-center">' . $links . '</nav>'));
$assert(
    'center' === ($centerColumn['attrs']['layout']['justifyContent'] ?? null),
    'a center-aligned source column justifies center'
);

// A horizontal menu keeps the core default untouched.
$row = $navigation($transform('<style>' . $utilities . '</style><nav class="flex items-center">' . $links . '</nav>'));
$assert(
    ! isset($row['attrs']['layout']),
    'a horizontal navigation keeps the core row default untouched'
);

// Print chrome is conditional presentation of another medium: `@media print`
// hiding nav elements must not downgrade the column's resolved display to a
// non-flex value (which would emit core's flow layout over the stack).
$printHidden = $navigation($transform(
    '<style>' . $utilities . '@media print{nav,footer,button{display:none !important}}</style>'
    . '<nav class="flex flex-col gap-3">' . $links . '</nav>'
));
$assert(
    array( 'type' => 'flex', 'orientation' => 'vertical', 'justifyContent' => 'left' ) === ($printHidden['attrs']['layout'] ?? null),
    'a print-scoped display rule does not turn the column into a flow layout',
    json_encode($printHidden['attrs']['layout'] ?? null)
);

if ( 0 < $failures ) {
    fwrite(STDERR, "Navigation vertical orientation contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}

echo "Navigation vertical orientation contract passed: {$passes} assertions\n";
