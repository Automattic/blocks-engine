<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// Browsers serialize colours authored in the sRGB `color()` space as
// `color(srgb 1 1 1)` (Wix sections), and paint nothing with a computed
// `rgba(255, 255, 255, 0)`. Neither is a design value to keep as a literal:
// the first is the palette's exact colour, the second the `transparent` keyword.
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main>'
        . '<section class="band" style="background-color:color(srgb 1 1 1)"><h2>Light</h2><p>Copy one</p></section>'
        . '<section class="band" style="background-color:rgba(255, 255, 255, 0)"><h2>Clear</h2><p>Copy two</p></section>'
        . '<p class="ink" style="color:color(srgb 0.411765 0.309804 0.137255)">Brown</p>'
        . '</main></body></html>',
    'site.css' => 'body{background:#ffffff;color:#694f23}.band{padding:24px}.ink{font-size:18px}',
)))->toArray()['source_reports']['wordpress_site_plan'];
$markup = $plan['pages'][0]['canonical_block_markup'];
$palette = array();
foreach ($plan['writes'] as $write) {
    if ('theme.json' !== $write['target_path']) continue;
    $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
    $palette = array_column($theme['settings']['color']['palette'], 'slug', 'color');
}
$white = $palette['#ffffff'] ?? null;
$brown = $palette['#694f23'] ?? null;
$assert(is_string($white) && is_string($brown), 'The source produces exact white and brown palette tokens.');

$block = static function (string $name, string $text) use ($markup, $assert): array {
    $assert(1 === preg_match('/<!-- wp:' . $name . ' (\{(?:(?!-->).)*\}) -->(<[a-z0-9]+[^>]*>)(?:(?!<!-- wp:' . $name . ' ).)*?' . $text . '/s', $markup, $match), $text . ' remains a native ' . $name . '.');
    return array(json_decode($match[1], true, 512, JSON_THROW_ON_ERROR), $match[2]);
};

[$light, $lightTag] = $block('group', 'Light');
$assert($white === ($light['backgroundColor'] ?? null), 'A `color(srgb …)` background binds to its exact palette token.');
$assert(!isset($light['style']['color']['background']) && !str_contains($lightTag, 'color(srgb') && str_contains($lightTag, 'has-' . $white . '-background-color'), 'The preset class replaces the inline `color(srgb …)` declaration.');

[$ink, $inkTag] = $block('paragraph', 'Brown');
$assert($brown === ($ink['textColor'] ?? null) && !str_contains($inkTag, 'color(srgb'), 'A `color(srgb …)` text colour binds to its exact palette token.');

[$clear, $clearTag] = $block('group', 'Clear');
$assert('transparent' === ($clear['style']['color']['background'] ?? null), 'A zero-alpha background is spelled as the `transparent` keyword.');
$assert(str_contains($clearTag, 'background-color:transparent') && !str_contains($clearTag, 'rgba(255, 255, 255, 0)') && !isset($clear['backgroundColor']), 'The inline declaration keeps its place, painting nothing, with no preset.');

echo "ok\n";
