<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$fixture = require dirname(__DIR__) . '/fixtures/excluded-route-shell.php';
$view = (new ArtifactCompiler())->compile($fixture)->toWordPressSitePlanView();
$plan = $view['wordpress_site_plan'];
$templates = array_column($plan['templates'], null, 'slug');
$parts = array_column($plan['template_parts'], 'canonical_block_markup', 'slug');
$resolve = static function (string $markup) use (&$resolve, $parts): string {
    return preg_replace_callback('/<!-- wp:template-part (\{.*?\}) \/-->/', static function (array $match) use (&$resolve, $parts): string {
        return $resolve($parts[json_decode($match[1], true)['slug']] ?? '');
    }, $markup);
};
$listing = current(array_filter($plan['pages'], static fn(array $page): bool => 'catalog/index.html' === $page['source_path']));
$assert(is_array($listing), 'The listing route remains a page.');
$key = $listing['metadata']['post_meta']['_wp_page_template'] ?? 'page-' . $listing['slug'];
$assert(isset($templates[$key]), 'The excluded listing page has an override template.');
$header = current(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
$assert(is_array($header) && str_contains($header['canonical_block_markup'], 'Article desk'), 'The article header family is extracted.');
$assert(in_array($key, $header['placement']['excluded_template_slugs'] ?? array(), true), 'The listing override is excluded from the dominant header.');
$rendered = $resolve($templates[$key]['canonical_block_markup'] . $listing['canonical_block_markup']);
foreach (array('Published by Field Notes', 'From the reading shelf', 'Catalog desk') as $text) {
    $assert(1 === substr_count($rendered, $text), 'The excluded listing renders its source chrome exactly once: ' . $text);
}
echo "excluded-route-shell: ok\n";
