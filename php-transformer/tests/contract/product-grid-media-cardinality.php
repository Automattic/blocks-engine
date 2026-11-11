<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// Main + hover images compile as a gallery; a lone main image and its
// Quick View control compile as separate leaves in the same media region.
$html = '<a href="/cart/">Cart</a>' . file_get_contents(dirname(__DIR__) . '/fixtures/commerce/media-cardinality-catalog.html');
// Importers compile under their own generated-block namespace; media
// recognition must not depend on the default one.
foreach (array('custom', 'ssi-example') as $namespace) {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'shop.html', 'files' => array('shop.html' => $html)) + ('custom' === $namespace ? array() : array('block_namespace' => $namespace)))->toArray();
    $assert('failed' !== $result['status'] && ! isset($result['source_reports']['wordpress_site_plan_diagnostics']), "[$namespace] The catalog composes cleanly.");
    $plan = $result['source_reports']['wordpress_site_plan'] ?? array();
    $grids = current(array_filter($plan['runtime_declarations'] ?? array(), static fn (array $declaration): bool => 'product_grids' === ($declaration['type'] ?? null))) ?: array();
    $entities = $grids['payload']['entities'] ?? array();
    $assert(1 === count($entities), 'The catalog declares one grid.');
    $grid = $entities[0];
    $assert(4 === count($grid['items'] ?? array()), 'All four products belong to the grid.');
    $media = array_values(array_filter($grid['item_template']['slots'] ?? array(), static fn (array $slot): bool => preg_match('~^' . $namespace . '/(?:gallery-|responsive-media)~', $slot['block_name']) === 1 || preg_match('~wp:' . $namespace . '/(?:gallery-|responsive-media)~', $grid['items'][0]['slots'][$slot['id']]['markup'] ?? '') === 1));
    $assert(1 === count($media), 'The template has one product media slot: ' . json_encode($media));
    $assert(false === ($media[0]['optional'] ?? false), 'The media slot is required even with different image counts: ' . json_encode($media));
    foreach ($grid['items'] as $item) {
        $markup = $item['slots'][$media[0]['id']]['markup'] ?? '';
        $assert(preg_match('~wp:' . $namespace . '/(?:gallery-|responsive-media)~', $markup) === 1 && str_contains($markup, '{{wordpress-site-plan:asset:'), 'Every product fills the media slot with its own main image: ' . json_encode($item));
    }
    $assert(str_contains($grid['item_template']['block_markup'], 'wp:' . $namespace . '/gallery-') && str_contains($grid['item_template']['block_markup'], '/product-page/silky-oak-board/'), 'The lone single-image card does not displace the representative hover-image card.');
}

echo "product-grid-media-cardinality: ok\n";
