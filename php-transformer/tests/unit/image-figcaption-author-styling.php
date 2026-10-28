<?php
declare(strict_types=1);

/**
 * A source <figcaption> keeps its author box on the core/image caption (#3622).
 *
 * Plain-PHP test script — no PHPUnit. core/image consumes the figure's
 * <figcaption> as its `caption` attribute, and core's save() writes the
 * caption <figcaption> itself, so the source caption element disappeared and
 * its classes had nowhere to land: the rendered caption was a bare
 * `<figcaption class="wp-element-caption">` and the author rule that drew its
 * padding, background, border and font size matched nothing. The source
 * caption's classes now ride the rendered figcaption, so those rules keep
 * applying while the caption stays the image block's editable caption.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
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

$reduction = '<!doctype html><html><head><meta charset="utf-8"><title>Figcaption</title><style>'
    . 'body{margin:0;font-family:sans-serif;font-size:16px}'
    . 'figure{margin:40px;max-width:300px;border:1px solid #ddd;display:flex;flex-direction:column}'
    . 'figure img{width:100%;display:block}'
    . '.cap{border-top:1px solid #ddd;background:#f8f5f1;padding:12px 16px;font-size:14px}'
    . '.cap strong{font-weight:600}.cap span{display:block;color:#666}'
    . '</style></head><body><main>'
    . '<figure><img src="https://picsum.photos/id/64/600/700" width="600" height="700" alt="Portrait"><figcaption class="cap"><strong>Jane Doe</strong><span>Structural engineer.</span></figcaption></figure>'
    . '</main></body></html>';

$result = ( new HtmlTransformer() )->transform($reduction, array())->toArray();
$markup = (string) ( $result['serialized_blocks'] ?? '' );
$assert('success' === ($result['status'] ?? null), 'the reduction transforms');

$css = '';
foreach ( $result['assets'] ?? array() as $asset ) {
    if ( is_array($asset) && 'css' === ($asset['kind'] ?? null) ) {
        $css .= "\n" . (string) ( $asset['content'] ?? '' );
    }
}

$assert(1 === preg_match_all('/<!-- wp:image /', $markup), 'the figure lowers to one core/image block', $markup);
$assert(str_contains($markup, '<figcaption class="wp-element-caption cap">'), 'the rendered caption carries the source caption class', $markup);
$assert(str_contains($markup, '<strong>Jane Doe</strong>') && str_contains($markup, 'Structural engineer.'), 'the caption content stays the image caption rich text', $markup);
$assert(! str_contains($markup, 'captionClassName'), 'the internal caption-class channel never reaches the serialized comment', $markup);

$document = new DOMDocument();
$document->loadHTML('<?xml encoding="utf-8" ?>' . $markup, LIBXML_NOERROR | LIBXML_NOWARNING);
$caption = $document->getElementsByTagName('figcaption')->item(0);
$assert($caption instanceof DOMElement, 'the converted markup keeps a caption figcaption', $markup);

if ( $caption instanceof DOMElement ) {
    // The authored rule that drew the caption's box keeps every declaration
    // and reaches the converted caption element.
    $declarations = array( 'border-top:1pxsolid#ddd', 'background:#f8f5f1', 'padding:12px16px', 'font-size:14px' );
    $captionBoxSelectors = array();
    (new CssStylesheetTransformer())->visitStyleRules($css, static function (string $prelude, string $body) use (&$captionBoxSelectors, $declarations): void {
        $compact = str_replace(' ', '', $body);
        foreach ( $declarations as $declaration ) {
            if ( ! str_contains($compact, $declaration) ) {
                return;
            }
        }
        foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array( trim($prelude) ) as $selector ) {
            $captionBoxSelectors[] = $selector;
        }
    });
    $assert(array() !== $captionBoxSelectors, 'the caption box rule survives with padding, font size, background and border', $css);

    $captionReached = false;
    foreach ( $captionBoxSelectors as $selector ) {
        if ( CssSelectorMatcher::matches($caption, CssSelectorMatcher::parse($selector))['matches'] ) {
            $captionReached = true;
        }
    }
    $assert($captionReached, 'a selector carrying the caption padding, font size, background and border matches the converted caption', json_encode($captionBoxSelectors));

    // The caption's own typography rule reaches its rich text content too.
    $strong = null;
    foreach ( $caption->childNodes as $child ) {
        if ( $child instanceof DOMElement && 'strong' === strtolower($child->tagName) ) {
            $strong = $child;
        }
    }
    $assert($strong instanceof DOMElement, 'the caption keeps its inline content', $markup);
    if ( $strong instanceof DOMElement ) {
        $strongReached = false;
        (new CssStylesheetTransformer())->visitStyleRules($css, static function (string $prelude, string $body) use (&$strongReached, $strong): void {
            if ( ! str_contains(str_replace(' ', '', $body), 'font-weight:600') ) {
                return;
            }
            foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array( trim($prelude) ) as $selector ) {
                if ( CssSelectorMatcher::matches($strong, CssSelectorMatcher::parse($selector))['matches'] ) {
                    $strongReached = true;
                }
            }
        });
        $assert($strongReached, 'the caption content keeps the author typography rule', $css);
    }
}

if ( 0 < $failures ) {
    fwrite(STDERR, "image figcaption author styling FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "image figcaption author styling passed: {$passes} assertions\n";
