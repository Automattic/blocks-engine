<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = file_get_contents(dirname(__DIR__) . '/fixtures/blog-subscription-dialog.html');
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'posts/river.html',
    'files' => array('posts/river.html' => $html),
))->toArray();
if ('failed' === $result['status']) throw new RuntimeException('Blog page must compile.');
foreach ($result['source_reports']['conversion_report']['fallback_diagnostics'] ?? array() as $finding) {
    if ('html_commerce_controls_fallback' === ($finding['diagnostic_code'] ?? null)) {
        throw new RuntimeException('A subscription dialog without product evidence is not a purchase region.');
    }
}
$markup = '';
foreach ($result['source_reports']['wordpress_site_plan']['pages'] ?? array() as $page) {
    if ('posts/river.html' === $page['source_path']) $markup = $page['canonical_block_markup'];
}
if (!str_contains($markup, '<!-- wp:button ') || !str_contains($markup, '>Checkout</button>')) {
    throw new RuntimeException('Subscription button must remain native block content: ' . $markup);
}
if (!str_contains($markup, 'Support independent writing') || !str_contains($markup, 'Read ad-free for €1.99/month')) {
    throw new RuntimeException('Dialog content must be retained.');
}
// A property-search widget's radios are not product options for a "Buy" call to action.
$listing = (new Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer())->transform('<div class="page"><main><div class="search-panel"><label><input type="radio" name="search-type" value="area"> Area</label><label><input type="radio" name="search-type" value="address"> Address</label></div><section class="cta"><h2>Find your next home</h2><p>Listings from $499,900</p><a class="btn" href="https://search.example.com/">Buy</a><a class="btn" href="/sell">Sell</a></section></main></div>')->toArray();
if (in_array('html_commerce_controls_fallback', array_column($listing['diagnostics'], 'code'), true)) {
    throw new RuntimeException('Search filters made a call to action a purchase region.');
}
echo "formless-subscription-dialog: ok\n";
