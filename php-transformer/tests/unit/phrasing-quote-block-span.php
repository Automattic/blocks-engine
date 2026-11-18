<?php
declare(strict_types=1);

/**
 * Regression coverage for a block-display span inside a phrasing blockquote.
 *
 * Quote blocks are often authored as
 * `<blockquote><span>“</span>Quote text.<span>”</span></blockquote>`, with
 * `blockquote>span:first-child{display:block;font-size:4em}` setting the
 * opening mark on its own large line. A blockquote with only phrasing children
 * lowers to one paragraph that holds them as RichText, so the span stays a
 * RichText run. Source analysis classified the `display:block` span as an
 * inline-layout carrier and scoped its rule behind
 * `p.blocks-engine-inline-layout-carrier > …`, a paragraph that is never
 * emitted. The mark then ran inline into the quote text, so the copy lost a
 * line break and the mark's own line.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$compile = static function (string $html): array {
    $result = ( new HtmlTransformer() )->transform($html, array())->toArray();
    $css    = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( 'author-css' === ( $asset['source'] ?? '' ) ) {
            $css .= (string) ( $asset['content'] ?? '' );
        }
    }

    return array( (string) ( $result['serialized_blocks'] ?? '' ), $css );
};

$css = '.quote-block blockquote>span:first-child{font-size:4em;display:block;opacity:.3}'
    . '.quote-block blockquote>span:last-child{display:none}';

foreach ( array(
    'figure' => '<figure><blockquote><span>“</span>Bedford has helped.<span>”</span></blockquote><figcaption class="source">— John Smith</figcaption></figure>',
    'bare'   => '<blockquote><span>“</span>Bedford has helped.<span>”</span></blockquote>',
) as $label => $quote ) {
    [ $markup, $authorCss ] = $compile('<style>' . $css . '</style><div class="quote-block">' . $quote . '</div>');

    $assert(
        ! str_contains($authorCss, 'blocks-engine-inline-layout-carrier'),
        "{$label}: rules for a block span inside a phrasing quote are not scoped behind an inline-layout carrier paragraph",
        $authorCss
    );
    $assert(
        1 === preg_match('/--blocks-engine-richtext-marker:(blocks-engine-richtext-[a-f0-9]+-0)" role="none">“<\/mark>/', $markup, $match)
            && 1 === preg_match('/' . preg_quote($match[1], '/') . '[^{]*\{[^}]*display:block/', $authorCss),
        "{$label}: the opening mark's RichText run carries its block display",
        $markup . "\n" . $authorCss
    );
}

if ( 0 < $failures ) {
    fwrite(STDERR, "Phrasing quote block span contract failed ({$failures} failing, {$passes} passing)\n");
    exit(1);
}

fwrite(STDOUT, "Phrasing quote block span contract passed: {$passes} assertions\n");
