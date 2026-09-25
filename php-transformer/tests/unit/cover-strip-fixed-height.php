<?php
declare(strict_types=1);

/**
 * A fixed-height strip keeps its source height when it also holds an
 * absolutely positioned object-fit:cover image. The image's paint height
 * (a captured viewport or cover box) must not become the strip's min-height.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$engineCss = static function (array $result): string {
    return implode("\n", array_map(
        static fn (array $asset): string => (string) ($asset['content'] ?? ''),
        array_values(array_filter(
            is_array($result['assets'] ?? null) ? $result['assets'] : array(),
            static fn (array $asset): bool => 'engine-support' === ($asset['source'] ?? '')
        ))
    ));
};

$authorCss = static function (array $result): string {
    return implode("\n", array_map(
        static fn (array $asset): string => (string) ($asset['content'] ?? ''),
        array_values(array_filter(
            is_array($result['assets'] ?? null) ? $result['assets'] : array(),
            static fn (array $asset): bool => 'author-css' === ($asset['source'] ?? '')
        ))
    ));
};

$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html)->toArray();

$fixedStrip = $transform(
    '<style>.strip{position:relative}.cover{position:absolute;inset:0;overflow:hidden}.strip-size{min-height:560px}</style>'
    . '<section class="strip"><div class="cover"><img src="cover.jpg" alt="" style="width:100%;height:900px;object-fit:cover"></div><div class="strip-size"></div></section>'
);
$fixedStripEngine = $engineCss($fixedStrip);
$fixedStripAuthor = $authorCss($fixedStrip);
$assert(
    ! str_contains($fixedStripEngine, 'min-height:900px'),
    'fixed-height strip does not adopt the cover image paint height as min-height',
    $fixedStripEngine
);
$assert(
    str_contains($fixedStripAuthor, '.strip-size{min-height:560px}'),
    'fixed strip height remains the authored sizing rule',
    $fixedStripAuthor
);

$nestedStrip = $transform(
    '<style>.strip{display:flex}.column{position:relative}.cover{position:absolute;inset:0}.strip-size{height:560px}</style>'
    . '<section class="strip"><div class="column"><div class="cover"><img src="cover.jpg" alt="" style="width:100%;height:900px;object-fit:cover" width="980" height="560"></div><div class="strip-size"></div></div></section>'
);
$nestedEngine = $engineCss($nestedStrip);
$assert(
    ! str_contains($nestedEngine, 'min-height:900px'),
    'nested fixed-height column does not adopt the cover image paint height',
    $nestedEngine
);
$assert(
    str_contains($authorCss($nestedStrip), '.strip-size{height:560px}'),
    'nested strip keeps its fixed height declaration',
    $authorCss($nestedStrip)
);

$mediaOnly = $transform(
    '<style>.cover{position:absolute}</style>'
    . '<section class="strip"><div class="cover"><img src="cover.jpg" alt="" style="width:100%;height:900px;object-fit:cover"></div></section>'
);
$assert(
    str_contains($engineCss($mediaOnly), 'min-height:900px'),
    'a media-only strip with no fixed height still reserves the out-of-flow image height',
    $engineCss($mediaOnly)
);

if ( $failures > 0 ) {
    fwrite(STDERR, "cover strip fixed height: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "cover strip fixed height: {$passes} passed\n");
