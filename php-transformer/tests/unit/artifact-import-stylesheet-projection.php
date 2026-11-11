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
    if ('link' !== $delivery && !str_contains(implode("\n", array_column($result['assets'], 'content')), $import)) {
        ++$failures;
        fwrite(STDERR, "FAIL: $delivery retains import conditions\n");
    }
}
exit($failures ? 1 : 0);
