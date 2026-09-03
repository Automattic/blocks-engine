<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RuntimeResourceElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\RuntimeSelectorState;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\SourceBlockCreatorFixture;

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
    $body = $document->getElementsByTagName('body')->item(0);
    if ( $body instanceof DOMElement ) {
        foreach ( $body->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                return $child;
            }
        }
    }
    throw new RuntimeException('Fixture element not parsed');
};

$session = new HtmlTransformerSession(new Runtime(), static fn (DOMElement $element): array => array());
$selectors = new RuntimeSelectorState(
    array( '#config' => true ),
    array(),
    array( '#stage' => true )
);
$session->installRuntimeSelectorState($selectors);
$session->fallbackEmitter()->configure(array(), array(), $selectors);

$preserved = 0;
$converter = new RuntimeResourceElementConverter(
    $session,
    static function (DOMElement $element) use (&$preserved): array {
        ++$preserved;
        return array( 'blockName' => 'core/html', 'attrs' => array( 'content' => 'preserved:' . $element->tagName ) );
    },
    new SourceBlockCreatorFixture(static fn (string $name, array $attributes, array $innerBlocks, DOMElement $element): array => array(
        'blockName' => $name,
        'attrs' => $attributes,
        'innerBlocks' => $innerBlocks,
    ))
);
$fallbacks = array();

$unhandled = $converter->convert($elementFrom('<div></div>'), 'div', $fallbacks);
$assert(! $unhandled->handled, 'non-runtime-resource-unhandled');

$plainCanvas = $converter->convert($elementFrom('<canvas></canvas>'), 'canvas', $fallbacks);
$assert($plainCanvas->handled && null === $plainCanvas->block && 0 === $preserved, 'non-runtime-canvas-dropped');

$runtimeCanvas = $converter->convert($elementFrom('<canvas id="stage"></canvas>'), 'canvas', $fallbacks);
$assert('core/html' === ($runtimeCanvas->block['blockName'] ?? '') && 1 === $preserved, 'runtime-canvas-preserved');
$canvasIsland = $session->runtimeDomState()->islands()[0] ?? array();
$assert('canvas' === ($canvasIsland['kind'] ?? '') && 'canvas_requires_runtime' === ($canvasIsland['preservation_reason'] ?? ''), 'runtime-canvas-island-recorded');

$staticJson = $converter->convert(
    $elementFrom('<script type="application/json" id="config">{"enabled":true}</script>'),
    'script',
    $fallbacks
);
$assert('core/html' === ($staticJson->block['blockName'] ?? ''), 'addressable-static-json-preserved');
$assert(
    '<script id="config" type="application/json">{"enabled":true}</script>' === ($staticJson->block['attrs']['content'] ?? ''),
    'static-json-serialized-deterministically'
);
$assert(1 === count($session->runtimeBehaviorState()->scriptMetadata()), 'static-script-metadata-recorded');
$jsonIsland = $session->runtimeDomState()->islands()[1] ?? array();
$assert('static_script' === ($jsonIsland['kind'] ?? '') && 'data' === ($jsonIsland['script_role'] ?? ''), 'static-json-runtime-island-recorded');

$unaddressed = $converter->convert(
    $elementFrom('<script type="application/json" id="other">{"enabled":true}</script>'),
    'script',
    $fallbacks
);
$assert($unaddressed->handled && null === $unaddressed->block, 'unaddressed-static-json-remains-metadata-only');
$assert(2 === count($session->runtimeBehaviorState()->scriptMetadata()), 'unaddressed-static-metadata-retained');

$invalid = $converter->convert(
    $elementFrom('<script type="application/json" id="config">not-json</script>'),
    'script',
    $fallbacks
);
$assert($invalid->handled && null === $invalid->block, 'invalid-addressable-json-not-preserved');

$runtimeScript = $converter->convert($elementFrom('<script src="/app.js"></script>'), 'script', $fallbacks);
$assert($runtimeScript->handled && null === $runtimeScript->block, 'runtime-script-produces-no-content-block');
$assert('script_requires_runtime' === ($fallbacks[0]['reason'] ?? ''), 'runtime-script-fallback-captured');

$template = $converter->convert($elementFrom('<template id="card"><p>Card</p></template>'), 'template', $fallbacks);
$assert($template->handled && null === $template->block, 'template-produces-no-content-block');
$assert('template' === ($fallbacks[1]['tag'] ?? ''), 'template-fallback-captured');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Runtime resource element converter tests: ' . $assertions . " passed\n";
