<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\BackgroundImageExtractor;

$failures = 0;
$assertSame = static function (string $expected, string $actual, string $message) use (&$failures): void {
    if ( $expected === $actual ) {
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true) . PHP_EOL);
};

$extractor = new BackgroundImageExtractor();

$assertSame(
    'assets/top.png',
    $extractor->urlFromStyle('background-image:url("assets/top.png"),url("assets/bottom.png")'),
    'Layered backgrounds select the first URL because CSS paints the first layer on top.'
);

$assertSame(
    'assets/photo.png',
    $extractor->urlFromStyle('background:linear-gradient(#0008,#0008),url("assets/photo.png") center/cover'),
    'Background shorthands select the first image URL after non-image layers.'
);

$assertSame(
    'data:image/svg+xml,%3Csvg%3E;%3C/svg%3E',
    $extractor->urlFromStyle('background-image:url("data:image/svg+xml,%3Csvg%3E;%3C/svg%3E");color:#fff'),
    'Declaration parsing keeps semicolons inside URL functions intact.'
);

$assertSame(
    '',
    $extractor->urlFromStyle('background-image:url("javascript:alert(1)")'),
    'Unsafe image URLs remain rejected.'
);

echo "background image extractor ok\n";

exit(0 === $failures ? 0 : 1);
