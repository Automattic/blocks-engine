<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

// A small figcaption must own its line box, not sit inside a larger cite
// strut. The latter grows a wrapping quote rail and shifts subsequent lists.
$result = (new HtmlTransformer())->transform(
    '<style>.rail{display:flex;flex-wrap:wrap}.card{flex:0 0 280px;padding:24px}'
    . '.card blockquote{margin:0 0 12px;font-size:14px;line-height:1.45}'
    . '.card figcaption{font-size:9px;font-weight:700;letter-spacing:.16em}</style>'
    . '<div class="rail"><figure class="card"><blockquote>A quotation that wraps across lines.</blockquote>'
    . '<figcaption>Publication</figcaption></figure></div><ol><li>Following content</li></ol>'
)->toArray();
$quote = $result['blocks'][0]['innerBlocks'][0] ?? array();
$citation = $quote['attrs']['citation'] ?? '';
if ('core/quote' !== ($quote['blockName'] ?? '')
    || !str_contains($citation, '<span style="display:block;font-size:9px;font-weight:700;letter-spacing:.16em">Publication</span>')
    || str_contains(json_encode($result), '>cite{')
    || 'core/list' !== ($result['blocks'][1]['blockName'] ?? '')
    || 'pass' !== ((new BlockValidityValidator())->validateBlocks($result['blocks'])['status'] ?? '')
) {
    throw new RuntimeException('Consumed figure captions must retain their own line box without restyling the citation host: ' . json_encode($result));
}
// Unstyled captions must retain native citation behavior, even under inherited
// typography. They do not have a separate authored line box to project.
$plain = (new HtmlTransformer())->transform(
    '<div style="font-family:serif;font-weight:700;color:red"><figure class="testimonial-card">'
    . '<blockquote><p>Build with care.</p></blockquote><figcaption><strong>Ada</strong>, Founder</figcaption>'
    . '</figure></div>'
)->toArray();
if (!str_contains($plain['serialized_blocks'], '<cite><strong>Ada</strong>, Founder</cite>')
    || str_contains(json_encode($plain), '>cite{')
) {
    throw new RuntimeException('Unstyled captions must not acquire citation host overrides: ' . json_encode($plain));
}
echo "Figure quote caption line box passed\n";
