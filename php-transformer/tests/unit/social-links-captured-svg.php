<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$source = file_get_contents(dirname(__DIR__) . '/fixtures/social-links-captured-svg.html');
$result = (new HtmlTransformer())->transform($source)->toArray();
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$assert(!str_contains($result['serialized_blocks'], 'wp:html'), 'Custom social artwork must never become Custom HTML.');
$assert(!str_contains($result['serialized_blocks'], 'wp:social-link'), 'Service recognition must not replace authored SVG geometry.');
$assert(!str_contains(json_encode($result), 'data-blocks-engine-capture-glyph-color'), 'Capture evidence must not leak.');
$assert(0 === $result['metrics']['fallback_count'], 'Source icon links remain editable without HTML fallback.');
$assert('pass' === $result['source_reports']['wp_block_validity']['status'], 'Source icon links remain WordPress-valid.');
$sourceDom = new DOMDocument();
$sourceDom->loadHTML($source);
$dom = new DOMDocument();
$dom->loadHTML($result['serialized_blocks']);
$assert(4 === $dom->getElementsByTagName('a')->length, 'Every link retains its anchor.');
foreach ($sourceDom->getElementsByTagName('a') as $index => $anchor) {
    $actual = $dom->getElementsByTagName('a')->item($index);
    $assert($anchor->getAttribute('href') === $actual->getAttribute('href'), 'Link targets survive.');
    $assert($anchor->getAttribute('aria-label') === $actual->getAttribute('aria-label'), 'Accessible names survive.');
    $assert($anchor->getElementsByTagName('path')->item(0)->getAttribute('d') === $actual->getElementsByTagName('path')->item(0)->getAttribute('d'), 'Path geometry must be identical.');
}
$assert(str_contains($result['serialized_blocks'], 'stroke="currentColor"'), 'Hover paint remains live.');
// A genuine core glyph with no divergent paint keeps the native social block.
$icons = json_decode(file_get_contents(dirname(__DIR__, 2) . '/resources/wordpress-social-link-icons.json'), true);
foreach ($icons as $service => $icon) {
    $iconDom = new DOMDocument();
    $assert(@$iconDom->loadXML($icon), $service . ': core icon must be well-formed XML.');
    $assert($iconDom->documentElement instanceof DOMElement && 'svg' === $iconDom->documentElement->tagName, $service . ': core icon must have an svg document element.');
}
// Issue #5600: Tumblr artwork must not crash compilation of a social list.
$reducedPage = <<<'HTML'
<!doctype html><html><head><title>Social</title><style>ul{display:flex;gap:8px;list-style:none;padding:0}svg{width:24px;height:24px}</style></head>
<body><main><h1>Follow</h1><ul class="social">
<li><a href="https://example.tumblr.com/"><svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M17.04 21.28h-3.28c-2.84 0-4.94-1.37-4.94-5.02v-5.67H6.08V7.5c2.93-.73 4.11-3.3 4.3-5.48h3.01v4.93h3.47v3.65H13.4v4.93c0 1.47.73 2.01 1.92 2.01h1.73v3.75z"/></svg></a></li>
<li><a href="https://www.instagram.com/example/"><svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="5"/></svg></a></li>
</ul></main></body></html>
HTML;
$reduced = (new HtmlTransformer())->transform($reducedPage)->toArray();
$assert(str_contains($reduced['serialized_blocks'], 'https://example.tumblr.com/'), 'Reduced page converts and retains the Tumblr target.');
$assert(str_contains($reduced['serialized_blocks'], 'https://www.instagram.com/example/'), 'Reduced page retains the Instagram target.');
$matching = (new HtmlTransformer())->transform('<div class="social"><a href="https://facebook.com/example" aria-label="Facebook">' . $icons['facebook'] . '</a></div>')->toArray();
$assert(str_contains($matching['serialized_blocks'], 'wp:social-link '), 'Matching core artwork keeps existing native output.');
// A builder re-export of the same glyph (other precision, start point and fill
// rule) paints the same area, so it keeps core/social-link; a visibly changed
// feature (the crossbar one unit wider) keeps source artwork.
$reexport = 'M22 12.061C22 6.505 17.523 2 12 2S2 6.505 2 12.061c0 5.022 3.657 9.184 8.438 9.939v-7.03h-2.54v-2.91h2.54V9.845c0-2.522 1.492-3.915 3.777-3.915 1.094 0 2.238.197 2.238.197v2.476h-1.26c-1.243 0-1.63.775-1.63 1.57v1.888h2.773l-.443 2.908h-2.33V22c4.78-.755 8.437-4.917 8.437-9.939z';
foreach (array('re-export' => array($reexport, true), 'wider crossbar' => array(str_replace('h-2.54v-2.91h2.54', 'h-3.54v-2.91h3.54', $reexport), false)) as $label => [$d, $native]) {
    $converted = (new HtmlTransformer())->transform('<style>.glyph{margin:0 8px 8px}</style><div class="widget widget-social"><h2>Follow</h2><div data-aid="SOCIAL_SOCIAL_LINKS"><a href="https://www.facebook.com/106416541004929" aria-label="Facebook Social Link"><svg class="glyph" viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><path fill-rule="evenodd" d="' . $d . '"></path></svg></a></div></div>')->toArray();
    $assert($native === str_contains($converted['serialized_blocks'], 'wp:social-link ') && $native !== str_contains($converted['serialized_blocks'], $d), $label . ': glyph equivalence is decided by painted area');
    if ($native) {
        // Core's icon keeps the source glyph box: attribute size and authored margin.
        $glyphCss = implode("\n", array_column(array_filter($converted['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
        $assert(1 === preg_match('/> ?a ?> ?svg\)[^{]*\{width:40px;height:40px\}/', $glyphCss) && 1 === preg_match('/> ?a ?> ?svg\)[^{]*\{margin:0 8px 8px\}/', $glyphCss), $label . ': core glyph keeps the source size hints and margin');
    }
}
$custom = (new HtmlTransformer())->transform('<div class="social"><a href="https://facebook.com/example" aria-label="Custom Facebook"><svg viewBox="0 0 24 24"><path d="M0 0h12v12z"/></svg></a></div>')->toArray();
$assert(str_contains($custom['serialized_blocks'], 'M0 0h12v12z'), 'Wrong core artwork is avoided even without capture metadata.');
$assert(str_contains($custom['serialized_blocks'], '<a '), 'Uncaptured custom artwork remains linked.');
$unsafe = (new HtmlTransformer())->transform('<div class="social"><a href="javascript:alert(1)" aria-label="Facebook" onclick="alert(1)"><svg viewBox="0 0 24 24" onload="alert(1)"><script>alert(1)</script><path d="M0 0h12v12z"/></svg></a></div>')->toArray();
$assert(!preg_match('/javascript:|onclick=|onload=|<script/i', $unsafe['serialized_blocks']), 'Source artwork uses sanitized markup, not raw source HTML.');
// Breakdance footer regression: X on a Twitter URL is not core's Twitter bird.
$xPath = 'M389.2 48h70.6L305.6 224.2 487 464H345L233.7 318.6 106.5 464H35.8L200.7 275.5 26.8 48H172.4L272.9 180.9 389.2 48zM364.4 421.8h39.1L151.1 88h-42L364.4 421.8z';
$linkedinPath = 'M4.98 3.5c0 1.381-1.11 2.5-2.48 2.5s-2.48-1.119-2.48-2.5 1.11-2.5 2.48-2.5 2.48 1.119 2.48 2.5z';
$footer = '<div class="bde-social-icons-24610-115 bde-social-icons">';
foreach (array('twitter' => array('https://twitter.com/robertjacobi', '0 0 512 512', $xPath, ''), 'linkedin' => array('https://linkedin.com/in/robert-jacobi', '-4 -2 32 32', $linkedinPath, ';fill:#ffffff')) as $label => [$href, $viewbox, $path, $style]) {
    $footer .= '<a class="breakdance-link bde-social-icons__icon-wrapper bde-social-icons__icon-' . $label . '" href="' . $href . '" target="_self" aria-label="' . $label . '"><svg xmlns="http://www.w3.org/2000/svg" width="100%" viewbox="' . $viewbox . '" style="' . $style . '"><path d="' . $path . '"></path></svg></a>';
}
$footer .= '</div>';
$convertedFooter = (new HtmlTransformer())->transform($footer)->toArray();
$assert(!str_contains($convertedFooter['serialized_blocks'], 'wp:html'), 'Breakdance footer has no Custom HTML.');
$footerDom = new DOMDocument();
@$footerDom->loadHTML($convertedFooter['serialized_blocks']);
foreach (array('twitter' => array('https://twitter.com/robertjacobi', $xPath), 'linkedin' => array('https://linkedin.com/in/robert-jacobi', $linkedinPath)) as $index => [$href, $path]) {
    $xpath = new DOMXPath($footerDom);
    $link = $xpath->query('//a[@aria-label="' . $index . '"]')->item(0);
    $assert($link instanceof DOMElement && $href === $link->getAttribute('href'), 'Footer links retain URL and accessible name.');
    $assert($path === $link->getElementsByTagName('path')->item(0)->getAttribute('d'), 'Footer geometry survives unchanged.');
}
$assert(str_contains($convertedFooter['serialized_blocks'], 'fill:#ffffff'), 'LinkedIn retains its authored white fill.');
// Matching service artwork with captured paint still uses core/social-links.
$painted = (new HtmlTransformer())->transform('<div class="social"><a href="https://facebook.com/example" aria-label="Facebook">' . str_replace('<svg ', '<svg data-blocks-engine-capture-glyph-color="#ffffff" ', $icons['facebook']) . '</a></div>')->toArray();
$assert(str_contains($painted['serialized_blocks'], 'wp:social-link '), 'Captured paint does not disqualify matching core geometry.');
// Mixed dropdown panels and navigation keep their prior native social blocks.
$iconLink = '<div class="social"><a aria-label="Social" href="https://example.test/social"><svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true"><path d="M2 4h16M2 10h16M2 16h16" stroke="currentColor"></path></svg></a></div>';
foreach (array('<nav>%s</nav>', '<div role="navigation">%s</div>', '<div data-blocks-engine-capture-dialog-panel="menu">%s</div>', '<div class="data-blocks-engine-capture-dropdown">%s</div>', '<div class="data-blocks-engine-capture-dialog">%s</div>') as $container) {
    $native = (new HtmlTransformer())->transform(sprintf($container, $iconLink))->toArray();
    $assert(!str_contains($native['serialized_blocks'], 'wp:html'), 'Navigation social artwork must not introduce Custom HTML blocks.');
    $assert(str_contains($native['serialized_blocks'], 'wp:social-link ') || str_contains($native['serialized_blocks'], 'wp:navigation-link '), 'Navigation retains native link output.');
    $assert(str_contains($native['serialized_blocks'], 'https://example.test/social'), 'Navigation social links retain their targets.');
}
$dropdown = (new HtmlTransformer())->transform(require dirname(__DIR__) . '/fixtures/scoped-mixed-dropdown.php')->toArray();
$assert(!str_contains($dropdown['serialized_blocks'], 'wp:html'), 'Both scoped dropdown copies contain only native blocks.');
if (in_array('--json', $argv, true)) {
    echo json_encode(array('source' => $source, 'result' => $result), JSON_THROW_ON_ERROR);
    exit;
}
echo "Captured social SVG parity passed.\n";
