<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SourceContentConservationReporter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Issue #6456: rendered source text that reaches no emitted block is a named
// html_content_dropped finding, not a silent drop.

$assert = static function (bool $condition, string $message, mixed $context = null): void {
    if ( ! $condition ) {
        throw new RuntimeException($message . ( null === $context ? '' : ': ' . json_encode($context, JSON_UNESCAPED_SLASHES) ));
    }
};
$dropped = static fn (array $result): array => array_values(array_filter(
    $result['diagnostics'] ?? array(),
    static fn (array $diagnostic): bool => SourceContentConservationReporter::CODE === ( $diagnostic['code'] ?? null )
));
$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html)->toArray();

// The #6440 fixture: a product wrapper whose gallery survives while its
// heading and description silently vanished.
$fixture = '<!DOCTYPE html><html lang="en"><head><title>Sample</title></head><body><main><item-info><div><div class="product product--thumbnail_slider"><div><item-gallery><item-slider><ul><li><div><item-opener><div><img src="/media/p.jpg" alt="Photo"></div></item-opener></div></li><li><div><item-opener><div><img src="/media/p.jpg" alt="Photo"></div></item-opener></div></li></ul></item-slider><item-slider><ul><li><button><img srcset="/media/thumb1.webp 416w, /media/thumb1-s.webp 104w" width="416"></button></li><li><button><img srcset="/media/thumb2.webp 416w, /media/thumb2-s.webp 104w" width="416"></button></li></ul></item-slider></item-gallery></div><div><section><div><h1>Sample product</h1><p>Short description of the product.</p></div></section></div></div></div></item-info></main></body></html>';
// #6475 fixed the #6440 drop: the wrapper now keeps its heading and
// description, so the check must stay quiet on it.
$result = $transform($fixture);
$assert(str_contains((string) $result['serialized_blocks'], 'Sample product'), 'The #6440 fixture keeps its heading on trunk', $result['serialized_blocks']);
$assert(array() === $dropped($result), 'The #6440 fixture yields no html_content_dropped finding once its content survives', $dropped($result));

// A genuine drop that conversion still makes: a definition term vanishes
// while its description survives. It must be named, not silent.
$lossy = '<!DOCTYPE html><html lang="en"><head><title>Glossary</title></head><body><main><dl><dt>Term</dt><dd><p>Block-level description</p></dd></dl></main></body></html>';
$findings = $dropped($transform($lossy));
$assert(1 === count($findings) && in_array('Term', $findings[0]['lost_text'], true), 'A definition term lost from its list is reported', $findings);
$finding = $findings[0];
$assert('content_loss' === $finding['loss_class'] && 'warning' === $finding['severity'], 'The finding is a content-loss warning', $finding);
$assert(is_string($finding['selector'] ?? null) && '' !== $finding['selector'], 'The finding names the dropped subtree', $finding);

$compiled = ( new ArtifactCompiler() )->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $lossy)))->toArray();
$metrics = $compiled['source_reports']['wordpress_site_plan']['quality']['metrics'] ?? array();
$assert(( $metrics['content_loss_count'] ?? 0 ) >= 1, 'The site plan counts the dropped content', $metrics);

// Content that conversion keeps, or that never renders, is no finding.
$negatives = array(
    'script, style and template' => '<body><main><p>Visible copy</p><script>var message = "Script words";</script><style>.x::after{content:"Style words"}</style><template><p>Template words</p></template><noscript>Enable scripts please</noscript></main></body>',
    'hidden and inert' => '<body><main><p>Visible copy</p><div hidden><p>Hidden words</p></div><div inert style="display:none"><p>Inert words</p></div><p style="display:none">Inline hidden words</p></main></body>',
    'aria-hidden and visually hidden' => '<body><main><a href="/photo"><span class="screen-reader-text">View fullsize</span><img src="/photo.webp" alt="Photo"></a><div aria-hidden="true" role="presentation">Ghost copy words</div><p>Visible copy</p></main></body>',
    'stylesheet hidden at every viewport' => '<body><style>.mf{display:none}</style><main><p class="mf">Never shown words</p><p>Visible copy</p></main></body>',
    'responsive counterparts' => '<body><style>.desk{display:block}.mob{display:none}@media(max-width:600px){.desk{display:none}.mob{display:block}}</style><header><nav class="desk" aria-label="Main"><a href="/">Home</a><a href="/about/">About us</a></nav><nav class="mob" aria-label="Mobile"><a href="/">Home</a><a href="/about/">About us</a></nav></header><main><p>Visible copy</p></main></body>',
    'generated block content attributes' => '<body><main><ul class="products"><li class="column"><product-card class="product-card"><figure><a href="/products/alpha"><img src="Alpha.jpg" alt="Alpha"></a></figure><h2><a href="/products/alpha">Alpha</a></h2><span>$145.000</span></product-card></li><li class="column"><product-card class="product-card"><figure><a href="/products/beta"><img src="Beta.jpg" alt="Beta"></a></figure><h2><a href="/products/beta">Beta</a></h2><span>$145.000</span></product-card></li></ul></main></body>',
    'plain conversion' => '<body><main><h1>Title words</h1><p>Body with <strong>bold</strong> and <a href="/x">link text</a>.</p><ul><li>First item</li><li>Second item</li></ul><blockquote><p>Quoted words</p></blockquote></main></body>',
);
foreach ( $negatives as $name => $html ) {
    $findings = $dropped($transform($html));
    $assert(array() === $findings, $name . ' produces no html_content_dropped finding', $findings);
}

// The generated-block negative only proves something when the text truly
// lives in a block attribute rather than in saved HTML.
$generated = $transform($negatives['generated block content attributes']);
$assert(str_contains((string) $generated['serialized_blocks'], '"content":'), 'Product cards convert to a generated block carrying a content attribute', $generated['serialized_blocks']);

echo "source-content-conservation: ok\n";
