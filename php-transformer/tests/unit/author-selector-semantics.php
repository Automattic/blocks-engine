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
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( 'css' === ($asset['kind'] ?? '') ) {
            return (string) ($asset['content'] ?? '');
        }
    }
    return '';
};

$paragraph = $transform('<style>p{color:red}span{color:blue}</style><span>Loose text</span><p>Paragraph</p>');
$paragraphClass = (string) ($paragraph['blocks'][1]['attrs']['className'] ?? '');
$assert('' !== $paragraphClass && str_contains($css($paragraph), ':where(.' . $paragraphClass . '):not(blocks-engine-specificity-') && ! str_contains($paragraph['serialized_blocks'], 'core/html'), 'p type selectors retain provenance and type specificity only on canonical p serialization');

$navigationShell = $transform('<style>nav{height:60px;padding:0 28px}.nav-links{display:flex}</style><header><nav><a class="nav-logo" href="/">Logo</a><ul class="nav-links"><li><a href="#one">One</a></li></ul></nav></header>');
$navigationShellCss = $css($navigationShell);
$navigationShellBlock = $navigationShell['blocks'][0] ?? array();
$navigationMenuBlock = $navigationShellBlock['innerBlocks'][1] ?? array();
$navigationShellClass = (string) ($navigationShellBlock['attrs']['className'] ?? '');
$assert(str_contains($navigationShellClass, 'blocks-engine-source-nav-') && ! str_contains((string) ($navigationMenuBlock['attrs']['className'] ?? ''), 'blocks-engine-source-nav-') && str_contains($navigationShellCss, ':where(.' . $navigationShellClass . '):not(blocks-engine-specificity-') && ! preg_match('/(^|[},])nav\s*\{/', $navigationShellCss), 'nav type selectors stay scoped to the canonical source navigation shell instead of matching nested core navigation markup');

$controls = $transform('<style>a.cta:hover{padding:1rem}button.cta:focus{padding:2rem}</style><a class="cta" href="/go" style="padding:1px;background:#000">Go</a><button class="cta" style="padding:1px;background:#000">Send</button>');
$controlCss = $css($controls);
$assert(2 === substr_count($controlCss, '> :where(.wp-block-button__link)') && str_contains($controlCss, ':hover') && str_contains($controlCss, ':focus'), 'promoted anchors and native buttons project dynamic selectors onto their links once');

$order = $transform('<style>a.cta:hover{color:red}a.cta:hover{color:blue}</style><a class="cta" href="/go" style="padding:1px;background:#000">Go</a>');
$orderCss = $css($order);
$assert(strpos($orderCss, 'color:red') < strpos($orderCss, 'color:blue'), 'projected selectors preserve authored rule order for cascade precedence');

$specificity = $transform('<style>a.cta{color:red}.cta{color:blue}p{color:red}*{color:blue}.copy{color:green}</style><a class="cta" href="/go" style="padding:1px;background:#000">Go</a><p class="copy">Paragraph</p>');
$specificityCss = $css($specificity);
$assert(str_contains($specificityCss, ':not(blocks-engine-specificity-') && strpos($specificityCss, 'color:red') < strpos($specificityCss, 'color:blue') && strpos($specificityCss, 'color:blue') < strpos($specificityCss, 'color:green'), 'type-specificity shims preserve the authored a.cta and p cascade ordering against later class and universal rules');

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
$assert(3 === substr_count($attributeCss, '> :where(.wp-block-button__link)') && str_contains($attributeCss, '{margin:1rem}') && str_contains($attributeCss, ':focus') && ! str_contains($attributeCss, '[data-cta]') && ! str_contains($attributeCss, '[aria-label]') && ! str_contains($attributeCss, '[data-kind') && ! str_contains($attributeCss, '#cta-id') && ! str_contains($attributes['serialized_blocks'], 'core/html') && 'pass' === ($attributes['source_reports']['wp_block_validity']['status'] ?? ''), 'data, aria, attribute-operator, ID, and class selectors project through exact control markers with canonical validity');

$wrapper = $transform('<style>.wrap a.cta:hover{padding:1rem}.wrap a.cta:focus{color:red}</style><div class="wrap" role="button"><a class="cta" href="/go">Go</a></div>');
$wrapperCss = $css($wrapper);
$wrapperButton = $wrapper['blocks'][0]['innerBlocks'][0] ?? array();
$assert('core/button' === ($wrapperButton['blockName'] ?? '') && str_contains((string) ($wrapperButton['attrs']['className'] ?? ''), 'blocks-engine-control-') && 2 === substr_count($wrapperCss, '> :where(.wp-block-button__link)') && str_contains($wrapperCss, ':hover') && str_contains($wrapperCss, ':focus') && 'pass' === ($wrapper['source_reports']['wp_block_validity']['status'] ?? ''), 'wrapper-driven role=button promotion retains logical anchor selectors, presentation wrapper attributes, and valid blocks');

$nestedControl = $transform('<style>.action-shell{display:inline-flex;align-items:center;gap:8px;margin-top:1rem;padding:12px 20px;min-width:14rem;border:2px solid #123456;border-radius:999px;background:#123456;color:#fff;font-weight:700}.action-shell:hover{background:#234567}.action-shell:focus{outline:2px solid #fff}</style><div class="action-shell" role="button"><a href="/go"><span>Go now</span></a></div>');
$nestedControlMarkup = (string) ($nestedControl['serialized_blocks'] ?? '');
$nestedControlCss = $css($nestedControl);
$assert(str_contains($nestedControlMarkup, '<div class="wp-block-buttons" style="margin-top:1rem"') && str_contains($nestedControlMarkup, '<div class="wp-block-button action-shell">') && ! str_contains($nestedControlMarkup, '<a href="/go"><a') && str_contains($nestedControlCss, '.action-shell{display:inline-flex;align-items:center;gap:8px;padding:12px 20px;min-width:14rem;border:2px solid #123456;border-radius:999px;background:#123456;color:#fff;font-weight:700}') && str_contains($nestedControlCss, '.action-shell:hover{background:#234567}') && str_contains($nestedControlCss, '.action-shell:focus{outline:2px solid #fff}') && 'pass' === ($nestedControl['source_reports']['wp_block_validity']['status'] ?? ''), 'nested button wrappers retain authored layout and states while external margin maps to the native buttons wrapper');

$layoutOnlyControl = $transform('<style>.layout-action{display:inline-flex;align-items:center;gap:8px;margin-top:1rem}</style><div class="layout-action" role="button"><a href="/go">Go now</a></div>');
$layoutOnlyCss = $css($layoutOnlyControl);
$assert(str_contains((string) ($layoutOnlyControl['serialized_blocks'] ?? ''), '<div class="wp-block-buttons" style="margin-top:1rem"') && str_contains($layoutOnlyCss, '.layout-action{display:inline-flex;align-items:center;gap:8px}') && ! str_contains($layoutOnlyCss, '> :where(.wp-block-button__link)'), 'layout-only source control rules remain on the canonical wrapper while external margin uses native spacing');

$directControl = $transform('<style>.control-cluster{display:flex;gap:8px}.action-control{display:inline-flex;align-items:center;gap:8px;padding:12px 20px;min-width:14rem;border:2px solid #123456;border-radius:999px;background:#123456;color:#fff;font-weight:700}.action-control:hover{background:#234567}.action-control:focus{outline:2px solid #fff}</style><div class="control-cluster"><a class="action-control" href="/go">Go now</a></div>');
$directControlMarkup = (string) ($directControl['serialized_blocks'] ?? '');
$directControlCss = $css($directControl);
preg_match('/(blocks-engine-control-[a-f0-9]+-\d+)/', $directControlMarkup, $directControlMarker);
$assert(str_contains($directControlMarkup, 'wp-block-blocks-engine-author-layout control-cluster') && str_contains($directControlMarkup, 'wp-block-blocks-engine-author-layout action-control') && str_contains($directControlMarkup, '<a href="/go"') && ! str_contains($directControlMarkup, 'wp-block-button__link'), 'direct source controls remain direct author-layout anchors rather than core/button wrappers');

$navCta = $transform('<style>a.btn.btn-primary.nav-cta{display:inline-flex;align-items:center;gap:8px;font-family:monospace;font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;padding:9px 20px;border-radius:6px;background:#e8a020;color:#050d1a}.nav-cta:hover{background:#f0ac22}.nav-cta:focus{outline:2px solid #fff}</style><main><a class="btn btn-primary nav-cta" href="#cta">Get Early Access</a></main>');
$navCtaMarkup = (string) ($navCta['serialized_blocks'] ?? '');
$navCtaCss = $css($navCta);
$assert(str_contains($navCtaMarkup, 'blocks-engine-control-') && str_contains($navCtaCss, '> :where(.wp-block-button__link){display:inline-flex') && str_contains($navCtaCss, 'font-family:monospace') && str_contains($navCtaCss, 'padding:9px 20px') && str_contains($navCtaCss, 'background:#e8a020') && str_contains($navCtaCss, ':hover{background:#f0ac22}') && str_contains($navCtaCss, ':focus{outline:2px solid #fff}') && 'pass' === ($navCta['source_reports']['wp_block_validity']['status'] ?? ''), 'class-bearing source anchors project compound, typography, paint, and pseudo-state selectors onto valid core/button links');

$controlMargin = $transform('<style>.nav-cta{margin-left:24px;font-family:monospace}</style><main><a class="nav-cta" href="#cta" style="display:inline-flex;padding:9px 20px;background:#e8a020">Get Early Access</a></main>');
$controlMarginCss = $css($controlMargin);
$controlMarginOuterClass = (string) ($controlMargin['blocks'][0]['attrs']['className'] ?? '');
$assert('24px' === ($controlMargin['blocks'][0]['attrs']['style']['spacing']['margin']['left'] ?? '') && str_contains($controlMarginOuterClass, 'blocks-engine-control-') && str_contains($controlMarginCss, '> :where(.wp-block-button__link){font-family:monospace}') && preg_match('/where\(\.blocks-engine-control-[^)]+\):where\(\.wp-block-buttons\)\{margin-left:24px\}/', $controlMarginCss) && ! str_contains($controlMarginCss, '> :where(.wp-block-button__link){margin-left:24px'), 'control margins map to native buttons flex-item spacing while typography remains on its link');

$structuredLogo = $transform('<style>.logo{display:inline-flex;align-items:center;gap:.6rem;text-decoration:none}.logo-mark{width:38px;height:38px;background:#111}.logo:hover{color:red}</style><header><a class="logo" href="/" aria-label="Home"><span class="logo-mark" aria-hidden="true"></span><span class="logo-text">The Block <span>Party</span></span></a></header>');
$structuredLogoMarkup = (string) ($structuredLogo['serialized_blocks'] ?? '');
$structuredLogoCss = $css($structuredLogo);
$assert(str_contains($structuredLogoMarkup, '<!-- wp:button') && str_contains($structuredLogoMarkup, 'blocks-engine-control-') && str_contains($structuredLogoMarkup, '<div class="wp-block-button') && ! str_contains($structuredLogoMarkup, 'style="display:flex"') && str_contains($structuredLogoMarkup, '<span class="logo-mark" aria-hidden="true"') && str_contains($structuredLogoMarkup, 'background-color:transparent') && str_contains($structuredLogoMarkup, 'border-radius:0') && str_contains($structuredLogoMarkup, 'padding-top:0') && ! str_contains($structuredLogoMarkup, '<!-- wp:html') && str_contains($structuredLogoCss, '> :where(.wp-block-button__link){display:inline-flex;align-items:center;gap:.6rem;text-decoration:none}') && str_contains($structuredLogoCss, '> :where(.wp-block-button__link):hover{color:red}') && 'pass' === ($structuredLogo['source_reports']['wp_block_validity']['status'] ?? ''), 'structured logo anchors neutralize invented button chrome and retain valid native inline content');

$svgLogo = $transform('<style>.logo{display:inline-flex;align-items:center;gap:.6rem}.logo-mark{width:38px;height:38px;display:grid;place-items:center;flex:none}.logo-mark svg{width:22px;height:22px}</style><header><a class="logo" href="/" aria-label="Home"><span class="logo-mark"><svg viewBox="0 0 38 38" aria-hidden="true"><circle cx="19" cy="19" r="18"/></svg></span><span class="logo-text">Block Party</span></a></header>');
$svgLogoMarkup = (string) ($svgLogo['serialized_blocks'] ?? '');
$assert(str_contains($svgLogoMarkup, '<span class="logo-mark" style="width:38px;height:38px;display:grid"') && str_contains($svgLogoMarkup, '<img src="assets/materialized-svg/') && str_contains($svgLogoMarkup, '<span class="logo-text">Block Party</span>') && 1 === count(array_filter($svgLogo['assets'] ?? array(), static fn (array $asset): bool => 'inline-svg' === ($asset['source'] ?? ''))) && 'pass' === ($svgLogo['source_reports']['wp_block_validity']['status'] ?? ''), 'structured text logos preserve passive inline SVG artwork and native RichText-safe container geometry');

$plainSvgLogo = $transform('<style>.nav-logo{display:flex;align-items:center;gap:10px;margin-right:auto}</style><header><a class="nav-logo" href="/" aria-label="Home"><svg width="28" height="28" viewBox="0 0 28 28" aria-hidden="true"><circle cx="14" cy="14" r="13"/></svg>Relay Atlas</a></header>');
$plainSvgLogoMarkup = (string) ($plainSvgLogo['serialized_blocks'] ?? '');
$assert(str_contains($plainSvgLogoMarkup, 'style="margin-right:auto"') && str_contains($plainSvgLogoMarkup, '<img src="assets/materialized-svg/') && str_contains($plainSvgLogoMarkup, 'Relay Atlas') && ! str_contains($plainSvgLogoMarkup, '<!-- wp:html') && 'pass' === ($plainSvgLogo['source_reports']['wp_block_validity']['status'] ?? ''), 'linked text logos preserve unclassed inline artwork and external spacing in block-valid structured chrome');

$iconOnlyButton = $transform('<style>.toolbar .icon-cta{width:42px;height:42px;padding:8px;border:2px solid #111;border-radius:50%;background:#f4c542}.toolbar .icon-cta:hover{background:#111}.toolbar .icon-cta:focus{outline:3px solid #d14}</style><div class="toolbar"><button class="icon-cta" aria-label="Open filters" style="width:42px;height:42px"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M3 6h18M6 12h12M9 18h6"/></svg></button></div>');
$iconOnlyMarkup = (string) ($iconOnlyButton['serialized_blocks'] ?? '');
$iconOnlyCss = $css($iconOnlyButton);
$assert(str_contains($iconOnlyMarkup, '<button type="button" class="wp-block-button__link') && str_contains($iconOnlyMarkup, 'title="Open filters"') && str_contains($iconOnlyMarkup, '<img src="assets/materialized-svg/') && ! str_contains($iconOnlyMarkup, '>Open filters</button>') && ! str_contains($iconOnlyMarkup, 'aria-label=') && str_contains($iconOnlyCss, '{height:42px !important;width:42px !important}') && str_contains($iconOnlyCss, '> :where(.wp-block-button__link){width:42px') && str_contains($iconOnlyCss, ':hover{background:#111}') && str_contains($iconOnlyCss, ':focus{outline:3px solid #d14}') && 1 === count(array_filter($iconOnlyButton['assets'] ?? array(), static fn (array $asset): bool => 'inline-svg' === ($asset['source'] ?? ''))) && 'pass' === ($iconOnlyButton['source_reports']['wp_block_validity']['status'] ?? ''), 'direct icon-only buttons retain sanitized SVG artwork, a core-valid accessible title, wrapper geometry, and link-projected chrome without synthesized visible text');

$labeledIconButton = $transform('<style>.ins-block span{font-size:.68rem;font-weight:600}</style><button class="ins-block"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3h18v18H3z"/></svg><span>Paragraph</span></button>');
$labeledIconButtonMarkup = (string) ($labeledIconButton['serialized_blocks'] ?? '');
$assert(str_contains($labeledIconButtonMarkup, '<img src="assets/materialized-svg/') && str_contains($labeledIconButtonMarkup, 'font-size:.68rem') && str_contains($labeledIconButtonMarkup, 'font-weight:600') && str_contains($labeledIconButtonMarkup, '>Paragraph</span>') && 1 === count(array_filter($labeledIconButton['assets'] ?? array(), static fn (array $asset): bool => 'inline-svg' === ($asset['source'] ?? ''))) && 'pass' === ($labeledIconButton['source_reports']['wp_block_validity']['status'] ?? ''), 'labeled controls preserve passive inline SVG artwork and descendant typography beside their visible RichText label');

$gridButton = $transform('<style>.controls{display:grid;grid-template-columns:repeat(3,1fr)}</style><div class="controls"><button>One</button><button>Two</button></div>');
$assert(2 === substr_count((string) ($gridButton['serialized_blocks'] ?? ''), 'tagName":"button"'), 'direct grid buttons retain direct editable button representation');

$inlineLeaves = $transform('<style>.meta{display:flex;gap:10px}.eyebrow{display:flex;gap:10px}.meta span{font:10px monospace;border:1px solid #999;padding:2px 8px}.eyebrow span{font-size:11px;letter-spacing:.1em}</style><div class="eyebrow"><span>Beta</span></div><div class="meta"><span>One</span><span>Two</span></div>');
$inlineMarkup = (string) ($inlineLeaves['serialized_blocks'] ?? '');
$inlineCss = $css($inlineLeaves);
$assert(3 === substr_count($inlineMarkup, '<div class="wp-block-group blocks-engine-semantic-') && 3 === substr_count($inlineCss, ':where(.blocks-engine-semantic-') && 'pass' === ($inlineLeaves['source_reports']['wp_block_validity']['status'] ?? ''), 'CSS-addressed sibling spans retain independent native wrapper identities and projected selector paths without HTML fallback');

$listInlineLeaves = $transform('<style>.maintenance-loop li{display:grid;grid-template-columns:42px 1fr}.maintenance-loop li > span{display:grid;place-items:center;width:30px;height:30px;border-radius:50%;background:#c9f27b}</style><ol class="maintenance-loop"><li><span>1</span><div><strong>Observe</strong><p>Copy</p></div></li><li><span>2</span><div><strong>Replay</strong></div><ul><li><span>N</span><div>Nested</div></li></ul></li></ol>');
$listInlineMarkup = (string) ($listInlineLeaves['serialized_blocks'] ?? '');
$listInlineCss = $css($listInlineLeaves);
$assert(3 === substr_count($listInlineMarkup, '<mark style="--blocks-engine-richtext-marker:') && 3 === substr_count($listInlineCss, 'mark[style*="--blocks-engine-richtext-marker:') && str_contains($listInlineCss, 'display:grid;place-items:center;width:30px;height:30px;border-radius:50%;background:#c9f27b') && str_contains($listInlineMarkup, '>1</mark><div>') && str_contains($listInlineMarkup, '>2</mark><div>') && str_contains($listInlineMarkup, '>N</mark><div>Nested</div>') && 2 === substr_count($listInlineMarkup, '<!-- wp:list ') && 'pass' === ($listInlineLeaves['source_reports']['wp_block_validity']['status'] ?? ''), 'list-item RichText markers retain direct span circle selectors while nested lists stay isolated native lists');

$repeatedParents = $transform('<style>.row{display:flex}.row .pill{padding:2px 8px;border:1px solid #999}.other .pill{color:red}</style><div class="row"><span class="pill">First</span></div><div class="row"><span class="pill">Second</span></div><div class="other"><span class="pill">Third</span></div>');
$repeatedMarkup = (string) ($repeatedParents['serialized_blocks'] ?? '');
$repeatedCss = $css($repeatedParents);
preg_match_all('/blocks-engine-semantic-[a-f0-9]+-\d+/', $repeatedMarkup . "\n" . $repeatedCss, $repeatedMarkers);
$assert(2 === count(array_unique($repeatedMarkers[0] ?? array())) && 2 === substr_count($repeatedCss, ':where(.blocks-engine-semantic-') && str_contains($repeatedMarkup, '--blocks-engine-richtext-marker:blocks-engine-richtext-') && str_contains($repeatedCss, 'mark[style*="--blocks-engine-richtext-marker:blocks-engine-richtext-'), 'repeated structural parents allocate unique source-path markers without leaking their box styles into an unrelated inline sibling');

$richTextPill = $transform('<style>p .pill{padding:2px 8px;border:1px solid #999}</style><p>Read <span class="pill">more</span>.</p>');
$richTextPillMarkup = (string) ($richTextPill['serialized_blocks'] ?? '');
$richTextPillCss = $css($richTextPill);
$assert(str_contains($richTextPillMarkup, '<mark class="pill"') && str_contains($richTextPillMarkup, '--blocks-engine-richtext-marker:blocks-engine-richtext-') && str_contains($richTextPillCss, 'mark[style*="--blocks-engine-richtext-marker:blocks-engine-richtext-') && 'pass' === ($richTextPill['source_reports']['wp_block_validity']['status'] ?? ''), 'RichText-contained selector hooks survive through valid mark formatting and projected CSS');

$richTextColor = $transform('<style>:root{--amber:#e8a020}.quote-mark{font-size:4rem;color:var(--amber)}</style><p><span class="quote-mark">&quot;</span>Testimonial</p>');
$richTextColorMarkup = (string) ($richTextColor['serialized_blocks'] ?? '');
$richTextColorCss = $css($richTextColor);
$assert(str_contains($richTextColorMarkup, '--blocks-engine-richtext-marker:blocks-engine-richtext-') && ! str_contains($richTextColorMarkup, 'color:inherit') && ! str_contains($richTextColorMarkup, 'background-color:transparent') && str_contains($richTextColorCss, ':where(mark)[style*="--blocks-engine-richtext-marker:"]{background-color:transparent;color:inherit}') && str_contains($richTextColorCss, '{font-size:4rem;color:var(--amber)}') && strpos($richTextColorCss, 'color:inherit') < strpos($richTextColorCss, 'color:var(--amber)'), 'RichText marker reset stays below projected author paint instead of overriding it inline');

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
$assert(1 === substr_count($gridRichTextMarkup, '<!-- wp:paragraph') && ! str_contains($gridRichTextMarkup, '<!-- wp:group') && str_contains($gridRichTextMarkup, '<p class="artifact-card blocks-engine-synthetic-paragraph">') && str_contains($gridRichTextMarkup, '<mark class="card-label"') && 1 === substr_count($gridRichTextMarkup, '--blocks-engine-richtext-marker:') && str_contains($gridRichTextCss, 'grid-template-columns:1fr auto') && str_contains($gridRichTextCss, 'grid-column:1 / -1') && 'pass' === ($gridRichText['source_reports']['wp_block_validity']['status'] ?? ''), 'phrasing-only grid cards retain selector-addressable inline children in one valid RichText save shape');

$selectorIdentity = $transform('<style>.roster-card .stamp{color:#6040cc}.roster-card .stamp:hover{color:#123456}.roster-card a.view{display:inline-flex;align-items:center;gap:6px}.roster-card a.view:hover{color:#123456}</style><div class="roster-card"><p><span class="stamp" id="release-stamp" data-kind="release">New</span></p><a class="view" id="view-release" data-kind="release-link" href="/release" target="_blank" rel="noopener">View release</a><a href="/plain">Plain link</a></div>');
$selectorIdentityMarkup = (string) ($selectorIdentity['serialized_blocks'] ?? '');
$selectorIdentityCss = $css($selectorIdentity);
$assert(str_contains($selectorIdentityMarkup, '<mark class="stamp" id="release-stamp" data-kind="release"') && str_contains($selectorIdentityMarkup, '--blocks-engine-richtext-marker:') && str_contains($selectorIdentityCss, ':hover{color:#123456}') && 'pass' === ($selectorIdentity['source_reports']['wp_block_validity']['status'] ?? ''), 'selector-addressable RichText spans retain safe class, id, and data identity on their valid mark carrier through pseudo-state projection');
$assert(str_contains($selectorIdentityMarkup, '<a class="view" id="view-release" data-kind="release-link" href="/release" target="_blank" rel="noopener">View release</a>') && str_contains($selectorIdentityMarkup, '<p class="blocks-engine-synthetic-paragraph">') && ! str_contains($selectorIdentityMarkup, '<p class="view') && ! str_contains($selectorIdentityMarkup, '<p id="view-release"') && str_contains($selectorIdentityCss, '.roster-card a.view{display:inline-flex;align-items:center;gap:6px}') && str_contains($selectorIdentityCss, '.roster-card a.view:hover{color:#123456}'), 'non-button anchor class, id, and data identity belong only to the inner link while its synthetic paragraph retains independent presentation');
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
$authorDefinition = $authorGrid['source_reports']['generated_blocks'][0] ?? array();
$assert('blocks-engine/author-layout' === ($authorGridBlock['blockName'] ?? '') && 5 === count($authorGridBlock['innerBlocks'] ?? array()) && str_contains($authorGridMarkup, '<section id="gallery" class="wp-block-blocks-engine-author-layout ex-row" aria-label="Gallery" data-kind="exhibition">') && ! str_contains((string) ($authorGridBlock['innerHTML'] ?? ''), 'wp-block-group') && ! str_contains($css($authorGrid), '--wp--style--block-gap'), 'author grids use one semantic editable companion wrapper with source CSS as layout authority');
$assert('blocks-engine/author-layout' === ($authorDefinition['block_json']['name'] ?? '') && false === ($authorDefinition['block_json']['supports']['layout'] ?? true) && false === ($authorDefinition['block_json']['supports']['spacing']['blockGap'] ?? true) && str_contains((string) ($authorDefinition['assets']['index.js'] ?? ''), 'InnerBlocks.Content'), 'author layout companion payload declares editable InnerBlocks and no core layout support');

$mediaLayout = $transform('<style>@media (min-width:700px){@supports (display:grid){.responsive-row{display:grid;gap:2rem}}}</style><div class="responsive-row"><div>One</div><div>Two</div></div>');
$flowLayout = $transform('<style>.flow-row{display:flex}@media (max-width:700px){.flow-row{display:block}}</style><div class="flow-row"><div>One</div><div>Two</div></div>');
$assert('blocks-engine/author-layout' === ($mediaLayout['blocks'][0]['blockName'] ?? '') && 'blocks-engine/author-layout' === ($flowLayout['blocks'][0]['blockName'] ?? ''), 'media-only, nested-condition, and flow-reverting CSS layouts retain the same author layout island');

$articleLayout = $transform('<style>.article-row{display:flex}</style><div class="article-row"><article>One</article><article>Two</article></div>');
$assert('blocks-engine/author-layout' === ($articleLayout['blocks'][0]['blockName'] ?? '') && 2 === substr_count((string) ($articleLayout['serialized_blocks'] ?? ''), '<article '), 'author layout islands preserve div to article direct-child topology');

$ordinaryFlow = $transform('<div><p>One</p><p>Two</p></div>');
$assert('core/group' === ($ordinaryFlow['blocks'][0]['blockName'] ?? '') && array() === ($ordinaryFlow['source_reports']['generated_blocks'] ?? array()), 'ordinary flow containers remain core blocks without companion generation');

$unclassedLayout = $transform('<style>header > nav{display:flex;align-items:center;max-width:60rem;margin:0 auto}</style><header><nav><a href="/">Home</a><div>Actions</div></nav></header>');
$unclassedLayoutMarkup = (string) ($unclassedLayout['serialized_blocks'] ?? '');
$unclassedLayoutCss = $css($unclassedLayout);
$assert(preg_match('/<nav class="wp-block-blocks-engine-author-layout (blocks-engine-source-nav-[^"]+)"/', $unclassedLayoutMarkup, $unclassedLayoutMarker) === 1 && str_contains($unclassedLayoutCss, ':where(.' . $unclassedLayoutMarker[1] . ')') && str_contains($unclassedLayoutCss, 'display:flex;align-items:center;max-width:60rem'), 'unclassed author-layout wrappers retain selector-projection provenance hooks');

$directControls = $transform('<style>.row{display:flex}</style><div class="row" role="list"><a class="item" href="/one" target="_blank" rel="noopener" role="listitem" data-key="one">One</a><button class="item" type="button" role="listitem" aria-label="Two">Two</button></div>');
$directControlsMarkup = (string) ($directControls['serialized_blocks'] ?? '');
$assert(str_contains($directControlsMarkup, '<div class="wp-block-blocks-engine-author-layout row" role="list">') && str_contains($directControlsMarkup, 'wp-block-blocks-engine-author-layout item') && str_contains($directControlsMarkup, 'href="/one"') && str_contains($directControlsMarkup, 'type="button"') && str_contains($directControlsMarkup, 'role="listitem"') && ! str_contains($directControlsMarkup, 'wp-block-buttons'), 'author layout controls retain direct anchor and button topology with safe attributes');
$authorLayoutScript = (string) ($directControls['source_reports']['generated_blocks'][0]['assets']['index.js'] ?? '');
$assert(str_contains($authorLayoutScript, "attributes.contentMode === 'rich-text'") && str_contains($authorLayoutScript, 'RichText.Content') && str_contains($authorLayoutScript, 'InnerBlocks.Content'), 'companion JS save contract selects direct RichText leaves and editable structural InnerBlocks by content mode');
$assert('rich-text' === ($directControls['blocks'][0]['innerBlocks'][0]['attrs']['contentMode'] ?? '') && array() === ($directControls['blocks'][0]['innerBlocks'][0]['innerBlocks'] ?? array()), 'simple direct controls persist rich-text mode without child blocks');

$logoControl = $transform('<style>.logo-row{display:flex}.logo > svg{width:24px;height:18px}</style><div class="logo-row"><a class="logo" href="/"><svg viewBox="0 0 10 10" aria-hidden="true"><circle cx="5" cy="5" r="4"/></svg><span>Logo</span></a></div>');
$logoBlock = $logoControl['blocks'][0]['innerBlocks'][0] ?? array();
$logoMarkup = (string) ($logoControl['serialized_blocks'] ?? '');
$logoContent = (string) ($logoBlock['attrs']['content'] ?? '');
$logoAssetCount = count(array_filter($logoControl['assets'] ?? array(), static fn (array $asset): bool => 'inline-svg' === ($asset['source'] ?? '')));
$logoDirectSave = 1 === preg_match('~<a href="/" class="wp-block-blocks-engine-author-layout logo"><img[^>]+><span>Logo</span></a>~', $logoMarkup);
$assert('rich-text' === ($logoBlock['attrs']['contentMode'] ?? '') && array() === ($logoBlock['innerBlocks'] ?? array()) && str_contains($logoContent, '<img src="assets/materialized-svg/') && str_contains($logoContent, 'style="width:24px;height:18px"') && 1 === $logoAssetCount && $logoDirectSave && ! str_contains($logoMarkup, '<svg') && ! str_contains($logoMarkup, '<a href="/" class="wp-block-blocks-engine-author-layout logo"><!-- wp:paragraph'), 'passive SVG logo controls save direct materialized images and phrasing content through RichText without synthetic wrappers');
$assert(array() === ($logoControl['source_reports']['conversion_report']['gutenberg_incompatibilities']['author_layout_topology'] ?? array()), 'SVG-to-image materialization preserves author-layout topology without a false wrapper-change diagnostic');

$structuredAnchor = $transform('<style>.row{display:flex}</style><div class="row"><a class="card" href="/"><span>Copy</span><div>Structured</div></a></div>');
$structuredAnchorBlock = $structuredAnchor['blocks'][0]['innerBlocks'][0] ?? array();
$assert('inner-blocks' === ($structuredAnchorBlock['attrs']['contentMode'] ?? '') && 0 < count($structuredAnchorBlock['innerBlocks'] ?? array()) && str_contains((string) ($structuredAnchor['serialized_blocks'] ?? ''), '<a href="/" class="wp-block-blocks-engine-author-layout card"><!-- wp:paragraph'), 'block-structured anchor descendants retain the PHP InnerBlocks save contract');

$instance = new HtmlTransformer();
$first = $instance->transform('<style>p{color:red}</style><p>First</p>')->toArray();
$second = $instance->transform('<style>.cta:hover{padding:1rem}</style><a class="cta" href="/go" style="padding:1px;background:#000">Go</a>')->toArray();
$assert(! str_contains($css($second), 'blocks-engine-source-p-') && 1 === substr_count($css($second), '> :where(.wp-block-button__link)') && ! str_contains($second['serialized_blocks'], 'core/html'), 'repeated transformer instances reset selector marker state and remain canonical');
$third = $instance->transform('<style>.cta:hover{color:red}</style><p>Read <span class="cta">this</span>.</p>')->toArray();
$assert(str_contains($css($third), 'blocks-engine-richtext-') && ! str_contains($css($third), '> :where(.wp-block-button__link)'), 'repeated selector text resolves against each transform source DOM');

if ( $failures > 0 ) {
    fwrite(STDERR, "Author selector semantics unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "Author selector semantics unit tests: {$passes} passed\n");
