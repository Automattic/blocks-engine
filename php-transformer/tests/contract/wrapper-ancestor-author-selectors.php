<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// Check matching declarations, not just retained selector text: a rule that
// still names removed ancestors is present in the artifact but does nothing.
$cases = array(
    'descendant' => array(' .w .c .g .item:first-child', '<div class="g">%s</div>', 'background:#2c9ce0', array(true, false)),
    'child' => array('.w > .c > .item', '%s', 'color:red', array(true, true)),
);
foreach ($cases as $name => [$selector, $inner, $declaration, $expected]) {
    $items = '<div class="item">One</div><div class="item">Two</div>';
    $html = '<!doctype html><html><head><link rel="stylesheet" href="s.css"></head><body><div class="w"><div class="c">'
        . sprintf($inner, $items) . '</div></div></body></html>';
    $result = (new ArtifactCompiler())->compile(array('files' => array(
        array('path' => 'index.html', 'kind' => 'html', 'content' => $html),
        array('path' => 's.css', 'kind' => 'css', 'content' => trim($selector) . '{' . $declaration . '}'),
    )))->toArray();
    $rules = array();
    foreach ($result['assets'] as $asset) {
        if ('css' !== ($asset['kind'] ?? '')) continue;
        (new CssStylesheetTransformer())->transformStyleRules($asset['content'], static function (string $prelude, string $body) use (&$rules): string {
            $rules[] = array($prelude, $body);
            return $prelude . '{' . $body . '}';
        });
    }
    $doc = new DOMDocument();
    @$doc->loadHTML('<html><body>' . $result['serialized_blocks'] . '</body></html>');
    $targets = (new DOMXPath($doc))->query('//*[contains(concat(" ",@class," ")," item ")]');
    if (2 !== $targets->length) throw new RuntimeException($name . ': missing emitted items');
    foreach ($targets as $index => $target) {
        $matches = str_contains(str_replace(' ', '', $target->getAttribute('style')), $declaration);
        foreach ($rules as [$prelude, $body]) {
            if (!str_contains(str_replace(' ', '', $body), $declaration)) continue;
            foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $emittedSelector) {
                if (CssSelectorMatcher::matches($target, CssSelectorMatcher::parse($emittedSelector))['matches']) $matches = true;
            }
        }
        if ($matches !== $expected[$index]) {
            throw new RuntimeException($name . ': declaration match changed for item ' . $index);
        }
    }
}
echo "wrapper-ancestor-author-selectors: passed\n";
