<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = (new HtmlTransformer())->transform(
    '<style>.site-nav a.cta{background:#111;color:#fff}.dropdown-panel{box-shadow:0 8px 24px #000}</style>'
    . '<nav class="site-nav"><ul><li><a class="cta current" href="/home">Home</a></li>'
    . '<li class="menu-item"><a href="/services">Services</a><ul class="dropdown-panel"><li><a href="/design">Design</a></li></ul></li></ul></nav>',
    array()
)->toArray();

$markup = (string) ($result['serialized_blocks'] ?? '');
$assets = is_array($result['assets'] ?? null) ? $result['assets'] : array();
$css = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $assets));
$provenance = $result['source_reports']['html']['source_provenance'] ?? array();
$submenuOwnership = array_values(array_filter(
    is_array($provenance) ? $provenance : array(),
    static fn (array $entry): bool => 'core/navigation-submenu' === ($entry['block_name'] ?? '')
));
$anchorOwnership = array_values(array_filter(
    is_array($provenance) ? $provenance : array(),
    static fn (array $entry): bool => 'cta current' === ($entry['navigation_source_ownership']['anchor']['class_name'] ?? null)
));

$assertions = array(
    array(! str_contains($markup, 'anchorClassName') && ! str_contains($markup, 'submenuClassName'), 'navigation comments contain only registered attributes'),
    array(str_contains($markup, 'blocks-engine-current-navigation-item'), 'current-item classes survive without source-only attributes'),
    array(str_contains($css, '.wp-block-navigation-item.cta>.wp-block-navigation-item__content{background:#111;color:#fff}'), 'anchor-owned CTA CSS projects from provenance onto the rendered anchor'),
    array('dropdown-panel' === ($submenuOwnership[0]['navigation_source_ownership']['submenu']['class_name'] ?? null), 'submenu source ownership is retained in the stable source report'),
    array('cta current' === ($anchorOwnership[0]['navigation_source_ownership']['anchor']['class_name'] ?? null), 'anchor source ownership is retained in the stable source report'),
);

$failures = array_map(
    static fn (array $assertion): string => $assertion[1],
    array_filter($assertions, static fn (array $assertion): bool => ! $assertion[0])
);
if ( array() !== $failures ) {
    fwrite(STDERR, "Navigation source provenance contract failed:\n" . implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Navigation source provenance contract passed: ' . count($assertions) . " assertions\n";
