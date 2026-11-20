<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$css = '.info-block:first-child span.label{display:none}.info-block span:nth-child(2){font-weight:bold}.info-block:nth-child(2) span.label:before{content:"B"}';
$body = '<div class="info-block"><span class="label">Price</span><span>$569,000</span></div><div class="info-block"><span class="label">Beds</span><span>3</span></div>';
$failures = 0;
foreach (array('link', 'inline-import', 'media-import', 'linked-import') as $delivery) {
    $import = '@import url("custom.css")' . ('media-import' === $delivery ? ' (min-width:641px)' : ('linked-import' === $delivery ? ' layer(custom) supports(display: grid) (min-width:641px)' : '')) . ';';
    $head = 'link' === $delivery ? '<link rel="stylesheet" href="custom.css">' : ('linked-import' === $delivery ? '<link rel="stylesheet" href="parent.css">' : '<style>' . $import . '</style>');
    $files = array(
        array('path' => 'index.html', 'kind' => 'html', 'content' => '<html><head>' . $head . '</head><body>' . $body . '</body></html>'),
        array('path' => 'custom.css', 'kind' => 'css', 'content' => $css),
    );
    if ('linked-import' === $delivery) $files[] = array('path' => 'parent.css', 'kind' => 'css', 'content' => $import);
    $result = (new ArtifactCompiler())->compile(array('files' => $files))->toArray();
    $assets = array_column($result['assets'], null, 'path');
    $projected = (string) ($assets['custom.css']['content'] ?? '');
    $rules = array();
    (new CssStylesheetTransformer())->transformStyleRules($projected, static function (string $selector, string $body) use (&$rules): string {
        $rules[] = array($selector, $body);
        return $selector . '{' . $body . '}';
    });
    $dom = new DOMDocument();
    @$dom->loadHTML('<html><body>' . preg_replace('/<!--.*?-->/s', '', $result['serialized_blocks']) . '</body></html>');
    $xpath = new DOMXPath($dom);
    foreach (array(array('(//*[contains(concat(" ",@class," ")," label ")])[1]', 'display:none'), array('//*[text()="$569,000"]', 'font-weight:bold')) as [$query, $declaration]) {
        $element = $xpath->query($query)->item(0);
        $matches = false;
        if ($element instanceof DOMElement) {
            foreach ($rules as [$selectors, $declarations]) {
                if (!str_contains(str_replace(' ', '', $declarations), $declaration)) continue;
                foreach (CssStylesheetTransformer::splitSelectorList($selectors) ?? array() as $selector) {
                    if (CssSelectorMatcher::matches($element, CssSelectorMatcher::parse($selector))['matches']) $matches = true;
                }
            }
        }
        if (!$matches) {
            ++$failures;
            fwrite(STDERR, "FAIL: $delivery projects $declaration onto $query\n");
        }
    }
    if ('link' !== $delivery && !str_contains(str_replace(' ', '', implode("\n", array_column($result['assets'], 'content'))), str_replace(' ', '', $import))) {
        ++$failures;
        fwrite(STDERR, "FAIL: $delivery retains import conditions\n");
    }
}
// A minified string import must project the captured font sheet, not retain a root URL.
foreach (array('@import"/external/fonts.css";', "@import'/external/fonts.css';", '@import "/external/fonts.css";', '@import url("/external/fonts.css");') as $import) {
    $result = (new ArtifactCompiler())->compile(array('files' => array(
        array('path' => 'index.html', 'kind' => 'html', 'content' => '<!doctype html><html><head><link rel="stylesheet" href="/assets/app.css"></head><body><h1 class="font-heading">Heading</h1><p>Body</p></body></html>'),
        array('path' => 'assets/app.css', 'kind' => 'css', 'content' => $import . '.font-heading{font-family:"Fixture Sans",sans-serif}'),
        array('path' => 'external/fonts.css', 'kind' => 'css', 'content' => '@font-face{font-family:"Fixture Sans";src:url(/media/m.woff2) format("woff2")}'),
        array('path' => 'media/m.woff2', 'kind' => 'font', 'content' => 'fixture-font'),
    )))->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'] ?? array();
    $writes = array_column($plan['writes'] ?? array(), null, 'target_path');
    $theme = json_decode((string) ($writes['theme.json']['payload']['data'] ?? '{}'), true);
    $families = $theme['settings']['typography']['fontFamilies'] ?? array();
    $faces = array_merge(...array_column($families, 'fontFace'));
    $appCss = (string) ($writes['assets/assets/app.css']['payload']['data'] ?? '');
    if (!in_array('file:./assets/media/m.woff2', array_column($faces, 'src'), true) || str_contains($appCss, '/external/fonts.css')) {
        ++$failures;
        fwrite(STDERR, "FAIL: $import follows and projects the captured font sheet\n");
    }
}
$candidates = (new \Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\ReferenceAnalyzer())->cssReferenceCandidates('@importurl("/external/fonts.css");', 'assets/app.css');
if (in_array('css-import', array_column($candidates, 'context'), true)) {
    ++$failures;
    fwrite(STDERR, "FAIL: @importurl is not an import at-keyword\n");
}
exit($failures ? 1 : 0);
