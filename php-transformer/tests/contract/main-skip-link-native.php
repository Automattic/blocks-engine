<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// The issue's minimal case: the only at-rest painted content survives, while
// neither a paragraph nor a button is emitted for the off-screen shortcut.
$minimal = '<!doctype html><html><head><style>.skip{position:absolute;left:-9999px}.skip:focus{left:8px;top:8px}</style></head><body><a class="skip" href="#main">Skip to content</a><header>…</header><main id="main"><h1>Hi</h1></main></body></html>';
$compile = static function (string $html, string $path = 'index.html'): string {
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => $path, 'files' => array($path => $html)))->toWordPressSitePlanView()['wordpress_site_plan'];
    return implode('', array_column($plan['pages'], 'canonical_block_markup'))
        . implode('', array_column($plan['template_parts'] ?? array(), 'canonical_block_markup'));
};
$fixture = (string) file_get_contents(__DIR__ . '/fixtures/source-skip-link.html');
foreach (array('/diensten/', '/diensten/index.html', 'index.html', './index.html', '../diensten/index.html') as $href) {
    $markup = $compile(str_replace('/diensten/index.html#', $href . '#', $fixture), 'website/diensten/index.html');
    $assert(! str_contains($markup, 'Ga direct') && ! str_contains($markup, 'wp:button'), 'Jimdo self-URL skip link is suppressed: ' . $href);
    $assert(1 === preg_match('/<main\\b[^>]*\\bid="main-content"/', $markup), 'Jimdo main retains its anchor.');
}
$assert(str_contains($compile(str_replace('/diensten/index.html#', 'diensten/index.html#', $fixture)), 'Ga direct'), 'A relative path to another document stays.');
foreach (array('/other/index.html', 'https://example.com/diensten/index.html', '//example.com/diensten/index.html', '/diensten/index.html?other=1') as $href) {
    $assert(str_contains($compile(str_replace('/diensten/index.html#', $href . '#', $fixture), 'website/diensten/index.html'), 'Ga direct'), 'Links to other documents stay: ' . $href);
}
$assert(str_contains($compile(str_replace('.jw-skip-link:not(:focus)', '.unrelated:not(:focus)', $fixture), 'website/diensten/index.html'), 'Ga direct'), 'A visible base alone is not a skip link.');
$markup = $compile($minimal);
$jimdo = str_replace('.skip{position:absolute;left:-9999px}.skip:focus{left:8px;top:8px}', '.skip{clip:auto;clip-path:none;left:10px;top:10px;position:absolute}.skip:not(:focus){clip:rect(0,0,0,0);height:1px;overflow:hidden;width:1px}', $minimal);
$assert(! str_contains($compile(str_replace('href="#main"', 'href="/#main"', $jimdo)), 'Skip to content'), 'Jimdo home-fragment link with resting-state clipping is removed on focus.');
$assert(! str_contains($markup, 'Skip to content') && ! str_contains($markup, 'wp:button'), 'The minimal skip link emits no paragraph or button.');
$assert(1 === preg_match('/<main\\b[^>]*\\bid="main"/', $markup), 'The minimal main group retains its anchor.');
$assert(str_contains($markup, 'Hi</h1>') && str_contains($markup, '…'), 'At-rest visible content is unchanged.');
$assert(! str_contains($compile(str_replace('<main id="main"><h1>', '<main><h1 id="main">', $minimal)), 'Skip to content'), 'A first-descendant target is also a skip link.');
$assert(! str_contains($compile(str_replace(array('<main', '</main>'), array('<div role="main"', '</div>'), $minimal)), 'Skip to content'), 'An explicit main landmark is supported.');
$assert(str_contains($compile(str_replace('left:-9999px', 'left:8px', $minimal)), 'Skip to content'), 'A visible fragment shortcut stays.');
$assert(str_contains($compile(str_replace('.skip:focus{left:8px;top:8px}', '', $minimal)), 'Skip to content'), 'An off-screen link without a focus reveal is not inferred to be a skip link.');
$assert(str_contains($compile(str_replace('<body>', '<body><button>First</button>', $minimal)), 'Skip to content'), 'A later focusable link stays.');

// Every route opens with the author's skip link to its <main>. A WordPress
// block template renders its own skip link to the template's first <main>,
// reusing that element's id, so the authored link would be a second one.
// It is not compiled, the <main> keeps its id, and the header that follows
// the skip link becomes the routes' shared part. Fragment links that do not
// skip to that <main> stay where the author put them.
$routes = array('index' => 'Home', 'about' => 'About', 'visit' => 'Visit');
$page = static function (string $current) use ($routes): string {
    $menu = '';
    foreach ($routes as $slug => $label) $menu .= '<li><a href="' . $slug . '.html">' . $label . '</a></li>';
    return '<!doctype html><html><head><title>' . $routes[$current] . '</title><style>.skip-link{position:absolute;top:-40px}.skip-link:focus{top:0}.primary a{color:#123456}</style></head><body>'
        . '<a class="skip-link" href="#main">Skip to content</a>'
        . '<header><a class="brand" href="index.html">Harbor</a><nav class="primary"><ul>' . $menu . '</ul></nav></header>'
        . '<main id="main"><h1>' . $routes[$current] . '</h1><p>' . $routes[$current] . ' body copy.</p><p><a href="#hours">Hours</a></p><h2 id="hours">Hours</h2><p><a href="#main">Back to top</a></p></main></body></html>';
};
$files = array();
foreach (array_keys($routes) as $slug) $files['website/' . $slug . '.html'] = $page($slug);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];

$headers = array_values(array_filter($plan['template_parts'] ?? array(), static fn (array $part): bool => 'header' === ($part['area'] ?? '')));
$assert(1 === count($headers), 'The header after the skip link is one shared part: ' . json_encode(array_column($plan['template_parts'] ?? array(), 'slug')));
$compiled = (string) ($headers[0]['canonical_block_markup'] ?? $headers[0]['block_markup'] ?? '');
foreach ($plan['pages'] as $document) {
    $markup = (string) $document['canonical_block_markup'];
    $compiled .= $markup;
    $assert(1 === preg_match('/<main\b[^>]*\bid="main"/', $markup), 'The skip target keeps its id in ' . $document['source_path']);
    $assert(str_contains($markup, 'href="#hours"') && str_contains($markup, 'Back to top</a>'), 'Fragment links inside main stay in ' . $document['source_path']);
}
$assert(! str_contains($compiled, 'Skip to content'), 'The authored skip link is left to the block template.');

// A single-page site's logo links to the top of <main> from inside its
// header: that is the brand, and it stays.
$brand = '<!doctype html><html><head><title>Solo</title></head><body><header><a class="brand" href="#top">Ledger</a><nav><a href="#services">Services</a></nav></header><main id="top"><h1>Ledger</h1><h2 id="services">Services</h2><p>Books balanced.</p></main></body></html>';
$single = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => array('website/index.html' => $brand)))->toWordPressSitePlanView()['wordpress_site_plan'];
$compiled = (string) $single['pages'][0]['canonical_block_markup'] . implode('', array_column($single['template_parts'] ?? array(), 'canonical_block_markup'));
$assert(str_contains($compiled, 'Ledger</a>'), 'A header logo linking to the top of main stays.');

// A link ahead of <main> to some other fragment is not a skip link.
$other = '<!doctype html><html><head><title>Solo</title></head><body><a href="#hours">Jump to hours</a><main id="main"><h1>Solo</h1><h2 id="hours">Hours</h2><p>Open daily.</p></main></body></html>';
$single = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => array('website/index.html' => $other)))->toWordPressSitePlanView()['wordpress_site_plan'];
$assert(str_contains((string) $single['pages'][0]['canonical_block_markup'], 'Jump to hours</a>'), 'A fragment link to another target stays.');
