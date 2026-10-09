<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A capture that scopes its rules under a device-document attribute
// (`:where([data-doc="desktop"]) #b1 .style-x__root`) projects them through
// attribute markers. For a Wix button the root anchor's marker lands on the
// core/buttons wrapper and the inner container's markers on core/button, so
// the button fill must be retargeted onto the link through both wrappers.
// Otherwise the wrapper paints the fill and the link keeps the theme's grey pill.
$button = '<a href="/about" class="Btn__root style-x__root wixui-button Btn__link" aria-label="About Me"><span class="Btn__container"><span class="Btn__label wixui-button__label">About Me</span><span class="Btn__icon wixui-button__icon" aria-hidden="true"><span><svg viewBox="0 0 10 10"><path d="M0 0h10v10H0z"/></svg></span></span></span></a>';
$css = ':where([data-doc="desktop"]) .Btn__root{cursor:pointer;box-sizing:border-box;border:0;width:100%;height:100%;padding:0;display:block}'
    . ':where([data-doc="desktop"]) .Btn__container{display:flex;align-items:center;justify-content:center;width:100%;height:100%}'
    . ':where([data-doc="desktop"]) #b1 .style-x__root{background:rgb(0,87,225);padding-left:10px;padding-right:10px}'
    . ':where([data-doc="desktop"]) #b1 .style-x__root .Btn__container{transition:inherit}'
    . '#b1{width:150px;height:42px}';
$source = '<style>' . $css . '</style><main data-doc="desktop"><p>Intro</p><div id="b1">' . $button . '</div></main>';
$result = (new HtmlTransformer())->transform($source)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$output = implode("\n", array_column($result['assets'] ?? array(), 'content'));

if (!preg_match('/<div class="wp-block-buttons ([^"]*)"/', $markup, $buttons) || !preg_match('/<div class="wp-block-button ([^"]*)"/', $markup, $button)) {
    throw new RuntimeException("Fixture must convert the anchor into core/buttons > core/button.\n" . $markup);
}
$rules = array_values(array_filter(preg_split('/(?<=\})/', $output) ?: array(), static fn (string $rule): bool => str_contains($rule, 'background:rgb(0,87,225)') && str_contains($rule, 'blocks-engine-attribute-')));
if (1 !== count($rules)) {
    throw new RuntimeException("The attribute-scoped button fill must be projected once.\n" . $output);
}
$reachesLink = false;
$arms = Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer::splitSelectorList(substr($rules[0], 0, (int) strpos($rules[0], '{'))) ?? array();
foreach ($arms as $arm) {
    $arm = trim($arm);
    if (!preg_match('/:where\(\.(blocks-engine-attribute-[a-f0-9]+-\d+)/', $arm, $marker)) continue;
    $onButtons = in_array($marker[1], explode(' ', $buttons[1]), true);
    $onButton = in_array($marker[1], explode(' ', $button[1]), true);
    if (str_ends_with($arm, '> :where(.wp-block-button)> :where(.wp-block-button__link)')) {
        $reachesLink = $reachesLink || $onButtons;
    } elseif (str_ends_with($arm, '> :where(.wp-block-button__link)')) {
        $reachesLink = $reachesLink || $onButton;
    } elseif (!str_contains($arm, ':not(.wp-block-buttons,.wp-block-button))')) {
        // An arm on the marker itself must exclude the generated wrappers.
        throw new RuntimeException("An attribute-scoped button rule must not paint the wrappers.\n" . trim($rules[0]));
    }
}
if (!$reachesLink) {
    throw new RuntimeException("The attribute-scoped button fill must reach the inner link.\n" . trim($rules[0]) . "\n" . $markup);
}
echo "Attribute-scoped button link passed\n";
