<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\BlockFactory;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$fixture = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/contract/wordpress-7.1-editor-save-shapes.json'), true);
if ( 'blocks-engine/php-transformer/wordpress-editor-save-shapes/v1' !== ($fixture['schema'] ?? null)
    || 'WordPress/wordpress-develop@7.1 wp.blocks.getSaveContent' !== ($fixture['source'] ?? null) ) {
    throw new RuntimeException('WordPress 7.1 editor save-shape fixture provenance is missing.');
}

$factory = new BlockFactory();
foreach ( $fixture['cases'] ?? array() as $case ) {
    $block = $factory->create((string) $case['block_name'], (array) $case['attributes']);
    if ( ($case['expected_inner_html'] ?? null) !== ($block['innerHTML'] ?? null) ) {
        throw new RuntimeException('WordPress 7.1 save shape mismatch for ' . (string) ($case['name'] ?? 'unknown') . '.');
    }
}

$button = ( new HtmlTransformer() )->transform(
    '<style>.cta{display:inline-flex;width:100%;padding:10px 18px;background:#111827;color:#fff}</style><main><a class="cta" href="/pricing">Start free</a></main>'
)->toArray();
$buttonBlock = $button['blocks'][0]['innerBlocks'][0]['innerBlocks'][0] ?? array();
$buttonMarkup = (string) ($button['serialized_blocks'] ?? '');
$buttonCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $button['assets'] ?? array()));
if ( isset($buttonBlock['attrs']['width'])
    || str_contains($buttonMarkup, 'has-custom-width')
    || str_contains($buttonMarkup, 'wp-block-button__width-100')
    || ! str_contains($buttonCss, '.wp-block-button){display:block!important;margin:0!important;width:100%!important}')
    || ! str_contains($buttonCss, '.wp-block-button__link){box-sizing:border-box;width:100%!important}') ) {
    throw new RuntimeException('Full-width button must use the WordPress 7.1 save shape while preserving desktop width through generated CSS.');
}

$image = ( new HtmlTransformer() )->transform(
    '<main><svg width="100%" viewBox="0 0 620 380" role="img" aria-label="Responsive map"><rect width="620" height="380"/></svg></main>'
)->toArray();
$imageMarkup = (string) ($image['serialized_blocks'] ?? '');
if ( ! str_contains($imageMarkup, 'style="width:100%;height:auto"') || str_contains($imageMarkup, 'height:380px') ) {
    throw new RuntimeException('Percentage-width core/image must match the WordPress 7.1 height:auto save shape.');
}

fwrite(STDOUT, "WordPress 7.1 save-shape tests passed.\n");
