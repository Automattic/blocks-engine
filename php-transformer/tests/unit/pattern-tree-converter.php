<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlCompilation;

$compilation = new HtmlCompilation();
$compilation->transform('<p>Prepare conversion state.</p>');

$document = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$document->loadHTML('<div><summary>Skip</summary><p>Keep</p><object data="/first.pdf"></object></div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
libxml_clear_errors();
libxml_use_internal_errors($previous);
$container = $document->documentElement;
if ( ! $container instanceof DOMElement ) {
    throw new RuntimeException('Fixture did not produce a DOM element.');
}

$fallbacks = array( array( 'diagnostic_code' => 'existing_fallback' ) );
$blocks = $compilation->children($container, $fallbacks, false);
if ( array( 'core/paragraph' ) !== array_column($blocks, 'blockName') ) {
    throw new RuntimeException('Child conversion must return every converted block.');
}
if ( array( 'existing_fallback' ) !== array_column($fallbacks, 'diagnostic_code') ) {
    throw new RuntimeException('Disabled unsupported capture must preserve existing diagnostics.');
}

$blocks = $compilation->children($container, $fallbacks, true);
if ( array( 'core/paragraph' ) !== array_column($blocks, 'blockName') ) {
    throw new RuntimeException('Captured child conversion must preserve converted blocks.');
}
if ( array( 'existing_fallback', 'html_unsupported_element', 'html_unsupported_element' ) !== array_column($fallbacks, 'diagnostic_code') ) {
    throw new RuntimeException('Child conversion must append fallback diagnostics in source order.');
}
if ( ! str_contains((string) ($fallbacks[1]['html'] ?? ''), '<summary>') || ! str_contains((string) ($fallbacks[2]['html'] ?? ''), '/first.pdf') ) {
    throw new RuntimeException('Child fallback order must match source order.');
}

$excludedFallbacks = array();
$blocks = $compilation->childrenWithoutTags($container, $excludedFallbacks, array( 'summary' ));
if ( array( 'core/paragraph' ) !== array_column($blocks, 'blockName') || 1 !== count($excludedFallbacks) || ! str_contains((string) ($excludedFallbacks[0]['html'] ?? ''), '/first.pdf') ) {
    throw new RuntimeException('Excluded-tag conversion must skip excluded children and retain other diagnostics.');
}

$object = $container->getElementsByTagName('object')->item(0);
if ( ! $object instanceof DOMElement ) {
    throw new RuntimeException('Fixture did not produce an object element.');
}
$elementFallbacks = array();
if ( null !== $compilation->element($object, $elementFallbacks, true) ) {
    throw new RuntimeException('Unsupported element conversion must remain nullable.');
}
if ( array( 'html_unsupported_element' ) !== array_column($elementFallbacks, 'diagnostic_code') ) {
    throw new RuntimeException('Nullable element conversion must retain its diagnostics.');
}

echo "pattern tree converter ok\n";
