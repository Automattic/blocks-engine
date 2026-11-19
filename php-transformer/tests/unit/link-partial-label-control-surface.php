<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = <<<'HTML'
<style>
.lockup { display:flex; align-items:center; gap:10px }
.badge { display:flex; width:44px; height:44px; padding:2px; border:1px solid gold; border-radius:50% }
.surface { padding:10px 20px; background:navy; border-radius:8px }
</style>
<footer>
<a class="lockup" href="/home"><span class="badge"><span>ABC</span></span><span>Catering</span></a>
<a href="/book"><span class="surface"><span>Book now</span></span></a>
<a class="surface" href="/contact"><span class="badge">ABC</span><span>Contact</span></a>
</footer>
HTML;
$result = (new HtmlTransformer())->transform($html, array())->toArray();
$urls = array();
$visit = static function (array $blocks) use (&$visit, &$urls): void {
    foreach ($blocks as $block) {
        if ('core/button' === ($block['blockName'] ?? '')) $urls[] = $block['attrs']['url'] ?? '';
        $visit($block['innerBlocks'] ?? array());
    }
};
$visit($result['blocks']);
if (array('/book', '/contact') !== $urls) {
    fwrite(STDERR, 'Only a surface covering the whole link label should become a button: ' . json_encode($urls) . "\n");
    exit(1);
}
$markup = $result['serialized_blocks'];
if (!preg_match('/<a\b[^>]*class="[^"]*lockup[^\"]*"[^>]*>(.*?)<\/a>/s', $markup, $link)
    || !str_contains($link[1], 'ABC') || !str_contains($link[1], 'Catering') || !str_contains($link[1], 'badge')) {
    fwrite(STDERR, "The lockup must keep one source-classed anchor and its badge.\n");
    exit(1);
}
echo "Partial-label control surface passed\n";
