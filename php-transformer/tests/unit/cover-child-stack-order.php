<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A source child stacked above an authored ::after overlay of its container
// (`.hero-copy{z-index:1}` as a grid item of `.hero`) is nested inside the
// cover's inner container once `.hero` becomes core/cover. The inner container
// must carry the child's stacking order so the overlay no longer covers it.

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( $condition ) {
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$compile = static function (string $copyZIndex): array {
    $html = '<style>.hero{position:relative;display:grid;place-items:center;min-height:400px;background:#111 url(https://example.com/hero.jpg) center/cover}'
        . '.hero::after{content:"";position:absolute;inset:0;background:#000a}.hero-copy{z-index:' . $copyZIndex . ';color:#fff}</style>'
        . '<section class="hero"><div class="hero-copy"><h1>Festival 2027</h1><p>July 29-31</p><a class="btn" href="/tickets">Tickets</a></div></section>';
    $result = (new ArtifactCompiler())->compileFragment($html, 'src')->toArray();
    $css = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $result['assets']));

    return array( (string) $result['serialized_blocks'], $css );
};

[$markup, $css] = $compile('1');
$assert(str_contains($markup, '<!-- wp:cover'), 'The painted hero converts to a cover.');
$assert(str_contains($markup, 'blocks-engine-cover-stack-1'), 'The cover records the source child stacking order.');
$assert(str_contains($css, '.wp-block-cover.blocks-engine-cover-stack-1>.wp-block-cover__inner-container{z-index:1}'), 'The inner container is stacked above the authored overlay.');

[$markup, $css] = $compile('7');
$assert(str_contains($css, '.wp-block-cover.blocks-engine-cover-stack-7>.wp-block-cover__inner-container{z-index:7}'), 'The source z-index value is carried verbatim.');

[$markup, $css] = $compile('auto');
$assert(! str_contains($markup, 'blocks-engine-cover-stack-'), 'An unstacked child adds no stacking order.');

if ( $failures > 0 ) {
    exit(1);
}

echo "cover child stack order: ok\n";
