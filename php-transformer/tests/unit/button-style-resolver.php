<?php
declare(strict_types=1);

/**
 * Regression coverage for native core/button style emission.
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

$html = <<<'HTML'
<style>
.btn { padding:9px 20px; border-radius:6px; font-weight:600; text-transform:uppercase; }
.btn-primary { background:#e8a020; color:#050d1a; box-shadow:0 0 24px rgba(232,160,32,0.3); }
</style>
<a class="btn btn-primary" href="/demo">Get early access</a>
HTML;

$result = ( new HtmlTransformer() )->transform($html, array())->toArray();
$button = $result['blocks'][0]['innerBlocks'][0] ?? array();
$attrs = is_array($button['attrs'] ?? null) ? $button['attrs'] : array();
$markup = (string) ($result['serialized_blocks'] ?? '');
$css = implode("\n", array_column($result['assets'] ?? array(), 'content'));

$assert('core/button' === ($button['blockName'] ?? ''), 'button signal becomes core/button', (string) ($button['blockName'] ?? '(none)'));
$assert(! isset($attrs['style']['shadow']) && str_contains($css, 'box-shadow:0 0 24px rgba(232,160,32,0.3)'), 'button box-shadow stays in the projected CSS when metadata rejects it', json_encode($attrs['style'] ?? array()));
$assert(str_contains($css, 'box-shadow:0 0 24px rgba(232,160,32,0.3)'), 'rendered core/button carries source box-shadow through CSS', $css);
$assert(str_contains($css, 'background-color:#e8a020!important'), 'rendered core/button carries source fill through CSS', $css);
$assert(str_contains($css, 'color:#050d1a!important'), 'rendered core/button carries source text color through CSS', $css);

$themed = ( new HtmlTransformer() )->transform(
    '<style>:root{--ink:#1d2230;--brand:linear-gradient(135deg,#2c63ff,#ff5d73)}[data-theme="dark"]{--ink:#f3f1ea}.btn{background:var(--brand);color:var(--ink)}</style><button class="btn">Continue</button>'
)->toArray();
$themedMarkup = (string) ($themed['serialized_blocks'] ?? '');
$themedCss = implode("\n", array_column($themed['assets'] ?? array(), 'content'));
$assert(str_contains($themedCss, '--brand:linear-gradient(135deg,#2c63ff,#ff5d73)') && str_contains($themedCss, 'background:var(--brand)'), 'button gradient remains in the projected CSS carrier', $themedCss);
$assert(str_contains($themedCss, 'color:#1d2230!important'), 'default root custom properties are not replaced by conditional theme overrides', $themedCss);
$assert(! str_contains($themedCss, 'color:#f3f1ea!important'), 'inactive dark-theme custom properties do not leak into canonical button paint', $themedCss);

$inheritedHeaderButton = ( new HtmlTransformer() )->transform(
    '<header style="color:#f8fff9;text-align:start"><a class="button" style="padding:10px 18px;background:#1d2230" href="/start">Start</a></header>'
)->toArray();
$inheritedHeaderMarkup = (string) ($inheritedHeaderButton['serialized_blocks'] ?? '');
$inheritedHeaderCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $inheritedHeaderButton['assets'] ?? array()));
$assert(str_contains($inheritedHeaderCss, 'color:#f8fff9!important'), 'header-inherited button foreground remains in the core/button CSS carrier', $inheritedHeaderCss);
$assert(str_contains($inheritedHeaderCss, 'text-align:start!important'), 'header-inherited start alignment overrides the core/button link default', $inheritedHeaderCss);
$assert('pass' === ($inheritedHeaderButton['source_reports']['wp_block_validity']['status'] ?? ''), 'header-inherited native button remains editor-valid', json_encode($inheritedHeaderButton['source_reports']['wp_block_validity'] ?? array()));
$assert(! str_contains($inheritedHeaderMarkup, '<!-- wp:html'), 'header-inherited native button needs no HTML fallback', $inheritedHeaderMarkup);

$cssWideInheritedButton = ( new HtmlTransformer() )->transform(
    '<style>a{color:inherit;text-align:inherit}.site-header{color:#f8fff9;text-align:start}</style><header class="site-header"><a class="button" style="padding:10px 18px;background:#1d2230" href="/start">Start</a></header>'
)->toArray();
$cssWideInheritedMarkup = (string) ($cssWideInheritedButton['serialized_blocks'] ?? '');
$cssWideInheritedCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $cssWideInheritedButton['assets'] ?? array()));
$assert(str_contains($cssWideInheritedMarkup, 'color:#f8fff9'), 'color:inherit resolves the header foreground into canonical core/button color', $cssWideInheritedMarkup);
$assert(str_contains($cssWideInheritedCss, 'color:#f8fff9!important') && str_contains($cssWideInheritedCss, 'text-align:start!important'), 'color:inherit and text-align:inherit resolve through the native button rule', $cssWideInheritedCss);
$assert('pass' === ($cssWideInheritedButton['source_reports']['wp_block_validity']['status'] ?? ''), 'CSS-wide inherited native button remains editor-valid', json_encode($cssWideInheritedButton['source_reports']['wp_block_validity'] ?? array()));

$defaultHeaderBrand = ( new HtmlTransformer() )->transform(
    '<header><a class="button" style="padding:10px 18px;background:#1d2230" href="/"><span class="brand-mark"><span class="brand-glyph">H</span></span> Header brand</a></header>'
)->toArray();
$defaultHeaderBrandMarkup = (string) ($defaultHeaderBrand['serialized_blocks'] ?? '');
$defaultHeaderBrandCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $defaultHeaderBrand['assets'] ?? array()));
$assert(str_contains($defaultHeaderBrandCss, 'text-align:start!important'), 'header brand with no text-align projects CSS initial start to the native button link', $defaultHeaderBrandCss);
$assert(str_contains($defaultHeaderBrandMarkup, 'brand-mark') && str_contains($defaultHeaderBrandMarkup, 'brand-glyph') && ! str_contains($defaultHeaderBrandMarkup, '<!-- wp:html') && 'pass' === ($defaultHeaderBrand['source_reports']['wp_block_validity']['status'] ?? ''), 'header brand mark and glyph remain native editor-valid button content', $defaultHeaderBrandMarkup);

$defaultFooterBrand = ( new HtmlTransformer() )->transform(
    '<footer><a class="button" style="padding:8px 14px;background:#18212b" href="/"><span class="brand-mark"><span class="brand-glyph">F</span></span> Footer brand</a></footer>'
)->toArray();
$defaultFooterBrandMarkup = (string) ($defaultFooterBrand['serialized_blocks'] ?? '');
$defaultFooterBrandCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $defaultFooterBrand['assets'] ?? array()));
$assert(str_contains($defaultFooterBrandCss, 'text-align:start!important'), 'footer brand with no text-align projects CSS initial start to the native button link', $defaultFooterBrandCss);
$assert(str_contains($defaultFooterBrandMarkup, 'brand-mark') && str_contains($defaultFooterBrandMarkup, 'brand-glyph') && ! str_contains($defaultFooterBrandMarkup, '<!-- wp:html') && 'pass' === ($defaultFooterBrand['source_reports']['wp_block_validity']['status'] ?? ''), 'footer brand mark and glyph remain native editor-valid button content', $defaultFooterBrandMarkup);

$inheritedFooterButton = ( new HtmlTransformer() )->transform(
    '<footer style="color:#d4e5ff;text-align:end"><a class="button" style="padding:8px 14px;background:#18212b" href="/">Brand</a></footer>'
)->toArray();
$inheritedFooterMarkup = (string) ($inheritedFooterButton['serialized_blocks'] ?? '');
$inheritedFooterCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $inheritedFooterButton['assets'] ?? array()));
$assert(str_contains($inheritedFooterMarkup, 'color:#d4e5ff'), 'footer-inherited button foreground maps to canonical core/button color', $inheritedFooterMarkup);
$assert(str_contains($inheritedFooterCss, 'text-align:end!important'), 'footer-inherited end alignment overrides the core/button link default', $inheritedFooterCss);
$assert('pass' === ($inheritedFooterButton['source_reports']['wp_block_validity']['status'] ?? ''), 'footer-inherited native button remains editor-valid', json_encode($inheritedFooterButton['source_reports']['wp_block_validity'] ?? array()));

$explicitButton = ( new HtmlTransformer() )->transform(
    '<header style="color:#f8fff9;text-align:start"><a class="button" style="padding:10px 18px;background:#1d2230;color:#102030;text-align:end" href="/start">Start</a></header>'
)->toArray();
$explicitMarkup = (string) ($explicitButton['serialized_blocks'] ?? '');
$explicitCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $explicitButton['assets'] ?? array()));
$explicitButtonAttrs = $explicitButton['blocks'][0]['innerBlocks'][0]['innerBlocks'][0]['attrs'] ?? array();
$assert(! isset($explicitButtonAttrs['style']['color']['text']) && str_contains($explicitCss, 'color:#102030'), 'explicit anchor color remains authoritative over inherited color through CSS', $explicitMarkup);
$assert(str_contains($explicitCss, 'text-align:end!important') && ! str_contains($explicitCss, 'text-align:start!important'), 'explicit anchor alignment remains authoritative over inherited alignment', $explicitCss);
$assert('pass' === ($explicitButton['source_reports']['wp_block_validity']['status'] ?? ''), 'explicit native button remains editor-valid', json_encode($explicitButton['source_reports']['wp_block_validity'] ?? array()));

if ( $failures > 0 ) {
    fwrite(STDERR, "Button style resolver tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Button style resolver tests: {$passes} passed\n");
