<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Wix's default button skin paints the anchor from an attribute condition on
// its wrapper: `.mu5PoX[aria-disabled=false] .twJknM`. The wrapper becomes a
// core/group, whose serialization keeps only id, class and style, so the
// condition must survive as a class or the button loses its fill and border.
$assertions = 0;
$failures = array();
$assert = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    ++$assertions;
    if (!$condition) $failures[] = $message;
};
$transform = static fn (string $html): array => (new HtmlTransformer())->transform($html, array())->toArray();
$css = static fn (array $result): string => implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? ''))
));
$stateClass = '(blocks-engine-attribute-state-[a-f0-9]{12}-\d+)';
// Engine marker hashes are seeded by the stylesheet, so compare structure with them neutralised.
$withoutStateClasses = static fn (string $markup): string => (string) preg_replace(
    array('/\s*blocks-engine-attribute-state-[a-f0-9]{12}-\d+/', '/blocks-engine-([a-z-]+)-[a-f0-9]{12}-(\d+)/'),
    array('', 'blocks-engine-$1-H-$2'),
    $markup
);

// 1. The Wix button skin and its dynamic variants follow the converted wrapper.
$skin = '.mu5PoX{height:100%}'
    . '.mu5PoX .twJknM{border-radius:var(--rd,0);position:absolute;inset:0}'
    . '.mu5PoX[aria-disabled=false] .twJknM{background-color:rgba(var(--bg),1);border:solid rgba(var(--brd),1) var(--brw,0)}'
    . 'body:not(.device-mobile-optimized) .mu5PoX[aria-disabled=false]:hover .twJknM{background-color:rgba(var(--bgh),1)}'
    . '.mu5PoX[aria-disabled="false"] .twJknM::after{content:""}'
    . '.mu5PoX[aria-disabled="false"] .OR4Nv8{text-transform:uppercase}'
    . '.mu5PoX[aria-disabled=false] .OR4Nv8{letter-spacing:2px}'
    . '.mu5PoX[aria-disabled=true] .twJknM{background-color:rgb(204,204,204)}'
    . '.mu5PoX:not([aria-disabled=true]) .OR4Nv8{color:black}'
    . '#comp-a{--bg:255,220,98;--bgh:0,0,0;--brd:0,0,0;--brw:1px;width:204px;height:40px;position:relative}';
$button = '<div class="mu5PoX" id="comp-a" aria-disabled="false"><a href="/contact-us" class="twJknM wixui-button" aria-disabled="false"><span class="OR4Nv8">Contact Us</span></a></div>';
$result = $transform('<style>' . $skin . '</style><main>' . $button . '</main>');
$markup = $result['serialized_blocks'];
$stylesheet = $css($result);
$assert(1 === preg_match('/<div id="comp-a" class="[^"]*\b' . $stateClass . '\b/', $markup, $wrapperMarker), 'the wrapper that loses aria-disabled carries an attribute-state class');
$marker = $wrapperMarker[1] ?? 'missing-marker';
$assert(str_contains($stylesheet, '.mu5PoX[aria-disabled=false] .twJknM,.mu5PoX.' . $marker . ' .twJknM{'), 'the skin fill rule also selects the converted wrapper through its state class');
$assert(str_contains($stylesheet, '.mu5PoX.' . $marker . ':hover .twJknM'), 'the hover variant keeps its dynamic state on the projected ancestor');
$assert(!str_contains($stylesheet, '.mu5PoX.' . $marker . ' .twJknM::after'), 'pseudo-element rules are left as authored rather than half-projected');
$assert(1 === preg_match('/\.mu5PoX\.' . preg_quote($marker, '/') . ' \.OR4Nv8:not\(:where\(\.blocks-engine-richtext-/', $stylesheet), 'the class form inherits the rich-text exclusion of the projected original');
$assert(str_contains($stylesheet, '.mu5PoX[aria-disabled=true] .twJknM{'), 'a state no source wrapper held stays as authored, with no class form');
$assert(1 === preg_match('/<a [^>]*class="twJknM wixui-button"[^>]*aria-disabled="false"/', $markup), 'the anchor keeps its own attribute and gains no state class');
$assert(1 === preg_match('/\.mu5PoX\.' . preg_quote($marker, '/') . ' \.OR4Nv8[^{]*\{text-transform:uppercase/', $stylesheet), 'quote styles of one condition share a single class');
$assert(str_contains($stylesheet, ':not([aria-disabled=true])'), 'conditions inside a negation are left to their own projection');
$plain = $transform('<style>' . str_replace(array('[aria-disabled=false]', '[aria-disabled="false"]'), '', $skin) . '</style><main>' . $button . '</main>');
$assert($withoutStateClasses($markup) === $withoutStateClasses($plain['serialized_blocks']), 'the state class changes no block structure or layout ownership');

// 2. A dropped navigation toggle written with an attribute condition keeps
// its superseded-toggle scoping in the class form.
$toggle = $transform('<style>.bar{display:flex;align-items:center;position:relative}.bar nav{position:absolute;right:0;display:flex;gap:22px}.bar nav a{font-size:18px;text-decoration:none}.bar[role=banner] button{display:none;border:0;background:none;font-size:27px}@media(max-width:1300px){.bar[role=banner] button{position:absolute;right:0;margin:0;padding:8px;display:flex}.bar nav{display:none;position:absolute;left:0;right:0;top:100%;flex-direction:column}.bar nav.open{display:flex}}</style><header><div class="bar" role="banner"><a class="brand" href="#home">Brand</a><button aria-controls="site-menu" aria-expanded="false" aria-label="Menu"><svg viewBox="0 0 24 24" width="24" height="24"><path d="M3 6h18M3 12h18M3 18h18"/></svg></button><nav id="site-menu"><a href="#home">Home</a><a href="#about">About</a><a href="#work">Work</a><a href="#contact">Contact</a></nav></div></header><main><h1>Hello</h1></main>');
$toggleCss = $css($toggle);
$assert(1 === preg_match('/\.bar\.' . $stateClass . ' button:where\(\.blocks-engine-superseded-menu-toggle\)/', $toggleCss), 'the class form keeps the superseded-toggle scope');
$assert(0 === preg_match('/\.bar\.blocks-engine-attribute-state-[a-f0-9]{12}-\d+ button(?!:where\(\.blocks-engine-superseded-menu-toggle\))/', $toggleCss), 'no unscoped class-form button selector can reach core navigation buttons');

// 3. Unrelated attribute holders are not newly preserved as wrappers.
$langSource = '<main><section lang="fr"><p>Bonjour</p></section><div><p class="t">x</p></div></main>';
$lang = $transform('<style>[lang=fr] p{font-style:italic}</style>' . $langSource);
$langPlain = $transform('<style>p{font-style:italic}</style>' . $langSource);
$assert($withoutStateClasses($lang['serialized_blocks']) === $withoutStateClasses($langPlain['serialized_blocks']), 'section[lang] keeps the wrapper decision it gets without the attribute rule');

// 4. Conditions differing only inside a quoted value get distinct classes.
$labels = $transform('<style>.w[aria-label="Sign In"] .t{color:red}.w[aria-label="SignIn"] .t{background:yellow}</style><main><div class="w" aria-label="Sign In"><a class="t" href="/a">A</a></div><div class="w" aria-label="SignIn"><a class="t" href="/b">B</a></div></main>');
preg_match_all('/<div class="wp-block-group w ' . $stateClass . '"/', $labels['serialized_blocks'], $labelMarkers);
$assert(2 === count(array_unique($labelMarkers[1] ?? array())), 'whitespace inside a quoted value is part of the condition identity');

// 5. States a script or the page toggles, and selectors keeping another lost condition, are not frozen.
$scripted = $transform('<style>.arrow[aria-disabled=true] .ico{opacity:.3}</style><main><div class="arrow" id="prev" aria-disabled="true"><span class="ico">&lt;</span></div></main><script>document.querySelector("#prev").setAttribute("aria-disabled","false")</script>');
$assert(!str_contains($scripted['serialized_blocks'], 'blocks-engine-attribute-state-'), 'a condition the source script writes is not frozen into a class');
$current = $transform('<style>div[aria-current=page] .t{font-weight:bold}</style><main><div aria-current="page"><p class="t">x</p></div></main>');
$assert(!str_contains($current['serialized_blocks'], 'blocks-engine-attribute-state-'), 'per-page aria-current is not frozen into a class');
$mixed = $transform('<style>.w[data-state=on][aria-disabled=false] .t{color:red}</style><main><div class="w" data-state="on" aria-disabled="false"><a class="t" href="/x">x</a></div></main>');
$assert(!preg_match('/\.w\[data-state=on\]\.blocks-engine-attribute-state-/', $css($mixed)), 'a class form that still needs a lost data condition is not emitted');

// 6. A condition repeated inside :not() keeps only the original selector:
// replacing both copies would let the negation's own holder match.
$negated = $transform('<style>.a[role=group] .b:not([role=group]) .c{color:red}</style><main><div class="a" role="group"><div class="b"><p class="c">yes</p></div><div class="b" role="group"><p class="c">no</p></div></div></main>');
$assert(!preg_match('/:not\(\.blocks-engine-attribute-state-/', $css($negated)), 'a condition copied inside :not() is never replaced by its class');
$assert(!str_contains($negated['serialized_blocks'], 'blocks-engine-attribute-state-'), 'a selector repeating its condition inside :not() gets no class form');

// 7. Only executable scripts that write the attribute disable the class form.
$jsonOnly = $transform('<style>.w[aria-disabled=false] .t{background:yellow}</style><main><div class="w" aria-disabled="false"><a class="t" href="/a">A</a></div></main><script type="application/json" id="cfg">{"buttons":{"style":{"aria-disabled":"false"}}}</script>');
$assert(1 === preg_match('/<div class="wp-block-group w ' . $stateClass . '"/', $jsonOnly['serialized_blocks']), 'a JSON data block mentioning the attribute does not disable the fix');
$reader = $transform('<style>.w[aria-disabled=false] .t{background:yellow}</style><main><div class="w" aria-disabled="false"><a class="t" href="/a">A</a></div></main><script>if (el.getAttribute("aria-disabled") === "false") go();</script>');
$assert(1 === preg_match('/<div class="wp-block-group w ' . $stateClass . '"/', $reader['serialized_blocks']), 'a script that only reads the attribute does not disable the fix');
$property = $transform('<style>.w[aria-disabled=false] .t{background:yellow}</style><main><div class="w" aria-disabled="false"><a class="t" href="/a">A</a></div></main><script>document.querySelectorAll(".w").forEach(e=>e.ariaDisabled="true")</script>');
$assert(!str_contains($property['serialized_blocks'], 'blocks-engine-attribute-state-'), 'an ARIA reflection property write disables the fix');

if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
echo "Ancestor attribute state projection passed: {$assertions} assertions\n";
