<?php
declare(strict_types=1);

/**
 * UnsupportedElementRecorder, exercised without an HtmlTransformer.
 *
 * This is the terminal core/html fallback decision (issue #497). Previously it
 * could only be observed by transforming a document containing an element that
 * nothing else in the dispatch chain claimed.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormControlMetadataBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\UnsupportedElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\UnsupportedElementRecorder;

$assertions = 0;
$failures   = array();
$assert     = static function (bool $condition, string $label, string $detail = '') use (&$assertions, &$failures): void {
    ++$assertions;
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']' . ('' !== $detail ? ': ' . $detail : '');
    }
};

$elementFrom = static function (string $html): DOMElement {
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    foreach ($doc->getElementsByTagName('body')->item(0)->childNodes as $node) {
        if ($node instanceof DOMElement) {
            return $node;
        }
    }
    throw new RuntimeException('No element parsed');
};

$makeRecorder = static function (array $overrides = array()): UnsupportedElementRecorder {
    $defaults = array(
        'maybeGenerate'   => static fn (DOMElement $e): ?array => null,
        'generatedBlock'  => static fn (array $g, DOMElement $e): array => array('blockName' => 'blocks-engine/generated', 'from' => $g),
        'selector'        => static fn (DOMElement $e): string => strtolower($e->tagName) . '.sel',
        'htmlAttributes'  => static fn (DOMElement $e): array => array('data-x' => '1'),
        'sourceContext'   => static fn (DOMElement $e): array => array('ctx' => true),
        'classify'        => static fn (DOMElement $e): array => array('kind' => 'unknown'),
        'events'          => static fn (DOMElement $e): array => array(),
        'childCount'      => static fn (DOMElement $e): int => $e->childNodes->length,
        'safeHtml'        => static fn (DOMElement $e): string => '<safe/>',
        'buildDiagnostic' => static fn (array $f): array => $f + array('built' => true),
    );
    $c = array_merge($defaults, $overrides);

    $metadataBuilder = new FormControlMetadataBuilder($c['selector']);
    return new UnsupportedElementRecorder(new UnsupportedElementContext(
        $c['maybeGenerate'],
        $c['generatedBlock'],
        $c['selector'],
        $c['htmlAttributes'],
        $c['sourceContext'],
        $c['classify'],
        $c['events'],
        $c['childCount'],
        $c['safeHtml'],
        $c['buildDiagnostic']
    ), $metadataBuilder);
};

// A high-confidence custom block short-circuits the fallback entirely: a block
// is returned and no diagnostic is recorded.
$fallbacks = array();
$generated = $makeRecorder(array('maybeGenerate' => static fn (DOMElement $e): ?array => array('name' => 'x-widget')))
    ->record($elementFrom('<x-widget></x-widget>'), 'x-widget', $fallbacks);
$assert('blocks-engine/generated' === ($generated['blockName'] ?? ''), 'generated-block-returned');
$assert(array() === $fallbacks, 'generated-block-records-no-fallback');

// Otherwise a fallback diagnostic is appended and the element drops.
$fallbacks = array();
$dropped   = $makeRecorder()->record($elementFrom('<x-thing>hello</x-thing>'), 'x-thing', $fallbacks);
$assert(null === $dropped, 'unsupported-element-drops');
$assert(1 === count($fallbacks), 'unsupported-element-records-one-fallback');

$row = $fallbacks[0];
$assert('html_unsupported_element' === ($row['diagnostic_code'] ?? ''), 'fallback-diagnostic-code');
$assert('unsupported_element' === ($row['type'] ?? ''), 'fallback-type');
$assert('unsupported_element' === ($row['reason'] ?? ''), 'fallback-reason');
$assert('html' === ($row['source_format'] ?? ''), 'fallback-source-format');
$assert('x-thing' === ($row['tag'] ?? ''), 'fallback-carries-tag');
$assert('x-thing.sel' === ($row['selector'] ?? ''), 'fallback-carries-selector');
$assert('<safe/>' === ($row['html'] ?? ''), 'fallback-carries-safe-html');
$assert(5 === ($row['text_length'] ?? 0), 'fallback-measures-text-length');
$assert(true === ($row['built'] ?? false), 'fallback-passes-through-diagnostic-builder');
$assert(! array_key_exists('control', $row), 'non-control-fallback-omits-control-key');

// Form-control metadata is attached only when the element actually has it.
$fallbacks = array();
$makeRecorder()->record($elementFrom('<input aria-label="Email">'), 'input', $fallbacks);
$assert(array('tag' => 'input', 'selector' => 'input.sel', 'type' => 'text', 'label' => 'Email') === ($fallbacks[0]['control'] ?? null), 'control-metadata-attached-when-present');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Unsupported element recorder tests: ' . $assertions . " passed\n";
