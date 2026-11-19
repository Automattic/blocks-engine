<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$result = (new HtmlTransformer())->transform(
    '<style>.review{padding:24px 36px;min-height:112px}.review figcaption{font-size:9px;line-height:1.5}</style>'
    . '<figure class="review"><blockquote>A quotation</blockquote><figcaption>Publication</figcaption></figure>'
)->toArray();
$citation = $result['blocks'][0]['attrs']['citation'] ?? '';
if (!str_contains($citation, 'display:block;')
    || !str_contains($citation, 'font-size:9px')
    || !str_contains($citation, 'line-height:1.5')
    || 'pass' !== ((new BlockValidityValidator())->validateBlocks($result['blocks'])['status'] ?? '')
) {
    throw new RuntimeException('Consumed figcaption must retain an independent line box: ' . $result['serialized_blocks']);
}
echo "Figure caption line box passed\n";
