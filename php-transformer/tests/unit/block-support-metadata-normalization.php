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

$result = ( new HtmlTransformer() )->transform('<p style="min-height:12rem">Metadata carrier</p>')->toArray();
$block = $result['blocks'][0] ?? array();
$css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
$assert(! isset($block['attrs']['style']['dimensions']['minHeight']), 'Paragraph output omits unsupported dimensions attributes.');
$assert(! str_contains((string) ($result['serialized_blocks'] ?? ''), 'min-height:12rem') && str_contains($css, 'min-height:12rem'), 'Paragraph output retains unsupported min-height through the deterministic carrier.');
$assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? ''), 'Carrier-backed Paragraph output passes the WordPress parse/save validity check.');

if ( 0 !== $failures ) exit(1);
fwrite(STDOUT, "Block support metadata normalization tests passed.\n");
