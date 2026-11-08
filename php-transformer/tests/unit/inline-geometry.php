<?php
declare(strict_types=1);

/**
 * Unit coverage for inline geometry collaborator (#1808).
 *
 * Constructed without StyleResolver `$this`. CssCascade / CssValueInspector
 * are used directly. Remaining StyleResolver operations are constructor closures.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\InlineGeometry;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\LayoutGeometryState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolutionContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$failures = 0;
$passes = 0;

$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
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

$session = new HtmlTransformerSession(new Runtime(), new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SourceElementDescription(new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier(), new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CommerceStructureRecognizer()));
$session->installLayoutGeometryState(new LayoutGeometryState());
$context = new StyleResolutionContext(
    static fn (string $value): string => $value,
    static fn (string $selector): array => array(),
    static fn (string $className): string => $className,
    static fn (string $url): string => $url
);

$parseDeclarations = static function (string $style): array {
    $declarations = array();
    foreach ( explode(';', $style) as $declaration ) {
        $separator = strpos($declaration, ':');
        if ( false === $separator ) {
            continue;
        }
        $name = strtolower(trim(substr($declaration, 0, $separator)));
        $value = trim(substr($declaration, $separator + 1));
        if ( '' !== $name && '' !== $value ) {
            $declarations[$name] = $value;
        }
    }
    return $declarations;
};

$geometry = new InlineGeometry($session,
    $context,
    $parseDeclarations,
    static fn (DOMElement $element, array $declarations): array => $declarations,
    static fn (string $style): array => array(),
    static fn (DOMElement $element, array $declarations): bool => false,
    static fn (DOMElement $element): bool => false,
    static fn (DOMElement $element, array $declarations, array $geometry, array $excluded): array => array(),
    static fn (DOMElement $element, array $declarations, array $excluded): array => array(),
    static fn (DOMElement $element, array $declarations, array $values): array => array(),
    static fn (DOMElement $element): string => 'div:1',
    static fn (DOMElement $element): array => array(),
    static fn (DOMElement $element, string $family): bool => false,
    static fn (string $property): string => $property,
    static fn (DOMElement $element): bool => false,
    static fn (DOMElement $element, array $properties): array => array( 0, 0, 0 ),
    static fn (DOMElement $element, array $properties): array => array( 0, 0, 0 )
);

$assert(! is_a(InlineGeometry::class, StyleResolver::class, true), 'inline-geometry-is-not-styleresolver');

$flex = $geometry->layoutAttribute($elementFrom('<div data-layout="flex"></div>'));
$assert(array( 'type' => 'flex' ) === $flex, 'data-layout-flex');

$column = $geometry->layoutAttribute($elementFrom('<div style="display:flex;flex-direction:column"></div>'));
$assert('flex' === ($column['type'] ?? null), 'inline-flex-layout-type');
$assert('vertical' === ($column['orientation'] ?? null), 'inline-flex-column-orientation');

$grid = $geometry->layoutAttribute($elementFrom('<div style="display:grid;grid-template-columns:1fr 1fr 1fr"></div>'));
$assert(array( 'type' => 'grid', 'columnCount' => 3 ) === $grid, 'inline-grid-track-layout');

$unstyled = $geometry->className($elementFrom('<div></div>'));
$assert('' === $unstyled, 'unstyled-element-mints-no-carrier');

$carrier = $geometry->className($elementFrom('<div style="width:240px;height:80px"></div>'));
$assert(str_starts_with($carrier, 'be-inline-geometry-'), 'sized-box-mints-carrier');
$assert(str_contains($session->layoutGeometryState()->cssForSerializedBlocks($carrier), 'width:240px'), 'carrier-rule-preserves-width');
$assert(! str_contains($session->layoutGeometryState()->cssForSerializedBlocks($carrier), 'max-width:'), 'ordinary-box-carrier-does-not-synthesize-width-cap');

$source = (string) file_get_contents((string) (new ReflectionClass(InlineGeometry::class))->getFileName());
$assert(! preg_match('/StyleResolver\s*\$/', $source), 'InlineGeometry has no StyleResolver $this parameter');
$assert(str_contains($source, 'CssCascade::'), 'InlineGeometry uses CssCascade explicitly');
$assert(str_contains($source, 'CssValueInspector::'), 'InlineGeometry uses CssValueInspector explicitly');

$resolver = new ReflectionClass(StyleResolver::class);
$method = $resolver->getMethod('inlineGeometryClassName');
$lines = array_slice(file((string) $resolver->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);
$body = implode('', $lines);
$assert(str_contains($body, 'inlineGeometry()->className('), 'StyleResolver::inlineGeometryClassName delegates to InlineGeometry');
$assert(! str_contains($body, 'allocateCarrier'), 'carrier allocation left inlineGeometryClassName');

$layoutCall = false;
foreach ( file((string) $resolver->getFileName()) as $line ) {
    if ( str_contains($line, 'inlineGeometry()->layoutAttribute(') ) {
        $layoutCall = true;
        break;
    }
}
$assert($layoutCall, 'StyleResolver presentation attributes delegate layoutAttribute');
$assert(false === $resolver->hasMethod('layoutAttribute'), 'layoutAttribute left StyleResolver');

$classFrom = static function (string $className) use ($elementFrom): DOMElement {
    return $elementFrom('<div class="' . $className . '"><p>a</p><p>b</p></div>');
};

// A class token is not evidence of `display: grid`: grid-looking names on a
// container without cascade grid evidence keep the flow/default layout
// (Automattic/blocks-engine#4302).
$gridLookingClasses = array(
    'grid grid-cols-3 gap-4',
    'grid',
    'grid-2',
    'grid-cols',
    'grid-cols-3',
    'grid-columns',
    'card-grid',
    'footer-grid',
    'mission_grid',
    'card-grid/50',
    'cards',
    'features',
);
foreach ( $gridLookingClasses as $className ) {
    $assert(array() === $geometry->layoutAttribute($classFrom($className)), 'grid-class-name-mints-no-layout:' . $className);
}
$assert(array() === $geometry->layoutAttribute($elementFrom('<div class="card-grid" style="display:block"><p>a</p><p>b</p></div>')), 'grid-class-with-block-display-stays-flow');

// Neutral names stay layout-free as well; mid-token fragments (`border-grid-line`)
// and near-miss tokens (`grid-pattern`) never were grid signals.
foreach ( array(
    'mt-16 p-8 border border-grid-line bg-white rounded-sm',
    'grid-pattern absolute inset-0 opacity-20',
    'text-grid-500 bg-slate-50',
    'pt-4 mt-4 border-t border-grid-line space-y-4',
    'flex items-center',
    'border-grid-line/50',
    'grid-area-x',
    'feature-list',
) as $className ) {
    $assert(array() === $geometry->layoutAttribute($classFrom($className)), 'non-grid-class-stays-flow:' . $className);
}

if ( $failures ) {
    fwrite(STDERR, $failures . " inline geometry test(s) failed\n");
    exit(1);
}

echo 'Inline geometry tests: ' . $passes . " passed\n";
