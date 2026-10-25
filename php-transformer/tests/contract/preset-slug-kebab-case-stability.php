<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// The editor re-derives preset classes with kebabCase(), which also splits
// letters from digits. A slug is stable under it exactly when every dash
// segment is all lowercase letters or all digits ("heading-28px" re-renders
// as has-heading-28-px-font-size, so the block is invalid content).
$stable = static fn(string $slug): bool => 1 === preg_match('/^(?:[a-z]+|[0-9]+)(?:-(?:[a-z]+|[0-9]+))*$/', $slug);

// A page reduced from #3409: a timetable whose heading renders at 28px while
// nine heavier text sizes claim the eight-step scale, so the heading size
// falls off the scale and becomes a preset of its own. Blocks bind it both
// directly (the heading) and class-driven (the timetable slot group), the way
// the twelve invalid core/group blocks were bound in the original report.
$rows = '';
foreach (array(16, 17, 18, 19, 20, 21, 22, 23, 24) as $index => $px) {
    $rows .= '<p class="step-' . $px . '">Day ' . $index . ' sessions run in halls across the campus and the studios all week long.</p>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="timetable.css"></head><body><main>'
        . '<h1 class="display">Timetable</h1>' . $rows
        . '<div class="slot"><p>9:00</p></div>'
        . '</main></body></html>',
    'timetable.css' => 'body{font-family:Georgia,serif;font-size:16px}'
        . '.display{font-size:28px;font-family:"Space Grotesk",Inter,sans-serif}'
        . '.slot{font-size:28px;font-family:"Space Grotesk",Inter,sans-serif}'
        . '.step-16{font-size:16px}.step-17{font-size:17px}.step-18{font-size:18px}.step-19{font-size:19px}'
        . '.step-20{font-size:20px}.step-21{font-size:21px}.step-22{font-size:22px}.step-23{font-size:23px}'
        . '.step-24{font-size:24px}',
)))->toArray()['source_reports']['wordpress_site_plan'];

$theme = null;
foreach ($plan['writes'] as $write) {
    if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
}
$assert(is_array($theme), 'The source produces a theme.json write.');

// Every generated preset slug must be its own kebab-case form, so the classes
// the PHP markup saves and the editor re-renders agree.
$slugs = array();
foreach (array(array('color', 'palette'), array('typography', 'fontSizes'), array('typography', 'fontFamilies'), array('spacing', 'spacingSizes')) as [$section, $group]) {
    foreach ($theme['settings'][$section][$group] ?? array() as $preset) $slugs[] = $preset['slug'];
}
$assert(array() !== $slugs, 'The theme declares presets.');
foreach ($slugs as $slug) $assert($stable($slug), "Preset slug '" . $slug . "' is not stable under the editor's kebabCase.");

$sizes = array_column($theme['settings']['typography']['fontSizes'], 'slug', 'size');
$assert('heading-28' === ($sizes['28px'] ?? null), 'The off-scale heading size becomes the unit-less preset heading-28 (' . json_encode($sizes['28px'] ?? null) . ')');
$assert(!in_array('heading-28px', $sizes, true), 'No preset slug keeps the pixel unit.');
$heading = array_values(array_filter($theme['settings']['typography']['fontSizes'], static fn(array $preset): bool => 'heading-28' === $preset['slug']));
$assert(1 === preg_match('/^Heading /', (string) ($heading[0]['name'] ?? '')), 'The preset display name still names the heading scale.');

// Editor validation: a block re-renders its preset classes from its
// attributes, so the saved classes must be exactly the kebab-case derivation
// of the bound slugs — one mismatch and the block is invalid content.
$markup = $plan['pages'][0]['canonical_block_markup'];
$bound = 0;
if (!preg_match_all('/<!-- wp:[a-z0-9\-\/]+ (\{.*?\}) -->\s*<([a-z0-9]+)([^>]*)>/', $markup, $blocks, PREG_SET_ORDER)) {
    throw new RuntimeException('The page serializes attributed blocks.');
}
foreach ($blocks as $block) {
    $attributes = json_decode($block[1], true, 512, JSON_THROW_ON_ERROR);
    $classes = 1 === preg_match('/class="([^"]*)"/', $block[3], $match) ? explode(' ', $match[1]) : array();
    $derived = array();
    foreach (array('fontSize' => 'font-size', 'fontFamily' => 'font-family') as $attribute => $kind) {
        if (!isset($attributes[$attribute])) continue;
        $assert($stable((string) $attributes[$attribute]), 'Bound preset slug is kebab-stable: ' . $attributes[$attribute]);
        $class = 'has-' . $attributes[$attribute] . '-' . $kind;
        $assert(in_array($class, $classes, true), $block[2] . ' saves the editor-derived class ' . $class . ' for ' . $attribute . ' ' . $attributes[$attribute]);
        $derived[] = $class;
        $bound++;
    }
    foreach ($classes as $class) {
        if (1 !== preg_match('/^has-[a-z0-9\-]+-(font-size|font-family)$/', $class)) continue;
        $assert(in_array($class, $derived, true), $block[2] . ' carries no preset class the editor would not re-render: ' . $class);
    }
}
$assert($bound >= 3, 'The page binds heading size and family presets onto blocks (' . $bound . ' classes).');
$assert(str_contains($markup, 'has-heading-28-font-size') && str_contains($markup, 'has-heading-font-family'), 'The heading and the timetable slot carry the heading presets.');

echo "Preset slug kebab-case stability contract passed.\n";
