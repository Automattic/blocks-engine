<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = file_get_contents(dirname(__DIR__) . '/fixtures/positioned-linked-logo.html');
if (false === $html) {
    throw new RuntimeException('Missing reduced logo fixture.');
}
$out = (new HtmlTransformer())->transform($html)->toArray();
$markup = (string) $out['serialized_blocks'];
if (1 !== preg_match('/<figure[^>]*class="([^"]*logo-box[^"]*)"/', $markup, $figure)) {
    throw new RuntimeException('The sized source box remains the image figure.');
}
foreach (array('blocks-engine-synthetic-flex-image-figure', 'blocks-engine-synthetic-image-figure-inline', 'blocks-engine-synthetic-image-figure-strut') as $transparent) {
    if (str_contains($figure[1], $transparent)) {
        throw new RuntimeException('A source containing block must not become transparent.');
    }
}
if (!str_contains($markup, 'blocks-engine-semantic-')) {
    throw new RuntimeException('The positioned unclassed image needs separate selector ownership from its source box.');
}
echo "positioned linked logo ok\n";
