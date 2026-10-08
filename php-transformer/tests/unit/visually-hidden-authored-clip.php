<?php
declare(strict_types=1);

/**
 * A class that clips its box to 1px is screen-reader text. Projection rewrites
 * that class onto a marker or inline-layout carrier and drops the authored
 * selector. Preserved markup keeps the class, not the marker, so the label
 * paints. The authored selector must still hide it.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message) use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

$clip = '.price-label{clip:rect(0,0,0,0);border:0;height:1px;margin:-1px;overflow:hidden;padding:0;position:absolute;width:1px}';
$clipPath = '.price-label{position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%);margin:-1px;padding:0;border:0}';

$preserved = static function (string $css, string $item): array {
    $html = '<style>' . $css . '</style><main><product-list>' . $item . $item . '</product-list></main>';
    $result = ( new HtmlTransformer() )->transform($html)->toArray();
    $generated = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( 'css' === ($asset['kind'] ?? '') ) {
            $generated .= (string) ($asset['content'] ?? '');
        }
    }
    $content = '';
    $walk = static function (array $blocks) use (&$walk, &$content): void {
        foreach ( $blocks as $block ) {
            if ( ! is_array($block) ) {
                continue;
            }
            $fragment = $block['attrs']['content'] ?? '';
            if ( is_string($fragment) && str_contains($fragment, 'price-label') ) {
                $content .= $fragment;
            }
            $walk($block['innerBlocks'] ?? array());
        }
    };
    $walk($result['blocks'] ?? array());

    return array( $content, $generated );
};

$clipped = static function (string $markup, string $css, string $className): bool {
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?><div>' . $markup . '</div>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $nodes = ( new DOMXPath($dom) )->query('//*[contains(concat(" ", normalize-space(@class), " "), " ' . $className . ' ")]');
    if ( ! $nodes instanceof DOMNodeList || 0 === $nodes->length ) {
        return false;
    }
    $cascade = new StaticCssCascade($dom, $css);
    foreach ( $nodes as $node ) {
        if ( ! $node instanceof DOMElement ) {
            return false;
        }
        $resolved = $cascade->resolve($node, array( 'clip', 'clip-path', 'overflow', 'position', 'width', 'height', 'display' ), array());
        if ( 'none' === strtolower(trim((string) ($resolved['display'] ?? ''))) || ! CssValueInspector::isVisuallyClippedBox($resolved) ) {
            return false;
        }
    }

    return true;
};

[ $linkMarkup, $linkCss ] = $preserved(
    $clip,
    '<p><a href="/item-1">Item 1 <span class="price-label">Price</span> $12.00</a></p>'
);
$assert(str_contains($linkMarkup, 'Price') && str_contains($linkMarkup, 'price-label'), 'the screen-reader label stays in the preserved paragraph link');
$assert($clipped($linkMarkup, $linkCss, 'price-label'), 'a clip/1px class inside a preserved paragraph link stays visually hidden');

[ $bareLinkMarkup, $bareLinkCss ] = $preserved(
    $clip,
    '<a href="/item-1">Item 1 <span class="price-label">Price</span> $12.00</a>'
);
$assert(str_contains($bareLinkMarkup, '>Price</span>'), 'the screen-reader label stays in the preserved link');
$assert($clipped($bareLinkMarkup, $bareLinkCss, 'price-label'), 'a clip/1px class inside a preserved link stays visually hidden');

[ $cardMarkup, $cardCss ] = $preserved(
    $clip . '.prices{display:flex;gap:4px}',
    '<div class="card"><a href="/item-1"><div class="prices"><span class="price-label">Price</span><span class="amount">$12.00</span></div></a></div>'
);
$assert(str_contains($cardMarkup, '>Price</span>') && str_contains($cardMarkup, '$12.00'), 'the price row keeps both the label and the amount');
$assert($clipped($cardMarkup, $cardCss, 'price-label'), 'a carrier-projected clip class still hides the preserved label');
$assert(! $clipped($cardMarkup, $cardCss, 'amount'), 'the visible amount next to the label is not clipped');

[ $insetMarkup, $insetCss ] = $preserved(
    $clipPath,
    '<a href="/item-1">Item 1 <span class="price-label">Price</span> $12.00</a>'
);
$assert(str_contains($insetMarkup, 'Price') && $clipped($insetMarkup, $insetCss, 'price-label'), 'a clip-path inset pattern is the same visually-hidden class rule');

if ( $failures > 0 ) {
    fwrite(STDERR, "visually hidden authored clip FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "visually hidden authored clip passed: {$passes} assertions\n";
