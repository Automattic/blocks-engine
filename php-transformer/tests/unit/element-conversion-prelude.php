<?php
declare(strict_types=1);

/**
 * Unit coverage for convertElement prelude collaborators (#1779).
 *
 * NativeGetFormBlockBuilder owns form-depth; NativeGetFormControlConverter
 * is constructed without HtmlCompilation.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\AuthoredFormControlBlockConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormControlMetadataBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\NativeGetFormBlockBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\NativeGetFormControlConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolutionContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformerAnalysisCache;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\SourceBlockCreatorFixture;
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

$runtime = new Runtime();
$session = new HtmlTransformerSession($runtime, static fn (DOMElement $element): array => array());
$createBlock = new SourceBlockCreatorFixture(static fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => array(
    'blockName' => $name,
    'attrs' => $attrs,
    'innerBlocks' => $innerBlocks,
));
$builder = null;
$sawInside = false;
$builder = new NativeGetFormBlockBuilder(
    static function (DOMElement $form, array &$fallbacks) use (&$builder, &$sawInside): array {
        $sawInside = $builder instanceof NativeGetFormBlockBuilder && $builder->isInside();
        return array( array( 'blockName' => 'core/paragraph' ) );
    },
    static fn (DOMElement $element): array => array(),
    $createBlock,
    static function (string $identity, array $definition): void {}
);
$assert(! $builder->isInside(), 'native-get-form-depth-starts-idle');

$form = $elementFrom('<form action="/search" method="get"><input name="q"><button type="submit">Go</button></form>');
$fallbacks = array();
$built = $builder->build($form, $fallbacks);
$assert($sawInside, 'native-get-form-depth-is-active-while-converting-children');
$assert(! $builder->isInside(), 'native-get-form-depth-restores-after-build');
$assert(null !== $built, 'safe-native-get-form-builds');

$metadataBuilder = new FormControlMetadataBuilder(static fn (DOMElement $element): string => strtolower($element->tagName));
$authored = new AuthoredFormControlBlockConverter(
    $metadataBuilder,
    static fn (DOMElement $element): array => array(),
    static fn (DOMElement $element): array => array(),
    $createBlock,
    static function (string $identity, array $definition): void {},
    static function (string $text): void {},
    $runtime,
    static fn (string $id): string => $id
);
$styleResolver = new StyleResolver(
    new StyleResolutionContext(
        $session,
        static fn (DOMElement $element): int => 0,
        static fn (string $value): string => $value,
        static fn (string $selector): array => array(),
        static fn (string $className): string => $className,
        static fn (string $url): string => $url,
        static fn (DOMElement $element): bool => false
    ),
    new HtmlTransformerAnalysisCache()
);
$converter = new NativeGetFormControlConverter($builder, $authored, $metadataBuilder, $styleResolver, $createBlock);
$outside = $converter->convert($elementFrom('<input name="q">'), 'input', $fallbacks);
$assert(! $outside->handled, 'form-control-converter-unhandled-outside-native-get-form');

if ( 0 !== $failures ) {
    fwrite(STDERR, $failures . " element conversion prelude test(s) failed\n");
    exit(1);
}

echo 'Element conversion prelude tests: ' . $passes . " passed\n";
