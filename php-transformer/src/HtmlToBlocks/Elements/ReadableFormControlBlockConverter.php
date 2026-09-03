<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Closure;
use DOMElement;

/** Converts labels and native controls into readable editable blocks. */
final class ReadableFormControlBlockConverter
{
    /**
     * @param Closure(DOMElement): array<string, mixed>                                                     $eventMetadata
     * @param Closure(DOMElement): bool                                                                     $isRuntimeDomTarget
     * @param Closure(DOMElement): array<string, mixed>                                                     $htmlPreservationBlock
     * @param Closure(DOMElement): array<string, mixed>                                                     $presentationAttributes
     * @param Closure(string): void                                                                         $registerEcho
     */
    public function __construct(
        private readonly FormControlMetadataBuilder $metadataBuilder,
        private readonly AuthoredFormControlBlockConverter $authoredBlockConverter,
        private readonly FormRuntimeIslandRecorder $runtimeIslandRecorder,
        private readonly Runtime $runtime,
        private readonly Closure $eventMetadata,
        private readonly Closure $isRuntimeDomTarget,
        private readonly Closure $htmlPreservationBlock,
        private readonly Closure $presentationAttributes,
        private readonly SourceBlockCreator $createBlock,
        private readonly Closure $registerEcho
    ) {
    }

    /** @return array<string, mixed>|null */
    public function convert(DOMElement $element): ?array
    {
        $tagName = strtolower($element->tagName);
        if ( 'label' === $tagName ) {
            return $this->convertLabel($element);
        }

        if ( ! FormControlClassifier::isControlElement($element)
            || ! FormControlClassifier::isReadableControl($element)
            || array() !== ($this->eventMetadata)($element)
        ) {
            return null;
        }

        if ( 'input' === $tagName && 'search' === FormControlClassifier::controlType($element) ) {
            $label = $this->metadataBuilder->label($element);
            if ( '' === $label ) {
                $label = SourceDom::attr($element, 'aria-label');
            }
            if ( '' === $label ) {
                $label = 'Search';
            }

            return ($this->htmlPreservationBlock)($element);
        }

        if ( ($this->isRuntimeDomTarget)($element) ) {
            $this->runtimeIslandRecorder->recordControl($element);
            return ($this->htmlPreservationBlock)($element);
        }

        if ( 'select' === $tagName ) {
            $selectBlock = $this->authoredBlockConverter->select($element);
            if ( null !== $selectBlock ) {
                return $selectBlock;
            }
        }

        if ( 'input' === $tagName ) {
            $inputBlock = $this->authoredBlockConverter->input($element);
            if ( null !== $inputBlock ) {
                return $inputBlock;
            }
        }

        $summary = $this->controlText($element);
        if ( '' === $summary ) {
            return null;
        }

        return $this->createBlock->createBlock('core/paragraph', array_merge(($this->presentationAttributes)($element), array( 'content' => $summary )), array(), $element);
    }

    /** @return array<string, mixed>|null */
    private function convertLabel(DOMElement $element): ?array
    {
        $controls = FormControlClassifier::controlElements($element);
        if ( array() !== $controls ) {
            $blocks = array();
            foreach ( $controls as $control ) {
                if ( ! FormControlClassifier::isReadableControl($control) || array() !== ($this->eventMetadata)($control) ) {
                    return null;
                }

                if ( ($this->isRuntimeDomTarget)($control) ) {
                    $this->runtimeIslandRecorder->recordControl($control);
                    return ($this->htmlPreservationBlock)($element);
                }

                $summary = $this->controlText($control);
                if ( '' !== $summary ) {
                    $blocks[] = $this->createBlock->createBlock('core/paragraph', array( 'content' => $summary ), array(), $control);
                }
            }

            if ( 1 === count($blocks) ) {
                return $blocks[0];
            }

            return array() !== $blocks
                ? $this->createBlock->createBlock('core/group', ($this->presentationAttributes)($element), $blocks, $element)
                : null;
        }

        $label = $this->metadataBuilder->labelText($element);
        if ( '' === $label ) {
            $label = trim(preg_replace('/\s+/', ' ', $element->textContent ?? '') ?? '');
        }

        return '' !== $label
            ? $this->createBlock->createBlock('core/paragraph', array( 'content' => $this->runtime->escapeHtml($label) ), array(), $element)
            : null;
    }

    private function controlText(DOMElement $control): string
    {
        $label = $this->metadataBuilder->readableLabel($control);
        $type = FormControlClassifier::controlType($control);
        if ( '' === $label ) {
            $label = 'select' === $type ? 'Select option' : ucfirst($type);
        }

        $details = array();
        if ( 'select' === strtolower($control->tagName) ) {
            $options = array();
            $selected = array();
            foreach ( $this->metadataBuilder->options($control) as $option ) {
                $optionLabel = (string) ($option['label'] ?? '');
                if ( '' === $optionLabel ) {
                    continue;
                }
                $options[] = $optionLabel;
                if ( true === ($option['selected'] ?? false) ) {
                    $selected[] = $optionLabel;
                }
            }
            if ( array() !== $options ) {
                $details[] = implode(', ', $options);
            }
            if ( array() !== $selected ) {
                $details[] = 'selected: ' . implode(', ', $selected);
            }
        } elseif ( 'range' === $type ) {
            $value = trim(SourceDom::attr($control, 'value'));
            if ( '' !== $value ) {
                $details[] = $value;
            }

            $bounds = array();
            foreach ( array( 'min', 'max', 'step' ) as $attribute ) {
                $value = trim(SourceDom::attr($control, $attribute));
                if ( '' !== $value ) {
                    $bounds[] = $attribute . ' ' . $value;
                }
            }
            if ( array() !== $bounds ) {
                $details[] = implode(', ', $bounds);
            }
        } else {
            foreach ( array( 'value', 'placeholder' ) as $attribute ) {
                $value = trim(SourceDom::attr($control, $attribute));
                if ( '' !== $value ) {
                    $details[] = $value;
                    break;
                }
            }
        }

        $text = $label;
        if ( array() !== $details ) {
            $text .= ': ' . implode(' (', $details) . ( count($details) > 1 ? ')' : '' );
        }
        if ( $control->hasAttribute('required') ) {
            $text .= ' (required)';
        }

        ($this->registerEcho)($text);
        return $this->runtime->escapeHtml($text);
    }
}
