<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$fixture = require dirname(__DIR__) . '/fixtures/design-system/inherited-logical-buttons.php';
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$plan = (new ArtifactCompiler())->compile($fixture)->toArray()['source_reports']['wordpress_site_plan'];
$markup = '';
foreach ($plan['writes'] as $write) {
    if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
    else $markup .= $write['payload']['data'];
}
$button = $theme['styles']['elements']['button'] ?? array();
$assert('8px' === ($button['border']['radius'] ?? null), 'button element radius');
$assert('7.5px 15px' === ($button['spacing']['padding'] ?? null), 'logical variable-backed padding');
$assert('400' === ($button['typography']['fontWeight'] ?? null), 'button weight');
foreach (array('fontFamily' => 'font-family', 'fontSize' => 'font-size') as $key => $preset) $assert(str_starts_with($button['typography'][$key] ?? '', 'var:preset|' . $preset . '|'), $key . ' preset');
$families = array_column($theme['settings']['typography']['fontFamilies'], 'fontFamily', 'slug');
$sizes = array_column($theme['settings']['typography']['fontSizes'], 'size', 'slug');
$assert('Georgia,serif' === ($families[substr($button['typography']['fontFamily'], strlen('var:preset|font-family|'))] ?? null), 'computed inherited family');
$assert('15px' === ($sizes[substr($button['typography']['fontSize'], strlen('var:preset|font-size|'))] ?? null), 'button size');
$palette = array_column($theme['settings']['color']['palette'], 'color', 'slug');
foreach (array('text' => '#5b5b5b', 'background' => '#cacaca') as $key => $value) $assert($value === ($palette[substr($button['color'][$key] ?? '', strlen('var:preset|color|'))] ?? null), 'rest ' . $key);
$hover = $button[':hover']['color']['text'] ?? '';
$assert('oklch(from #646464 calc(l + .1) c h)' === $hover, 'opaque relative hover colour');
preg_match_all('/<!-- wp:button(?: (\{.*?\}))? -->/', $markup, $matches);
$assert(count($matches[1]) >= 1, 'native buttons emitted');
foreach ($matches[1] as $json) {
    $attrs = ('' === $json ? array() : json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    $assert(!isset($attrs['style']['color']) && !isset($attrs['style']['typography']) && !isset($attrs['style']['spacing']['padding']) && !isset($attrs['style']['border']['radius']), 'matching look is not inline');
    $assert(!isset($attrs['textColor']) && !isset($attrs['backgroundColor']) && !isset($attrs['fontSize']) && !isset($attrs['fontFamily']), 'no duplicate presets');
}
foreach (array('physical', 'focus', 'media', 'conditional-variable', 'unsupported-state', 'relative-size', 'original') as $case) {
    $input = $fixture;
    foreach ($input['files'] as &$html) {
        if ('physical' === $case) $html = str_replace('padding-block:7.5px;padding-inline:var(--edge)', 'padding-top:7.5px;padding-right:var(--edge);padding-bottom:7.5px;padding-left:var(--edge)', $html);
        if ('original' === $case) $html = str_replace(array('font-size:var(--jw-button-font-size)', 'color:#5b5b5b!important', 'oklch(from #646464 calc(l + .1) c h)'), array('font-size:16px;font-weight:700', 'color:#5b5b5b', '#000'), $html);
        if ('unsupported-state' === $case) $html = str_replace('oklch(from #646464 calc(l + .1) c h)', 'var(--unresolved-hover)', $html);
        if ('relative-size' === $case) $html = str_replace('calc(16px*.9375)', '.9375em', $html);
        if ('focus' === $case) $html = str_replace(':hover', ':focus', $html);
        if ('media' === $case) $html = str_replace('</style>', '@media(max-width:600px){.btn{padding-inline:20px;color:red}}</style>', $html);
        if ('conditional-variable' === $case) $html = str_replace('</style>', '@media(max-width:600px){.btn{--edge:20px}}</style>', $html);
    }
    unset($html);
    $variant = (new ArtifactCompiler())->compile($input)->toArray()['source_reports']['wordpress_site_plan'];
    foreach ($variant['writes'] as $write) {
        if ('theme.json' !== $write['target_path']) continue;
        $styles = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR)['styles']['elements']['button'] ?? array();
        if (in_array($case, array('media', 'conditional-variable'), true)) $assert(!isset($styles['spacing']['padding']), $case . ' disqualifies padding');
        else {
            $assert('7.5px 15px' === ($styles['spacing']['padding'] ?? null), $case . ' preserves the look');
            if ('relative-size' === $case) $assert(!isset($styles['typography']['fontSize']), 'relative size disqualifies only size');
            if ('original' === $case) $assert('700' === ($styles['typography']['fontWeight'] ?? null), $case . ' weight');
            if ('unsupported-state' === $case) $assert(!isset($styles[':hover']), 'unsupported hover drops only the state');
            if ('focus' === $case) $assert(isset($styles[':focus']['color']['text']), 'focus colour emitted');
        }
    }
}
$input = require dirname(__DIR__) . '/fixtures/design-system/per-field-button-looks.php';
$variant = (new ArtifactCompiler())->compile($input)->toArray()['source_reports']['wordpress_site_plan'];
$radii = array();
foreach ($variant['writes'] as $write) {
    if ('theme.json' === $write['target_path']) {
        $styles = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR)['styles']['elements']['button'] ?? array();
        $shared = $button;
        unset($shared['border']);
        $assert($shared === $styles, 'radius tie preserves every shared element field and no radius');
        continue;
    }
    preg_match_all('/<!-- wp:button(?: (\{.*?\}))? -->/', $write['payload']['data'], $matches);
    foreach ($matches[1] as $json) {
        $attrs = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $radius = $attrs['style']['border']['radius'] ?? null;
        $assert(in_array($radius, array('8px', '999px'), true), 'each button keeps its own radius');
        $radii[$radius] = true;
        $assert(array('border' => array('radius' => $radius)) === $attrs['style'], 'radius is the only inline style');
        foreach (array('textColor', 'backgroundColor', 'fontSize', 'fontFamily') as $key) $assert(!isset($attrs[$key]), 'shared preset is not repeated');
    }
}
$assert(2 === count($radii), 'both radius looks covered');
echo "Inherited logical button element contract passed\n";
