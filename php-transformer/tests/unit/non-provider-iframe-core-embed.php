<?php
declare(strict_types=1);

// #6455: a source iframe no oEmbed provider serves (a Google Maps embed) was
// imported as the generated `<namespace>/visual-iframe` block, which an editor
// can only change through the importer's own block. It is a core/embed of its
// URL, and the generated theme renders the source's own bare iframe from it.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\IframeEmbedRendererFixture;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};

$url = 'https://maps.google.com/maps?ll=40.720896%2C-74.001037&z=12&t=m&hl=en&output=embed';
$source = '<!DOCTYPE html><html><head></head><body><iframe src="https://maps.google.com/maps?ll=40.720896%2C-74.001037&amp;z=12&amp;t=m&amp;hl=en&amp;output=embed" width="701" height="408"></iframe></body></html>';
$result = (new HtmlTransformer())->transform($source)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$block = $result['blocks'][0] ?? array();

$assert(array() === ($result['source_reports']['generated_blocks'] ?? array()) && ! str_contains($markup, 'visual-iframe'), 'a non-provider iframe registers no generated block');
$assert('core/embed' === ($block['blockName'] ?? null) && $url === ($block['attrs']['url'] ?? null), 'the frame is a core/embed of its source URL');
$assert(array( 'width' => '701', 'height' => '408' ) === IframeEmbedRendererFixture::frame($block), 'the frame attributes travel in core/embed metadata');
$assert(array() === ($result['fallbacks'] ?? array()) && ! str_contains($markup, '<!-- wp:html') && 'pass' === ($result['source_reports']['wp_block_validity']['status'] ?? null), 'the core/embed has a valid save shape and no fallback');

// The theme renders the source frame itself, not core's figure, from the block's current URL.
$assert(
    '<iframe src="https://maps.google.com/maps?ll=40.720896%2C-74.001037&amp;z=12&amp;t=m&amp;hl=en&amp;output=embed" width="701" height="408" style="max-width:100%"></iframe>' === IframeEmbedRendererFixture::render($markup),
    'the frontend renders the bare source iframe'
);
$edited = str_replace('https://maps.google.com/maps?ll=40.720896%2C-74.001037\u0026z=12\u0026t=m\u0026hl=en\u0026output=embed', 'https://www.openstreetmap.org/export/embed.html', $markup);
$assert(str_contains(IframeEmbedRendererFixture::render($edited), 'src="https://www.openstreetmap.org/export/embed.html"'), 'an editor URL change is what the frontend frame loads');
$unsafe = str_replace('https://maps.google.com/maps?ll=40.720896%2C-74.001037\u0026z=12\u0026t=m\u0026hl=en\u0026output=embed', 'javascript:alert(1)', $markup);
$assert(! str_contains(IframeEmbedRendererFixture::render($unsafe), '<iframe'), 'only a credential-free https URL renders as a frame');

// The editor previews the imported URL through the theme's embed handler.
IframeEmbedRendererFixture::load($markup);
$assert(str_contains((string) IframeEmbedRendererFixture::handlerHtml($url), '<iframe src="https://maps.google.com/maps?ll='), 'the embed handler answers the imported frame URL');
$assert(null === IframeEmbedRendererFixture::handlerHtml('https://example.test/other'), 'the embed handler is limited to imported frame URLs');

// The site plan bootstrap ships the runtime only for documents that carry a frame.
$page = static fn (string $content): array => array( 'source_path' => 'contact.html', 'post_type' => 'page', 'route' => array( 'path' => '/contact' ), 'reconciliation_identity' => 'contact', 'canonical_block_markup' => $content, 'metadata' => array() );
$assert(str_contains(ThemeBootstrap::functionsPhp(array(), array(), array(), array(), array(), array( $page($markup) )), "render_block_core/embed"), 'the generated theme bootstraps the frame renderer');
$assert(! str_contains(ThemeBootstrap::functionsPhp(array(), array(), array(), array(), array(), array( $page('<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->') )), "render_block_core/embed"), 'a theme without frames ships no frame renderer');

echo "Non-provider iframe core/embed tests passed ({$assertions} assertions)\n";
