<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
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
    $assert(count($palette) === ($metrics['palette_size'] ?? null) && is_int($metrics['source_css_bytes'] ?? null) && $metrics['source_css_bytes'] > 0 && is_float($metrics['generated_to_source_css_ratio'] ?? null), "$fixture: metrics report palette size and CSS byte ratio");
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
$assert(array('base' => '#ffffff', 'contrast' => '#222222', 'primary' => '#e11d48') === $colors, 'near-duplicate colours within one just-noticeable difference merge into the heaviest member');
$assert('contrast' === $system?->colorSlug('#232323') && 'base' === $system?->colorSlug('#fefefe') && null === $system?->colorSlug('#2a2a2a') && null === $system?->colorSlug('rgba(34,34,34,.5)'), 'token matching uses the documented tolerance and skips translucent colours');
$many = array();
for ($i = 0; $i < 20; $i++) $many[] = $row(sprintf('#%02x%02x%02x', 20 + $i * 11, 200 - $i * 9, ($i * 37) % 256), 20 - $i);
$capped = DesignSystem::fromUsage(array('a.html' => $usage($many)));
$assert(12 === count($capped?->colors ?? array()), 'the palette is capped at twelve colours');
$sizes = array();
foreach (array(12, 14, 16, 16.2, 18, 20, 24, 30, 36, 48, 60, 72) as $index => $size) $sizes[] = $row('#111111', 100 - $index, 'body', $size);
$scale = DesignSystem::fromUsage(array('a.html' => $usage($sizes)));
$scaleSlugs = array_column($scale?->fontSizes ?? array(), 'slug');
$assert(8 === count($scaleSlugs) && 'medium' === ($scale?->fontSizeSlug('16.2px')) && in_array('small', $scaleSlugs, true) && in_array('large', $scaleSlugs, true), 'font sizes within half a pixel merge and the scale is capped at eight steps');
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
$sameToken = (new PresetBinding($bindSystem, array(), $classUsage(array(array('classes' => 'text-rose', 'properties' => array('color' => array('#e11d48;#e21d48')))))))->bind($plain);
$assert(str_contains($sameToken, '"textColor":"' . $slug . '"'), 'a breakpoint value that matches the same token does not block binding');
$roseUsage = $classUsage(array(array('classes' => 'text-rose', 'properties' => array('color' => array('#e11d48')))));
foreach (array(
    'an !important rule reaches the block' => '.text-rose{color:#e11d48!important}',
    'a hover rule restyles it' => '.text-rose:hover{color:#111}',
    'a nested hover rule restyles it' => '.text-rose{&:hover{color:#111}}',
) as $why => $css) $assert($plain === (new PresetBinding($bindSystem, array(array('kind' => 'css', 'content' => $css)), $roseUsage))->bind($plain), "class-driven colour is not bound when $why");
$assert(str_contains((new PresetBinding($bindSystem, array(array('kind' => 'css', 'content' => 'p.text-rose:not(.has-text-color){color:#e11d48!important}')), $roseUsage))->bind($plain), '"textColor"'), 'an !important rule that steps aside for preset colours does not block binding');

// Low-weight colours fold into a nearby heavier token; heavy colours stay.
$consolidated = DesignSystem::consolidateColors(array(
    array('color' => '#050b18', 'weight' => 900, 'members' => array('#050b18')),
    array('color' => '#3b82f6', 'weight' => 60, 'members' => array('#3b82f6')),
    array('color' => '#2563eb', 'weight' => 40, 'members' => array('#2563eb')),
    array('color' => '#0a1630', 'weight' => 20, 'members' => array('#0a1630')),
    array('color' => '#e11d48', 'weight' => 10, 'members' => array('#e11d48')),
));
$assert(array('#050b18' => 920, '#3b82f6' => 60, '#2563eb' => 40, '#e11d48' => 10) === array_column($consolidated, 'weight', 'color'), 'a low-weight shade within ΔE 18 merges into the heavier token; distinct and heavier colours stay');
$assert(array() === array_diff(array('#050b18', '#0a1630'), $consolidated[0]['members']), 'the merged shade is a member of the heavier token');
$keptCta = DesignSystem::consolidateColors(array(array('color' => '#050b18', 'weight' => 900, 'members' => array('#050b18')), array('color' => '#0a1630', 'weight' => 20, 'members' => array('#0a1630'))), array('#0a1630' => 20));
$assert(2 === count($keptCta), 'a call-to-action background is never folded away');
$paletteWith = DesignSystem::fromUsage(array('a.html' => $usage(array($row('#e5e7eb', 600)), array(array('role' => 'root', 'color' => '#050b18', 'authored' => '#050b18', 'count' => 1, 'weight' => 900), array('role' => 'section', 'color' => '#0a1630', 'authored' => '#0a1630', 'count' => 1, 'weight' => 20)))));
$assert(array('#050b18', '#e5e7eb') === array_column($paletteWith?->colors ?? array(), 'color') && null === $paletteWith?->colorSlug('#0a1630'), 'the derived palette omits the folded shade, and the shade keeps rendering as itself');

// Without usage, projection keeps the legacy hashed presets.
$legacy = (new ThemeJsonProjection())->project(array(array('kind' => 'css', 'content' => 'body{color:#123456}', 'scopes' => array(array('kind' => 'global')))));
$assert(1 === preg_match('/^color-/', (string) ($legacy['theme']['settings']['color']['palette'][0]['slug'] ?? '')), 'projection without a design system keeps hashed literal presets');
$withSystem = (new ThemeJsonProjection())->project(array(array('kind' => 'css', 'content' => 'body{color:#111111}', 'scopes' => array(array('kind' => 'global')))), array(), $bindSystem);
$assert('var:preset|color|contrast' === ($withSystem['theme']['styles']['color']['text'] ?? null), 'projected selector values resolve to design-system tokens');

fwrite(STDOUT, "Design system usage derivation: {$passes} passed, {$failures} failed\n");
exit($failures ? 1 : 0);
