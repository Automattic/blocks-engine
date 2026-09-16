<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$out = ( new HtmlTransformer() )->transform(
    '<style>.section-background img{object-fit:cover;width:100%;height:100%}</style>'
    . '<main><div class="section-background">'
    . '<img alt="" src="hero.jpg" width="1536" height="1024" style="display:block;object-position:50% 50%">'
    . '</div></main>'
)->toArray();
$markup = (string) ( $out['serialized_blocks'] ?? '' );
if ( ! str_contains($markup, 'object-fit:cover') ) {
    fwrite(STDERR, "FAIL: section background images must keep stylesheet object-fit:cover\n" . $markup . "\n");
    exit(1);
}

fwrite(STDOUT, "section background object-fit tests: passed\n");
