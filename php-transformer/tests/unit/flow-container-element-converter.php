<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FlowContainerElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FlowContainerElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\SpacerPattern;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\ElementPresentationResolverFixture;
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
    $element = $document->getElementsByTagName('body')->item(0)?->firstElementChild;
    if ( $element instanceof DOMElement ) {
        return $element;
    }
    throw new RuntimeException('No element parsed');
};

$state = (object) array( 'mode' => 'empty', 'captures' => 0 );
$false = static fn (): bool => false;
$null = static fn (): mixed => null;
$operations = array(
    'runtimeAppShellBlock' => static fn (): ?array => 'runtime' === $state->mode ? array( 'blockName' => 'core/html' ) : null,
    'isEmptyInteractiveFeatureShell' => static fn (): bool => 'interactive-empty' === $state->mode,
    'capturePseudoFormFallback' => static function () use ($state): void { ++$state->captures; },
    'recognizePatterns' => static function (DOMElement $element, array &$fallbacks, array $patterns) use ($state): ?array {
        return 'spacer' === $state->mode && array( SpacerPattern::class ) === $patterns ? array( 'blockName' => 'core/spacer' ) : null;
    },
    'flankedSeparatorBlock' => $null,
    'capturedMediaLayoutBlock' => $null,
    'sourceElementClassifier' => new SourceElementClassifier(),
    'responsiveMediaBlock' => static fn (): array => array( 'blockName' => 'responsive-media' ),
    'isDirectChildOfAuthorOwnedLayout' => $false,
    'authorLayoutBlock' => static fn (): array => array( 'blockName' => 'author-layout' ),
    'hasMultipleRuntimeInlineTextTargets' => $false,
    'paragraphBlockFromInlineContentWrapper' => $null,
    'isGeneratedComponentCandidate' => $false,
    'isAuthorOwnedLayout' => static fn (): bool => 'author-layout' === $state->mode,
    'proofBackedWrapperCoalescing' => $null,
    'shouldPreserveEmptyVisualElement' => static fn (): bool => 'visual-empty' === $state->mode,
    'emptyVisualElementAttributes' => static fn (): array => array(),
    'createBlock' => new SourceBlockCreatorFixture(static fn (string $name, array $attributes, array $innerBlocks, ?DOMElement $sourceElement): array => array(
        'blockName' => $name,
        'attrs' => $attributes,
        'innerBlocks' => $innerBlocks,
        'sourceTag' => $sourceElement?->tagName,
    )),
    'patternContext' => new PatternContext($null, new SourceBlockCreatorFixture(static fn (): array => array())),
    'shouldDeferNavigationPatternToChildren' => $false,
    'rememberAccordionDisclosureRoot' => static fn (array $block): array => $block,
    'metadataGridBlock' => $null,
    'rememberNativeDisclosureRoot' => static fn (): null => null,
    'mediaGalleryBlock' => $null,
    'namePriceRowBlock' => $null,
    'inlineTokenGroupBlock' => $null,
    'visualTextWrapperBlock' => $null,
    'standaloneSearchBlock' => $null,
    'readableFormControlBlock' => $null,
    'cssAuthoredMarqueeBlock' => static fn (): ?array => 'marquee' === $state->mode ? array( 'blockName' => 'custom/authored-marquee' ) : null,
    'authoredCarouselBlock' => $null,
    'generatedComponentBlock' => $null,
    'textFlowBlock' => $null,
    'convertChildren' => static function () use ($state): array {
        if ( 'single-child' === $state->mode ) {
            return array( array( 'blockName' => 'core/paragraph' ) );
        }
        if ( 'multiple-children' === $state->mode ) {
            return array( array( 'blockName' => 'core/heading' ), array( 'blockName' => 'core/paragraph' ) );
        }
        return array();
    },
    'backgroundImageBlock' => $null,
    'coalescedSingleGroupWrapper' => $null,
    'shouldPreserveWrapper' => $false,
    'presentationResolver' => new ElementPresentationResolverFixture(static fn (): array => array( 'className' => 'flow' )),
    'emptyVisualSpacerBlock' => static fn (): array => array( 'blockName' => 'core/spacer' ),
);
$converter = new FlowContainerElementConverter(new FlowContainerElementContext(...$operations));
$fallbacks = array();
$div = $elementFrom('<div></div>');

$assert($converter->handles('div') && $converter->handles('main') && ! $converter->handles('span'), 'handles-flow-tags-only');
$assert(! $converter->convert($elementFrom('<span></span>'), 'span', $fallbacks)->handled, 'unhandled-outcome');

$state->mode = 'runtime';
$assert('core/html' === ($converter->convert($div, 'div', $fallbacks)->block['blockName'] ?? ''), 'runtime-shell-first');
$state->mode = 'interactive-empty';
$emptyInteractive = $converter->convert($div, 'div', $fallbacks);
$assert($emptyInteractive->handled && null === $emptyInteractive->block && 0 === $state->captures, 'empty-interactive-before-form-capture');
$state->mode = 'marquee';
$assert('custom/authored-marquee' === ($converter->convert($div, 'div', $fallbacks)->block['blockName'] ?? '') && 0 === $state->captures, 'authored-marquee-before-generic-flow-conversion');

$state->mode = 'spacer';
$assert('core/spacer' === ($converter->convert($div, 'div', $fallbacks)->block['blockName'] ?? '') && 1 === $state->captures, 'spacer-after-form-capture');
$state->mode = 'author-layout';
$assert('author-layout' === ($converter->convert($div, 'div', $fallbacks)->block['blockName'] ?? ''), 'author-layout-before-generic-patterns');
$state->mode = 'single-child';
$assert('core/paragraph' === ($converter->convert($div, 'div', $fallbacks)->block['blockName'] ?? ''), 'single-child-unwrapped');
$state->mode = 'multiple-children';
$group = $converter->convert($div, 'div', $fallbacks)->block;
$assert('core/group' === ($group['blockName'] ?? '') && 2 === count($group['innerBlocks'] ?? array()), 'multiple-children-grouped');
$state->mode = 'visual-empty';
$assert('core/spacer' === ($converter->convert($div, 'div', $fallbacks)->block['blockName'] ?? ''), 'empty-visual-preserved');
$state->mode = 'empty';
$empty = $converter->convert($div, 'div', $fallbacks);
$assert($empty->handled && null === $empty->block, 'empty-flow-handled-without-block');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Flow container element converter tests: ' . $assertions . " passed\n";
