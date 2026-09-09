<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$source = '<div><section><video src="movie.mp4"></video></section><div><a href="https://x.com">X</a><a href="https://facebook.com">Facebook</a></div></div>';
$result = (new HtmlTransformer())->transform($source)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');

if (! str_contains($markup, '<!-- wp:video')) {
    fwrite(STDERR, "FAIL: a social-links descendant must not consume sibling video content\n");
    exit(1);
}

echo "Social-links boundary tests passed\n";
