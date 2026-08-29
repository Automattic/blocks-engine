<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};
$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html)->toArray();
$css = static function (array $result): string {
    $parts = array();
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( 'css' === ($asset['kind'] ?? '') ) {
            $parts[] = (string) ($asset['content'] ?? '');
        }
    }
    return implode("\n", $parts);
};

$paragraph = $transform('<style>p{color:red}span{color:blue}</style><span>Loose text</span><p>Paragraph</p>');
$paragraphClass = (string) ($paragraph['blocks'][1]['attrs']['className'] ?? '');
$assert('' !== $paragraphClass && str_contains($css($paragraph), ':where(.' . $paragraphClass . '):not(blocks-engine-specificity-') && ! str_contains($paragraph['serialized_blocks'], 'core/html'), 'p type selectors retain provenance and type specificity only on canonical p serialization');

$navigationShell = $transform('<style>nav{height:60px;padding:0 28px}.nav-links{display:flex}</style><header><nav><a class="nav-logo" href="/">Logo</a><ul class="nav-links"><li><a href="#one">One</a></li></ul></nav></header>');
$navigationShellCss = $css($navigationShell);
$navigationShellBlock = $navigationShell['blocks'][0] ?? array();
$navigationMenuBlock = $navigationShellBlock['innerBlocks'][1] ?? array();
$navigationShellClass = (string) ($navigationShellBlock['attrs']['className'] ?? '');
preg_match('/blocks-engine-source-nav-[^\s]+/', $navigationShellClass, $navigationShellMarker);
$assert(isset($navigationShellMarker[0]) && ! str_contains((string) ($navigationMenuBlock['attrs']['className'] ?? ''), 'blocks-engine-source-nav-') && str_contains($navigationShellCss, ':where(.' . $navigationShellMarker[0] . '):not(blocks-engine-specificity-') && ! preg_match('/(^|[},])nav\s*\{/', $navigationShellCss), 'nav type selectors stay scoped to the canonical source navigation shell instead of matching nested core navigation markup');

$controls = $transform('<style>a.cta:hover{padding:1rem}button.cta:focus{padding:2rem}</style><a class="cta" href="/go" style="padding:1px;background:#000">Go</a><button class="cta" style="padding:1px;background:#000">Send</button>');
$controlCss = $css($controls);
$assert(2 === substr_count($controlCss, '> :where(.wp-block-button__link)') && str_contains($controlCss, ':hover') && str_contains($controlCss, ':focus'), 'promoted anchors and native buttons project dynamic selectors onto their links once');

$order = $transform('<style>a.cta:hover{color:red}a.cta:hover{color:blue}</style><a class="cta" href="/go" style="padding:1px;background:#000">Go</a>');
$orderCss = $css($order);
$assert(strpos($orderCss, 'color:red') < strpos($orderCss, 'color:blue'), 'projected selectors preserve authored rule order for cascade precedence');

$specificity = $transform('<style>a.cta{color:red}.cta{color:blue}p{color:red}*{color:blue}.copy{color:green}</style><a class="cta" href="/go" style="padding:1px;background:#000">Go</a><p class="copy">Paragraph</p>');
$specificityCss = $css($specificity);
$specificityAuthorCss = strstr($specificityCss, "\n\n.blocks-engine-control-", true) ?: $specificityCss;
$assert(str_contains($specificityAuthorCss, ':not(blocks-engine-specificity-') && strrpos($specificityAuthorCss, 'color:red') < strrpos($specificityAuthorCss, 'color:blue') && strrpos($specificityAuthorCss, 'color:blue') < strrpos($specificityAuthorCss, 'color:green'), 'type-specificity shims preserve the authored a.cta and p cascade ordering against later class and universal rules');

$important = $transform('<style>a.cta:hover{padding:1rem!important}.cta:hover{padding:2rem}</style><a class="cta" href="/go" style="padding:1px;background:#000">Go</a>');
$assert(str_contains($css($important), '> :where(.wp-block-button__link):hover{padding:1rem!important}') && strpos($css($important), 'padding:1rem!important') < strpos($css($important), 'padding:2rem'), 'projected selectors preserve !important declarations and authored cascade order');

$shared = $transform('<style>.shared{margin:1px}p.shared{color:green}*{color:blue}</style><a class="shared cta" href="/go" style="padding:1px;background:#000">Go</a><button class="shared" style="padding:1px;background:#000">Send</button><p class="shared wp-block-button">Other</p>');
$sharedCss = $css($shared);
$assert(! str_contains($sharedCss, ':not(.wp-block-button)') && str_contains($sharedCss, '.shared:not(:where(.blocks-engine-control-') && 1 === substr_count($sharedCss, '{margin:1px}') && str_contains($sharedCss, ':not(blocks-engine-specificity-') && str_contains($sharedCss, '*:not(:where('), 'shared selectors exclude only matched control markers without changing specificity or excluding authored wp-block-button classes');

$relations = $transform('<style>p{margin:0}p + a.cta{color:red}p ~ button.cta{color:blue}main > p + a.cta{padding:1rem}</style><main><p>Before</p><a class="cta" href="/go" style="padding:1px;background:#000">Go</a><button class="cta" style="padding:1px;background:#000">Send</button></main>');
$relationCss = $css($relations);
$assert(str_contains($relationCss, ':where(.blocks-engine-source-p-') && 3 === substr_count($relationCss, '> :where(.wp-block-button__link)') && ! str_contains($relationCss, 'p + a.cta'), 'child and sibling source matches project through exact controls while independent p selectors retain provenance');

$base = '<style>.blocks-engine-source-p-deadbeef-0{display:block}p{color:red}</style><p>Collision</p>';
$baseCss = $css($transform($base));
preg_match_all('/blocks-engine-source-p-([a-f0-9]+)-(\d+)/', $baseCss, $matches);
$match = array( end($matches[0]) ?: '', end($matches[1]) ?: '', (int) (end($matches[2]) ?: -1) );
$collision = '<style>.' . ($match[0] ?? 'missing') . '{display:block}p{color:red}</style><p>Collision</p>';
$collisionResult = $transform($collision);
$assert('' !== $match[0] && str_contains($css($collisionResult), 'blocks-engine-source-p-' . $match[1] . '-' . ($match[2] + 1)), 'marker collisions select the deterministic next candidate');

$nested = $transform('<style>@media (min-width:1px){@supports (display:grid){p + a.cta:hover{padding:1rem}}}</style><p>Before</p><a class="cta" href="/go" style="padding:1px;background:#000">Go</a>');
$assert(str_contains($css($nested), '@media') && str_contains($css($nested), '@supports') && str_contains($css($nested), '> :where(.wp-block-button__link):hover'), 'nested media and supports rules preserve declarations while projecting selectors');

$rootChildren = $transform('<style>body > *{position:relative;z-index:1}section{color:red}</style><section id="hero"><h1>Hero</h1></section><section id="features"><h2>Features</h2></section>');
$rootChildrenMarkup = (string) ($rootChildren['serialized_blocks'] ?? '');
$rootChildrenCss = $css($rootChildren);
preg_match_all('/blocks-engine-root-child-[a-f0-9]+-\d+/', $rootChildrenMarkup . "\n" . $rootChildrenCss, $rootChildMarkers);
$assert(2 === count(array_unique($rootChildMarkers[0] ?? array())) && str_contains($rootChildrenCss, ':where(.blocks-engine-root-child-') && str_contains($rootChildrenCss, 'section{color:red}') && 'pass' === ($rootChildren['source_reports']['wp_block_validity']['status'] ?? ''), 'root-child selectors project through isolated markers without rewriting unrelated selectors for the same elements');

$rootShells = $transform('<style>body > *{position:relative;z-index:1}</style><header><p>Header</p></header><main><p>Body</p></main><footer><p>Footer</p></footer>');
$rootShellCss = $css($rootShells);
$assert(str_contains($rootShellCss, ':where(header.wp-block-template-part)') && str_contains($rootShellCss, ':where(footer.wp-block-template-part)') && 1 === substr_count($rootShellCss, ':where(.blocks-engine-root-child-'), 'root-child selectors target canonical template-part wrappers while page content retains isolated marker identities');

$attributes = $transform('<style>[data-cta]:focus{color:red}[aria-label]{padding:1rem}[data-kind^="primary"]{margin:1rem}#cta-id.cta{border-width:1px}</style><a id="cta-id" class="cta" data-cta aria-label="Start" data-kind="primary-action" href="/go" style="padding:1px;background:#000">Go</a>');
$attributeCss = $css($attributes);
$attributeFallbacks = array_values(array_filter($attributes['fallbacks'] ?? array(), static fn (array $fallback): bool => 'html_stylable_button_accessible_name_fallback' === ($fallback['diagnostic_code'] ?? null)));
$attributeFallback = $attributeFallbacks[0] ?? array();
$assert(str_contains((string) ($attributes['serialized_blocks'] ?? ''), '<!-- wp:html') && str_contains((string) ($attributeFallback['html'] ?? ''), 'aria-label="Start"'), 'a materially different anchor accessible name remains a diagnostic fallback rather than becoming an invalid native button');

$attributeProjection = $transform('<style>.form-shell{display:flex}.form-shell [data-role="label"]{flex-grow:1}.animated:not([data-state="settled"]){animation:fade 1s backwards paused}</style><div class="form-shell"><div data-role="label">Label</div></div><div class="animated" data-state="settled">Visible</div>');
$attributeProjectionMarkup = (string) ($attributeProjection['serialized_blocks'] ?? '');
$attributeProjectionCss = $css($attributeProjection);
preg_match_all('/blocks-engine-attribute-[a-f0-9]+-\d+/', $attributeProjectionMarkup, $attributeMarkers);
preg_match('/blocks-engine-attribute-state-[a-f0-9]+-\d+/', $attributeProjectionMarkup, $attributeStateMarker);
$assert(
    1 === count(array_unique($attributeMarkers[0] ?? array()))
    && str_contains($attributeProjectionCss, ':where(.blocks-engine-attribute-')
    && str_contains($attributeProjectionCss, 'flex-grow:1')
    && isset($attributeStateMarker[0])
    && str_contains($attributeProjectionCss, ':not(.' . $attributeStateMarker[0] . ')')
    && ! str_contains($attributeProjectionMarkup, 'data-role=')
    && ! str_contains($attributeProjectionMarkup, 'data-state=')
    && 'pass' === ($attributeProjection['source_reports']['wp_block_validity']['status'] ?? ''),
    'rightmost data-attribute flex-grow and settled negated state selectors project through valid synthetic markers without source attributes'
);

$zeroWidthControl = $transform('<style>.skip{position:absolute;left:50%;width:0;height:0;padding:0 24px}</style><button class="skip">Skip</button>');
$zeroWidthControlCss = $css($zeroWidthControl);
$assert(str_contains($zeroWidthControlCss, ':where(.wp-block-buttons){position:absolute;left:50%;width:0;height:0}') && str_contains($zeroWidthControlCss, '> :where(.wp-block-button__link){padding:0 24px}'), 'control dimensions and positioning stay on the native wrapper while inner paint remains on the button link');

$wrapper = $transform('<style>.wrap a.cta:hover{padding:1rem}.wrap a.cta:focus{color:red}</style><div class="wrap" role="button"><a class="cta" href="/go">Go</a></div>');
$wrapperCss = $css($wrapper);
$wrapperButton = $wrapper['blocks'][0]['innerBlocks'][0] ?? array();
$assert('core/button' === ($wrapperButton['blockName'] ?? '') && str_contains((string) ($wrapperButton['attrs']['className'] ?? ''), 'blocks-engine-control-') && 2 === substr_count($wrapperCss, '> :where(.wp-block-button__link)') && str_contains($wrapperCss, ':hover') && str_contains($wrapperCss, ':focus') && 'pass' === ($wrapper['source_reports']['wp_block_validity']['status'] ?? ''), 'wrapper-driven role=button promotion retains logical anchor selectors, presentation wrapper attributes, and valid blocks');

$nestedControl = $transform('<style>.action-shell{display:inline-flex;align-items:center;gap:8px;margin-top:1rem;padding:12px 20px;min-width:14rem;border:2px solid #123456;border-radius:999px;background:#123456;color:#fff;font-weight:700}.action-shell:hover{background:#234567}.action-shell:focus{outline:2px solid #fff}</style><div class="action-shell" role="button"><a href="/go"><span>Go now</span></a></div>');
$nestedControlMarkup = (string) ($nestedControl['serialized_blocks'] ?? '');
$nestedControlCss = $css($nestedControl);
$assert(! str_contains($nestedControlMarkup, '<a href="/go"><a') && str_contains($nestedControlCss, 'margin-top:1rem') && str_contains($nestedControlCss, '.action-shell{display:inline-flex;align-items:center;gap:8px;padding:12px 20px;min-width:14rem;border:2px solid #123456;border-radius:999px;background:#123456;color:#fff;font-weight:700}') && str_contains($nestedControlCss, '.action-shell:hover{background:#234567}') && str_contains($nestedControlCss, '.action-shell:focus{outline:2px solid #fff}') && 'pass' === ($nestedControl['source_reports']['wp_block_validity']['status'] ?? ''), 'nested button wrappers retain authored layout and states while the carrier preserves external margin');

$layoutOnlyControl = $transform('<style>.layout-action{display:inline-flex;align-items:center;gap:8px;margin-top:1rem}</style><div class="layout-action" role="button"><a href="/go">Go now</a></div>');
$layoutOnlyCss = $css($layoutOnlyControl);
$assert(str_contains((string) ($layoutOnlyControl['serialized_blocks'] ?? ''), '<div class="wp-block-buttons" style="margin-top:1rem"') && str_contains($layoutOnlyCss, '.layout-action{display:inline-flex;align-items:center;gap:8px}') && ! str_contains($layoutOnlyCss, '> :where(.wp-block-button__link)'), 'layout-only source control rules remain on the canonical wrapper while external margin uses native spacing');

$directControl = $transform('<style>.control-cluster{display:flex;gap:8px}.action-control{display:inline-flex;align-items:center;gap:8px;padding:12px 20px;min-width:14rem;border:2px solid #123456;border-radius:999px;background:#123456;color:#fff;font-weight:700}.action-control:hover{background:#234567}.action-control:focus{outline:2px solid #fff}</style><div class="control-cluster"><a class="action-control" href="/go">Go now</a></div>');
$directControlMarkup = (string) ($directControl['serialized_blocks'] ?? '');
$directControlCss = $css($directControl);
preg_match('/(blocks-engine-control-[a-f0-9]+-\d+)/', $directControlMarkup, $directControlMarker);
$assert(str_contains($directControlMarkup, 'control-cluster') && str_contains($directControlMarkup, 'href="/go"') && ! str_contains($directControlMarkup, 'wp-block-blocks-engine-author-layout'), 'direct source controls retain native link content without a companion block');

$navCta = $transform('<style>a.btn.btn-primary.nav-cta{display:inline-flex;align-items:center;gap:8px;font-family:monospace;font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;padding:9px 20px;border-radius:6px;background:#e8a020;color:#050d1a}.nav-cta:hover{background:#f0ac22}.nav-cta:focus{outline:2px solid #fff}</style><main><a class="btn btn-primary nav-cta" href="#cta">Get Early Access</a></main>');
$navCtaMarkup = (string) ($navCta['serialized_blocks'] ?? '');
$navCtaCss = $css($navCta);
$assert(str_contains($navCtaMarkup, 'blocks-engine-control-') && str_contains($navCtaCss, '> :where(.wp-block-button__link){display:inline-flex') && str_contains($navCtaCss, 'font-family:monospace') && str_contains($navCtaCss, 'padding:9px 20px') && str_contains($navCtaCss, 'background:#e8a020') && str_contains($navCtaCss, ':hover{background:#f0ac22}') && str_contains($navCtaCss, ':focus{outline:2px solid #fff}') && 'pass' === ($navCta['source_reports']['wp_block_validity']['status'] ?? ''), 'class-bearing source anchors project compound, typography, paint, and pseudo-state selectors onto valid core/button links');

$controlMargin = $transform('<style>.nav-cta{margin-left:24px;font-family:monospace}</style><main><a class="nav-cta" href="#cta" style="display:inline-flex;padding:9px 20px;background:#e8a020">Get Early Access</a></main>');
$controlMarginCss = $css($controlMargin);
$controlMarginOuterClass = (string) ($controlMargin['blocks'][0]['attrs']['className'] ?? '');
$assert(! isset($controlMargin['blocks'][0]['attrs']['style']['spacing']['margin']['left']) && str_contains($controlMarginOuterClass, 'blocks-engine-control-') && str_contains($controlMarginCss, '> :where(.wp-block-button__link){font-family:monospace}') && str_contains($controlMarginCss, 'margin-left:24px') && ! str_contains($controlMarginCss, '> :where(.wp-block-button__link){margin-left:24px'), 'control margins use the carrier while typography remains on its link');

$textLockupLogo = $transform('<style>.brand{font-size:19.2px;font-weight:400;letter-spacing:.02em;text-transform:uppercase;text-decoration:none;line-height:1;display:inline-flex;align-items:center;gap:.5rem;white-space:nowrap}.brand .mark{display:inline-grid;place-items:center;width:1.9em;height:1.9em;border-radius:50%;background:#f15a36;color:#fff;font-size:.72em;letter-spacing:.02em}.brand .word span{color:#f8d849}</style><a class="brand" href="#hero"><span class="mark">SC</span><span class="word">SUPER<span>COACHING</span></span></a>');
$textLockupLogoMarkup = (string) ($textLockupLogo['serialized_blocks'] ?? '');
$textLockupLogoBlock = $textLockupLogo['blocks'][0] ?? array();
$assert(
    'core/paragraph' === ($textLockupLogoBlock['blockName'] ?? '')
    && ! str_contains($textLockupLogoMarkup, '<!-- wp:buttons')
    && ! str_contains($textLockupLogoMarkup, '<!-- wp:button')
    && str_contains($textLockupLogoMarkup, '<a href="#hero">')
    && str_contains($textLockupLogoMarkup, 'SC')
    && str_contains($textLockupLogoMarkup, 'SUPER')
    && str_contains($textLockupLogoMarkup, 'COACHING')
    && str_contains($textLockupLogoMarkup, 'display:inline-grid')
    && str_contains($textLockupLogoMarkup, 'width:1.9em')
    && str_contains($textLockupLogoMarkup, 'height:1.9em')
    && str_contains($textLockupLogoMarkup, 'border-radius:50%')
    && str_contains($textLockupLogoMarkup, 'background:#f15a36')
    && str_contains($textLockupLogoMarkup, 'color:#fff')
    && str_contains($textLockupLogoMarkup, 'color:#f8d849')
    && '19.2px' === ($textLockupLogoBlock['attrs']['style']['typography']['fontSize'] ?? '')
    && '400' === ($textLockupLogoBlock['attrs']['style']['typography']['fontWeight'] ?? '')
    && 'pass' === ($textLockupLogo['source_reports']['wp_block_validity']['status'] ?? ''),
    'classed-span text lockups use paragraphs and preserve the partial RichText-safe styling bound',
    $textLockupLogoMarkup
);

$decorativeMarkLogo = $transform('<style>.brand{display:inline-flex;align-items:center;gap:.7rem;font-size:.94rem;font-weight:750}.brand-mark{display:grid;place-items:center;width:30px;height:30px;border-radius:7px;background:#c9f27b}</style><a class="brand" href="#top" aria-label="Architecture home"><span class="brand-mark" aria-hidden="true">S</span><span>Architecture</span></a>');
$decorativeMarkLogoMarkup = (string) ($decorativeMarkLogo['serialized_blocks'] ?? '');
$decorativeMarkLogoCss = $css($decorativeMarkLogo);
$assert(
    'core/buttons' === ($decorativeMarkLogo['blocks'][0]['blockName'] ?? '')
        && 'core/button' === ($decorativeMarkLogo['blocks'][0]['innerBlocks'][0]['blockName'] ?? '')
        && str_contains($decorativeMarkLogoMarkup, '<span class="brand-mark" aria-hidden="true"')
        && str_contains($decorativeMarkLogoCss, 'background-color:transparent!important')
        && str_contains($decorativeMarkLogoCss, 'border-radius:0 !important')
        && str_contains($decorativeMarkLogoCss, 'padding-top:0!important')
        && 'pass' === ($decorativeMarkLogo['source_reports']['wp_block_validity']['status'] ?? ''),
    'logo anchors with direct decorative marks retain the neutral structured button path',
    $decorativeMarkLogoMarkup
);

$nestedDecorativeMarkLogo = $transform('<a class="brand" href="#top"><span class="word">Architecture<span class="spark" aria-hidden="true">*</span></span></a>');
$assert(
    'core/paragraph' === ($nestedDecorativeMarkLogo['blocks'][0]['blockName'] ?? ''),
    'nested decorative text does not make a visible text lockup structured button chrome',
    (string) ($nestedDecorativeMarkLogo['serialized_blocks'] ?? '')
);

// Header lockups become synthetic paragraphs. Their inner anchor must retain
// only source-proven winners; otherwise flex semantics sit on the generated
// paragraph while the inner anchor loses them.
$headerLockup = $transform(
    '<style>header{color:#fff}a{color:inherit}'
        . '.brand.lockup{display:inline-flex;align-items:center;gap:.5rem}.brand{column-gap:9rem}'
        . '.brand .mark{display:inline-grid;place-items:center;box-shadow:0 0 0 2px #fff}'
        . '.mark{place-items:end;box-shadow:none}</style>'
        . '<header><a class="brand lockup" href="/"><span class="mark">SC</span><span>SUPER</span></a></header>'
);
$headerLockupMarkup = (string) ($headerLockup['serialized_blocks'] ?? '');
$headerLockupCss = $css($headerLockup);
preg_match('/blocks-engine-synthetic-header-anchor-[a-f0-9]+/', $headerLockupMarkup, $headerAnchorMarker);
$headerAnchorClass = $headerAnchorMarker[0] ?? '';
$headerAnchorRule = '';
if ( '' !== $headerAnchorClass && preg_match('/p\.' . preg_quote($headerAnchorClass, '/') . '>a\{([^}]*)\}/', $headerLockupCss, $headerAnchorRuleMatch) ) {
    $headerAnchorRule = $headerAnchorRuleMatch[1];
}
$assert(
    '' !== $headerAnchorClass
        && str_contains($headerAnchorRule, 'color:#fff')
        && str_contains($headerAnchorRule, 'display:inline-flex')
        && str_contains($headerAnchorRule, 'align-items:center')
        && str_contains($headerAnchorRule, 'row-gap:.5rem')
        && str_contains($headerAnchorRule, 'column-gap:.5rem'),
    'header synthetic anchor carries explicit inherit colour plus authored layout winners'
);
preg_match('/--blocks-engine-richtext-marker:([^;" ]+)/', $headerLockupMarkup, $headerRichTextMarker);
$headerRichTextRule = '';
if ( isset($headerRichTextMarker[1])
    && preg_match(
        '/mark\[style\*="--blocks-engine-richtext-marker:' . preg_quote($headerRichTextMarker[1], '/') . '"\],span\[data-blocks-engine-richtext-marker="' . preg_quote($headerRichTextMarker[1], '/') . '"\]\{([^}]*)\}/',
        $headerLockupCss,
        $headerRichTextRuleMatch
    )
) {
    $headerRichTextRule = $headerRichTextRuleMatch[1];
}
$assert(
    str_contains($headerRichTextRule, 'place-items:center')
        && str_contains($headerRichTextRule, 'box-shadow:0 0 0 2px #fff')
        && ! str_contains($headerRichTextRule, 'place-items:end')
        && ! str_contains($headerRichTextRule, 'box-shadow:none'),
    'header RichText marker carries specificity winners for place-items and box-shadow'
);

$uncoloredHeaderLockup = $transform(
    '<style>.brand{display:inline-flex;align-items:center;gap:8px}</style>'
        . '<header><a class="brand" href="/"><span>Brand</span></a></header>'
);
$uncoloredHeaderCss = $css($uncoloredHeaderLockup);
preg_match('/p\.(blocks-engine-synthetic-header-anchor-[a-f0-9]+)>a\{([^}]*)\}/', $uncoloredHeaderCss, $uncoloredHeaderRule);
$assert(
    isset($uncoloredHeaderRule[2])
        && ! str_contains((string) $uncoloredHeaderRule[2], 'color:inherit'),
    'synthetic header anchor stays uncoloured when source does not state inherit'
);

$inheritedHeaderTypography = $transform(
    '<style>.label{font-family:Georgia;font-size:18px;font-style:italic;letter-spacing:.05em;line-height:1.4;text-transform:uppercase;white-space:nowrap}.brand{display:inline-flex}</style>'
        . '<header><div class="label"><a class="brand" href="/">Brand</a></div></header>'
);
$inheritedHeaderTypographyMarkup = (string) ($inheritedHeaderTypography['serialized_blocks'] ?? '');
$inheritedHeaderTypographyCss = $css($inheritedHeaderTypography);
preg_match('/p\.(blocks-engine-synthetic-header-anchor-[a-f0-9]+)>a\{([^}]*)\}/', $inheritedHeaderTypographyCss, $inheritedHeaderTypographyRule);
$inheritedHeaderTypographyCarrier = (string) ($inheritedHeaderTypographyRule[2] ?? '');
$assert(
    str_contains($inheritedHeaderTypographyMarkup, 'wp-block-group label')
        && str_contains($inheritedHeaderTypographyCss, '.label{font-family:Georgia;font-size:18px')
        && str_contains($inheritedHeaderTypographyCarrier, 'display:inline-flex')
        && ! preg_match('/(?:font-family|font-size|font-style|letter-spacing|line-height|text-transform|white-space):/', $inheritedHeaderTypographyCarrier),
    'header anchor carrier leaves typography to the reconstructed ancestor chain while retaining non-inherited layout'
);

$mediaLogo = $transform('<a id="brand" href="/"><picture><img src="logo.png" alt=""></picture><span>Brand</span></a>');
$mediaLogoMarkup = (string) ($mediaLogo['serialized_blocks'] ?? '');
$assert(
    'core/buttons' === ($mediaLogo['blocks'][0]['blockName'] ?? '')
    && 'core/button' === ($mediaLogo['blocks'][0]['innerBlocks'][0]['blockName'] ?? '')
    && str_contains($mediaLogoMarkup, '<picture>')
    && str_contains($mediaLogoMarkup, '<img src="logo.png" alt="">'),
    'ID-signaled logo anchors with embedded media retain the structured core button path',
    $mediaLogoMarkup
);

$buttonRichTextMarker = $transform('<style>.brand-control{display:inline-flex;padding:8px 12px;background:#173e30;color:#f8fff9}.brand-mark{display:grid;place-items:center;width:30px;height:30px;border-radius:7px;background:#c9f27b;color:#17231d}</style><a class="brand-control" href="/"><span class="brand-mark">S</span><span>Static Site Importer</span></a>');
$buttonRichTextMarkerMarkup = (string) ($buttonRichTextMarker['serialized_blocks'] ?? '');
$buttonRichTextMarkerCss = $css($buttonRichTextMarker);
preg_match('/data-blocks-engine-richtext-marker="([^"]+)"/', $buttonRichTextMarkerMarkup, $buttonRichTextMarkerMatch);
$buttonRichTextMarkerValue = $buttonRichTextMarkerMatch[1] ?? '';
$assert(str_contains($buttonRichTextMarkerMarkup, '<!-- wp:button') && '' !== $buttonRichTextMarkerValue && str_contains($buttonRichTextMarkerMarkup, '<span class="brand-mark" data-blocks-engine-richtext-marker="' . $buttonRichTextMarkerValue) && str_contains($buttonRichTextMarkerCss, 'span[data-blocks-engine-richtext-marker="' . $buttonRichTextMarkerValue . '"]') && str_contains($buttonRichTextMarkerCss, 'display:grid;place-items:center;width:30px;height:30px') && ! str_contains($buttonRichTextMarkerMarkup, 'blocks-engine-semantic-') && ! str_contains($buttonRichTextMarkerMarkup, '<!-- wp:html') && 'pass' === ($buttonRichTextMarker['source_reports']['wp_block_validity']['status'] ?? ''), 'CSS-addressed display-grid spans inside promoted control RichText retain matching marker carriers and valid nested span topology');

$svgLogo = $transform('<style>.logo{display:inline-flex;align-items:center;gap:.6rem}.logo-mark{width:38px;height:38px;display:grid;place-items:center;flex:none}.logo-mark svg{width:22px;height:22px}</style><header><a class="logo" href="/" aria-label="Home"><span class="logo-mark"><svg viewBox="0 0 38 38" aria-hidden="true"><circle cx="19" cy="19" r="18"/></svg></span><span class="logo-text">Block Party</span></a></header>');
$svgLogoMarkup = (string) ($svgLogo['serialized_blocks'] ?? '');
$assert(str_contains($svgLogoMarkup, '<span class="logo-mark" style="width:38px;height:38px;display:grid;place-items:center"') && str_contains($svgLogoMarkup, '<img src="assets/materialized-svg/') && str_contains($svgLogoMarkup, '<span class="logo-text">Block Party</span>') && 1 === count(array_filter($svgLogo['assets'] ?? array(), static fn (array $asset): bool => 'inline-svg' === ($asset['source'] ?? ''))) && 'pass' === ($svgLogo['source_reports']['wp_block_validity']['status'] ?? ''), 'structured text logos preserve passive inline SVG artwork and native RichText-safe container geometry');

$gridTextControl = $transform('<style>.grid-cta{padding:8px 12px;background:#123456;color:#fff}.grid-cta .grid-label{width:30px;height:30px;display:grid;place-items:center}</style><a class="grid-cta" href="/go"><span class="grid-label">Go</span></a>');
$gridTextControlCss = $css($gridTextControl);
$assert('core/button' === ($gridTextControl['blocks'][0]['innerBlocks'][0]['blockName'] ?? '') && str_contains($gridTextControlCss, 'width:30px;height:30px;display:grid;place-items:center') && ! str_contains((string) ($gridTextControl['serialized_blocks'] ?? ''), 'core/html') && 'pass' === ($gridTextControl['source_reports']['wp_block_validity']['status'] ?? ''), 'text-bearing grid descendants retain place-items projection inside promoted native controls');

$plainSvgLogo = $transform('<style>.nav-logo{display:flex;align-items:center;gap:10px;margin-right:auto}</style><header><a class="nav-logo" href="/" aria-label="Home"><svg width="28" height="28" viewBox="0 0 28 28" aria-hidden="true"><circle cx="14" cy="14" r="13"/></svg>Relay Atlas</a></header>');
$plainSvgLogoMarkup = (string) ($plainSvgLogo['serialized_blocks'] ?? '');
$plainSvgLogoCss = $css($plainSvgLogo);
$assert(str_contains($plainSvgLogoCss, 'margin-right:auto') && str_contains($plainSvgLogoMarkup, '<img src="assets/materialized-svg/') && str_contains($plainSvgLogoMarkup, 'Relay Atlas') && ! str_contains($plainSvgLogoMarkup, '<!-- wp:html') && 'pass' === ($plainSvgLogo['source_reports']['wp_block_validity']['status'] ?? ''), 'linked text logos preserve unclassed inline artwork and external spacing in block-valid structured chrome');

$iconOnlyButton = $transform('<style>.toolbar .icon-cta{width:42px;height:42px;padding:8px;border:2px solid #111;border-radius:50%;background:#f4c542}.toolbar .icon-cta:hover{background:#111}.toolbar .icon-cta:focus{outline:3px solid #d14}</style><div class="toolbar"><button class="icon-cta" aria-label="Open filters" style="width:42px;height:42px"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M3 6h18M6 12h12M9 18h6"/></svg></button></div>');
$iconOnlyMarkup = (string) ($iconOnlyButton['serialized_blocks'] ?? '');
$iconOnlyCss = $css($iconOnlyButton);
$assert(str_contains($iconOnlyMarkup, '<button type="button" class="wp-block-button__link') && str_contains($iconOnlyMarkup, 'title="Open filters"') && str_contains($iconOnlyMarkup, '<img src="assets/materialized-svg/') && ! str_contains($iconOnlyMarkup, '>Open filters</button>') && ! str_contains($iconOnlyMarkup, 'aria-label=') && str_contains($iconOnlyCss, '{width:42px !important;height:42px !important}') && str_contains($iconOnlyCss, ':where(.wp-block-buttons){width:42px;height:42px}') && str_contains($iconOnlyCss, '> :where(.wp-block-button__link){padding:8px') && str_contains($iconOnlyCss, ':hover{background:#111}') && str_contains($iconOnlyCss, ':focus{outline:3px solid #d14}') && 1 === count(array_filter($iconOnlyButton['assets'] ?? array(), static fn (array $asset): bool => 'inline-svg' === ($asset['source'] ?? ''))) && 'pass' === ($iconOnlyButton['source_reports']['wp_block_validity']['status'] ?? ''), 'direct icon-only buttons retain sanitized SVG artwork, a core-valid accessible title, wrapper geometry, and link-projected chrome without synthesized visible text');

$labeledIconButton = $transform('<style>.ins-block span{font-size:.68rem;font-weight:600}</style><button class="ins-block"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3h18v18H3z"/></svg><span>Paragraph</span></button>');
$labeledIconButtonMarkup = (string) ($labeledIconButton['serialized_blocks'] ?? '');
$assert(str_contains($labeledIconButtonMarkup, '<img src="assets/materialized-svg/') && str_contains($labeledIconButtonMarkup, 'font-size:.68rem') && str_contains($labeledIconButtonMarkup, 'font-weight:600') && str_contains($labeledIconButtonMarkup, '>Paragraph</span>') && 1 === count(array_filter($labeledIconButton['assets'] ?? array(), static fn (array $asset): bool => 'inline-svg' === ($asset['source'] ?? ''))) && 'pass' === ($labeledIconButton['source_reports']['wp_block_validity']['status'] ?? ''), 'labeled controls preserve passive inline SVG artwork and descendant typography beside their visible RichText label');

$wrappedPercentageIconButton = $transform('<button><span>WhatsApp</span><div class="icon-wrap" data-source-visual-width="34.75898" data-source-visual-height="34.916664"><svg viewBox="0 0 34.759 34.917" width="100%" height="100%" data-figma-vector="true" aria-hidden="true"><path d="M0 0h34.759v34.917z"/></svg></div></button>');
$wrappedPercentageIconMarkup = (string) ($wrappedPercentageIconButton['serialized_blocks'] ?? '');
$assert(str_contains($wrappedPercentageIconMarkup, '<img src="assets/materialized-svg/') && str_contains($wrappedPercentageIconMarkup, 'style="width:34.759px;height:34.9167px"') && ! str_contains($wrappedPercentageIconMarkup, '<!-- wp:html') && 'pass' === ($wrappedPercentageIconButton['source_reports']['wp_block_validity']['status'] ?? ''), 'percentage-sized SVG artwork retains its concrete wrapper dimensions when promoted into native control RichText');

$gridButton = $transform('<style>.controls{display:grid;grid-template-columns:repeat(3,1fr)}</style><div class="controls"><button>One</button><button>Two</button></div>');
$assert(2 === substr_count((string) ($gridButton['serialized_blocks'] ?? ''), 'tagName":"button"'), 'direct grid buttons retain direct editable button representation');

$inlineLeaves = $transform('<style>.meta{display:flex;gap:10px}.eyebrow{display:flex;gap:10px}.meta span{font:10px monospace;border:1px solid #999;padding:2px 8px}.eyebrow span{font-size:11px;letter-spacing:.1em}</style><div class="eyebrow"><span>Beta</span></div><div class="meta"><span>One</span><span>Two</span></div>');
$inlineMarkup = (string) ($inlineLeaves['serialized_blocks'] ?? '');
$inlineCss = $css($inlineLeaves);
$assert(! str_contains($inlineMarkup, 'wp-block-blocks-engine-author-layout') && ! str_contains($inlineMarkup, 'blocks-engine-semantic-') && 3 === substr_count($inlineMarkup, '<p class="blocks-engine-inline-layout-carrier">') && str_contains($inlineMarkup, '<p class="blocks-engine-inline-layout-carrier"><span>Beta</span></p>') && str_contains($inlineCss, '.eyebrow p.blocks-engine-inline-layout-carrier > span{font-size:11px;letter-spacing:.1em}') && 'pass' === ($inlineLeaves['source_reports']['wp_block_validity']['status'] ?? ''), 'typography-only direct structural spans retain selector paths through standalone carriers without a companion block');

$listInlineLeaves = $transform('<style>.maintenance-loop li{display:grid;grid-template-columns:42px 1fr}.maintenance-loop li > span{display:grid;place-items:center;width:30px;height:30px;border-radius:50%;background:#c9f27b}</style><ol class="maintenance-loop"><li><span>1</span><div><strong>Observe</strong><p>Copy</p></div></li><li><span>2</span><div><strong>Replay</strong></div><ul><li><span>N</span><div>Nested</div></li></ul></li></ol>');
$listInlineMarkup = (string) ($listInlineLeaves['serialized_blocks'] ?? '');
$listInlineCss = $css($listInlineLeaves);
$assert(! str_contains($listInlineMarkup, '<!-- wp:html') && 2 === substr_count($listInlineMarkup, 'tagName":"ol"') + substr_count($listInlineMarkup, 'tagName":"ul"') && 3 === substr_count($listInlineMarkup, 'tagName":"li"') && str_contains($listInlineCss, ':where(.blocks-engine-semantic-') && str_contains($listInlineMarkup, 'blocks-engine-source-li-') && str_contains($listInlineCss, 'display:grid;place-items:center;width:30px;height:30px;border-radius:50%;background:#c9f27b') && str_contains($listInlineMarkup, '<p>1</p>') && str_contains($listInlineMarkup, '<p>2</p>') && str_contains($listInlineMarkup, '<p>N</p>') && str_contains($listInlineMarkup, '<p>Nested</p>') && 'pass' === ($listInlineLeaves['source_reports']['wp_block_validity']['status'] ?? ''), 'structural lists retain semantic list topology, editable native children, and projected source selectors');

$coexistingInlineFlows = $transform('<style>.card-grid{display:grid}.fallback-card > strong{display:block;margin:12px 0 4.8px}.maintenance-loop li{display:grid;grid-template-columns:42px 1fr}.maintenance-loop li > span{display:grid;place-items:center;width:30px;height:30px}</style><div class="card-grid"><div class="fallback-card"><strong>index.html</strong></div></div><ol class="maintenance-loop"><li><span>1</span><div>Observe</div></li></ol>');
$coexistingInlineFlowsMarkup = (string) ($coexistingInlineFlows['serialized_blocks'] ?? '');
$coexistingInlineFlowsCss = $css($coexistingInlineFlows);
$assert(str_contains($coexistingInlineFlowsMarkup, '<p class="blocks-engine-inline-layout-carrier"><strong>index.html</strong></p>') && str_contains($coexistingInlineFlowsCss, '.fallback-card > p.blocks-engine-inline-layout-carrier > strong{display:block}') && str_contains($coexistingInlineFlowsMarkup, 'tagName":"ol"') && str_contains($coexistingInlineFlowsMarkup, 'blocks-engine-source-li-') && str_contains($coexistingInlineFlowsCss, ':where(.blocks-engine-semantic-') && str_contains($coexistingInlineFlowsCss, 'place-items:center;width:30px;height:30px') && ! str_contains($coexistingInlineFlowsMarkup, '<!-- wp:html') && 'pass' === ($coexistingInlineFlows['source_reports']['wp_block_validity']['status'] ?? ''), 'standalone layout carriers coexist with native structural-list decomposition and projected selector markers');

$groupInlineLeaves = $transform('<style>.stage-output{display:grid}.stage-output span{font-size:13px;display:inline-block;margin:2px}.stage-output strong{font-size:15px;display:block;margin:4px}</style><div class="stage-output"><span>Label</span><strong>Value</strong></div>');
$groupInlineMarkup = (string) ($groupInlineLeaves['serialized_blocks'] ?? '');
$groupInlineCss = $css($groupInlineLeaves);
$assert(str_contains($groupInlineMarkup, '<div class="wp-block-group stage-output') && str_contains($groupInlineMarkup, '<p class="blocks-engine-inline-layout-carrier"><span>Label</span></p>') && str_contains($groupInlineMarkup, '<p class="blocks-engine-inline-layout-carrier"><strong>Value</strong></p>') && str_contains($groupInlineCss, '.stage-output p.blocks-engine-inline-layout-carrier > span{font-size:13px;display:inline-block}') && str_contains($groupInlineCss, '.stage-output p.blocks-engine-inline-layout-carrier > span{margin:2px}') && str_contains($groupInlineCss, '.stage-output p.blocks-engine-inline-layout-carrier > strong{font-size:15px;display:block}') && str_contains($groupInlineCss, '.stage-output p.blocks-engine-inline-layout-carrier > strong{margin:4px}'), 'native Group inline leaves retain projected typography, display, and margin declarations through their valid paragraph carriers');

$typographyOnlyStructuralLeaves = $transform('<style>.typography-grid{display:grid}.typography-flex{display:flex}.typography-grid > strong{font-size:13px;font-weight:600;letter-spacing:.08em}.typography-flex > strong{font-size:15px;line-height:1.2}.maintenance-loop li > span{display:grid;place-items:center;width:30px;height:30px}</style><div class="typography-grid"><strong>Grid label</strong></div><div class="typography-flex"><strong>Flex label</strong></div><ol class="maintenance-loop"><li><span>1</span><div>Observe</div></li></ol><p>Ordinary <strong>prose</strong>.</p>');
$typographyOnlyStructuralMarkup = (string) ($typographyOnlyStructuralLeaves['serialized_blocks'] ?? '');
$typographyOnlyStructuralCss = $css($typographyOnlyStructuralLeaves);
$typographyOnlyStructuralBlocks = $typographyOnlyStructuralLeaves['blocks'] ?? array();
$assert(
    'core/group' === ($typographyOnlyStructuralBlocks[0]['blockName'] ?? '')
    && 'core/paragraph' === ($typographyOnlyStructuralBlocks[0]['innerBlocks'][0]['blockName'] ?? '')
    && 'core/group' === ($typographyOnlyStructuralBlocks[1]['blockName'] ?? '')
    && 'core/paragraph' === ($typographyOnlyStructuralBlocks[1]['innerBlocks'][0]['blockName'] ?? '')
    && str_contains($typographyOnlyStructuralMarkup, '<p class="blocks-engine-inline-layout-carrier"><strong>Grid label</strong></p>')
    && str_contains($typographyOnlyStructuralMarkup, '<p class="blocks-engine-inline-layout-carrier"><strong>Flex label</strong></p>')
    && str_contains($typographyOnlyStructuralCss, '.typography-grid > p.blocks-engine-inline-layout-carrier > strong{font-size:13px;font-weight:600;letter-spacing:.08em}')
    && str_contains($typographyOnlyStructuralCss, '.typography-flex > p.blocks-engine-inline-layout-carrier > strong{font-size:15px;line-height:1.2}')
    && str_contains($typographyOnlyStructuralMarkup, 'typography-grid blocks-engine-css-owned-layout')
    && str_contains($typographyOnlyStructuralMarkup, 'typography-flex blocks-engine-css-owned-layout')
    && str_contains($typographyOnlyStructuralMarkup, 'tagName":"ol"')
    && str_contains($typographyOnlyStructuralMarkup, 'blocks-engine-source-li-')
    && str_contains($typographyOnlyStructuralCss, ':where(.blocks-engine-semantic-')
    && ! str_contains($typographyOnlyStructuralMarkup, '<!-- wp:html')
    && str_contains($typographyOnlyStructuralMarkup, '<p>Ordinary <strong>prose</strong>.</p>')
    && 'pass' === ($typographyOnlyStructuralLeaves['source_reports']['wp_block_validity']['status'] ?? ''),
    'direct Grid and Flex typography-only strong leaves retain valid standalone carriers while native structural lists preserve selector addressability and ordinary prose stays RichText'
);

$listInlineLeaves = $transform('<style>.maintenance-loop{display:grid}.maintenance-loop li > span{display:inline-block;width:10px;height:10px;border-radius:50%;background:#e8a020}</style><ul class="maintenance-loop"><li><span>Build</span></li><li><span>Verify</span><ul><li><span>Nested</span></li></ul></li></ul>');
$listInlineMarkup = (string) ($listInlineLeaves['serialized_blocks'] ?? '');
$listInlineCss = $css($listInlineLeaves);
$assert(3 === substr_count($listInlineMarkup, '--blocks-engine-richtext-marker:') && str_contains($listInlineCss, 'width:10px;height:10px;border-radius:50%;background:#e8a020') && 3 === substr_count($listInlineCss, 'mark[style*="--blocks-engine-richtext-marker:') && str_contains($listInlineMarkup, '>Build</mark>') && str_contains($listInlineMarkup, '>Verify</mark><!-- wp:list -->') && str_contains($listInlineMarkup, '>Nested</mark>') && 'pass' === ($listInlineLeaves['source_reports']['wp_block_validity']['status'] ?? ''), 'native List/Grid direct span circles retain projected selector markers while nested list content stays in its own list item');

$repeatedParents = $transform('<style>.row{display:flex}.row .pill{padding:2px 8px;border:1px solid #999}.other .pill{color:red}</style><div class="row"><span class="pill">First</span></div><div class="row"><span class="pill">Second</span></div><div class="other"><span class="pill">Third</span></div>');
$repeatedMarkup = (string) ($repeatedParents['serialized_blocks'] ?? '');
$repeatedCss = $css($repeatedParents);
$assert(2 === substr_count($repeatedMarkup, '<p class="blocks-engine-inline-layout-carrier">') && str_contains($repeatedCss, '.row p.blocks-engine-inline-layout-carrier > .pill{padding:2px 8px;border:1px solid #999}') && str_contains($repeatedMarkup, '--blocks-engine-richtext-marker:blocks-engine-richtext-') && str_contains($repeatedCss, 'mark[style*="--blocks-engine-richtext-marker:blocks-engine-richtext-'), 'repeated structural leaves share selector projection without leaking their box styles into an unrelated inline sibling');

$richTextPill = $transform('<style>p .pill{padding:2px 8px;border:1px solid #999}</style><p>Read <span class="pill">more</span>.</p>');
$richTextPillMarkup = (string) ($richTextPill['serialized_blocks'] ?? '');
$richTextPillCss = $css($richTextPill);
$assert(str_contains($richTextPillMarkup, '<mark class="pill"') && str_contains($richTextPillMarkup, '--blocks-engine-richtext-marker:blocks-engine-richtext-') && str_contains($richTextPillCss, 'mark[style*="--blocks-engine-richtext-marker:blocks-engine-richtext-') && 'pass' === ($richTextPill['source_reports']['wp_block_validity']['status'] ?? ''), 'RichText-contained selector hooks survive through valid mark formatting and projected CSS');

$richTextColor = $transform('<style>:root{--amber:#e8a020}.quote-mark{font-size:4rem;color:var(--amber)}</style><p><span class="quote-mark">&quot;</span>Testimonial</p>');
$richTextColorMarkup = (string) ($richTextColor['serialized_blocks'] ?? '');
$richTextColorCss = $css($richTextColor);
$assert(str_contains($richTextColorMarkup, '--blocks-engine-richtext-marker:blocks-engine-richtext-') && ! str_contains($richTextColorMarkup, 'color:inherit') && ! str_contains($richTextColorMarkup, 'background-color:transparent') && str_contains($richTextColorCss, ':where(mark)[style*="--blocks-engine-richtext-marker:"]{background-color:transparent;color:inherit}') && str_contains($richTextColorCss, '{font-size:4rem;color:var(--amber)}') && strpos($richTextColorCss, 'color:inherit') < strpos($richTextColorCss, 'color:var(--amber)'), 'RichText marker defers transparent background to the preceding reset CSS while explicit author color remains authoritative');

$markStaticLonghand = $transform('<style>.hl{background-color:gold}</style><p><span class="hl">Static longhand</span></p>');
$markStaticLonghandMarkup = (string) ($markStaticLonghand['serialized_blocks'] ?? '');
$assert(str_contains($markStaticLonghandMarkup, '<mark class="hl"') && str_contains($markStaticLonghandMarkup, 'background-color:gold') && ! str_contains($markStaticLonghandMarkup, 'background-color:transparent'), 'authored static longhand background survives RichText mark projection');

$markStaticShorthand = $transform('<style>.hl{background:gold}</style><p><span class="hl">Static shorthand</span></p>');
$markStaticShorthandMarkup = (string) ($markStaticShorthand['serialized_blocks'] ?? '');
$assert(str_contains($markStaticShorthandMarkup, '<mark class="hl"') && str_contains($markStaticShorthandMarkup, 'background:gold') && ! str_contains($markStaticShorthandMarkup, 'background-color:transparent'), 'authored static shorthand background survives RichText mark projection');

$markConditionalLonghand = $transform('<style>@media(min-width:1px){.hl{background-color:gold}}</style><p><span class="hl">Conditional longhand</span></p>');
$markConditionalLonghandMarkup = (string) ($markConditionalLonghand['serialized_blocks'] ?? '');
$markConditionalLonghandCss = $css($markConditionalLonghand);
$assert(str_contains($markConditionalLonghandMarkup, '<mark class="hl"') && str_contains($markConditionalLonghandCss, '@media(min-width:1px)') && str_contains($markConditionalLonghandCss, 'background-color:gold') && ! str_contains($markConditionalLonghandMarkup, 'background-color:transparent'), 'authored conditional longhand background survives RichText mark projection');

$markInlineLonghand = $transform('<p><span class="hl" style="background-color:gold">Inline longhand</span></p>');
$markInlineLonghandMarkup = (string) ($markInlineLonghand['serialized_blocks'] ?? '');
$assert(str_contains($markInlineLonghandMarkup, '<mark class="hl"') && str_contains($markInlineLonghandMarkup, 'background-color:gold') && ! str_contains($markInlineLonghandMarkup, 'background-color:transparent'), 'authored inline longhand background survives RichText mark projection');

$richTextPunctuation = $transform('<style>.quote-mark{font-size:4rem}</style><p><span class="quote-mark">"</span>The team\'s launch</p>');
$richTextPunctuationMarkup = (string) ($richTextPunctuation['serialized_blocks'] ?? '');
$assert(str_contains($richTextPunctuationMarkup, '&quot;') && str_contains($richTextPunctuationMarkup, 'team&#039;s') && 'pass' === ($richTextPunctuation['source_reports']['wp_block_validity']['status'] ?? ''), 'RichText straight punctuation uses entities that retain source glyphs through WordPress texturization');

$standaloneBadge = $transform('<style>.card .badge{display:inline-block;margin-top:1rem;padding:.25rem .7rem;border:1px solid #999;border-radius:999px;background:#eee;color:#6040cc}</style><article class="card"><h2>Feature</h2><span class="badge">Stable</span></article>');
$standaloneBadgeMarkup = (string) ($standaloneBadge['serialized_blocks'] ?? '');
$assert(str_contains($standaloneBadgeMarkup, '<mark style="') && str_contains($standaloneBadgeMarkup, 'display:inline-block') && str_contains($standaloneBadgeMarkup, 'padding:.25rem .7rem') && str_contains($standaloneBadgeMarkup, 'border-radius:999px') && str_contains($standaloneBadgeMarkup, 'background:#eee') && 'pass' === ($standaloneBadge['source_reports']['wp_block_validity']['status'] ?? ''), 'standalone RichText styling hooks carry static visual declarations without depending on runtime selector markers');

$inlineStat = $transform('<style>.stat-num{font-size:4rem}.stat-num .suffix{font-size:2rem;color:#6040cc}</style><div class="stat-num"><span data-count="43">43</span><span class="suffix">%</span></div>');
$inlineStatMarkup = (string) ($inlineStat['serialized_blocks'] ?? '');
$assert(1 === substr_count($inlineStatMarkup, '<!-- wp:paragraph') && str_contains($inlineStatMarkup, '>43</span><mark class="suffix"') && str_contains($inlineStatMarkup, 'font-size:2rem') && str_contains($inlineStatMarkup, '>%</mark>'), 'non-structural inline metrics and suffixes remain in one styled RichText line');

$gradientText = $transform('<style>:root{--hero:linear-gradient(90deg,#26f,#f56)}h1 .grad{background:var(--hero);background-clip:text;-webkit-background-clip:text;-webkit-text-fill-color:transparent}</style><h1>Open <span class="grad">forever</span></h1>');
$gradientTextMarkup = (string) ($gradientText['serialized_blocks'] ?? '');
$assert(str_contains($gradientTextMarkup, 'background:var(--hero)') && str_contains($gradientTextMarkup, 'background-clip:text') && str_contains($gradientTextMarkup, '-webkit-text-fill-color:transparent') && str_contains($gradientTextMarkup, 'color:transparent'), 'gradient RichText carries its clipped background and transparent text fallback inline');

$richTextStates = $transform('<style>p .pill:hover{color:red}p .pill:focus{color:blue}p .pill:active{color:green}p .pill:visited{color:purple}</style><p>Read <span class="pill">more</span>.</p>');
$richTextStatesMarkup = (string) ($richTextStates['serialized_blocks'] ?? '');
$richTextStatesCss = $css($richTextStates);
$assert(str_contains($richTextStatesMarkup, '--blocks-engine-richtext-marker:blocks-engine-richtext-') && 4 === substr_count($richTextStatesCss, 'mark[style*="--blocks-engine-richtext-marker:blocks-engine-richtext-') && str_contains($richTextStatesCss, ':hover{color:red}') && str_contains($richTextStatesCss, ':focus{color:blue}') && str_contains($richTextStatesCss, ':active{color:green}') && str_contains($richTextStatesCss, ':visited{color:purple}') && ! str_contains($richTextStatesMarkup, ':hover') && ! str_contains($richTextStatesMarkup, ':focus') && ! str_contains($richTextStatesMarkup, ':active') && ! str_contains($richTextStatesMarkup, ':visited'), 'RichText marker projections retain dynamic pseudo-state suffixes without inlining a permanent state');

$nestedLeaf = $transform('<style>.meta{display:flex}.pill{padding:2px 8px;border:1px solid #999}.meta > .item .pill{color:red}</style><div class="meta"><div class="item"><span class="pill">Nested</span></div></div>');
$nestedLeafMarkup = (string) ($nestedLeaf['serialized_blocks'] ?? '');
$nestedLeafCss = $css($nestedLeaf);
$assert(! str_contains($nestedLeafMarkup, 'blocks-engine-semantic-') && str_contains($nestedLeafMarkup, '--blocks-engine-richtext-marker:blocks-engine-richtext-') && str_contains($nestedLeafCss, 'mark[style*="--blocks-engine-richtext-marker:blocks-engine-richtext-') && 'pass' === ($nestedLeaf['source_reports']['wp_block_validity']['status'] ?? ''), 'nested selector-addressable leaves retain a valid inline marker instead of becoming a structural group');

$proseBadge = $transform('<style>.card{display:flex}.badge{padding:2px 8px;border:1px solid #999}.card .badge{color:red}</style><div class="card"><div class="copy">Read <span class="badge">new</span> notes.</div></div>');
$proseBadgeMarkup = (string) ($proseBadge['serialized_blocks'] ?? '');
$proseBadgeCss = $css($proseBadge);
$assert(! str_contains($proseBadgeMarkup, 'blocks-engine-semantic-') && str_contains($proseBadgeMarkup, '--blocks-engine-richtext-marker:blocks-engine-richtext-') && str_contains($proseBadgeCss, 'mark[style*="--blocks-engine-richtext-marker:blocks-engine-richtext-') && 'pass' === ($proseBadge['source_reports']['wp_block_validity']['status'] ?? ''), 'a padded badge inside prose in a flex card remains an inline RichText marker');

$ordinaryInline = $transform('<style>span{color:red}</style><p>Read <span>this</span> now.</p>');
$ordinaryInlineMarkup = (string) ($ordinaryInline['serialized_blocks'] ?? '');
$assert(! str_contains($ordinaryInlineMarkup, 'blocks-engine-semantic-') && 'core/paragraph' === ($ordinaryInline['blocks'][0]['blockName'] ?? '') && 'pass' === ($ordinaryInline['source_reports']['wp_block_validity']['status'] ?? ''), 'ordinary inline span styling remains RichText flow rather than becoming a group wrapper');

$gridRichText = $transform('<style>.artifact-card{display:grid;grid-template-columns:1fr auto}.artifact-card > span:not(.card-label){grid-column:2}.artifact-card .card-label{grid-column:1 / -1}</style><div class="artifact-card"><span class="card-label">Input</span><strong>index.html</strong><span>styles.css</span><span>assets/</span></div>');
$gridRichTextMarkup = (string) ($gridRichText['serialized_blocks'] ?? '');
$gridRichTextCss = $css($gridRichText);
$assert(str_contains($gridRichTextMarkup, '<!-- wp:group {"className":"artifact-card blocks-engine-css-owned-layout blocks-engine-css-owned-grid"}') && ! str_contains($gridRichTextMarkup, 'wp-block-blocks-engine-author-layout') && str_contains($gridRichTextCss, 'grid-template-columns:1fr auto') && str_contains($gridRichTextCss, 'grid-column:1 / -1') && 'pass' === ($gridRichText['source_reports']['wp_block_validity']['status'] ?? ''), 'grid cards retain selector-addressable source CSS through core/group');

$selectorIdentity = $transform('<style>.roster-card .stamp{color:#6040cc}.roster-card .stamp:hover{color:#123456}.roster-card a.view{display:inline-flex;align-items:center;gap:6px}.roster-card a.view:hover{color:#123456}</style><div class="roster-card"><p><span class="stamp" id="release-stamp" data-kind="release">New</span></p><a class="view" id="view-release" data-kind="release-link" href="/release" target="_blank" rel="noopener">View release</a><a href="/plain">Plain link</a></div>');
$selectorIdentityMarkup = (string) ($selectorIdentity['serialized_blocks'] ?? '');
$selectorIdentityCss = $css($selectorIdentity);
$assert(str_contains($selectorIdentityMarkup, '<mark class="stamp" id="release-stamp" data-kind="release"') && str_contains($selectorIdentityMarkup, '--blocks-engine-richtext-marker:') && str_contains($selectorIdentityCss, ':hover{color:#123456}') && 'pass' === ($selectorIdentity['source_reports']['wp_block_validity']['status'] ?? ''), 'selector-addressable RichText spans retain safe class, id, and data identity on their valid mark carrier through pseudo-state projection');
$assert(str_contains($selectorIdentityMarkup, '<a class="view" id="view-release" data-kind="release-link" href="/release" target="_blank" rel="noopener">View release</a>') && str_contains($selectorIdentityMarkup, '<p class="blocks-engine-synthetic-paragraph">') && ! str_contains($selectorIdentityMarkup, '<p class="view') && ! str_contains($selectorIdentityMarkup, '<p id="view-release"') && str_contains($selectorIdentityCss, '.roster-card a.view{display:inline-flex;align-items:center;gap:6px}') && str_contains($selectorIdentityCss, '.roster-card a.view:hover{color:#123456}'), 'non-button anchor class, id, and data identity belong only to the inner link while its synthetic paragraph retains independent presentation');

$linkedRichTextMarker = ( new HtmlTransformer() )->transform('<style>.shell .label{font-size:25px;color:#222}</style><div class="shell"><a href="/"><span class="label">Brand</span></a></div>')->toArray();
$linkedRichTextMarkerMarkup = (string) ($linkedRichTextMarker['serialized_blocks'] ?? '');
$linkedRichTextMarkerCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $linkedRichTextMarker['assets'] ?? array()));
$assert(str_contains($linkedRichTextMarkerMarkup, '<a href="/"><mark class="label" style="--blocks-engine-richtext-marker:blocks-engine-richtext-') && str_contains($linkedRichTextMarkerCss, 'mark[style*="--blocks-engine-richtext-marker:blocks-engine-richtext-') && 'pass' === ($linkedRichTextMarker['source_reports']['wp_block_validity']['status'] ?? ''), 'selector-addressed spans nested in non-button links retain valid RichText marker carriers');

$resetRoleButton = ( new HtmlTransformer() )->transform('<style>a{background:0 0;border:0}.label{font-size:25px}</style><a role="button"><span class="label">Contact</span></a>')->toArray();
$resetRoleButtonCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $resetRoleButton['assets'] ?? array()));
$assert(str_contains($resetRoleButtonCss, 'background-color:transparent!important') && str_contains($resetRoleButtonCss, 'border-style:none!important') && str_contains($resetRoleButtonCss, 'border-width:0!important') && ! str_contains($resetRoleButtonCss, 'border-radius:0!important'), 'native button protection carries only the properties reset by the source border declaration past later theme defaults');
$assert(str_contains($selectorIdentityMarkup, '<a href="/plain">Plain link</a>') && ! str_contains($selectorIdentityMarkup, '<a class="" href="/plain"'), 'plain links remain native links without invented source identity');
$emptyAccessibleLink = $transform('<a class="logo-link" id="site-logo" data-kind="brand" href="/" aria-label="Home"></a>');
$emptyAccessibleLinkMarkup = (string) ($emptyAccessibleLink['serialized_blocks'] ?? '');
$assert(str_contains($emptyAccessibleLinkMarkup, '<p class="blocks-engine-synthetic-paragraph"><a class="logo-link" id="site-logo" data-kind="brand" href="/" aria-label="Home"></a></p>') && ! str_contains($emptyAccessibleLinkMarkup, '<p class="logo-link') && ! str_contains($emptyAccessibleLinkMarkup, '<p id="site-logo"'), 'empty accessible links retain class, id, and data identity only on their inner anchor without duplicate wrapper IDs');

$semanticInline = $transform('<style>*{margin:0;padding:0}em,i{font-style:italic;font-weight:inherit}</style><p>Read <em>this</em> now.</p>');
$semanticInlineMarkup = (string) ($semanticInline['serialized_blocks'] ?? '');
$assert(str_contains($semanticInlineMarkup, '<em>this</em>') && ! str_contains($semanticInlineMarkup, '<mark') && str_contains($css($semanticInline), 'em,i{font-style:italic;font-weight:inherit}') && 'pass' === ($semanticInline['source_reports']['wp_block_validity']['status'] ?? ''), 'attribute-free semantic RichText keeps its native tag and author selector without a redundant mark wrapper');

$structural = $transform('<style>.product-layout{display:grid;grid-template-columns:1fr 20rem;gap:3rem}.product-layout > .detail-pane{min-width:0}</style><div class="product-layout"><div>Primary</div><aside class="detail-pane">Secondary</aside></div>');
$structuralMarkup = (string) ($structural['serialized_blocks'] ?? '');
$assert(str_contains($structuralMarkup, 'product-layout') && str_contains($structuralMarkup, 'detail-pane') && str_contains($css($structural), '.product-layout > .detail-pane') && 'pass' === ($structural['source_reports']['wp_block_validity']['status'] ?? ''), 'CSS-significant structural group and child classes survive native grid materialization');

$authorGrid = $transform('<style>.ex-row{display:grid;grid-template-columns:repeat(5,1fr);gap:1rem}@media (max-width:600px){.ex-row{grid-template-columns:1fr}}</style><section id="gallery" class="ex-row" aria-label="Gallery" data-kind="exhibition"><div>One</div><div>Two</div><div>Three</div><div>Four</div><div>Five</div></section>');
$authorGridMarkup = (string) ($authorGrid['serialized_blocks'] ?? '');
$authorGridBlock = $authorGrid['blocks'][0] ?? array();
$assert('core/group' === ($authorGridBlock['blockName'] ?? '') && 5 === count($authorGridBlock['innerBlocks'] ?? array()) && str_contains($authorGridMarkup, '<section id="gallery" class="wp-block-group') && str_contains($authorGridMarkup, 'ex-row') && str_contains($authorGridMarkup, 'blocks-engine-css-owned-layout') && ! str_contains($authorGridMarkup, 'wp-block-blocks-engine-author-layout') && str_contains($css($authorGrid), 'margin-block-start:0'), 'author grids retain their semantic core/group save shape with scoped flow neutralization');
$assert(array() === ($authorGrid['source_reports']['generated_blocks'] ?? array()), 'CSS-owned layouts add no companion block dependency');

$cardGrid = $transform('<style>.card{display:grid;grid-template-columns:1fr auto}.card > span{grid-column:2}.card > strong{grid-column:1}</style><div class="card"><span>Label</span><strong>Title</strong><span>Detail</span></div>');
$cardGridChildren = $cardGrid['blocks'][0]['innerBlocks'] ?? array();
$assert(
    'core/group' === ($cardGrid['blocks'][0]['blockName'] ?? '')
    && 3 === count($cardGridChildren)
    && str_contains((string) ($cardGrid['serialized_blocks'] ?? ''), 'wp-block-group')
    && array() === ($cardGrid['source_reports']['conversion_report']['gutenberg_incompatibilities']['author_layout_topology'] ?? array()),
    'author grid cards retain direct child order and placement selectors through core/group'
);
$nestedGridItem = $transform('<style>.grid{display:grid}.card{display:grid}.card > span{grid-column:2}</style><div class="grid"><div class="card"><span>Label</span><span>Value</span></div></div>');
$nestedGridItemBlock = $nestedGridItem['blocks'][0] ?? array();
$nestedGridItemChildren = $nestedGridItemBlock['innerBlocks'] ?? array();
$nestedGridItemCss = $css($nestedGridItem);
$assert(
    str_ends_with((string) ($nestedGridItemBlock['blockName'] ?? ''), '/layout-shell')
    && 2 === count($nestedGridItemBlock['attrs']['wrappers'] ?? array())
    && 2 === count($nestedGridItemChildren)
    && 'core/paragraph' === ($nestedGridItemChildren[0]['blockName'] ?? '')
    && str_contains((string) ($nestedGridItemChildren[0]['attrs']['className'] ?? ''), 'blocks-engine-inline-layout-carrier')
    && str_contains($nestedGridItemCss, '.card > p.blocks-engine-inline-layout-carrier > span{grid-column:2}')
    && ! str_contains((string) ($nestedGridItem['serialized_blocks'] ?? ''), 'blocks-engine-css-owned-layout-item')
    && 'pass' === ($nestedGridItem['source_reports']['wp_block_validity']['status'] ?? ''),
    'selector-addressed text layout items use one valid paragraph carrier instead of a redundant Group wrapper'
);

$boxedLayoutLeaves = $transform('<style>.marquee{display:flex}.marquee > span{display:inline-flex;align-items:center;padding:0 3rem;color:#68625a}.tags{display:flex}.tags > span{padding:.4rem .9rem;border:1px solid #6a5628;background:#0c0b09;color:#c4a35a}</style><div class="marquee"><span>Vessel Beauty</span><span>Nomad Hotels</span></div><div class="tags"><span>Brand Systems</span><span>Editorial</span></div>');
$boxedLayoutMarkup = (string) ($boxedLayoutLeaves['serialized_blocks'] ?? '');
$boxedLayoutCss = $css($boxedLayoutLeaves);
$boxedLayoutMetrics = $boxedLayoutLeaves['source_reports']['editability_report']['metrics'] ?? array();
$assert(
    2 === ($boxedLayoutMetrics['wrapper_block_count'] ?? -1)
    && 4 === substr_count($boxedLayoutMarkup, '<!-- wp:paragraph')
    && 4 === substr_count($boxedLayoutMarkup, 'blocks-engine-inline-layout-carrier') / 2
    && ! str_contains($boxedLayoutMarkup, 'blocks-engine-css-owned-layout-item')
    && str_contains($boxedLayoutCss, '.marquee > p.blocks-engine-inline-layout-carrier > span{display:inline-flex;align-items:center;padding:0 3rem;color:#68625a}')
    && str_contains($boxedLayoutCss, '.tags > p.blocks-engine-inline-layout-carrier > span{padding:.4rem .9rem;border:1px solid #6a5628;background:#0c0b09;color:#c4a35a}')
    && 'pass' === ($boxedLayoutLeaves['source_reports']['wp_block_validity']['status'] ?? ''),
    'boxed direct flex text leaves retain author CSS and editor validity without one Group per leaf'
);

$textOnlyLayoutItems = $transform('<style>.grid{display:grid;grid-template-columns:repeat(2,1fr)}</style><div class="grid"><div>One</div><div>Two</div></div>');
$assert(array() === ($textOnlyLayoutItems['source_reports']['html']['author_layout_topology'] ?? array()), 'text-only author-layout leaves do not report an element-topology change');

$mediaLayout = $transform('<style>@media (min-width:700px){@supports (display:grid){.responsive-row{display:grid;gap:2rem}}}</style><div class="responsive-row"><div>One</div><div>Two</div></div>');
$flowLayout = $transform('<style>.flow-row{display:flex}@media (max-width:700px){.flow-row{display:block}}</style><div class="flow-row"><div>One</div><div>Two</div></div>');
$assert('core/group' === ($mediaLayout['blocks'][0]['blockName'] ?? '') && 'core/group' === ($flowLayout['blocks'][0]['blockName'] ?? ''), 'media-only, nested-condition, and flow-reverting CSS layouts retain core/group');

$responsiveMinHeight = $transform('<style>.responsive-section{display:flex;min-height:1000px}@media(max-width:1600px){.responsive-section{min-height:0}}</style><section class="responsive-section"><p>Copy</p></section>');
$responsiveMinHeightMarkup = (string) ($responsiveMinHeight['serialized_blocks'] ?? '');
$responsiveMinHeightCss = $css($responsiveMinHeight);
$assert(
    ! str_contains($responsiveMinHeightMarkup, 'be-inline-geometry-')
    && str_contains($responsiveMinHeightCss, '.responsive-section{display:flex;min-height:1000px}')
    && str_contains($responsiveMinHeightCss, '@media(max-width:1600px){.responsive-section{min-height:0}}'),
    'class-owned min-height with a responsive variant remains under author stylesheet ownership'
);
$inlineResponsiveMinHeight = $transform('<style>.responsive-section{display:flex}@media(max-width:1600px){.responsive-section{min-height:0}}</style><section class="responsive-section" style="min-height:1000px"><p>Copy</p></section>');
$assert(
    str_contains($css($inlineResponsiveMinHeight), 'min-height:1000px !important'),
    'source inline min-height retains inline priority over a normal responsive author rule'
);

$articleLayout = $transform('<style>.article-row{display:flex}</style><div class="article-row"><article>One</article><article>Two</article></div>');
$assert('core/group' === ($articleLayout['blocks'][0]['blockName'] ?? '') && 2 === substr_count((string) ($articleLayout['serialized_blocks'] ?? ''), '<article '), 'CSS-owned groups preserve div to article direct-child topology');

$ordinaryFlow = $transform('<div><p>One</p><p>Two</p></div>');
$assert('core/group' === ($ordinaryFlow['blocks'][0]['blockName'] ?? '') && array() === ($ordinaryFlow['source_reports']['generated_blocks'] ?? array()), 'ordinary flow containers remain core blocks without companion generation');

$unclassedLayout = $transform('<style>header > nav{display:flex;align-items:center;max-width:60rem;margin:0 auto}</style><header><nav><a href="/">Home</a><div>Actions</div></nav></header>');
$unclassedLayoutMarkup = (string) ($unclassedLayout['serialized_blocks'] ?? '');
$unclassedLayoutCss = $css($unclassedLayout);
$assert(preg_match('/<nav class="[^"]*(blocks-engine-source-nav-[^\s"]+)/', $unclassedLayoutMarkup, $unclassedLayoutMarker) === 1 && str_contains($unclassedLayoutCss, ':where(.' . $unclassedLayoutMarker[1] . ')') && str_contains($unclassedLayoutCss, 'display:flex;align-items:center;max-width:60rem'), 'unclassed CSS-owned groups retain selector-projection provenance hooks');

$directControls = $transform('<style>.row{display:flex}</style><div class="row" role="list"><a class="item" href="/one" target="_blank" rel="noopener" role="listitem" data-key="one">One</a><button class="item" type="button" role="listitem" aria-label="Two">Two</button></div>');
$directControlsMarkup = (string) ($directControls['serialized_blocks'] ?? '');
$assert(str_contains($directControlsMarkup, 'wp-block-group row blocks-engine-css-owned-layout') && str_contains($directControlsMarkup, 'href="/one"') && str_contains($directControlsMarkup, 'type="button"') && ! str_contains($directControlsMarkup, 'wp-block-blocks-engine-author-layout'), 'CSS-owned controls retain valid native block content without a companion block');
$assert(array() === ($directControls['source_reports']['generated_blocks'] ?? array()), 'simple direct controls do not generate a companion block dependency');

$logoControl = $transform('<style>.logo-row{display:flex}.logo > svg{width:24px;height:18px}</style><div class="logo-row"><a class="logo" href="/"><svg viewBox="0 0 10 10" aria-hidden="true"><circle cx="5" cy="5" r="4"/></svg><span>Logo</span></a></div>');
$logoMarkup = (string) ($logoControl['serialized_blocks'] ?? '');
$logoAssetCount = count(array_filter($logoControl['assets'] ?? array(), static fn (array $asset): bool => 'inline-svg' === ($asset['source'] ?? '')));
$assert(str_contains($logoMarkup, 'assets/materialized-svg/') && 1 === $logoAssetCount && ! str_contains($logoMarkup, '<svg') && ! str_contains($logoMarkup, 'wp-block-blocks-engine-author-layout'), 'passive SVG logo controls retain materialized assets without a companion block');
$assert(array() === ($logoControl['source_reports']['conversion_report']['gutenberg_incompatibilities']['author_layout_topology'] ?? array()), 'SVG-to-image materialization preserves author-layout topology without a false wrapper-change diagnostic');

$structuredAnchor = $transform('<style>.row{display:flex}</style><div class="row"><a class="card" href="/"><span>Copy</span><div>Structured</div></a></div>');
$structuredAnchorBlock = $structuredAnchor['blocks'][0] ?? array();
$assert(! str_contains((string) ($structuredAnchor['serialized_blocks'] ?? ''), 'wp-block-blocks-engine-author-layout') && str_ends_with((string) ($structuredAnchorBlock['blockName'] ?? ''), '/layout-shell') && 2 === count($structuredAnchorBlock['innerBlocks'] ?? array()), 'block-structured anchor descendants retain native blocks without a companion block');

$instance = new HtmlTransformer();
$first = $instance->transform('<style>p{color:red}</style><p>First</p>')->toArray();
$second = $instance->transform('<style>.cta:hover{padding:1rem}</style><a class="cta" href="/go" style="padding:1px;background:#000">Go</a>')->toArray();
$assert(! str_contains($css($second), 'blocks-engine-source-p-') && 1 === substr_count($css($second), '> :where(.wp-block-button__link)') && ! str_contains($second['serialized_blocks'], 'core/html'), 'repeated transformer instances reset selector marker state and remain canonical');
$third = $instance->transform('<style>.cta:hover{color:red}</style><p>Read <span class="cta">this</span>.</p>')->toArray();
$assert(str_contains($css($third), 'blocks-engine-richtext-') && ! str_contains($css($third), '> :where(.wp-block-button__link)'), 'repeated selector text resolves against each transform source DOM');

$customPropertyCards = $transform('<style>.tour-card{background:linear-gradient(135deg,var(--tone),#fff)}</style><div class="tour-card" style="width:344px;height:430px;--tone:#f06;--unused:discard">First</div><div class="tour-card" style="width:344px;height:430px;--tone:#0af;--unused:discard">Second</div>');
$customPropertyCardsMarkup = (string) ($customPropertyCards['serialized_blocks'] ?? '');
$assert(! str_contains($customPropertyCardsMarkup, '--tone:') && ! str_contains($customPropertyCardsMarkup, '--unused:discard') && str_contains($css($customPropertyCards), '--tone:#f06 !important') && str_contains($css($customPropertyCards), '--tone:#0af !important') && str_contains($css($customPropertyCards), 'background:linear-gradient(135deg,var(--tone),#fff)'), 'author-CSS-consumed card custom properties retain distinct gradient values in generated carrier CSS without unused-property or cross-card leakage');

$pseudoCustomProperty = $transform('<style>.tour-card::before{content:"";background:var(--accent)}</style><div class="tour-card" style="width:344px;height:430px;--accent:#fc0;--unused:discard">Card</div>');
$pseudoCustomPropertyMarkup = (string) ($pseudoCustomProperty['serialized_blocks'] ?? '');
$assert(! str_contains($pseudoCustomPropertyMarkup, '--accent:') && ! str_contains($pseudoCustomPropertyMarkup, '--unused:discard') && str_contains($css($pseudoCustomProperty), '--accent:#fc0 !important') && str_contains($css($pseudoCustomProperty), '::before{content:"";background:var(--accent)}'), 'pseudo-element author rules retain only their consumed custom properties in generated carrier CSS');

$inheritedConditionalCustomProperty = $transform('<style>@media (prefers-reduced-motion:no-preference){.scene .visual{margin-bottom:calc(100lvh - max(100lvh,var(--motion-comp-height,100%)))}}</style><div class="scene" style="--motion-comp-height:742px;--unused:discard"><div class="visual">Visual</div></div>');
$inheritedConditionalCustomPropertyMarkup = (string) ($inheritedConditionalCustomProperty['serialized_blocks'] ?? '');
$inheritedConditionalCustomPropertyCss = $css($inheritedConditionalCustomProperty);
$assert(
    ! str_contains($inheritedConditionalCustomPropertyMarkup, '--motion-comp-height:')
    && str_contains($inheritedConditionalCustomPropertyCss, '--motion-comp-height:742px !important')
    && ! str_contains($inheritedConditionalCustomPropertyCss, '--unused:'),
    'inherited inline custom properties consumed by conditional descendant rules retain a generated carrier'
);

$geometryCustomProperty = $transform('<div style="width:var(--card-width);height:430px;--card-width:344px;--unused:discard">Card</div>');
$geometryCustomPropertyMarkup = (string) ($geometryCustomProperty['serialized_blocks'] ?? '');
$assert(! str_contains($geometryCustomPropertyMarkup, '--card-width:') && ! str_contains($geometryCustomPropertyMarkup, '--unused:discard') && str_contains($css($geometryCustomProperty), '--card-width:344px !important'), 'inline geometry retains only its referenced custom property in generated carrier CSS');

$customPropertyRoundTrip = $transform('<style>.tour-card{background:linear-gradient(135deg,var(--tone),var(--accent))}</style><div class="tour-card" style="--tone:#315b74;border-color:var(--line);border-width:1px;border-style:solid;border-radius:var(--radius);padding:1.2rem;min-height:430px;--accent:#d9b86c">Card</div>');
$customPropertyRoundTripMarkup = (string) ($customPropertyRoundTrip['serialized_blocks'] ?? '');
$assert(
    str_contains($customPropertyRoundTripMarkup, 'style="border-style:solid;border-width:1px;border-radius:var(--radius);padding-top:1.2rem;padding-right:1.2rem;padding-bottom:1.2rem;padding-left:1.2rem"')
    && ! str_contains($customPropertyRoundTripMarkup, 'has-border-color')
    && ! str_contains($customPropertyRoundTripMarkup, 'min-height:430px')
    && ! str_contains($customPropertyRoundTripMarkup, '--accent:')
    && str_contains($css($customPropertyRoundTrip), '--tone:#315b74 !important')
    && str_contains($css($customPropertyRoundTrip), '--accent:#d9b86c !important')
    && str_contains($css($customPropertyRoundTrip), 'border-color:var(--line)')
    && str_contains($css($customPropertyRoundTrip), 'min-height:430px !important')
    && 'pass' === ($customPropertyRoundTrip['source_reports']['wp_block_validity']['status'] ?? ''),
    'unresolved color variables, custom properties, and dimensions move to generated carrier CSS while supported styles retain a valid core block round trip',
    $customPropertyRoundTripMarkup
);

$neutralSingleGroup = $transform('<style>.outer .copy{color:red}</style><div class="outer"><div class="content"><p class="copy">Copy</p></div></div>');
$neutralSingleGroupMarkup = (string) ($neutralSingleGroup['serialized_blocks'] ?? '');
$assert(1 === substr_count($neutralSingleGroupMarkup, '<!-- wp:group') && str_contains($neutralSingleGroupMarkup, 'outer content') && str_contains($css($neutralSingleGroup), '.outer .copy{color:red}'), 'neutral single-Group wrappers coalesce while retaining their descendant selector hook on the child Group');

$selectorEdgeGroup = $transform('<style>.outer > .content{color:red}</style><div class="outer"><div class="content"><p>Copy</p></div></div>');
$assert(2 === substr_count((string) ($selectorEdgeGroup['serialized_blocks'] ?? ''), '<!-- wp:group'), 'single-Group wrappers remain separate when an author selector depends on their parent-child edge');

$geometryEdgeGroup = $transform('<style>.content{margin:10px}</style><div class="outer"><div class="content"><p>Copy</p></div></div>');
$assert(2 === substr_count((string) ($geometryEdgeGroup['serialized_blocks'] ?? ''), '<!-- wp:group'), 'single-Group wrappers remain separate when the child geometry depends on its containing block');

$neutralSameSourceGroupChain = $transform('<div class="outer"><div class="middle"><div class="content"><p>Copy</p></div></div></div>');
$neutralSameSourceGroupChainMarkup = (string) ($neutralSameSourceGroupChain['serialized_blocks'] ?? '');
$assert(1 === substr_count($neutralSameSourceGroupChainMarkup, '<!-- wp:group') && str_contains($neutralSameSourceGroupChainMarkup, 'outer middle content'), 'neutral same-source Group chains coalesce to their source-provenance leaf');

$commentAnnotatedGroupChain = $transform('<div class="outer"><!-- export annotation --><div class="content"><p>Copy</p></div></div>');
$commentAnnotatedGroupChainMarkup = (string) ($commentAnnotatedGroupChain['serialized_blocks'] ?? '');
$assert(1 === substr_count($commentAnnotatedGroupChainMarkup, '<!-- wp:group') && str_contains($commentAnnotatedGroupChainMarkup, 'outer content'), 'comment-annotated neutral Group wrappers coalesce because comments are semantically transparent');

$sameSourceGroupChainSelectorEdge = $transform('<style>.outer > .middle{color:red}</style><div class="outer"><div class="middle"><div class="content"><p>Copy</p></div></div></div>');
	$assert(2 === substr_count((string) ($sameSourceGroupChainSelectorEdge['serialized_blocks'] ?? ''), '<!-- wp:group'), 'same-source Group chains retain the outer boundary when an author selector matches a removed chain node');

$nestedFlex = $transform('<div style="display:flex"><div style="display:flex"><p>A</p><p>B</p></div></div>');
$nestedFlexMarkup = (string) ($nestedFlex['serialized_blocks'] ?? '');
$assert(1 === substr_count($nestedFlexMarkup, '<!-- wp:group') && str_contains($nestedFlexMarkup, 'blocks-engine-css-owned-layout'), 'redundant nested flex wrappers coalesce to the child geometry group');

$flexItemGroup = $transform('<div style="display:flex"><div><p>A</p><p>B</p></div></div>');
$assert(1 === substr_count((string) ($flexItemGroup['serialized_blocks'] ?? ''), '<!-- wp:custom/layout-shell') && 2 === count($flexItemGroup['blocks'][0]['attrs']['wrappers'] ?? array()), 'a flex item wrapper around stacked content remains distinct inside one layout shell');

$namedFlex = $transform('<style>.shell{display:flex}</style><div class="shell"><div style="display:flex"><p>A</p><p>B</p></div></div>');
$assert(1 === substr_count((string) ($namedFlex['serialized_blocks'] ?? ''), '<!-- wp:custom/layout-shell') && 2 === count($namedFlex['blocks'][0]['attrs']['wrappers'] ?? array()) && str_contains((string) ($namedFlex['serialized_blocks'] ?? ''), 'shell'), 'author-named flex wrappers remain distinct inside one layout shell');

if ( $failures > 0 ) {
    fwrite(STDERR, "Author selector semantics unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "Author selector semantics unit tests: {$passes} passed\n");
