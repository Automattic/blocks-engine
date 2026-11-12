<?php
declare(strict_types=1);

// Pager dots: a list of empty links in a nav, each named only by aria-label.
// The source paints no text. core/navigation-link paints its label, so the
// name must be kept read but not painted, or each 8px dot shows "Slide 1".
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$html = '<style>.dots{display:flex;list-style:none;margin:0;padding:0}.dot{display:block;width:8px;height:8px;margin:0 6px;border-radius:50%;background:#333}</style>'
    . '<p>Carousel</p>'
    . '<nav aria-label="Slides"><ol class="dots">'
    . '<li><a href="#s1" aria-label="Slide 1" class="dot"></a></li>'
    . '<li><a href="#s2" aria-label="Slide 2" class="dot"></a></li>'
    . '<li aria-current="true"><a href="#s3" title="Slide 3" class="dot on"></a></li>'
    . '</ol></nav>'
    . '<nav aria-label="Main"><ul><li><a href="/about/">About</a></li><li><a href="/contact/" aria-label="Contact us">Contact</a></li></ul></nav>';

$links = array();
$visit = static function (array $blocks) use (&$visit, &$links): void {
    foreach ($blocks as $block) {
        if ('core/navigation-link' === ($block['blockName'] ?? '')) $links[(string) ($block['attrs']['url'] ?? '')] = (string) ($block['attrs']['label'] ?? '');
        $visit($block['innerBlocks'] ?? array());
    }
};
$blocks = (new HtmlTransformer())->transform($html)->toArray()['blocks'];
$visit($blocks);

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
foreach (array('#s1' => 'Slide 1', '#s2' => 'Slide 2', '#s3' => 'Slide 3') as $url => $name) {
    $assert(SourceDom::visuallyHiddenLabel($name) === ($links[$url] ?? null), "empty anchor {$url} keeps its name visually hidden; got " . var_export($links[$url] ?? null, true));
}
$assert('About' === ($links['/about/'] ?? null), 'a text link keeps its visible label');
$assert('Contact' === ($links['/contact/'] ?? null), 'a text link with aria-label keeps its visible text, not the hidden name');
$assert(!str_contains((new Runtime())->serializeBlocks($blocks), '"label":"Slide 1"'), 'no empty anchor name is saved as a plain visible label');

if (array() !== $failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "navigation-empty-anchor-name: passed\n");
