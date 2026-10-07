<?php
declare(strict_types=1);

/**
 * Regression coverage for ColumnsPattern's sidebar/content partner test.
 *
 * `hasSidebarAndContentChildren()` promotes a container to core/columns when
 * one child is named like a sidebar (`aside`, `.sidebar`, `.toc`) and another
 * like content (`main`, `article`, `.content`). The names only promise a
 * two-pane layout when both children are actually laid out beside each other
 * at the desktop reference viewport. A phone-only sticky bar — an `<aside>`
 * that is `display:none` from the tablet breakpoint up and `position:fixed`
 * to the viewport bottom — is never beside the content, yet the name test
 * counted it, so the page wrapper became a row of equal columns: a 0-height
 * spacer took half the page width and the real content sat in the other half.
 *
 * The fix reads each candidate child's resolved layout at the desktop
 * reference viewport (inline, static and media-conditional author rules
 * together) and skips children that are not rendered there (`display:none`,
 * the `hidden` attribute with no author display) or that are out of flow
 * (`position:absolute|fixed`). Children hidden only at the base viewport and
 * revealed by a `min-width` rule (mobile-first sidebars) still count, as do
 * sticky, floated and width-only sidebars.
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

$pair = '<div class="layout"><aside class="sidebar"><p>Nav</p></aside><main class="content"><h1>Title</h1><p>Body</p></main></div>';

/**
 * @return array{top: list<string>, columns: bool, serialized: string}
 */
$transform = static function (string $html, string $css = ''): array {
    $result = ( new HtmlTransformer() )->transform($html, '' === $css ? array() : array( 'static_css' => $css ))->toArray();
    $top = array();
    foreach ( $result['blocks'] ?? array() as $block ) {
        $top[] = (string) ($block['blockName'] ?? '');
    }
    $serialized = (string) ($result['serialized_blocks'] ?? '');

    return array( 'top' => $top, 'columns' => str_contains($serialized, '<!-- wp:columns'), 'serialized' => $serialized );
};

// ---------------------------------------------------------------------
// 1. Partners that are not laid out beside the content at the desktop
//    reference viewport must not promote the container to core/columns.
// ---------------------------------------------------------------------
$notColumns = array(
    'aside hidden from the tablet breakpoint up and fixed to the viewport bottom' => array(
        '<div class="shell"><main class="shell__main"><h1>Title</h1><p>Body</p></main><aside class="bar bar--sticky"><p>Call</p></aside></div>',
        '.shell{position:relative}.bar{width:100%}.bar.bar--sticky{position:fixed;right:0;bottom:0;left:0;z-index:30}@media (min-width: 769px){.bar{display:none}}',
    ),
    'page wrapper with breakpoint spacers, main and a phone-only fixed aside' => array(
        '<div class="shell"><div class="spacer-desktop" aria-hidden="true"></div><div class="spacer-mobile" aria-hidden="true" style="height:63.75px;"></div><main class="shell__main"><h1>Title</h1><p>Body</p></main><aside class="bar bar--sticky"><p>Call</p></aside></div>',
        '.shell{position:relative}.spacer-desktop{display:none}.spacer-mobile{display:none}@media (min-width: 769px){.spacer-desktop{display:block}.spacer-mobile{display:none}.bar{display:none}}.bar{width:100%}.bar.bar--sticky{position:fixed;right:0;bottom:0;left:0;z-index:30}',
    ),
    'absolutely positioned aside' => array( $pair, '.layout{position:relative}.sidebar{position:absolute;top:0;right:0}' ),
    'aside hidden by an unconditional author rule' => array( $pair, '.sidebar{display:none}' ),
    'aside hidden by an inline style' => array(
        '<div class="layout"><aside class="sidebar" style="display:none"><p>Nav</p></aside><main class="content"><h1>Title</h1><p>Body</p></main></div>',
    ),
    'aside carrying the hidden attribute with no author display' => array(
        '<div class="layout"><aside class="sidebar" hidden><p>Nav</p></aside><main class="content"><h1>Title</h1><p>Body</p></main></div>',
    ),
    'aside hidden by a max-width rule that holds at the desktop reference viewport' => array( $pair, '@media (max-width: 1500px){.sidebar{display:none}}' ),
    'fixed main beside a visible aside' => array( $pair, '.content{position:fixed;inset:0}' ),
);

foreach ( $notColumns as $label => $case ) {
    $out = $transform($case[0], $case[1] ?? '');
    $assert(! $out['columns'], $label . ': container is not core/columns', 'top=' . implode(',', $out['top']));
    $assert(array( 'core/group' ) === $out['top'], $label . ': container stays one core/group', 'top=' . implode(',', $out['top']));
}

// ---------------------------------------------------------------------
// 2. Partners laid out in flow at the desktop reference viewport keep the
//    sidebar/content promotion, including mobile-first sidebars that are
//    hidden at the base viewport and revealed by a min-width rule.
// ---------------------------------------------------------------------
$stillColumns = array(
    'plain aside + main with no author CSS' => array( $pair ),
    'sticky aside' => array( $pair, '.sidebar{position:sticky;top:1rem}' ),
    'floated aside' => array( $pair, '.sidebar{float:left;width:250px}.content{margin-left:270px}' ),
    'width-only aside' => array( $pair, '.sidebar{width:250px}.content{width:calc(100% - 250px)}' ),
    'aside hidden only below the tablet breakpoint' => array( $pair, '@media (max-width: 768px){.sidebar{display:none}}' ),
    'mobile-first aside: hidden at base, block from the desktop breakpoint' => array( $pair, '.sidebar{display:none}@media (min-width: 1024px){.sidebar{display:block;width:250px}}' ),
    'mobile-first aside with utility classes (hidden md:block)' => array(
        '<div class="layout"><aside class="sidebar hidden md:block"><p>Nav</p></aside><main class="content"><h1>Title</h1><p>Body</p></main></div>',
        '.hidden{display:none}@media (min-width: 768px){.md\\:block{display:block}}',
    ),
    'relative aside (in flow)' => array( $pair, '.sidebar{position:relative;top:4px}' ),
);

foreach ( $stillColumns as $label => $case ) {
    $out = $transform($case[0], $case[1] ?? '');
    $assert($out['columns'], $label . ': container is still core/columns', 'top=' . implode(',', $out['top']));
    $assert(str_contains($out['serialized'], '<!-- wp:column {"className":"sidebar'), $label . ': the aside is a column', substr($out['serialized'], 0, 200));
}

if ( 0 < $failures ) {
    fwrite(STDERR, "Columns sidebar partner desktop layout contract failed ({$failures} failing, {$passes} passing)\n");
    exit(1);
}

fwrite(STDOUT, "Columns sidebar partner desktop layout contract passed: {$passes} assertions\n");
