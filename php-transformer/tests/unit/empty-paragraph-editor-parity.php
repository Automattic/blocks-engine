<?php
declare(strict_types=1);

/**
 * Saved, an empty source paragraph is an empty element: it has no line box,
 * and authored `p:empty` rules size it. In the editor, its RichText holds a
 * padding character and a placeholder, so it is not `:empty` and gains a line
 * of height. The editor must render it with the same geometry.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

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
$transform = static function (string $html): array {
    $result = ( new HtmlTransformer() )->transform($html)->toArray();
    $css = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( is_array($asset) && 'css' === ($asset['kind'] ?? null) ) {
            $css .= (string) ($asset['content'] ?? '');
        }
    }
    return array( (string) ($result['serialized_blocks'] ?? ''), $css );
};

list( $markup, $css ) = $transform(
    '<style>@media (min-width:600px){.card p:empty{min-height:1em}}.card p:not(:empty){color:red}</style>'
    . '<main><div class="card"><h3>Title</h3><p></p><p>Body</p></div></main>'
);
$marker = 'blocks-engine-empty-source-paragraph';
$editorEmpty = ':root .editor-styles-wrapper p.' . $marker . '[data-empty="true"]';

$assert(1 === preg_match('/<!-- wp:paragraph \{"className":"' . $marker . '"\} --><p class="' . $marker . '"><\/p>/', $markup), 'an empty source paragraph is marked', $markup);
$assert(1 === substr_count($markup, $marker . '"></p>') && ! str_contains($markup, $marker . '">Body'), 'only the empty paragraph is marked', $markup);
$assert(str_contains($css, $editorEmpty . '{line-height:0}'), 'the editor collapses the padding line of an empty source paragraph', $css);
$assert(str_contains($css, $editorEmpty . '>[data-rich-text-placeholder]{display:none}'), 'the editor withholds the placeholder of an empty source paragraph', $css);
$assert(1 === preg_match('/@media \(min-width:600px\)\{[^@]*:where\(:root \.editor-styles-wrapper\) \.card p\[data-empty="true"\]\{min-height:1em\}/', $css), 'an authored :empty rule reaches the empty paragraph in the editor, inside its media query', $css);
$assert(! str_contains($css, ':not([data-empty="true"])'), 'a negated :empty rule gets no editor variant', $css);

list( $plainMarkup, $plainCss ) = $transform('<main><p>Only text</p></main>');
$assert(! str_contains($plainMarkup, $marker) && ! str_contains($plainCss, $marker), 'documents without empty paragraphs get no marker or editor rule', $plainCss);

if ( 0 < $failures ) {
    fwrite(STDERR, "empty paragraph editor parity FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "empty paragraph editor parity passed: {$passes} assertions\n";
