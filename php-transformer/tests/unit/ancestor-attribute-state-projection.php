<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Wix's default button skin paints the anchor from an attribute condition on
// its wrapper: `.mu5PoX[aria-disabled=false] .twJknM`. The wrapper becomes a
// core/group, whose serialization keeps only id, class and style, so the
// condition must survive as a class or the button loses its fill and border.
$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$transform = static fn (string $html): array => (new HtmlTransformer())->transform($html, array())->toArray();
$css = static fn (array $result): string => implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? ''))
));

$skin = '.mu5PoX{height:100%}'
    . '.mu5PoX .twJknM{border-radius:var(--rd,0);position:absolute;inset:0}'
    . '.mu5PoX[aria-disabled=false] .twJknM{background-color:rgba(var(--bg),1);border:solid rgba(var(--brd),1) var(--brw,0)}'
    . 'body:not(.device-mobile-optimized) .mu5PoX[aria-disabled=false]:hover .twJknM{background-color:rgba(var(--bgh),1)}'
    . '.mu5PoX[aria-disabled=true] .twJknM{background-color:rgb(204,204,204)}'
    . '.mu5PoX:not([aria-disabled=true]) .OR4Nv8{color:black}'
    . '#comp-a{--bg:255,220,98;--bgh:0,0,0;--brd:0,0,0;--brw:1px;width:204px;height:40px;position:relative}';
$result = $transform('<style>' . $skin . '</style><main><div class="mu5PoX" id="comp-a" aria-disabled="false"><a href="/contact-us" class="twJknM wixui-button" aria-disabled="false"><span class="OR4Nv8">Contact Us</span></a></div></main>');
$markup = $result['serialized_blocks'];
$stylesheet = $css($result);

$assert(1 === preg_match('/<div id="comp-a" class="[^"]*\b(blocks-engine-attribute-state-[a-f0-9]{12}-\d+)\b/', $markup, $wrapperMarker), 'the wrapper that loses aria-disabled carries an attribute-state class');
$marker = $wrapperMarker[1] ?? 'missing-marker';
$assert(str_contains($stylesheet, '.mu5PoX[aria-disabled=false] .twJknM,.mu5PoX.' . $marker . ' .twJknM{'), 'the skin fill rule also selects the converted wrapper through its state class');
$assert(str_contains($stylesheet, '.mu5PoX.' . $marker . ':hover .twJknM'), 'the hover variant keeps its dynamic state on the same projected ancestor');
$assert(str_contains($stylesheet, '.mu5PoX[aria-disabled=true] .twJknM{'), 'a state no source wrapper held stays as authored, with no state class invented for it');
$assert(1 === preg_match('/<a [^>]*class="twJknM wixui-button"[^>]*aria-disabled="false"/', $markup), 'the anchor keeps its own attribute and gains no state class');
$assert(str_contains($stylesheet, ':not([aria-disabled=true])'), 'conditions inside a negation are left to their own projection');

if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
echo "Ancestor attribute state projection passed: 6 assertions\n";
