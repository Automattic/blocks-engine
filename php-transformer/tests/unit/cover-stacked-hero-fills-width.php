<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A painted column-flex hero (header row stacked above the hero copy) whose
// cross axis stretches gives every stacked child the full hero width. Core
// shrinks a cover's inner container to its content for any left/right
// content position, which would pull a space-between header row narrower
// than the hero. The cover must keep a full-width inner container and stretch
// the stacked children, while explicit cross-axis alignment keeps mapping to
// a left/right position.

$failures = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$failures): void {
    if ( $expected === $actual ) {
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true) . PHP_EOL);
};

$hero = static function (string $alignItems): array {
    $html = '<style>.hero{display:flex;flex-direction:column;justify-content:flex-start;' . $alignItems . 'min-height:500px;background-image:url(https://example.com/a.jpg);background-size:cover}'
        . '.bar{display:flex;justify-content:space-between}.copy{flex:1 1 auto}</style>'
        . '<section class="hero"><div class="bar"><a href="/">Brand</a><a href="/more">More</a></div><div class="copy"><h1>Title</h1></div></section>';
    $result = (new HtmlTransformer())->transform($html)->toArray();

    return $result['blocks'][0] ?? array();
};

foreach ( array( 'default' => '', 'stretch' => 'align-items:stretch;', 'normal' => 'align-items:normal;' ) as $label => $alignItems ) {
    $cover = $hero($alignItems);
    $assertSame('core/cover', $cover['blockName'] ?? null, $label . ': stacked hero converts to a cover.');
    $assertSame('top center', $cover['attrs']['contentPosition'] ?? null, $label . ': stretched inline axis keeps the full-width inner container.');
    $layout = $cover['innerBlocks'][0]['attrs']['layout'] ?? array();
    $assertSame('vertical', $layout['orientation'] ?? null, $label . ': stacked children stay vertical.');
    $assertSame('stretch', $layout['justifyContent'] ?? null, $label . ': stacked children stretch to the hero width.');
}

$start = $hero('align-items:flex-start;');
$assertSame('top left', $start['attrs']['contentPosition'] ?? null, 'Explicit start alignment still maps to a left position.');
$assertSame('left', $start['innerBlocks'][0]['attrs']['layout']['justifyContent'] ?? null, 'Explicit start alignment keeps left-justified children.');

$end = $hero('align-items:flex-end;');
$assertSame('top right', $end['attrs']['contentPosition'] ?? null, 'Explicit end alignment still maps to a right position.');

if ( $failures > 0 ) {
    exit(1);
}

echo "cover stacked hero fills width: ok\n";
