<?php
declare(strict_types=1);

/**
 * Regression coverage for native core/button style emission.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ButtonStyleResolver;

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

$resetButton = ( new HtmlTransformer() )->transform(
    '<style>.cta{padding:13.5px 27px;border-radius:56.25px;background:#123456;color:#fff}a{border:0;padding:0}</style><a class="cta" href="/quote">Quote</a>'
)->toArray();
$resetButtonCss = implode("\n", array_column($resetButton['assets'] ?? array(), 'content'));
$assert(str_contains($resetButtonCss, 'border-radius:56.25px!important') && str_contains($resetButtonCss, 'padding-right:27px!important') && str_contains($resetButtonCss, 'padding-left:27px!important'), 'resolved button styling overrides an authored border and padding reset', $resetButtonCss);

$longhandBorderButton = ( new HtmlTransformer() )->transform(
    '<style>a{border:0;background:0 0}.btn{border-top:1px solid rgb(254,126,3);border-right:1px solid rgb(254,126,3);border-bottom:1px solid rgb(254,126,3);border-left:1px solid rgb(254,126,3);border-radius:4px;padding:10px 20px}</style><a class="btn" href="/team">Meet the Team</a>'
)->toArray();
$longhandBorderCss = implode("\n", array_column($longhandBorderButton['assets'] ?? array(), 'content'));
$assert(! str_contains($longhandBorderCss, 'border-style:none!important') && ! str_contains($longhandBorderCss, 'border-width:0!important'), 'a border declared through longhands after a `border:0` shorthand reset is not neutralized', $longhandBorderCss);
$assert(str_contains($longhandBorderCss, 'border-radius:4px!important'), 'radius handling keeps working alongside a longhand-declared border', $longhandBorderCss);

$customPropertyBorderButton = ( new HtmlTransformer() )->transform(
    '<style>a{border:0;background:0 0}.btn{--border-top:1px solid rgb(254,126,3);--border-right:1px solid rgb(254,126,3);--border-bottom:1px solid rgb(254,126,3);--border-left:1px solid rgb(254,126,3);border-top:var(--border-top);border-right:var(--border-right);border-bottom:var(--border-bottom);border-left:var(--border-left);border-radius:4px;padding:10px 20px}</style><a class="btn" href="/team">Meet the Team</a>'
)->toArray();
$customPropertyBorderCss = implode("\n", array_column($customPropertyBorderButton['assets'] ?? array(), 'content'));
$assert(! str_contains($customPropertyBorderCss, 'border-style:none!important') && ! str_contains($customPropertyBorderCss, 'border-width:0!important'), 'a var()-driven longhand border after a `border:0` shorthand reset is not statically provable as zero and is not neutralized', $customPropertyBorderCss);
$assert(1 === preg_match('/\.blocks-engine-control-[^\s.]+\.blocks-engine-control-[^\s>]+>\.wp-block-button__link\{[^}]*border-top:var\(--border-top\)!important[^}]*border-right:var\(--border-right\)!important[^}]*border-bottom:var\(--border-bottom\)!important[^}]*border-left:var\(--border-left\)!important/', $customPropertyBorderCss), 'the generated native link rule consumes each var()-driven border side rather than relying on the source selector', $customPropertyBorderCss);

$genuinelyBorderlessButton = ( new HtmlTransformer() )->transform(
    '<style>a{border:0;background:0 0}.btn{background:#173b64;border-radius:6px;padding:10px 20px}</style><a class="btn" href="/team">Meet the Team</a>'
)->toArray();
$genuinelyBorderlessCss = implode("\n", array_column($genuinelyBorderlessButton['assets'] ?? array(), 'content'));
$assert(str_contains($genuinelyBorderlessCss, 'border-style:none!important') && str_contains($genuinelyBorderlessCss, 'border-width:0!important'), 'a control with no border on the shorthand or any longhand still gets neutralized against theme default button chrome', $genuinelyBorderlessCss);

$squareButtonStyle = ( new ButtonStyleResolver() )->nativeAttributes('border:0;padding:0;border-radius:0');
$assert('0' === ($squareButtonStyle['style']['border']['radius'] ?? '') && '0' === ($squareButtonStyle['style']['spacing']['padding']['left'] ?? '') && '0' === ($squareButtonStyle['style']['spacing']['padding']['right'] ?? ''), 'explicit zero button radius and padding remain preserved', json_encode($squareButtonStyle));

foreach ( array(
    '/contact' => array( '', '' ),
    'https://example.com/quote' => array( '_blank', 'noopener external' ),
    'tel:+15551234567' => array( '', '' ),
    'mailto:hello@example.com' => array( '', '' ),
) as $url => $linkAttributes ) {
    $stylable = ( new HtmlTransformer() )->transform(
        '<style>.wix-label{padding:12px 20px;background:#173b64;border-radius:6px;color:#fff}.wix-icon{width:1em;height:1em}</style>' .
        '<a class="wix-action wix-button" href="' . $url . '" target="' . $linkAttributes[0] . '" rel="' . $linkAttributes[1] . '" aria-label="Contact us"><span class="wix-label"><span>Contact us</span></span><svg class="wix-icon" aria-hidden="true"><g><path d="M0 0h1v1z"/></g></svg></a>'
    )->toArray();
    $stylableButton = $stylable['blocks'][0]['innerBlocks'][0] ?? array();
    $stylableAttrs = $stylableButton['attrs'] ?? array();
    $stylableMarkup = (string) ($stylable['serialized_blocks'] ?? '');

    $assert('core/button' === ($stylableButton['blockName'] ?? null), 'nested-label SVG anchor becomes a native button for ' . $url, $stylableMarkup);
    $assert($url === ($stylableAttrs['url'] ?? null) && $linkAttributes[0] === ($stylableAttrs['linkTarget'] ?? '') && $linkAttributes[1] === ($stylableAttrs['rel'] ?? '') && ! isset($stylableAttrs['ariaLabel']), 'nested-label SVG button retains schema-supported link semantics for ' . $url, json_encode($stylableAttrs));
    $assert(str_contains($stylableMarkup, 'href="' . $url . '"') && ! str_contains($stylableMarkup, 'aria-label=') && str_contains($stylableMarkup, 'wix-label') && str_contains($stylableMarkup, 'materialized-svg'), 'equivalent accessible label stays visible without an unsupported native attribute for ' . $url, $stylableMarkup);
    $assert(! str_contains($stylableMarkup, '<!-- wp:html'), 'nested-label SVG button has no HTML fallback for ' . $url, $stylableMarkup);
}

$unsafeButton = ( new HtmlTransformer() )->transform('<a class="button" style="padding:12px;background:#123" href="/join"><span>Join</span><input type="checkbox"></a>')->toArray();
$unsafeMarkup = (string) ($unsafeButton['serialized_blocks'] ?? '');
$assert(! str_contains($unsafeMarkup, '<!-- wp:button'), 'nested interactive content is not promoted to a native button', $unsafeMarkup);

$differentAccessibleName = ( new HtmlTransformer() )->transform('<a class="wix-button" href="/contact" aria-label="Open contact form"><span class="wix-label">Contact us</span><svg aria-hidden="true"><path d="M0 0h1v1z"/></svg></a>')->toArray();
$differentMarkup = (string) ($differentAccessibleName['serialized_blocks'] ?? '');
$differentFallbacks = $differentAccessibleName['fallbacks'] ?? array();
$assert(str_contains($differentMarkup, '<!-- wp:html') && str_contains($differentMarkup, 'aria-label="Open contact form"') && 'html_stylable_button_accessible_name_fallback' === ($differentFallbacks[0]['diagnostic_code'] ?? null), 'materially different accessible name remains a diagnostic HTML fallback pending a typed companion', json_encode($differentAccessibleName));

if ( $failures > 0 ) {
    fwrite(STDERR, "Button style resolver tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Button style resolver tests: {$passes} passed\n");
