<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// Both labels become marks in synthetic paragraphs. Every pseudo spelling
// must follow the same subject, including structurally restricted rules.
foreach (array(':before', '::before', ':after', '::after') as $suffix) {
    $result = (new ArtifactCompiler())->compile(array('files' => array(
        array('path' => 'index.html', 'kind' => 'html', 'content' => '<!doctype html><html><head><link rel="stylesheet" href="custom.css"></head><body><div class="info-block"><span class="label">Price</span><span>$569,000</span></div><div class="info-block"><span class="label">Beds</span><span>3</span></div></body></html>'),
        array('path' => 'custom.css', 'kind' => 'css', 'content' => '.info-block span.label' . $suffix . '{content:"*";margin-right:5px}.info-block:nth-child(2) span.label' . $suffix . '{content:"B"}'),
    )))->toArray();
    $document = new DOMDocument();
    @$document->loadHTML('<html><body>' . $result['serialized_blocks'] . '</body></html>');
    $labels = (new DOMXPath($document))->query('//mark[contains(concat(" ",@class," ")," label ")]');
    if (2 !== $labels->length) throw new RuntimeException('Expected two emitted RichText label marks.');
    $rules = array();
    foreach ($result['assets'] as $asset) {
        if ('css' !== ($asset['kind'] ?? '')) continue;
        (new CssStylesheetTransformer())->transformStyleRules($asset['content'], static function (string $prelude, string $body) use (&$rules): string {
            foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) $rules[] = array($selector, $body);
            return $prelude . '{' . $body . '}';
        });
    }
    foreach ($labels as $index => $label) {
        foreach (array('content:"*"', 'margin-right:5px', 1 === $index ? 'content:"B"' : null) as $declaration) {
            if (null === $declaration) continue;
            $found = false;
            foreach ($rules as [$selector, $body]) {
                if (!preg_match('/:{1,2}' . ltrim($suffix, ':') . '$/i', $selector) || !str_contains(str_replace(' ', '', $body), $declaration)) continue;
                $host = (string) preg_replace('/:{1,2}(before|after)$/i', '', $selector);
                if (CssSelectorMatcher::matches($label, CssSelectorMatcher::parse($host))['matches']) $found = true;
            }
            if (!$found) throw new RuntimeException('Missing ' . $declaration . ' on label ' . $index . $suffix);
        }
    }
}
echo "RichText pseudo-element author rules passed.\n";
