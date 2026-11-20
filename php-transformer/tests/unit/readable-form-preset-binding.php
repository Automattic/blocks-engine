<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A form provider renders a form fallback's readable blocks in place of the
// page's form. Text in that tree whose colour an author class paints must own
// it natively, as the same paragraph does in page markup: otherwise the class
// rule silently overrides the colour control of every enclosing block (#7227).
$html = '<html><head><link rel="stylesheet" href="s.css"></head><body><main><section>'
    . '<p class="text-sm text-foreground/85">Book your catering</p>'
    . '<form method="post" class="booking"><div class="field"><label class="lbl" for="name">Name</label><input id="name" name="name" class="control"></div>'
    . '<div class="field"><label class="lbl" for="service">Service</label><div class="rounded-[10px] border border-input bg-secondary/50 px-3 py-2 text-sm font-medium text-foreground/85">School Catering</div></div>'
    . '<button type="submit" class="submit">Send</button></form>'
    . '</section></main></body></html>';
// The colour reads a custom property, as utility CSS does: only the site
// plan's preset binding resolves it to a native colour.
$css = ':root{--foreground:150 30% 12%;--input:36 15% 88%;--secondary:36 20% 92%}body{color:hsl(var(--foreground))}.lbl{font-weight:500}'
    . '.rounded-\[10px\]{border-radius:10px}.border{border-width:1px}.border-input{border-color:hsl(var(--input))}.bg-secondary\/50{background-color:hsl(var(--secondary) / .5)}'
    . '.px-3{padding-left:.75rem;padding-right:.75rem}.py-2{padding-top:.5rem;padding-bottom:.5rem}.text-sm{font-size:.875rem;line-height:1.25rem}.font-medium{font-weight:500}'
    . '.text-foreground\/85{color:hsl(var(--foreground) / .85)}'
    . '.control{border:1px solid #d8d2c4;padding:.25rem .75rem}.submit{background:#1c362b;color:#faf8f5;padding:.5rem 1rem}';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => $html),
    array('path' => 's.css', 'content' => $css),
)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? null;
if (!is_array($plan)) {
    throw new RuntimeException('Missing WordPress site plan: ' . json_encode($result['diagnostics'] ?? array()));
}

$find = static function (array $blocks, callable $match) use (&$find): ?array {
    foreach ($blocks as $block) {
        if (!is_array($block)) continue;
        if ($match($block)) return $block;
        $inner = $find($block['innerBlocks'] ?? array(), $match);
        if (null !== $inner) return $inner;
    }
    return null;
};
$readable = array();
foreach ($plan['quality']['fallbacks'] ?? array() as $fallback) {
    if (is_array($fallback['readable_blocks'] ?? null)) $readable = array_merge($readable, $fallback['readable_blocks']);
}
if (array() === $readable) {
    throw new RuntimeException('The form fallback carries no readable blocks: ' . json_encode($plan['quality']['fallbacks'] ?? array()));
}

$chip = $find($readable, static fn (array $block): bool => 'core/paragraph' === ($block['blockName'] ?? null) && str_contains((string) ($block['innerHTML'] ?? ''), 'School Catering'));
if (null === $chip) {
    throw new RuntimeException('Missing readable paragraph: ' . json_encode($readable));
}
$owned = isset($chip['attrs']['textColor']) || isset($chip['attrs']['style']['color']['text']);
if (!$owned || !str_contains((string) $chip['innerHTML'], 'has-text-color')) {
    throw new RuntimeException('A readable form paragraph must own its class colour natively: ' . json_encode($chip));
}
if ($chip['innerContent'][0] !== $chip['innerHTML']) {
    throw new RuntimeException('A bound readable paragraph keeps its innerContent in step with its innerHTML: ' . json_encode($chip));
}

// Only colours bind in the readable tree: the form provider keeps the
// source's typography and spacing as compiled (its presentation contract).
if (isset($chip['attrs']['fontSize']) || isset($chip['attrs']['style']['spacing']) || isset($chip['attrs']['style']['typography'])) {
    throw new RuntimeException('A readable form block binds only its colours: ' . json_encode($chip['attrs']));
}

// The page's own copy of the same text binds the same way.
$page = implode('', array_column($plan['pages'], 'canonical_block_markup'));
if (!preg_match('/<!-- wp:paragraph (\{[^}]*\}) -->\s*<p\b[^>]*>Book your catering<\/p>/', $page, $match) || !str_contains($match[1], 'textColor')) {
    throw new RuntimeException('Page paragraph must own its class colour: ' . $page);
}

// A form control's subtree is rebuilt by the provider from its own facts, so
// binding leaves it as compiled.
$submit = $find($readable, static fn (array $block): bool => 'core/button' === ($block['blockName'] ?? null));
if (null !== $submit && isset($submit['attrs']['textColor'])) {
    throw new RuntimeException('A form control keeps its compiled attributes: ' . json_encode($submit));
}

echo "PASS: readable form blocks bind their class presentation like page markup\n";
