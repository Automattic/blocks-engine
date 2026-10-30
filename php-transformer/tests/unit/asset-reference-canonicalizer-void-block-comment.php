<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\AssetReferenceCanonicalizer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

if ( ! function_exists('parse_blocks') ) {
    /**
     * Minimal faithful reduction of the WordPress block grammar opener
     * (`@wordpress/block-serialization-spec-parser`, mirrored by PHP's
     * `parse_blocks()`): serialized attributes may be followed only by
     * whitespace before the closer, and a void block's closer must be the
     * exact `/-->`. A comment closing as `} / -->` fails the opener match,
     * so `parse_blocks()` degrades the markup to freeform HTML and the
     * block disappears from the parsed document.
     *
     * @return array<int, array<string, mixed>>
     */
    function parse_blocks(string $content): array
    {
        if ( preg_match('~^<!--\s*wp:(?P<namespace>[a-z][a-z0-9_-]*/)?(?P<name>[a-z][a-z0-9_-]*)(?:[ \t]+(?P<attrs>\{.*\}))?[ \t]*(?P<void>/)?-->~s', $content, $opener) ) {
            $attributes = isset($opener['attrs']) && '' !== $opener['attrs'] ? json_decode($opener['attrs'], true) : array();
            return array(
                array(
                    'blockName'    => ('' !== $opener['namespace'] ? $opener['namespace'] : 'core/') . $opener['name'],
                    'attrs'        => is_array($attributes) ? $attributes : array(),
                    'innerBlocks'  => array(),
                    'innerHTML'    => '',
                    'innerContent' => isset($opener['void']) && '' !== $opener['void'] ? array() : array(''),
                ),
            );
        }
        return array(
            array(
                'blockName'    => null,
                'attrs'        => array(),
                'innerBlocks'  => array(),
                'innerHTML'    => $content,
                'innerContent' => array($content),
            ),
        );
    }
}

$token = WordPressSitePlan::TOKEN_PREFIX . '9f2c}}';
$canonicalizer = new AssetReferenceCanonicalizer(array(array('source_path' => 'terms.pdf', 'token' => '9f2c')), '');

$voidNavigationLink = '<!-- wp:core/navigation-link {"url":"/terms.pdf","kind":"custom"} /-->';
$canonicalVoid = $canonicalizer->content($voidNavigationLink, 'wp_navigation/index.html');

$expectedVoid = '<!-- wp:core/navigation-link {"url":"' . $token . '","kind":"custom"} /-->';
if ($expectedVoid !== $canonicalVoid) {
    throw new RuntimeException('A rewritten void block must serialize with the exact `/-->` closer, got: ' . $canonicalVoid);
}

$parsed = parse_blocks($canonicalVoid);
$parsedNavigationLink = $parsed[0] ?? array();
if ('core/navigation-link' !== ($parsedNavigationLink['blockName'] ?? null)) {
    throw new RuntimeException('parse_blocks() must recover the rewritten void navigation link instead of freeform markup, got: ' . var_export($parsedNavigationLink['blockName'] ?? null, true));
}
if ($token !== ($parsedNavigationLink['attrs']['url'] ?? null)) {
    throw new RuntimeException('parse_blocks() must expose the rewritten localized URL on the recovered void block, got: ' . var_export($parsedNavigationLink['attrs']['url'] ?? null, true));
}
if ('custom' !== ($parsedNavigationLink['attrs']['kind'] ?? null) || array() !== $parsedNavigationLink['innerBlocks']) {
    throw new RuntimeException('The recovered void block must keep its remaining attributes and stay void.');
}

$balancedImageOpener = '<!-- wp:image {"src":"/terms.pdf"} -->';
$canonicalBalancedOpener = $canonicalizer->content($balancedImageOpener, 'index.html');
if ('<!-- wp:image {"src":"' . $token . '"} -->' !== $canonicalBalancedOpener) {
    throw new RuntimeException('A rewritten balanced block opener must keep the plain ` -->` closer, got: ' . $canonicalBalancedOpener);
}
$parsedImage = parse_blocks($canonicalBalancedOpener);
if ('core/image' !== ($parsedImage[0]['blockName'] ?? null) || $token !== ($parsedImage[0]['attrs']['src'] ?? null)) {
    throw new RuntimeException('parse_blocks() must keep recognizing rewritten balanced block openers with the rewritten source.');
}

fwrite(STDOUT, "Asset reference canonicalizer void block comment contract passed\n");
