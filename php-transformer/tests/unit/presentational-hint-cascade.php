<?php
declare(strict_types=1);

/**
 * HTML presentational hints (`align`, `valign`) cascade at zero specificity
 * beneath every author rule, the way browsers apply them (#2889).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$submit = '<form action="https://payments.example.test/checkout" method="post"><input type="hidden" name="cmd" value="give"><input type="image" src="https://cdn.example.test/give.gif" alt="Give"></form>';
$cases = array(
    'issue image submit' => array('<div style="text-align:justify"><div align="center">' . $submit . '</div></div>', 'center'),
    'inherited paragraph' => array('<div align="center"><p>One</p></div>', 'center'),
    'inline equivalent' => array('<div style="text-align:center"><p>One</p></div>', 'center'),
    'author class wins' => array('<style>.x{text-align:right}</style><div class="x" align="center"><p>One</p></div>', null),
    'zero specificity author wins' => array('<style>*{text-align:right}</style><p align="center">One</p>', null),
    'layered author wins' => array('<style>@layer base{.x{text-align:right}}</style><p class="x" align="center">One</p>', null),
    'inline wins' => array('<div align="center" style="text-align:left"><p>One</p></div>', 'left'),
    'descendant wins' => array('<div align="center"><p style="text-align:right">One</p></div>', 'right'),
    'normalized heading' => array('<h2 align=" CENTER ">One</h2>', 'center'),
    'invalid hint ignored' => array('<div style="text-align:right"><p align="bogus">One</p></div>', 'right'),
);
$paragraphs = array();
foreach ($cases as $name => [$html, $alignment]) {
    $result = (new HtmlTransformer())->transform($html, array())->toArray();
    $markup = $result['serialized_blocks'];
    // null: an author rule wins, so the stylesheet owns alignment and the
    // losing hint must not be stamped as a block class.
    if (null === $alignment ? str_contains($markup, 'has-text-align-') : !str_contains($markup, 'has-text-align-' . $alignment)) {
        fwrite(STDERR, "FAIL: {$name}: expected {$alignment}\n{$markup}\n");
        exit(1);
    }
    if (in_array($name, array('issue image submit', 'inherited paragraph', 'inline equivalent'), true)
        && (str_contains($markup, 'wp:group') || str_contains($markup, 'blocks-engine-inline-'))) {
        fwrite(STDERR, "FAIL: {$name}: alignment introduced a wrapper or carrier\n{$markup}\n");
        exit(1);
    }
    $paragraphs[$name] = $markup;
}
if ($paragraphs['inherited paragraph'] !== $paragraphs['inline equivalent']) {
    fwrite(STDERR, "FAIL: presentational hint differs from inline equivalent\n");
    exit(1);
}
fwrite(STDOUT, "Presentational hint cascade tests passed.\n");
