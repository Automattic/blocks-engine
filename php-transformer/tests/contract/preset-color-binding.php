<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssColor;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DesignSystem;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// Issue #6551: buttons whose inline colours equal palette presets, guarded
// by hover rules without a resting rule that restates the colour, beside
// coloured groups. Every colour attribute equal to a preset is stored as
// that preset, buttons included.
$compile = static function (string $css): array {
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
        'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main>'
            . '<section class="band" style="background:#1a3c34;color:#f3efe8"><h2>Collection</h2><p>Hand-picked pieces.</p>'
            . '<a class="enquire" href="/enquire" style="display:inline-block;padding:12px 24px;background:#1a3c34;color:#f3efe8">Enquire about this collection</a>'
            . '<a class="whatsapp" href="/whatsapp" style="display:inline-block;padding:12px 24px;background:#25d366;color:#fff">WhatsApp Business ↗</a>'
            . '</section><div class="card" style="background:#ffffff;color:#1a3c34"><p>Card copy.</p></div></main></body></html>',
        'site.css' => $css,
    )))->toArray()['source_reports']['wordpress_site_plan'];
};
$plan = $compile('.enquire:hover{background:#000}.whatsapp:hover{color:#000}');
$markup = $plan['pages'][0]['canonical_block_markup'];
$metrics = $plan['theme']['design_system']['metrics'];
$palette = array();
foreach ($plan['writes'] as $write) {
    if ('theme.json' !== $write['target_path']) continue;
    $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
    $palette = array_column($theme['settings']['color']['palette'], 'color', 'slug');
}

$assert(array_key_exists('literal_preset_color_count', $metrics), 'The site plan reports literal_preset_color_count.');
$assert(0 === $metrics['literal_preset_color_count'], 'No colour attribute stores a literal equal to a preset (' . json_encode($metrics['literal_preset_color_count']) . ').');
$assert(0 === $metrics['refusal_reasons']['state_variant'], 'Hover-guarded button colours equal to presets are not state_variant refusals.');

$assert(2 === preg_match_all('/<!-- wp:button (\{.*?\}) -->.*?<a ([^>]*)>(.*?)<\/a>.*?<!-- \/wp:button -->/s', $markup, $buttons, PREG_SET_ORDER), 'Both links are native buttons.');
$expected = array('Enquire about this collection' => array('text' => '#f3efe8', 'background' => '#1a3c34'), 'WhatsApp Business ↗' => array('text' => '#fff', 'background' => '#25d366'));
foreach ($buttons as [, $json, $tag, $label]) {
    $attributes = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    foreach ($expected[$label] as $key => $source) {
        $value = $attributes['style']['color'][$key] ?? null;
        $assert(is_string($value) && 1 === preg_match('/^var:preset\|color\|([a-z0-9-]+)$/', $value, $slug), $label . ' stores its ' . $key . ' colour as a preset (' . json_encode($value) . ').');
        // Rendering is unchanged: the inline declaration keeps its place
        // (no !important preset class over the hover rule) and its preset
        // is exactly the source colour.
        $assert(CssColor::hex($palette[$slug[1]] ?? '') === CssColor::hex($source), $label . ' ' . $key . ' preset is the source colour.');
        $property = 'text' === $key ? 'color' : 'background-color';
        $assert(str_contains($tag, $property . ':var(--wp--preset--color--' . $slug[1] . ')') && !str_contains($tag, 'has-' . $slug[1] . '-'), $label . ' keeps ' . $property . ' inline, reading the preset.');
    }
}
preg_match_all('/<!-- wp:group (\{.*?\}) -->/', $markup, $groups);
$colored = array_values(array_filter(array_map(static fn(string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $groups[1]), static fn(array $attributes): bool => isset($attributes['textColor']) || isset($attributes['style']['color'])));
$assert(2 === count($colored) && array() === array_filter($colored, static fn(array $attributes): bool => isset($attributes['style']['color']) || !isset($attributes['backgroundColor'])), 'The coloured groups use preset attributes.');

// The counter counts every colour attribute that stores a literal equal to
// a preset, whatever its spelling, and nothing else.
$system = DesignSystem::fromUsage(array('index.html' => array(
    'schema' => DesignSystem::USAGE_SCHEMA,
    'text' => array(array('role' => 'body', 'color' => '#ffffff', 'font_size_px' => 16, 'count' => 1, 'weight' => 20)),
)));
$assert(null !== $system && null !== ($white = $system->colorSlug('#ffffff')), 'The usage yields a white preset.');
$literal = '<!-- wp:button {"style":{"color":{"text":"#fff","background":"#123456"},"border":{"color":"#FFFFFF","top":{"color":"rgb(255, 255, 255)"}}}} /-->'
    . '<!-- wp:group {"style":{"color":{"background":"var:preset|color|' . $white . '"},"elements":{"link":{"color":{"text":"#ffffff"}}}}} /-->';
$assert(4 === $system->metrics(array($literal), array())['literal_preset_color_count'], 'Text, border, border-side and link literals equal to a preset are counted; presets and unmatched colours are not.');

echo "Preset colour binding contract passed.\n";
