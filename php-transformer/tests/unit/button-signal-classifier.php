<?php
declare(strict_types=1);

/**
 * Unit coverage for the shared button signal classifier used by HTML transform
 * button promotion and visual parity probes.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ButtonSignalClassifier;

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

$element = static function (string $html): DOMElement {
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $body = $document->getElementsByTagName('body')->item(0);
    if ( ! $body instanceof DOMElement || ! $body->firstElementChild instanceof DOMElement ) {
        throw new RuntimeException('Unable to parse fixture element.');
    }

    return $body->firstElementChild;
};

$classifier = new ButtonSignalClassifier();

$assert($classifier->hasClassSignal($element('<a class="hero-btn" href="#">Learn more</a>')), '1: class signal detects btn substring');
$assert($classifier->hasClassSignal($element('<a id="actionButton" href="#">Learn more</a>')), '2: id signal detects button substring');
$assert($classifier->hasTransformSignal($element('<a role="button" href="#">Learn more</a>')), '3: role=button is a transform signal');
$assert($classifier->hasTransformSignal($element('<a class="cta" href="#">Learn more</a>')), '4: cta token is a transform signal');
$assert($classifier->hasTransformSignal($element('<a class="primary-action" href="#">Learn more</a>')), '5: primary-action phrase is a transform signal');
$assert($classifier->hasTransformSignal($element('<a href="#">Buy now</a>')), '6: exact action text is a transform signal');
$assert($classifier->hasStyleSignal($element('<a style="padding:12px 18px;background:#135e96" href="#">Learn more</a>')), '7: padding plus filled background is a style signal');
$assert($classifier->hasStyleSignal($element('<a style="padding:12px 18px;border-radius:999px" href="#">Learn more</a>')), '8: padding plus radius is a style signal');
$assert(! $classifier->hasStyleSignal($element('<a style="padding:12px 18px;background:transparent" href="#">Learn more</a>')), '9: transparent background alone is not a style signal');
$assert(! $classifier->hasTransformSignal($element('<a href="#">Learn more</a>')), '10: plain link has no transform signal');

$result = ( new HtmlTransformer() )->transform('<a style="padding:12px 18px;background:#135e96;color:#fff" href="/buy">Buy tickets</a>', array())->toArray();
$button = $result['blocks'][0]['innerBlocks'][0] ?? array();
$assert('core/buttons' === ($result['blocks'][0]['blockName'] ?? ''), '11: styled anchor is promoted to core/buttons', json_encode($result['blocks'] ?? array()));
$assert('core/button' === ($button['blockName'] ?? ''), '12: styled anchor inner block is core/button', json_encode($button));
$assert('/buy' === ($button['attrs']['url'] ?? ''), '13: styled anchor promotion preserves URL', json_encode($button['attrs'] ?? array()));

$buttonResult = ( new HtmlTransformer() )->transform('<button style="padding:12px 18px;background:#135e96;color:#fff">Buy tickets</button>', array())->toArray();
$nativeButton = $buttonResult['blocks'][0]['innerBlocks'][0] ?? array();
$assert('core/buttons' === ($buttonResult['blocks'][0]['blockName'] ?? ''), '14: native button is dispatched to core/buttons', json_encode($buttonResult['blocks'] ?? array()));
$assert('core/button' === ($nativeButton['blockName'] ?? ''), '15: native button inner block is core/button', json_encode($nativeButton));
$assert('button' === ($nativeButton['attrs']['tagName'] ?? ''), '16: native button keeps button tagName', json_encode($nativeButton['attrs'] ?? array()));

$plainLinkResult = ( new HtmlTransformer() )->transform('<a href="/about">About us</a>', array())->toArray();
$plainLink = $plainLinkResult['blocks'][0] ?? array();
$assert('core/paragraph' === ($plainLink['blockName'] ?? ''), '17: plain anchor stays paragraph rich text', json_encode($plainLinkResult['blocks'] ?? array()));
$assert(str_contains((string) ($plainLink['innerHTML'] ?? ''), 'href="/about"'), '18: plain anchor preserves href in content', json_encode($plainLink ?? array()));

if ( $failures > 0 ) {
    fwrite(STDERR, "ButtonSignalClassifier unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "ButtonSignalClassifier unit tests: {$passes} passed\n");
