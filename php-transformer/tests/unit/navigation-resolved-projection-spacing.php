<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = '<style>.menu{display:flex;padding-left:20px}.menu a{display:block}</style>'
    . '<nav aria-label="Primary"><ul class="menu"><li><a href="/">Home</a></li><li><a href="/about/">About</a></li><li><a href="/contact/">Contact</a></li></ul></nav>';
$result = (new HtmlTransformer())->transform($html)->toArray();
$after = implode("\n", array_map(
    static fn(array $asset): string => 'after-author' === ($asset['stylesheet_placement'] ?? '') ? (string) ($asset['content'] ?? '') : '',
    array_filter($result['assets'] ?? array(), 'is_array')
));
$reset = '.wp-block-navigation.blocks-engine-list-navigation>.wp-block-navigation__container{padding:0!important;margin:0!important;border-width:0!important}';
$blocks = $result['blocks'] ?? array();
$navigation = $blocks[0] ?? array();
$links = $navigation['innerBlocks'] ?? array();
$labels = array_column(array_map(static fn(array $block): array => is_array($block['attrs'] ?? null) ? $block['attrs'] : array(), $links), 'label');
$projections = $result['source_reports']['html']['source_target_projections'] ?? array();

if ('core/navigation' !== ($navigation['blockName'] ?? '')
    || array('Home', 'About', 'Contact') !== $labels
    || array_filter($links, static fn(array $block): bool => array_key_exists('style', $block['attrs'] ?? array())) !== array()
    || 1 !== substr_count($after, $reset)
    || str_contains($after, '.wp-block-navigation-item{padding:0!important')
    || 1 !== count($projections)
    || 'nav:nth-of-type(1) > ul:nth-of-type(1)' !== ($projections[0]['source_selector'] ?? '')
    || '.wp-block-navigation.blocks-engine-list-navigation>.wp-block-navigation__container' !== ($projections[0]['target_selector'] ?? '')
    || 'padding:0!important;margin:0!important;border-width:0!important' !== ($projections[0]['declarations'] ?? '')
) {
    fwrite(STDERR, "Busy Bears navigation spacing projection failed\n");
    exit(1);
}

fwrite(STDOUT, "Busy Bears navigation spacing projection passed\n");
