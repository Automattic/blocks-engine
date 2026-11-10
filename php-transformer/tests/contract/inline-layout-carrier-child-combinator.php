<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

$css = '.item{grid-template-columns:2.25rem minmax(0,1fr) auto;align-items:center;gap:.75rem;display:grid}'
    . '.item>span{color:#ff5a1f;font-size:.72rem;font-weight:900}';
$link = static fn (string $number): string => '<a class="item" href="/' . $number . '/"><span>' . $number . '</span><div><p>Business</p><h3>Price Builder</h3></div><svg width="24" height="24" viewBox="0 0 24 24"><path d="M4 12h16"/></svg></a>';
$html = '<!doctype html><html><head><style>' . $css . '</style></head><body><main><section><div>'
    . $link('01') . $link('02') . '</div></section></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$frontend = '';
foreach ($plan['assets'] ?? array() as $asset) {
    if ('css' === ($asset['kind'] ?? null) && in_array($asset['stylesheet_target'] ?? 'both', array('frontend', 'both'), true)) {
        $frontend .= (string) ($asset['content'] ?? '') . "\n";
    }
}
$document = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$document->loadHTML('<?xml encoding="utf-8" ?><html><body>' . $markup . '</body></html>');
libxml_clear_errors();
libxml_use_internal_errors($previous);
$counters = (new DOMXPath($document))->query('//a[@class="item"]/p[@class="blocks-engine-inline-layout-carrier"]/span');
$assert(2 === $counters->length, 'Both grid links retain their counters inside inline-layout carriers.');
$matched = false;
(new CssStylesheetTransformer())->transformStyleRules($frontend, static function (string $prelude, string $body) use ($counters, &$matched): string {
    if (str_contains($body, 'font-weight:900')) {
        foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
            if (! str_contains($selector, '.item') || ! str_contains($selector, ':where(.blocks-engine-inline-layout-carrier)') || ! str_contains($selector, '>span')) continue;
            foreach ($counters as $counter) {
                $match = CssSelectorMatcher::matches($counter, CssSelectorMatcher::parse(trim($selector)));
                if ($match['supported'] && $match['matches']) $matched = true;
            }
        }
    }
    return $prelude . '{' . $body . '}';
});
$assert($matched, 'A specificity-neutral child-combinator projection through the carrier must style the counter with font-weight:900.');
$checkDiagnostics = static function (array $value) use (&$checkDiagnostics, $assert): void {
    if ('projected_author_selector' === ($value['pattern_family'] ?? null)) {
        $encoded = json_encode($value);
        $assert(! (str_contains($encoded, '.item>span') && str_contains($encoded, 'bound to no element')), 'The plan must not report the counter selector bound to no element.');
    }
    foreach ($value as $child) {
        if (is_array($child)) $checkDiagnostics($child);
    }
};
$checkDiagnostics($plan);
// Sibling-indexed subjects keep their source arm only: the carrier changes siblings.
$fixture = dirname(__DIR__, 3) . '/fixtures/solved/89-static-site-importer-architecture';
$footer = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => (string) file_get_contents($fixture . '/index.html'),
    'css/site.css' => (string) file_get_contents($fixture . '/css/site.css'),
)))->toArray();
$assert(str_contains(json_encode($footer), 'footer > a:last-child') && ! str_contains(json_encode($footer), 'inline-layout-carrier)>a:last-child'), 'A last-child subject must not gain a carrier hop that matches the footer brand link.');
echo "inline-layout carrier child combinator contract passed.\n";
