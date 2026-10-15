<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// An iframe `height` attribute is a presentational hint, so any author CSS
// `height` overrides it. A provider embed captured as `height="507"` but
// styled `height:auto;aspect-ratio:16/9` renders at its ratio: its wrapper
// must not be pinned to the attribute height, which freezes it at one
// viewport and drifts from the ratio's own layout rounding. A class-authored
// `aspect-ratio` (outside the resting presentation cascade) still selects
// core's matching ratio preset.

/** @return array{wrapper: string, figure: string} */
$embed = static function (string $html): array {
    $output = stripslashes(json_encode((new HtmlTransformer())->transform($html), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    preg_match('/\.wp-block-embed__wrapper\{height:([^!};]+)/', $output, $wrapper);
    preg_match('/<figure class="([^"]*wp-block-embed[^"]*)"/', $output, $figure);

    return array( 'wrapper' => $wrapper[1] ?? '', 'figure' => $figure[1] ?? '' );
};

$assertions = 0;
$failures = array();
$assert = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    ++$assertions;
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$src = 'src="https://www.youtube.com/embed/abc"';

$ratio = $embed('<style>.frame{width:100%;height:auto;aspect-ratio:16/9}</style><iframe class="frame" ' . $src . ' width="901" height="507"></iframe>');
$assert('' === $ratio['wrapper'], 'an author height:auto overrides the height attribute; no fixed wrapper height (got "' . $ratio['wrapper'] . '")');
$assert(str_contains($ratio['figure'], 'wp-embed-aspect-16-9 wp-has-aspect-ratio'), 'the author aspect-ratio still selects the 16:9 preset');

// Responsive captures state the rule per device document, in `<style media>`,
// alongside an unconditioned rule for the same frame.
$mediaRatio = $embed('<style>iframe{border:0}</style><style media="(min-width:1401px)">.frame{width:100%;height:auto;aspect-ratio:16/9}</style><style media="(max-width:1400px)">.frame{width:100%;height:auto;aspect-ratio:16/9}</style><iframe class="frame" ' . $src . ' width="901" height="507"></iframe>');
$assert('' === $mediaRatio['wrapper'] && str_contains($mediaRatio['figure'], 'wp-embed-aspect-16-9'), 'a media-scoped author height:auto overrides the attribute next to a resting rule (got "' . $mediaRatio['wrapper'] . '")');

$ratioOnly = $embed('<style>.frame{width:100%;aspect-ratio:4/3}</style><iframe class="frame" ' . $src . '></iframe>');
$assert(str_contains($ratioOnly['figure'], 'wp-embed-aspect-4-3 wp-has-aspect-ratio'), 'a class-authored aspect-ratio alone selects its preset');

$cssPixels = $embed('<style>.frame{height:400px}</style><iframe class="frame" ' . $src . ' width="560" height="315"></iframe>');
$assert('400px' === $cssPixels['wrapper'], 'an author pixel height overrides the attribute height (got "' . $cssPixels['wrapper'] . '")');

$attribute = $embed('<iframe ' . $src . ' width="560" height="315"></iframe>');
$assert('315px' === $attribute['wrapper'], 'an unstyled frame keeps its attribute height');

$noRatio = $embed('<style>.frame{height:auto}</style><iframe class="frame" ' . $src . ' width="400" height="300"></iframe>');
$assert('' === $noRatio['wrapper'] && str_contains($noRatio['figure'], 'wp-embed-aspect-4-3'), 'an overridden height keeps the attribute shape as its ratio');

if ( array() !== $failures ) {
    fwrite(STDERR, "Embed author height override failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Embed author height override passed: {$assertions} assertions\n";
