<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$header = '<header><nav class="nav"><a href="/">Home</a><a href="/about/">About</a><a href="/about/#details">Details</a>'
    . '<button class="toggle" aria-label="Open menu" aria-expanded="false"><svg width="24" height="24" viewBox="0 0 24 24"><!-- bar -> stem --><path d="M2 12h20"/></svg></button></nav></header>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $header . '<main><h1>Home</h1></main>',
    'about/index.html' => $header . '<main><h1>About</h1><p id="details">Details</p></main>',
)))->toArray();
$assert(!str_contains(json_encode($result['source_reports']['wordpress_site_plan_diagnostics'] ?? array(), JSON_THROW_ON_ERROR), 'wordpress_site_plan_not_self_contained'), 'SVG comments must not invalidate the site plan.');
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$assert(2 === count($plan['pages'] ?? array()), 'Both routes must have pages.');
$menus = array_filter($plan['menus'] ?? array(), static fn(array $menu): bool => isset($menu['token'], $menu['block_markup']));
$assert(count($menus) > 0, 'The shared header must declare a navigation entity.');
$refs = 0;
$openerFound = false;
$markups = array_column(array_merge($plan['pages'] ?? array(), $plan['template_parts'] ?? array(), $plan['templates'] ?? array(), $plan['synced_patterns'] ?? array()), 'canonical_block_markup');
foreach ($plan['writes'] ?? array() as $write) {
    if ('utf8' === ($write['payload']['encoding'] ?? null)) $markups[] = $write['payload']['data'];
}
foreach ($markups as $markup) {
    $parsedRefs = 0;
    foreach (WordPressSitePlan::blockRanges($markup) as $range) {
        $block = substr($markup, $range['offset'], $range['length']);
        if (!preg_match('/^<!-- wp:navigation (\{.*\}) \/-->$/s', $block, $match)) continue;
        $attrs = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
        if (!isset($attrs['ref'])) continue;
        $assert(in_array($attrs['ref'], array_map(static fn(array $menu): string => WordPressSitePlan::NAVIGATION_TOKEN_PREFIX . $menu['token'] . '}}', $menus), true), 'Each parsed reference must resolve to a declared menu.');
        $svg = $attrs['metadata']['blocksEngineNavigationOpener']['svg'] ?? '';
        if (str_contains($svg, '<!-- bar -> stem -->')) $openerFound = true;
        ++$parsedRefs;
    }
    $assert(substr_count($markup, WordPressSitePlan::NAVIGATION_TOKEN_PREFIX) === $parsedRefs, 'Every navigation ref delimiter must parse as a complete block.');
    $refs += $parsedRefs;
}
$assert($refs > 0 && $openerFound, 'Parsed navigation refs must preserve the toggle SVG comment.');

echo "wordpress-site-plan-navigation-attribute-escaping: ok\n";
