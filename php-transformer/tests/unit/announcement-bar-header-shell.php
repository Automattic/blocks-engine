<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// A one-page app whose <main> wraps the whole document: a text announcement
// bar, then the site navigation, then the sections and the footer. The bar and
// the navigation are the masthead; every template must place one header part.
$nav = '<nav class="site-nav" aria-label="Main navigation"><a class="brand" href="#top">ZACH <em>RICHARDS</em></a><div class="nav-links"><a href="#about">About</a><a href="#book">Audiobook</a></div></nav>';
$sections = '<section class="hero" id="top"><h1>Hero</h1></section><section id="about"><p>About copy</p></section><section id="book"><p>Book copy</p></section><footer class="footer"><p>© 2026 Zach</p></footer>';
$cases = array(
    'announcement bar' => '<div id="root"><main class="noise"><div class="top-banner">CONCEPT MOCKUP FOR <strong>ZACH RICHARDS</strong></div>' . $nav . $sections . '</main></div>',
    'navigation first' => '<div id="root"><main class="noise">' . $nav . $sections . '</main></div>',
);
foreach ($cases as $label => $html) {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $plan = (new WordPressSitePlan())->fromResult($result);
    $headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
    $assert(1 === count($headers), "$label: expected one header part, found " . count($headers));
    $header = $headers[0];
    $assert(str_contains($header['canonical_block_markup'], 'wp:navigation'), "$label: the header part must hold the navigation");
    if ('announcement bar' === $label) $assert(str_contains($header['canonical_block_markup'], 'CONCEPT MOCKUP'), "$label: the header part must hold the announcement bar above the navigation");
    foreach ($plan['templates'] as $template) {
        $assert(str_contains($template['canonical_block_markup'], '"slug":"' . $header['slug'] . '"'), "$label: template {$template['slug']} must place the header part");
    }
    foreach ($plan['pages'] as $page) {
        $markup = $page['canonical_block_markup'];
        $assert(!str_contains($markup, 'wp:template-part') && !str_contains($markup, 'CONCEPT MOCKUP') && !str_contains($markup, 'wp:navigation'), "$label: the masthead must leave page content");
        $assert(str_contains($markup, 'Hero') && str_contains($markup, 'Book copy'), "$label: page sections must remain");
    }
}
fwrite(STDOUT, "Announcement bar header shell tests passed\n");
