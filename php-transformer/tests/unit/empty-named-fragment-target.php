<?php
declare(strict_types=1);

/**
 * An empty href-less named <a> is a fragment target, not an unsupported
 * element. Its identifier must survive as a Gutenberg HTML anchor so
 * same-page hash links keep a destination.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$failures = 0;
$passes   = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$transformer = new HtmlTransformer();
$validity = new BlockValidityValidator();

$named = $transformer->transform(
    '<main><p>Body</p><a name="comments" id="comments"></a><h2>Leave a Reply.</h2><p><a href="#comments">0 Comments</a></p></main>'
)->toArray();
$namedMarkup = (string) ( $named['serialized_blocks'] ?? '' );
$namedFallbacks = $named['fallbacks'] ?? array();
$assert(array() === $namedFallbacks, '1: empty named fragment target records no fallback', json_encode($namedFallbacks));
$assert(! str_contains($namedMarkup, '<!-- wp:html'), '2: empty named fragment target does not emit core/html', $namedMarkup);
$assert(str_contains($namedMarkup, 'id="comments"'), '3: fragment id survives in saved markup', $namedMarkup);
$assert(str_contains($namedMarkup, '"anchor":"comments"'), '4: fragment id is the block HTML anchor attribute', $namedMarkup);
$assert(
    'pass' === ( $validity->validateBlocks($named['blocks'] ?? array())['status'] ?? '' ),
    '5: named fragment target remains Gutenberg-valid',
    json_encode($validity->validateBlocks($named['blocks'] ?? array()))
);
$assert(str_contains($namedMarkup, 'href="#comments"'), '6: same-page hash link is preserved', $namedMarkup);
$assert(str_contains($namedMarkup, 'Leave a Reply.'), '7: following sibling still converts', $namedMarkup);

$nameOnly = $transformer->transform(
    '<main><a name="section"></a><h2>Section</h2></main>'
)->toArray();
$nameOnlyMarkup = (string) ( $nameOnly['serialized_blocks'] ?? '' );
$assert(array() === ( $nameOnly['fallbacks'] ?? array() ), '8: name-only fragment target records no fallback', json_encode($nameOnly['fallbacks'] ?? array()));
$assert(str_contains($nameOnlyMarkup, 'id="section"'), '9: name without id maps to the same fragment identifier', $nameOnlyMarkup);
$assert(! str_contains($nameOnlyMarkup, '<!-- wp:html'), '10: name-only fragment target does not emit core/html', $nameOnlyMarkup);

$collision = $transformer->transform(
    '<main><div id="comments"><p>Existing</p></div><a name="comments" id="comments"></a></main>'
)->toArray();
$collisionMarkup = (string) ( $collision['serialized_blocks'] ?? '' );
$assert(array() === ( $collision['fallbacks'] ?? array() ), '11: colliding empty named target records no fallback', json_encode($collision['fallbacks'] ?? array()));
$assert(1 === preg_match_all('/id="comments"/', $collisionMarkup), '12: colliding fragment id is not duplicated', $collisionMarkup);
$assert(str_contains($collisionMarkup, 'Existing'), '13: the existing id owner still converts', $collisionMarkup);

$hrefEmpty = $transformer->transform(
    '<main><a href="/x"></a><p>After</p></main>'
)->toArray();
$assert(
    'html_unsupported_element' === ( $hrefEmpty['fallbacks'][0]['diagnostic_code'] ?? null ),
    '14: an empty destination-bearing anchor remains an unsupported element',
    json_encode($hrefEmpty['fallbacks'] ?? array())
);

if ( $failures > 0 ) {
    fwrite(STDERR, PHP_EOL . "empty named fragment target tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "empty named fragment target tests: {$passes} passed" . PHP_EOL);
