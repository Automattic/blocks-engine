<?php
declare(strict_types=1);

/**
 * A padded content-box link stretched by a sized flex wrapper renders a border
 * box equal to the wrapper. Its lowered link fills the carrier with
 * `width:100%`/`height:100%`, so that fill must size the border box: the
 * native link rule restating the source content box would otherwise add the
 * padding a second time (125x60 rendered as 179x100). The browser counterpart
 * is button-flex-fill-content-box-browser.mjs.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$fixture = (string) file_get_contents(dirname(__DIR__) . '/fixtures/button-flex-fill-content-box.html');
$failures = 0;
$check = static function (string $label, string $html, bool $expectBorderBox) use (&$failures): void {
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $css = implode('', array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
    $fill = null;
    $restatesContentBox = false;
    foreach ( explode('}', $css) as $rule ) {
        $parts = explode('{', $rule);
        $selector = (string) ($parts[ count($parts) - 2 ] ?? '');
        $body = (string) end($parts);
        if ( ! str_ends_with(trim($selector), 'wp-block-button__link)') && ! str_ends_with(trim($selector), '>.wp-block-button__link') ) continue;
        if ( str_contains($body, 'box-sizing:content-box') ) $restatesContentBox = true;
        if ( str_contains($body, 'height:100%') && str_contains($body, 'width:100%!important') ) $fill = $body;
    }
    if ( null === $fill ) {
        fwrite(STDERR, "FAIL {$label}: the stretched link must fill its flex carrier\n");
        ++$failures;
        return;
    }
    if ( ! $restatesContentBox ) {
        fwrite(STDERR, "FAIL {$label}: the fixture must exercise the content-box restatement\n");
        ++$failures;
    }
    $hasBorderBox = str_contains($fill, 'box-sizing:border-box!important');
    if ( $expectBorderBox !== $hasBorderBox ) {
        fwrite(STDERR, "FAIL {$label}: the percentage fill " . ($expectBorderBox ? 'must' : 'must not') . " size the border box: {$fill}\n");
        ++$failures;
    }
};

$check('auto-sized content-box link', $fixture, true);
// An authored block size belongs to the source content box; the fill keeps it.
$check('authored min-height', str_replace('line-height:20px;', 'line-height:20px;min-height:30px;', $fixture), false);

if ( $failures > 0 ) {
    exit(1);
}
echo "Content-box link flex fill keeps the source border box: 2 cases passed\n";
