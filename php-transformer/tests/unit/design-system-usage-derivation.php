<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssColor;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Automattic\BlocksEngine\PhpTransformer\WordPress\SourceClassIdentity;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DesignSystem;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\PresetBinding;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeJsonProjection;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ($condition) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$compile = static function (array $artifact): array {
    $result = (new ArtifactCompiler())->compile($artifact)->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'] ?? array();
    $theme = array();
    foreach ($plan['writes'] ?? array() as $write) if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
    return array($plan, $theme, $result['source_reports']['editability_report'] ?? array());
};
// Rendered stacks need no @font-face to become editable role presets (#3302).
$systemStackCss = ':root{--font-body:ui-sans-serif,system-ui,sans-serif;--font-display:Georgia,serif}'
    . 'body{font-family:var(--font-body)} h1,h2{font-family:var(--font-display)}';
[$systemStackPlan, $systemStackTheme] = $compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => '<!doctype html><html><head><style>' . $systemStackCss . '</style></head><body><h1>Title</h1><p>Body</p></body></html>'),
));
$systemStackFamilies = array_column($systemStackTheme['settings']['typography']['fontFamilies'] ?? array(), 'fontFamily', 'slug');
$assert(array('heading' => 'Georgia,serif', 'body' => 'ui-sans-serif,system-ui,sans-serif') === $systemStackFamilies, 'rendered system stacks: body and heading presets contain the rendered stacks without font faces');
$assert('var:preset|font-family|body' === ($systemStackTheme['styles']['typography']['fontFamily'] ?? null), 'rendered system stacks: root typography references body');
$assert('var:preset|font-family|heading' === ($systemStackTheme['styles']['elements']['heading']['typography']['fontFamily'] ?? null), 'rendered system stacks: heading typography references heading (' . json_encode($systemStackTheme['styles'] ?? array()) . ')');
$systemStackAssets = implode('', array_column($systemStackPlan['assets'] ?? array(), 'content'));
foreach (array('body' => 'body', 'heading' => 'display') as $role => $token) {
    $assert(str_contains($systemStackAssets, 'font-family:var(--wp--preset--font-family--' . $role . ',var(--font-' . $token . '))'), 'rendered system stacks: ' . $role . ' source declaration binds with its original var() fallback');
}

// Channel custom properties are colours only in the consuming function, not
// standalone palette values. Keep the authored expression as the CSS fallback.
foreach (array('hsl(var(--foreground))', 'hsl(var(--foreground) / 1)', 'rgb(var(--channels))', 'rgb(var(--channels) / 100%)') as $expression) {
    [$channelPlan, $channelTheme] = $compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => '<!doctype html><html><head><style>:root{--foreground:0 0% 3.9%;--muted:215 16% 47%;--channels:10 10 10;--paper:255 255 255}body{color:' . $expression . ';background-color:rgb(var(--paper) / 1)}.muted{color:hsl(var(--muted))}.ink{color:#000}.surface{background:linear-gradient(' . $expression . ',rgb(var(--paper) / 100%))}</style></head><body><p class="surface">' . str_repeat('Lots of body copy. ', 20) . '</p><p class="muted">Secondary copy.</p><p class="ink">A little black text</p></body></html>'),
    ));
    $channelPalette = array_column($channelTheme['settings']['color']['palette'] ?? array(), 'color', 'slug');
    $channelSlug = array_search('#0a0a0a', $channelPalette, true);
    $assert(false !== $channelSlug, $expression . ': rendered channel colour belongs to the palette');
    $assert('#0a0a0a' === ($channelPalette['contrast'] ?? null), $expression . ': dominant body copy determines contrast, not the small literal black run');
    $assert('var:preset|color|' . $channelSlug === ($channelTheme['styles']['color']['text'] ?? null), $expression . ': root text references its matching preset');
    $assert('var:preset|color|base' === ($channelTheme['styles']['color']['background'] ?? null), $expression . ': root background references its matching preset');
    $channelCss = implode("\n", array_column($channelPlan['assets'], 'content'));
    $assert(str_contains($channelCss, 'color:var(--wp--preset--color--' . $channelSlug . ',' . $expression . ')'), $expression . ': CSS binds with the original expression as fallback');
    $assert(str_contains($channelCss, 'linear-gradient(var(--wp--preset--color--' . $channelSlug . ',' . $expression . '),var(--wp--preset--color--base,rgb(var(--paper) / 100%)))'), $expression . ': compound colour tokens resolve and retain their original fallbacks too');
}

// A late shared WordPress capture stylesheet cannot redefine generated presets.
[$sourcePresetPlan, $sourcePresetTheme] = $compile(require dirname(__DIR__) . '/fixtures/design-system/source-wordpress-presets.php');
$sourcePalette = array_column($sourcePresetTheme['settings']['color']['palette'], 'color', 'slug');
$assert('#ffffff' === ($sourcePalette['accent-2'] ?? null), 'shared WordPress fixture binds white to accent-2');
$assert(2 === count($sourcePresetPlan['pages']), 'WordPress preset collision fixture retains both routes');
foreach ($sourcePresetPlan['pages'] as $page) {
    $assert(str_contains($page['canonical_block_markup'], '"backgroundColor":"accent-2"'), 'each white card binds to the generated accent-2');
}
$sharedPresetCss = '';
foreach ($sourcePresetPlan['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? null)) continue;
    $css = $asset['content'] ?? '';
    $assert(1 !== preg_match('/--wp--preset--[a-zA-Z0-9_-]+\\s*:/', $css), 'carried CSS never declares a generated preset');
    if (str_contains($css, '.card{') && !str_contains($css, 'editor-styles-wrapper')) $sharedPresetCss .= $css;
}
$assert(1 === preg_match('/--be--source--preset--color--accent-2:(?:#F6CFF4|var\(--wp--preset--color--[a-z0-9-]+,#F6CFF4\))/', $sharedPresetCss), 'shared capture CSS keeps the source palette in its own namespace, optionally bound to its own token');
foreach (array('color', 'background-color', 'border-color', 'gradient-background', 'font-size', 'font-family') as $suffix) {
    $class = 'has-accent-2-' . $suffix;
    $marker = SourceClassIdentity::marker($class);
    $assert('' !== $marker, 'core preset utility gets a source-only identity: ' . $suffix);
}
$sourceUtilityMarker = SourceClassIdentity::marker('has-accent-2-background-color');
$assert(str_contains($sharedPresetCss, '.' . $sourceUtilityMarker), 'source preset utility selectors target source-only identities');
foreach ($sourcePresetPlan['pages'] as $page) {
    $assert(str_contains($page['canonical_block_markup'], $sourceUtilityMarker), 'authored preset utility keeps its source-only identity on both routes');
}
$assert(str_contains($sharedPresetCss, 'background-color:var(--wp--preset--color--accent-2,rgb(255,255,255))'), 'card CSS reads the generated preset, not the source preset');
$assert(str_contains($sharedPresetCss, 'outline-color:var(--be--source--preset--color--accent-2)'), 'unbound source reads keep following the source palette');
foreach (array('font-size--large:31px', 'spacing--40:19px', 'font-family--body:Georgia') as $preset) {
    $assert(str_contains($sharedPresetCss, '--be--source--preset--' . $preset), 'non-colour source presets are isolated too: ' . $preset);
}
$roleSlug = '/^(?:base|contrast|primary|secondary|accent-\d+)$/';
$sizeSlug = '/^(?:medium|(?:x{0,5}-)?small|(?:x{0,5}-)?large)$/';

// Fixtures: utility classes, custom-property tokens, plain CSS, and compiled
// Tailwind v3 and v4 sites whose colours, sizes and spacing come only from
// utility classes. Each declares one font family that no page renders.
$unusedFamilies = array('utility-classes' => 'Merriweather', 'custom-properties' => 'JetBrains Mono', 'plain-css' => 'Lobster', 'tailwind-v3' => 'Georgia', 'tailwind-v4' => 'Merriweather');
$plans = array();
foreach ($unusedFamilies as $fixture => $unusedFamily) {
    $artifact = require dirname(__DIR__) . '/fixtures/design-system/' . $fixture . '.php';
    [$plan, $theme, $editability] = $compile($artifact);
    $assert(array() !== $plan && array() !== $theme, "$fixture: compiles to a site plan with theme.json");
    $palette = $theme['settings']['color']['palette'] ?? array();
    $slugs = array_column($palette, 'slug');
    $assert(count($palette) >= 5 && count($palette) <= 12, "$fixture: palette has 5-12 colours (" . count($palette) . ')');
    $assert(in_array('base', $slugs, true) && in_array('contrast', $slugs, true), "$fixture: palette names base and contrast");
    $assert(array() === array_filter($slugs, static fn(string $slug): bool => 1 !== preg_match($roleSlug, $slug)), "$fixture: every colour slug is a role name");
    $assert(count($slugs) === count(array_unique(array_map('strtolower', array_column($palette, 'color')))), "$fixture: palette colours are distinct");
    $sizes = $theme['settings']['typography']['fontSizes'] ?? array();
    $assert(count($sizes) >= 4 && count($sizes) <= 8, "$fixture: type scale has 4-8 steps (" . count($sizes) . ')');
    $assert(in_array('medium', array_column($sizes, 'slug'), true) && array() === array_filter(array_column($sizes, 'slug'), static fn(string $slug): bool => 1 !== preg_match($sizeSlug, $slug)), "$fixture: type scale uses t-shirt slugs around medium");
    $families = $theme['settings']['typography']['fontFamilies'] ?? array();
    $assert(in_array('body', array_column($families, 'slug'), true), "$fixture: body family is a preset");
    $assert(array() === array_filter($families, static fn(array $family): bool => str_contains((string) $family['fontFamily'], $unusedFamily)), "$fixture: the declared but unrendered $unusedFamily stack is not a preset");
    $metrics = $plan['theme']['design_system']['metrics'] ?? array();
    $assert(array($unusedFamily) === ($metrics['unused_font_families'] ?? null), "$fixture: metrics report only the primary family $unusedFamily as unused (" . json_encode($metrics['unused_font_families'] ?? null) . ')');
    $assert(($metrics['preset_share'] ?? 0) >= 0.8 && ($metrics['preset_value_count'] ?? 0) > 0, "$fixture: at least 80% of block style values are presets (" . json_encode($metrics['preset_share'] ?? null) . ')');
    $assert(count($palette) === ($metrics['palette_size'] ?? null) && is_int($metrics['unique_source_css_bytes'] ?? null) && $metrics['unique_source_css_bytes'] > 0 && ($metrics['theme_css_bytes'] ?? 0) >= ($metrics['engine_css_bytes'] ?? PHP_INT_MAX) && is_float($metrics['generated_to_source_css_ratio'] ?? null), "$fixture: metrics report palette size and CSS byte ratio");
    $assert(($editability['design_system']['metrics'] ?? null) == $metrics, "$fixture: the editability report carries the design-system metrics");
    if ('tailwind-v4' === $fixture) {
        // The page colours follow `prefers-color-scheme`, so no fixed root
        // colour may replace them.
        $assert(!isset($theme['styles']['color']) && !isset($theme['styles']['elements']['link']['color']) && 'var:preset|font-family|body' === ($theme['styles']['typography']['fontFamily'] ?? null), "$fixture: root colours that follow the dark theme stay with the source; the family is a preset reference");
    } else {
        $assert('var:preset|color|base' === ($theme['styles']['color']['background'] ?? null) && 'var:preset|font-family|body' === ($theme['styles']['typography']['fontFamily'] ?? null), "$fixture: root background and family are preset references");
        $assert(is_string($theme['styles']['elements']['link']['color']['text'] ?? null) && str_starts_with($theme['styles']['elements']['link']['color']['text'], 'var:preset|color|'), "$fixture: link element colour is a preset reference");
    }
    $assert(false === ($theme['settings']['color']['defaultPalette'] ?? null), "$fixture: the editor offers the source palette instead of core's");
    $markup = implode('', array_column($plan['pages'], 'canonical_block_markup'));
    $assert(1 === preg_match('/"(?:textColor|backgroundColor)":"[a-z0-9-]+"/', $markup) && str_contains($markup, 'var:preset|spacing|'), "$fixture: blocks are bound to colour and spacing presets");

    // Route order must not change the design system or any page markup.
    $reversed = $artifact;
    $reversed['files'] = array_reverse($artifact['files'], true);
    [$reversedPlan, $reversedTheme] = $compile($reversed);
    $assert($theme === $reversedTheme, "$fixture: theme.json is identical under route reordering");
    $byPath = static fn(array $plan): array => array_column($plan['pages'], 'canonical_block_markup', 'source_path');
    $assert($byPath($plan) == $byPath($reversedPlan) && ($plan['theme']['design_system'] ?? null) === ($reversedPlan['theme']['design_system'] ?? null), "$fixture: bound markup and design-system report are identical under route reordering");
    [$againPlan, $againTheme] = $compile($artifact);
    $assert($theme === $againTheme && $byPath($plan) === $byPath($againPlan) && ($plan['theme']['design_system'] ?? null) === ($againPlan['theme']['design_system'] ?? null), "$fixture: compiling twice gives identical theme.json, markup and design-system report");
    $plans[$fixture] = array($plan, $theme);
}

// Compiled Tailwind: values set only by author classes are bound to presets.
foreach (array('tailwind-v3', 'tailwind-v4') as $fixture) {
    [$plan, $theme] = $plans[$fixture];
    $markup = implode('', array_column($plan['pages'], 'canonical_block_markup'));
    $assert(1 === preg_match('/<!-- wp:paragraph \{"className":"[^"]*text-base[^"]*",(?:"[a-zA-Z]+":"[a-z0-9-]+",)*"fontSize":"medium"[^}]*\} --><p class="[^"]*has-medium-font-size/', $markup), "$fixture: a paragraph sized by a utility class carries the font-size preset");
    $assert(1 === preg_match('/"textColor":"[a-z0-9-]+"/', $markup) && 1 === preg_match('/<h2 class="[^"]*has-text-color has-[a-z0-9-]+-color[^"]*has-heading-font-family/', $markup), "$fixture: headings coloured by a utility class carry colour and family presets");
    $assert(1 === preg_match('/"backgroundColor":"[a-z0-9-]+"[^>]*--><section class="[^"]*has-background[^"]*has-[a-z0-9-]+-background-color/', $markup), "$fixture: a section painted by a utility class carries the background preset");
    // A size that changes at a breakpoint and a colour that changes in the
    // dark theme stay with the author classes.
    $assert(1 === preg_match('/<!-- wp:heading \{"className":"[^"]*md:text-5xl[^"]*","level":1,(?:"textColor":"[a-z0-9-]+",)?"fontFamily":"heading"\} -->/', $markup) && !preg_match('/<!-- wp:heading \{"className":"[^"]*md:text-5xl[^"]*"[^}]*"fontSize"/', $markup), "$fixture: a responsive heading size is not bound to one preset");
    $hoverButton = preg_match('/<!-- wp:button \{[^}]*hover:bg-[^}]*\}/', $markup, $button) ? $button[0] : '';
    $assert('' !== $hoverButton && !str_contains($hoverButton, '"backgroundColor"'), "$fixture: a button background that changes on hover is not bound");
}
$v4 = $plans['tailwind-v4'][0]['pages'];
$v4Markup = implode('', array_column($v4, 'canonical_block_markup'));
$assert(!preg_match('/dark:text-slate-100[^"]*","level":1,"textColor"/', $v4Markup) && !preg_match('/"className":"text-xl text-slate-600 dark:text-slate-300","textColor"/', $v4Markup), 'tailwind-v4: colours the dark theme changes are not bound');
$v3Families = $plans['tailwind-v3'][1]['settings']['typography']['fontFamilies'] ?? array();
$assert(1 === count(array_filter($v3Families, static fn(array $family): bool => str_contains((string) $family['fontFamily'], 'IBM Plex Mono'))), 'tailwind-v3: stacks that share a primary family are one preset');
$assert(array() === array_filter($v3Families, static fn(array $family): bool => 1 === preg_match('/^\W*(?:ui-|system-ui|Georgia|Inter Fallback)/i', (string) $family['fontFamily'])), 'tailwind-v3: only rendered primary families become presets');
// A dark theme that swaps the token a utility reads keeps the utility.
$darkTokens = require dirname(__DIR__) . '/fixtures/design-system/tailwind-v4.php';
$darkTokens['files']['assets/app.css'] .= '@media (prefers-color-scheme:dark){:root{--color-slate-900:#f8fafc}}';
$darkMarkup = implode('', array_column($compile($darkTokens)[0]['pages'], 'canonical_block_markup'));
$assert(!preg_match('/<!-- wp:paragraph \{"className":"text-base text-slate-900"[^}]*"textColor"/', $darkMarkup) && !preg_match('/"className":"bg-slate-900 text-slate-50 p-8"[^}]*"backgroundColor"/', $darkMarkup) && str_contains($darkMarkup, '"textColor"'), 'a colour read from a custom property the dark theme redefines is not bound');

// Clustering and roles from usage alone.
$usage = static fn(array $text, array $backgrounds = array(), array $root = array()): array => array('schema' => DesignSystem::USAGE_SCHEMA, 'root_font_size_px' => 16, 'root' => $root, 'text' => $text, 'backgrounds' => $backgrounds, 'spacing' => array(), 'widths' => array());
$row = static fn(string $color, int $weight, string $role = 'body', float $size = 16, string $background = '#ffffff'): array => array('role' => $role, 'color' => $color, 'font_family' => 'Inter, sans-serif', 'font_size_px' => $size, 'background' => $background, 'count' => 1, 'weight' => $weight);
$system = DesignSystem::fromUsage(array(
    'a.html' => $usage(array($row('#222222', 100), $row('#232323', 40), $row('#e11d48', 10, 'link')), array(array('role' => 'root', 'color' => '#ffffff', 'authored' => null, 'count' => 1, 'weight' => 150), array('role' => 'root', 'color' => '#fefefe', 'authored' => null, 'count' => 1, 'weight' => 5))),
));
$colors = array_column($system?->colors ?? array(), 'color', 'slug');
$assert(array('base' => '#ffffff', 'contrast' => '#222222', 'primary' => '#e11d48', 'accent-1' => '#232323', 'accent-2' => '#fefefe') === $colors, 'near-duplicate colours within one just-noticeable difference rank under the heaviest member, and each painted shade keeps a token of its own after the distinct colours (' . json_encode($colors) . ')');
$assert('contrast' === $system?->colorSlug('#222') && 'base' === $system?->colorSlug('rgb(255,255,255)') && 'accent-1' === $system?->colorSlug('#232323') && 'accent-2' === $system?->colorSlug('#fefefe') && null === $system?->colorSlug('#242424') && null === $system?->colorSlug('rgba(34,34,34,.5)'), 'a colour binds only to the token of its exact value: a near shade with no token of its own and translucent colours keep their literal');
$many = array();
for ($i = 0; $i < 20; $i++) $many[] = $row(sprintf('#%02x%02x%02x', 20 + $i * 11, 200 - $i * 9, ($i * 37) % 256), 20 - $i);
$capped = DesignSystem::fromUsage(array('a.html' => $usage($many)));
$assert(12 === count($capped?->colors ?? array()), 'the palette is capped at twelve colours');
$sizes = array();
foreach (array(12, 14, 16, 16.2, 18, 20, 24, 30, 36, 48, 60, 72) as $index => $size) $sizes[] = $row('#111111', 100 - $index, 'body', $size);
$scale = DesignSystem::fromUsage(array('a.html' => $usage($sizes)));
$scaleSlugs = array_column($scale?->fontSizes ?? array(), 'slug');
$assert(8 === count($scaleSlugs) && 'medium' === ($scale?->fontSizeSlug('16px')) && null === ($scale?->fontSizeSlug('16.2px')) && in_array('small', $scaleSlugs, true) && in_array('large', $scaleSlugs, true), 'font sizes within half a pixel merge, only the preset\'s own size binds, and the scale is capped at eight steps');
// Three per-page copies of one stylesheet ship three times its bytes.
$copies = array_fill(0, 3, array('kind' => 'css', 'source' => 'files', 'content' => str_repeat('a{color:#111111}', 10)));
$sizeMetrics = DesignSystem::fromUsage(array('a.html' => $usage(array($row('#111111', 5)))))?->metrics(array('<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->'), $copies, array(), array(), 160);
$assert(480 === ($sizeMetrics['theme_css_bytes'] ?? null) && 160 === ($sizeMetrics['unique_source_css_bytes'] ?? null) && 0 === ($sizeMetrics['engine_css_bytes'] ?? null) && 3.0 === ($sizeMetrics['generated_to_source_css_ratio'] ?? null), 'three per-page copies of one stylesheet read as 3x the captured source CSS (' . json_encode($sizeMetrics) . ')');

$first = DesignSystem::fromUsage(array('a.html' => $usage(array($row('#111111', 5))), 'b.html' => $usage(array($row('#0369a1', 5, 'link'))))) ?->report();
$second = DesignSystem::fromUsage(array('b.html' => $usage(array($row('#0369a1', 5, 'link'))), 'a.html' => $usage(array($row('#111111', 5))))) ?->report();
$assert(null !== $first && $first === $second, 'document order cannot change the derived system');

// Binding is exact, idempotent and leaves values a stylesheet could observe.
$bindSystem = DesignSystem::fromUsage(array('a.html' => $usage(array($row('#111111', 50), $row('#e11d48', 20, 'body', 24)), array(array('role' => 'root', 'color' => '#ffffff', 'authored' => '#fff', 'count' => 1, 'weight' => 70)))));
$paragraph = '<!-- wp:paragraph {"style":{"color":{"text":"#e11d48"},"typography":{"fontSize":"24px"}}} --><p class="has-text-color" style="color:#e11d48;font-size:24px">Hi</p><!-- /wp:paragraph -->';
$binding = new PresetBinding($bindSystem, array());
$bound = $binding->bind($paragraph);
$slug = (string) $bindSystem?->colorSlug('#e11d48');
$assert('<!-- wp:paragraph {"textColor":"' . $slug . '","fontSize":"large"} --><p class="has-text-color has-' . $slug . '-color has-large-font-size">Hi</p><!-- /wp:paragraph -->' === $bound, 'a literal colour and size become preset attributes with their classes, and the inline declarations are removed');
$assert($bound === $binding->bind($bound), 'binding is idempotent');
$importantBound = (new PresetBinding($bindSystem, array(array('kind' => 'css', 'content' => 'main p{color:red!important}'))))->bind($paragraph);
$assert(!str_contains($importantBound, 'textColor') && str_contains($importantBound, '"fontSize":"large"'), 'an !important author colour on the same subject keeps the literal colour but not the unrelated size');
$assert(!str_contains((new PresetBinding($bindSystem, array(array('kind' => 'css', 'content' => '[style*="color"]{outline:0}'))))->bind($paragraph), 'textColor'), 'a selector reading the inline style text keeps the literal colour');
$mismatch = '<!-- wp:paragraph {"style":{"color":{"text":"#e11d48"}}} --><p class="has-text-color" style="color:#E11D48">Hi</p><!-- /wp:paragraph -->';
$assert($mismatch === $binding->bind($mismatch), 'markup whose inline style was not serialized from the attributes is left alone');

// Class-driven binding: the value the source rendered for a block's class
// list becomes a preset when every element with that class list agreed.
$classUsage = static fn(array $rows): array => array('a.html' => array('schema' => DesignSystem::USAGE_SCHEMA, 'class_styles' => $rows));
$plain = '<!-- wp:paragraph {"className":"text-rose"} --><p class="text-rose">Hi</p><!-- /wp:paragraph -->';
$classBound = (new PresetBinding($bindSystem, array(), $classUsage(array(array('classes' => 'text-rose', 'properties' => array('color' => array('#e11d48'), 'font-size' => array('24px')))))))->bind($plain);
$assert('<!-- wp:paragraph {"className":"text-rose","textColor":"' . $slug . '","fontSize":"large"} --><p class="text-rose has-text-color has-' . $slug . '-color has-large-font-size">Hi</p><!-- /wp:paragraph -->' === $classBound, 'a colour and size set by an author class become preset attributes and classes');
$withSpacing = DesignSystem::fromUsage(array('a.html' => array_replace($usage(array($row('#111111', 50))), array('spacing' => array(array('value' => '1rem', 'px' => 16, 'count' => 4, 'weight' => 4))))));
$assert(null !== $withSpacing, 'a system with a spacing step derives');
$padded = (new PresetBinding($withSpacing, array(), $classUsage(array(array('classes' => 'p-4', 'properties' => array('padding-top' => array('16px'), 'padding-right' => array('16px'), 'padding-bottom' => array('16px'), 'padding-left' => array('16px')))))))->bind('<!-- wp:group {"className":"p-4"} --><div class="wp-block-group p-4"></div><!-- /wp:group -->');
$assert('<!-- wp:group {"className":"p-4","style":{"spacing":{"padding":{"top":"var:preset|spacing|20","right":"var:preset|spacing|20","bottom":"var:preset|spacing|20","left":"var:preset|spacing|20"}}}} --><div class="wp-block-group p-4" style="padding-top:var(--wp--preset--spacing--20);padding-right:var(--wp--preset--spacing--20);padding-bottom:var(--wp--preset--spacing--20);padding-left:var(--wp--preset--spacing--20)"></div><!-- /wp:group -->' === $padded, 'padding set by an author class becomes inline spacing presets');
$refused = array(
    'elements with the class list disagree' => array(array('classes' => 'text-rose', 'properties' => array('color' => array('!')))),
    'a breakpoint gives another token' => array(array('classes' => 'text-rose', 'properties' => array('color' => array('#e11d48;#111111')))),
    'the value is not a token' => array(array('classes' => 'text-rose', 'properties' => array('color' => array('#00ff00')))),
);
foreach ($refused as $why => $rows) $assert($plain === (new PresetBinding($bindSystem, array(), $classUsage($rows)))->bind($plain), "class-driven colour is not bound when $why");
$sameToken = (new PresetBinding($bindSystem, array(), $classUsage(array(array('classes' => 'text-rose', 'properties' => array('color' => array('#e11d48;#e11d48')))))))->bind($plain);
$assert(str_contains($sameToken, '"textColor":"' . $slug . '"'), 'a breakpoint value that matches the same token does not block binding');
$roseUsage = $classUsage(array(array('classes' => 'text-rose', 'properties' => array('color' => array('#e11d48')))));
// Core pads `.has-background` paragraphs, headings, lists and columns, so a
// class-driven background binds there only when the source sets that padding.
$bgRows = static fn(array $padding): array => array(array('classes' => 'bg-rose', 'properties' => array('background-color' => array('#e11d48')) + $padding));
$bgParagraph = '<!-- wp:paragraph {"className":"bg-rose"} --><p class="bg-rose">1</p><!-- /wp:paragraph -->';
$assert($bgParagraph === (new PresetBinding($bindSystem, array(), $classUsage($bgRows(array()))))->bind($bgParagraph), 'a class-driven background is not bound where has-background would add core padding');
$sourcePadded = $bgRows(array('padding-top' => array('0px'), 'padding-right' => array('8px'), 'padding-bottom' => array('0px'), 'padding-left' => array('8px')));
$assert(str_contains((new PresetBinding($bindSystem, array(), $classUsage($sourcePadded)))->bind($bgParagraph), '"backgroundColor":"' . $slug . '"'), 'a class-driven background binds when the source sets every padding side');
$bgGroup = '<!-- wp:group {"className":"bg-rose"} --><div class="wp-block-group bg-rose"></div><!-- /wp:group -->';
$assert(str_contains((new PresetBinding($bindSystem, array(), $classUsage($bgRows(array()))))->bind($bgGroup), '"backgroundColor":"' . $slug . '"'), 'a class-driven background binds on a block core does not pad');
foreach (array(
    'an !important rule reaches the block' => '.text-rose{color:#e11d48!important}',
    'a hover rule restyles it' => '.text-rose:hover{color:#111}',
    'a nested hover rule restyles it' => '.text-rose{&:hover{color:#111}}',
) as $why => $css) $assert($plain === (new PresetBinding($bindSystem, array(array('kind' => 'css', 'content' => $css)), $roseUsage))->bind($plain), "class-driven colour is not bound when $why");
$assert(str_contains((new PresetBinding($bindSystem, array(array('kind' => 'css', 'content' => 'p.text-rose:not(.has-text-color){color:#e11d48!important}')), $roseUsage))->bind($plain), '"textColor"'), 'an !important rule that steps aside for preset colours does not block binding');

// Low-weight colours group with a heavier colour within ΔE 5 for slot
// ranking only; a painted shade still keeps a token of its own exact value.
$consolidated = DesignSystem::consolidateColors(array(
    array('color' => '#050b18', 'weight' => 900, 'members' => array('#050b18')),
    array('color' => '#3b82f6', 'weight' => 60, 'members' => array('#3b82f6')),
    array('color' => '#2563eb', 'weight' => 40, 'members' => array('#2563eb')),
    array('color' => '#0a1630', 'weight' => 20, 'members' => array('#0a1630')),
    array('color' => '#08101f', 'weight' => 15, 'members' => array('#08101f')),
    array('color' => '#e11d48', 'weight' => 10, 'members' => array('#e11d48')),
));
$assert(array('#050b18' => 915, '#3b82f6' => 60, '#2563eb' => 40, '#0a1630' => 20, '#e11d48' => 10) === array_column($consolidated, 'weight', 'color'), 'a low-weight shade within ΔE 5 ranks with the heavier colour; a shade ΔE 12.9 away and heavier colours rank on their own');
$assert(array() === array_diff(array('#050b18', '#08101f'), $consolidated[0]['members']), 'the grouped shade is a member of the heavier colour\'s group');
$heavyTwins = DesignSystem::consolidateColors(array(array('color' => '#333333', 'weight' => 500, 'members' => array('#333333')), array('color' => '#3b3b3b', 'weight' => 450, 'members' => array('#3b3b3b')), array('color' => '#2563eb', 'weight' => 400, 'members' => array('#2563eb'))));
$assert(array('#333333' => 950, '#2563eb' => 400) === array_column($heavyTwins, 'weight', 'color'), 'a heavily used near-duplicate (ΔE 3.6) still ranks with the heavier colour, so distinct colours take slots first');
$foldedBackgrounds = array(array('role' => 'root', 'color' => '#050b18', 'authored' => '#050b18', 'count' => 1, 'weight' => 900), array('role' => 'section', 'color' => '#08101f', 'authored' => '#08101f', 'count' => 1, 'weight' => 20), array('role' => 'section', 'color' => '#0a1630', 'authored' => '#0a1630', 'count' => 1, 'weight' => 20));
$paletteWith = DesignSystem::fromUsage(array('a.html' => $usage(array($row('#e5e7eb', 600)), $foldedBackgrounds)));
$assert(array('#050b18', '#e5e7eb', '#0a1630', '#08101f') === array_column($paletteWith?->colors ?? array(), 'color'), 'the derived palette lists the distinct colours first, then the painted near shade with a token of its own (' . json_encode(array_column($paletteWith?->colors ?? array(), 'color')) . ')');
$assert('base' === $paletteWith?->colorSlug('#050b18') && '#08101f' === $paletteWith?->color((string) $paletteWith?->colorSlug('#08101f')) && '#0a1630' === $paletteWith?->color((string) $paletteWith?->colorSlug('#0a1630')), 'each painted shade binds to the token of its exact value, never to the colour it ranks with');
$assert(array() === ($paletteWith?->report()['literal_colors'] ?? null) && !array_key_exists('absorbs', $paletteWith?->report()['palette'][0] ?? array()) && 5.0 === ($paletteWith?->report()['tolerances']['color_consolidation_delta_e76'] ?? null), 'no token absorbs a painted shade, and the report names the ranking bound');
$assert((CssColor::distance('#08101f', '#050b18') ?? INF) <= DesignSystem::COLOR_CONSOLIDATION_DISTANCE && (CssColor::distance('#0a1630', '#050b18') ?? 0.0) > DesignSystem::COLOR_CONSOLIDATION_DISTANCE, 'the fixture shades sit on either side of the bound');

// Without usage, projection keeps the legacy hashed presets.
$legacy = (new ThemeJsonProjection())->project(array(array('kind' => 'css', 'content' => 'body{color:#123456}', 'scopes' => array(array('kind' => 'global')))));
$assert(1 === preg_match('/^color-/', (string) ($legacy['theme']['settings']['color']['palette'][0]['slug'] ?? '')), 'projection without a design system keeps hashed literal presets');
$withSystem = (new ThemeJsonProjection())->project(array(array('kind' => 'css', 'content' => 'body{color:#111111}', 'scopes' => array(array('kind' => 'global')))), array(), $bindSystem);
$assert('var:preset|color|contrast' === ($withSystem['theme']['styles']['color']['text'] ?? null), 'projected selector values resolve to design-system tokens');

// Stylesheet values equal to a token read the preset variable, with the
// literal as fallback, so links, navigation items and states follow it too.
$sheet = static fn(string $css, array $assets = array()): string => (new PresetBinding($bindSystem, array_merge($assets, array(array('kind' => 'css', 'content' => $css)))))->bindStylesheet($css);
$assert('.a{color:var(--wp--preset--color--' . $slug . ',#e11d48)}.a:hover{background-color:var(--wp--preset--color--contrast,#111111)!important}' === $sheet('.a{color:#e11d48}.a:hover{background-color:#111111!important}'), 'stylesheet colours and states bind to the token variable with the literal as fallback, keeping !important');
$assert('@media (min-width:40rem){.a{color:var(--wp--preset--color--' . $slug . ',#e11d48)}}' === $sheet('@media (min-width:40rem){.a{color:#e11d48}}') && '.a{color:#e21d48}' === $sheet('.a{color:#e21d48}'), 'a token colour binds inside a media query, and a near shade keeps its bytes');
$assert('.a{color:#00ff00;border-color:#111111;font-size:23px}' === $sheet('.a{color:#00ff00;border-color:#111111;font-size:23px}'), 'values that are not tokens, and properties outside colour, background, size and family, keep their bytes');
$assert('.a{font-size:var(--wp--preset--font-size--large,1.5rem)}' === $sheet('.a{font-size:1.5rem}'), 'a font size equal to a preset length binds');
$assert('.a{font-size:24.4px}' === $sheet('.a{font-size:24.4px}'), 'a font size within tolerance but not equal stays, so no layout moves');
$assert(':root{--brand:var(--wp--preset--color--' . $slug . ',#e11d48)}' === $sheet(':root{--brand:#e11d48}'), 'a custom property that is a token colour binds, so every utility reading it follows');
$sourceRead = $sheet(':root{--wp--preset--color--accent-2:#e11d48}.a{color:var(--wp--preset--color--accent-2)}');
$assert(str_contains($sourceRead, '--be--source--preset--color--accent-2:var(--wp--preset--color--' . $slug . ',#e11d48)'), 'a source preset definition matching a token follows it under a source-owned name');
$assert(str_contains($sourceRead, '.a{color:var(--wp--preset--color--' . $slug . ',var(--be--source--preset--color--accent-2))}'), 'a resolved source preset read binds to the generated token with a source-owned fallback');
$quotedPreset = '.a{content:"--wp--preset--color--accent-2";/* --wp--preset--color--accent-2 */outline-color:var(--wp--preset--color--accent-2)}';
$assert(str_contains($sheet($quotedPreset), 'content:"--wp--preset--color--accent-2"') && str_contains($sheet($quotedPreset), 'outline-color:var(--be--source--preset--color--accent-2)'), 'source projection leaves quoted content alone while rewriting variable reads');
$assert(str_contains($sheet('@media(min-width:1px){.a{--wp--preset--spacing--40:3px;padding:var(--wp--preset--spacing--40)}}'), '--be--source--preset--spacing--40:3px;padding:var(--be--source--preset--spacing--40)'), 'conditional source definitions and reads share the source namespace');
$opacity = '.text-x{--tw-text-opacity:1;color:rgb(225 29 72 / var(--tw-text-opacity, 1))}';
$assert(str_contains($sheet($opacity), 'color:var(--wp--preset--color--' . $slug . ',rgb(225 29 72 / var(--tw-text-opacity, 1)))'), 'a Tailwind opacity variable every rule sets to its fallback resolves');
$assert(!str_contains($sheet($opacity . '.text-opacity-50{--tw-text-opacity:.5}'), '--wp--preset--color--'), 'an opacity variable another rule sets differently keeps the literal');
$assert(!str_contains($sheet('.a{color:hsl(var(--x))}', array(array('kind' => 'css', 'content' => ':root{--x:347 77% 50%}.dark{--x:0 0% 7%}'))), '--wp--preset--color--'), 'a colour read from a custom property a scope redefines keeps the literal');
$assert('.a{font-family:var(--wp--preset--font-family--body,Inter, sans-serif)}.b{font-family:Inter,Arial}' === $sheet('.a{font-family:Inter, sans-serif}.b{font-family:Inter,Arial}'), 'only the preset\'s own font stack binds, so fallbacks do not change');
$fontVariables = ':root{--family:Inter, sans-serif;--other:Inter,Arial}.a{font-family:var(--family)}.b{font-family:var(--other)}';
$assert(str_contains($sheet($fontVariables), '.a{font-family:var(--wp--preset--font-family--body,var(--family))}') && str_contains($sheet($fontVariables), '.b{font-family:var(--other)}'), 'resolved font declarations bind only an exact stack and retain the original variable fallback');
$assert('.a{font-family:var(--wp--preset--font-family--body,"Inter",sans-serif)}.b{font-family:"In ter",sans-serif}' === $sheet('.a{font-family:"Inter",sans-serif}.b{font-family:"In ter",sans-serif}'), 'font stack comparison preserves spaces inside family names');

// Compound colour values follow the palette without interpreting positions,
// variable fallbacks or URLs as colour tokens.
$compoundSystem = (new \ReflectionClass(DesignSystem::class))->newInstanceWithoutConstructor();
$compoundConstructor = new \ReflectionMethod(DesignSystem::class, '__construct');
$compoundConstructor->invoke($compoundSystem, array(
    array('slug' => 'primary', 'color' => '#2563eb'),
    array('slug' => 'accent', 'color' => '#1d4ed8'),
), array(), array(), array(), array(), array(), array(), 16.0, 1);
$compoundBinding = new PresetBinding($compoundSystem, array());
$compoundCss = '.a{--x:#1d4ed8 var(--pos);--stops:var(--a), #2563eb var(--b), var(--c);background-image:linear-gradient(135deg,#1d4ed8,#2563eb 60%,#000);--fade:#1d4ed800 var(--pos);background:url("#1d4ed8") #abcdef;--other:var(--missing,#1d4ed8) #abcdef}';
$compoundExpected = '.a{--x:var(--wp--preset--color--accent,#1d4ed8) var(--pos);--stops:var(--a), var(--wp--preset--color--primary,#2563eb) var(--b), var(--c);background-image:linear-gradient(135deg,var(--wp--preset--color--accent,#1d4ed8),var(--wp--preset--color--primary,#2563eb) 60%,#000);--fade:color-mix(in srgb, var(--wp--preset--color--accent,#1d4ed8) 0%, transparent) var(--pos);background:url("#1d4ed8") #abcdef;--other:var(--missing,#1d4ed8) #abcdef}';
$assert($compoundExpected === $compoundBinding->bindStylesheet($compoundCss), 'compound custom properties and gradient stops bind exact palette colours and translucent variants, leaving references, positions, URLs and other colours untouched');
$assert(5 === $compoundBinding->boundCount(), 'each bound compound colour token is counted once');
$assert('.a{background:linear-gradient(to right, var(--wp--preset--color--accent,rgb(29,78,216)), var(--wp--preset--color--primary,rgba(37,99,235,1)) 60%, #000)!important}' === $compoundBinding->bindStylesheet('.a{background:linear-gradient(to right, rgb(29,78,216), rgba(37,99,235,1) 60%, #000)!important}'), 'background gradients keep functional colours whole and preserve angles, stops and importance');
$assert('.a{--x:var(--be--source--preset--color--old) var(--wp--preset--color--accent,#1d4ed8)}' === $compoundBinding->bindStylesheet('.a{--x:var(--wp--preset--color--old) #1d4ed8}'), 'compound source reads stay source-owned while generated preset reads keep the WordPress namespace');

// A document whose root is 10px names its sizes in rem. A rule on the root
// element itself resolves rem, em and % against the initial 16px, so binding
// `html{font-size:10px}` to a `1rem` preset would scale every rem on the page
// by 1.6. The root keeps its literal; descendants still bind.
$tenPxUsage = $usage(array($row('#111111', 50, 'body', 10), $row('#111111', 30, 'body', 20)));
$tenPxUsage['root_font_size_px'] = 10;
$tenPxSystem = DesignSystem::fromUsage(array('a.html' => $tenPxUsage));
$tenPxSlug = (string) $tenPxSystem?->fontSizeSlug('10px');
$tenPxSheet = static fn(string $css): string => (new PresetBinding($tenPxSystem, array(array('kind' => 'css', 'content' => $css))))->bindStylesheet($css);
$assert('' !== $tenPxSlug && in_array('1rem', array_column($tenPxSystem?->fontSizes ?? array(), 'size'), true), 'a 10px root names its 10px size as 1rem (' . json_encode($tenPxSystem?->fontSizes) . ')');
$assert('html{font-size:10px}:root{font-size:10px}html[lang]:not(.x){font-size:10px}' === $tenPxSheet('html{font-size:10px}:root{font-size:10px}html[lang]:not(.x){font-size:10px}'), 'a root-element font size keeps its literal instead of a root-relative preset');
$assert('html .x{font-size:var(--wp--preset--font-size--' . $tenPxSlug . ',10px)}' === $tenPxSheet('html .x{font-size:10px}'), 'a descendant of the root still binds to the rem preset');
$assert('@media (min-width:40rem){html{font-size:10px}}' === $tenPxSheet('@media (min-width:40rem){html{font-size:10px}}'), 'a root font size inside a media query keeps its literal');
$assert('html,.x{font-size:10px}' === $tenPxSheet('html,.x{font-size:10px}'), 'a selector list that includes the root keeps its literal');
$assert(str_contains($sheet('html{font-size:1.5rem}'), '--wp--preset--font-size--large'), 'a root font size still binds to an absolute preset');

// Contrast is the readable main text colour on base, never a low-contrast one.
$contrastOf = static fn(array $text, array $root = array()): ?string => array_column(DesignSystem::fromUsage(array('a.html' => $usage($text, array(array('role' => 'root', 'color' => '#ffffff', 'authored' => null, 'count' => 1, 'weight' => 500)), $root)))?->colors ?? array(), 'color', 'role')['contrast'] ?? null;
$assert('#333333' === $contrastOf(array($row('#cccccc', 300), $row('#333333', 120))), 'a heavier text colour below WCAG AA on base is not contrast');
$assert('#555555' === $contrastOf(array($row('#111111', 100), $row('#555555', 150)), array('background' => '#ffffff', 'color' => '#555555')), 'the root text colour is contrast when it reads on base');
$assert('#111111' === $contrastOf(array($row('#111111', 100), $row('#bbbbbb', 150)), array('background' => '#ffffff', 'color' => '#bbbbbb')), 'a root text colour below WCAG AA on base is not contrast');
$assert(null === $contrastOf(array($row('#eeeeee', 100))), 'no colour is contrast when no text reads on base');
$assert(21.0 === round((float) CssColor::contrastRatio('#000', 'white'), 2) && 1.0 === CssColor::contrastRatio('#123456', '#123456') && null === CssColor::contrastRatio('currentColor', '#fff'), 'contrast ratios follow WCAG 2');

// Every route counts the same: one long route in another style cannot
// outweigh the shared shell and the other routes.
$routeUsage = static fn(string $text, string $background, int $weight): array => $usage(array($row($text, $weight, 'body', 16, $background), $row('#e9f1ff', 20, 'heading', 32, '#050b18')), array(array('role' => 'root', 'color' => $background, 'authored' => null, 'count' => 1, 'weight' => $weight + 20)));
$minority = DesignSystem::fromUsage(array('a.html' => $routeUsage('#e9f1ff', '#050b18', 100), 'b.html' => $routeUsage('#e9f1ff', '#050b18', 100), 'c.html' => $routeUsage('#1a1a1a', '#f3f4f6', 5000)));
$roles = array_column($minority?->colors ?? array(), 'color', 'role');
$assert('#050b18' === ($roles['base'] ?? null) && '#e9f1ff' === ($roles['contrast'] ?? null), 'a long route in another style does not take base and contrast from the majority of routes');

// A multi-route site whose header and footer are shared, with five dark
// marketing routes and one long light app route in a system font.
[$shellPlan, $shellTheme] = $compile(require dirname(__DIR__) . '/fixtures/design-system/tailwind-shell.php');
$shellPalette = array_column($shellTheme['settings']['color']['palette'] ?? array(), 'color', 'slug');
$assert('#050b18' === ($shellPalette['base'] ?? null) && '#e9f1ff' === ($shellPalette['contrast'] ?? null), 'tailwind-shell: base and contrast come from the shared shell and most routes (' . json_encode($shellPalette) . ')');
$assert((CssColor::contrastRatio($shellPalette['contrast'] ?? '', $shellPalette['base'] ?? '') ?? 0) >= DesignSystem::MIN_CONTRAST_RATIO, 'tailwind-shell: contrast reads on base');
$shellFamilies = array_column($shellTheme['settings']['typography']['fontFamilies'] ?? array(), 'name', 'slug');
$assert('Inter' === ($shellFamilies['body'] ?? null) && 'Space Grotesk' === ($shellFamilies['heading'] ?? null), 'tailwind-shell: body is the root family and heading the headings\' family (' . json_encode($shellFamilies) . ')');
$shellParts = implode('', array_column($shellPlan['template_parts'] ?? array(), 'canonical_block_markup'));
$assert(str_contains($shellParts, '"textColor":"contrast"') && str_contains($shellParts, '"backgroundColor":"base"'), 'tailwind-shell: shared template parts bind like page content');
$shellCss = implode('', array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), array_filter($shellPlan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null))));
$shellMuted = (string) array_search('#93a8ce', $shellPalette, true);
$assert('' !== $shellMuted && str_contains($shellCss, 'color:var(--wp--preset--color--' . $shellMuted . ',rgb(147 168 206 / var(--tw-text-opacity, 1)))'), 'tailwind-shell: the navigation and footer link colour class reads the palette');
$shellMetrics = $shellPlan['theme']['design_system']['metrics'] ?? array();
$capturedCss = strlen((string) ((require dirname(__DIR__) . '/fixtures/design-system/tailwind-shell.php')['files']['assets/site.css'] ?? ''));
$assert($capturedCss === ($shellMetrics['unique_source_css_bytes'] ?? null) && round(($shellMetrics['theme_css_bytes'] ?? 0) / $capturedCss, 4) === ($shellMetrics['generated_to_source_css_ratio'] ?? null), 'tailwind-shell: the CSS ratio is all shipped theme CSS over the captured site.css counted once, not its per-page copies (' . json_encode(array_intersect_key($shellMetrics, array_flip(array('theme_css_bytes', 'unique_source_css_bytes', 'engine_css_bytes')))) . ')');
$assert(($shellMetrics['restyle_coverage'] ?? 0) >= 0.8, 'tailwind-shell: at least 80% of rendered colours come from presets (' . json_encode($shellMetrics['restyle_coverage'] ?? null) . ')');
[$reversedShell] = $compile(array_replace(require dirname(__DIR__) . '/fixtures/design-system/tailwind-shell.php', array('files' => array_reverse((require dirname(__DIR__) . '/fixtures/design-system/tailwind-shell.php')['files'], true))));
$assert(($shellPlan['theme']['design_system'] ?? null) === ($reversedShell['theme']['design_system'] ?? null), 'tailwind-shell: route order does not change the design system');

// Six greys each written two indistinguishable ways, all on long paragraphs,
// a cyan accent that only marks many small elements through a custom
// property, and an amber and a rose heading painted once each. Slots past
// the roles go to painted colours by their own painted weight, whatever
// family they rank in: the accent and the heavy grey twins keep tokens, and
// the lightest painted colours (the two one-off headings among them) keep
// their literal and are reported; a literal never binds to a near token.
[$accentPlan, $accentTheme] = $compile(require dirname(__DIR__) . '/fixtures/design-system/near-duplicate-neutrals-small-accent.php');
$accentPalette = array_column($accentTheme['settings']['color']['palette'] ?? array(), 'color', 'slug');
$cyan = (string) array_search('#22d3ee', $accentPalette, true);
$accentSystem = $accentPlan['theme']['design_system'] ?? array();
$accentLiterals = $accentSystem['literal_colors'] ?? array();
$assert(1 === preg_match($roleSlug, $cyan) && 12 === count($accentPalette) && array() === array_intersect($accentLiterals, $accentPalette), 'near-duplicate-neutrals-small-accent: the accent on many small elements keeps a token and the palette is full (' . json_encode($accentPalette) . ')');
$assert(array() === array_diff(array('#b45309', '#be123c'), $accentLiterals) && 6 === count($accentLiterals) && 6 === ($accentSystem['metrics']['literal_color_count'] ?? null), 'near-duplicate-neutrals-small-accent: the lightest painted colours, including the one-off headings, are the ones left literal and reported (' . json_encode($accentLiterals) . ')');
$accentMarkup = implode('', array_column($accentPlan['pages'], 'canonical_block_markup'));
$classColors = array('g1' => '#1a1a1a', 'g1b' => '#212121', 'g2' => '#3a3a3a', 'g2b' => '#424242', 'g3' => '#5c5c5c', 'g3b' => '#646464', 'g4' => '#7d7d7d', 'g4b' => '#858585', 'g5' => '#9e9e9e', 'g5b' => '#a6a6a6', 'g6' => '#c4c4c4', 'g6b' => '#cbcbcb');
$literalBindings = array();
foreach ($classColors as $class => $hex) {
    preg_match('/"className":"' . $class . '","textColor":"([a-z0-9-]+)"/', $accentMarkup, $bound);
    $expected = in_array($hex, $accentLiterals, true) ? null : (string) array_search($hex, $accentPalette, true);
    if (($bound[1] ?? null) !== $expected) $literalBindings[$class] = array('expected' => $expected, 'bound' => $bound[1] ?? null);
}
$assert(array() === $literalBindings, 'near-duplicate-neutrals-small-accent: each grey binds to the token of its exact value, and a grey with no token keeps its literal (' . json_encode($literalBindings) . ')');
$accentCss = implode('', array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), array_filter($accentPlan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null))));
$assert(str_contains($accentCss, '--cyan:var(--wp--preset--color--' . $cyan . ',#22d3ee)') && 1 === preg_match('/\\.dot[^{]*\\{color:var\\(--wp--preset--color--' . $cyan . ',var\\(--cyan\\)\\)/', $accentCss), 'near-duplicate-neutrals-small-accent: the authored --cyan custom property reads the accent token, so every rule using it follows the palette');

// A heavy near shade of `base` (white cards on a `#f7fafc` page) outranks
// two distinct colours each painted on one tiny element: slots past the
// roles go by each colour's own painted weight, so white keeps a token of
// its exact value and the two barely used colours are the ones left literal.
[$heavyPlan, $heavyTheme] = $compile(require dirname(__DIR__) . '/fixtures/design-system/heavy-near-shade-tiny-accents.php');
$heavyPalette = array_column($heavyTheme['settings']['color']['palette'] ?? array(), 'color', 'slug');
$heavySystem = $heavyPlan['theme']['design_system'] ?? array();
$heavyLiterals = $heavySystem['literal_colors'] ?? array();
sort($heavyLiterals);
$assert('#f7fafc' === ($heavyPalette['base'] ?? null) && 12 === count($heavyPalette) && (CssColor::distance('#ffffff', '#f7fafc') ?? INF) <= DesignSystem::COLOR_CONSOLIDATION_DISTANCE, 'heavy-near-shade-tiny-accents: the palette is full and white is a near shade of base (' . json_encode($heavyPalette) . ')');
$assert(1 === preg_match($roleSlug, (string) array_search('#ffffff', $heavyPalette, true)), 'heavy-near-shade-tiny-accents: the heavily painted near shade of base keeps a token of its own exact value (' . json_encode($heavyPalette) . ')');
$assert(array('#34d399', '#80a690') === $heavyLiterals && 2 === ($heavySystem['metrics']['literal_color_count'] ?? null), 'heavy-near-shade-tiny-accents: the colours painted on one tiny element each are the ones left literal (' . json_encode($heavySystem['literal_colors'] ?? null) . ')');
$heavyMarkup = implode('', array_column($heavyPlan['pages'], 'canonical_block_markup'));
preg_match_all('/<!-- wp:group (\{.*?\}) -->/', $heavyMarkup, $heavyGroups);
$cardColors = array();
foreach ($heavyGroups[1] as $json) if ('card' === (json_decode($json, true)['className'] ?? null)) $cardColors[] = $heavyPalette[(string) (json_decode($json, true)['backgroundColor'] ?? '')] ?? null;
$assert(8 === count($cardColors) && array('#ffffff') === array_values(array_unique($cardColors)), 'heavy-near-shade-tiny-accents: every white card binds to the white token, never to base (' . json_encode($cardColors) . ')');

// A blog index of many small system-font titles does not name the heading
// role over the display h1/h2 every other route opens with: each route's
// headings share one weight, and a heading weighs by its level and size.
[, $blogTheme] = $compile(require dirname(__DIR__) . '/fixtures/design-system/blog-list-headings.php');
$blogFamilies = array_column($blogTheme['settings']['typography']['fontFamilies'] ?? array(), 'name', 'slug');
$assert('Fraunces' === ($blogFamilies['heading'] ?? null) && 'Inter' === ($blogFamilies['body'] ?? null), 'blog-list-headings: the display family of the page titles is heading, not the small list titles\' family (' . json_encode($blogFamilies) . ')');

// Element styles come from what every link, button and heading renders,
// with `:hover` where the state rule gives a token. A button whose hover
// rule a preset class would mask renders through the element style, so it
// drops its duplicate literal colours instead of being refused.
[$elementPlan, $elementTheme] = $compile(require dirname(__DIR__) . '/fixtures/design-system/element-styles.php');
$elementSlug = array_flip(array_column($elementTheme['settings']['color']['palette'] ?? array(), 'color', 'slug'));
$preset = static fn(string $hex): string => 'var:preset|color|' . ($elementSlug[$hex] ?? '?');
$elements = $elementTheme['styles']['elements'] ?? array();
$assert($preset('#0f766e') === ($elements['link']['color']['text'] ?? null) && $preset('#134e4a') === ($elements['link'][':hover']['color']['text'] ?? null), 'element-styles: link colour and hover colour are palette references (' . json_encode($elements['link'] ?? null) . ')');
$assert($preset('#ffffff') === ($elements['button']['color']['text'] ?? null) && $preset('#0f766e') === ($elements['button']['color']['background'] ?? null) && $preset('#134e4a') === ($elements['button'][':hover']['color']['background'] ?? null) && '600' === ($elements['button']['typography']['fontWeight'] ?? null), 'element-styles: button colours, hover background and weight project (' . json_encode($elements['button'] ?? null) . ')');
$assert($preset('#134e4a') === ($elements['heading']['color']['text'] ?? null) && 'var:preset|font-family|heading' === ($elements['heading']['typography']['fontFamily'] ?? null) && '700' === ($elements['heading']['typography']['fontWeight'] ?? null), 'element-styles: heading colour, family and weight project (' . json_encode($elements['heading'] ?? null) . ')');
// An element style outranks author element rules (`:root :where(h1)`), so a
// usage-derived value never fills a property an author stylesheet sets to
// another value: here a selectable `light` set makes `h1` weight 300.
[, $activationTheme] = $compile(require dirname(__DIR__) . '/fixtures/stylesheet-activation.php');
$activationElements = $activationTheme['styles']['elements'] ?? array();
$assert(!isset($activationElements['heading']['typography']['fontWeight']) && !isset($activationElements['h1']['typography']['fontWeight']), 'element-styles: a heading weight a selectable stylesheet varies stays source-owned (' . json_encode($activationElements) . ')');
$assert(str_starts_with((string) ($elements['h1']['typography']['fontSize'] ?? ''), 'var:preset|font-size|') && ($elements['h1']['typography']['fontSize'] ?? null) !== ($elements['h3']['typography']['fontSize'] ?? null), 'element-styles: heading levels that differ in size get their own size preset');
$elementMarkup = implode('', array_column($elementPlan['pages'], 'canonical_block_markup'));
$assert(2 === preg_match_all('/<!-- wp:button \{(?![^}]*"color")(?![^}]*Color")[^>]*--><div [^>]*><a class="wp-block-button__link wp-element-button" style="(?![^"]*color)[^"]*"/', $elementMarkup), 'element-styles: buttons render through the element style without duplicate literal colours');
$elementMetrics = $elementPlan['theme']['design_system']['metrics'] ?? array();
$assert(0 === ($elementMetrics['refusal_reasons']['state_variant'] ?? null) && 1.0 === (float) ($elementMetrics['restyle_coverage'] ?? 0), 'element-styles: no state-variant refusals remain (' . json_encode($elementMetrics['refusal_reasons'] ?? null) . ')');
// A unanimous heading colour is an element default only when it reads on
// the theme's root background. Every H1 here sits in a dark section that
// sets `color:#fff` on a light page: white stays on the section, which each
// H1 inherits, so the painted colour is unchanged. Dark H1s on the light
// page (the control) keep their element colour.
$darkHero = require dirname(__DIR__) . '/fixtures/design-system/dark-hero-headings.php';
foreach (array('dark-hero-headings' => false, 'dark-hero-headings control' => true) as $label => $darkHeadings) {
    [$heroPlan, $heroTheme] = $compile($darkHero($darkHeadings));
    $heroPalette = array_column($heroTheme['settings']['color']['palette'] ?? array(), 'color', 'slug');
    $heroElements = $heroTheme['styles']['elements'] ?? array();
    $assert('var:preset|color|base' === ($heroTheme['styles']['color']['background'] ?? null) && '#f6f8fa' === ($heroPalette['base'] ?? null), "$label: the root background is the light base (" . json_encode($heroTheme['styles']['color'] ?? null) . ')');
    $blockColor = static function (array $attributes) use ($heroPalette): ?string {
        if (isset($attributes['textColor'])) return $heroPalette[(string) $attributes['textColor']] ?? '?';
        return isset($attributes['style']['color']['text']) ? CssColor::hex((string) $attributes['style']['color']['text']) : null;
    };
    $h1Colors = array();
    foreach ($heroPlan['pages'] as $heroPage) {
        if (!preg_match_all('/<!-- wp:group (\{(?:(?!-->)[^\n])*\}) --><section[^>]*>(?:(?!<!-- wp:).)*?<!-- wp:heading (\{[^\n]*?"level":1[^\n]*?\}) -->/s', (string) $heroPage['canonical_block_markup'], $heroMatches, PREG_SET_ORDER)) continue;
        foreach ($heroMatches as $match) {
            $heading = json_decode($match[2], true);
            $h1Colors[] = $blockColor(is_array($heading) ? $heading : array()) ?? $blockColor((array) json_decode($match[1], true));
        }
    }
    if ($darkHeadings) {
        $assert('var:preset|color|' . array_search('#0b1f33', $heroPalette, true) === ($heroElements['h1']['color']['text'] ?? null), "$label: a unanimous H1 colour that reads on the root background stays an element style (" . json_encode($heroElements['h1'] ?? null) . ')');
        $assert(array('#0b1f33', '#0b1f33', '#0b1f33') === $h1Colors, "$label: each H1 still renders its dark colour (" . json_encode($h1Colors) . ')');
        continue;
    }
    $assert(!isset($heroElements['h1']['color']) && !isset($heroElements['heading']['color']), "$label: white, unreadable on the light root background, is not an h1 or heading element colour (" . json_encode($heroElements) . ')');
    $assert(array('#ffffff', '#ffffff', '#ffffff') === $h1Colors, "$label: each H1 still renders #fff through its block or its section (" . json_encode($h1Colors) . ')');
}

// A size a breakpoint changes stays with the source: an element style
// would override the responsive variant.
[, $v4Theme] = $compile(require dirname(__DIR__) . '/fixtures/design-system/tailwind-v4.php');
$assert(!isset($v4Theme['styles']['elements']['h1']['typography']['fontSize']), 'tailwind-v4: the responsive h1 size is not an element style');

// Root styles name roles, and restyle coverage is reported for every fixture.
foreach ($plans as $fixture => [$plan, $theme]) {
    $styles = $theme['styles'] ?? array();
    $assert(!isset($styles['color']['text']) || 'var:preset|color|contrast' === $styles['color']['text'], "$fixture: root text colour is the contrast role");
    $assert(!isset($styles['color']['background']) || 'var:preset|color|base' === $styles['color']['background'], "$fixture: root background is the base role");
    $assert(!isset($styles['elements']['heading']['typography']['fontFamily']) || 'var:preset|font-family|heading' === $styles['elements']['heading']['typography']['fontFamily'], "$fixture: heading family is the heading role");
    $coverage = $plan['theme']['design_system']['metrics']['restyle_coverage'] ?? null;
    // Exact binding keeps every rendered value; painted shades take tokens
    // of their own, so only colours past the palette bound keep literals.
    $assert(is_float($coverage) && $coverage >= 0.7 && $coverage <= 1.0, "$fixture: at least 70% of rendered colours come from presets (" . json_encode($coverage) . ')');
}

// Headings in a display webfont over a system-stack body and interface:
// `heading` is the webfont, though one route's minor headings stay in the
// system stack, and the headings that render it bind to it.
$displayFixture = require dirname(__DIR__) . '/fixtures/design-system/system-stack-display-headings.php';
[$displayPlan, $displayTheme] = $compile($displayFixture);
$displayFamilies = array_column($displayTheme['settings']['typography']['fontFamilies'] ?? array(), 'fontFamily', 'slug');
$assert(str_contains((string) ($displayFamilies['heading'] ?? ''), 'Fraunces') && str_starts_with((string) ($displayFamilies['body'] ?? ''), '-apple-system'), 'system-stack-display-headings: heading is the webfont headings render, body the system stack (' . json_encode($displayFamilies) . ')');
$displayMarkup = implode('', array_column($displayPlan['pages'], 'canonical_block_markup'));
$assert(2 === preg_match_all('/<!-- wp:heading \{"className":"font-display[^}]*"fontFamily":"heading"\} --><h[12] class="[^"]*has-heading-font-family/', implode('', array_column(array_slice($displayPlan['pages'], 0, 1), 'canonical_block_markup'))) && !preg_match('/<!-- wp:heading \{"className":"text-xl","level":3[^}]*"fontFamily"/', $displayMarkup), 'system-stack-display-headings: display headings bind to the heading family; system-stack headings do not');
$assert(!isset($displayTheme['styles']['elements']['heading']['typography']['fontFamily']), 'system-stack-display-headings: the heading element style is not set while some headings render another family');
$unanimous = $displayFixture;
$unanimous['files']['contact.html'] = str_replace('<h3 class="text-xl">', '<h3 class="font-display text-xl">', $unanimous['files']['contact.html']);
[, $unanimousTheme] = $compile($unanimous);
$assert('var:preset|font-family|heading' === ($unanimousTheme['styles']['elements']['heading']['typography']['fontFamily'] ?? null) && str_contains((string) (array_column($unanimousTheme['settings']['typography']['fontFamilies'] ?? array(), 'fontFamily', 'slug')['heading'] ?? ''), 'Fraunces'), 'every heading in the webfont: the heading element style reads the heading preset');
$headingOnly = DesignSystem::fromUsage(array('a.html' => $usage(array(
    array('role' => 'body', 'color' => '#111111', 'font_family' => '-apple-system,BlinkMacSystemFont,sans-serif', 'font_size_px' => 16, 'background' => '#ffffff', 'count' => 1, 'weight' => 900),
    array('role' => 'heading', 'color' => '#111111', 'font_family' => '-apple-system,BlinkMacSystemFont,sans-serif', 'font_size_px' => 20, 'background' => '#ffffff', 'count' => 3, 'weight' => 60),
    array('role' => 'heading', 'color' => '#111111', 'font_family' => '"Fraunces",serif', 'font_size_px' => 30, 'background' => '#ffffff', 'count' => 1, 'weight' => 40),
))));
$assert('heading' === $headingOnly?->fontFamilySlug('Fraunces, serif') && 'body' === $headingOnly?->fontFamilySlug('-apple-system, sans-serif'), 'a webfont on a share of the headings is heading even when more heading text is in the system stack');

// Inline colours in rich text become core's text-colour format.
$displayFirst = (string) ($displayPlan['pages'][0]['canonical_block_markup'] ?? '');
$assert(3 === substr_count($displayFirst, '<mark style="background-color:rgba(0, 0, 0, 0)') && 3 === substr_count($displayFirst, 'class="has-inline-color has-primary-color') && !str_contains($displayFirst, 'color:#38bdf8'), 'system-stack-display-headings: class, inline-style and authored-mark colours become the text-colour format with no literal left');
$assert(str_contains($displayFirst, 'class="has-inline-color has-primary-color text-sky"') && str_contains($displayFirst, '>a highlighted phrase</mark>') && str_contains($displayFirst, '>a marked one</mark>'), 'system-stack-display-headings: the format keeps the text and the author classes');
$runtime = new Runtime();
$assert($displayFirst === $runtime->serializeBlocks($runtime->parseBlocks($displayFirst)) && 'pass' === ($runtime->validateBlockSerialization($displayFirst)['status'] ?? null), 'system-stack-display-headings: the bound page survives parse_blocks/serialize_blocks and validates');
$inlineBinding = new PresetBinding($bindSystem, array());
$inline = static fn(string $content): string => '<!-- wp:paragraph --><p>' . $content . '</p><!-- /wp:paragraph -->';
$assert($inline('A <mark style="background-color:rgba(0, 0, 0, 0)" class="has-inline-color has-' . $slug . '-color x" role="none">b</mark>') === $inlineBinding->bind($inline('A <span class="x" style="color:#e11d48" role="none">b</span>')), 'a token-coloured span becomes the text-colour format mark');
$assert($inline('<mark style="background-color:#ffffff" class="has-inline-color has-' . $slug . '-color">b</mark>') === $inlineBinding->bind($inline('<mark style="color:#e11d48;background-color:#ffffff">b</mark>')), 'an inline background stays a literal, as core keeps it');
foreach (array(
    'it is a link' => '<a href="/x" style="color:#e11d48">b</a>',
    'it has other attributes' => '<mark id="m" style="color:#e11d48">b</mark>',
    'it is translucent' => '<mark style="color:rgb(225 29 72 / .5)">b</mark>',
    'it is not a token' => '<mark style="color:#00ff00">b</mark>',
) as $why => $content) $assert($inline($content) === $inlineBinding->bind($inline($content)), "an inline colour is left alone when $why");
$assert($inline('<span style="color:#e11d48">b</span>') === (new PresetBinding($bindSystem, array(array('kind' => 'css', 'content' => 'mark{padding:2px}'))))->bind($inline('<span style="color:#e11d48">b</span>')), 'a span is not made a mark that an author mark rule would restyle');
$assert($inline('<mark style="color:#e11d48">b</mark>') === (new PresetBinding($bindSystem, array(array('kind' => 'css', 'content' => 'mark{color:red!important}'))))->bind($inline('<mark style="color:#e11d48">b</mark>')), 'an !important mark colour keeps the inline literal');

// The format replaces the authored inline element; it never wraps it. A
// conversion carrier whose whole content is that element hands it its
// marker and letter spacing, and only the element binds, to its own token.
[$nestedPlan, $nestedTheme] = $compile(require dirname(__DIR__) . '/fixtures/design-system/nested-inline-accent.php');
$nestedSlug = (string) array_search('#35c9f0', array_column($nestedTheme['settings']['color']['palette'] ?? array(), 'color', 'slug'), true);
$nestedMarkup = implode('', array_column($nestedPlan['pages'], 'canonical_block_markup'));
$assert('' !== $nestedSlug && 2 === preg_match_all('/<mark style="background-color:rgba\(0, 0, 0, 0\);background:transparent;letter-spacing:\.2em;--blocks-engine-richtext-marker:blocks-engine-richtext-[a-f0-9]+-0" class="has-inline-color has-' . $nestedSlug . '-color text-\[color:var\(--cyan\)\] blocks-engine-role-richtext blocks-engine-subject [^"]*">(?:Studio|Process)<\/mark>/', $nestedMarkup), 'nested-inline-accent: each highlighted word is one mark carrying the token it renders (' . $nestedSlug . ')');
$assert(!preg_match('/style="[^"]*(?<![-\w])color:var\(--cyan\)/', $nestedMarkup) && !preg_match('/<mark[^>]*><mark[^>]*>(?:Studio|Process)/', $nestedMarkup), 'nested-inline-accent: no literal colour and no wrapping mark is left on the bound words');
$assert(1 === preg_match('/<mark class="blocks-engine-role-richtext blocks-engine-subject [^"]*" style="color:#93a8ce;letter-spacing:\.2em;--blocks-engine-richtext-marker:[^"]*"><mark class="text-\[color:var\(--glow\)\]" style="[^"]*color:var\(--glow\)[^"]*">Soon<\/mark><\/mark>/', $nestedMarkup) && 1 === ($nestedPlan['theme']['design_system']['metrics']['refusal_reasons']['unresolved_value'] ?? null), 'nested-inline-accent: a word whose colour is no token keeps its markup and counts one refusal');
$carrier = static fn(string $inner): string => $inline('<mark style="color:#111111;letter-spacing:.2em;--blocks-engine-richtext-marker:blocks-engine-richtext-0a-0">' . $inner . '</mark>');
$assert($inline('<mark style="background-color:rgba(0, 0, 0, 0);letter-spacing:.2em;--blocks-engine-richtext-marker:blocks-engine-richtext-0a-0" class="has-inline-color has-' . $slug . '-color x">b</mark>') === $inlineBinding->bind($carrier('<mark class="x" style="color:#e11d48">b</mark>')), 'a carrier around one token-coloured mark becomes that mark, in the format, with the carrier\'s marker');
$assert($carrier('<mark style="color:#e11d48">b</mark> c') !== $inlineBinding->bind($carrier('<mark style="color:#e11d48">b</mark> c')) && str_contains($inlineBinding->bind($carrier('<mark style="color:#e11d48">b</mark> c')), 'has-contrast-color'), 'a carrier with other text keeps its own colour, which that text renders');

// Translucent colours bind in stylesheets as the token mixed with transparency.
$assert('.a{color:color-mix(in srgb, var(--wp--preset--color--' . $slug . ',#e11d48) 50%, transparent)}' === $sheet('.a{color:rgb(225 29 72 / .5)}'), 'a translucent token colour binds as color-mix() with the token');
$assert('.a{color:rgb(225 29 72 / .333)}' === $sheet('.a{color:rgb(225 29 72 / .333)}') && '.a{color:rgb(0 255 0 / .5)}' === $sheet('.a{color:rgb(0 255 0 / .5)}'), 'an alpha that is not a whole percentage, or a colour that is no token, keeps its bytes');
$assert(str_contains((string) json_encode(array_column($displayPlan['assets'], 'content')), 'color-mix(in srgb, var(--wp--preset--color--contrast,#f5f1e8) 70%, transparent)'), 'system-stack-display-headings: the translucent navigation link colour reads the contrast token');

// Navigation and button labels bind through the attributes core supports.
$roseNav = static fn(string $second): string => '<!-- wp:navigation {"className":"menu"} --><!-- wp:navigation-link {"className":"text-rose","label":"A","url":"/a","kind":"custom"} /-->' . $second . '<!-- /wp:navigation -->';
$navBinding = new PresetBinding($bindSystem, array(), $classUsage(array(array('classes' => 'text-rose', 'properties' => array('color' => array('#e11d48'))))));
$assert(str_starts_with($navBinding->bind($roseNav('<!-- wp:navigation-link {"metadata":{"blocksEngineNavigationAnchor":{"style":"","className":"text-rose"}},"label":"B","url":"/b","kind":"custom"} /-->')), '<!-- wp:navigation {"className":"menu","textColor":"' . $slug . '"} -->'), 'a navigation whose links all render one token carries it as textColor');
$assert($roseNav('<!-- wp:navigation-link {"label":"B","url":"/b","kind":"custom"} /-->') === $navBinding->bind($roseNav('<!-- wp:navigation-link {"label":"B","url":"/b","kind":"custom"} /-->')), 'a navigation with a link that inherits its colour is left alone');
$assert(str_contains((string) ($shellParts ?? ''), '"textColor":"' . $shellMuted . '","ref"'), 'tailwind-shell: the footer navigation carries its links\' token as textColor');
$button = '<!-- wp:button {"className":"text-rose"} --><div class="wp-block-button text-rose"><a class="wp-block-button__link wp-element-button">Go</a></div><!-- /wp:button -->';
$assert('<!-- wp:button {"className":"text-rose","textColor":"' . $slug . '"} --><div class="wp-block-button text-rose"><a class="wp-block-button__link has-text-color has-' . $slug . '-color wp-element-button">Go</a></div><!-- /wp:button -->' === (new PresetBinding($bindSystem, array(), $roseUsage))->bind($button) || str_contains((new PresetBinding($bindSystem, array(), $roseUsage))->bind($button), '"textColor":"' . $slug . '"'), 'a button label coloured by an author class carries the textColor preset');

// Values binding refuses are reported by reason.
$refusalsOf = static fn(array $markups, array $assets = array(), array $rows = array()): array => array_filter((new PresetBinding($bindSystem, $assets, $classUsage($rows)))->refusals($markups));
$assert(array('background_padding' => 1) === $refusalsOf(array($bgParagraph), array(), $bgRows(array())), 'the core padding refusal is counted as background_padding');
$para = static fn(string $attributes, string $style): string => '<!-- wp:paragraph ' . $attributes . ' --><p class="has-text-color" style="' . $style . '">Hi</p><!-- /wp:paragraph -->';
$assert(array('no_matching_token' => 1) === $refusalsOf(array($para('{"style":{"color":{"text":"#00ff00"}}}', 'color:#00ff00'))), 'a colour no token represents is refused as no_matching_token');
$assert(array('alpha' => 1) === $refusalsOf(array($para('{"style":{"color":{"text":"rgba(225,29,72,0.5)"}}}', 'color:rgba(225,29,72,0.5)'))), 'a translucent block colour is refused as alpha');
$assert(array('important' => 1) === $refusalsOf(array($plain), array(array('kind' => 'css', 'content' => '.text-rose{color:#e11d48!important}')), $roseUsage['a.html']['class_styles']), 'an !important rule is refused as important');
$assert(array('state_variant' => 1) === $refusalsOf(array($plain), array(array('kind' => 'css', 'content' => '.text-rose:hover{color:#111}')), $roseUsage['a.html']['class_styles']), 'a hover rule is refused as state_variant');
// An attribute-only state rule for inputs cannot reach navy sections (#3042),
// whatever their descendants carry; a state rule that can match still refuses.
$navySystem = DesignSystem::fromUsage(array('a.html' => $usage(array($row('#ffffff', 50, 'body', 16, '#0b1f3a')), array(array('role' => 'section', 'color' => '#0b1f3a', 'authored' => '#0b1f3a', 'count' => 3, 'weight' => 90), array('role' => 'root', 'color' => '#ffffff', 'authored' => '#fff', 'count' => 1, 'weight' => 70)))));
$navy = (string) $navySystem?->colorSlug('#0b1f3a');
$navySection = static fn(string $attributes = ''): string => '<!-- wp:group {"tagName":"section","style":{"color":{"background":"#0b1f3a"},"spacing":{"padding":{"top":"2rem","right":"2rem","bottom":"2rem","left":"2rem"}}}} --><section class="wp-block-group has-background"' . $attributes . ' style="background-color:#0b1f3a;padding-top:2rem;padding-right:2rem;padding-bottom:2rem;padding-left:2rem"><!-- wp:html --><input type="checkbox"><!-- /wp:html --></section><!-- /wp:group -->';
$navyBind = static fn(string $css, string $attributes = ''): string => (new PresetBinding($navySystem, array(array('kind' => 'css', 'content' => $css))))->bind(str_repeat($navySection($attributes), 3));
$checkboxCss = '[type="checkbox"]:indeterminate:hover,[type="checkbox"]:indeterminate:focus{background-color:currentcolor}';
$assert('' !== $navy && 3 === substr_count($navyBind($checkboxCss), '"backgroundColor":"' . $navy . '"'), 'an attribute-only checkbox state rule does not block navy sections from the palette slug (' . $navy . ')');
$assert(str_contains($navyBind($checkboxCss, ' data-type="checkbox"'), '"backgroundColor":"' . $navy . '"') && !str_contains($navyBind('[data-kind="hero"]:hover{background-color:#000}', ' data-kind="hero"'), '"backgroundColor"'), 'only the section\'s own attributes count: a state rule its start tag satisfies refuses, a near-miss attribute does not');
$assert(!str_contains($navyBind('section:hover{background-color:#000}'), '"backgroundColor"') && !str_contains($navyBind(':hover{background-color:#000}'), '"backgroundColor"') && !str_contains($navyBind('[type="checkbox"]:hover,section:focus{background-color:#000}'), '"backgroundColor"'), 'a tag or universal state rule that can match the section still refuses');
$assert(str_contains($navyBind('a:hover{background-color:#000}'), '"backgroundColor":"' . $navy . '"'), 'a state rule for another tag does not block the section');
$assert(array('breakpoint_variant' => 1) === $refusalsOf(array($plain), array(), array(array('classes' => 'text-rose', 'properties' => array('color' => array('#e11d48;#111111'))))) && array('breakpoint_variant' => 1) === $refusalsOf(array($plain), array(), array(array('classes' => 'text-rose', 'properties' => array('color' => array('!breakpoint'))))), 'a breakpoint that changes the token is refused as breakpoint_variant');
$assert(array('state_variant' => 1, 'alpha' => 1, 'important' => 1) == $refusalsOf(array($plain, str_replace('text-rose', 'text-a', $plain), str_replace('text-rose', 'text-b', $plain)), array(), array(array('classes' => 'text-rose', 'properties' => array('color' => array('!state'))), array('classes' => 'text-a', 'properties' => array('color' => array('!alpha'))), array('classes' => 'text-b', 'properties' => array('color' => array('!important'))))), 'collector observations carry their refusal reason');
$assert(array('unsupported_block' => 1) === $refusalsOf(array('<!-- wp:cover {"style":{"color":{"text":"#e11d48"}}} --><div class="wp-block-cover"></div><!-- /wp:cover -->')), 'a literal colour on a block binding does not rewrite is refused as unsupported_block');
$assert(array('inline_format_unsupported' => 1) === $refusalsOf(array($inline('<a href="/x" style="color:#e11d48">b</a>'))), 'a link colour in rich text is refused as inline_format_unsupported');
$assert(array() === $refusalsOf(array($inlineBinding->bind($inline('<mark style="color:#e11d48">b</mark>')), $classBound)), 'bound values are not refusals');
$displayMetrics = $displayPlan['theme']['design_system']['metrics'] ?? array();
$assert(PresetBinding::REFUSAL_REASONS === array_keys($displayMetrics['refusal_reasons'] ?? array()) && ($displayMetrics['refusal_reasons']['alpha'] ?? 0) > 0 && array_sum($displayMetrics['refusal_reasons']) === ($displayMetrics['refused_value_count'] ?? null), 'system-stack-display-headings: metrics break refusals down by every reason (' . json_encode($displayMetrics['refusal_reasons'] ?? null) . ')');
$assert(1.0 === ($displayMetrics['restyle_coverage'] ?? null), 'system-stack-display-headings: every rendered colour follows the palette (' . json_encode($displayMetrics['restyle_coverage'] ?? null) . ')');

// Issue #3043: a preset binding never changes a painted colour. Every colour
// a binding produced, with the source value it replaced: stylesheet
// declarations read `var(--wp--preset--color--slug,source)`, block colour
// attributes replace the colour their class rendered ($sourceByClass), and
// theme.json root and element styles replace the value every document
// rendered ($sourceStyles, keyed by path).
$colorBindings = static function (array $plan, array $theme, array $sourceByClass = array(), array $sourceStyles = array()): array {
    $palette = array_column($theme['settings']['color']['palette'] ?? array(), 'color', 'slug');
    $bindings = array();
    $resolve = static function (string $value): ?string {
        // `var(--x, fallback)` renders its fallback when nothing sets --x.
        for ($depth = 0; $depth < 5 && preg_match('/var\(\s*--[A-Za-z0-9_-]+\s*,\s*([^()]*)\)/', $value); $depth++) $value = (string) preg_replace('/var\(\s*--[A-Za-z0-9_-]+\s*,\s*([^()]*)\)/', '$1', $value);
        return CssColor::hex(trim($value));
    };
    foreach ($plan['assets'] ?? array() as $asset) {
        if ('css' !== ($asset['kind'] ?? null)) continue;
        $css = (string) ($asset['content'] ?? '');
        $offset = 0;
        while (false !== ($at = strpos($css, 'var(--wp--preset--color--', $offset))) {
            $depth = 0;
            for ($end = $at; $end < strlen($css); $end++) {
                if ('(' === $css[$end]) $depth++;
                if (')' === $css[$end] && 0 === --$depth) break;
            }
            $inner = substr($css, $at + 4, $end - $at - 4);
            [$property, $fallback] = array_pad(explode(',', $inner, 2), 2, '');
            $slug = substr($property, strlen('--wp--preset--color--'));
            $bindings[] = array('where' => 'stylesheet ' . substr($css, max(0, $at - 40), $end - $at + 41), 'preset' => $palette[$slug] ?? null, 'source' => $resolve($fallback));
            $offset = $end;
        }
    }
    $markups = array_column(array_merge($plan['pages'] ?? array(), $plan['template_parts'] ?? array(), $plan['templates'] ?? array()), 'canonical_block_markup');
    foreach ($plan['menus'] ?? array() as $menu) $markups[] = (string) ($menu['block_markup'] ?? '');
    foreach ($markups as $markup) {
        preg_match_all('/<!-- wp:([a-z0-9\/-]+) (\{.*?\}) \/?-->/s', (string) $markup, $blocks, PREG_SET_ORDER);
        foreach ($blocks as [, $name, $json]) {
            $attributes = json_decode($json, true);
            if (!is_array($attributes)) continue;
            $classes = preg_split('/\s+/', (string) ($attributes['className'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: array();
            foreach (array('textColor' => 'text', 'backgroundColor' => 'background') as $key => $property) {
                if (!is_string($attributes[$key] ?? null)) continue;
                $source = null;
                foreach ($classes as $class) $source ??= $sourceByClass[$class][$property] ?? null;
                $bindings[] = array('where' => "$name.$key " . implode(' ', $classes), 'preset' => $palette[$attributes[$key]] ?? null, 'source' => null === $source ? null : CssColor::hex($source));
            }
        }
    }
    $walk = static function (mixed $node, string $path) use (&$walk, &$bindings, $palette, $sourceStyles): void {
        if (is_string($node) && str_starts_with($node, 'var:preset|color|')) $bindings[] = array('where' => 'theme.json ' . $path, 'preset' => $palette[substr($node, strlen('var:preset|color|'))] ?? null, 'source' => isset($sourceStyles[$path]) ? CssColor::hex($sourceStyles[$path]) : null);
        if (is_array($node)) foreach ($node as $key => $child) $walk($child, '' === $path ? (string) $key : $path . '.' . $key);
    };
    $walk($theme['styles'] ?? array(), '');
    return $bindings;
};
$repainted = static fn(array $bindings): array => array_values(array_filter($bindings, static fn(array $binding): bool => null === $binding['preset'] || $binding['preset'] !== $binding['source']));

// (a) The reduced fixture: the `#ffffff` card and the `#f6f8fa` surfaces
// both bind, each to a token whose value is its own colour.
[$surfacePlan, $surfaceTheme] = $compile(require dirname(__DIR__) . '/fixtures/design-system/painted-surface-shades.php');
$surfacePalette = array_column($surfaceTheme['settings']['color']['palette'] ?? array(), 'color', 'slug');
$surfaceMarkup = implode('', array_column($surfacePlan['pages'] ?? array(), 'canonical_block_markup'));
$boundTo = static function (string $class, string $markup): array {
    preg_match_all('/<!-- wp:group (\{[^\n]*?\}) -->/', $markup, $groups);
    $slugs = array();
    foreach ($groups[1] as $json) {
        $attributes = json_decode($json, true);
        if ($class === ($attributes['className'] ?? null)) $slugs[] = $attributes['backgroundColor'] ?? null;
    }
    return $slugs;
};
$surfaceSlugs = $boundTo('bg-surface', $surfaceMarkup);
$cardSlugs = $boundTo('bg-white', $surfaceMarkup);
$assert(3 === count($surfaceSlugs) && array() === array_filter($surfaceSlugs, static fn(?string $slug): bool => '#f6f8fa' !== ($surfacePalette[(string) $slug] ?? null)) && array('#ffffff') === array_map(static fn(?string $slug): ?string => $surfacePalette[(string) $slug] ?? null, $cardSlugs), 'painted-surface-shades: the #ffffff card and every #f6f8fa section bind, each to a token whose value is its own exact colour (' . json_encode(array('surfaces' => $surfaceSlugs, 'card' => $cardSlugs, 'palette' => $surfacePalette)) . ')');
// (b) No block or stylesheet colour differs from its source value.
$surfaceBindings = $colorBindings($surfacePlan, $surfaceTheme, array('bg-surface' => array('background' => '#f6f8fa'), 'bg-white' => array('background' => '#ffffff')), array('color.text' => '#000000', 'color.background' => '#ffffff', 'elements.heading.color.text' => '#000000', 'elements.link.color.text' => '#000000'));
$assert(array() !== $surfaceBindings && array() === $repainted($surfaceBindings), 'painted-surface-shades: every block and stylesheet colour binding reads a token equal to its source colour (' . json_encode($repainted($surfaceBindings)) . ')');
$surfaceCss = implode('', array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), array_filter($surfacePlan['assets'] ?? array(), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null))));
$assert(1 === preg_match('/\.bg-surface\{background-color:var\(--wp--preset--color--([a-z0-9-]+),#f6f8fa\)/', $surfaceCss, $surfaceRule) && '#f6f8fa' === ($surfacePalette[$surfaceRule[1]] ?? null), 'painted-surface-shades: the stylesheet surface rule reads the surface\'s own token');
$surfaceSystem = $surfacePlan['theme']['design_system'] ?? array();
$surfaceMetrics = $surfaceSystem['metrics'] ?? array();
$assert(array() === ($surfaceSystem['literal_colors'] ?? null) && 4 === ($surfaceMetrics['restyle_backgrounds'] ?? null) && 4 === ($surfaceMetrics['restyle_backgrounds_preset'] ?? null) && 0 === ($surfaceMetrics['literal_value_count'] ?? null), 'painted-surface-shades: every painted background follows the palette with no literal left (' . json_encode($surfaceMetrics) . ')');

// (c) The navigation-anchor-subject fixture: the `#fafafa` header and every
// other bound colour keep their source value, so the header paints 1:1.
$anchorSource = require dirname(__DIR__) . '/fixtures/navigation-anchor-subject.php';
[$anchorPlan, $anchorTheme] = $compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $anchorSource, 'other.html' => $anchorSource)));
$anchorStyles = array('color.text' => '#17212b', 'elements.link.color.text' => '#17212b', 'elements.heading.color.text' => '#17212b', 'elements.button.color.text' => '#ffffff');
$anchorBindings = $colorBindings($anchorPlan, $anchorTheme, array(), $anchorStyles);
$anchorPalette = array_column($anchorTheme['settings']['color']['palette'] ?? array(), 'color', 'slug');
$assert(count($anchorBindings) >= 10 && array() === $repainted($anchorBindings), 'navigation-anchor-subject: every bound colour equals its source value (' . json_encode($repainted($anchorBindings)) . ')');
$assert(1 === preg_match('/header\{padding:12px;background:var\(--wp--preset--color--([a-z0-9-]+),#fafafa\)/', implode('', array_column($anchorPlan['assets'] ?? array(), 'content')), $headerRule) && '#fafafa' === ($anchorPalette[$headerRule[1]] ?? null), 'navigation-anchor-subject: the #fafafa header keeps a token of its own, so it follows the palette without repainting');

fwrite(STDOUT, "Design system usage derivation: {$passes} passed, {$failures} failed\n");
exit($failures ? 1 : 0);
