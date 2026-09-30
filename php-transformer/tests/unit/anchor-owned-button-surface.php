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
$owned = $transform('<style>.cta{display:flex;padding:16px 20px;border-radius:10px;background:#39b4e1;color:white}.label{opacity:1}</style><header><a class="cta" href="/contact"><div class="label">Contact us</div></a></header>');
$button = $findButton($owned['blocks']);
$assert('#39b4e1' === ($button['attrs']['style']['color']['background'] ?? ''), 'the painted anchor retains its native fill');
$assert('16px' === ($button['attrs']['style']['spacing']['padding']['top'] ?? ''), 'anchor-owned vertical padding survives a styled label descendant');
$assert('20px' === ($button['attrs']['style']['spacing']['padding']['right'] ?? ''), 'anchor-owned horizontal padding survives');
$assert('10px' === ($button['attrs']['style']['border']['radius'] ?? ''), 'anchor-owned rounding survives');
$assert('/contact' === ($button['attrs']['url'] ?? '') && 'Contact us' === ($button['attrs']['text'] ?? ''), 'destination and editable text remain native');
$typedLabel = $transform('<style>.cta{padding:10px 20px;border-radius:10px;background:#39b4e1}.label p{color:white;font-family:Inter;font-size:16px;font-weight:600;line-height:30px}</style><a class="cta" href="/contact"><div class="label"><p>Contact</p></div></a>');
$typed = $findButton($typedLabel['blocks']);
$assert('16px' === ($typed['attrs']['style']['typography']['fontSize'] ?? ''), 'the label font is preserved independently from its anchor box');
$assert('Inter' === ($typed['attrs']['style']['typography']['fontFamily'] ?? '') && '30px' === ($typed['attrs']['style']['typography']['lineHeight'] ?? ''), 'the declared label face and leading survive inline reduction');
$assert('white' === ($typed['attrs']['style']['color']['text'] ?? ''), 'the declared label foreground survives');

$childOwned = $transform('<style>.surface{display:flex;padding:12px;border-radius:6px;background:#123456;color:white}</style><a href="/contact"><div class="surface">Contact</div></a>');
$child = $findButton($childOwned['blocks']);
$assert('#123456' === ($child['attrs']['style']['color']['background'] ?? ''), 'a genuinely child-owned surface still provides its paint');
$assert('12px' === ($child['attrs']['style']['spacing']['padding']['top'] ?? ''), 'a genuinely child-owned surface still provides its sizing');

if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
echo "Anchor-owned button surface passed: 10 assertions\n";
