<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

// A flow-content link keeps its unadorned content box without making that
// box a generated block. Author margins must survive Core's layout defaults.
// The trailing icon label keeps the card on the preserved-link path: without
// it (or with a text-only arrow) a headingless media card lowers to a native
// stretched link instead.
$result = (new HtmlTransformer())->transform(
    '<style>p{margin:0}.title{margin-top:12px}</style>'
    . '<a href="/product"><img src="wheel.jpg" alt="Wheel"><div>'
    . '<span>Fits your vehicle</span><p class="title">Wheel set</p><p>R 48 999</p>'
    . '</div><span>View <svg viewBox="0 0 24 24"><path d="M5 12h14"></path></svg></span></a>'
)->toArray();
$card = $result['blocks'][0];
$content = $card['innerBlocks'][1];
if ('core/group' !== $content['blockName'] || 3 !== count($content['innerBlocks'])) {
    throw new RuntimeException('Linked card content must retain a native editable Group.');
}
if (!str_contains($content['attrs']['className'] ?? '', 'blocks-engine-css-owned-layout')
    || isset($content['attrs']['layout'])) {
    throw new RuntimeException('The source content box must not acquire Core flow layout.');
}
$runtime = new Runtime();
$saved = $runtime->serializeBlocks($runtime->parseBlocks($result['serialized_blocks']));
$document = new DOMDocument();
@$document->loadHTML($saved);
$xpath = new DOMXPath($document);
if (1 !== $xpath->query('//a[@href="/product"]/div[contains(@class,"wp-block-group")]')->length
    || 3 !== $xpath->query('//a/div/p')->length
    || 1 !== $xpath->query('//a/div/p/span[text()="Fits your vehicle"]')->length) {
    throw new RuntimeException('Editor save must preserve the linked content box and its text ancestry.');
}
echo "Linked card native content container passed\n";
