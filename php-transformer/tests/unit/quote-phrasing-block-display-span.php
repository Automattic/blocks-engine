<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A phrasing-only blockquote lowers to one quote paragraph whose RichText keeps
// its spans. A block-display span in it (a decorative opening quote mark) is a
// RichText mark, not an inline-layout carrier paragraph, so the author rule
// sizing it must still reach the emitted mark; otherwise the quote loses the
// mark's line box and the page gets shorter.
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => '<main><div class="quote-block"><figure class="contents"><blockquote>'
        . '<span>“</span>A quotation that matters.<span>”</span>'
        . '</blockquote><figcaption class="source">— Ada</figcaption></figure></div></main>'
        . '<style>.quote-block blockquote{font-size:20px;margin:0}'
        . '.quote-block blockquote>span:first-child{font-size:4em;display:block;opacity:.3}'
        . '.quote-block blockquote>span:last-child{display:none}</style>'),
))->toArray();

$document = new DOMDocument();
$document->loadHTML('<?xml encoding="utf-8"?>' . $result['serialized_blocks'], LIBXML_NOERROR | LIBXML_NOWARNING);
$opening = null;
foreach ((new DOMXPath($document))->query('//blockquote//mark') ?: array() as $mark) {
    if ($mark instanceof DOMElement && '“' === trim($mark->textContent)) {
        $opening = $mark;
    }
}
if (!$opening instanceof DOMElement || 1 !== preg_match('/--blocks-engine-richtext-marker:(blocks-engine-richtext-[a-f0-9]{12}-\d+)/', $opening->getAttribute('style'), $match)) {
    throw new RuntimeException('The opening quote mark must stay a marked RichText run of the quote paragraph');
}

$css = '';
array_walk_recursive($result, static function ($value) use (&$css): void {
    if (is_string($value)) {
        $css .= $value;
    }
});
$projected = false;
foreach (explode('}', $css) as $rule) {
    if (str_contains($rule, '--blocks-engine-richtext-marker:' . $match[1]) && str_contains($rule, 'font-size:4em') && str_contains($rule, 'display:block')) {
        $projected = true;
    }
}
if (!$projected) {
    throw new RuntimeException('The block-display quote mark rule must be projected onto its RichText mark, not an unemitted carrier paragraph');
}
echo "Quote phrasing block-display span passed\n";
