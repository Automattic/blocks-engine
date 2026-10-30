<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner;

// A rich-text marker carrier restates its inline's resting values inline. A
// property a media rule also declares must stay in the author rules, which
// reach the carrier through its marker: an inline copy outranks every
// breakpoint. Reduced from a gallery caption (32px, 28px under 480px) whose
// decorative sibling splits its paragraph into marker carriers.
$css = '.caption{height:100%}'
    . '.caption em{background:url(/plus.png) no-repeat;width:24px;height:24px}'
    . '.caption .title{display:inline-block;color:#ffffff;font-size:32px;margin:0}'
    . '@media (max-width: 480px){.caption .title{font-size:28px}}';
$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Gallery</title><style>' . $css . '</style></head><body><main>'
    . '<a href="/sculptures/" class="thumbnail"><img src="/a.jpg" alt=""> <p class="caption"> <em></em><br> <span class="title">Sculptures</span><br> <span class="sub"></span> </p></a>'
    . '</main></body></html>';

$result = (new HtmlTransformer())->transform($html, array())->toArray();
$dom = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="utf-8" ?>' . StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($result['serialized_blocks']));
libxml_clear_errors();
libxml_use_internal_errors($previous);

$carrier = null;
foreach ($dom->getElementsByTagName('mark') as $mark) {
    if ('Sculptures' === trim($mark->textContent)) {
        $carrier = $mark;
    }
}
if (! $carrier instanceof DOMElement) {
    throw new RuntimeException('Expected the caption title as a rich-text marker carrier: ' . $result['serialized_blocks']);
}
if (str_contains($carrier->getAttribute('style'), 'font-size')) {
    throw new RuntimeException('The carrier inlines a responsive font-size that outranks the 480px rule: ' . $carrier->getAttribute('style'));
}
// The resting value still reaches it through the author rules.
$resolved = (new StaticCssCascade($dom, implode("\n", array_column($result['assets'], 'content'))))->resolve($carrier, array('font-size', 'display'), array());
if ('32px' !== ($resolved['font-size'] ?? null) || 'inline-block' !== ($resolved['display'] ?? null)) {
    throw new RuntimeException('The author rules no longer style the carrier at rest: ' . json_encode($resolved));
}

echo "Rich-text carriers leave responsive properties to the author rules\n";
