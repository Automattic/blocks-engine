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
 * caption's rules now target a generated figure carrier and its caption,
 * keeping presentation without classes that invalidate core save (#4508).
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
$assert(str_contains($markup, '<figcaption class="wp-element-caption">'), 'the rendered caption has exactly the core save class', $markup);
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

// The exact reduced page from #4508: both width-only images must agree
// with WordPress 7.1.4 block-library image save, not a PHP round-trip.
$issuePage = '<!doctype html><html><head><meta charset="utf-8"><title>Figure</title><style>'
    . '.mt-sm{margin-top:1rem}.text-center{text-align:center}.text-sm{font-size:.875rem}.font-bold{font-weight:700}'
    . '.w-full{width:100%}.h-auto{height:auto}.object-cover{object-fit:cover}'
    . '</style></head><body><main>'
    . '<figure><img alt="A photo" width="800" height="600" class="w-full object-cover" src="photo.jpg" style="aspect-ratio: 4 / 3"><figcaption class="mt-sm text-center text-sm font-bold">Weddings</figcaption></figure>'
    . '<div><img alt="Banner" class="w-full h-auto object-cover" src="photo.jpg"></div>'
    . '</main></body></html>';
$issueResult = ( new HtmlTransformer() )->transform($issuePage, array())->toArray();
$issueMarkup = (string) ($issueResult['serialized_blocks'] ?? '');
$assert(2 === preg_match_all('/<!-- wp:image /', $issueMarkup), 'the issue reduction produces two native images', $issueMarkup);
$assert(str_contains($issueMarkup, '<figcaption class="wp-element-caption">Weddings</figcaption>'), 'the issue caption matches core save exactly', $issueMarkup);
$assert(2 === substr_count($issueMarkup, 'object-fit:cover;width:100%;height:auto'), 'both issue images emit core save width-only styles', $issueMarkup);
$assert(! str_contains($issueMarkup, 'captionClassName'), 'the issue reduction never serializes captionClassName', $issueMarkup);
$issueDocument = new DOMDocument();
$issueDocument->loadHTML('<?xml encoding="utf-8" ?>' . $issueMarkup, LIBXML_NOERROR | LIBXML_NOWARNING);
$issueCaption = $issueDocument->getElementsByTagName('figcaption')->item(0);
$reached = array();
foreach ( $issueResult['assets'] ?? array() as $asset ) {
    if ( 'css' !== ($asset['kind'] ?? '') || ! $issueCaption instanceof DOMElement ) {
        continue;
    }
    (new CssStylesheetTransformer())->visitStyleRules((string) ($asset['content'] ?? ''), static function (string $prelude, string $body) use ($issueCaption, &$reached): void {
        foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
            if ( CssSelectorMatcher::matches($issueCaption, CssSelectorMatcher::parse($selector))['matches'] ) {
                $reached[] = str_replace(' ', '', $body);
            }
        }
    });
}
foreach ( array('margin-top:1rem', 'text-align:center', 'font-size:.875rem', 'font-weight:700') as $declaration ) {
    $assert(str_contains(implode(';', $reached), $declaration), 'the issue caption retains ' . $declaration, implode(';', $reached));
}

if ( 0 < $failures ) {
    fwrite(STDERR, "image figcaption author styling FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "image figcaption author styling passed: {$passes} assertions\n";
