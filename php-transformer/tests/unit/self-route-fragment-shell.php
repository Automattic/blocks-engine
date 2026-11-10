<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$fixture = require dirname(__DIR__) . '/fixtures/self-route-fragment-shell.php';
$compile = static fn(array $input): array => (new ArtifactCompiler())->compile($input)->toWordPressSitePlanView()['wordpress_site_plan'];
$plan = $compile($fixture);
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
$assert(1 === count($headers), 'Self-route fragment navigation produces one shared header.');
$assert(2 === count($plan['pages']), 'Both routes remain pages.');
foreach ($plan['pages'] as $page) {
    $assert(!str_contains($page['canonical_block_markup'], 'site-header'), 'No page retains the header inline.');
}
$menu = $plan['menus'][0];
$assert(str_contains($headers[0]['canonical_block_markup'], 'navigation:' . $menu['token']), 'The shared header references its navigation menu.');
preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/-->/', $menu['block_markup'], $links);
$urls = array_map(static fn(string $json): string => json_decode($json, true)['url'], $links[1]);
$assert(array('#about', '#contact') === $urls, 'Shared navigation targets the current document sections.');

// Cross-route fragments must not acquire same-document identity.
$fixture['files'][1]['content'] = str_replace('/services/index.html#', '/index.html#', $fixture['files'][1]['content']);
$other = $compile($fixture);
$assert(str_contains(implode('', array_column($other['menus'], 'block_markup')), '"url":"/#about"'), 'A shared link to another route retains its destination.');
echo "self-route-fragment-shell: ok\n";
