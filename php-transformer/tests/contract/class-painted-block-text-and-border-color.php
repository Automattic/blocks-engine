<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/*
 * A group whose own author class paints its text or border colour renders
 * that colour through the class stylesheet. When the colour is a near shade
 * of a palette colour (`#111` beside `#171717`) it has no exact preset, so a
 * literal block style repeating it sits outside the editable design system
 * and masks the class's hover rule. The block must not repeat the literal:
 * its border colour stays with its class, as a native button's does, and
 * its text colour may name only the shade's derived token, whose value
 * restates the shade from its representative preset.
 */
$para = '<p>Zach Richards writes about civil liberties, free expression and privacy in the workplace and beyond, every single week, for readers who care about them deeply.</p>';
$html = '<!doctype html><html><head><style>
body{font-family:Georgia,serif;color:#171717;background:#ede8e3}
.writing{color:#111;background:#ede8e3;padding:160px 0}
.hero-title{color:#111;letter-spacing:-.03em;margin:24px 0 32px;font-size:48px;font-weight:700;line-height:1.05}
.writing-card{color:#111;cursor:pointer;background:#fff;border:1px solid #111;flex-direction:column;text-decoration:none;display:flex}
.writing-card:hover{border-color:#171717}
</style></head><body><main>
<section class="writing"><h1 class="hero-title">New every week.</h1>
<article class="writing-card"><h3>Rights aren\'t given</h3><p>They\'re defended, one argument at a time.</p></article>
<article class="writing-card"><h3>What I got wrong</h3><p>On outrage, and what replaces it.</p></article>
</section>
<section class="about"><h2>About</h2>' . str_repeat($para, 8) . '</section>
</main></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['source_reports']['wordpress_site_plan'];
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$palette = array_map('strtolower', array_column($plan['theme']['design_system']['palette'], 'color'));
$assert(!in_array('#111', $palette, true) && !in_array('#111111', $palette, true), 'The class colour must be a near shade without a preset of its own: ' . implode(',', $palette));
$assert(in_array("#171717", $palette, true), "The page text colour is a preset: " . implode(",", $palette));
$markup = implode('', array_column($plan['pages'], 'canonical_block_markup'));
$seen = array('writing' => 0, 'writing-card' => 0);
preg_match_all('/<!-- wp:group (\{(?:(?!-->).)*\}) -->\s*(<[a-z0-9]+\b[^>]*>)/s', $markup, $matches, PREG_SET_ORDER);
foreach ($matches as [, $json, $tag]) {
    $attributes = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $class = array_values(array_intersect(explode(' ', (string) ($attributes['className'] ?? '')), array_keys($seen)))[0] ?? null;
    if (null === $class) continue;
    ++$seen[$class];
    $assert(!isset($attributes['style']['color']['text']) && !isset($attributes['style']['border']['color']), "The .$class group must leave its text and border colour to its author class: $json");
    $assert(!str_contains($tag, '#111') && !str_contains($tag, 'has-border-color'), "The saved .$class group must not repeat the class colour: $tag");
    $assert(!isset($attributes['textColor']) || 1 === preg_match('/^contrast-shade-[a-p]{6}$/', $attributes['textColor']), "The .$class group's text names only the derived near-shade token: $json");
    $assert(!isset($attributes['style']['border']) || 'solid' === ($attributes['style']['border']['style'] ?? null), "The .$class group keeps its own border style: $json");
}
$assert(array(1, 2) === array_values($seen), 'The page must carry the class-painted groups: ' . $markup);
$css = implode("\n", array_column(array_filter($plan['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$assert(1 === preg_match('/\.writing\b[^{,]*\{[^}]*color:#111/', $css) && 1 === preg_match('/\.writing-card\b[^{,]*\{[^}]*#111/', $css), 'The author classes must still paint the colour.');

echo "class-painted block text and border colour: ok\n";
