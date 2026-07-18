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

$instance = new HtmlTransformer();
$first = $instance->transform('<style>p{color:red}</style><p>First</p>')->toArray();
$second = $instance->transform('<style>.cta:hover{padding:1rem}</style><a class="cta" href="/go" style="padding:1px;background:#000">Go</a>')->toArray();
$assert(! str_contains($css($second), 'blocks-engine-source-p-') && 1 === substr_count($css($second), '> :where(.wp-block-button__link)') && ! str_contains($second['serialized_blocks'], 'core/html'), 'repeated transformer instances reset selector marker state and remain canonical');

if ( $failures > 0 ) {
    fwrite(STDERR, "Author selector semantics unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "Author selector semantics unit tests: {$passes} passed\n");
