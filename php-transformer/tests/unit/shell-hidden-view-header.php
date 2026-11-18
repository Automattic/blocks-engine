<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeTemplates;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// A single-page app: an announcement bar and a body-level <nav> are the site
// chrome, then base-hidden page views, then a body-level <footer>. One view
// holds its own <header>; that view header is content, not the site header.
$html = '<!doctype html><html><head><style>.page{display:none}.page.active{display:block}</style></head><body>'
    . '<div class="announcement"><p>Free shipping on every order</p></div>'
    . '<nav><span class="nav-logo" role="button"><img src="logo.png" alt="Site"></span><ul class="nav-links"><li><a href="#collections">Collections</a></li><li><a href="#about">About</a></li></ul></nav>'
    . '<div id="home-page" class="page active"><h1>Home view</h1><p>Home copy</p></div>'
    . '<div id="account-page" class="page"><div class="account"><header class="hdr"><p>Member header</p><a href="#orders">Orders</a></header><p>Account copy</p></div></div>'
    . '<footer><section class="footer-list"><p>Sign up for news</p></section><div class="footer-top"><a href="#privacy">Privacy</a></div></footer>'
    . '</body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['source_reports']['wordpress_site_plan'];

$parts = array();
foreach ($plan['template_parts'] as $part) $parts[$part['area']][] = $part;
$assert(1 === count($parts['header'] ?? array()), 'expected one header part, found ' . count($parts['header'] ?? array()));
$assert(1 === count($parts['footer'] ?? array()), 'expected one footer part, found ' . count($parts['footer'] ?? array()));
$header = $parts['header'][0]['canonical_block_markup'];
$footer = $parts['footer'][0]['canonical_block_markup'];
$assert(!str_contains($header, 'Member header'), 'a header inside a hidden page view must not become the site header part');
$assert(str_contains($header, 'wp:navigation') && str_contains($header, 'Free shipping'), 'the body-level announcement and navigation cluster must become the header part');
$assert(str_contains($footer, 'Sign up for news') && str_contains($footer, 'Privacy'), 'the body-level footer must become the footer part');
$assert(array() !== $plan['menus'], 'the header navigation must be backed by a saved menu');

foreach (ThemeTemplates::rendered($plan) as $slug => $template) {
    $assert(1 === substr_count($template, '"slug":"' . $parts['header'][0]['slug'] . '"'), "template {$slug} must reference the header part once");
    $assert(1 === substr_count($template, '"slug":"' . $parts['footer'][0]['slug'] . '"'), "template {$slug} must reference the footer part once");
}

$page = $plan['pages'][0]['canonical_block_markup'];
$assert(!str_contains($page, 'wp:template-part'), 'page content must not carry a shell reference in a view position');
$assert(!str_contains($page, 'wp:navigation') && !str_contains($page, 'Sign up for news'), 'site chrome must leave page content');
$assert(str_contains($page, 'Member header') && str_contains($page, 'Account copy') && str_contains($page, 'Home view'), 'page views, including the view header, must remain page content');

fwrite(STDOUT, "Shell hidden view header tests passed\n");
