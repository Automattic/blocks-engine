<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssCascade;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$html = <<<'HTML'
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Device inline geometry</title>
<script data-blocks-engine-capture-device-selection="">(function(){var rules=[{userAgent:"iPad",flags:"i",document:"tablet"}],key="desktop";for(var i=0;i<rules.length;i++){if(new RegExp(rules[i].userAgent,rules[i].flags).test(navigator.userAgent)){key=rules[i].document;break;}}document.documentElement.setAttribute('data-blocks-engine-capture-selected-document',key);})();</script>
<style>body{margin:0;font-family:sans-serif}.after{background:#5d1ff2;color:#fff;padding:16px}</style></head><body>
<script data-blocks-engine-capture-device-body="">(function(){var key=document.documentElement.getAttribute('data-blocks-engine-capture-selected-document');document.addEventListener('DOMContentLoaded',function(){document.querySelectorAll('[data-blocks-engine-capture-device-inline]').forEach(function(node){var styles=JSON.parse(node.getAttribute('data-blocks-engine-capture-device-inline'));if(Object.prototype.hasOwnProperty.call(styles,key))node.setAttribute('style',styles[key]);});});})();</script>
<div data-blocks-engine-capture-device-document="desktop"><main>
<div role="note" style="box-sizing:border-box;max-width:1120px;display:flex;align-items:center;justify-content:center;padding:12px;border:1px dashed #999;width:100%;min-height:1120px" data-blocks-engine-capture-device-inline="{&quot;tablet&quot;:&quot;box-sizing:border-box;max-width:820px;display:flex;align-items:center;justify-content:center;padding:12px;border:1px dashed #999;width:100%;min-height:820px&quot;}">Embedded feed placeholder</div>
<div class="after">After the placeholder</div>
</main></div></body></html>
HTML;
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $html),
))->toArray();
$markup = (string) $result['serialized_blocks'];
$assert(str_contains($markup, 'data-blocks-engine-capture-device-inline'), 'The public compilation retains the runtime inline binding.');
$document = new DOMDocument();
@$document->loadHTML($markup);
$xpath = new DOMXPath($document);
$target = $xpath->query('//*[text()="Embedded feed placeholder"]')->item(0);
$assert($target instanceof DOMElement, 'The placeholder survives lowering.');
$css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);

foreach (array('desktop' => 1120, 'tablet' => 820) as $device => $expected) {
    $winners = array();
    foreach ($rules as $order => $rule) {
        $selector = CssSelectorMatcher::parse(trim($rule[1]));
        if (! CssSelectorMatcher::matches($target, $selector)['matches']) continue;
        foreach (SourceDeclarations::parse($rule[2]) as $property => $value) {
            $candidate = array('value' => $value, 'important' => str_contains($value, '!important'), 'inline' => false,
                'specificity' => array_values(CssSelectorMatcher::specificityCounts($selector)), 'order' => $order);
            if (! isset($winners[$property]) || CssCascade::wins($candidate, $winners[$property])) $winners[$property] = $candidate;
        }
    }
    // The preserved device runtime replaces the style attribute at DOMContentLoaded.
    if ('tablet' === $device) {
        preg_match('/<!-- wp:paragraph (.*?) -->/', $markup, $block);
        $attributes = json_decode($block[1], true, 512, JSON_THROW_ON_ERROR);
        $variants = json_decode($attributes['metadata']['blocksEngineRuntimeAttributes']['data-blocks-engine-capture-device-inline'], true, 512, JSON_THROW_ON_ERROR);
        foreach (SourceDeclarations::parse($variants[$device]) as $property => $value) {
            $candidate = array('value' => $value, 'important' => str_contains($value, '!important'), 'inline' => true,
                'specificity' => array(0, 0, 0), 'order' => count($rules));
            if (! isset($winners[$property]) || CssCascade::wins($candidate, $winners[$property])) $winners[$property] = $candidate;
        }
    }
    foreach (array('min-height', 'max-width') as $property) {
        $assert(($expected . 'px') === CssValueInspector::comparable($winners[$property]['value'] ?? ''), "{$device}: {$property} computes {$expected}px, allowing the following block at y={$expected}.");
    }
}

fwrite(STDOUT, "Device inline geometry contract passed\n");
