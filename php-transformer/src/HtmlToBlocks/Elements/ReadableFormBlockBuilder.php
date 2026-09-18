<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredInputBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Closure;
use DOMElement;

/** Builds the readable static block representation of a form. */
final class ReadableFormBlockBuilder
{
    /**
     * @param Closure(DOMElement): array<string, mixed>                                                     $eventMetadata
     * @param Closure(DOMElement): bool                                                                     $isRuntimeDomTarget
     * @param Closure(DOMElement): array<string, mixed>                                                     $presentationAttributes
     * @param Closure(string): string                                                                       $generatedBlockName Resolves a local name through the transform's registry.
     * @param Closure(list<DOMElement>, list<array<string, mixed>>, DOMElement): array<string, mixed>       $layoutShellBlockForElements
     */
    public function __construct(
        private readonly FormControlMetadataBuilder $metadataBuilder,
        private readonly ReadableFormControlBlockConverter $controlBlockConverter,
        private readonly FormRuntimeIslandRecorder $runtimeIslandRecorder,
        private readonly Runtime $runtime,
        private readonly Closure $eventMetadata,
        private readonly Closure $isRuntimeDomTarget,
        private readonly Closure $presentationAttributes,
        private readonly SourceBlockCreator $createBlock,
        private readonly Closure $generatedBlockName,
        private readonly Closure $layoutShellBlockForElements
    ) {
    }

    /** @return array<string, mixed>|null */
    public function build(DOMElement $form, bool $allowFormEvents = false): ?array
    {
        if ( 0 < $form->getElementsByTagName('script')->length
            || ( ! $allowFormEvents && array() !== ($this->eventMetadata)($form) )
        ) {
            return null;
        }

        $buttonBlocks = array();
        $authoredInputName = ($this->generatedBlockName)(AuthoredInputBlockGenerator::LOCAL_NAME);
        foreach ( FormControlClassifier::controlElements($form) as $control ) {
            if ( array() !== ($this->eventMetadata)($control) || ! FormControlClassifier::isReadableControl($control) ) {
                return null;
            }

            if ( FormControlClassifier::isSubmitLikeControl($control) ) {
                $buttonBlocks[] = $this->createBlock->createBlock('core/button', array_merge(($this->presentationAttributes)($control), array(
                    'text' => $this->runtime->escapeHtml($this->metadataBuilder->submitText($control, 'Submit')),
                )), array(), $control);
                continue;
            }

            if ( ($this->isRuntimeDomTarget)($control) ) {
                $this->runtimeIslandRecorder->recordControl($control);
            }
        }

        $contentBlocks = $this->groupedContentBlocks($form, $authoredInputName);
        if ( array() !== $buttonBlocks ) {
            $contentBlocks[] = $this->createBlock->createBlock('core/buttons', array(), $buttonBlocks, $form);
        }

        if ( array() === $contentBlocks ) {
            return null;
        }

        // A degraded form is still a form: keep the source element so its
        // controls stay grouped for assistive technology and for a provider
        // that binds a handler to it later.
        $attributes = ($this->presentationAttributes)($form);
        if ( 'form' === strtolower($form->tagName) ) {
            $attributes['tagName'] = 'form';
        }

        return $this->createBlock->createBlock('core/group', $attributes, $contentBlocks, $form);
    }

    /**
     * Walk the source grouping tree so a shared wrapper around two or more
     * converted controls stays a layout-shell, instead of flattening every
     * control into a single list.
     *
     * @return array<int, array<string, mixed>>
     */
    private function groupedContentBlocks(DOMElement $container, string $authoredInputName): array
    {
        $blocks = array();
        foreach ( $container->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            if ( FormControlClassifier::isControlElement($child) ) {
                $block = $this->convertDataEntryControl($child, $authoredInputName);
                if ( null !== $block ) {
                    $blocks[] = $block;
                }
                continue;
            }

            $inner = $this->groupedContentBlocks($child, $authoredInputName);
            if ( array() === $inner ) {
                continue;
            }
            if ( 2 <= count($inner) ) {
                $blocks[] = ($this->layoutShellBlockForElements)(array( $child ), $inner, $child);
                continue;
            }

            array_push($blocks, ...$inner);
        }

        return $blocks;
    }

    /** @return array<string, mixed>|null */
    private function convertDataEntryControl(DOMElement $control, string $authoredInputName): ?array
    {
        if ( FormControlClassifier::isSubmitLikeControl($control) ) {
            return null;
        }

        // The control's own label is a source fact the degraded form must
        // keep, so it rides on the control block as a real `<label>` rather
        // than being dropped with the rest of the replaced subtree.
        $readableControlBlock = $this->controlBlockConverter->convert($control, $this->metadataBuilder->labelElement($control));
        if ( null === $readableControlBlock ) {
            return null;
        }

        return $authoredInputName === ($readableControlBlock['blockName'] ?? '')
            ? $this->createBlock->createBlock('core/group', array(), array( $readableControlBlock ), $control)
            : $readableControlBlock;
    }
}
