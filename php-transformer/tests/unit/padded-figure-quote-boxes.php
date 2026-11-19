<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

// A small figcaption needs its own line box, not an inline span in the
// larger inherited cite line box. Do not change the native quote topology.
$result = (new HtmlTransformer())->transform(
    '<style>body{font-size:16px;line-height:1.5}'
    . '.card{padding:24px 36px 20px;margin:0}'
    . '.card blockquote{margin:0 0 12px;font-size:14px;line-height:1.45}'
    . '.card figcaption{font-size:9px;font-weight:700}</style>'
    . '<figure class="card"><blockquote>A quotation</blockquote><figcaption>Publication</figcaption></figure>'
)->toArray();
$quote = $result['blocks'][0] ?? array();
$citation = $quote['attrs']['citation'] ?? '';
if ('core/quote' !== ($quote['blockName'] ?? '')
    || ! str_contains($citation, 'display:block;font-size:9px;font-weight:700')
    || ! str_contains($citation, '>Publication</span>')
    || 'core/paragraph' !== ($quote['innerBlocks'][0]['blockName'] ?? '')
    || 'pass' !== ((new BlockValidityValidator())->validateBlocks($result['blocks'])['status'] ?? '')
) {
    throw new RuntimeException('Styled figure captions must retain their own line box inside native quotes: ' . $result['serialized_blocks']);
}

// Unstyled citations must not acquire an unnecessary carrier.
$plain = (new HtmlTransformer())->transform('<figure><blockquote>Quote</blockquote><figcaption>Source</figcaption></figure>')->toArray();
if ('Source' !== ($plain['blocks'][0]['attrs']['citation'] ?? '')) {
    throw new RuntimeException('Unstyled figure citations must remain unchanged.');
}
echo "Figure caption line boxes passed\n";
