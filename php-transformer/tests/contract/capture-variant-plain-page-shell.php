<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };
$writes = static function (array $plan): array { $rows = array(); foreach ($plan['writes'] as $write) $rows[$write['target_path']] = $write; return $rows; };

// Reduced issue fixture: three routes captured with desktop + mobile
// capture-document scopes and one plain route (as when its mobile capture
// fails). Every document is utility bar -> header -> main -> footer, with an
// empty widget container in the footer that one route's capture populated.
$navigation = '<!-- wp:navigation {"overlayMenu":"never"} --><!-- wp:navigation-link {"label":"News","url":"/news/","kind":"custom"} /--><!-- wp:navigation-link {"label":"Our Parent Section","url":"/parent/","kind":"custom"} /--><!-- wp:navigation-link {"label":"Child","url":"/parent/child/","kind":"custom"} /--><!-- /wp:navigation -->';
$utility = '<!-- wp:group {"anchor":"secondary-nav","className":"utility-bar"} --><div id="secondary-nav" class="wp-block-group utility-bar"><!-- wp:paragraph --><p><a href="tel:+15550100">555-0100</a> · <a href="/news/">News</a></p><!-- /wp:paragraph --></div><!-- /wp:group -->';
$header = static function (string $class, string $inner): string {
    return '<!-- wp:group {"className":"' . $class . '","tagName":"header"} --><header class="wp-block-group ' . $class . '">' . $inner . '</header><!-- /wp:group -->';
};
$desktopHeader = $header('site-header', '<!-- wp:paragraph --><p><a href="/">Shore Lane Cooperative</a></p><!-- /wp:paragraph -->' . $navigation);
$mobileHeader = $header('site-header site-header-compact', $navigation);
$footer = static function (string $class, string $widgetContent): string {
    $widget = '<!-- wp:group {"anchor":"translate-widget"} --><div id="translate-widget" class="wp-block-group">' . $widgetContent . '</div><!-- /wp:group -->';
    return '<!-- wp:group {"className":"' . $class . '","tagName":"footer"} --><footer class="wp-block-group ' . $class . '"><!-- wp:paragraph --><p>Shore Lane Cooperative · 1 Shore Lane</p><!-- /wp:paragraph -->' . $widget . '</footer><!-- /wp:group -->';
};
$populatedWidget = '<!-- wp:paragraph --><p><span>Select Language</span></p><!-- /wp:paragraph -->';
$main = static fn(string $title): string => '<!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">' . $title . '</h2><!-- /wp:heading --></main><!-- /wp:group -->';
$variantDocument = static function (string $class, string $headerMarkup, string $footerMarkup, string $title) use ($utility, $main): string {
    return '<!-- wp:group {"className":"' . $class . '"} --><div class="wp-block-group ' . $class . '">' . $utility . $headerMarkup . $main($title) . $footerMarkup . '</div><!-- /wp:group -->';
};
$dualCapture = static function (string $title, string $widgetContent = '') use ($variantDocument, $desktopHeader, $mobileHeader, $footer): string {
    return $variantDocument(CaptureEvidence::DESKTOP_DOCUMENT_CLASS, $desktopHeader, $footer('site-footer', $widgetContent), $title)
        . $variantDocument(CaptureEvidence::MOBILE_DOCUMENT_CLASS, $mobileHeader, $footer('site-footer site-footer-compact', $widgetContent), $title . ' mobile');
};
$plainDocument = static function (string $headerMarkup, string $footerMarkup, string $title) use ($utility, $main): string {
    return '<!-- wp:group {"className":"site-wrap"} --><div class="wp-block-group site-wrap">' . $utility . $headerMarkup . $main($title) . $footerMarkup . '</div><!-- /wp:group -->';
};

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<main><h1>Home</h1></main>',
    'news/index.html' => '<main><article><time itemprop="datePublished" datetime="2026-08-01">News</time></article></main>',
    'odd-news/index.html' => '<main><article><time itemprop="datePublished" datetime="2026-08-02">Odd news</time></article></main>',
    'parent/child/index.html' => '<main><h1>Child</h1></main>',
)))->toArray();
foreach ($result['source_reports']['compiled_site']['pages'] as &$page) {
    $page['block_markup'] = match ($page['source_path']) {
        'index.html' => $dualCapture('Home'),
        'news/index.html' => $dualCapture('News'),
        'odd-news/index.html' => $dualCapture('Odd news', $populatedWidget),
        'parent/child/index.html' => $plainDocument($desktopHeader, $footer('site-footer', ''), 'Child'),
    };
}
unset($page);
$plan = (new WordPressSitePlan())->fromResult($result);
WordPressSitePlan::assertValid($plan);
$declaredWrites = $writes($plan);
$pages = array_column($plan['pages'], null, 'source_path');

// The generic fallback templates always carry the dominant shared shell, so a
// page or post created later renders the site chrome.
foreach (array('page', 'single') as $slug) {
    $template = (string) ($declaredWrites['templates/' . $slug . '.html']['payload']['data'] ?? '');
    $assert(1 === substr_count($template, '"slug":"header"') && 1 === substr_count($template, '"slug":"footer"'), "The generic {$slug} template binds the shared header and footer once: {$template}");
}

// The plain page's landmarks equal the rank-0 (desktop) variant identity, so
// it is adopted: its content no longer carries the header/footer copy.
$child = (string) ($pages['parent/child/index.html']['canonical_block_markup'] ?? '');
$assert(!str_contains($child, 'site-header') && !str_contains($child, 'site-footer') && !str_contains($child, '<!-- wp:navigation ') && str_contains($child, '>Child</h2>'), "The plain page keeps its content and no header/footer copy: {$child}");
$assert(!isset($declaredWrites['templates/page-child.html']), 'The adopted plain page uses the generic page template.');

// The shared parts hold both capture variants; the route whose footer capture
// populated the widget keeps that footer page-owned behind its own template.
$parts = array_column($plan['template_parts'], null, 'area');
$assert(str_contains((string) ($parts['header']['canonical_block_markup'] ?? ''), CaptureEvidence::DESKTOP_DOCUMENT_CLASS) && str_contains((string) $parts['header']['canonical_block_markup'], CaptureEvidence::MOBILE_DOCUMENT_CLASS), 'The shared header part renders both capture variants.');
$oddNews = (string) ($pages['odd-news/index.html']['canonical_block_markup'] ?? '');
$oddNewsTemplate = (string) ($declaredWrites['templates/single-post-odd-news.html']['payload']['data'] ?? '');
$assert(1 === substr_count($oddNewsTemplate, '"slug":"header"') && !str_contains($oddNewsTemplate, '"slug":"footer"') && str_contains($oddNews, 'Select Language'), 'The route with a divergent footer keeps it page-owned behind its own template with the shared header.');
foreach (array('index.html', 'news/index.html') as $source) {
    $markup = (string) ($pages[$source]['canonical_block_markup'] ?? '');
    $assert(!str_contains($markup, 'site-header') && !str_contains($markup, 'site-footer'), "{$source} no longer carries the shared shell.");
}

// When the plain page cannot be adopted (its chrome differs), the generic page
// template still carries the dominant shell: the divergent route gets its own
// override template instead of suppressing the fallback.
$distinctResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<main><h1>Home</h1></main>',
    'news/index.html' => '<main><article><time itemprop="datePublished" datetime="2026-08-01">News</time></article></main>',
    'parent/child/index.html' => '<main><h1>Child</h1></main>',
)))->toArray();
foreach ($distinctResult['source_reports']['compiled_site']['pages'] as &$page) {
    $page['block_markup'] = match ($page['source_path']) {
        'index.html' => $dualCapture('Home'),
        'news/index.html' => $dualCapture('News'),
        'parent/child/index.html' => $plainDocument($header('child-header', '<!-- wp:paragraph --><p>Child banner</p><!-- /wp:paragraph -->'), $footer('child-footer', ''), 'Child'),
    };
}
unset($page);
$distinct = (new WordPressSitePlan())->fromResult($distinctResult);
WordPressSitePlan::assertValid($distinct);
$distinctWrites = $writes($distinct);
$distinctPage = (string) ($distinctWrites['templates/page.html']['payload']['data'] ?? '');
$distinctChildTemplate = (string) ($distinctWrites['templates/page-child.html']['payload']['data'] ?? '');
$distinctChild = (string) (array_column($distinct['pages'], null, 'source_path')['parent/child/index.html']['canonical_block_markup'] ?? '');
$assert(1 === substr_count($distinctPage, '"slug":"header"') && 1 === substr_count($distinctPage, '"slug":"footer"'), "The generic page template binds the shared shell even when the only inner page diverges: {$distinctPage}");
$assert('' !== $distinctChildTemplate && !str_contains($distinctChildTemplate, '"slug":"header"') && !str_contains($distinctChildTemplate, '"slug":"footer"') && str_contains($distinctChild, 'child-header') && str_contains($distinctChild, 'child-footer'), 'The divergent plain page keeps its own chrome behind its override template.');

fwrite(STDOUT, "Capture-variant plain-page shell contract passed.\n");
