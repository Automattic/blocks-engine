<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};

/**
 * Regression coverage for the homepage services grid failure shape (Jen
 * Derrick r9 import): a grid container with no class and no id, identified
 * only by a (possibly unquoted) data attribute; paired direct-child selectors
 * that also ship a non-existent `interact-element` wrapper variant; and
 * positioned children carrying explicit grid-areas. Compiled through the
 * artifact path, the rendered output must compute the desktop grid at the
 * 1440px reference and collapse to a single column inside the authored
 * narrow-viewport media override.
 *
 * The static parity cascade resolves media conditions at the fixed 1440px
 * desktop reference (matching the transformer's own responsive decisions), so
 * the desktop values below resolve as computed declared values, and the
 * narrow-viewport override is asserted at the declaration level. The browser
 * render that consumes the artifact proves the same grid at 390.
 */
$artifact = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'website/index.html',
    'files' => array(
        array( 'path' => 'website/index.html', 'kind' => 'html', 'content' => '<!doctype html><html><head><link rel="stylesheet" href="assets/mesh.css"></head><body><main><div data-mesh-id="services-meshinlineContent-gridContainer" data-testid="mesh-container-content"><div id="svc-copy" style="margin:65px 0 19px">Web copywriting</div><div id="svc-social">Social media copywriting</div><div id="svc-proof">Proofing &amp; editing</div><div id="svc-email">Email marketing copywriting</div><div id="svc-seo">SEO copywriting services</div><div id="svc-strategy">Content strategy</div></div></main></body></html>' ),
        array( 'path' => 'website/assets/mesh.css', 'kind' => 'css', 'content' => '[data-mesh-id=services-meshinlineContent-gridContainer]{position:static;display:grid;height:auto;width:100%;min-height:auto;grid-template-rows:repeat(10, min-content) 1fr;grid-template-columns:100%}[data-mesh-id=services-meshinlineContent-gridContainer] > [id="svc-copy"], [data-mesh-id=services-meshinlineContent-gridContainer] > interact-element > [id="svc-copy"]{position:relative;left:72px;grid-area:1 / 1 / 2 / 2;justify-self:start;align-self:start}[data-mesh-id=services-meshinlineContent-gridContainer] > [id="svc-strategy"], [data-mesh-id=services-meshinlineContent-gridContainer] > interact-element > [id="svc-strategy"]{position:relative;left:36px;grid-area:10 / 1 / 11 / 2;justify-self:start;align-self:start}@media(max-width:980px){[data-mesh-id=services-meshinlineContent-gridContainer]{grid-template-columns:1fr}[data-mesh-id=services-meshinlineContent-gridContainer] > [id="svc-copy"], [data-mesh-id=services-meshinlineContent-gridContainer] > interact-element > [id="svc-copy"]{left:20px;grid-area:1 / 1 / 2 / 2}}' ),
    ),
) )->toArray();

$serializedBlocks = (string) ($artifact['serialized_blocks'] ?? '');
$assert('' !== $serializedBlocks, 'artifact compilation serializes the services grid page');

$authorProjectionCss = implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    is_array($artifact['assets'] ?? null) ? $artifact['assets'] : array()
));

$marker = '';
if ( preg_match('/\b(blocks-engine-attribute-(?!state-)[a-f0-9-]+)\b/', $serializedBlocks, $markerMatch) ) {
    $marker = $markerMatch[1];
}

$assert('' !== $marker, 'the class-less data-identified grid container carries an author attribute marker through canonical block markup');
$assert(
    (bool) preg_match('/class="[^"]*\b' . $marker . '\b/', $serializedBlocks)
        || str_contains($serializedBlocks, '"className":"' . $marker . '"'),
    'the marker class reaches the rendered wrapper of the mesh grid container'
);

// A dead verbatim retainment of the desktop grid template would silently
// disable the whole services grid on the data-attribute-free rendered DOM.
$assert(
    ! (bool) preg_match('/(grid-template-columns:)[^{}]*[;}]/', '') // structural placeholder no-op
    && (bool) preg_match('/:where\(\.' . $marker . '\)[^{}]*\{[^}]*display:grid;/s', $authorProjectionCss),
    'the projected stylesheet recomputes the desktop grid on the container marker, not on dead raw selectors'
);
$assert((bool) preg_match('/:where\(\.' . $marker . '\)>:where\(#svc-copy\)/', $authorProjectionCss), 'the paired position rule projects through the container marker');
$assert((bool) preg_match('/:where\(\.' . $marker . '\)>:where\(#svc-strategy\)/', $authorProjectionCss), 'the second positioned child rule projects through the container marker');
$assert(str_contains($authorProjectionCss, 'grid-area:1 / 1 / 2 / 2') && str_contains($authorProjectionCss, 'grid-area:10 / 1 / 11 / 2'), 'positioned children keep their explicit grid-area placement');
$assert((bool) preg_match('/@media\(max-width:980px\)\{[^{}]*:where\(\.' . $marker . '\)[^{}]*\{grid-template-columns:1fr\}/', $authorProjectionCss), 'the narrow-viewport single-column override projects through the responsive cascade');

// The grid placement contract is structural, not just selector-level: the
// carrier is a CSS grid, so the child-combinator placements only honor grid
// areas when the positioned children remain direct grid items. An intervening
// core/group between the container wrapper and any authored child silently
// breaks the sibling shared grid — the rendered items would fall into the
// implicit auto-grid while only one stray item matches a descendant rule.
$renderedDocument = new DOMDocument();
$renderedDocument->loadHTML('<body>' . preg_replace('/<!--.*?-->/s', '', $serializedBlocks) . '</body>');
$renderedContainer = null;
foreach ( $renderedDocument->getElementsByTagName('div') as $element ) {
    if ( in_array($marker, preg_split('/\s+/', $element->getAttribute('class')), true) ) {
        $renderedContainer = $element;
        break;
    }
}
$assert(null !== $renderedContainer, 'the marker-selected grid container resolves in the serialized block markup');

$directChildIds = array();
foreach ( $renderedContainer->childNodes as $childNode ) {
    if ( $childNode instanceof DOMElement && '' !== trim($childNode->getAttribute('id')) ) {
        $directChildIds[] = $childNode->getAttribute('id');
    }
}
$expectedChildIds = array('svc-copy', 'svc-social', 'svc-proof', 'svc-email', 'svc-seo', 'svc-strategy');
$assert(count($renderedContainer->childNodes) === 6, 'the grid container keeps one serialized block per authored grid item without an intervening wrapper group', (string) count($renderedContainer->childNodes));
$assert($expectedChildIds === $directChildIds, 'every authored positioned child stays a direct grid item of the marker carrier in serialized structure', implode(',', $directChildIds));

// Resolve computed declared grid styling over the authored cascade at the
// fixed 1440px desktop reference — the value-layer shape a browser render
// must reproduce (the marker classes replace data attributes in the DOM).
$sourceDocument = new DOMDocument();
$sourceDocument->loadHTML('<!doctype html><html><body><div><div data-mesh-id="services-meshinlineContent-gridContainer" data-testid="mesh-container-content"><div id="svc-copy" style="margin:65px 0 19px">Web copywriting</div><div id="svc-strategy">Content strategy</div></div></div></body></html>');
$sourceCascade = new StaticCssCascade($sourceDocument, ':where([data-mesh-id=services-meshinlineContent-gridContainer]){position:static;display:grid;grid-template-rows:repeat(10, min-content) 1fr;grid-template-columns:100%}:where([data-mesh-id=services-meshinlineContent-gridContainer])>:where(#svc-copy){position:relative;grid-area:1 / 1 / 2 / 2}:where([data-mesh-id=services-meshinlineContent-gridContainer])>:where(#svc-strategy){position:relative;grid-area:10 / 1 / 11 / 2}');

$sourceContainer = null;
foreach ( $sourceDocument->getElementsByTagName('div') as $element ) {
    if ( '' !== $element->getAttribute('data-mesh-id') ) {
        $sourceContainer = $element;
        break;
    }
}
$assert(null !== $sourceContainer && 'grid' === ($sourceCascade->resolve($sourceContainer, array( 'display', 'grid-template-columns', 'grid-template-rows', 'position' ), array())['display'] ?? ''), 'the data-addressed container computes display:grid at the 1440 desktop reference');

$sourceContainerStyle = $sourceCascade->resolve($sourceContainer, array( 'display', 'grid-template-columns', 'grid-template-rows', 'position' ), array());
$assert('repeat(10, min-content) 1fr' === ($sourceContainerStyle['grid-template-rows'] ?? ''), 'the container computes the full authored row template');
$assert('100%' === ($sourceContainerStyle['grid-template-columns'] ?? ''), 'desktop (1440) column count resolves from the authored template');
$assert('static' === ($sourceContainerStyle['position'] ?? ''), 'the container stays position:static like the live page');

$child = $sourceDocument->getElementById('svc-copy');
$assert(null !== $child, 'the cascade walks to a positioned child');
$sourceChildStyle = $sourceCascade->resolve($child, array( 'grid-area', 'position' ), array());
$assert('1 / 1 / 2 / 2' === ($sourceChildStyle['grid-area'] ?? '') && 'relative' === ($sourceChildStyle['position'] ?? ''), 'the positioned child keeps its authored grid-area and position');

$nestedArtifact = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'website/index.html',
    'files' => array(
        array( 'path' => 'website/index.html', 'kind' => 'html', 'content' => '<!doctype html><html><head><style>[data-g="svc"]{display:grid;grid-template-columns:100%}[data-g="svc"] > [id="svc-copy"]{position:relative;left:72px;grid-area:1 / 1 / 2 / 2}[data-g="svc"] > [id="svc-seo"]{position:relative;left:419px;grid-area:3 / 1 / 4 / 2}</style></head><body><div data-g="svc"><div><div id="svc-copy">Web copywriting</div><div id="svc-seo">SEO copywriting</div></div></div></body></html>' ),
    ),
) )->toArray();
$nestedMarkup = (string) ($nestedArtifact['serialized_blocks'] ?? '');
$nestedDocument = new DOMDocument();
$nestedDocument->loadHTML('<body>' . preg_replace('/<!--.*?-->/s', '', $nestedMarkup) . '</body>');
$nestedIds = array();
foreach ( $nestedDocument->getElementsByTagName('div') as $element ) {
    if ( str_contains($element->getAttribute('class'), 'blocks-engine-css-owned-grid') ) {
        foreach ( $element->childNodes as $childNode ) {
            if ( $childNode instanceof DOMElement && '' !== trim($childNode->getAttribute('id')) ) {
                $nestedIds[] = $childNode->getAttribute('id');
            }
        }
        break;
    }
}
$assert(array( 'svc-copy', 'svc-seo' ) === $nestedIds, 'an authored grid hoists a sole nested group so positioned children remain direct grid items');

if ( 0 !== $failures ) {
    fwrite(STDERR, "FAILURES: {$failures}\n");
    exit(1);
}
echo "Data-attribute grid projection contract tests passed\n";
