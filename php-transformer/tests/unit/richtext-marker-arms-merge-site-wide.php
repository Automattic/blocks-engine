<?php
declare(strict_types=1);

// The site-wide author stylesheet merged arms that differ by one page-local
// marker class into one `:where(.m1,.m2)` list, but left RichText carrier
// arms (`mark[style*=…]`, `span[data-…-marker=…]`) and every arm ending in a
// pseudo-element one per marker. A preflight `*,::before,::after` rule then
// shipped each carrier nine times, tens of kilobytes on a site with many
// formatted runs (Automattic/blocks-engine#6145, theme_css_weight). Those
// arms now merge too, and expand() still splits them back into arms.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorListCompactor;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMarkerSelector;

$assert = static function (bool $condition, string $message, string $context = ''): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n{$context}\n"); exit(1); }
};

$markers = array('blocks-engine-richtext-0d12244fb829-0', 'blocks-engine-richtext-bb97f27d7f7b-0', 'blocks-engine-richtext-b2fd12de509c-0');
$carriers = array();
foreach ($markers as $marker) {
    foreach (CssStylesheetTransformer::splitSelectorList(RichTextMarkerSelector::carrierSelectorList($marker)) ?? array() as $carrier) $carriers[] = trim($carrier);
}
$classes = array('blocks-engine-semantic-1058c139c202-0', 'blocks-engine-semantic-259a669c7de1-0');
$arms = array();
foreach (array('', ':after', ':before', '::marker') as $pseudo) {
    foreach ($carriers as $carrier) $arms[] = ':where(' . $carrier . '):not(.be-c-0)' . $pseudo;
    foreach ($classes as $class) $arms[] = ':where(.' . $class . '):not(.be-c-0)' . $pseudo;
}
$arms[] = 'p.lead';
$prelude = implode(',', $arms);
$css = $prelude . '{margin:0}';
$out = CssSelectorListCompactor::stylesheet($css, true);

$selector = substr($out, 0, (int) strpos($out, '{'));
$assert(9 === count(CssStylesheetTransformer::splitSelectorList($selector) ?? array()), 'site-wide sheet merges RichText carrier and pseudo-element marker arms into one arm per suffix and kind', $out);
foreach (array('', ':after', ':before', '::marker') as $pseudo) {
    $assert(str_contains($out, ':where(' . implode(',', $carriers) . '):not(.be-c-0)' . $pseudo . ','), 'carriers share one :where() list per suffix ' . ($pseudo ?: '(element)'), $out);
    $assert(str_contains($out, ':where(.' . implode(',.', $classes) . '):not(.be-c-0)' . $pseudo . ','), 'marker classes share one :where() list per suffix ' . ($pseudo ?: '(element)'), $out);
}
$assert(str_contains($out, 'p.lead'), 'unrelated arms stay', $out);

$expanded = CssStylesheetTransformer::splitSelectorList(CssSelectorListCompactor::expand($selector)) ?? array();
$expanded = array_map('trim', $expanded);
sort($expanded);
$original = $arms;
sort($original);
$assert($original === $expanded, 'merged lists expand back into exactly the original arms', implode("\n", array_diff($original, $expanded)) . "\n--\n" . implode("\n", array_diff($expanded, $original)));

// Quoted text outside a carrier, or a carrier mixed with classes, never merges.
$quoted = ':where(' . $carriers[0] . ')[title="a,b"],:where(' . $carriers[1] . ')[title="a,b"]';
$assert($quoted === CssSelectorListCompactor::mergeMarkerLists($quoted), 'arms quoting text outside carriers stay as authored');
$mixed = ':where(' . $carriers[0] . ') a,:where(.' . $classes[0] . ') a';
$assert($mixed === CssSelectorListCompactor::mergeMarkerLists($mixed), 'carrier and class lists never mix');

echo "RichText marker arms merge site-wide: passed\n";
