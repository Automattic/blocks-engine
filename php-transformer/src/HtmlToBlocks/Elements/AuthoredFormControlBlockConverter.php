<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredInputBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredSelectBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Closure;
use DOMElement;

/** Converts native input and select controls into editable block representations. */
final class AuthoredFormControlBlockConverter
{
    /**
     * @param Closure(DOMElement): array<string, mixed>                                                     $structuralPresentationDeclarations
     * @param Closure(DOMElement): array<string, mixed>                                                     $presentationAttributes
     * @param Closure(class-string, array<string, mixed>): void                                             $registerGeneratedBlock
     * @param Closure(string): void                                                                         $registerEcho
     * @param Closure(string): string                                                                       $safeAnchor
     */
    public function __construct(
        private readonly FormControlMetadataBuilder $metadataBuilder,
        private readonly Closure $structuralPresentationDeclarations,
        private readonly Closure $presentationAttributes,
        private readonly SourceBlockCreator $createBlock,
        private readonly Closure $registerGeneratedBlock,
        private readonly Closure $registerEcho,
        private readonly Runtime $runtime,
        private readonly Closure $safeAnchor
    ) {
    }

    /** @return array<string, mixed>|null */
    public function select(DOMElement $select): ?array
    {
        $label = $this->metadataBuilder->readableLabel($select);
        ($this->registerEcho)($label);
        $options = $this->metadataBuilder->options($select);
        if ( array() === $options ) {
            return null;
        }

        // Class/id presence alone does not justify a generated native block;
        // require authored presentation proven by the resolved cascade.
        if ( array() === ($this->structuralPresentationDeclarations)($select) ) {
            $optionBlocks = array();
            foreach ( $options as $option ) {
                $optionLabel = trim((string) ($option['label'] ?? ''));
                if ( '' === $optionLabel ) {
                    continue;
                }
                if ( true === ($option['selected'] ?? false) ) {
                    $optionLabel .= ' (selected)';
                }
                ($this->registerEcho)($optionLabel);
                $optionBlocks[] = $this->createBlock->createBlock('core/list-item', array( 'content' => $this->runtime->escapeHtml($optionLabel) ));
            }

            return $this->createBlock->createBlock('core/group', ($this->presentationAttributes)($select), array(
                $this->createBlock->createBlock('core/paragraph', array( 'content' => $this->runtime->escapeHtml($label) ), array(), $select),
                $this->createBlock->createBlock('core/list', array(), $optionBlocks, $select),
            ), $select);
        }

        $generator = new AuthoredSelectBlockGenerator();
        ($this->registerGeneratedBlock)(AuthoredSelectBlockGenerator::class, $generator->definition());
        $attrs = array_filter(array(
            'id' => SourceDom::attr($select, 'id'),
            'name' => SourceDom::attr($select, 'name'),
            'ariaLabel' => SourceDom::attr($select, 'aria-label'),
            'placeholder' => SourceDom::attr($select, 'placeholder'),
            'className' => SourceDom::attr($select, 'class'),
            'style' => SourceDom::attr($select, 'style'),
            'options' => $options,
            'selectedSummary' => $this->selectedOptionSummary($options),
        ), static fn (mixed $value): bool => is_array($value) ? array() !== $value : '' !== $value);
        $markup = $generator->markup($attrs);
        $controlBlock = array(
            'blockName' => AuthoredSelectBlockGenerator::NAME,
            'attrs' => $attrs,
            'innerBlocks' => array(),
            'innerHTML' => $markup,
            'innerContent' => array( $markup ),
        );

        // Preserve the established structural address while source identity and
        // authored selectors remain on the native control inside this shell.
        return $this->createBlock->createBlock('core/group', array_filter(array(
            'anchor' => ($this->safeAnchor)(SourceDom::attr($select, 'id')),
            'className' => 'blocks-engine-authored-select-wrapper',
        )), array( $controlBlock ), null);
    }

    /**
     * Return a compact native input only when the resolved cascade proves
     * authored presentation that a readable paragraph cannot retain.
     *
     * @return array<string, mixed>|null
     */
    public function input(DOMElement $input): ?array
    {
        if ( array() === ($this->structuralPresentationDeclarations)($input) ) {
            return null;
        }

        $generator = new AuthoredInputBlockGenerator();
        ($this->registerGeneratedBlock)(AuthoredInputBlockGenerator::class, $generator->definition());
        $attrs = array_filter(array(
            'type' => FormControlClassifier::controlType($input),
            'id' => SourceDom::attr($input, 'id'),
            'name' => SourceDom::attr($input, 'name'),
            'value' => SourceDom::attr($input, 'value'),
            'placeholder' => SourceDom::attr($input, 'placeholder'),
            'ariaLabel' => SourceDom::attr($input, 'aria-label'),
            'className' => SourceDom::attr($input, 'class'),
            'style' => SourceDom::attr($input, 'style'),
            'min' => SourceDom::attr($input, 'min'),
            'max' => SourceDom::attr($input, 'max'),
            'step' => SourceDom::attr($input, 'step'),
            'required' => $input->hasAttribute('required'),
            'disabled' => $input->hasAttribute('disabled'),
            'readOnly' => $input->hasAttribute('readonly'),
            'checked' => $input->hasAttribute('checked'),
        ), static fn (mixed $value): bool => is_bool($value) ? $value : '' !== $value);
        $markup = $generator->markup($attrs);

        return array(
            'blockName' => AuthoredInputBlockGenerator::NAME,
            'attrs' => $attrs,
            'innerBlocks' => array(),
            'innerHTML' => $markup,
            'innerContent' => array( $markup ),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $options
     */
    private function selectedOptionSummary(array $options): string
    {
        $selected = array();
        foreach ( $options as $option ) {
            if ( ! empty($option['selected']) && '' !== trim((string) ($option['label'] ?? '')) ) {
                $selected[] = (string) $option['label'];
            }
        }

        return array() === $selected ? '' : implode(', ', $selected) . ' (selected)';
    }
}
