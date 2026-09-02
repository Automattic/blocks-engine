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
     */
    public function __construct(
        private readonly FormControlMetadataBuilder $metadataBuilder,
        private readonly ReadableFormControlBlockConverter $controlBlockConverter,
        private readonly FormRuntimeIslandRecorder $runtimeIslandRecorder,
        private readonly Runtime $runtime,
        private readonly Closure $eventMetadata,
        private readonly Closure $isRuntimeDomTarget,
        private readonly Closure $presentationAttributes,
        private readonly SourceBlockCreator $createBlock
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

            $readableControlBlock = $this->controlBlockConverter->convert($control);
            if ( null === $readableControlBlock ) {
                continue;
            }

            $fieldBlocks = array();
            $associatedLabel = $this->metadataBuilder->associatedLabel($control);
            if ( $associatedLabel instanceof DOMElement && AuthoredInputBlockGenerator::NAME === ($readableControlBlock['blockName'] ?? '') ) {
                $labelBlock = $this->controlBlockConverter->convert($associatedLabel);
                if ( null !== $labelBlock ) {
                    $fieldBlocks[] = $labelBlock;
                }
            }
            $fieldBlocks[] = $readableControlBlock;
            $contentBlocks[] = ( 1 === count($fieldBlocks) && AuthoredInputBlockGenerator::NAME !== ($readableControlBlock['blockName'] ?? '') )
                ? $fieldBlocks[0]
                : $this->createBlock->createBlock('core/group', array(), $fieldBlocks, $control);
        }

        if ( array() !== $buttonBlocks ) {
            $contentBlocks[] = $this->createBlock->createBlock('core/buttons', array(), $buttonBlocks, $form);
        }

        if ( array() === $contentBlocks ) {
            return null;
        }

        return $this->createBlock->createBlock('core/group', ($this->presentationAttributes)($form), $contentBlocks, $form);
    }
}
