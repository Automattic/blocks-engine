<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$findButton = static function (array $blocks) use (&$findButton): ?array {
    foreach ($blocks as $block) {
        if ('core/button' === ($block['blockName'] ?? '')) return $block;
        $found = $findButton($block['innerBlocks'] ?? array());
        if (null !== $found) return $found;
    }
    return null;
};
$transform = static fn (string $html): array => (new HtmlTransformer())->transform($html, array())->toArray();
$owned = $transform('<style>.cta{display:flex;padding:16px 20px;border-radius:10px;background:#39b4e1;color:white}.label{opacity:1}</style><header><a class="cta" href="/contact"><span class="label">Contact us</span></a></header>');
$button = $findButton($owned['blocks']);
$assert('#39b4e1' === ($button['attrs']['style']['color']['background'] ?? ''), 'the painted anchor retains its native fill');
$assert('16px' === ($button['attrs']['style']['spacing']['padding']['top'] ?? ''), 'anchor-owned vertical padding survives a styled label descendant');
$assert('20px' === ($button['attrs']['style']['spacing']['padding']['right'] ?? ''), 'anchor-owned horizontal padding survives');
$assert('10px' === ($button['attrs']['style']['border']['radius'] ?? ''), 'anchor-owned rounding survives');
$assert('/contact' === ($button['attrs']['url'] ?? '') && str_contains($button['attrs']['text'] ?? '', 'Contact us'), 'destination and editable text remain native');
$typedLabel = $transform('<style>.cta{padding:10px 20px;border-radius:10px;background:#39b4e1}.label p{color:white;font-family:Inter;font-size:16px;font-weight:600;line-height:30px}</style><a class="cta" href="/contact"><div class="label"><p>Contact</p></div></a>');
$typed = $findButton($typedLabel['blocks']);
$assert(null === $typed, 'a paragraph label remains block content rather than inline reduction');
$assert(str_contains($typedLabel['serialized_blocks'], '>Contact</p>'), 'the paragraph remains editable');
$assert(str_contains($typedLabel['serialized_blocks'], 'href="/contact"'), 'the container keeps its destination');

// A heading tag alone is not a card: own chrome plus one direct label is
// the historical native-button case. Wrapped/branching content is not.
foreach (array('background:#135e96', 'border:1px solid #135e96') as $paint) {
    $single = $transform('<a class="primary-button" href="/book" style="padding:10px 16px;' . $paint . '"><h3>Reserve now</h3><span aria-hidden="true"></span></a>');
    $native = $findButton($single['blocks']);
    $assert(null !== $native && '/book' === ($native['attrs']['url'] ?? ''), 'own painted single heading label remains a native button');
}
foreach (array(
    '<h3>Title</h3>',
    '<div><h3>Title</h3></div>',
    '<div><h3>Title</h3><div><svg width="24" height="24"></svg></div></div>',
    '<h3>Title</h3><p>Details</p>',
    '<h3>Title</h3><img src="https://example.com/icon.png" alt="">',
    '<h3>Title</h3><div></div>',
) as $index => $content) {
    $style = 0 === $index ? '' : ' style="padding:12px;background:#135e96"';
    $card = $transform('<a href="/card"' . $style . '>' . $content . '</a>');
    $assert(null === $findButton($card['blocks']), 'unpainted or structural card content is not a button: ' . $index);
    $assert(str_contains($card['serialized_blocks'], '<h3') && str_contains($card['serialized_blocks'], 'href="/card"'), 'linked card retains heading and destination: ' . $index);
}

$childOwned = $transform('<style>.surface{display:flex;padding:12px;border-radius:6px;background:#123456;color:white}</style><a href="/contact"><span class="surface">Contact</span></a>');
$child = $findButton($childOwned['blocks']);
$assert('#123456' === ($child['attrs']['style']['color']['background'] ?? ''), 'a genuinely child-owned surface still provides its paint');
$assert('12px' === ($child['attrs']['style']['spacing']['padding']['top'] ?? ''), 'a genuinely child-owned surface still provides its sizing');

if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
echo "Anchor-owned button surface passed\n";
