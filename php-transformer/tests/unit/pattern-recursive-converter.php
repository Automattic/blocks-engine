<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternConversionResult;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecursiveConverter;

$document = new DOMDocument();
$document->loadHTML('<div></div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
$element = $document->documentElement;
if ( ! $element instanceof DOMElement ) {
    throw new RuntimeException('Fixture did not produce a DOM element.');
}

$captureCalls = array();
$converter = new PatternRecursiveConverter(
    static function (DOMElement $source, bool $captureUnsupported) use (&$captureCalls): PatternConversionResult {
        $captureCalls[] = array( 'children', $captureUnsupported );
        return new PatternConversionResult(
            array( array( 'blockName' => 'core/paragraph' ) ),
            array( array( 'diagnostic_code' => 'child_fallback' ) )
        );
    },
    static function (DOMElement $source, bool $captureUnsupported) use (&$captureCalls): PatternConversionResult {
        $captureCalls[] = array( 'element', $captureUnsupported );
        return new PatternConversionResult(
            array( array( 'blockName' => 'core/group' ) ),
            array( array( 'diagnostic_code' => 'element_fallback' ) )
        );
    },
    static function (DOMElement $source, array $excludedTags) use (&$captureCalls): PatternConversionResult {
        $captureCalls[] = array( 'without_tags', $excludedTags );
        return new PatternConversionResult(
            array( array( 'blockName' => 'core/quote' ) ),
            array( array( 'diagnostic_code' => 'excluded_tags_fallback' ) )
        );
    }
);

$fallbacks = array( array( 'diagnostic_code' => 'existing_fallback' ) );
$blocks = $converter->children($element, $fallbacks, true);
if ( array( array( 'blockName' => 'core/paragraph' ) ) !== $blocks ) {
    throw new RuntimeException('Child conversion must return every converted block.');
}
if ( array( 'existing_fallback', 'child_fallback' ) !== array_column($fallbacks, 'diagnostic_code') ) {
    throw new RuntimeException('Child conversion must append fallback diagnostics in source order.');
}

$block = $converter->element($element, $fallbacks, false);
if ( 'core/group' !== ($block['blockName'] ?? null) ) {
    throw new RuntimeException('Element conversion must return its converted block.');
}
if ( array( 'existing_fallback', 'child_fallback', 'element_fallback' ) !== array_column($fallbacks, 'diagnostic_code') ) {
    throw new RuntimeException('Element conversion must append fallback diagnostics in source order.');
}
$withoutTags = $converter->childrenWithoutTags($element, $fallbacks, array( 'summary' ));
if ( 'core/quote' !== ($withoutTags[0]['blockName'] ?? null) ) {
    throw new RuntimeException('Excluded-tag conversion must return every converted block.');
}
if ( array( 'existing_fallback', 'child_fallback', 'element_fallback', 'excluded_tags_fallback' ) !== array_column($fallbacks, 'diagnostic_code') ) {
    throw new RuntimeException('Excluded-tag conversion must append fallback diagnostics in source order.');
}
if ( array( array( 'children', true ), array( 'element', false ), array( 'without_tags', array( 'summary' ) ) ) !== $captureCalls ) {
    throw new RuntimeException('Capture policy must pass through unchanged.');
}

$emptyConverter = new PatternRecursiveConverter(
    static fn (DOMElement $source, bool $captureUnsupported): PatternConversionResult => new PatternConversionResult(array()),
    static fn (DOMElement $source, bool $captureUnsupported): PatternConversionResult => new PatternConversionResult(
        array(),
        array( array( 'diagnostic_code' => 'empty_element_fallback' ) )
    ),
    static fn (DOMElement $source, array $excludedTags): PatternConversionResult => new PatternConversionResult(array())
);
$emptyFallbacks = array();
if ( null !== $emptyConverter->element($element, $emptyFallbacks, true) ) {
    throw new RuntimeException('Empty element conversion must remain nullable.');
}
if ( array( 'empty_element_fallback' ) !== array_column($emptyFallbacks, 'diagnostic_code') ) {
    throw new RuntimeException('Empty element conversion must retain its diagnostics.');
}

echo "pattern recursive converter ok\n";
