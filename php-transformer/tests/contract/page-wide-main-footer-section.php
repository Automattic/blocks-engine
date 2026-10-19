<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Page chrome inside a page-wide <main>: the <main> wraps the page header,
// the route content and the footer, as some site builders export it. That
// <main> is a page-shell wrapper, so its header and page-final footer are
// shared parts. Section-level landmarks, footer-named elements that are not
// page-final, and footers whose content differs per route stay in content.

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

$header = '<header class="block-header"><a href="/">Brand</a><nav><ul><li><a href="/">Home</a></li><li><a href="/about/">About</a></li></ul></nav></header>';
$route = static fn(string $title, string $blocks): string => '<!DOCTYPE html><html><head><title>' . $title . '</title></head><body><div><main class="page"><div class="top-blocks">' . $header . '</div><div class="page__blocks">' . $blocks . '</div></main></div></body></html>';
$hero = static fn(string $title): string => '<section id="hero" class="block"><h1>' . $title . ' page</h1><p>' . $title . ' body.</p></section>';
$plan = static function (callable $blocks) use ($route): array {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
        'index.html' => $route('Home', $blocks('Home')),
        'about/index.html' => $route('About', $blocks('About')),
    )))->toWordPressSitePlanView();
    return $result['wordpress_site_plan'];
};
$areas = static function (array $plan): array {
    $areas = array_column($plan['template_parts'] ?? array(), 'area');
    sort($areas);
    return $areas;
};
$contents = static fn(array $plan): array => array_column($plan['pages'] ?? array(), 'canonical_block_markup', 'source_path');

// The issue fixture: a footer named by id at the end of the page-wide <main>.
$sectionFooter = $plan(static fn(string $title): string => $hero($title) . '<section id="footer" class="block"><p>© Brand</p></section>');
$assert(array('footer', 'header') === $areas($sectionFooter), 'A page-final section#footer inside a page-wide main extracts header and footer parts.');
$footerPart = current(array_filter($sectionFooter['template_parts'], static fn(array $part): bool => 'footer' === $part['area']));
$assert(str_contains((string) ($footerPart['canonical_block_markup'] ?? ''), '© Brand'), 'The footer part owns the shared footer copy.');
foreach ($contents($sectionFooter) as $source => $markup) {
    $assert(str_contains($markup, '"slug":"header"') && str_contains($markup, '"slug":"footer"'), "{$source} references both shared parts.");
    $assert(!str_contains($markup, '© Brand') && !str_contains($markup, '<nav'), "{$source} keeps no footer or header copy in page content.");
    $assert(str_contains($markup, 'body.</p>'), "{$source} keeps its route content.");
}

// A footer named by class, not id, is the same footer.
$classFooter = $plan(static fn(string $title): string => $hero($title) . '<div class="footer"><p>© Brand</p></div>');
$assert(array('footer', 'header') === $areas($classFooter), 'A page-final div.footer inside a page-wide main extracts a footer part.');

// A <footer> landmark inside the page-wide <main>.
$landmarkFooter = $plan(static fn(string $title): string => $hero($title) . '<footer id="footer"><p>© Brand</p></footer>');
$assert(array('footer', 'header') === $areas($landmarkFooter), 'A footer landmark inside a page-wide main extracts a footer part.');
foreach ($contents($landmarkFooter) as $source => $markup) $assert(!str_contains($markup, '© Brand'), "{$source} keeps no footer landmark copy in page content.");

// Section-level landmarks stay in content even inside a page-wide <main>.
$articleFooter = $plan(static fn(string $title): string => '<article><header><h2>' . $title . ' story</h2></header><p>' . $title . ' body.</p><footer><p>Posted by Brand</p></footer></article>');
$assert(!in_array('footer', $areas($articleFooter), true), 'An article footer inside a page-wide main is not a footer part.');
foreach ($contents($articleFooter) as $source => $markup) $assert(str_contains($markup, 'Posted by Brand') && str_contains($markup, 'story</h2>'), "{$source} keeps its article header and footer in content.");

// A footer whose content differs per route is not shared.
$routeFooter = $plan(static fn(string $title): string => $hero($title) . '<section id="footer" class="block"><p>© Brand ' . $title . '</p></section>');
$assert(!in_array('footer', $areas($routeFooter), true), 'A route-varying section#footer is not a footer part.');
foreach ($contents($routeFooter) as $source => $markup) $assert(str_contains($markup, '© Brand '), "{$source} keeps its route-specific footer in content.");

// A footer-named section that page content follows is not the page footer.
$midFooter = $plan(static fn(string $title): string => '<section id="footer" class="block"><p>© Brand</p></section>' . $hero($title));
$assert(!in_array('footer', $areas($midFooter), true), 'A section#footer that page content follows is not a footer part.');
foreach ($contents($midFooter) as $source => $markup) $assert(str_contains($markup, '© Brand'), "{$source} keeps its mid-page footer-named section in content.");

echo "page-wide-main-footer-section contract passed\n";
