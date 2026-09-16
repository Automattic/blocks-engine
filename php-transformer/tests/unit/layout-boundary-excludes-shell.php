<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( $condition ) {
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$nest = static function (string $inner, int $depth): string {
    for ( $i = 0; $i < $depth; ++$i ) {
        $inner = '<div class="depth-' . $i . '">' . $inner . '</div>';
    }

    return $inner;
};

$shell = $nest(
    '<header><nav aria-label="Site"><ul><li><a href="/">Home</a></li><li><a href="/about">About</a></li></ul></nav></header>'
    . '<main><img src="hero.jpg" alt="Hero"></main>',
    24
);
$shellResult = ( new HtmlTransformer() )->transform($shell)->toArray();
$shellMarkup = (string) ($shellResult['serialized_blocks'] ?? '');
$assert(
    ! str_starts_with(trim($shellMarkup), '<!-- wp:custom/responsive-layout'),
    'a deep wrapper that owns header chrome is not captured as one layout boundary'
);
$assert(
    str_contains($shellMarkup, '<!-- wp:navigation') || str_contains($shellMarkup, '<header'),
    'header chrome remains a native landmark or navigation block'
);

$main = '<main>' . $nest('<a href="/story" aria-label="Story"><img src="story.jpg" alt="Story"></a>', 24) . '</main>';
$mainResult = ( new HtmlTransformer() )->transform($main)->toArray();
$mainMarkup = (string) ($mainResult['serialized_blocks'] ?? '');
$assert(
    str_contains($mainMarkup, '<!-- wp:custom/responsive-layout {"content":'),
    'a deep media main without document chrome still compiles as a layout boundary'
);

$mainWithHeader = '<main class="story">' . $nest(
    '<header><nav aria-label="Primary"><a href="/about">About</a></nav></header>'
    . '<section><h1>Deep story</h1><img src="hero.jpg" alt="Hero"></section>',
    21
) . '</main>';
$mainHeaderResult = ( new HtmlTransformer() )->transform($mainWithHeader)->toArray();
$mainHeaderMarkup = (string) ($mainHeaderResult['serialized_blocks'] ?? '');
$assert(
    str_contains($mainHeaderMarkup, '<!-- wp:custom/responsive-layout {"content":'),
    'a deep media main may still capture when an inner header is page content'
);

if ( 0 !== $failures ) {
    exit(1);
}

echo "layout-boundary-excludes-shell passed\n";
