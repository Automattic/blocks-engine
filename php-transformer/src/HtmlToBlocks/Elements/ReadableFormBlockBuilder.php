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
        private readonly Closure $generatedBlockName
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

        $contentBlocks = array();
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

            // The control's own label is a source fact the degraded form must
            // keep, so it rides on the control block as a real `<label>` rather
            // than being dropped with the rest of the replaced subtree.
            $readableControlBlock = $this->controlBlockConverter->convert($control, $this->metadataBuilder->labelElement($control));
            if ( null === $readableControlBlock ) {
                continue;
            }

            $contentBlocks[] = $authoredInputName === ($readableControlBlock['blockName'] ?? '')
                ? $this->createBlock->createBlock('core/group', array(), array( $readableControlBlock ), $control)
                : $readableControlBlock;
        }

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
}
