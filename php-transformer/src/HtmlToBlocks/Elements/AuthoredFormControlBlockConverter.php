<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\GeneratedBlockRegistry;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredButtonBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredInputBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredSelectBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredTextareaBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Closure;
use DOMElement;

/** Converts native input, select, textarea, and button controls into editable block representations. */
final class AuthoredFormControlBlockConverter
{
    /**
     * @param Closure(DOMElement): array<string, mixed>                                                     $structuralPresentationDeclarations
     * @param Closure(DOMElement): array<string, mixed>                                                     $presentationAttributes
     * @param Closure(): GeneratedBlockRegistry                                                             $generatedBlocks
     * @param Closure(string): void                                                                         $registerEcho
     * @param Closure(string): string                                                                       $safeAnchor
     */
    public function __construct(
        private readonly FormControlMetadataBuilder $metadataBuilder,
        private readonly Closure $structuralPresentationDeclarations,
        private readonly Closure $presentationAttributes,
        private readonly SourceBlockCreator $createBlock,
        private readonly Closure $generatedBlocks,
        private readonly Closure $registerEcho,
        private readonly Runtime $runtime,
        private readonly Closure $safeAnchor
    ) {
    }

    /** @return array<string, mixed>|null */
    public function select(DOMElement $select, bool $forceNative = false, ?DOMElement $labelElement = null): ?array
    {
        $label = $this->metadataBuilder->readableLabel($select);
        ($this->registerEcho)($label);
        $options = $this->metadataBuilder->options($select);
        if ( array() === $options ) {
            return null;
        }

        // Class/id presence alone does not justify a generated native block;
        // require authored presentation proven by the resolved cascade.
        if ( ! $forceNative && array() === ($this->structuralPresentationDeclarations)($select) ) {
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
        $registry = ($this->generatedBlocks)();
        $registry->register(AuthoredSelectBlockGenerator::class, $generator->definition($registry->namespace()));
        $attrs = array_filter(array(
            'id' => SourceDom::attr($select, 'id'),
            'name' => SourceDom::attr($select, 'name'),
            'ariaLabel' => SourceDom::attr($select, 'aria-label'),
            'placeholder' => SourceDom::attr($select, 'placeholder'),
            'className' => SourceDom::attr($select, 'class'),
            'style' => SourceDom::attr($select, 'style'),
            'options' => $options,
            'selectedSummary' => $this->selectedOptionSummary($options),
            'label' => $labelElement instanceof DOMElement ? $this->metadataBuilder->labelText($labelElement) : '',
            'labelClassName' => $labelElement instanceof DOMElement ? SourceDom::attr($labelElement, 'class') : '',
            'labelStyle' => $labelElement instanceof DOMElement ? SourceDom::attr($labelElement, 'style') : '',
            'required' => $select->hasAttribute('required'),
            'disabled' => $select->hasAttribute('disabled'),
        ), static fn (mixed $value): bool => is_array($value) ? array() !== $value : '' !== $value);
        $markup = $generator->markup($attrs);
        $controlBlock = array(
            'blockName' => $registry->blockName(AuthoredSelectBlockGenerator::LOCAL_NAME),
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

    public function listbox(DOMElement $trigger, ?DOMElement $panel = null): ?array
    {
        if (!$panel instanceof DOMElement) {
            $key = trim(SourceDom::attr($trigger, 'data-dla-listbox-trigger'));
            $document = $trigger->ownerDocument;
            if ('' === $key || !$document instanceof \DOMDocument) {
                return null;
            }
            foreach ($document->getElementsByTagName('*') as $candidate) {
                if ($candidate instanceof DOMElement
                    && $candidate->getAttribute('data-dla-listbox-panel') === $key
                    && 'listbox' === strtolower(trim($candidate->getAttribute('role')))) {
                    $panel = $candidate;
                    break;
                }
            }
        }
        if (!$panel instanceof DOMElement) {
            return null;
        }
        $triggerLabel = trim(preg_replace('/\s+/', ' ', $trigger->textContent ?? '') ?? '');
        $options = array();
        foreach ($panel->getElementsByTagName('*') as $option) {
            if (!$option instanceof DOMElement || 'option' !== strtolower($option->getAttribute('role'))) {
                continue;
            }
            $label = trim(preg_replace('/\s+/', ' ', $option->textContent ?? '') ?? '');
            if ('' === $label) {
                continue;
            }
            $value = $option->getAttribute('data-value');
            $value = '' === $value ? $label : $value;
            $options[] = array(
                'label' => $label,
                'value' => $value,
                'selected' => 'true' === strtolower(trim($option->getAttribute('aria-selected'))) || $label === $triggerLabel,
                'disabled' => 'true' === strtolower(trim($option->getAttribute('aria-disabled'))),
            );
        }
        if (array() === $options) {
            return null;
        }
        $selected = array_values(array_filter($options, static fn (array $option): bool => !empty($option['selected'])));
        $selectedOption = $selected[0] ?? $options[0];
        $generator = new AuthoredSelectBlockGenerator();
        $registry = ($this->generatedBlocks)();
        $registry->register(AuthoredSelectBlockGenerator::class, $generator->definition($registry->namespace()));
        $attrs = array_filter(array(
            'id' => SourceDom::attr($trigger, 'id'),
            'name' => SourceDom::attr($trigger, 'name'),
            'ariaLabel' => SourceDom::attr($trigger, 'aria-label'),
            'className' => SourceDom::attr($trigger, 'class'),
            'style' => SourceDom::attr($trigger, 'style'),
            'options' => $options,
            'selectedValue' => (string) ($selectedOption['value'] ?? ''),
            'selectedLabel' => (string) ($selectedOption['label'] ?? ''),
            'placeholder' => $triggerLabel,
        ), static fn (mixed $value): bool => is_array($value) ? array() !== $value : '' !== $value);
        $markup = $generator->markup($attrs);
        $control = array('blockName' => $registry->blockName(AuthoredSelectBlockGenerator::LOCAL_NAME), 'attrs' => $attrs, 'innerBlocks' => array(), 'innerHTML' => $markup, 'innerContent' => array($markup));
        return $this->createBlock->createBlock('core/group', array('className' => 'blocks-engine-authored-select-wrapper'), array($control), null);
    }

    /**
     * Return a compact native input only when the resolved cascade proves
     * authored presentation that a readable paragraph cannot retain.
     *
     * @return array<string, mixed>|null
     */
    public function input(DOMElement $input, ?DOMElement $label = null, bool $preserveDataAttributes = false, bool $forceNative = false): ?array
    {
        if ( ! $forceNative && array() === ($this->structuralPresentationDeclarations)($input) ) {
            return null;
        }

        $generator = new AuthoredInputBlockGenerator();
        $registry = ($this->generatedBlocks)();
        $registry->register(AuthoredInputBlockGenerator::class, $generator->definition($registry->namespace()));
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
            'dataAttributes' => $preserveDataAttributes ? $this->dataAttributes($input) : array(),
            'label' => $label instanceof DOMElement ? $this->metadataBuilder->labelText($label) : '',
            'labelClassName' => $label instanceof DOMElement ? SourceDom::attr($label, 'class') : '',
            'labelStyle' => $label instanceof DOMElement ? SourceDom::attr($label, 'style') : '',
        ), static fn (mixed $value): bool => is_array($value) ? array() !== $value : (is_bool($value) ? $value : '' !== $value));
        $markup = $generator->markup($attrs);

        return array(
            'blockName' => $registry->blockName(AuthoredInputBlockGenerator::LOCAL_NAME),
            'attrs' => $attrs,
            'innerBlocks' => array(),
            'innerHTML' => $markup,
            'innerContent' => array( $markup ),
        );
    }

    /**
     * Return a compact native textarea on the same terms as `input()`: a
     * multiline control the source styles has no readable prose equivalent,
     * because a paragraph keeps neither the entry field nor its authored height.
     *
     * @return array<string, mixed>|null
     */
    public function textarea(DOMElement $textarea, ?DOMElement $label = null, bool $forceNative = false): ?array
    {
        if ( ! $forceNative && array() === ($this->structuralPresentationDeclarations)($textarea) ) {
            return null;
        }

        $generator = new AuthoredTextareaBlockGenerator();
        $registry = ($this->generatedBlocks)();
        $registry->register(AuthoredTextareaBlockGenerator::class, $generator->definition($registry->namespace()));
        $attrs = array_filter(array(
            'id' => SourceDom::attr($textarea, 'id'),
            'name' => SourceDom::attr($textarea, 'name'),
            'value' => $textarea->textContent ?? '',
            'placeholder' => SourceDom::attr($textarea, 'placeholder'),
            'ariaLabel' => SourceDom::attr($textarea, 'aria-label'),
            'className' => SourceDom::attr($textarea, 'class'),
            'style' => SourceDom::attr($textarea, 'style'),
            'rows' => SourceDom::attr($textarea, 'rows'),
            'cols' => SourceDom::attr($textarea, 'cols'),
            'maxLength' => SourceDom::attr($textarea, 'maxlength'),
            'required' => $textarea->hasAttribute('required'),
            'disabled' => $textarea->hasAttribute('disabled'),
            'readOnly' => $textarea->hasAttribute('readonly'),
            'label' => $label instanceof DOMElement ? $this->metadataBuilder->labelText($label) : '',
            'labelClassName' => $label instanceof DOMElement ? SourceDom::attr($label, 'class') : '',
            'labelStyle' => $label instanceof DOMElement ? SourceDom::attr($label, 'style') : '',
        ), static fn (mixed $value): bool => is_bool($value) ? $value : '' !== $value);
        $markup = $generator->markup($attrs);

        return array(
            'blockName' => $registry->blockName(AuthoredTextareaBlockGenerator::LOCAL_NAME),
            'attrs' => $attrs,
            'innerBlocks' => array(),
            'innerHTML' => $markup,
            'innerContent' => array( $markup ),
        );
    }

    /**
     * Return a compact native button on the same terms as `input()`: a submit
     * (or other form-action) control the source styles has no readable prose
     * equivalent, because a Gutenberg button saves as an anchor and cannot
     * submit its ancestor form.
     *
     * @return array<string, mixed>|null
     */
    public function button(DOMElement $button, bool $forceNative = false): ?array
    {
        if ( ! $forceNative && array() === ($this->structuralPresentationDeclarations)($button) ) {
            return null;
        }

        $generator = new AuthoredButtonBlockGenerator();
        $registry = ($this->generatedBlocks)();
        $registry->register(AuthoredButtonBlockGenerator::class, $generator->definition($registry->namespace()));
        $type = FormControlClassifier::controlType($button);
        if ( ! in_array($type, array( 'button', 'reset', 'submit' ), true) ) {
            $type = 'submit';
        }
        $attrs = array_filter(array(
            'type' => $type,
            'id' => SourceDom::attr($button, 'id'),
            'name' => SourceDom::attr($button, 'name'),
            'ariaLabel' => SourceDom::attr($button, 'aria-label'),
            'className' => SourceDom::attr($button, 'class'),
            'style' => SourceDom::attr($button, 'style'),
            'text' => $this->metadataBuilder->submitText($button, 'Submit'),
            'disabled' => $button->hasAttribute('disabled'),
        ), static fn (mixed $value): bool => is_bool($value) ? $value : '' !== $value);
        $markup = $generator->markup($attrs);

        return array(
            'blockName' => $registry->blockName(AuthoredButtonBlockGenerator::LOCAL_NAME),
            'attrs' => $attrs,
            'innerBlocks' => array(),
            'innerHTML' => $markup,
            'innerContent' => array( $markup ),
        );
    }

    /** @return array<string, string> */
    private function dataAttributes(DOMElement $input): array
    {
        $attributes = array();
        foreach ( $input->attributes as $attribute ) {
            $name = strtolower($attribute->nodeName);
            if ( 1 !== preg_match('/^data-(?!wp-)[a-z0-9_.:-]+$/', $name) ) {
                continue;
            }
            $attributes[$name] = $attribute->nodeValue ?? '';
        }

        ksort($attributes);

        return $attributes;
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
