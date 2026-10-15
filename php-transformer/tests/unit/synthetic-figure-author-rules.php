<?php
declare(strict_types=1);

/**
 * Core synthesizes <figure> wrappers the source never had: a bare <img>
 * lowers into core/image, a bare provider <iframe> into core/embed. Author
 * rules whose selectors name the figure type addressed authored figures only,
 * so they must reach the block converted from a source <figure> and never a
 * synthesized wrapper. Otherwise a reset such as `figure{margin:0 0 1rem}`
 * adds space below every lowered image and video.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$html = '<html><head><style>'
    . 'figure{margin:0 0 1rem;padding:4px}'
    . 'figure,.note{border-top:1px solid red}'
    . '</style></head><body><main>'
    . '<div class="card"><img src="/a.jpg" alt="A" width="400" height="300"></div>'
    . '<div class="video"><iframe src="https://www.youtube.com/embed/neutral01?rel=0" width="560" height="315"></iframe></div>'
    . '<figure class="authored"><img src="/b.jpg" alt="B" width="400" height="300"><figcaption>Caption</figcaption></figure>'
    . '<p class="note">Note</p>'
    . '</main></body></html>';

$result = ( new HtmlTransformer() )->transform($html, array())->toArray();
$blocks = (string) ($result['serialized_blocks'] ?? '');
$css = '';
foreach ( $result['assets'] ?? array() as $asset ) {
    if ( is_array($asset) && 'css' === ($asset['kind'] ?? null) ) {
        $css .= (string) ($asset['content'] ?? '');
    }
}

preg_match_all('/<figure class="([^"]*)"/', $blocks, $figureMatches);
$figures = array();
foreach ( $figureMatches[1] as $classes ) {
    $kind = str_contains($classes, 'authored') ? 'authored' : ( str_contains($classes, 'wp-block-embed') ? 'embed' : 'image' );
    $figures[$kind] = ' ' . $classes . ' ';
}
$assert(3 === count($figures), 'the page lowers an authored figure, a synthesized image figure and a synthesized embed figure', implode(' | ', $figureMatches[1]));
$marker = 1 === preg_match('/ (blocks-engine-source-figure-[a-z0-9-]+) /', $figures['authored'] ?? '', $markerMatch) ? $markerMatch[1] : '';
$assert('' !== $marker, 'the figure converted from a source <figure> carries the source figure marker', $figures['authored'] ?? '');
foreach ( array( 'image', 'embed' ) as $kind ) {
    $assert(isset($figures[$kind]) && ! str_contains($figures[$kind], ' blocks-engine-source-figure-'), "the synthesized {$kind} figure carries no source figure marker", $figures[$kind] ?? '');
}

$figureRules = array();
$noteSelectors = array();
( new CssStylesheetTransformer() )->visitStyleRules($css, static function (string $prelude, string $body) use (&$figureRules, &$noteSelectors): string {
    if ( ! str_contains($body, '1rem') && ! str_contains($body, '4px') && ! str_contains($body, 'solid red') ) {
        return '';
    }
    foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
        $selector = trim($selector);
        if ( str_contains($selector, 'note') ) {
            $noteSelectors[] = $selector;
        } else {
            $figureRules[] = $selector . '{' . $body . '}';
        }
    }
    return '';
});

$assert(3 <= count($figureRules), 'the author figure margin, padding and border rules are all projected', $css);
foreach ( $figureRules as $rule ) {
    $assert(
        '' !== $marker && str_contains($rule, '.' . $marker) && 1 !== preg_match('/(?:^|[\s>+~,(])figure\b/', $rule),
        'an author figure rule addresses only the source figure marker, never the figure type',
        $rule
    );
}
$assert(array() !== $noteSelectors, 'a non-figure selector in the same list is still projected', $css);

if ( 0 < $failures ) {
    fwrite(STDERR, "synthetic figure author rules FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "synthetic figure author rules passed: {$passes} assertions\n";
