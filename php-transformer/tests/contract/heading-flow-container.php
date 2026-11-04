<?php
declare(strict_types=1);

// Headings used as layout containers keep editable native children and source presentation (#4327).
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$flatten = static function (array $blocks) use (&$flatten): array {
    $result = array();
    foreach ($blocks as $block) {
        $result[] = $block;
        $result = array_merge($result, $flatten($block['innerBlocks'] ?? array()));
    }
    return $result;
};
$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$cases = array(
    'image card' => array('h2', '<div><img src="red.png" alt="Red square" width="40" height="40"></div><div><p>Marketplace Optimization</p></div>', true),
    'text columns' => array('h3', '<div><p>Marketplace Optimization</p></div><div><p>Second column</p></div>', false),
);
foreach ($cases as $label => [$tag, $content, $hasImage]) {
    $html = '<!doctype html><html><head><meta charset="utf-8"><title>Heading wrapping flow content</title></head><body>'
        . '<' . $tag . ' class="html-element" style="padding:12px">' . $content . '</' . $tag . '></body></html>';
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $blocks = $flatten($result['blocks'] ?? array());
    $assert(!in_array('core/html', array_column($blocks, 'blockName'), true), $label . ': no core/html');
    $groups = array_values(array_filter($blocks, static fn (array $block): bool =>
        'core/group' === ($block['blockName'] ?? null)
        && in_array('html-element', explode(' ', $block['attrs']['className'] ?? ''), true)
    ));
    $assert(1 === count($groups), $label . ': source class belongs to a group');
    $children = $flatten($groups[0]['innerBlocks'] ?? array());
    $paragraphs = array_filter($children, static fn (array $block): bool =>
        'core/paragraph' === ($block['blockName'] ?? null)
        && str_contains((string) ($block['attrs']['content'] ?? ''), 'Marketplace Optimization')
    );
    $assert(array() !== $paragraphs, $label . ': native paragraph inside source group');
    $assert(!$hasImage || in_array('core/image', array_column($children, 'blockName'), true), $label . ': native image inside source group');
    $assert('12px' === ($groups[0]['attrs']['style']['spacing']['padding']['top'] ?? null), $label . ': source presentation retained');
}
// The issue fixture has no author CSS: the replacement div must carry the
// heading box's defaults, while its paragraph inherits rather than resetting.
$fixture = '<div><img src="red.png" alt="Red square" width="40" height="40"></div><div><p>Marketplace Optimization</p></div>';
$result = (new HtmlTransformer())->transform('<h2>' . $fixture . '</h2>')->toArray();
$group = $result['blocks'][0] ?? array();
$assert('core/group' === ($group['blockName'] ?? null), 'unstyled fixture: native group');
$assert('1.5em' === ($group['attrs']['style']['typography']['fontSize'] ?? null), 'unstyled fixture: heading size on group');
$assert('bold' === ($group['attrs']['style']['typography']['fontWeight'] ?? null), 'unstyled fixture: heading weight on group');
foreach (array('top', 'bottom') as $side) {
    $assert('0.83em' === ($group['attrs']['style']['spacing']['margin'][$side] ?? null), 'unstyled fixture: heading margin ' . $side);
}
foreach ($flatten($group['innerBlocks'] ?? array()) as $child) {
    if ('core/paragraph' === ($child['blockName'] ?? null)) {
        $assert(!isset($child['attrs']['style']['typography']['fontSize']) && !isset($child['attrs']['style']['typography']['fontWeight']), 'unstyled fixture: paragraph inherits heading typography');
        $assert(!preg_match('/font-(?:size|weight)/', $child['innerHTML'] ?? ''), 'unstyled fixture: no inline typography reset');
    }
}
$authored = (new HtmlTransformer())->transform('<style>.card{font-size:30px;font-weight:500;line-height:1.4;margin:10px 0}</style><h2 class="card">' . $fixture . '</h2>')->toArray();
$authoredGroup = $authored['blocks'][0] ?? array();
$assert(str_contains($authoredGroup['attrs']['className'] ?? '', 'card'), 'author heading class retained');
$assert('30px' === ($authoredGroup['attrs']['style']['typography']['fontSize'] ?? null), 'author font size wins');
$assert('500' === ($authoredGroup['attrs']['style']['typography']['fontWeight'] ?? null), 'author weight wins');
$assert('1.4' === ($authoredGroup['attrs']['style']['typography']['lineHeight'] ?? null), 'author line height retained');
$assert('10px' === ($authoredGroup['attrs']['style']['spacing']['margin']['top'] ?? null), 'author margin wins');

foreach (array('<h2>Title</h2>', '<h2>Title <em>inline</em></h2>') as $html) {
    $blocks = $flatten((new HtmlTransformer())->transform($html)->toArray()['blocks'] ?? array());
    $headings = array_values(array_filter($blocks, static fn (array $block): bool => 'core/heading' === ($block['blockName'] ?? null)));
    $assert(1 === count($headings) && 2 === ($headings[0]['attrs']['level'] ?? 2), 'plain/inline heading stays level 2');
}
if (array() !== $failures) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "heading-flow-container: passed\n";
