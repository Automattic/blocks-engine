<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$source = require dirname(__DIR__) . '/fixtures/block-link-cards.php';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();
$headings = array();
$visit = static function (array $blocks) use (&$visit, &$headings): void {
    foreach ($blocks as $block) {
        if ('core/button' === ($block['blockName'] ?? '')) throw new RuntimeException('Block links must not become buttons.');
        if ('core/heading' === ($block['blockName'] ?? '')) $headings[] = $block;
        $visit($block['innerBlocks'] ?? array());
    }
};
$visit($result['blocks']);
if (3 !== count($headings)) throw new RuntimeException('Each card retains an editable heading.');
foreach ($headings as $heading) {
    if (4 !== ($heading['attrs']['level'] ?? null) || !str_contains($heading['attrs']['className'] ?? '', 'card__title')) throw new RuntimeException('Source heading level and class must survive.');
}
$dom = new DOMDocument();
@$dom->loadHTML($result['serialized_blocks']);
$xpath = new DOMXPath($dom);
foreach (range(1, 3) as $index) {
    if (1 !== $xpath->query('//a[@href="https://example.com/' . $index . '"]//h4[contains(@class,"card__title")]')->length) throw new RuntimeException('One anchor wraps each heading.');
}
$phrasing = (new HtmlTransformer())->transform('<a href="/go" style="padding:12px;background:#123;color:white">Go <svg width="24" height="24"><path d="M0 0h24v24H0z"/></svg></a>')->toArray();
if (!str_contains($phrasing['serialized_blocks'], '<!-- wp:button ')) throw new RuntimeException('Phrasing-only styled links still become native buttons.');
echo "Block link cards: editable heading/link semantics and phrasing button passed\n";
