<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

// No span or format tag: an anchor alone must enter inline materialization.
$html = '<style>a{color:inherit;text-decoration:inherit}.muted{color:#aaa}.primary{color:#c86}</style>'
    . '<div><p class="muted">Your cart is empty. <a class="primary" href="/shop">Start shopping</a></p></div>';
$result = (new HtmlTransformer())->transform($html)->toArray();
$markup = $result['serialized_blocks'];
if (!preg_match('/<a[^>]*href="\/shop"[^>]*><mark[^>]*style="[^"]*color:#c86[^"]*"[^>]*>Start shopping<\/mark><\/a>/', $markup)) {
    throw new RuntimeException('Anchor-only RichText carries its resolved author paint inside the editable link.');
}
if ('pass' !== ((new BlockValidityValidator())->validateBlocks($result['blocks'])['status'] ?? '')) {
    throw new RuntimeException('The inline link carrier must be Gutenberg-valid.');
}
if ($markup !== (new HtmlTransformer())->transform($html)->toArray()['serialized_blocks']) {
    throw new RuntimeException('Anchor paint materialization must be deterministic.');
}
echo "Anchor-only RichText paint passed\n";
