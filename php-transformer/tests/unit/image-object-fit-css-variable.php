<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$out = ( new HtmlTransformer() )->transform(
    '<div style="--image-component-object-fit: cover;">'
    . '<img src="hero.jpg" alt="" style="width:9.74vw;height:6.5vw;object-fit:var(--image-component-object-fit)">'
    . '</div>'
)->toArray();
$markup = (string) ( $out['serialized_blocks'] ?? '' );

if ( ! str_contains($markup, 'object-fit:cover') ) {
    fwrite(STDERR, "FAIL: CSS-variable object-fit must resolve to native scale/cover\n" . $markup . "\n");
    exit(1);
}

fwrite(STDOUT, "image object-fit css variable tests: passed\n");
