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

// Rule-level bisect of the real Framer import. Every row must retain #101010.
// Before the resolver fix:
// | Extra rule | style.color.text |
// | --- | --- |
// | @supports not (color:color(display-p3 1 1 1)){a.framer-text{color:red}} | LOST |
// | @supports not (display:grid){a.framer-text[data-current]{color:red}} | LOST |
// | @supports (display:flex){a.framer-text[data-current]{color:red}} | kept |
// | @supports not (display:grid){a[data-current]{color:red}} | kept |
// | @media (min-width:1px){a[data-current]{color:red}} | kept |
// | a.framer-text[data-current]{color:red} | kept |
$conditionalRules = array(
    'LOST: negated gamut supports / missing class' => '@supports not (color:color(display-p3 1 1 1)){a.framer-text{color:red}}',
    'LOST: negated grid supports / missing class and attribute' => '@supports not (display:grid){a.framer-text[data-current]{color:red}}',
    'kept: positive supports' => '@supports (display:flex){a.framer-text[data-current]{color:red}}',
    'kept: negated supports / missing attribute' => '@supports not (display:grid){a[data-current]{color:red}}',
    'kept: media / missing attribute' => '@media (min-width:1px){a[data-current]{color:red}}',
    'kept: unconditional / missing class and attribute' => 'a.framer-text[data-current]{color:red}',
    'kept: no extra rule' => '',
);
$minimalDocument = <<<'HTML'
<!doctype html><html><head><style>
body{--token-ink:#101010;--token-lime:#c6ff4d}
p.framer-text{color:var(--framer-blockquote-text-color-rgb,var(--framer-blockquote-text-color,var(--framer-text-color-rgb,var(--framer-text-color,#000))))}
.cta{display:flex;padding:16px 24px;border-radius:2px;text-decoration:none}
%s
</style></head><body><main><section>
<a class="cta" href="/leistungen/" style="background-color:var(--token-lime)"><div class="label" style="--extracted-r6o4lv: var(--token-ink)"><p class="framer-text" style="--framer-font-size:14px;--framer-text-color:var(--extracted-r6o4lv, var(--token-ink))">Alle Leistungen ansehen</p></div></a>
</section></main></body></html>
HTML;
foreach ($conditionalRules as $label => $rule) {
    $result = (new HtmlTransformer())->transform(sprintf($minimalDocument, $rule))->toArray();
    $button = $firstBlock($result['blocks'] ?? array(), 'core/button');
    $color = $button['attrs']['style']['color']['text'] ?? null;
    $assert('#101010' === $color, $label . ': expected style.color.text #101010; got ' . json_encode($color));
}

// The second rule isolated by bisecting the real page's 300 rules matches
// the label, but its extra unset -rgb fallbacks compute to the same color.
$duplicateDocument = str_replace(
    'p.framer-text{color:var(--framer-blockquote-text-color-rgb,var(--framer-blockquote-text-color,var(--framer-text-color-rgb,var(--framer-text-color,#000))))}',
    'p.framer-text{color:var(--framer-blockquote-text-color,var(--framer-text-color,#000))}',
    $minimalDocument
);
$duplicateRule = '@supports not (color:color(display-p3 1 1 1)){p.framer-text{color:var(--framer-blockquote-text-color-rgb,var(--framer-blockquote-text-color,var(--framer-text-color-rgb,var(--framer-text-color,#000))))}}';
foreach (array('equal resolved fallback' => '', 'different resolved fallback' => '--framer-text-color-rgb:#ff0000;') as $label => $inline) {
    $html = str_replace('--framer-font-size:14px;', $inline . '--framer-font-size:14px;', sprintf($duplicateDocument, $duplicateRule));
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $button = $firstBlock($result['blocks'] ?? array(), 'core/button');
    $color = $button['attrs']['style']['color']['text'] ?? null;
    $assert(null !== $button && ('' === $inline ? '#101010' === $color : null === $color), $label . ': matching conditional duplicate; got ' . json_encode($color));
}

// A conditional rule that really can match must still own the color in CSS,
// rather than being frozen into an unconditional native text-color attribute.
foreach (array(
    '@supports not (display:grid){p.framer-text{color:red}}',
    '@media (min-width:1px){p.framer-text{color:red}}',
) as $rule) {
    $result = (new HtmlTransformer())->transform(sprintf($minimalDocument, $rule))->toArray();
    $button = $firstBlock($result['blocks'] ?? array(), 'core/button');
    $assert(null !== $button && !isset($button['attrs']['style']['color']['text']), 'matching conditional selector keeps color stylesheet-owned: ' . $rule);
}

// DOM delta-debug reduction of the real home page: a mixed inline run must
// resolve the wrapped label in its original CSS scope, just like a lone CTA.
$mixedInlineDocument = <<<'HTML'
<!DOCTYPE html><html><head><style>body { --token-5c4ab942-0b5f-42a3-81d8-027b4885b291: #101010; --token-9e31575d-60ee-43f8-9b06-6fc6c6ac75de: #f5f3ee; --token-d1cb36e4-3378-4804-a263-30f466c246a7: #858585; --token-1801d4d9-9be0-45f8-85d1-e9c989c018cf: #c6ff4d; }
p.framer-text, div.framer-text, h1.framer-text, h2.framer-text, h3.framer-text, h4.framer-text, h5.framer-text, h6.framer-text, li.framer-text, ol.framer-text, ul.framer-text, mark.framer-text, span.framer-text:not([data-text-fill]) { font-family: var(--framer-font-family-preview,var(--framer-blockquote-font-family,var(--framer-font-family,Inter, Inter Placeholder, sans-serif))); font-style: var(--framer-font-style-preview,var(--framer-blockquote-font-style,var(--framer-font-style,normal))); font-weight: var(--framer-font-weight-preview,var(--framer-blockquote-font-weight,var(--framer-font-weight,400))); color: var(--framer-blockquote-text-color,var(--framer-text-color,#000)); font-size: calc(var(--framer-blockquote-font-size,var(--framer-font-size,16px)) * var(--framer-font-size-scale,1)); letter-spacing: var(--framer-blockquote-letter-spacing,var(--framer-letter-spacing,0)); text-transform: var(--framer-blockquote-text-transform,var(--framer-text-transform,none)); text-decoration-line: var(--framer-blockquote-text-decoration,var(--framer-text-decoration,initial)); text-decoration-style: var(--framer-blockquote-text-decoration-style,var(--framer-text-decoration-style,initial)); text-decoration-color: var(--framer-blockquote-text-decoration-color,var(--framer-text-decoration-color,initial)); text-decoration-thickness: var(--framer-blockquote-text-decoration-thickness,var(--framer-text-decoration-thickness,initial)); text-decoration-skip-ink: var(--framer-blockquote-text-decoration-skip-ink,var(--framer-text-decoration-skip-ink,initial)); text-underline-offset: var(--framer-blockquote-text-decoration-offset,var(--framer-text-decoration-offset,initial)); line-height: var(--framer-blockquote-line-height,var(--framer-line-height,1.2em)); text-align: var(--framer-blockquote-text-alignment,var(--framer-text-alignment,start)); -webkit-text-stroke-width: var(--framer-text-stroke-width,initial); -webkit-text-stroke-color: var(--framer-text-stroke-color,initial); font-feature-settings: var(--framer-font-open-type-features,initial); font-variation-settings: var(--framer-font-variation-axes-preview,var(--framer-font-variation-axes,normal)); text-wrap: var(--framer-text-wrap-override,var(--framer-text-wrap)); }
@supports not (color:color(display-p3 1 1 1)) {
  p.framer-text, div.framer-text, h1.framer-text, h2.framer-text, h3.framer-text, h4.framer-text, h5.framer-text, h6.framer-text, li.framer-text, ol.framer-text, ul.framer-text, span.framer-text:not([data-text-fill]) { color: var(--framer-blockquote-text-color-rgb,var(--framer-blockquote-text-color,var(--framer-text-color-rgb,var(--framer-text-color,#000)))); -webkit-text-stroke-color: var(--framer-text-stroke-color-rgb,var(--framer-text-stroke-color,initial)); }
  mark.framer-text { background-color: var(--framer-blockquote-text-background-color-rgb,var(--framer-blockquote-text-background-color,var(--framer-text-background-color-rgb,var(--framer-text-background-color,initial)))); }
}
.framer-cxa9c.framer-c00dtm { cursor: pointer; flex-flow: row; place-content: center; align-items: center; gap: 0px; width: min-content; height: min-content; padding: 16px 24px; text-decoration: none; display: flex; position: relative; }</style></head>
<body>

	<!-- Start of bodyStart -->

    <!-- End of bodyStart -->

	<!--$-->Gute Unternehmenverdienen bessere<span>Websites.</span><!--$--><!--$--><a class="framer-cxa9c framer-c00dtm framer-v-c00dtm framer-twulji data-blocks-engine-capture-responsive-counterpart-e226f9e06d49" style="background-color:var(--token-1801d4d9-9be0-45f8-85d1-e9c989c018cf);border-bottom-left-radius:2px;border-bottom-right-radius:2px;border-top-left-radius:2px;border-top-right-radius:2px;opacity:1"><div style="--extracted-r6o4lv: var(--token-5c4ab942-0b5f-42a3-81d8-027b4885b291);opacity: 1;transform: none"><p class="framer-text data-blocks-engine-capture-responsive-counterpart-080b549768f4" style="--font-selector:SW50ZXItU2VtaUJvbGQ=;--framer-font-size:14px;--framer-font-weight:600;--framer-text-color:var(--extracted-r6o4lv, var(--token-5c4ab942-0b5f-42a3-81d8-027b4885b291))">Projekt besprechen ↗</p></div></a><!--/$--><!--/$--><!--/$-->


	<!---->



	<!-- Start of bodyEnd -->

    <!-- End of bodyEnd -->


</body></html>
HTML;
foreach (array(
    'LOST: mixed inline run' => $mixedInlineDocument,
    'kept: anchor only' => str_replace('Gute Unternehmenverdienen bessere<span>Websites.</span>', '', $mixedInlineDocument),
    'kept: loose text only' => str_replace('<span>Websites.</span>', '', $mixedInlineDocument),
) as $label => $html) {
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $button = $firstBlock($result['blocks'] ?? array(), 'core/button');
    $color = $button['attrs']['style']['color'] ?? array();
    $assert(null !== $button && '#101010' === ($color['text'] ?? null), $label . ': expected style.color.text #101010; got style.color ' . json_encode($color));
}

if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
echo "Button link wrapped label contract passed\n";
