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

// A real design system states its foreground on `body`, not on a class-named
// or tag-named "high value" ancestor, and often behind a custom property
// (Tailwind/shadcn `body{color:hsl(var(--foreground))}`). `<body>` carries no
// class/id/role token and is not one of the structural tag names the style
// boundary always resolves, so its matched (non-inline) rule was invisible to
// native-button inheritance even though `<header style="...">` above already
// worked. This is the exact shape of a real import where a light-background
// "Start Selling" button sat beside a "Browse Logos" button that had its own
// explicit white text: only the button with no color of its own lost its
// label to the theme's white .wp-element-button default.
$bodyForegroundButton = ( new HtmlTransformer() )->transform(
    '<style>:root{--foreground:0 0% 7%}body{color:hsl(var(--foreground))}</style><div class="hero"><a class="btn" style="padding:12px 40px;background:#fefefe" href="/sell">Start Selling</a></div>'
)->toArray();
$bodyForegroundBlock = $bodyForegroundButton['blocks'][0]['innerBlocks'][0]['innerBlocks'][0] ?? array();
$bodyForegroundMarkup = (string) ($bodyForegroundButton['serialized_blocks'] ?? '');
$assert(
    'hsl(0 0% 7%)' === ($bodyForegroundBlock['attrs']['style']['color']['text'] ?? null),
    'a body-level foreground authored through a custom property fills a filled button with no color of its own',
    (string) json_encode($bodyForegroundBlock['attrs']['style'] ?? array())
);
$assert(
    str_contains($bodyForegroundMarkup, 'has-text-color') && str_contains($bodyForegroundMarkup, 'color:hsl(0 0% 7%)'),
    'the body-inherited foreground reaches the native button link markup',
    $bodyForegroundMarkup
);
$assert('pass' === ($bodyForegroundButton['source_reports']['wp_block_validity']['status'] ?? ''), 'body-inherited native button remains editor-valid', json_encode($bodyForegroundButton['source_reports']['wp_block_validity'] ?? array()));

// No regression: a sibling filled button whose OWN fill already authors white
// text — the same value the theme's .wp-element-button default paints — must
// stay exactly as explicit, unaffected by the sibling's new inherited fill.
$matchingDefaultButton = ( new HtmlTransformer() )->transform(
    '<style>:root{--foreground:0 0% 7%}body{color:hsl(var(--foreground))}</style><div class="hero"><a class="btn btn-primary" style="padding:12px 40px;background:#6d28d9;color:#ffffff" href="/browse">Browse Logos</a></div>'
)->toArray();
$matchingDefaultBlock = $matchingDefaultButton['blocks'][0]['innerBlocks'][0]['innerBlocks'][0] ?? array();
$assert(
    '#ffffff' === ($matchingDefaultBlock['attrs']['style']['color']['text'] ?? null),
    'a button whose own fill already authors white text keeps that explicit value unchanged',
    (string) json_encode($matchingDefaultBlock['attrs']['style'] ?? array())
);

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
$assert('#102030' === ($explicitButtonAttrs['style']['color']['text'] ?? null) && str_contains($explicitCss, 'color:#102030'), 'explicit anchor color remains authoritative over inherited color through CSS', $explicitMarkup);
$assert(str_contains($explicitCss, 'text-align:end!important') && ! str_contains($explicitCss, 'text-align:start!important'), 'explicit anchor alignment remains authoritative over inherited alignment', $explicitCss);
$assert('pass' === ($explicitButton['source_reports']['wp_block_validity']['status'] ?? ''), 'explicit native button remains editor-valid', json_encode($explicitButton['source_reports']['wp_block_validity'] ?? array()));

$resetButton = ( new HtmlTransformer() )->transform(
    '<style>.cta{padding:13.5px 27px;border-radius:56.25px;background:#123456;color:#fff}a{border:0;padding:0}</style><a class="cta" href="/quote">Quote</a>'
)->toArray();
$resetButtonCss = implode("\n", array_column($resetButton['assets'] ?? array(), 'content'));
$assert(str_contains($resetButtonCss, 'border-radius:56.25px!important') && str_contains($resetButtonCss, 'padding-right:27px!important') && str_contains($resetButtonCss, 'padding-left:27px!important'), 'resolved button styling overrides an authored border and padding reset', $resetButtonCss);

$logicalCornerButton = ( new HtmlTransformer() )->transform(
    '<style>.cta{padding:12px 24px;background:#123456;border-start-start-radius:var(--corner);border-start-end-radius:var(--corner);border-end-start-radius:var(--corner);border-end-end-radius:var(--corner);--corner:50px}</style><a class="cta" href="/quote">Quote</a>'
)->toArray();
$logicalCornerCss = implode("\n", array_column($logicalCornerButton['assets'] ?? array(), 'content'));
$assert(! str_contains($logicalCornerCss, 'border-radius:0!important') && str_contains($logicalCornerCss, 'border-start-start-radius:'), 'authored logical corners prevent the native square-corner fallback', $logicalCornerCss);

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

$generatedSurface = ( new HtmlTransformer() )->transform(
    '<style>.cta{display:inline-flex}.surface{display:block;background:#0077cc;color:#000;padding:12px 20px}.surface::after{content:" arrow";margin-left:8px}</style><a class="cta button" href="/quote"><span class="surface">Quote</span></a>'
)->toArray();
$generatedSurfaceMarkup = (string) ($generatedSurface['serialized_blocks'] ?? '');
$generatedSurfaceCss = implode("\n", array_column($generatedSurface['assets'] ?? array(), 'content'));
$assert('core/button' === ($generatedSurface['blocks'][0]['innerBlocks'][0]['blockName'] ?? ''), 'generated inner surface remains a native core/button', $generatedSurfaceMarkup);
$assert(str_contains($generatedSurfaceCss, 'background-color:#0077cc!important') && str_contains($generatedSurfaceCss, 'color:#000!important'), 'inner-surface foreground and background reach the native button link', $generatedSurfaceCss);
$assert(2 === preg_match_all('/wp-block-button__link\)::after\{(?:content:" arrow"|margin-left:8px)\}/', $generatedSurfaceCss), 'inner-surface generated content and geometry reach the native button link', $generatedSurfaceCss);
$assert(! str_contains($generatedSurfaceCss, '.surface::after') && ! str_contains($generatedSurfaceMarkup, '<!-- wp:html') && 'pass' === ($generatedSurface['source_reports']['wp_block_validity']['status'] ?? ''), 'generated inner-surface native button remains editor-valid without HTML fallback', $generatedSurfaceMarkup);

$differentAccessibleName = ( new HtmlTransformer() )->transform('<a class="wix-button" href="/contact" aria-label="Open contact form"><span class="wix-label">Contact us</span><svg aria-hidden="true"><path d="M0 0h1v1z"/></svg></a>')->toArray();
$differentMarkup = (string) ($differentAccessibleName['serialized_blocks'] ?? '');
$differentBlock = $differentAccessibleName['blocks'][0] ?? array();
$assert('custom/accessible-link' === ($differentBlock['blockName'] ?? '') && 'Open contact form' === ($differentBlock['attrs']['accessibleLabel'] ?? '') && str_contains((string) ($differentBlock['attrs']['content'] ?? ''), 'materialized-svg') && ! str_contains($differentMarkup, '<!-- wp:html') && array() === ($differentAccessibleName['fallbacks'] ?? array()), 'materially different accessible names use a typed companion whose source-ordered RichText content retains the materialized icon', json_encode($differentAccessibleName));

$oklchButton = ( new HtmlTransformer() )->transform(
    '<a href="/x" style="display:inline-flex;padding:8px 24px;border-radius:9999px;background:oklch(0.56 0.13 45);color:#fff">Agendar</a>'
)->toArray();
$oklchButtonBlock = $oklchButton['blocks'][0]['innerBlocks'][0] ?? array();
$oklchCss = implode("\n", array_column($oklchButton['assets'] ?? array(), 'content'));
$oklchMarkup = (string) ($oklchButton['serialized_blocks'] ?? '');
$oklchBackground = (string) ($oklchButtonBlock['attrs']['style']['color']['background'] ?? '');
$assert('core/button' === ($oklchButtonBlock['blockName'] ?? ''), 'oklch-filled pill becomes core/button', (string) ($oklchButtonBlock['blockName'] ?? '(none)'));
$assert(
    str_contains($oklchBackground, 'oklch(0.56 0.13 45)')
        || str_contains($oklchCss, 'oklch(0.56 0.13 45)')
        || str_contains($oklchMarkup, 'oklch(0.56 0.13 45)'),
    'oklch fill is serialized onto the native button, not dropped or replaced with #0000',
    $oklchBackground . "\n" . $oklchCss
);
$assert(! str_contains($oklchCss, '#0000') && ! str_contains($oklchMarkup, '#0000'), 'visible oklch fill is not stored as transparent #0000', $oklchCss);

$oklchPadding = (string) json_encode($oklchButtonBlock['attrs']['style']['spacing']['padding'] ?? array());
$oklchLinkStyle = '';
if ( preg_match('/<a[^>]*class="[^"]*wp-block-button__link[^"]*"[^>]*style="([^"]*)"/', $oklchMarkup, $oklchLinkMatches) ) {
    $oklchLinkStyle = (string) $oklchLinkMatches[1];
}
$assert(
    array( 'top' => '8px', 'right' => '24px', 'bottom' => '8px', 'left' => '24px' ) === ( $oklchButtonBlock['attrs']['style']['spacing']['padding'] ?? array() ),
    'authored inline padding bakes onto core/button style.spacing.padding',
    $oklchPadding
);
$assert(
    str_contains($oklchLinkStyle, 'padding-top:8px') && str_contains($oklchLinkStyle, 'padding-right:24px') && str_contains($oklchLinkStyle, 'padding-left:24px'),
    'baked padding serializes onto the native button link so it wins over Gutenberg default padding',
    $oklchLinkStyle
);

$logicalPaddingButton = ( new HtmlTransformer() )->transform(
    '<style>:root{--spacing:.25rem}.px-6{padding-inline:calc(var(--spacing) * 6)}.py-2{padding-block:calc(var(--spacing) * 2)}.cta{background:oklch(0.56 0.13 45);color:#fff}</style><a class="cta px-6 py-2" href="/start">Comecar</a>'
)->toArray();
$logicalPaddingBlock = $logicalPaddingButton['blocks'][0]['innerBlocks'][0] ?? array();
$logicalPaddingMarkup = (string) ($logicalPaddingButton['serialized_blocks'] ?? '');
$logicalPaddingSides = $logicalPaddingBlock['attrs']['style']['spacing']['padding'] ?? array();
$assert(
    array( 'top' => '0.5rem', 'right' => '1.5rem', 'bottom' => '0.5rem', 'left' => '1.5rem' ) === $logicalPaddingSides,
    'logical padding-inline/padding-block bake as plain rem lengths Gutenberg will apply',
    (string) json_encode($logicalPaddingSides)
);
$assert(
    str_contains($logicalPaddingMarkup, 'padding-top:0.5rem') && str_contains($logicalPaddingMarkup, 'padding-left:1.5rem') && ! str_contains($logicalPaddingMarkup, 'padding-top:calc('),
    'logical padding serializes onto the native button link as plain lengths, not calc()',
    $logicalPaddingMarkup
);

$logicalSidePaddingButton = ( new HtmlTransformer() )->transform(
    '<style>:root{--spacing:.25rem}.cta{padding-inline-start:calc(var(--spacing) * 4);padding-inline-end:calc(var(--spacing) * 8);padding-block-start:calc(var(--spacing) * 1);padding-block-end:calc(var(--spacing) * 3);background:#173b64;color:#fff}</style><a class="cta" href="/start">Lado</a>'
)->toArray();
$logicalSideSides = $logicalSidePaddingButton['blocks'][0]['innerBlocks'][0]['attrs']['style']['spacing']['padding'] ?? array();
$assert(
    array( 'top' => '0.25rem', 'right' => '2rem', 'bottom' => '0.75rem', 'left' => '1rem' ) === $logicalSideSides,
    'logical padding side longhands map to physical sides as plain rem lengths',
    (string) json_encode($logicalSideSides)
);

$preflightButton = ( new HtmlTransformer() )->transform(
    '<style>*,::before,::after{box-sizing:border-box;border:0 solid;padding:0}.cta{padding:8px 24px;background:oklch(0.56 0.13 45);color:#fff;border-radius:9999px}</style><a class="cta" href="/x">Agendar</a>'
)->toArray();
$preflightCss = implode("\n", array_column($preflightButton['assets'] ?? array(), 'content'));
$assert(
    ! preg_match('/wp-block-button__link\{[^}]*padding:\s*0!important/', $preflightCss),
    'Tailwind preflight padding:0 is not forced onto the native button link',
    $preflightCss
);

$negatedPreflightButton = ( new HtmlTransformer() )->transform(
    '<style>*:not(:where(.keep)),:after,:before,::backdrop{box-sizing:border-box;border:0 solid;padding:0}'
    . ':root{--spacing:.25rem}.px-6{padding-inline:calc(var(--spacing) * 6)}.py-2{padding-block:calc(var(--spacing) * 2)}'
    . '.cta{background:oklch(0.56 0.13 45);color:#fff;display:inline-flex}</style>'
    . '<a class="cta px-6 py-2" href="/x">Agendar</a>'
)->toArray();
$negatedPreflightCss = implode("\n", array_column($negatedPreflightButton['assets'] ?? array(), 'content'));
$assert(
    ! preg_match('/wp-block-button__link\)?\{[^}]*padding:\s*0!important/', $negatedPreflightCss),
    'Tailwind v4 negated universal preflight does not force padding:0!important onto the button link',
    $negatedPreflightCss
);

// -- core/button declares `color.__experimentalSkipSerialization: true` and
// `__experimentalBorder.__experimentalSkipSerialization: true` because its
// save() merges colorProps.style and borderProps.style into the link's own
// style attribute — the same statement the spacing and typography filters
// above it already make. Reading those flags as "this block has no colour or
// border" dropped the authored paint from the block entirely, leaving the
// theme's styles.elements.button to repaint the button while only the CSS
// carrier still held the author's value.
$paintedButton = ( new HtmlTransformer() )->transform(
    '<html><body><div><button class="pill" type="button">All 06</button></div></body></html>',
    array( 'static_css' => '.pill{border-radius:9999px;background:#1b2a3a;border:1px solid #2d4257;padding:8px 16px;color:#e6f0ff}' )
)->toArray();
$paintedBlock = $paintedButton['blocks'][0]['innerBlocks'][0] ?? array();
$paintedStyle = $paintedBlock['attrs']['style'] ?? array();
$paintedMarkup = (string) ($paintedButton['serialized_blocks'] ?? '');
$paintedCss = implode("\n", array_column($paintedButton['assets'] ?? array(), 'content'));

$assert(
    array( 'background' => '#1b2a3a', 'text' => '#e6f0ff' ) === ($paintedStyle['color'] ?? null),
    'authored button colour survives block-support normalization into style.color',
    (string) json_encode($paintedStyle['color'] ?? null)
);
$assert(
    array( 'width' => '1px', 'style' => 'solid', 'color' => '#2d4257', 'radius' => '9999px' ) === ($paintedStyle['border'] ?? null),
    'authored button border survives block-support normalization into style.border',
    (string) json_encode($paintedStyle['border'] ?? null)
);
$assert(
    str_contains($paintedMarkup, 'class="wp-block-button__link has-text-color has-background has-border-color wp-element-button"')
        && str_contains($paintedMarkup, 'color:#e6f0ff')
        && str_contains($paintedMarkup, 'background-color:#1b2a3a')
        && str_contains($paintedMarkup, 'border-color:#2d4257')
        && str_contains($paintedMarkup, 'border-radius:9999px'),
    'authored button colour and border serialize onto the link with the support classes core/button save() emits',
    $paintedMarkup
);
$assert(
    str_contains($paintedCss, 'background-color:#1b2a3a!important') && str_contains($paintedCss, 'color:#e6f0ff!important'),
    'the CSS carrier keeps its copy of the authored button paint',
    $paintedCss
);
$assert(
    'pass' === ($paintedButton['source_reports']['wp_block_validity']['status'] ?? ''),
    'a painted button still passes generated WordPress block validity checks',
    (string) ($paintedButton['source_reports']['wp_block_validity']['status'] ?? '(none)')
);

// An authored alpha channel is a plain part of the value: it has to reach the
// attribute byte-for-byte rather than being flattened to an opaque colour.
$translucentButton = ( new HtmlTransformer() )->transform(
    '<html><body><div><button class="pill" type="button">All 06</button></div></body></html>',
    array( 'static_css' => '.pill{padding:8px 16px;background:rgba(27,42,58,0.35);border:1px solid rgba(45,66,87,0.5);color:rgba(230,240,255,0.7)}' )
)->toArray();
$translucentStyle = $translucentButton['blocks'][0]['innerBlocks'][0]['attrs']['style'] ?? array();
$translucentMarkup = (string) ($translucentButton['serialized_blocks'] ?? '');

$assert(
    'rgba(230,240,255,0.7)' === ($translucentStyle['color']['text'] ?? null)
        && 'rgba(27,42,58,0.35)' === ($translucentStyle['color']['background'] ?? null)
        && 'rgba(45,66,87,0.5)' === ($translucentStyle['border']['color'] ?? null),
    'a translucent authored button colour reaches the block attribute with its alpha channel intact',
    (string) json_encode($translucentStyle)
);
$assert(
    str_contains($translucentMarkup, 'color:rgba(230,240,255,0.7)')
        && str_contains($translucentMarkup, 'background-color:rgba(27,42,58,0.35)')
        && str_contains($translucentMarkup, 'border-color:rgba(45,66,87,0.5)'),
    'a translucent authored button colour serializes onto the link with its alpha channel intact',
    $translucentMarkup
);

// A button whose source authors no colour or border gains none, so the theme
// keeps painting it rather than having an opaque default frozen into the block.
$unpaintedButton = ( new HtmlTransformer() )->transform(
    '<style>.plain{padding:10px 16px;font-weight:600}</style><section><button class="plain" type="button">Start</button></section>'
)->toArray();
$unpaintedStyle = $unpaintedButton['blocks'][0]['innerBlocks'][0]['attrs']['style'] ?? array();
$unpaintedMarkup = (string) ($unpaintedButton['serialized_blocks'] ?? '');

$assert(
    ! isset($unpaintedStyle['color']) && ! isset($unpaintedStyle['border']),
    'a button with no authored colour or border gains neither attribute',
    (string) json_encode($unpaintedStyle)
);
$assert(
    ! str_contains($unpaintedMarkup, 'has-text-color')
        && ! str_contains($unpaintedMarkup, 'has-background')
        && ! str_contains($unpaintedMarkup, 'has-border-color')
        && ! str_contains($unpaintedMarkup, 'background-color:')
        && ! str_contains($unpaintedMarkup, 'border-color:'),
    'no paint is frozen inline onto an unpainted button link, so theme defaults keep applying',
    $unpaintedMarkup
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Button style resolver tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Button style resolver tests: {$passes} passed\n");
