<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};

// One table exercises the reported parser recovery and the xml:space value
// contract, both on the SVG root and on its descendants.
$cases = array(
    'issue fixture' => array('xml:space="preserve x=" 0"=""', '', null),
    'invalid descendant' => array('', 'xml:space="preserve x=" 0"=""', null),
    'empty' => array('xml:space=""', '', null),
    'unknown' => array('', 'xml:space="invalid"', null),
    'case sensitive' => array('xml:space="Preserve"', '', null),
    'not trimmed' => array('xml:space=" preserve "', '', null),
    'default' => array('xml:space="default"', '', 'default'),
    'preserve descendant' => array('', 'xml:space="preserve"', 'preserve'),
);
foreach ( $cases as $label => [$rootAttributes, $pathAttributes, $expectedSpace] ) {
    $html = '<svg xmlns="http://www.w3.org/2000/svg" ' . $rootAttributes . ' viewBox="0 0 10 10"><path' . ($pathAttributes === '' ? '' : ' ' . $pathAttributes) . ' d="M0 0h10v10z"/></svg>';
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $json = (string) json_encode($result['blocks'], JSON_UNESCAPED_SLASHES);
    $assert(str_contains($json, 'core/image'), "{$label}: core/image");
    $assert(! str_contains($json, 'core/html'), "{$label}: no core/html");
    $assets = array_values(array_filter($result['assets'] ?? array(), static fn (array $asset): bool => 'inline-svg' === ($asset['source'] ?? '')));
    $assert(count($assets) === 1, "{$label}: one SVG asset");
    foreach ( $assets as $asset ) {
        $content = (string) ($asset['content'] ?? '');
        $document = new DOMDocument();
        $assert(@$document->loadXML($content, LIBXML_NONET), "{$label}: well-formed SVG");
        $assert(str_contains($content, 'viewBox="0 0 10 10"') && str_contains($content, 'd="M0 0h10v10z"'), "{$label}: geometry preserved");
        $assert($expectedSpace === null ? ! str_contains($content, 'xml:space=') : str_contains($content, 'xml:space="' . $expectedSpace . '"'), "{$label}: xml:space contract");
    }
}

// SimpleXML can construct names that older HTML parsers silently discard.
// Exercise XML Name (not just ASCII identifiers) without parser dependence.
$document = new DOMDocument();
$document->loadXML('<root><child/></root>');
foreach ( array($document->documentElement, $document->documentElement->firstElementChild) as $element ) {
    $names = array('0"', '1name', '@event', 'has space', '0', 'data-label', '_name', 'étiquette', 'a·b', 'a:b');
    foreach ( $names as $name ) {
        if ( 'a:b' === $name ) {
            $element->setAttribute($name, 'kept');
        } else {
            simplexml_import_dom($element)->addAttribute($name, 'kept');
        }
    }
}
SourceDom::removeIntegerKeyAttributes($document->documentElement);
foreach ( array($document->documentElement, $document->documentElement->firstElementChild) as $element ) {
    $names = array_map(static fn (DOMAttr $attribute): string => $attribute->nodeName, iterator_to_array($element->attributes, false));
    $assert(array('data-label', '_name', 'étiquette', 'a·b', 'a:b') === $names, 'cleanup removes invalid XML names and preserves valid names throughout the subtree');
}

if ( $failures > 0 ) {
    exit(1);
}
fwrite(STDOUT, "malformed-svg-attributes: passed\n");
