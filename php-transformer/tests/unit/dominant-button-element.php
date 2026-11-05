<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$fixture = require dirname(__DIR__) . '/fixtures/design-system/buttons.php';
$compile = static function (array $input): array {
    return (new ArtifactCompiler())->compile($input)->toArray()['source_reports']['wordpress_site_plan'];
};
$plan = $compile($fixture);
$theme = array();
$markup = '';
foreach ($plan['writes'] as $write) {
    if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
    else $markup .= $write['payload']['data'];
}
$button = $theme['styles']['elements']['button'] ?? array();
$assert('9px' === ($button['border']['radius'] ?? null), 'dominant button radius');
$spacing = array_column($theme['settings']['spacing']['spacingSizes'], 'size', 'slug');
$padding = $button['spacing']['padding'] ?? array();
foreach (array('top' => '13px', 'right' => '22px', 'bottom' => '13px', 'left' => '22px') as $side => $value) {
    $reference = $padding[$side] ?? '';
    $assert($value === ($spacing[substr($reference, strlen('var:preset|spacing|'))] ?? $reference), 'dominant button padding ' . $side);
}
$type = $button['typography'] ?? array();
foreach (array('fontWeight' => '600', 'textTransform' => 'uppercase', 'letterSpacing' => '.07em') as $key => $value) $assert($value === ($type[$key] ?? null), $key . ' belongs to the element');
foreach (array('fontSize' => 'font-size', 'fontFamily' => 'font-family') as $key => $preset) $assert(str_starts_with($type[$key] ?? '', 'var:preset|' . $preset . '|'), $key . ' uses a preset');
$assert('var:preset|color|primary' === ($button['color']['background'] ?? null), 'dominant fill uses primary');
$palette = array_column($theme['settings']['color']['palette'], 'color', 'slug');
$assert('#ffffff' === ($palette[substr($button['color']['text'], strlen('var:preset|color|'))] ?? null), 'white text uses the palette');
$sizes = array_column($theme['settings']['typography']['fontSizes'], 'size', 'slug');
$assert('14.72px' === ($sizes[substr($type['fontSize'], strlen('var:preset|font-size|'))] ?? null), 'button size belongs to the type scale');
preg_match_all('/<!-- wp:button (\{.*?\}) -->/', $markup, $matches);
$assert(count($matches[1]) >= 6, 'public multi-route compiler emits native buttons');
$outline = $small = $primary = 0;
foreach ($matches[1] as $json) {
    $attributes = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $style = $attributes['style'] ?? array();
    $assert(!isset($style['border']['radius']), 'matching radius is not per-block');
    foreach (array('fontFamily', 'fontWeight', 'textTransform', 'letterSpacing') as $key) $assert(!isset($style['typography'][$key]), 'matching ' . $key . ' is not per-block');
    if (isset($style['typography']['fontSize'])) {
        ++$small;
        $assert('.84rem' === $style['typography']['fontSize'], 'small CTA keeps only its actual size');
        $assert(isset($style['spacing']['padding']), 'small CTA retains its different padding');
    } else {
        $assert(!isset($style['spacing']['padding']), 'matching padding is not per-block');
        $assert(!isset($attributes['fontSize']), 'matching size has no overriding preset class');
        if (str_contains($attributes['className'] ?? '', 'is-style-outline')) ++$outline;
        else {
            ++$primary;
            $assert(!isset($attributes['backgroundColor']) && !isset($style['color']['background']), 'primary fill follows the element');
        }
    }
}
$assert($outline > 0 && $small > 0 && $primary > 0, 'all three variants covered');
$reversed = $fixture;
$reversed['files'] = array_reverse($fixture['files'], true);
foreach ($compile($reversed)['writes'] as $write) {
    if ('theme.json' === $write['target_path']) $assert($theme === json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR), 'route insertion order does not change the design system');
}
$input = $fixture;
foreach ($input['files'] as &$html) $html = str_replace('</body>', '<button class="close" aria-label="Close" style="font-size:1.2rem;border-radius:50%">✕</button></body>', $html);
unset($html);
$variationMarkup = '';
$registered = false;
foreach ($compile($input)['writes'] as $write) {
    if ('functions.php' === $write['target_path']) $registered = str_contains($write['payload']['data'], "register_block_style( 'core/button'");
    if ('theme.json' === $write['target_path']) $variationTheme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
    else $variationMarkup .= $write['payload']['data'];
}
$assert(!isset($variationTheme['styles']['elements']['button']['spacing']['padding']), 'icon control prevents element look');
$variations = $variationTheme['styles']['blocks']['core/button']['variations'] ?? array();
$assert(array() === $variations, 'partial ownership does not manufacture a complete variation');
preg_match_all('/<!-- wp:button (\{.*?\}) -->/', $variationMarkup, $variantMatches);
$styled = $close = 0;
foreach ($variantMatches[1] as $json) {
    $attrs = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if ('50%' === ($attrs['style']['border']['radius'] ?? null)) {
        ++$close;
        $assert('1.2rem' === ($attrs['style']['typography']['fontSize'] ?? null), 'close keeps its own size');
    } else {
        ++$styled;
        $assert(isset($attrs['style']['spacing']['padding']), 'unowned padding remains local');
    }
}
$assert($styled > 0 && $close > 0, 'styled buttons and icon covered');
// Tied and viewport-dependent fields stay local; shared fields still vote.
foreach (array('tie', 'responsive', 'unstyled') as $case) {
    $input = $fixture;
    foreach ($input['files'] as &$html) {
        if ('tie' === $case) $html = str_replace(array('btn-primary hero-cta', '.btn-ghost{border:1px solid #fff;color:#fff}'), array('btn-ghost', '.btn-ghost{border:1px solid #fff;color:#fff;background:transparent}'), $html);
        if ('responsive' === $case) $html = str_replace('</style>', '@media(max-width:600px){.btn{font-size:1.1rem}}</style>', $html);
        if ('unstyled' === $case) $html = str_replace('</body>', '<button>Plain</button></body>', $html);
    }
    unset($html);
    foreach ($compile($input)['writes'] as $write) {
        if ('theme.json' !== $write['target_path']) continue;
        $negative = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
        if ('unstyled' === $case) $assert(!isset($negative['styles']['elements']['button']['spacing']['padding']), 'unstyled: no unproven dominant look');
        else {
            $scale = array_column($negative['settings']['spacing']['spacingSizes'], 'size', 'slug');
            $actual = $negative['styles']['elements']['button']['spacing']['padding'] ?? array();
            foreach (array('top' => '13px', 'right' => '22px', 'bottom' => '13px', 'left' => '22px') as $side => $value) {
                $reference = $actual[$side] ?? '';
                $assert($value === ($scale[substr($reference, strlen('var:preset|spacing|'))] ?? $reference), $case . ': shared padding survives');
            }
        }
        if ('responsive' === $case) $assert(!isset($negative['styles']['elements']['button']['typography']['fontSize']), 'responsive size stays local');
        $assert(!isset($negative['styles']['blocks']['core/button']['variations']), $case . ': no variation');
    }
}
echo "Dominant button element regression passed\n";
