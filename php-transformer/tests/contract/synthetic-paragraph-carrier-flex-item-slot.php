<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$failures = array();
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) $failures[] = $message;
};
$html = '<main><section><div class="h"><div><p class="eyebrow">Completed</p><h2>Start with finished guides for real work</h2><p>Long description text that wraps across lines in a narrow viewport for sure.</p></div><a class="text-link" href="/field-guides">Explore all Field Guides<svg class="lucide lucide-arrow-right" width="24" height="24" viewBox="0 0 24 24"><path d="m12 5 7 7-7 7"></path></svg></a></div></section></main>';
$css = '.h{justify-content:space-between;align-items:end;gap:1rem;display:flex}.text-link{align-items:center;gap:.4rem;margin-top:.5rem;font-size:.82rem;font-weight:900;display:inline-flex}.h>.text-link{flex:none}';
foreach (array('issue' => $html, 'grid' => $html, 'unrelated carrier' => str_replace('</a></div>', '</a><a href="/unrelated">Unrelated</a></div>', $html)) as $case => $source) {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
        'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="/source.css"></head><body>' . $source . '</body></html>',
        // Exercise mixed placement/paint and grid placement through the same hop.
        'source.css' => ('grid' === $case ? str_replace('display:flex}', 'display:grid}', $css) : $css) . '.h>.text-link{color:#123456;order:2;grid-column:2}',
    )))->toArray();
    $pages = array_column($result['source_reports']['wordpress_site_plan']['pages'] ?? array(), null, 'source_path');
    $markup = (string) ($pages['index.html']['canonical_block_markup'] ?? '');
    $dom = new DOMDocument();
    @$dom->loadHTML($markup);
    $anchor = null;
    foreach ($dom->getElementsByTagName('a') as $element) {
        if ('/field-guides' === $element->getAttribute('href')) $anchor = $element;
    }
    $assert($anchor instanceof DOMElement, "$case: source anchor survives");
    if (!$anchor instanceof DOMElement) continue;
    $carrier = $anchor->parentNode;
    $assert($carrier instanceof DOMElement && str_contains($carrier->getAttribute('class'), 'blocks-engine-synthetic-paragraph'), "$case: anchor has a synthetic paragraph carrier");
    $bound = array();
    $emittedCss = implode("\n", array_column($result['assets'] ?? array(), 'content'));
    (new CssStylesheetTransformer())->visitStyleRules($emittedCss, static function (string $selector, string $body) use ($dom, $anchor, $carrier, &$bound, $assert, $case): void {
        foreach (array('flex:none', 'color:#123456', 'font-weight:900', 'order:2', 'grid-column:2') as $declaration) {
            if (!str_contains($body, $declaration)) continue;
            foreach (CssStylesheetTransformer::splitSelectorList($selector) ?? array() as $arm) {
                $parsed = CssSelectorMatcher::parse($arm);
                foreach ($dom->getElementsByTagName('*') as $element) {
                    if (!CssSelectorMatcher::matches($element, $parsed)['matches']) continue;
                    $placement = in_array($declaration, array('flex:none', 'order:2', 'grid-column:2'), true);
                    $assert($element === $anchor || ($placement && $element === $carrier), "$case: $declaration must not bind an unrelated sibling or paint the carrier: $arm");
                    if ($element === ($placement ? $carrier : $anchor)) $bound[$declaration] = true;
                }
            }
        }
    });
    foreach (array('flex:none', 'color:#123456', 'font-weight:900', 'order:2', 'grid-column:2') as $declaration) {
        $assert(isset($bound[$declaration]), "$case: $declaration binds its emitted owner");
    }
}
if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Synthetic paragraph carrier item-slot contract passed.\n";
