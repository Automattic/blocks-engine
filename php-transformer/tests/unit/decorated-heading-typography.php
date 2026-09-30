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

// A logo heading inside a navigation link paints the link label. It keeps its
// presentation hooks as an inline span; an unstyled block wrapper does not.
$nav = (string) ((new HtmlTransformer())->transform(
    '<style>.logo{font-size:26px;letter-spacing:4px}</style><header><nav><ul>'
    . '<li><a href="/"><div><h3 class="logo">Acme</h3></div></a></li>'
    . '<li><a href="/about"><p class="plain">About</p></a></li><li><a href="/c">Contact</a></li></ul></nav></header><main><p>x</p></main>',
    array()
)->toArray()['serialized_blocks'] ?? '');
if (!str_contains($nav, '"label":"\u003cspan class=\u0022logo blocks-engine-label-typography\u0022\u003eAcme\u003c/span\u003e"')) {
    $failures[] = 'navigation label keeps the typography-owning logo heading as a span: ' . $nav;
}
if (!str_contains($nav, '"label":"About"')) {
    $failures[] = 'an unstyled block wrapper in a navigation label is still reduced to text: ' . $nav;
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Decorated heading typography: 10 passed\n";
