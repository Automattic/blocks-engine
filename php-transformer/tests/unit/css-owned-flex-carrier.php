<?php
declare(strict_types=1);

/**
 * Contract for inline `display:flex` on containers demoted to CSS-owned layout.
 *
 * `cssOwnedGroupAttributes()` drops the native `layout` attribute when an author
 * flex/grid container is demoted to a css-owned core/group. Grids survive that
 * demotion because their inline declarations ride to the generated stylesheet on
 * a carrier class. Flex containers had no equivalent, so the declaration that
 * makes the container a flex container was dropped while the block was still
 * marked `blocks-engine-css-owned-flow` — the children then stacked.
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

$cssFor = static function (array $result, string $source): string {
    return implode("\n", array_map(
        static fn (array $asset): string => (string) ($asset['content'] ?? ''),
        array_values(array_filter(
            is_array($result['assets'] ?? null) ? $result['assets'] : array(),
            static fn (array $asset): bool => $source === ($asset['source'] ?? '')
        ))
    ));
};

$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html, array())->toArray();
$transformWithCss = static fn (string $html, string $css): array => ( new HtmlTransformer() )->transform($html, array('static_css' => $css))->toArray();

/** Rule body for the geometry carrier class present in the markup. */
$carrierRule = static function (string $markup, string $css): string {
    if ( 1 !== preg_match('/\b(be-inline-geometry-[a-f0-9-]+)\b/', $markup, $match) ) {
        return '';
    }
    if ( 1 !== preg_match('/(?<![\w-])\.' . preg_quote($match[1], '/') . '\{([^}]*)\}/', $css, $rule) ) {
        return '';
    }

    return (string) $rule[1];
};

$columns = '<div><p>Alpha</p></div><div><p>Beta</p></div><div><p>Gamma</p></div>';

// -- Defect: a css-owned-flow footer loses its inline display:flex entirely.
$flex = $transform(
    '<footer style="display:flex;gap:2rem;justify-content:space-between;align-items:center">' . $columns . '</footer>'
);
$flexMarkup = (string) ($flex['serialized_blocks'] ?? '');
$flexEngineCss = $cssFor($flex, 'engine-support');
$flexRule = $carrierRule($flexMarkup, $flexEngineCss);

$assert(
    str_contains($flexMarkup, 'blocks-engine-css-owned-flow'),
    'inline flex: the container is still demoted to a css-owned-flow group',
    $flexMarkup
);
$assert(
    1 === preg_match('/\bbe-inline-geometry-[a-f0-9-]+\b/', $flexMarkup),
    'inline flex: the demoted container receives a geometry carrier class',
    $flexMarkup
);
$assert(
    str_contains($flexRule, 'display:flex'),
    'inline flex: the carrier keeps the container a flex container',
    '' !== $flexRule ? $flexRule : $flexEngineCss
);
$assert(
    str_contains($flexRule, 'gap:2rem'),
    'inline flex: the authored gap rides with the display declaration',
    '' !== $flexRule ? $flexRule : $flexEngineCss
);
$assert(
    str_contains($flexRule, 'justify-content:space-between'),
    'inline flex: the authored main-axis distribution rides with the display declaration',
    '' !== $flexRule ? $flexRule : $flexEngineCss
);
$assert(
    str_contains($flexRule, 'align-items:center'),
    'inline flex: the authored cross-axis alignment rides with the display declaration',
    '' !== $flexRule ? $flexRule : $flexEngineCss
);

// -- A vertical flex column keeps its direction rather than silently becoming a row.
$column = $transform(
    '<footer style="display:flex;flex-direction:column;flex-wrap:wrap;gap:1rem">' . $columns . '</footer>'
);
$columnMarkup = (string) ($column['serialized_blocks'] ?? '');
$columnRule = $carrierRule($columnMarkup, $cssFor($column, 'engine-support'));

$assert(
    str_contains($columnRule, 'display:flex') && str_contains($columnRule, 'flex-direction:column') && str_contains($columnRule, 'flex-wrap:wrap'),
    'inline flex column: direction and wrap ride with the display declaration',
    '' !== $columnRule ? $columnRule : $cssFor($column, 'engine-support')
);

// A clipping fixed inline height is unsafe once the converted direct children
// no longer have the source tags. The emitted carrier, rather than every
// CSS-owned group in the document, is the repair target.
$topologyChanged = $transform(
    '<div style="display:flex;height:50px;overflow:hidden"><a href="/">One</a><a href="/two">Two</a></div>'
);
$topologyChangedCss = $cssFor($topologyChanged, 'engine-support');
$assert(
    ! str_contains($topologyChangedCss, 'height:50px'),
    'topology-changing flex: fixed inline height is not carried onto the CSS-owned group',
    $topologyChangedCss
);

// A topology finding elsewhere must not remove a fixed height that still has
// the source child structure, nor an absolute page-layer's fixed geometry.
$retainedHeights = $transform(
    '<div style="display:flex;height:50px;overflow:hidden"><a href="/">One</a><a href="/two">Two</a></div>'
    . '<div style="display:flex;height:75px;overflow:hidden"><div>One</div><div>Two</div></div>'
    . '<div style="position:absolute;height:120px;overflow:hidden">Page layer</div>'
);
$retainedHeightsCss = $cssFor($retainedHeights, 'engine-support');
$assert(
    ! str_contains($retainedHeightsCss, 'height:50px') && str_contains($retainedHeightsCss, 'height:75px') && str_contains($retainedHeightsCss, 'height:120px'),
    'topology repair: removes only the clipping carrier whose source child topology changed',
    $retainedHeightsCss
);
$assert(
    str_contains($topologyChangedCss, 'display:flex'),
    'topology-changing flex: the required author-owned flex layout remains carried',
    $topologyChangedCss
);

// Regression: `unsafe_layout_constraint` (blocks-engine#unsafe-layout-constraint-repair).
// The fixed height above rides an INLINE style. A stylesheet-owned fixed
// height — e.g. an ID selector, as commonly authored — reaches the geometry
// carrier through a second, independent mechanism: createBlock()'s
// applyIntrinsicVisualMediaHeight(), which re-derives height from merged
// presentation declarations for every core/group regardless of CSS
// ownership. Before this fix it did not know about the topology-changed
// exclusion cssOwnedGroupAttributes() applies, so it silently re-pinned the
// exact fixed height that call had just excluded, via a NEW carrier of its
// own. The importer detects the pinned carrier and raises `error`-severity
// `unsafe_layout_constraint`, refusing to ship a possibly clipped layout.
$stylesheetOwnedCss = '#pin{display:flex;height:24px}';
$stylesheetOwnedTopologyChanged = $transformWithCss(
    '<aside id="pin"><a href="/">One</a><a href="/two">Two</a></aside>',
    $stylesheetOwnedCss
);
$stylesheetOwnedMarkup = (string) ($stylesheetOwnedTopologyChanged['serialized_blocks'] ?? '');
$stylesheetOwnedEngineCss = $cssFor($stylesheetOwnedTopologyChanged, 'engine-support');
$stylesheetOwnedAuthorCss = $cssFor($stylesheetOwnedTopologyChanged, 'author-css');
$stylesheetOwnedTopologyDiagnostics = array_values(array_filter(
    is_array($stylesheetOwnedTopologyChanged['diagnostics'] ?? null) ? $stylesheetOwnedTopologyChanged['diagnostics'] : array(),
    static fn (array $diagnostic): bool => 'author_layout_topology_changed' === ($diagnostic['code'] ?? '')
));

$assert(
    1 !== preg_match('/\bbe-inline-geometry-[a-f0-9-]+\b/', $stylesheetOwnedMarkup),
    'stylesheet-owned topology change: no geometry carrier class is invented for the fixed height at all',
    $stylesheetOwnedMarkup
);
$assert(
    ! str_contains($stylesheetOwnedEngineCss, 'height:24px'),
    'stylesheet-owned topology change: the engine does not synthesize a fixed-height pin',
    $stylesheetOwnedEngineCss
);
$assert(
    str_contains($stylesheetOwnedAuthorCss, '#pin{display:flex;height:24px}'),
    'stylesheet-owned topology change: the source layout survives verbatim in the materialized author stylesheet',
    $stylesheetOwnedAuthorCss
);
$assert(
    1 === count($stylesheetOwnedTopologyDiagnostics),
    'stylesheet-owned topology change: the topology-changed detector still fires — it is not suppressed',
    json_encode($stylesheetOwnedTopologyDiagnostics)
);

// Control: the SAME stylesheet-owned fixed height, with topology UNCHANGED
// (both children stay paragraphs), still receives the intrinsic-height
// carrier — proving the topology-changed exclusion above did not regress the
// legitimate case applyIntrinsicVisualMediaHeight() exists to serve.
$stylesheetOwnedStable = $transformWithCss(
    '<aside id="pin"><p>One</p><p>Two</p></aside>',
    $stylesheetOwnedCss
);
$stylesheetOwnedStableMarkup = (string) ($stylesheetOwnedStable['serialized_blocks'] ?? '');
$stylesheetOwnedStableEngineCss = $cssFor($stylesheetOwnedStable, 'engine-support');
$assert(
    1 === preg_match('/\bbe-inline-geometry-[a-f0-9-]+\b/', $stylesheetOwnedStableMarkup),
    'stylesheet-owned topology control: an unchanged-topology container still receives its intrinsic-height carrier',
    $stylesheetOwnedStableMarkup
);
$assert(
    str_contains($stylesheetOwnedStableEngineCss, 'height:24px'),
    'stylesheet-owned topology control: the intrinsic fixed height is still carried when topology is unchanged',
    $stylesheetOwnedStableEngineCss
);

// -- Control: no authored display gains no carrier and no flex declaration.
$plain = $transform('<footer class="plain">' . $columns . '</footer>');
$plainMarkup = (string) ($plain['serialized_blocks'] ?? '');
$plainEngineCss = $cssFor($plain, 'engine-support');

$assert(
    1 !== preg_match('/\bbe-inline-geometry-[a-f0-9-]+\b/', $plainMarkup),
    'no authored display control: no geometry carrier class is invented',
    $plainMarkup
);
$assert(
    ! str_contains($plainEngineCss, 'display:flex'),
    'no authored display control: no flex declaration is invented',
    $plainEngineCss
);
$assert(
    str_starts_with($plainMarkup, '<!-- wp:group {"className":"plain","tagName":"footer","metadata":{"name":"Footer"}} --><footer class="wp-block-group plain">'),
    'no authored display control: the container block is unchanged',
    substr($plainMarkup, 0, 200)
);

// -- Control: a class-owned flex container has nothing inline to carry, and the
// author stylesheet already retains the declaration.
$classFlex = $transform(
    '<style>.bar{display:flex;gap:2rem}</style><footer class="bar">' . $columns . '</footer>'
);
$classFlexMarkup = (string) ($classFlex['serialized_blocks'] ?? '');

$assert(
    1 !== preg_match('/\bbe-inline-geometry-[a-f0-9-]+\b/', $classFlexMarkup),
    'class-owned flex control: no carrier is generated for declarations the author stylesheet owns',
    $classFlexMarkup
);
$assert(
    str_contains($cssFor($classFlex, 'author-css'), '.bar{display:flex;gap:2rem}'),
    'class-owned flex control: the author rule stays the owner of the flex declaration',
    $cssFor($classFlex, 'author-css')
);

if ( $failures > 0 ) {
    fwrite(STDERR, "CSS-owned flex carrier contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "CSS-owned flex carrier contract passed: {$passes} assertions\n");
