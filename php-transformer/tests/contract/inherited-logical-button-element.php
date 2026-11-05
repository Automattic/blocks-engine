<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$fixture = require dirname(__DIR__) . '/fixtures/design-system/inherited-logical-buttons.php';
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$paddingValues = static function (array $styles, array $theme): array {
    $padding = $styles['spacing']['padding'] ?? array();
    if (is_string($padding)) {
        $parts = preg_split('/\s+/', $padding);
        return array($parts[0], $parts[1] ?? $parts[0], $parts[2] ?? $parts[0], $parts[3] ?? $parts[1] ?? $parts[0]);
    }
    $scale = array_column($theme['settings']['spacing']['spacingSizes'] ?? array(), 'size', 'slug');
    return array_values(array_map(static fn(string $value): string => $scale[substr($value, strlen('var:preset|spacing|'))] ?? $value, $padding));
};
$plan = (new ArtifactCompiler())->compile($fixture)->toArray()['source_reports']['wordpress_site_plan'];
$markup = '';
foreach ($plan['writes'] as $write) {
    if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
    else $markup .= $write['payload']['data'];
}
$button = $theme['styles']['elements']['button'] ?? array();
$assert('8px' === ($button['border']['radius'] ?? null), 'button element radius');
$assert(array('7.5px', '15px', '7.5px', '15px') === $paddingValues($button, $theme), 'logical variable-backed padding');
$assert('400' === ($button['typography']['fontWeight'] ?? null), 'button weight');
foreach (array('fontFamily' => 'font-family', 'fontSize' => 'font-size') as $key => $preset) $assert(str_starts_with($button['typography'][$key] ?? '', 'var:preset|' . $preset . '|'), $key . ' preset');
$families = array_column($theme['settings']['typography']['fontFamilies'], 'fontFamily', 'slug');
$sizes = array_column($theme['settings']['typography']['fontSizes'], 'size', 'slug');
$assert('Georgia,serif' === ($families[substr($button['typography']['fontFamily'], strlen('var:preset|font-family|'))] ?? null), 'computed inherited family');
$assert('15px' === ($sizes[substr($button['typography']['fontSize'], strlen('var:preset|font-size|'))] ?? null), 'button size');
$palette = array_column($theme['settings']['color']['palette'], 'color', 'slug');
foreach (array('text' => '#5b5b5b', 'background' => '#cacaca') as $key => $value) $assert($value === ($palette[substr($button['color'][$key] ?? '', strlen('var:preset|color|'))] ?? null), 'rest ' . $key);
$hover = $button[':hover']['color']['text'] ?? '';
$assert('' === $hover, 'important resting text defeats normal hover colour');
$assert('oklch(from #cacaca calc(l - .04) c h)' === ($button[':hover']['color']['background'] ?? null), 'state-local relative background captured');
preg_match_all('/<!-- wp:button(?: (\{.*?\}))? -->/', $markup, $matches);
$assert(count($matches[1]) >= 1, 'native buttons emitted');
foreach ($matches[1] as $json) {
    $attrs = ('' === $json ? array() : json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    $assert(!isset($attrs['style']['color']) && !isset($attrs['style']['typography']) && !isset($attrs['style']['spacing']['padding']) && !isset($attrs['style']['border']['radius']), 'matching look is not inline');
    $assert(!isset($attrs['textColor']) && !isset($attrs['backgroundColor']) && !isset($attrs['fontSize']) && !isset($attrs['fontFamily']), 'no duplicate presets');
}
foreach (array('physical', 'focus', 'media', 'conditional-variable', 'unsupported-state', 'relative-size', 'original', 'state-token') as $case) {
    $input = $fixture;
    foreach ($input['files'] as &$html) {
        if ('state-token' === $case) $html = str_replace(array('background-color:#cacaca', '--hover-bg:oklch(from #cacaca calc(l - .04) c h);background-color:var(--hover-bg)'), array('--bg:#cacaca;background-color:var(--bg)', '--bg:oklch(from #cacaca calc(l - .04) c h)'), $html);
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
        $variantTheme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
        $styles = $variantTheme['styles']['elements']['button'] ?? array();
        if (in_array($case, array('media', 'conditional-variable'), true)) $assert(!isset($styles['spacing']['padding']), $case . ' disqualifies padding');
        else {
            $assert(array('7.5px', '15px', '7.5px', '15px') === $paddingValues($styles, $variantTheme), $case . ' preserves the look');
            if ('relative-size' === $case) $assert(!isset($styles['typography']['fontSize']), 'relative size disqualifies only size');
            if ('original' === $case) $assert('700' === ($styles['typography']['fontWeight'] ?? null), $case . ' weight');
            if ('unsupported-state' === $case) $assert(!isset($styles[':hover']['color']['text']), 'unsupported hover drops only the state');
            if ('focus' === $case) $assert(isset($styles[':focus']['color']['background']) && !isset($styles[':focus']['color']['text']), 'focus background emitted, dead text omitted');
            if ('original' === $case) $assert(isset($styles[':hover']['color']['text']), 'non-important resting colour permits hover');
            if ('state-token' === $case) $assert('oklch(from #cacaca calc(l - .04) c h)' === ($styles[':hover']['color']['background'] ?? null), 'state-only token change captured');
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
// A minority size/padding must not veto the strict per-field majority, and
// minority blocks must retain their differing values.
$input = require dirname(__DIR__) . '/fixtures/design-system/per-field-button-looks.php';
foreach ($input['files'] as &$html) {
    $html = str_replace('</style>', '.small{font-size:13.125px;padding:5.625px}</style>', $html);
    $html = str_replace('</body>', '<a class="btn small" href="/small">Small</a><a class="btn pill small" href="/small-pill">Small pill</a></body>', $html);
}
unset($html);
$variant = (new ArtifactCompiler())->compile($input)->toArray()['source_reports']['wordpress_site_plan'];
$minority = $majority = false;
foreach ($variant['writes'] as $write) {
    if ('theme.json' === $write['target_path']) {
        $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
        $styles = $theme['styles']['elements']['button'] ?? array();
        $assert(array('7.5px', '15px', '7.5px', '15px') === $paddingValues($styles, $theme), 'majority padding promoted despite minority');
        $sizes = array_column($theme['settings']['typography']['fontSizes'], 'size', 'slug');
        $assert('15px' === ($sizes[substr($styles['typography']['fontSize'] ?? '', strlen('var:preset|font-size|'))] ?? null), 'majority font size promoted despite minority');
        $assert(!isset($styles['border']['radius']), 'radius tie remains unpromoted');
        continue;
    }
    preg_match_all('/<!-- wp:button(?: (\{.*?\}))? -->/', $write['payload']['data'], $matches);
    foreach ($matches[1] as $json) {
        $attrs = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (isset($attrs['style']['spacing']['padding'])) {
            $minority = true;
            $assert(isset($attrs['fontSize']) || isset($attrs['style']['typography']['fontSize']), 'minority keeps own font size');
        } else {
            $majority = true;
            $assert(!isset($attrs['fontSize']) && !isset($attrs['style']['typography']['fontSize']), 'majority yields matching padding and size to the element');
        }
    }
}
$assert($minority && $majority, 'minority padding retained and majority padding removed');
$input = require dirname(__DIR__) . '/fixtures/design-system/jimdo-button-state-swap.php';
$variant = (new ArtifactCompiler())->compile($input)->toArray()['source_reports']['wordpress_site_plan'];
foreach ($variant['writes'] as $write) {
    if ('theme.json' !== $write['target_path']) continue;
    $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
    $styles = $theme['styles']['elements']['button'] ?? array();
    $palette = array_column($theme['settings']['color']['palette'], 'color', 'slug');
    foreach (array('hover', 'focus', 'active') as $state) {
        $value = $styles[':' . $state]['color']['background'] ?? '';
        $value = $palette[substr($value, strlen('var:preset|color|'))] ?? $value;
        $assert('#bdbdbd' === $value, $state . ' re-resolves resting paint through the exact Jimdo token swap');
        $assert(!isset($styles[':' . $state]['color']['text']), $state . ' cannot defeat important resting text');
    }
}
$input = require dirname(__DIR__) . '/fixtures/design-system/preset-padding-buttons.php';
$variant = (new ArtifactCompiler())->compile($input)->toArray()['source_reports']['wordpress_site_plan'];
$majority = $minority = 0;
foreach ($variant['writes'] as $write) {
    if ('theme.json' === $write['target_path']) {
        $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
        $padding = $theme['styles']['elements']['button']['spacing']['padding'] ?? array();
        $scale = array_column($theme['settings']['spacing']['spacingSizes'], 'size', 'slug');
        foreach (array('top' => '7.5px', 'right' => '15px', 'bottom' => '7.5px', 'left' => '15px') as $side => $value) {
            $reference = $padding[$side] ?? '';
            $assert(str_starts_with($reference, 'var:preset|spacing|'), 'element padding side binds to spacing preset');
            $assert($value === ($scale[substr($reference, strlen('var:preset|spacing|'))] ?? null), 'element spacing preset preserves padding');
        }
        continue;
    }
    preg_match_all('/<!-- wp:button(?: (\{.*?\}))? -->(.*?)<!-- \/wp:button -->/s', $write['payload']['data'], $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $attrs = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
        if (str_contains($match[2], '>Small<')) {
            ++$minority;
            $assert(isset($attrs['style']['spacing']['padding']), 'minority padding stays block-owned');
        } else {
            ++$majority;
            $assert(!isset($attrs['style']['spacing']['padding']), 'matching preset padding is dropped from blocks');
            $assert(!str_contains($match[2], 'padding-'), 'matching saved padding declarations are dropped');
        }
    }
}
$assert($majority > 0 && $minority > 0, 'preset fixture covers majority and minority buttons');
$input = require dirname(__DIR__) . '/fixtures/design-system/missing-family-preset-padding-buttons.php';
$variant = (new ArtifactCompiler())->compile($input)->toArray()['source_reports']['wordpress_site_plan'];
$buttons = 0;
foreach ($variant['writes'] as $write) {
    if ('theme.json' === $write['target_path']) {
        $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
        $styles = $theme['styles']['elements']['button'] ?? array();
        $assert(!isset($styles['typography']['fontFamily']), 'unowned family alone is rejected');
        $assert(isset($styles['border']['radius'], $styles['color']['text'], $styles['color']['background'], $styles['typography']['fontSize']), 'other dominant fields survive missing family');
        $assert(array('7.5px', '15px', '7.5px', '15px') === $paddingValues($styles, $theme), 'missing family preserves padding');
        foreach ($styles['spacing']['padding'] ?? array() as $value) $assert(str_starts_with($value, 'var:preset|spacing|'), 'surviving padding is preset-bound');
        continue;
    }
    preg_match_all('/<!-- wp:button(?: (\{.*?\}))? -->(.*?)<!-- \/wp:button -->/s', $write['payload']['data'], $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        ++$buttons;
        $attrs = '' === $match[1] ? array() : json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
        $assert(!isset($attrs['style']['spacing']['padding']), 'missing family does not prevent matching block padding removal');
        $assert(!str_contains($match[2], 'padding-'), 'saved padding declarations are removed too');
        if (str_contains($match[2], '>One<') || str_contains($match[2], '>Two<')) $assert(isset($attrs['fontFamily']) || isset($attrs['style']['typography']['fontFamily']), 'explicit minority family remains block-owned');
    }
}
$assert($buttons >= 3, 'missing-family fixture exercises at least three buttons');
$input = require dirname(__DIR__) . '/fixtures/design-system/navigation-cta-button-votes.php';
$result = (new ArtifactCompiler())->compile($input)->toArray();
$variant = $result['source_reports']['wordpress_site_plan'];
foreach ($result['source_reports']['compiled_site']['pages'] as $page) {
    $looks = $page['design_usage']['button_looks'] ?? array();
    $assert(3 === count($looks), 'each content control votes once per document despite repeated shell compilation');
    foreach ($looks as $look) $assert('7.5px 15px' === ($look['padding'] ?? null), 'only emitted core/button controls contribute looks');
}
$buttons = 0;
$documents = $variant['writes'];
foreach ($variant['pages'] as $page) $documents[] = array('target_path' => $page['source_path'], 'payload' => array('data' => $page['canonical_block_markup']));
foreach ($documents as $write) {
    if ('theme.json' === $write['target_path']) {
        $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
        $assert(array('7.5px', '15px', '7.5px', '15px') === $paddingValues($theme['styles']['elements']['button'] ?? array(), $theme), 'navigation CTA and form controls cannot vote against content padding');
        continue;
    }
    $markup = $write['payload']['data'];
    preg_match_all('/<!-- wp:button(?: (\{.*?\}))? -->(.*?)<!-- \/wp:button -->/s', $markup, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        ++$buttons;
        $attrs = '' === $match[1] ? array() : json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
        $assert(!isset($attrs['style']['spacing']['padding']), 'content buttons yield padding to element');
        $assert(!str_contains($match[2], 'padding-'), 'content saved padding removed');
    }
}
$assert($buttons >= 3 && str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'wp:navigation-link'), 'fixture emits content buttons and native navigation links');
echo "Inherited logical button element contract passed\n";
