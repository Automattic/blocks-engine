<?php
declare(strict_types=1);

/**
 * Unit coverage for leftover HtmlCompilation builders hoisted onto generators (#1800).
 *
 * DescriptionListBlockGenerator, AuthoredCarouselBlockGenerator,
 * VisualIframeBlockGenerator, and CustomBlockGenerator own convert() and are
 * constructed without HtmlCompilation.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredCarouselBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\CustomBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\DescriptionListBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\VisualIframeBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\ElementPresentationResolverFixture;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\SourceBlockCreatorFixture;

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

$elementFrom = static function (string $html, string $tag = ''): DOMElement {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $element = '' === $tag
        ? $document->getElementsByTagName('body')->item(0)?->firstElementChild
        : $document->getElementsByTagName($tag)->item(0);
    if ( $element instanceof DOMElement ) {
        return $element;
    }
    throw new RuntimeException('No element parsed');
};

$assert(! is_a(DescriptionListBlockGenerator::class, 'Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlCompilation', true), 'description-list-generator-is-not-htmlcompilation');
$assert(! is_a(AuthoredCarouselBlockGenerator::class, 'Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlCompilation', true), 'carousel-generator-is-not-htmlcompilation');
$assert(! is_a(VisualIframeBlockGenerator::class, 'Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlCompilation', true), 'iframe-generator-is-not-htmlcompilation');
$assert(! is_a(CustomBlockGenerator::class, 'Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlCompilation', true), 'custom-block-generator-is-not-htmlcompilation');

$registered = array();
$descriptionList = new DescriptionListBlockGenerator(
    new SourceElementClassifier(),
    function (string $identity, array $definition) use (&$registered): void {
        $registered[$identity] = $definition;
    }
);
$block = $descriptionList->convert($elementFrom('<dl class="facts"><dt>Office</dt><dd>North Hall</dd></dl>', 'dl'));
$assert(DescriptionListBlockGenerator::NAME === ($block['blockName'] ?? null), 'description-list-convert-without-htmlcompilation');
$assert(isset($registered[DescriptionListBlockGenerator::class]), 'description-list-registers-companion-definition');
$assert('Office' === ($block['attrs']['groups'][0]['terms'][0]['content'] ?? null), 'description-list-preserves-term-content');
$assert(null === $descriptionList->convert($elementFrom('<dl><dd>Description before term</dd></dl>', 'dl')), 'description-list-rejects-malformed-list');

$unwiredCarousel = new AuthoredCarouselBlockGenerator();
$threw = false;
try {
    $unwiredCarousel->convert($elementFrom('<div class="carousel"></div>'));
} catch (LogicException $exception) {
    $threw = str_contains($exception->getMessage(), 'not wired for conversion');
}
$assert($threw, 'unwired-carousel-convert-throws');
$assert(is_array($unwiredCarousel->shell(array('ariaLabel' => 'Carousel'))), 'carousel-definition-api-survives-no-arg-constructor');

$unwiredIframe = new VisualIframeBlockGenerator();
$threw = false;
$fallbacks = array();
try {
    $unwiredIframe->convert($elementFrom('<iframe src="https://example.test/map"></iframe>', 'iframe'), $fallbacks);
} catch (LogicException $exception) {
    $threw = str_contains($exception->getMessage(), 'not wired for conversion');
}
$assert($threw, 'unwired-iframe-convert-throws');
$assert('visual-iframe' === ($unwiredIframe->definition('custom')['name'] ?? null), 'iframe-definition-api-survives-no-arg-constructor');

$unwiredCustom = new CustomBlockGenerator();
$threw = false;
$fallbacks = array();
try {
    $unwiredCustom->convert($elementFrom('<vendor-card><p>Hi</p></vendor-card>'), $fallbacks);
} catch (LogicException $exception) {
    $threw = str_contains($exception->getMessage(), 'not wired for conversion');
}
$assert($threw, 'unwired-custom-convert-throws');
$assert(CustomBlockGenerator::CATEGORY === 'widgets', 'custom-block-generator-no-arg-constructor');

$createBlock = new SourceBlockCreatorFixture(static fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => array(
    'blockName' => $name,
    'attrs' => $attrs,
    'innerBlocks' => $innerBlocks,
));
$transparent = new CustomBlockGenerator(
    new SourceElementClassifier(),
    new ElementPresentationResolverFixture(static fn (): array => array()),
    $createBlock,
    static fn (): bool => true,
    static fn (DOMElement $element): array => array( array( 'blockName' => 'core/paragraph', 'attrs' => array( 'content' => trim($element->textContent ?? '') ) ) ),
    static fn (): bool => false,
    static fn (): array => array( 'blockName' => 'custom/layout-shell' )
);
$fallbacks = array();
$lowered = $transparent->convert($elementFrom('<vendor-card><p>Hello</p></vendor-card>'), $fallbacks);
$assert('core/paragraph' === ($lowered['blockName'] ?? null), 'transparent-custom-element-flattens-single-child');
$assert(array() === $fallbacks, 'transparent-custom-element-emits-no-fallbacks');

if ( 0 !== $failures ) {
    fwrite(STDERR, $failures . " htmlcompilation generator builder test(s) failed\n");
    exit(1);
}

echo 'HtmlCompilation generator builder tests: ' . $passes . " passed\n";
