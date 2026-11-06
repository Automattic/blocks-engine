<?php
declare(strict_types=1);

/**
 * An icon-only ARIA toggle beside the site navigation is a hamburger only when
 * it is unnamed or named a menu. A named search expander ("Open search bar",
 * as Google Sites ships it) survives instead of being suppressed as a
 * redundant menu toggle.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$svg = '<svg viewBox="0 0 24 24"><path d="M9.5 3a6.5 6.5 0 1 0 4 11.6l5 5 1.5-1.5-5-5A6.5 6.5 0 0 0 9.5 3z"></path></svg>';
$toggle = static fn (string $name): string => '<div role="button" tabindex="0" aria-expanded="false"' . $name . '>' . $svg . '</div>';
$header = static fn (string $control): string => '<!doctype html><html><body><header><nav><a href="/a">Alpha</a><a href="/b">Beta</a><a href="/c">Gamma</a></nav>' . $control . '</header><main><p>Body</p></main></body></html>';

$cases = array(
    // aria-label => control survives conversion
    ' aria-label="Open search bar"' => true,
    ' aria-label="Open menu"' => false,
    '' => false,
);

$failures = array();
foreach ( $cases as $name => $survives ) {
    $blocks = (string) ( new HtmlTransformer() )->transform($header($toggle($name)), array())->toArray()['serialized_blocks'];
    $kept = str_contains($blocks, '<!-- wp:search') || str_contains($blocks, '<!-- wp:button') || str_contains($blocks, '<!-- wp:image');
    if ( $kept !== $survives ) {
        $failures[] = sprintf('toggle%s: expected %s', '' === $name ? ' (unnamed)' : $name, $survives ? 'kept' : 'suppressed');
    }
}

foreach ( $failures as $failure ) {
    fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
}
echo 'Labelled ARIA toggle not menu: ' . ( count($cases) - count($failures) ) . ' passed, ' . count($failures) . ' failed' . PHP_EOL;
exit(array() === $failures ? 0 : 1);
