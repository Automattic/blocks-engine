<?php
declare(strict_types=1);

/**
 * A `<font size>` attribute is a presentational hint: any author rule that
 * sets `font-size` on the element outranks it. A heading whose text sits in
 * `<font size="7">` while a max-width media query resets that font to
 * `inherit` renders the heading's own breakpoint size on narrow screens and
 * the hint's 48px only where no author rule applies.
 *
 * The RichText carrier that replaces the `<font>` must keep both halves: the
 * author rule has to follow the carrier, and the hint must not be frozen
 * inline where it would outrank that rule at every width.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$transform = static function (string $important): array {
    $css = '.hero h2{font-size:36px}'
        . '@media screen and (min-width:767px){.hero h2{font-size:70px !important}}'
        . '@media screen and (max-width:1024px){.hero h2 font{font-size:inherit' . $important . '}}';
    $result = ( new HtmlTransformer() )->transform(
        '<div class="hero"><h2 class="title"><font color="#ffffff" size="7">Studio title</font></h2></div>',
        array( 'static_css' => $css )
    )->toArray();
    $css = array();
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( 'css' === ($asset['kind'] ?? '') ) {
            $css[(string) ($asset['source'] ?? '') . ':' . (string) ($asset['path'] ?? '')] = (string) ($asset['content'] ?? '');
        }
    }

    return array( (string) ($result['serialized_blocks'] ?? ''), $css, $result );
};

foreach ( array( 'important' => ' !important', 'normal' => '' ) as $label => $important ) {
    [ $blocks, $css, $result ] = $transform($important);
    preg_match('/<mark (?:class="[^"]*" )?style="([^"]*)"[^>]*>Studio title<\/mark>/', $blocks, $mark);
    preg_match('/--blocks-engine-richtext-marker:(blocks-engine-richtext-[a-z0-9-]+)/', $mark[1] ?? '', $marker);
    $marker = $marker[1] ?? '';
    $author = implode("\n", array_filter($css, static fn (string $key): bool => str_starts_with($key, 'author-css:'), ARRAY_FILTER_USE_KEY));
    $before = implode("\n", array_filter($css, static fn (string $key): bool => str_contains($key, 'before-author'), ARRAY_FILTER_USE_KEY));

    $assert('' !== $marker, "{$label}: the <font> carrier keeps a selector hook for the author rule naming it", $blocks);
    $assert(! str_contains($mark[1] ?? '', 'font-size'), "{$label}: the size hint is not frozen inline over the breakpoint rule", $blocks);
    $assert(
        '' !== $marker && 1 === preg_match('/@media screen and \(max-width:1024px\)\{[^{}]*' . preg_quote($marker, '/') . '[^{}]*\{font-size:inherit/', $author),
        "{$label}: the max-width author rule follows the carrier",
        $author
    );
    $assert(
        '' !== $marker && str_contains($before, ':where(mark[style*="--blocks-engine-richtext-marker:' . $marker . ';"]') && str_contains($before, '{font-size:48px}'),
        "{$label}: the size hint is restated at zero specificity ahead of the author stylesheet",
        $before
    );
    $assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? ''), "{$label}: the heading remains Gutenberg-valid", $blocks);
}

// Without any author rule on the <font>, the hint is the only size and stays inline.
$plain = ( new HtmlTransformer() )->transform('<h2><font size="7">Plain</font></h2>')->toArray();
$assert(
    str_contains((string) ($plain['serialized_blocks'] ?? ''), 'font-size:48px'),
    'an unstyled <font size> keeps its inline size',
    (string) ($plain['serialized_blocks'] ?? '')
);

// Reduced full-document regression: bare type selectors must follow replaced inlines too.
$fixture = '<!doctype html><html><head><meta charset="utf-8"><title>Font size media</title>'
    . '<style>body{font-size:14px}h2{font-size:40px}@media (max-width:767px){h2{font-size:24px}font{font-size:inherit!important}}</style></head>'
    . '<body><main><h2><strong><font color="#3f3f3f" size="4">Follow us</font></strong></h2><p><font size="2">Small print.</font></p></main></body></html>';
$regression = ( new HtmlTransformer() )->transform($fixture)->toArray();
$blocks = (string) ($regression['serialized_blocks'] ?? '');
$author = '';
$before = '';
foreach ( $regression['assets'] ?? array() as $asset ) {
    if ( 'css' !== ($asset['kind'] ?? '') ) continue;
    if ( 'author-css' === ($asset['source'] ?? '') ) $author .= $asset['content'];
    if ( str_contains((string) ($asset['path'] ?? ''), 'before-author') ) $before .= $asset['content'];
}
foreach ( array( 'Follow us' => '18px', 'Small print.' => '13px' ) as $text => $size ) {
    preg_match('/<mark\\b[^>]*style="([^"]*)"[^>]*>' . preg_quote($text, '/') . '<\\/mark>/', $blocks, $mark);
    preg_match('/--blocks-engine-richtext-marker:(blocks-engine-richtext-[a-z0-9-]+)/', $mark[1] ?? '', $hook);
    $marker = $hook[1] ?? '';
    $assert('' !== $marker && ! str_contains($mark[1] ?? '', 'font-size'), "$text: size is not frozen inline", $blocks);
    $assert('' !== $marker && 1 === preg_match('/[^{}]*' . preg_quote($marker, '/') . '[^{}]*\\{font-size:' . $size . '\\}/', $before), "$text: desktop keeps the presentational size", $before);
    $assert(str_contains($blocks, 'blocks-engine-subject-tag-font') && 1 === preg_match('/@media \\(max-width:767px\\)\\{(?:[^{}]*\\{[^{}]*\\})*[^{}]*blocks-engine-subject-tag-font[^{}]*\\{font-size:inherit!important/', $author), "$text: mobile inherits within the original media", $author);
}
$unconditional = ( new HtmlTransformer() )->transform(str_replace('@media (max-width:767px){h2{font-size:24px}font{font-size:inherit!important}}', 'font{font-size:inherit!important}', $fixture))->toArray();
$assert(! str_contains(json_encode($unconditional['assets']), 'font-size:18px') && ! str_contains(json_encode($unconditional['assets']), 'font-size:13px') && ! preg_match('/font-size:(?:18|13)px/', (string) $unconditional['serialized_blocks']), 'unconditional author inheritance still suppresses the size hints');

if ( $failures > 0 ) {
    fwrite(STDERR, "Legacy font size breakpoint hint: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Legacy font size breakpoint hint passed: {$passes} assertions\n");
