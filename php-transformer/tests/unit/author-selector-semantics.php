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
$css = static fn (array $result): string => (string) ($result['assets'][0]['content'] ?? '');

$paragraph = $transform('<style>p{color:red}span{color:blue}</style><span>Loose text</span><p>Paragraph</p>');
$paragraphClass = (string) ($paragraph['blocks'][1]['attrs']['className'] ?? '');
$assert('' !== $paragraphClass && str_contains($css($paragraph), ':where(.' . $paragraphClass . '):not(blocks-engine-specificity-') && ! str_contains($paragraph['serialized_blocks'], 'core/html'), 'p type selectors retain provenance and type specificity only on canonical p serialization');

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

$attributes = $transform('<style>[data-cta]:focus{color:red}[aria-label]{padding:1rem}[data-kind^="primary"]{margin:1rem}#cta-id.cta{border-width:1px}</style><a id="cta-id" class="cta" data-cta aria-label="Start" data-kind="primary-action" href="/go" style="padding:1px;background:#000">Go</a>');
$attributeCss = $css($attributes);
$assert(4 === substr_count($attributeCss, '> :where(.wp-block-button__link)') && str_contains($attributeCss, ':focus') && ! str_contains($attributeCss, '[data-cta]') && ! str_contains($attributeCss, '[aria-label]') && ! str_contains($attributeCss, '[data-kind') && ! str_contains($attributeCss, '#cta-id') && ! str_contains($attributes['serialized_blocks'], 'core/html') && 'pass' === ($attributes['source_reports']['wp_block_validity']['status'] ?? ''), 'data, aria, attribute-operator, ID, and class selectors project through exact control markers with canonical validity');

$wrapper = $transform('<style>.wrap a.cta:hover{padding:1rem}.wrap a.cta:focus{color:red}</style><div class="wrap" role="button"><a class="cta" href="/go">Go</a></div>');
$wrapperCss = $css($wrapper);
$wrapperButton = $wrapper['blocks'][0]['innerBlocks'][0] ?? array();
$assert('core/button' === ($wrapperButton['blockName'] ?? '') && str_contains((string) ($wrapperButton['attrs']['className'] ?? ''), 'blocks-engine-control-') && 2 === substr_count($wrapperCss, '> :where(.wp-block-button__link)') && str_contains($wrapperCss, ':hover') && str_contains($wrapperCss, ':focus') && 'pass' === ($wrapper['source_reports']['wp_block_validity']['status'] ?? ''), 'wrapper-driven role=button promotion retains logical anchor selectors, presentation wrapper attributes, and valid blocks');

$navCta = $transform('<style>a.btn.btn-primary.nav-cta{display:inline-flex;align-items:center;gap:8px;font-family:monospace;font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;padding:9px 20px;border-radius:6px;background:#e8a020;color:#050d1a}.nav-cta:hover{background:#f0ac22}.nav-cta:focus{outline:2px solid #fff}</style><main><a class="btn btn-primary nav-cta" href="#cta">Get Early Access</a></main>');
$navCtaMarkup = (string) ($navCta['serialized_blocks'] ?? '');
$navCtaCss = $css($navCta);
$assert(str_contains($navCtaMarkup, 'blocks-engine-control-') && str_contains($navCtaCss, '> :where(.wp-block-button__link){display:inline-flex') && str_contains($navCtaCss, 'font-family:monospace') && str_contains($navCtaCss, 'padding:9px 20px') && str_contains($navCtaCss, 'background:#e8a020') && str_contains($navCtaCss, ':hover{background:#f0ac22}') && str_contains($navCtaCss, ':focus{outline:2px solid #fff}') && 'pass' === ($navCta['source_reports']['wp_block_validity']['status'] ?? ''), 'class-bearing source anchors project compound, typography, paint, and pseudo-state selectors onto valid core/button links');

$inlineLeaves = $transform('<style>.meta{display:flex;gap:10px}.eyebrow{display:flex;gap:10px}.meta span{font:10px monospace;border:1px solid #999;padding:2px 8px}.eyebrow span{font-size:11px;letter-spacing:.1em}</style><div class="eyebrow"><span>Beta</span></div><div class="meta"><span>One</span><span>Two</span></div>');
$inlineMarkup = (string) ($inlineLeaves['serialized_blocks'] ?? '');
$inlineCss = $css($inlineLeaves);
$assert(3 === substr_count($inlineMarkup, '<div class="wp-block-group blocks-engine-semantic-') && 3 === substr_count($inlineCss, ':where(.blocks-engine-semantic-') && 'pass' === ($inlineLeaves['source_reports']['wp_block_validity']['status'] ?? ''), 'CSS-addressed sibling spans retain independent native wrapper identities and projected selector paths without HTML fallback');

$repeatedParents = $transform('<style>.row{display:flex}.row .pill{padding:2px 8px;border:1px solid #999}.other .pill{color:red}</style><div class="row"><span class="pill">First</span></div><div class="row"><span class="pill">Second</span></div><div class="other"><span class="pill">Third</span></div>');
$repeatedMarkup = (string) ($repeatedParents['serialized_blocks'] ?? '');
$repeatedCss = $css($repeatedParents);
preg_match_all('/blocks-engine-semantic-[a-f0-9]+-\d+/', $repeatedMarkup . "\n" . $repeatedCss, $repeatedMarkers);
$assert(2 === count(array_unique($repeatedMarkers[0] ?? array())) && 2 === substr_count($repeatedCss, ':where(.blocks-engine-semantic-') && str_contains($repeatedMarkup, '--blocks-engine-richtext-marker:blocks-engine-richtext-') && str_contains($repeatedCss, 'mark[style*="--blocks-engine-richtext-marker:blocks-engine-richtext-'), 'repeated structural parents allocate unique source-path markers without leaking their box styles into an unrelated inline sibling');

$richTextPill = $transform('<style>p .pill{padding:2px 8px;border:1px solid #999}</style><p>Read <span class="pill">more</span>.</p>');
$richTextPillMarkup = (string) ($richTextPill['serialized_blocks'] ?? '');
$richTextPillCss = $css($richTextPill);
$assert(str_contains($richTextPillMarkup, '<mark style="') && str_contains($richTextPillMarkup, '--blocks-engine-richtext-marker:blocks-engine-richtext-') && str_contains($richTextPillCss, 'mark[style*="--blocks-engine-richtext-marker:blocks-engine-richtext-') && 'pass' === ($richTextPill['source_reports']['wp_block_validity']['status'] ?? ''), 'RichText-contained selector hooks survive through valid mark formatting and projected CSS');

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

$structural = $transform('<style>.product-layout{display:grid;grid-template-columns:1fr 20rem;gap:3rem}.product-layout > .detail-pane{min-width:0}</style><div class="product-layout"><div>Primary</div><aside class="detail-pane">Secondary</aside></div>');
$structuralMarkup = (string) ($structural['serialized_blocks'] ?? '');
$assert(str_contains($structuralMarkup, 'product-layout') && str_contains($structuralMarkup, 'detail-pane') && str_contains($css($structural), '.product-layout > .detail-pane') && 'pass' === ($structural['source_reports']['wp_block_validity']['status'] ?? ''), 'CSS-significant structural group and child classes survive native grid materialization');

$instance = new HtmlTransformer();
$first = $instance->transform('<style>p{color:red}</style><p>First</p>')->toArray();
$second = $instance->transform('<style>.cta:hover{padding:1rem}</style><a class="cta" href="/go" style="padding:1px;background:#000">Go</a>')->toArray();
$assert(! str_contains($css($second), 'blocks-engine-source-p-') && 1 === substr_count($css($second), '> :where(.wp-block-button__link)') && ! str_contains($second['serialized_blocks'], 'core/html'), 'repeated transformer instances reset selector marker state and remain canonical');

if ( $failures > 0 ) {
    fwrite(STDERR, "Author selector semantics unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "Author selector semantics unit tests: {$passes} passed\n");
