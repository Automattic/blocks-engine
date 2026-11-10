<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$html = <<<'HTML'
<style>
.btns{display:flex;flex-direction:column;padding:0;margin:0;list-style:none;width:347px}
.btns > li > a{display:inline-flex;align-items:center;flex-direction:row-reverse;width:100%;box-sizing:border-box;padding:0 26px;height:50px;font-size:34.6667px;background:#f05e3e;color:#fff}
.btns > li > a > svg{display:block;flex-grow:0;flex-shrink:0;height:1.875rem;min-width:16px;width:1.625em;margin-left:.5em;margin-right:calc(-.125em + 0rem)}
.btns > li > a > .label{flex-grow:1;overflow:hidden;white-space:nowrap}
</style>
<svg viewBox="0 0 40 40" display="none" width="0" height="0"><symbol id="arrow" viewBox="0 0 40 40"><path d="M17 5l13 14-13 14-3-3 11-11-11-11z"/></symbol></svg>
<main><ul class="btns"><li><a href="/next/"><svg><use xlink:href="#arrow"></use></svg><span class="label">A long button label that fills the row</span></a></li></ul></main>
HTML;
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$document = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$document->loadHTML('<?xml encoding="utf-8" ?><html><body>' . $result['serialized_blocks'] . '</body></html>');
libxml_clear_errors();
libxml_use_internal_errors($previous);
$icon = (new DOMXPath($document))->query('//a[@href="/next/"]/img')->item(0);
if (! $icon instanceof DOMElement) {
    throw new RuntimeException('The SVG must become a direct image child of the native button link.');
}
$css = '';
foreach ($result['source_reports']['wordpress_site_plan']['assets'] ?? array() as $asset) {
    if ('css' === ($asset['kind'] ?? null) && in_array($asset['stylesheet_target'] ?? 'both', array('frontend', 'both'), true)) {
        $css .= $asset['content'] . "\n";
    }
}
$declarations = array();
(new CssStylesheetTransformer())->transformStyleRules($css, static function (string $prelude, string $body) use ($icon, &$declarations): string {
    foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
        $match = CssSelectorMatcher::matches($icon, CssSelectorMatcher::parse(trim($selector)));
        if ($match['supported'] && $match['matches']) {
            foreach (explode(';', $body) as $declaration) {
                $parts = explode(':', $declaration, 2);
                if (2 === count($parts)) {
                    $declarations[trim($parts[0])] = trim($parts[1]);
                }
            }
            break;
        }
    }
    return $prelude . '{' . $body . '}';
});
foreach (array('flex-shrink' => '0', 'width' => '1.625em', 'margin-left' => '.5em', 'margin-right' => 'calc(-.125em + 0rem)') as $property => $expected) {
    if ($expected !== ($declarations[$property] ?? null)) {
        throw new RuntimeException('The emitted SVG replacement must receive authored ' . $property . '; got ' . ($declarations[$property] ?? 'no matching declaration'));
    }
}
echo "Button materialized SVG author rule contract passed.\n";
