<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};
$runtime = new Runtime();
$style = array(
    'dimensions' => array( 'minHeight' => '20rem' ),
    'spacing' => array( 'padding' => array( 'top' => '1rem' ) ),
    'typography' => array( 'fontSize' => '2rem', 'textDecoration' => 'underline' ),
    'color' => array( 'text' => 'red', 'gradient' => 'linear-gradient(red, blue)' ),
    'border' => array( 'width' => '2px' ),
    'shadow' => '0 1px 2px #000',
);
$panelAnchor = $runtime->normalizeBlockSupportAttributes('core/accordion-panel', array( 'anchor' => 'radix-_R_2diaq_', 'className' => 'text-sm' ));
$assert(! isset($panelAnchor['attrs']['anchor']) && 'text-sm' === ($panelAnchor['attrs']['className'] ?? ''), 'Accordion panel drops unsupported anchor so Gutenberg save() is not given an id it will not reproduce.');
$groupAnchor = $runtime->normalizeBlockSupportAttributes('core/group', array( 'anchor' => 'inicio' ));
$assert('inicio' === ($groupAnchor['attrs']['anchor'] ?? ''), 'Group retains supported anchor.');
$paragraph = $runtime->normalizeBlockSupportAttributes('core/paragraph', array( 'style' => $style, 'layout' => array( 'type' => 'flex' ) ));
$paragraphStyle = $paragraph['attrs']['style'] ?? array();
$assert(! isset($paragraphStyle['dimensions']['minHeight']) && isset($paragraph['fallbackStyle']['dimensions']['minHeight']), 'Paragraph rejects unsupported dimensions into the carrier payload.');
$assert(isset($paragraphStyle['spacing']['padding']['top']), 'Paragraph retains supported spacing.');
$assert(isset($paragraphStyle['typography']['fontSize']), 'Paragraph retains supported typography.');
$assert(isset($paragraphStyle['color']['text']), 'Paragraph retains supported color.');
$assert(isset($paragraphStyle['border']['width']), 'Paragraph retains supported border.');
$assert(! isset($paragraphStyle['shadow']) && isset($paragraph['fallbackStyle']['shadow']), 'Paragraph rejects unsupported shadow into the carrier payload.');
$assert(! isset($paragraph['attrs']['layout']), 'Paragraph rejects unsupported layout.');
$group = $runtime->normalizeBlockSupportAttributes('core/group', array( 'style' => $style, 'layout' => array( 'type' => 'grid' ) ));
$assert(isset($group['attrs']['style']['dimensions']['minHeight']) && isset($group['attrs']['style']['shadow']) && isset($group['attrs']['layout']), 'Group retains supported layout, dimensions, and shadow.');
$cover = $runtime->normalizeBlockSupportAttributes('core/cover', array( 'style' => array( 'color' => array( 'gradient' => 'linear-gradient(red, blue)', 'text' => 'red' ) ) ));
$assert(isset($cover['attrs']['style']['color']['text']) && ! isset($cover['attrs']['style']['color']['gradient']) && isset($cover['fallbackStyle']['color']['gradient']), 'Feature-level skip serialization moves Cover gradients into the carrier payload.');
$navigation = $runtime->normalizeBlockSupportAttributes('core/navigation', array( 'style' => array( 'spacing' => array( 'padding' => array( 'top' => '1rem' ) ), 'typography' => array( 'textDecoration' => 'underline' ), 'border' => array( 'width' => '2px' ) ) ));
$assert(isset($navigation['fallbackStyle']['spacing']['padding']['top']) && isset($navigation['fallbackStyle']['typography']['textDecoration']) && isset($navigation['fallbackStyle']['border']['width']), 'Navigation rejects unsupported spacing, skipped typography, and border.');
$navigationResult = ( new HtmlTransformer() )->transform('<nav class="menu" style="margin-left:auto"><a href="/">Home</a></nav>')->toArray();
$navigationCss = implode("\n", array_column($navigationResult['assets'] ?? array(), 'content'));
$assert(! isset($navigationResult['blocks'][0]['attrs']['style']['spacing']['margin']) && str_contains($navigationCss, '.wp-block-navigation.menu{margin-left:auto}'), 'Navigation carries metadata-rejected spacing on its rendered block class.');

$spacerResult = ( new HtmlTransformer() )->transform('<style>.signal{display:block;width:24px;height:4px;background:var(--green)}</style><main><span class="signal"></span></main>')->toArray();
$spacer = $spacerResult['blocks'][0]['innerBlocks'][0] ?? array();
$spacerCss = implode("\n", array_column($spacerResult['assets'] ?? array(), 'content'));
$assert(! isset($spacer['attrs']['style']['color']) && str_contains((string) ($spacer['attrs']['className'] ?? ''), 'be-inline-geometry-') && preg_match('/\.be-inline-geometry-[^{]+\{[^}]*background-color:var\(--green\)/', $spacerCss), 'Spacer carries metadata-rejected generated paint through deterministic CSS.');

$result = ( new HtmlTransformer() )->transform('<p style="min-height:12rem">Metadata carrier</p>')->toArray();
$block = $result['blocks'][0] ?? array();
$css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
$assert(! isset($block['attrs']['style']['dimensions']['minHeight']), 'Paragraph output omits unsupported dimensions attributes.');
$assert(! str_contains((string) ($result['serialized_blocks'] ?? ''), 'min-height:12rem') && str_contains($css, 'min-height:12rem'), 'Paragraph output retains unsupported min-height through the deterministic carrier.');
$assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? ''), 'Carrier-backed Paragraph output passes the WordPress parse/save validity check.');

$accordionHtml = '<div data-orientation="vertical"><div class="border-b"><h3><button type="button" aria-expanded="false" aria-controls="p1">Q?</button></h3><div id="p1" role="region"><p>A.</p></div></div><div class="border-b"><h3><button type="button" aria-expanded="false" aria-controls="p2">Q2?</button></h3><div id="p2" role="region"><p>A2.</p></div></div></div>';
$accordion = ( new HtmlTransformer() )->transform($accordionHtml)->toArray();
$serialized = (string) ($accordion['serialized_blocks'] ?? '');
$assert('core/accordion' === ($accordion['blocks'][0]['blockName'] ?? ''), 'Heading-wrapped ARIA toggles still emit core/accordion.');
$assert(! str_contains($serialized, 'id="p1"') && ! str_contains($serialized, '"anchor":"p1"'), 'Accordion panel save markup does not keep the source region id Gutenberg cannot round-trip.');
$assert(str_contains($serialized, 'role="region"') && str_contains($serialized, 'wp:accordion-panel'), 'Accordion panel still emits core save() role="region" wrapper.');

if ( 0 !== $failures ) exit(1);
fwrite(STDOUT, "Block support metadata normalization tests passed.\n");
