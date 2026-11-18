<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// A utility bar that precedes the top-level <header> as its <body> sibling is
// leading chrome: the header part starts with it, in source order, and it
// leaves page content so it renders above the header on every route.
$html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Utility</title>'
    . '<style>.utility-inner{display:flex;justify-content:space-between;min-height:34px}.site-header{position:sticky;top:0;background:#f8f6f1}.nav-inner{display:flex;justify-content:space-between;min-height:94px}.main-nav{display:flex;gap:25px}</style></head><body>'
    . '<div class="utility-bar"><div class="utility-inner"><span>A parish in the city</span><a href="https://example.org/nl">Nederlands</a></div></div>'
    . '<header class="site-header"><div class="nav-inner"><a class="brand" href="#top">Brand</a><nav class="main-nav" aria-label="Main"><a href="#a">Home</a><a href="#b">About</a></nav></div></header>'
    . '<main id="top"><h1>Welcome</h1><p id="a">Intro.</p><p id="b">More.</p></main>'
    . '<footer><p>© Footer</p></footer></body></html>';
$cases = array(
    'one page' => array('index.html' => $html),
    'two pages' => array('index.html' => $html, 'about.html' => str_replace(array('Welcome', 'Intro.'), array('About us', 'Other copy.'), $html)),
);
foreach ($cases as $label => $files) {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
    $plan = (new WordPressSitePlan())->fromResult($result);
    $headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
    $assert(1 === count($headers), "$label: expected one header part, found " . count($headers));
    $header = $headers[0]['canonical_block_markup'];
    $bar = strpos($header, 'A parish in the city');
    $landmark = strpos($header, '"tagName":"header"');
    $assert(false !== $bar && false !== $landmark && $bar < $landmark, "$label: the header part must hold the utility bar before the header landmark");
    $assert(1 === substr_count($header, 'A parish in the city') && str_contains($header, 'wp:navigation'), "$label: the header part must hold one utility bar and the navigation");
    foreach ($plan['pages'] as $page) {
        $markup = $page['canonical_block_markup'];
        $assert(!str_contains($markup, 'A parish in the city') && !str_contains($markup, 'utility-bar'), "$label: the utility bar must leave page content");
        $assert(str_contains($markup, '"tagName":"main"'), "$label: page content must remain");
    }
    foreach ($plan['templates'] as $template) {
        $assert(str_contains($template['canonical_block_markup'], '"slug":"' . $headers[0]['slug'] . '"'), "$label: template {$template['slug']} must place the header part");
    }
}
fwrite(STDOUT, "Leading utility bar header shell tests passed\n");
