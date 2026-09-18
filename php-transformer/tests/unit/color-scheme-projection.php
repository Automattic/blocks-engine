<?php
declare(strict_types=1);

/**
 * Regression coverage for color-scheme variant projection (#2028).
 *
 * A source that declares a colour variant and a gradient-background variant
 * under a color-scheme rest-state must keep both under stylesheet ownership
 * and project the gate as `@media (prefers-color-scheme)` so the imported
 * canvas adapts. The gradient computes as `background-image`, not
 * `background-color`. Light-mode paint must stay unchanged.
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
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$cssOf = static function (array $result): string {
    $parts = array();
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( 'css' === ( $asset['kind'] ?? '' ) ) {
            $parts[] = (string) ( $asset['content'] ?? '' );
        }
    }

    return implode("\n", $parts);
};

$source = <<<'HTML'
<style>
.text-gray-900{color:rgb(17 24 39)}
.bg-white{background-color:rgb(255 255 255)}
.dark\:text-gray-100:where([data-mode=dark],[data-mode=dark] *){color:rgb(243 244 246)}
.dark\:bg-\[radial-gradient\(circle_at_top\,\#202124_0\%\,\#151619_48\%\,\#101113_100\%\)\]:where([data-mode=dark],[data-mode=dark] *){background-image:radial-gradient(circle at top,#202124,#151619 48%,#101113)}
</style>
<body class="bg-white text-gray-900 dark:text-gray-100 dark:bg-[radial-gradient(circle_at_top,#202124_0%,#151619_48%,#101113_100%)]">
<p class="text-gray-900 dark:text-gray-100">Hello</p>
<span class="bg-white">Mark</span>
</body>
HTML;

$result = ( new HtmlTransformer() )->transform($source)->toArray();
$css = $cssOf($result);
$markup = (string) ( $result['serialized_blocks'] ?? '' );

$assert(str_contains($markup, 'text-gray-900') && str_contains($markup, 'dark:text-gray-100'), 'authored colour and color-scheme classes survive on content');
$assert(str_contains($css, '.text-gray-900{color:rgb(17 24 39)}'), 'light-mode text colour stays outside the dark media query');
$assert(
    1 === preg_match('/body:not\(\.blocks-engine-specificity-class-[a-f0-9]+-\d+\)\{background-color:rgb\(255 255 255\)\}/', $css)
        || str_contains($css, '.bg-white{background-color:rgb(255 255 255)}'),
    'light-mode canvas background-color is preserved',
    $css
);

$assert(str_contains($css, '@media (prefers-color-scheme: dark)'), 'color-scheme selector gates project as prefers-color-scheme media');
$assert(! str_contains($css, 'data-mode=dark'), 'the dropped document-state gate is not left as the only way the variant can apply');
$assert(str_contains($css, 'color:rgb(243 244 246)'), 'the dark colour variant reaches the stylesheet');
$assert(
    str_contains($css, 'background-image:radial-gradient(circle at top,#202124,#151619 48%,#101113)'),
    'the dark gradient variant is carried as background-image',
    $css
);

preg_match_all('/@media \(prefers-color-scheme: dark\)\{([^}]*(?:\{[^}]*\}[^}]*)*)\}/', $css, $darkBlocks);
$darkCss = implode("\n", $darkBlocks[0] ?? array());
$assert(str_contains($darkCss, 'color:rgb(243 244 246)'), 'dark text colour is inside the dark media query', $darkCss);
$assert(str_contains($darkCss, 'background-image:radial-gradient'), 'dark gradient background-image is inside the dark media query', $darkCss);
$assert(! str_contains($darkCss, 'color:rgb(17 24 39)'), 'light text colour is not moved into the dark media query', $darkCss);
$assert(
    1 === preg_match('/@media \(prefers-color-scheme: dark\)\{[^}]*body:not\(\.blocks-engine-specificity-class-[a-f0-9]+-\d+\)\{[^}]*background-image:radial-gradient/', $css)
        || 1 === preg_match('/@media \(prefers-color-scheme: dark\)\{body:not\(\.blocks-engine-specificity-class-[a-f0-9]+-\d+\)\{background-image:radial-gradient/', $css),
    'the dark gradient paints the rendered body, not only a class that WordPress will not keep on body',
    $css
);

$mediaSource = <<<'HTML'
<style>
.copy{color:rgb(17 24 39);background-color:rgb(255 255 255)}
@media (prefers-color-scheme: dark){
  .copy{color:rgb(243 244 246);background-image:radial-gradient(circle at top,#202124,#151619 48%,#101113)}
}
</style>
<body class="copy"><p class="copy">Hello</p></body>
HTML;
$mediaCss = $cssOf(( new HtmlTransformer() )->transform($mediaSource)->toArray());
$assert(str_contains($mediaCss, '@media (prefers-color-scheme: dark)'), 'an authored prefers-color-scheme query is retained');
$assert(str_contains($mediaCss, 'background-image:radial-gradient(circle at top,#202124,#151619 48%,#101113)'), 'an authored dark gradient background-image is retained');
$assert(str_contains($mediaCss, '.copy{color:rgb(17 24 39);background-color:rgb(255 255 255)}') || str_contains($mediaCss, 'background-color:rgb(255 255 255)'), 'light-mode copy paint is unchanged beside the dark media query');

if ( 0 < $failures ) {
    fwrite(STDERR, "color-scheme projection: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "color-scheme projection passed\n");
