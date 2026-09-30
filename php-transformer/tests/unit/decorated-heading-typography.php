<?php
declare(strict_types=1);

/**
 * A heading whose text sits beside a block-level decoration (a rule under the
 * title) is kept as a wrapper carrying the source heading classes, with an
 * editable inner heading. The inner heading must inherit the source heading's
 * typography instead of adding the destination heading defaults (bold weight).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = array();
$markup = (string) ((new HtmlTransformer())->transform(
    '<style>.title{font-weight:400;font-size:44px}</style><main><h2 class="title"><span>Contact Us</span><div><hr></div></h2></main>',
    array()
)->toArray()['serialized_blocks'] ?? '');

if (1 !== preg_match('/<!-- wp:group \{"className":"title"\} -->.*<h2 class="wp-block-heading" style="([^"]*)"/s', $markup, $match)) {
    $failures[] = 'decorated heading keeps the source-classed wrapper and an inner heading: ' . $markup;
} else {
    foreach (array('font-size', 'font-weight', 'line-height', 'letter-spacing', 'text-transform', 'font-style') as $property) {
        if (!str_contains($match[1], $property . ':inherit')) {
            $failures[] = "inner heading inherits {$property}: " . $match[1];
        }
    }
}
if (!str_contains($markup, 'wp:separator')) {
    $failures[] = 'the decoration stays a separate block: ' . $markup;
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Decorated heading typography: 8 passed\n";
