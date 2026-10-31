<?php
declare(strict_types=1);

/**
 * A selector the matcher cannot parse (`:dir()`) that names a button-like
 * link by class, id or an escaped class still lands on the control blocks it
 * names, and only those.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$out = ( new HtmlTransformer() )->transform(
    '<style>.go{display:inline-block;padding:10px 20px;background:#036;color:#fff;border-radius:4px}'
    . ':is(.row .go):dir(ltr){letter-spacing:3px}'
    . ':where(.row) #buy:dir(ltr){letter-spacing:5px}'
    . ':is(.\31 st):dir(ltr){letter-spacing:7px}</style>'
    . '<div class="row"><a class="go" href="/a">Go</a><a id="buy" class="go" href="/b">Buy</a><a class="go 1st" href="/c">First</a></div>'
)->toArray();
$css = '';
foreach ( $out['assets'] ?? array() as $asset ) {
    if ( is_array($asset) && 'css' === ( $asset['kind'] ?? '' ) ) {
        $css .= (string) ( $asset['content'] ?? '' );
    }
}

$failures = 0;
// Each authored rule names a set of controls: the class names all three, the
// id one, and the class CSS spells escaped only the link that carries it.
foreach ( array( '3px' => 3, '5px' => 1, '7px' => 1 ) as $value => $controls ) {
    $projected = preg_match('/([^{}]*)\{letter-spacing:' . $value . '\}/', $css, $match)
        ? preg_match_all('/:where\(\.blocks-engine-control-[0-9a-f]+-\d+\)> :where\(\.wp-block-button__link\)/', $match[1])
        : 0;
    if ( $controls !== $projected ) {
        ++$failures;
        fwrite(STDERR, "FAIL: letter-spacing:{$value} reaches {$projected} control blocks, expected {$controls}\n{$css}\n");
    }
}
if ( $failures > 0 ) {
    exit(1);
}
echo "unsupported functional control selector ok\n";
