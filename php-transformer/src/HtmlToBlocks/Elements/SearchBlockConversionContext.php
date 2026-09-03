<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\GeneratedSupportStylesheetState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ElementPresentationResolver;
use Closure;
use DOMElement;

/** Explicit transformer-owned surface consumed by {@see SearchBlockConverter}. */
final class SearchBlockConversionContext
{
    /**
     * @param Closure(string): string                                                                $restoreSvgCasing
     * @param Closure(DOMElement): bool                                                              $isRuntimeDomTarget
     * @param Closure(DOMElement): array<string, mixed>                                              $htmlPreservationBlock
     */
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly ElementPresentationResolver $presentationResolver,
        private readonly SourceBlockCreator $createBlock,
        private readonly Closure $restoreSvgCasing,
        private readonly Closure $isRuntimeDomTarget,
        private readonly Closure $htmlPreservationBlock
    ) {
    }

    public function hasSearchFormSignal(DOMElement $form, DOMElement $input): bool
    {
        if ( 'search' === FormControlClassifier::controlType($input) || 'search' === strtolower(trim(SourceDom::attr($form, 'role'))) ) {
            return true;
        }

        $queryName = strtolower(trim(SourceDom::attr($input, 'name')));
        if ( in_array($queryName, array( 's', 'q', 'query', 'search' ), true) ) {
            return true;
        }

        $haystack = strtolower(implode(' ', array(
            SourceDom::attr($form, 'action'),
            SourceDom::attr($form, 'aria-label'),
            SourceDom::attr($form, 'id'),
            SourceDom::attr($form, 'class'),
        )));

        return str_contains($haystack, 'search');
    }

    /** @return array<string, mixed> */
    public function presentationAttributes(DOMElement $element): array
    {
        return $this->presentationResolver->presentationAttributes($element);
    }

    /** @return array<string, string> */
    public function presentationDeclarations(DOMElement $element): array
    {
        return $this->presentationResolver->presentationDeclarations($element);
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return array<string, mixed>
     */
    public function createBlock(string $name, array $attributes = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array
    {
        return $this->createBlock->createBlock($name, $attributes, $innerBlocks, $sourceElement);
    }

    public function restoreSvgCasing(string $html): string
    {
        return ($this->restoreSvgCasing)($html);
    }

    public function generatedSupportStyles(): GeneratedSupportStylesheetState
    {
        return $this->session->generatedSupportStylesheetState();
    }

    public function isRuntimeDomTarget(DOMElement $element): bool
    {
        return ($this->isRuntimeDomTarget)($element);
    }

    /** @return array<string, mixed> */
    public function htmlPreservationBlock(DOMElement $element): array
    {
        return ($this->htmlPreservationBlock)($element);
    }
}
