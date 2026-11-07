<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// A page builder pattern: a phone hero visible at rest, a desktop hero shown
// only by a media rule, and a call-to-action whose inline colour and font
// equal design-system tokens while a hover rule restyles its background. Its
// resting class paints the same background; the second link has no resting
// rule for its background.
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"><style>#tagline-phone{font-size:40px !important}#tagline-desktop{font-size:56px !important}</style></head><body><div class="x"><main>'
        . '<section class="c-phone"><h1 id="tagline-phone" class="c-tagline">Phone tagline</h1></section>'
        . '<section class="c-desktop"><h1 id="tagline-desktop" class="c-tagline">Desktop tagline</h1></section>'
        . '<p class="c-copy">Body copy that sets the text scale for the page.</p><p class="c-copy">More body copy.</p>'
        . '<a class="c-cta c-primary c-primary-hover" href="https://example.test/" style="background-color:rgb(120, 97, 97);color:rgb(255, 255, 255);font-family:Georgia, serif">Join</a>'
        . '<a class="c-cta" href="https://example.test/more" style="background-color:rgb(255, 255, 255);color:rgb(34, 34, 34)">More</a>'
        . '<p class="c-note">An accent note.</p>'
        . '</main></div></body></html>',
    'site.css' => 'body{font-family:Inter, sans-serif;color:#222;background:#fff}.x .c-copy{font-size:16px}.x .c-tagline{font-size:32px;font-family:Georgia, serif}@media (min-width:1024px){.x .c-tagline{font-size:48px}}'
        . '.x .c-desktop{display:none}@media (min-width:1024px){.x .c-phone{display:none}.x .c-desktop{display:block}}'
        . '.x .c-cta{display:inline-block;padding:8px 32px}.x .c-primary{background-color:rgb(120, 97, 97)}.x .c-primary-hover:hover{background-color:rgb(149, 121, 121)}'
        . '.x .c-note{background:#786161;color:#fff;padding:8px}',
)))->toArray()['source_reports']['wordpress_site_plan'];

$theme = array();
foreach ($plan['writes'] as $write) {
    if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
}
$sizes = array_column($theme['settings']['typography']['fontSizes'] ?? array(), 'slug', 'size');
$colors = array_column($theme['settings']['color']['palette'] ?? array(), 'slug', 'color');
$families = array_column($theme['settings']['typography']['fontFamilies'] ?? array(), 'slug', 'fontFamily');
$markup = $plan['pages'][0]['canonical_block_markup'];
$css = implode("\n", array_map(static fn(array $asset): string => is_string($asset['content'] ?? null) ? $asset['content'] : '', $plan['assets']));

// The desktop heading renders at the reference viewport, so its size is a
// preset and its stylesheet declaration reads that preset.
$assert(isset($sizes['40px']), 'The resting heading size has a preset.');
$assert(isset($sizes['56px']), 'A heading shown only at the desktop reference viewport still gets a size preset.');
$assert(str_contains($css, 'var(--wp--preset--font-size--' . $sizes['56px'] . ',56px)'), 'The desktop heading size reads its preset variable.');

// The CTA keeps its inline declarations (a preset class is !important and
// would change the cascade), but reads the colour and font tokens.
$primary = $colors['#786161'] ?? null;
$assert(is_string($primary), 'The CTA background is a palette colour.');
$assert(2 === preg_match_all('/<!-- wp:button (\{.*?\}) -->(.*?)<!-- \/wp:button -->/s', $markup, $buttons, PREG_SET_ORDER), 'The CTAs are native buttons.');
$button = $buttons[0];
$attributes = json_decode($button[1], true, 512, JSON_THROW_ON_ERROR);
$assert('var:preset|color|' . $primary === ($attributes['style']['color']['background'] ?? null), 'A hover-restyled inline background binds to an inline preset variable.');
$assert(str_contains($button[2], 'background-color:var(--wp--preset--color--' . $primary . ')') && !str_contains($button[2], 'rgb(120, 97, 97)'), 'The saved link reads the background preset inline.');
$assert(!str_contains($button[2], 'has-' . $primary . '-background-color'), 'No !important preset class masks the hover rule.');
$family = $families['Georgia, serif'] ?? null;
$assert(is_string($family), 'The body font is a font-family preset.');
$assert('var:preset|font-family|' . $family === ($attributes['style']['typography']['fontFamily'] ?? null) && str_contains($button[2], 'font-family:var(--wp--preset--font-family--' . $family . ')'), 'A button font family equal to a preset stack reads the preset inline.');
// Without a resting rule that paints the same colour, a state-guarded inline
// literal stays literal and remains a reported refusal.
$assert(str_contains($buttons[1][2], 'background-color:rgb(255, 255, 255)'), 'A state-guarded literal without a matching resting rule stays literal.');
$assert(1 === $plan['theme']['design_system']['metrics']['refusal_reasons']['state_variant'], 'Only the literal without a matching resting rule is a state_variant refusal.');

echo "Restyle state and reference heading presets contract passed.\n";
