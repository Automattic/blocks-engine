<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$result = (new HtmlTransformer())->transform(
    '<style>.review{font-size:20px}.review .source{font-size:.875em;opacity:.5}</style>'
    . '<figure class="review"><blockquote>A quotation</blockquote>'
    . '<figcaption class="source">An author</figcaption></figure>'
)->toArray();
$citation = $result['blocks'][0]['attrs']['citation'] ?? '';
// One source caption must remain one typography carrier. A classed outer
// span plus an inline-sized inner span compounds .875em to .765625em.
if ('<span class="source" style="font-size:.875em">An author</span>' !== $citation) {
    throw new RuntimeException('Relative caption size must apply once: ' . $citation);
}
if ('pass' !== ((new BlockValidityValidator())->validateBlocks($result['blocks'])['status'] ?? '')) {
    throw new RuntimeException('Quote must remain valid native block markup');
}
echo "Figure quote relative caption passed\n";
