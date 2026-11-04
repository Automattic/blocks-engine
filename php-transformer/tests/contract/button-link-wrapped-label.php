<?php
declare(strict_types=1);

/**
 * A button-styled link whose label sits inside one unstyled wrapper element
 * (anchor > div.label > p) lowers to core/buttons > core/button exactly as
 * the unwrapped `<a><p>` form does, with the same text, href, and button
 * styling. A wrapper painting its own box keeps the link a container.
 *
 * https://github.com/Automattic/blocks-engine/issues/4314
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$findBlocks = static function (array $blocks, string $name) use (&$findBlocks): array {
    $found = array();
    foreach ($blocks as $block) {
        if ($name === ($block['blockName'] ?? '')) $found[] = $block;
        if (!empty($block['innerBlocks'])) $found = array_merge($found, $findBlocks($block['innerBlocks'], $name));
    }
    return $found;
};
$firstBlock = static function (array $blocks, string $name) use ($findBlocks): ?array {
    $found = $findBlocks($blocks, $name);
    return $found[0] ?? null;
};

$document = <<<'HTML'
<!doctype html>
<html><head><meta charset="utf-8"><title>Block CTA</title>
<style>
:root{--token-1801:#c6ff4d;--token-5c4a:#101010}
.cta{display:flex;align-items:center;padding:12px 20px;text-decoration:none;width:min-content;white-space:nowrap}
.cta p{margin:0;font-size:14px;font-weight:600;color:#101010}.framer-text{color:var(--framer-text-color)}
</style></head>
<body>
<p>Intro text.</p>
%1$s
</body></html>
HTML;

$paintedAnchor = '<a class="cta" href="/kontakt/" style="background-color:#c6ff4d;border-radius:2px">';
$framer = '<a class="cta" href="/kontakt/" style="background-color:var(--token-1801);border-radius:2px"><div class="framer-1yr8m22" style="--extracted-r6o4lv:var(--token-5c4a)"><p class="framer-text" style="--framer-font-size:14px;--framer-font-weight:600;--framer-text-color:var(--extracted-r6o4lv, var(--token-5c4a))">Projekt besprechen ↗</p></div></a>';
$cases = array(
    'framer custom property' => $framer,
    'wrapped label' => $paintedAnchor . '<div class="label"><p>Projekt besprechen ↗</p></div></a>',
    'unwrapped control' => $paintedAnchor . '<p>Projekt besprechen ↗</p></a>',
    'painting wrapper' => $paintedAnchor . '<div style="background:red"><p>Projekt besprechen ↗</p></div></a>',
);

$results = array();
foreach ($cases as $label => $anchorMarkup) {
    $results[$label] = (new HtmlTransformer())->transform(sprintf($document, $anchorMarkup))->toArray();
}

$nativeCases = array('wrapped label', 'unwrapped control', 'framer custom property');
foreach ($nativeCases as $label) {
    $result = $results[$label];
    $button = $firstBlock($result['blocks'] ?? array(), 'core/button');
    $assert(null !== $button, $label . ': the painted control lowers to core/button');
    $assert('Projekt besprechen ↗' === ($button['attrs']['text'] ?? ''), $label . ': the label becomes the editable button text');
    $assert('/kontakt/' === ($button['attrs']['url'] ?? ''), $label . ': the destination is the button url');
    $assert('#c6ff4d' === ($button['attrs']['style']['color']['background'] ?? ''), $label . ': the anchor-owned fill stays on the button');
    $assert('2px' === ($button['attrs']['style']['border']['radius'] ?? ''), $label . ': the anchor-owned rounding stays on the button');
    $assert('12px' === ($button['attrs']['style']['spacing']['padding']['top'] ?? '') && '20px' === ($button['attrs']['style']['spacing']['padding']['left'] ?? ''), $label . ': the authored box padding stays on the button');
    $assert('14px' === ($button['attrs']['style']['typography']['fontSize'] ?? '') && '600' === ($button['attrs']['style']['typography']['fontWeight'] ?? '') && '#101010' === ($button['attrs']['style']['color']['text'] ?? ''), $label . ': the label element typography and color are carried');
}

$unwrappedButton = $firstBlock($results['unwrapped control']['blocks'] ?? array(), 'core/button');
$wrappedButton = $firstBlock($results['wrapped label']['blocks'] ?? array(), 'core/button');
$assert(null !== $unwrappedButton && null !== $wrappedButton && $unwrappedButton['attrs'] === $wrappedButton['attrs'], 'the wrapped label lowers to the identical core/button of the unwrapped form');

$painted = $results['painting wrapper'];
$assert(null === $firstBlock($painted['blocks'] ?? array(), 'core/button') && null === $firstBlock($painted['blocks'] ?? array(), 'core/buttons'), 'painting wrapper: a wrapper painting its own box keeps the link a container');
$assert('' !== $firstBlock($painted['blocks'] ?? array(), 'core/paragraph'), 'painting wrapper: the label stays an editable paragraph');
$assert(str_contains((string) ($painted['serialized_blocks'] ?? ''), 'href="/kontakt/"'), 'painting wrapper: the container keeps its destination');
$assert(str_contains((string) ($painted['serialized_blocks'] ?? ''), 'background-color:red'), 'painting wrapper: the wrapper keeps its own paint');

if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
echo "Button link wrapped label contract passed\n";
