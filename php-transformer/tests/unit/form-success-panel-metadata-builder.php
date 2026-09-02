<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormSuccessPanelMetadataBuilder;

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
    foreach ( $document->getElementsByTagName('body')->item(0)->childNodes as $node ) {
        if ( $node instanceof DOMElement ) {
            return $node;
        }
    }
    throw new RuntimeException('No element parsed');
};

$selector = static function (DOMElement $element): string {
    $id = $element->getAttribute('id');
    return '' !== $id ? '#' . $id : strtolower($element->tagName);
};
$fallbackHtmlMetadata = static function (DOMElement $element): array {
    $html = trim($element->ownerDocument->saveHTML($element) ?: '');
    return array(
        'html'      => $html,
        'bytes'     => strlen($html),
        'truncated' => false,
    );
};
$innerHtml = static function (DOMElement $element): string {
    $html = '';
    foreach ( $element->childNodes as $child ) {
        $html .= $element->ownerDocument->saveHTML($child);
    }
    return trim($html);
};
$builder = new FormSuccessPanelMetadataBuilder($selector, $fallbackHtmlMetadata, $innerHtml);

$form = $elementFrom('<form></form>   <div id="sent" class="notice success" role="status" aria-live="polite"> Thanks <strong>Chris</strong> &amp; team </div>');
$metadata = $builder->build($form);
$assert('#sent' === ($metadata['selector'] ?? ''), 'real-form-finds-adjacent-panel-after-whitespace');
$assert('notice success' === ($metadata['class'] ?? ''), 'panel-attributes-are-retained');
$assert('Thanks Chris & team' === ($metadata['text'] ?? ''), 'nested-confirmation-text-is-normalized');
$assert(false === ($metadata['html_truncated'] ?? null), 'bounded-html-state-is-retained');
$assert(($metadata['html_bytes'] ?? 0) === strlen((string) ($metadata['html'] ?? '')), 'bounded-html-byte-count-is-retained');

$blocked = $elementFrom('<form></form><p>Other content</p><div role="status">Sent</div>');
$assert(array() === $builder->build($blocked), 'unrelated-adjacent-element-blocks-panel-discovery');

$commentBlocked = $elementFrom('<form></form><!-- boundary --><div role="status">Sent</div>');
$assert(array() === $builder->build($commentBlocked), 'non-element-adjacent-node-blocks-panel-discovery');

$pseudoForm = $elementFrom('<div id="signup"><label>Email<input></label><div><p id="confirmation-message">Submitted</p></div></div>');
$assert('#confirmation-message' === ($builder->build($pseudoForm)['selector'] ?? ''), 'pseudo-form-finds-descendant-token-panel');

$firstPanel = $elementFrom('<div><p role="alert" id="first">Try again</p><p role="status" id="second">Sent</p></div>');
$assert('#first' === ($builder->build($firstPanel)['selector'] ?? ''), 'pseudo-form-uses-first-signaled-descendant');

$ariaToken = $elementFrom('<div><output aria-live="confirmed">Complete</output></div>');
$assert('output' === ($builder->build($ariaToken)['selector'] ?? ''), 'confirmation-token-in-aria-live-is-a-signal');

$partialToken = $elementFrom('<div><p class="unsuccessful">No match</p></div>');
$assert(array() === $builder->build($partialToken), 'partial-success-token-is-not-a-signal');

$boundedBuilder = new FormSuccessPanelMetadataBuilder(
    $selector,
    static fn (DOMElement $element): array => array( 'html' => '<p>...', 'bytes' => 2500, 'truncated' => true ),
    $innerHtml
);
$truncated = $boundedBuilder->build($elementFrom('<div><p role="status">Sent</p></div>'));
$assert(true === ($truncated['html_truncated'] ?? false) && 2500 === ($truncated['html_bytes'] ?? 0), 'truncated-html-metadata-is-propagated');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Form success panel metadata builder tests: ' . $assertions . " passed\n";
