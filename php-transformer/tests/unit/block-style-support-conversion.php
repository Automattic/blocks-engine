<?php
declare(strict_types=1);

/**
 * Regression coverage for inline CSS that must become Gutenberg block supports
 * instead of unsupported raw block style strings.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$html = '<nav class="site-nav" style="display:flex;align-items:center;justify-content:space-between;gap:24px;background:var(--wp--preset--color--base);color:var(--wp--preset--color--contrast);padding:16px 24px;box-shadow:0 12px 30px rgba(0,0,0,.12)">'
    . '<a href="/">Home</a><a href="/menu">Menu</a>'
    . '</nav>';

$result = ( new HtmlTransformer() )->transform($html, array())->toArray();
$blocks = $result['blocks'] ?? array();
$nav = $blocks[0] ?? array();

$assert('core/navigation' === ($nav['blockName'] ?? ''), '1: nav-like wrapper becomes core/navigation', (string) ($nav['blockName'] ?? '(none)'));
$attrs = is_array($nav['attrs'] ?? null) ? $nav['attrs'] : array();
$assert('base' === ($attrs['backgroundColor'] ?? ''), '2: preset background variable maps to backgroundColor attr', json_encode($attrs));
$assert('contrast' === ($attrs['textColor'] ?? ''), '3: preset text variable maps to textColor attr', json_encode($attrs));
$assert('24px' === ($attrs['style']['spacing']['blockGap'] ?? ''), '4: gap maps to spacing.blockGap', json_encode($attrs['style']['spacing'] ?? array()));
$assert('flex' === ($attrs['layout']['type'] ?? ''), '5: display:flex maps to flex layout', json_encode($attrs['layout'] ?? array()));
$assert('space-between' === ($attrs['layout']['justifyContent'] ?? ''), '6: justify-content maps to layout.justifyContent', json_encode($attrs['layout'] ?? array()));
$assert(! isset($attrs['style']['box-shadow']), '7: unsupported box-shadow is not stored as block style', json_encode($attrs['style'] ?? array()));
$assert(! is_string($attrs['style'] ?? null), '8: block style attr is structured, never a raw style string');

$groupHtml = '<div class="hero-row" style="display:flex;justify-content:center;gap:1rem;min-height:100svh;padding:2rem;background:var(--wp--preset--color--base)"><p>Hello</p><p>World</p></div>';
$groupResult = ( new HtmlTransformer() )->transform($groupHtml, array())->toArray();
$group = $groupResult['blocks'][0] ?? array();
$groupAttrs = is_array($group['attrs'] ?? null) ? $group['attrs'] : array();
$groupInnerHtml = (string) ($group['innerHTML'] ?? '');

$assert('core/columns' === ($group['blockName'] ?? ''), '9: horizontal flex content wrapper becomes columns', (string) ($group['blockName'] ?? '(none)'));
$assert('center' === ($groupAttrs['layout']['justifyContent'] ?? ''), '10: group flex justify-content is normalized to layout attr', json_encode($groupAttrs['layout'] ?? array()));
$assert(str_contains($groupInnerHtml, 'has-base-background-color has-background'), '11: rendered wrapper uses preset color classes', $groupInnerHtml);
$assert(str_contains($groupInnerHtml, 'is-layout-flex wp-block-columns-is-layout-flex'), '12: rendered wrapper uses layout support classes', $groupInnerHtml);
$assert(! str_contains($groupInnerHtml, 'gap:1rem'), '13: rendered wrapper omits blockGap when the core save shape does not reproduce it', $groupInnerHtml);
$assert(! str_contains($groupInnerHtml, 'display:flex') && ! str_contains($groupInnerHtml, 'justify-content:center'), '14: rendered wrapper does not carry raw flex declarations', $groupInnerHtml);
$assert('100svh' === ($groupAttrs['style']['dimensions']['minHeight'] ?? ''), '15: min-height maps to Gutenberg dimensions support', json_encode($groupAttrs['style']['dimensions'] ?? array()));
$assert(str_contains($groupInnerHtml, 'min-height:100svh'), '16: rendered wrapper preserves section min-height geometry', $groupInnerHtml);

$cardHtml = '<section class="pricing-shell" style="max-width:1120px;margin:0 auto;padding:5rem 2rem"><article class="pricing-card" style="max-width:360px;padding:2rem;background:#fff"><h2>Team</h2><p>Scale every launch.</p></article></section>';
$cardResult = ( new HtmlTransformer() )->transform($cardHtml, array())->toArray();
$cardShell = $cardResult['blocks'][0] ?? array();
$card = $cardShell['innerBlocks'][0] ?? array();
$cardShellAttrs = is_array($cardShell['attrs'] ?? null) ? $cardShell['attrs'] : array();
$cardAttrs = is_array($card['attrs'] ?? null) ? $card['attrs'] : array();
$cardMarkup = (string) ($cardResult['serialized_blocks'] ?? '');

$assert(! isset($cardShellAttrs['style']['dimensions']['maxWidth']), '17: core/group omits max-width attr that Gutenberg save does not reproduce', json_encode($cardShellAttrs['style']['dimensions'] ?? array()));
$assert(! isset($cardAttrs['style']['dimensions']['maxWidth']), '18: nested core/group omits max-width attr that Gutenberg save does not reproduce', json_encode($cardAttrs['style']['dimensions'] ?? array()));
$assert(! str_contains($cardMarkup, 'max-width:1120px'), '19: rendered core/group wrapper omits unsupported max-width style', $cardMarkup);
$assert(! str_contains($cardMarkup, 'max-width:360px'), '20: rendered nested core/group wrapper omits unsupported max-width style', $cardMarkup);
$assert(! str_contains($cardMarkup, 'style="max-width:1120px;margin:0 auto;padding:5rem 2rem"'), '21: rendered section does not keep unsupported raw style wholesale', $cardMarkup);

$columnsMaxWidthHtml = '<div class="feature-row" style="display:flex;max-width:var(--max-w);margin:0 auto"><div><p>A</p></div><div><p>B</p></div></div>';
$columnsMaxWidthResult = ( new HtmlTransformer() )->transform($columnsMaxWidthHtml, array())->toArray();
$columnsMaxWidthBlock = $columnsMaxWidthResult['blocks'][0] ?? array();
$columnsMaxWidthAttrs = is_array($columnsMaxWidthBlock['attrs'] ?? null) ? $columnsMaxWidthBlock['attrs'] : array();
$columnsMaxWidthMarkup = (string) ($columnsMaxWidthResult['serialized_blocks'] ?? '');

$assert('core/columns' === ($columnsMaxWidthBlock['blockName'] ?? ''), '22: horizontal flex wrapper still becomes columns', (string) ($columnsMaxWidthBlock['blockName'] ?? '(none)'));
$assert(! isset($columnsMaxWidthAttrs['style']['dimensions']['maxWidth']), '23: core/columns omits max-width attr that Gutenberg save does not reproduce', json_encode($columnsMaxWidthAttrs['style']['dimensions'] ?? array()));
$assert(! str_contains($columnsMaxWidthMarkup, 'max-width:var(--max-w)'), '24: rendered core/columns wrapper omits unsupported max-width style', $columnsMaxWidthMarkup);

$labelHtml = '<section class="pricing"><div class="section-head"><div class="tag">Pricing</div><h2>Simple plans</h2></div><article class="pricing-card"><div class="tier-name">Team</div><div class="tier-price"><span class="amount">$29</span>/mo</div><div class="use-case-result">Launch faster</div></article></section>';
$labelCss = '.tag{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:100px}.pricing-card{padding:2rem}.tier-name{font-family:monospace;font-size:11px;letter-spacing:.12em;text-transform:uppercase}.tier-price{display:flex;align-items:flex-end;gap:6px}.use-case-result{display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:6px}';
$labelResult = ( new HtmlTransformer() )->transform($labelHtml, array('static_css' => $labelCss))->toArray();
$labelMarkup = (string) ($labelResult['serialized_blocks'] ?? '');

$assert(str_contains($labelMarkup, '<div class="wp-block-group tag'), '25: class-owned section badge stays a group wrapper', $labelMarkup);
$assert(str_contains($labelMarkup, '<div class="wp-block-group tier-name'), '26: class-owned card tier label stays a group wrapper', $labelMarkup);
$assert(str_contains($labelMarkup, '<div class="wp-block-group tier-price'), '27: class-owned card price row stays a group wrapper', $labelMarkup);
$assert(str_contains($labelMarkup, '<div class="wp-block-group use-case-result'), '28: class-owned card result row stays a group wrapper', $labelMarkup);
$assert(! str_contains($labelMarkup, '<p class="tier-name"'), '29: card label is not flattened into a paragraph that breaks wrapper CSS', $labelMarkup);

$stackHtml = '<div class="hero-content"><p>Eyebrow</p><h1>Low Tide Table</h1><div></div><p>Local shrimp.</p><div><p>Next Run</p></div><div><a href="#reserve">Reserve</a></div></div>';
$stackResult = ( new HtmlTransformer() )->transform($stackHtml, array())->toArray();
$stack = $stackResult['blocks'][0] ?? array();

$assert('core/group' === ($stack['blockName'] ?? ''), '30: multi-child hero content stack stays a group, not columns', (string) ($stack['blockName'] ?? '(none)'));
$assert('hero-content' === (($stack['attrs']['className'] ?? '')), '31: multi-child hero content stack keeps source class for stylesheet materialization', json_encode($stack['attrs'] ?? array()));

$paragraphColorHtml = '<p class="has-text-color hero-tagline" style="color:var(--wp--preset--color--contrast)">Steeped daily.</p>';
$paragraphColorResult = ( new HtmlTransformer() )->transform($paragraphColorHtml, array())->toArray();
$paragraphColorMarkup = (string) ($paragraphColorResult['serialized_blocks'] ?? '');
$paragraphColorAttrs = $paragraphColorResult['blocks'][0]['attrs'] ?? array();

$assert(str_contains($paragraphColorMarkup, '<p class="has-contrast-color has-text-color hero-tagline">Steeped daily.</p>'), '32: paragraph preset text color emits one has-text-color token plus source class', $paragraphColorMarkup);
$assert(! str_contains($paragraphColorMarkup, 'has-text-color has-text-color'), '33: paragraph color support never duplicates has-text-color', $paragraphColorMarkup);
$assert('hero-tagline' === ($paragraphColorAttrs['className'] ?? ''), '34: paragraph className excludes generated color support classes', json_encode($paragraphColorAttrs));

$textPresetHtml = '<p class="masthead__bio" style="color:var(--wp--preset--color--text)">I am a cognitive neuroscientist.</p>';
$textPresetResult = ( new HtmlTransformer() )->transform($textPresetHtml, array())->toArray();
$textPresetBlock = $textPresetResult['blocks'][0] ?? array();
$textPresetAttrs = is_array($textPresetBlock['attrs'] ?? null) ? $textPresetBlock['attrs'] : array();
$textPresetInnerHtml = (string) ($textPresetBlock['innerHTML'] ?? '');

$assert(! isset($textPresetAttrs['textColor']), '35: preset text slug stays custom color to avoid duplicate has-text-color classes', json_encode($textPresetAttrs));
$assert('var(--wp--preset--color--text)' === ($textPresetAttrs['style']['color']['text'] ?? ''), '36: preset text color value remains visually preserved', json_encode($textPresetAttrs['style'] ?? array()));
$assert('masthead__bio' === ($textPresetAttrs['className'] ?? ''), '37: source paragraph class remains preserved without generated color class leakage', json_encode($textPresetAttrs));
$assert(! str_contains($textPresetInnerHtml, 'has-text-color has-text-color'), '38: rendered paragraph does not carry duplicate generated text color classes', $textPresetInnerHtml);

$paintCss = '.pricing-card{background:radial-gradient(circle at 20% 10%,rgba(255,255,255,.9),rgba(255,255,255,0) 38%),linear-gradient(180deg,#fff,#f5efe4);background-position:center top;background-size:120% 80%,100% 100%;background-repeat:no-repeat;box-shadow:0 28px 80px rgba(20,12,4,.18);padding:2rem;border-radius:24px}';
$paintHtml = '<main><section class="pricing-card"><h2>Roast Club</h2><p>Fresh coffee every week.</p></section></main>';
$paintResult = ( new HtmlTransformer() )->transform($paintHtml, array('static_css' => $paintCss))->toArray();
$paintBlock = $paintResult['blocks'][0] ?? array();
$paintAttrs = is_array($paintBlock['attrs'] ?? null) ? $paintBlock['attrs'] : array();

$assert('pricing-card' === ($paintAttrs['className'] ?? ''), '39: high-value card wrapper keeps source class for class-owned paint CSS', json_encode($paintAttrs));
$assert(! isset($paintAttrs['style']['box-shadow']), '40: class-owned box-shadow is not stored as an unsupported block style attr', json_encode($paintAttrs['style'] ?? array()));
$assert(! isset($paintAttrs['style']['background-position']) && ! isset($paintAttrs['style']['background-size']), '41: background layer controls stay out of block style attrs', json_encode($paintAttrs['style'] ?? array()));

$rulesMethod = new ReflectionMethod(HtmlTransformer::class, 'staticStyleRules');
$paintRules = $rulesMethod->invoke(new HtmlTransformer(), '', $paintCss);
$paintDeclarations = $paintRules[0]['declarations'] ?? array();

$assert(($paintDeclarations['background'] ?? '') === 'radial-gradient(circle at 20% 10%,rgba(255,255,255,.9),rgba(255,255,255,0) 38%),linear-gradient(180deg,#fff,#f5efe4)', '42: radial and layered backgrounds survive safe CSS resolution', json_encode($paintDeclarations));
$assert(($paintDeclarations['background-position'] ?? '') === 'center top', '43: background-position survives safe CSS resolution', json_encode($paintDeclarations));
$assert(($paintDeclarations['background-size'] ?? '') === '120% 80%,100% 100%', '44: background-size survives safe CSS resolution', json_encode($paintDeclarations));
$assert(($paintDeclarations['background-repeat'] ?? '') === 'no-repeat', '45: background-repeat survives safe CSS resolution', json_encode($paintDeclarations));
$assert(($paintDeclarations['box-shadow'] ?? '') === '0 28px 80px rgba(20,12,4,.18)', '46: box-shadow survives safe CSS resolution', json_encode($paintDeclarations));

if ( $failures > 0 ) {
    fwrite(STDERR, "Block style support conversion tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Block style support conversion tests: {$passes} passed\n");
