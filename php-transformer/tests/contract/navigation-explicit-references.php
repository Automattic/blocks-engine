<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\NavigationEntityProjection;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$link = static fn(string $fragment, string $extra = ''): string => '<!-- wp:navigation-link {"label":"Section","url":"/guide/#' . $fragment . '"' . $extra . '} /-->';
$nav = static fn(string $inner, string $class): string => '<!-- wp:navigation {"className":"' . $class . '","overlayMenu":"never"} -->' . $inner . '<!-- /wp:navigation -->';
$nested = '<!-- wp:navigation-submenu {"label":"Guide","url":"/guide/"} -->' . $link('one') . '<!-- /wp:navigation-submenu -->';
$flat = '<!-- wp:navigation-link {"label":"Guide","url":"/guide/"} /-->' . $link('one');
$inner = $nav($link('one'), 'desktop') . '<!-- wp:details --><details><summary>Menu</summary>' . $nav($link('one'), 'mobile') . '</details><!-- /wp:details -->';
$projected = NavigationEntityProjection::project(array(array('source_path' => 'index.html', 'canonical_block_markup' => $inner)), array(), array());
$assert(1 === count($projected['menus']), 'Equivalent menu content shares across different host presentation.');
$markup = $projected['pages'][0]['canonical_block_markup'];
$assert(2 === substr_count($markup, WordPressSitePlan::NAVIGATION_TOKEN_PREFIX), 'Every recognized occurrence has an explicit reference.');
$assert(str_contains($markup, '<details><summary>Menu</summary><!-- wp:navigation') && str_contains($markup, '"className":"mobile"') && str_contains($markup, '"className":"desktop"'), 'The mobile host retains its actual disclosure ancestry and host attributes.');
$assert($projected['pages'][0]['content_hash'] === hash('sha256', $markup), 'Occurrence replacement updates canonical document hashes.');
$different = NavigationEntityProjection::project(array(array('source_path' => 'index.html', 'canonical_block_markup' =>
    $nav($link('one'), 'a') . $nav($link('two'), 'b') . $nav($nested, 'c') . $nav($flat, 'd')
    . $nav($link('one', ',"opensInNewTab":true'), 'e') . $nav($link('one', ',"className":"paint-red"'), 'f'))), array(), array());
$assert(6 === count($different['menus']), 'Fragments, submenu hierarchy, link behavior, and item paint remain distinct.');
$protected = NavigationEntityProjection::project(array(array('source_path' => 'index.html', 'canonical_block_markup' => $inner)), array(), array(), array(array('payload' => array('entities' => array(array('bindings' => array(array('source_path' => 'index.html', 'position' => array('offset' => 0, 'length' => strlen($inner))))))))));
$assert($protected['pages'][0]['canonical_block_markup'] === $inner && array() === $protected['menus'], 'Provider-owned anchors remain exact rather than being detached by navigation factoring.');

$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<header><nav><a href="#one">Section</a></nav></header><main><h1>Guide</h1><section id="one">One</section></main>')))->toArray()['source_reports']['wordpress_site_plan'];
$resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => 'https://destination.example/themes/guide'));
$assert($resolved['menus'][0]['resolved_block_markup'] === $plan['menus'][0]['block_markup'], 'The real resolver projects entity content without binding destination-owned IDs.');
$assert(str_contains(implode('', array_column($resolved['template_parts'], 'resolved_block_markup')), WordPressSitePlan::NAVIGATION_TOKEN_PREFIX), 'Entity refs survive asset resolution for the WordPress materializer.');
foreach (array('duplicate', 'unknown', 'malformed', 'unbound') as $case) {
    $bad = $plan;
    if ('duplicate' === $case) $bad['menus'][] = $bad['menus'][0];
    elseif ('unknown' === $case) $bad['menus'] = array();
    elseif ('malformed' === $case) $bad['menus'][0]['token'] = 'invalid';
    else $bad['menus'][0]['token'] = 'navigation-aaaaaaaaaaaaaaaa';
    try { NavigationEntityProjection::assertReferences($bad); throw new RuntimeException('Invalid navigation contract was accepted: ' . $case); }
    catch (InvalidArgumentException) { }
}
echo "navigation-explicit-references: ok\n";
