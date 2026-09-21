<?php
declare(strict_types=1);

/**
 * The item reset reaches an anchor nested inside wrapper elements.
 *
 * A mesh menu renders `li > div > a` — Wix's stylable menu wraps every anchor
 * in a container — and the collapse hoists the whole chain's classes onto the
 * one rendered navigation item. The authored anchor rule then matches that
 * item directly while the same declarations are also projected onto the
 * rendered `.wp-block-navigation-item__content`, so every menu item painted
 * its padding and margin twice: on www.lonestarlunar.com that widened four
 * menu items past their band, wrapped the menu to a second row, and grew the
 * transparent site header from 113px to 221px of dead white band.
 *
 * The reset that prevents the second box required the source anchor's direct
 * parent to be the `li`, so it failed closed on exactly the shape that needs
 * it. It now resolves the anchor's own list item through the wrapper chain,
 * and still fails closed when a wrapper carried the anchor class or painted
 * an overlapping property itself — that box was real, and resetting the
 * rendered item would erase it.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$document = static function (string $css, string $items): string {
    // The reset stylesheet every mesh export ships: it stamps zero paint on
    // every element, wrappers included, and must not block the item reset.
    return '<style>div, span, a, ul, li{vertical-align:baseline;background:0px 0px;border:0px;outline:0px;margin:0px;padding:0px}'
        . '#menu .menu-root{display:flex}' . $css . '</style>'
        . '<header><div id="menu"><nav class="menu-root" aria-label="Site"><ul class="menu-list">'
        . $items
        . '</ul></nav></div></header><main><p>Body</p></main>';
};

$assets = static function (string $document): string {
    $result = ( new HtmlTransformer() )->transform($document, array())->toArray();

    return implode("\n", array_map(
        static fn (array $asset): string => (string) ($asset['content'] ?? ''),
        is_array($result['assets'] ?? null) ? $result['assets'] : array()
    ));
};

$item = static function (string $label, string $wrapperClass = 'item-container'): string {
    $slug = strtolower($label);
    return '<li class="item-wrapper"><div class="' . $wrapperClass . '">'
        . '<a class="menu-item" href="/' . $slug . '">' . $label . '</a>'
        . '</div></li>';
};

$forwardSelector = '#menu .menu-root .wp-block-navigation-item.menu-item>.wp-block-navigation-item__content';
$resetSelector = '#menu .menu-root .wp-block-navigation-item.menu-item';
$itemBody = static function (string $css) use ($resetSelector): string {
    if ( preg_match('/' . preg_quote($resetSelector, '/') . '\{([^}]*)\}/', $css, $match) ) {
        return $match[1];
    }
    return '';
};

// The lonestar shape: the anchor rule is scoped to the menu, and every anchor
// sits behind one wrapper. The projection and the item reset must both fire.
$nested = $assets($document(
    '#menu .menu-root .menu-item{padding:10px;margin:4px 6px}',
    $item('Vision') . $item('Services') . $item('Team')
));
$assert(
    str_contains($nested, $forwardSelector . '{padding:10px;margin:4px 6px}'),
    'the authored anchor box is projected onto the rendered anchor through the wrapper chain',
    $nested
);
$assert(
    str_contains($nested, $resetSelector . '{padding:0px;margin:0px}'),
    'a wrapped anchor class does not paint a second generated item box: the item restates the source li reset',
    $nested
);

// A wrapper that carries the anchor class painted the authored box itself.
// Resetting the rendered item would erase paint the source really had.
$classedWrapper = $assets($document(
    '#menu .menu-root .menu-item{padding:10px;margin:4px 6px}',
    $item('Vision', 'item-container menu-item') . $item('Team', 'item-container menu-item')
));
$assert(
    '' === $itemBody($classedWrapper),
    'a wrapper carrying the anchor class keeps the item reset withheld',
    $classedWrapper
);

// A wrapper that resolves its own overlapping paint owned a real box whose
// class rules now reach the rendered item; the reset must fail closed.
$paintedWrapper = $assets($document(
    '#menu .menu-root .menu-item{padding:10px;margin:4px 6px}'
        . '.item-container{padding:2px}',
    $item('Vision') . $item('Services') . $item('Team')
));
$assert(
    str_contains($paintedWrapper, $forwardSelector),
    'a painted wrapper still projects the anchor box onto the rendered anchor',
    $paintedWrapper
);
$assert(
    ! str_contains($itemBody($paintedWrapper), 'padding'),
    'a wrapper with overlapping paint of its own keeps the padding reset withheld',
    $paintedWrapper
);
$assert(
    str_contains($itemBody($paintedWrapper), 'margin:0px'),
    'the margin reset is unaffected by a wrapper that paints only padding',
    $paintedWrapper
);

// The direct li > a shape keeps working exactly as before.
$flat = $assets($document(
    '#menu .menu-root .menu-item{padding:10px;margin:4px 6px}',
    '<li class="item-wrapper"><a class="menu-item" href="/vision">Vision</a></li>'
        . '<li class="item-wrapper"><a class="menu-item" href="/team">Team</a></li>'
));
$assert(
    str_contains($flat, $resetSelector . '{padding:0px;margin:0px}'),
    'a direct li > a anchor keeps its item reset',
    $flat
);

// The rule must fire on the import path, not only in the direct transformer
// (#1951): the compiled entry point emits the same reset.
$compiled = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => $document(
            '#menu .menu-root .menu-item{padding:10px;margin:4px 6px}',
            $item('Vision') . $item('Services') . $item('Team')
        ),
    ),
))->toArray();
$compiledCss = implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    is_array($compiled['assets'] ?? null) ? $compiled['assets'] : array()
));
$assert(
    str_contains($compiledCss, $resetSelector . '{padding:0px;margin:0px}'),
    'the compiled entry point emits the wrapped-anchor item reset',
    $compiledCss
);

echo sprintf("navigation-nested-anchor-item-reset: %d passed, %d failed\n", $passes, $failures);
exit(0 === $failures ? 0 : 1);
