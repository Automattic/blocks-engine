<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A child rule written through data attributes, `[data-a] .wrap > [data-b]`,
// is projected onto marker classes because core blocks keep only id, class
// and style. The subject (the child) and, for the `>` form, its parent both
// receive a marker. They must be different markers: with one shared class
// the subject form `:where(.marker)` also selects the parent, and a child
// declaration such as `width:100%; height:100%` lands on the wrapper, whose
// own `#wrap { width:160px }` then loses to the projected id-level specificity.
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
$markerOf = static function (string $markup, string $id): string {
    return preg_match('/<[a-z]+ [^>]*id="' . preg_quote($id, '/') . '"[^>]*class="[^"]*\b(blocks-engine-attribute-[a-f0-9]{12}-\d+)\b/', $markup, $m)
        || preg_match('/<[a-z]+ [^>]*class="[^"]*\b(blocks-engine-attribute-[a-f0-9]{12}-\d+)\b[^"]*"[^>]*id="' . preg_quote($id, '/') . '"/', $markup, $m)
        ? $m[1] : '';
};

$styles = '#w1{width:160px;min-height:55px}'
    . '#w2{width:50px;min-height:50px}'
    . '.wrap{display:grid}'
    . '#site [data-flex-id] .wrap > [data-element-type]{width:100%;height:100%}'
    . '#site .cta{background:#c01d2e;color:#fff;border-radius:50px;display:flex;padding:10px 0}';
// Subject ids start with a digit, as captured builders emit them, so the
// subject cannot be addressed as `#id` and receives a marker class instead.
$html = '<style>' . $styles . '</style>'
    . '<main id="site"><div data-flex-id="row" class="row">'
    . '<div id="w1" class="wrap" data-widget-type="link"><a id="1507921607" class="cta" data-element-type="button" href="/contact.html">Get in touch</a></div>'
    . '<div id="w2" class="wrap" data-widget-type="link"><a id="1507921608" class="cta" data-element-type="button" href="/about.html">About us</a></div>'
    . '</div></main>';

$result = $transform($html);
$markup = $result['serialized_blocks'];
$stylesheet = $css($result);

$parentMarker = $markerOf($markup, 'w1');
$subjectMarker = $markerOf($markup, '1507921607');
$assert('' !== $parentMarker, 'the wrapper that parents a `>` attribute subject carries an attribute marker class');
$assert('' !== $subjectMarker, 'a subject whose id is not a safe anchor carries an attribute marker class');
$assert($parentMarker !== $subjectMarker, 'parent and subject receive different marker classes (' . $parentMarker . ' vs ' . $subjectMarker . ')');
$assert($markerOf($markup, 'w2') === $parentMarker, 'every parent of the same selector shares one parent marker');
$assert($markerOf($markup, '1507921608') === $subjectMarker, 'every subject of the same selector shares one subject marker');

$subjectRule = '/:where\(\.' . preg_quote($subjectMarker, '/') . '\)[^{,]*\{[^}]*width:100%/';
$assert('' !== $subjectMarker && 1 === preg_match($subjectRule, $stylesheet), 'the child declarations are emitted for the subject marker');
$parentAlone = '/:where\(\.' . preg_quote($parentMarker, '/') . '\)(?!\s*>)/';
$assert('' !== $parentMarker && 0 === preg_match($parentAlone, $stylesheet), 'the parent marker is only ever used as the left side of a `>` combinator, never as a subject');
$assert(1 === preg_match('/#w1\{width:160px;min-height:55px\}|#w1 *\{[^}]*width:160px/', $stylesheet), 'the wrapper keeps its own sizing rule');

if ($failures) {
    fwrite(STDERR, "attribute projection parent marker: " . count($failures) . " of {$assertions} assertions failed\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "attribute projection parent marker: {$assertions} passed\n";
