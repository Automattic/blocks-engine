<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\MediaDispatchElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\MediaDispatchElementConverter;

$assertions = 0;
$failures = array();
$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    ++$assertions;
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']';
    }
};

$elementFrom = static function (string $html): DOMElement {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $element = $document->getElementsByTagName('body')->item(0)?->firstElementChild;
    if ( $element instanceof DOMElement ) {
        return $element;
    }
    throw new RuntimeException('No element parsed');
};

$context = new MediaDispatchElementContext(
    static fn (DOMElement $element, array &$fallbacks): ?array => $element->hasAttribute('data-placeholder') ? array( 'blockName' => 'core/group' ) : null,
    static fn (DOMElement $element): ?array => $element->hasAttribute('data-drop') ? null : array( 'blockName' => 'core/image' ),
    static fn (DOMElement $element): ?array => array( 'blockName' => 'core/picture' ),
    static function (DOMElement $element, array &$fallbacks): ?array {
        $fallbacks[] = array( 'iframe' => true );
        return array( 'blockName' => 'core/embed' );
    },
    static fn (DOMElement $element): ?array => array( 'blockName' => 'core/' . strtolower($element->tagName) ),
    static fn (DOMElement $element): ?array => $element->getElementsByTagName('img')->length > 0 ? array( 'blockName' => 'core/image', 'linked' => true ) : null
);
$converter = new MediaDispatchElementConverter($context);
$fallbacks = array();

$assert($converter->handles('img') && $converter->handles('a') && ! $converter->handles('div'), 'handles-native-media-and-anchors');
$placeholder = $converter->convert($elementFrom('<div data-placeholder="true"></div>'), 'div', $fallbacks);
$assert($placeholder->handled && 'core/group' === ($placeholder->block['blockName'] ?? ''), 'placeholder-precedes-tag-routing');
$assert(! $converter->convert($elementFrom('<div></div>'), 'div', $fallbacks)->handled, 'ordinary-div-unhandled');

$assert('core/image' === ($converter->convert($elementFrom('<img src="x">'), 'img', $fallbacks)->block['blockName'] ?? ''), 'image-routed');
$droppedImage = $converter->convert($elementFrom('<img data-drop="true">'), 'img', $fallbacks);
$assert($droppedImage->handled && null === $droppedImage->block, 'handled-image-may-emit-no-block');
$assert('core/picture' === ($converter->convert($elementFrom('<picture></picture>'), 'picture', $fallbacks)->block['blockName'] ?? ''), 'picture-routed');

$fallbacks = array();
$assert('core/embed' === ($converter->convert($elementFrom('<iframe></iframe>'), 'iframe', $fallbacks)->block['blockName'] ?? '') && 1 === count($fallbacks), 'iframe-routed-with-fallbacks');
$assert('core/audio' === ($converter->convert($elementFrom('<audio></audio>'), 'audio', $fallbacks)->block['blockName'] ?? ''), 'audio-routed');
$assert('core/video' === ($converter->convert($elementFrom('<video></video>'), 'video', $fallbacks)->block['blockName'] ?? ''), 'video-routed');
$assert(true === ($converter->convert($elementFrom('<a><img src="x"></a>'), 'a', $fallbacks)->block['linked'] ?? false), 'linked-image-anchor-routed');
$assert(! $converter->convert($elementFrom('<a>Text</a>'), 'a', $fallbacks)->handled, 'ordinary-anchor-unhandled');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Media dispatch element converter tests: ' . $assertions . " passed\n";
