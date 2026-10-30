<?php
declare(strict_types=1);

/**
 * A link that wraps an image is converted to core/image with native link
 * attributes: the source anchor is rebuilt as
 * `<figure><a href … class=linkClass><img></a></figure>` from those attributes
 * alone, and its inline style does not survive. When that inline style
 * declared custom properties the author stylesheet reads — a header logo
 * sized through `width:var(--logo-w)` on the anchor — the surviving readers
 * resolved to nothing and the image collapsed to nothing.
 *
 * The anchor's consumed inline custom properties must be restated in the
 * anchor's own cascade scope: a generated carrier class riding `linkClass`,
 * the same mechanism the inline geometry carrier already uses for converted
 * wrappers. Ordinary inline presentational declarations keep their current
 * handling (dropped with the anchor's style attribute, not newly carried).
 *
 * The fixture is the reduced page from the report.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures): void {
    if ( $condition ) {
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' === $detail ? '' : ' - ' . $detail ) . PHP_EOL);
};

$document = (string) file_get_contents(dirname(__DIR__) . '/fixtures/linked-image-inline-custom-properties.html');
$authored = '';
if ( 1 !== preg_match('/<style>(.*?)<\/style>/s', $document, $styleMatch) ) {
    fwrite(STDERR, 'FAIL: the fixture carries its authored stylesheet in one <style> block' . PHP_EOL);
    exit(1);
}
$authored = (string) $styleMatch[1];

/** @return array{blocks: string, css: string} */
$transformed = static function (string $page): array {
    $out = ( new HtmlTransformer() )->transform($page)->toArray();
    $css = '';
    foreach ( $out['assets'] ?? array() as $asset ) {
        if ( is_array($asset) && 'css' === ( $asset['kind'] ?? '' ) ) {
            $css .= (string) ( $asset['content'] ?? '' );
        }
    }

    return array( 'blocks' => (string) ( $out['serialized_blocks'] ?? '' ), 'css' => $css );
};

/** The `<a>` tag the image block rebuilt from the source anchor, if any. */
$anchorTag = static function (string $blocks): string {
    return 1 === preg_match('/<a href="[^"]*" class="[^"]*\blogo\b[^"]*"/', $blocks, $match) ? $match[0] : '';
};

/** The generated carrier class an element tag carries, if any. */
$carrierClass = static function (string $tag): string {
    return 1 === preg_match('/(be-inline-geometry-[a-f0-9]{16})/', $tag, $match) ? $match[1] : '';
};

/** Every generated rule scoped to one carrier class. */
$carrierRule = static function (string $css, string $className): string {
    $rule = '';
    foreach ( preg_split('/(?<=})/', $css) ?: array() as $candidate ) {
        if ( str_contains($candidate, '.' . $className . '{') ) {
            $rule .= $candidate;
        }
    }

    return $rule;
};

$out = $transformed($document);

$tag = $anchorTag($out['blocks']);
$assert('' !== $tag, 'the source anchor survives as the image block\'s rebuilt link', $out['blocks']);

$carrier = $carrierClass($tag);
$assert('' !== $carrier, 'the rebuilt anchor keeps a generated custom-property carrier class', $tag);

$rule = $carrierRule($out['css'], $carrier);
$assert('' !== $rule && str_contains($rule, '--logo-w:165px') && str_contains($rule, '--logo-h:54px'), 'the carrier rule defines both custom properties in the anchor\'s scope', $rule);
$assert(str_contains($out['css'], '.logo{width:var(--logo-w);height:var(--logo-h)'), 'the author rule reading the properties still matches the anchor', $out['css']);

// The compiled-artifact path runs the same conversion over real files.
$linked = str_replace('<style>' . $authored . '</style>', '<link rel="stylesheet" href="assets/site.css">', $document);
$compiled = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'index.html',
    'files'      => array(
        'index.html'      => $linked,
        'assets/site.css' => $authored,
    ),
))->toArray();
$compiledCss = '';
foreach ( $compiled['assets'] ?? array() as $asset ) {
    if ( is_array($asset) && 'css' === ( $asset['kind'] ?? '' ) ) {
        $compiledCss .= (string) ( $asset['content'] ?? '' );
    }
}
$compiledBlocks = (string) ( $compiled['serialized_blocks'] ?? '' );
$compiledCarrier = $carrierClass($anchorTag($compiledBlocks));
$assert('' !== $compiledCarrier && str_contains($compiledCss, '.' . $compiledCarrier . '{'), 'artifact compilation restates the anchor\'s custom properties too', $compiledBlocks);
$assert(str_contains($carrierRule($compiledCss, $compiledCarrier), '--logo-w:165px'), 'the compiled carrier rule defines the anchor\'s custom properties', $compiledCss);

// An ordinary inline declaration on the anchor keeps its current handling:
// only the custom properties the surviving readers consume are restated.
$mixed = $transformed((string) preg_replace(
    '/(<a class="logo" href="\/" style="[^"]*)"/',
    '$1;background:#123456"',
    $document
));
$mixedRule = $carrierRule($mixed['css'], $carrierClass($anchorTag($mixed['blocks'])));
$assert('' !== $mixedRule && ! str_contains($mixedRule, 'background'), 'an ordinary inline declaration is not newly carried onto the anchor', $mixedRule);

// Custom properties nothing reads are left uncarried, like today.
$unread = $transformed((string) preg_replace(
    '/\.logo\{[^}]*\}/',
    '.logo{display:flex}',
    $document,
    1
));
$assert('' === $carrierClass($anchorTag($unread['blocks'])), 'custom properties no rule reads are not carried', $unread['blocks'] . "\n" . $unread['css']);

if ( 0 !== $failures ) {
    exit(1);
}

echo "Linked-image inline custom properties contract passed.\n";
