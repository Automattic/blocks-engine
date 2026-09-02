<?php
declare(strict_types=1);

/**
 * Contract for a non-anchor brand beside a direct link cluster.
 *
 * Northwind authors a `<nav>` landmark, a text wordmark, and a wrapping cluster
 * of destination anchors:
 *
 *     <nav class="nav">
 *       <span class="brand">Northwind</span>
 *       <div>
 *         <a href="/">Home</a>
 *         <a href="/about.html">About</a>
 *       </div>
 *     </nav>
 *
 * `brandAnchorCarrier()` used to require the brand to be an `<a>`, so this
 * shape declined and generic lowering emitted the brand plus each menu link as
 * separate synthetic paragraphs. The links stacked vertically and the imported
 * editor lost `core/navigation` semantics.
 *
 * The carrier now recognizes an explicitly named non-anchor wordmark beside one
 * unambiguous menu and emits the brand as its own native block next to
 * `core/navigation` / `core/navigation-link` children. Anchor brands and
 * standalone generic inline spans keep their existing paths.
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
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html, array())->toArray();

/** @param array<int, array<string, mixed>> $blocks */
$findBlocks = static function (array $blocks, string $name) use (&$findBlocks): array {
    $found = array();
    foreach ( $blocks as $block ) {
        if ( $name === ($block['blockName'] ?? '') ) {
            $found[] = $block;
        }
        $found = array_merge($found, $findBlocks(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array(), $name));
    }

    return $found;
};

$northwind = $transform(
    '<nav class="nav">'
    . '<span class="brand">Northwind</span>'
    . '<div>'
    . '<a href="/">Home</a>'
    . '<a href="/about.html">About</a>'
    . '</div>'
    . '</nav>'
);
$northwindBlocks = is_array($northwind['blocks'] ?? null) ? $northwind['blocks'] : array();
$northwindMarkup = (string) ($northwind['serialized_blocks'] ?? '');
$northwindNavigations = $findBlocks($northwindBlocks, 'core/navigation');
$northwindLinks = $findBlocks($northwindBlocks, 'core/navigation-link');
$northwindCarriers = array_values(array_filter(
    $findBlocks($northwindBlocks, 'core/group'),
    static fn (array $block): bool => 'nav' === (string) ($block['attrs']['tagName'] ?? '')
));

$assert(
    1 === count($northwindNavigations) && 1 === count($northwindCarriers),
    'Northwind: one nav carrier holds one core/navigation',
    $northwindMarkup
);
$assert(
    2 === count($northwindLinks)
        && 'Home' === (string) ($northwindLinks[0]['attrs']['label'] ?? '')
        && '/' === (string) ($northwindLinks[0]['attrs']['url'] ?? '')
        && 'About' === (string) ($northwindLinks[1]['attrs']['label'] ?? '')
        && '/about.html' === (string) ($northwindLinks[1]['attrs']['url'] ?? ''),
    'Northwind: the cluster becomes core/navigation-link children with authored labels and URLs',
    json_encode($northwindLinks)
);
$assert(
    str_contains($northwindMarkup, 'Northwind')
        && str_contains($northwindMarkup, 'class="brand"')
        && ! str_contains($northwindMarkup, 'blocks-engine-synthetic-paragraph')
        && ! str_contains($northwindMarkup, 'blocks-engine-synthetic-anchor-undecorated')
        && 0 === count($findBlocks($northwindBlocks, 'core/html'))
        && ! str_contains($northwindMarkup, '<!-- wp:html'),
    'Northwind: the wordmark stays native with no synthetic paragraphs or HTML fallback',
    $northwindMarkup
);
$assert(
    'core/paragraph' === (string) ($northwindCarriers[0]['innerBlocks'][0]['blockName'] ?? '')
        && 'core/navigation' === (string) ($northwindCarriers[0]['innerBlocks'][1]['blockName'] ?? ''),
    'Northwind: authored order is the brand block then the navigation',
    json_encode($northwindCarriers[0]['innerBlocks'] ?? array())
);
$assert(
    'pass' === ($northwind['source_reports']['wp_block_validity']['status'] ?? ''),
    'Northwind: the carrier remains Gutenberg-valid',
    json_encode($northwind['source_reports']['wp_block_validity'] ?? array())
);

$direct = $transform(
    '<nav class="nav"><span class="brand">Northwind</span>'
    . '<a href="/">Home</a><a href="/about.html">About</a></nav>'
);
$directBlocks = is_array($direct['blocks'] ?? null) ? $direct['blocks'] : array();
$directMarkup = (string) ($direct['serialized_blocks'] ?? '');
$assert(
    1 === count($findBlocks($directBlocks, 'core/navigation'))
        && 2 === count($findBlocks($directBlocks, 'core/navigation-link'))
        && ! str_contains($directMarkup, 'blocks-engine-synthetic-paragraph'),
    'Northwind direct cluster: sibling anchors use the same native carrier semantics',
    $directMarkup
);

$anchorBrand = $transform(
    '<nav class="nav">'
    . '<a class="brand" href="/">Northwind</a>'
    . '<div><a href="/">Home</a><a href="/about.html">About</a></div>'
    . '</nav>'
);
$anchorBrandBlocks = is_array($anchorBrand['blocks'] ?? null) ? $anchorBrand['blocks'] : array();
$anchorBrandMarkup = (string) ($anchorBrand['serialized_blocks'] ?? '');
$assert(
    1 === count($findBlocks($anchorBrandBlocks, 'core/navigation'))
        && 2 === count($findBlocks($anchorBrandBlocks, 'core/navigation-link'))
        && str_contains($anchorBrandMarkup, 'Northwind')
        && str_contains($anchorBrandMarkup, '<a href="/">Northwind</a>'),
    'anchor brand beside a cluster keeps the existing linked-wordmark carrier',
    $anchorBrandMarkup
);

$genericInline = $transform('<span class="brand">Northwind</span>');
$genericMarkup = (string) ($genericInline['serialized_blocks'] ?? '');
$assert(
    str_contains($genericMarkup, '<mark class="brand"')
        && ! str_contains($genericMarkup, '<!-- wp:navigation'),
    'standalone brand span keeps the generic inline mark fallback',
    $genericMarkup
);

$ordinaryLabel = $transform(
    '<nav class="nav"><span>Browse</span><div><a href="/">Home</a><a href="/about.html">About</a></div></nav>'
);
$ordinaryMarkup = (string) ($ordinaryLabel['serialized_blocks'] ?? '');
$assert(
    ! str_contains($ordinaryMarkup, '<!-- wp:navigation'),
    'an ordinary non-link label does not infer a brand carrier',
    $ordinaryMarkup
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Navigation non-anchor brand carrier contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Navigation non-anchor brand carrier contract passed: {$passes} assertions\n");
