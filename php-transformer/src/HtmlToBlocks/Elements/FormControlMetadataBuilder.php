<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;
use DOMNode;

/** Builds provider-neutral form and control metadata from source DOM. */
final class FormControlMetadataBuilder
{
    /** How far a control's own field wrapper may sit above it. */
    private const FIELD_WRAPPER_DEPTH = 4;

    /** A field description reads as a note, not an article; bound it like other in-form copy. */
    private const MAX_DESCRIPTION_LENGTH = 240;

    /** @param Closure(DOMElement): string $elementSelector */
    public function __construct(
        private readonly Closure $elementSelector,
        private readonly ?Closure $presentationAttributes = null
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function controls(DOMElement $form): array
    {
        $controls = array();
        $order = 0;
        foreach ( FormControlClassifier::controlElements($form) as $control ) {
            $metadata = $this->control($control);
            if ( array() !== $metadata ) {
                $metadata['order'] = $order;
                $controls[] = $metadata;
                ++$order;
            }
        }

        return $controls;
    }

    /** @return array<string, mixed> */
    public function form(DOMElement $form): array
    {
        $metadata = array_filter(array(
            'id'           => SourceDom::attr($form, 'id'),
            'name'         => SourceDom::attr($form, 'name'),
            'class'        => SourceDom::attr($form, 'class'),
            'aria_label'   => SourceDom::attr($form, 'aria-label'),
            'action'       => SourceDom::attr($form, 'action'),
            'method'       => strtolower(SourceDom::attr($form, 'method')),
            'enctype'      => SourceDom::attr($form, 'enctype'),
            'target'       => SourceDom::attr($form, 'target'),
            'autocomplete' => SourceDom::attr($form, 'autocomplete'),
        ), static fn (string $value): bool => '' !== $value);

        if ( $form->hasAttribute('novalidate') ) {
            $metadata['novalidate'] = true;
        }

        // Empty status output still owns a layout slot after the controls.
        $status = $form->lastElementChild;
        if ( $status instanceof DOMElement && 'status' === $status->getAttribute('role')
            && in_array(strtolower($status->tagName), array('p', 'div', 'output'), true)
            && 0 === $status->childElementCount && '' === trim($status->textContent)
            && in_array($status->getAttribute('aria-live'), array('', 'polite'), true) ) {
            $output = array('role' => 'status');
            if ( preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,79}$/D', $status->getAttribute('id')) ) {
                $output['id'] = $status->getAttribute('id');
            }
            foreach ( array('top', 'bottom') as $side ) {
                if ( preg_match('/(?:^|;)\s*margin-' . $side . '\s*:\s*(-?[0-9]+(?:\.[0-9]+)?(?:px|em|rem|vh|vw|%)|0)\s*(?:;|$)/i', $status->getAttribute('style'), $match) ) {
                    $output['margin_' . $side] = $match[1];
                }
            }
            $metadata['trailing_status'] = $output;
        }

        $context = $this->inFormContext($form);
        foreach ( array( 'context_before', 'context_after' ) as $position ) {
            if ( array() !== $context[$position] ) {
                $metadata[$position] = $context[$position];
            }
        }
        if ( $context['interleaved_context'] ) {
            $metadata['interleaved_context'] = true;
        }
        if ( array() !== $context['unrepresented_context'] ) {
            $metadata['unrepresented_context'] = $context['unrepresented_context'];
        }

        return $metadata;
    }

    /**
     * Copy a source puts inside its own form — an introduction above the
     * fields, a "required field" note — is content the reader sees, but it is
     * not a control, so nothing in the control manifest carries it. Record it
     * against the controls it sits around so a materialized form can keep it.
     *
     * Copy that sits between two controls cannot be placed by position alone
     * (see `interleaved_context`), but the text itself is still reported —
     * bounded, under `unrepresented_context` — instead of being discarded
     * outright, so a caller can turn it into a named diagnostic rather than a
     * silent loss.
     *
     * @return array{context_before: array<int, array<string, mixed>>, context_after: array<int, array<string, mixed>>, interleaved_context: bool, unrepresented_context: array<int, array<string, mixed>>}
     */
    private function inFormContext(DOMElement $form): array
    {
        $before = array();
        $after = array();
        $unrepresented = array();
        $interleaved = false;
        $seenControls = 0;
        $totalControls = 0;
        foreach ( $form->getElementsByTagName('*') as $node ) {
            if ( $node instanceof DOMElement && FormControlClassifier::isControlElement($node) ) {
                ++$totalControls;
            }
        }

        foreach ( $form->getElementsByTagName('*') as $node ) {
            if ( ! $node instanceof DOMElement ) {
                continue;
            }
            if ( FormControlClassifier::isControlElement($node) ) {
                ++$seenControls;
                continue;
            }

            $item = $this->inFormContextItem($node);
            if ( null === $item ) {
                continue;
            }
            if ( 0 === $seenControls ) {
                $before[] = $item;
            } elseif ( $seenControls >= $totalControls ) {
                $after[] = $item;
            } else {
                $interleaved = true;
                $unrepresented[] = $item;
            }
        }

        return array(
            'context_before' => array_slice($before, 0, 8),
            'context_after' => array_slice($after, 0, 8),
            'interleaved_context' => $interleaved,
            'unrepresented_context' => array_slice($unrepresented, 0, 8),
        );
    }

    /** @return array<string, mixed>|null */
    private function inFormContextItem(DOMElement $node): ?array
    {
        $tagName = strtolower($node->tagName);
        $text = trim((string) preg_replace('/\s+/', ' ', $node->textContent ?? ''));
        if ( '' === $text || 200 < strlen($text) ) {
            return null;
        }

        if ( 1 === preg_match('/^h([1-6])$/', $tagName, $matches) ) {
            return array(
                'type' => 'heading',
                'level' => (int) $matches[1],
                'text' => $text,
            );
        }

        // A note only reads as instructional when the source says so. Every
        // label would otherwise be duplicated out of its own field.
        if ( in_array($tagName, array( 'label', 'p' ), true)
            && 1 === preg_match('/(?:required|note|instruction|help)/i', SourceDom::attr($node, 'class')) ) {
            return array(
                'type' => 'paragraph',
                'text' => $text,
            );
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function control(DOMElement $control): array
    {
        if ( ! FormControlClassifier::isControlElement($control) ) {
            return array();
        }

        $tagName = strtolower($control->tagName);
        $type = FormControlClassifier::controlType($control);
        $labelElement = $this->labelElement($control);
        $description = $this->describeControl($control, $labelElement);
        if ( 'button' === $type && FormControlClassifier::isSubmitLikeControl($control) ) {
            $type = 'submit';
        }
        $metadata = array_filter(array(
            'tag'              => $tagName,
            'selector'         => ($this->elementSelector)($control),
            'id'               => SourceDom::attr($control, 'id'),
            'class'            => $this->classNames($control),
            'label_id'         => $labelElement instanceof DOMElement ? SourceDom::attr($labelElement, 'id') : '',
            'label_class'      => $labelElement instanceof DOMElement ? $this->classNames($labelElement) : '',
            'name'             => SourceDom::attr($control, 'name'),
            'type'             => $this->authoredInputType($control, $type),
            'label'            => $this->label($control),
            'aria_haspopup'    => SourceDom::attr($control, 'aria-haspopup'),
            'aria_describedby' => SourceDom::attr($control, 'aria-describedby'),
            'placeholder'      => SourceDom::attr($control, 'placeholder'),
            'autocomplete'     => SourceDom::attr($control, 'autocomplete'),
            'pattern'          => SourceDom::attr($control, 'pattern'),
            'min'              => SourceDom::attr($control, 'min'),
            'max'              => SourceDom::attr($control, 'max'),
            'step'             => SourceDom::attr($control, 'step'),
            'maxlength'        => SourceDom::attr($control, 'maxlength'),
            'rows'             => $this->effectiveRows($control),
            'description'      => $description['description'],
        ), static fn (string $value): bool => '' !== $value);

        // More than one candidate means the text cannot be safely attributed to
        // this control alone; the caller surfaces it as a diagnostic instead of
        // guessing, via this internal marker stripped before publication.
        if ( array() !== $description['ambiguous'] ) {
            $metadata['_unresolved_description_candidates'] = array_slice($description['ambiguous'], 0, 4);
        }

        if ( in_array($type, array( 'button', 'reset', 'submit' ), true) ) {
            $text = $this->buttonText($control);
            if ( '' !== $text ) {
                $metadata['text'] = $text;
                $labelElement = $this->buttonLabelElement($control, $text);
                if ( $labelElement instanceof DOMElement ) {
                    $metadata['label_classes'] = $this->classNames($labelElement);
                    // Author rules that addressed this element are projected onto its
                    // rich-text marker, so the marker travels with it. A consumer that
                    // reproduces the element without it would keep the markup and lose
                    // the styles.
                    $labelMarker = SourceDom::attr($labelElement, 'data-blocks-engine-richtext-marker');
                    if ( '' !== $labelMarker ) {
                        $metadata['label_marker'] = $labelMarker;
                    }
                }
            }
            if ( null !== $this->presentationAttributes ) {
                $presentation = ($this->presentationAttributes)($control);
                if ( is_array($presentation['style'] ?? null) && array() !== $presentation['style'] ) {
                    $metadata['presentation'] = array( 'style' => $presentation['style'] );
                }
            }
        }

        if ( $control->hasAttribute('required') || 'true' === strtolower(trim(SourceDom::attr($control, 'aria-required'))) ) {
            $metadata['required'] = true;
            if ( ($marker = $this->requiredMarker($control)) instanceof DOMElement ) {
                $metadata['required_text'] = trim($marker->textContent ?? '');
            } else {
                // Required validation and a visible required marker are separate source facts.
                $metadata['required_indicator'] = false;
            }
        }
        if ( isset($metadata['label']) && is_string($metadata['label']) && 1 === preg_match('/^(.*?)(?:\s*\(\s*required\s*\))\s*$/iu', $metadata['label'], $requiredLabel) ) {
            $metadata['label'] = trim($requiredLabel[1]);
            $metadata['required'] = true;
            if ( '' === ($metadata['required_text'] ?? '') ) {
                $metadata['required_text'] = '(required)';
            }
        }
        if ( '' !== ($metadata['required_text'] ?? '') ) {
            unset($metadata['required_indicator']);
        }
        foreach ( array( 'disabled', 'readonly', 'checked', 'multiple' ) as $attribute ) {
            if ( $control->hasAttribute($attribute) ) {
                $metadata[$attribute] = true;
            }
        }

        $value = SourceDom::attr($control, 'value');
        if ( '' !== $value && 'select' !== $tagName ) {
            $metadata['value'] = $value;
        }

        if ( 'select' === $tagName ) {
            $options = $this->options($control);
            if ( array() !== $options ) {
                $metadata['options'] = $options;
            }
        }

        return $metadata;
    }

    public function label(DOMElement $control): string
    {
        $authoredAriaLabel = SourceDom::attr($control, 'aria-label');
        $ariaLabel = trim($authoredAriaLabel);
        $label = $this->labelElement($control);
        if ( '' !== $ariaLabel ) {
            $collapsed = $this->collapseRepeatedLabel($ariaLabel);
            // An authored label can carry the space that separates its own text
            // from a decorative required marker rendered beside it. Reported text
            // keeps that separator, exactly as the label element's text does, so
            // the rendered line box matches the source instead of closing up.
            if ( 1 === preg_match('/\s$/u', $authoredAriaLabel)
                && $label instanceof DOMElement
                && $this->hasDecorativeRequiredMarker($label)
            ) {
                return $collapsed . ' ';
            }

            return $collapsed;
        }

        if ( $label instanceof DOMElement ) {
            $text = $this->labelText($label);
            if ( '' !== $text ) {
                return $text;
            }
        }

        return $this->labelledByText($control);
    }

    private function labelledByText(DOMElement $control): string
    {
        $ids = preg_split('/\s+/', trim(SourceDom::attr($control, 'aria-labelledby'))) ?: array();
        $document = $control->ownerDocument;
        if ( ! $document instanceof \DOMDocument ) {
            return '';
        }
        $parts = array();
        foreach ( $ids as $id ) {
            if ( '' === $id ) {
                continue;
            }
            $target = $document->getElementById($id);
            if ( $target instanceof DOMElement ) {
                $part = $this->labelText($target);
                if ( '' !== $part ) {
                    $parts[] = $part;
                }
            }
        }

        return trim(implode(' ', $parts));
    }

    /**
     * A textarea without an authored `rows` still has an intrinsic height: HTML
     * defaults it to two rows. Report that source fact so a provider reproduces
     * the authored control height instead of imposing its own default.
     */
    private function effectiveRows(DOMElement $control): string
    {
        $rows = trim(SourceDom::attr($control, 'rows'));
        if ( 'textarea' !== strtolower($control->tagName) ) {
            return $rows;
        }
        if ( 1 === preg_match('/^[1-9][0-9]{0,3}$/D', $rows) ) {
            return $rows;
        }

        return '2';
    }

    private function authoredInputType(DOMElement $control, string $type): string
    {
        if ( 'text' !== $type ) {
            return $type;
        }
        $autocomplete = strtolower(trim(SourceDom::attr($control, 'autocomplete')));
        $legacy       = strtolower(trim(SourceDom::attr($control, 'x-autocompletetype')));
        $name         = strtolower(trim(SourceDom::attr($control, 'name')));
        if ( str_starts_with($autocomplete, 'tel') || str_starts_with($legacy, 'tel') ) {
            return 'tel';
        }
        if ( str_starts_with($autocomplete, 'email') || str_starts_with($legacy, 'email') || 'email' === $name ) {
            return 'email';
        }

        return $type;
    }

    public function readableLabel(DOMElement $control): string
    {
        $label = $this->label($control);
        if ( '' === $label ) {
            $label = SourceDom::attr($control, 'aria-label');
        }
        foreach ( array( 'placeholder', 'name' ) as $attribute ) {
            if ( '' === $label ) {
                $label = SourceDom::attr($control, $attribute);
            }
        }

        $type = FormControlClassifier::controlType($control);
        if ( '' === $label && FormControlClassifier::isSubmitLikeControl($control) ) {
            $label = trim(preg_replace('/\s+/', ' ', $control->textContent ?? '') ?? '');
        }

        return '' !== $label ? $label : ( 'select' === $type ? 'Select option' : ucfirst($type) );
    }

    /** Label associated by `for`; wrapping labels are handled with their control. */
    public function associatedLabel(DOMElement $control): ?DOMElement
    {
        return SourceDom::associatedLabel($control);
    }

    /** The explicit decorative required marker also supplies provider presentation identity. */
    public function requiredMarker(DOMElement $control): ?DOMElement
    {
        if ( ! $control->hasAttribute('required') && 'true' !== strtolower(trim(SourceDom::attr($control, 'aria-required'))) ) {
            return null;
        }
        $label = $this->labelElement($control);
        if ( ! $label instanceof DOMElement ) {
            return null;
        }
        foreach ( $label->getElementsByTagName('span') as $marker ) {
            $text = trim($marker->textContent ?? '');
            if ( 'true' === strtolower(SourceDom::attr($marker, 'aria-hidden')) && preg_match('/^\*{1,4}$/D', $text) ) {
                return $marker;
            }
        }
        return null;
    }

    /** The element that labels this control, by `for`, by wrapping, or by field position. */
    public function labelElement(DOMElement $control): ?DOMElement
    {
        $label = $this->associatedLabel($control);
        if ( $label instanceof DOMElement ) {
            return $label;
        }
        for ( $parent = $control->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            if ( 'label' === strtolower($parent->tagName) ) {
                return $parent;
            }
        }
        return $this->fieldWrapperLabel($control);
    }

    /**
     * Markup rendered by a client framework routinely omits both `id` and `for`
     * and states the association by position alone: one label and one control
     * inside the same field wrapper. That is the only association the source
     * makes, so read it instead of reporting the control as unlabelled.
     */
    private function fieldWrapperLabel(DOMElement $control): ?DOMElement
    {
        $depth = 0;
        for ( $wrapper = $control->parentNode; $wrapper instanceof DOMElement && $depth < self::FIELD_WRAPPER_DEPTH; $wrapper = $wrapper->parentNode, ++$depth ) {
            if ( in_array(strtolower($wrapper->tagName), array( 'form', 'fieldset', 'body', 'html' ), true) ) {
                return null;
            }

            $controls = FormControlClassifier::controlElements($wrapper);
            // A wrapper shared with another control cannot say which one a label belongs to.
            if ( 1 !== count($controls) || ! $controls[0]->isSameNode($control) ) {
                return null;
            }

            $labels = array();
            foreach ( $wrapper->getElementsByTagName('label') as $label ) {
                if ( $label instanceof DOMElement && '' === SourceDom::attr($label, 'for') ) {
                    $labels[] = $label;
                }
            }
            if ( 1 === count($labels) ) {
                return $labels[0];
            }
        }

        return null;
    }

    /**
     * A field's own helper/description copy — "Link to your design work…"
     * under a Portfolio URL input — is neither the label nor the control, so
     * neither the label lookup above nor the control manifest carries it. A
     * consumer materializing this field onto a provider block (Jetpack's
     * per-field `helpText` attribute, for one) needs that text attached to
     * the specific control it describes, not folded into a form-wide bucket
     * it cannot be positioned from.
     *
     * Read it the same way `fieldWrapperLabel()` reads a positional label:
     * only from a wrapper this control exclusively owns, so the text cannot
     * actually belong to a sibling field instead. More than one qualifying
     * candidate in that wrapper cannot be safely attributed either, so it is
     * reported as ambiguous rather than guessed at.
     *
     * @return array{description: string, ambiguous: array<int, string>}
     */
    private function describeControl(DOMElement $control, ?DOMElement $labelElement): array
    {
        $depth = 0;
        for ( $wrapper = $control->parentNode; $wrapper instanceof DOMElement && $depth < self::FIELD_WRAPPER_DEPTH; $wrapper = $wrapper->parentNode, ++$depth ) {
            if ( in_array(strtolower($wrapper->tagName), array( 'form', 'fieldset', 'body', 'html' ), true) ) {
                break;
            }

            $controls = FormControlClassifier::controlElements($wrapper);
            // A wrapper shared with another control cannot say which one nearby copy belongs to.
            if ( 1 !== count($controls) || ! $controls[0]->isSameNode($control) ) {
                break;
            }

            $candidates = $this->descriptionCandidates($wrapper, $control, $labelElement);
            if ( 1 === count($candidates) ) {
                return array( 'description' => $this->collapsedElementText($candidates[0]), 'ambiguous' => array() );
            }
            if ( 1 < count($candidates) ) {
                return array(
                    'description' => '',
                    'ambiguous' => array_map(fn (DOMElement $candidate): string => $this->collapsedElementText($candidate), $candidates),
                );
            }
        }

        return array( 'description' => '', 'ambiguous' => array() );
    }

    /**
     * Text-bearing descendants of an exclusively-owned field wrapper, other
     * than the control itself and its label. Ancestors of an already-found
     * candidate are skipped so a wrapping element is not double-counted with
     * the specific element that actually carries the text.
     *
     * @return array<int, DOMElement>
     */
    private function descriptionCandidates(DOMElement $wrapper, DOMElement $control, ?DOMElement $labelElement): array
    {
        $candidates = array();
        foreach ( $wrapper->getElementsByTagName('*') as $node ) {
            if ( ! $node instanceof DOMElement ) {
                continue;
            }
            if ( SourceDom::elementContains($control, $node) || SourceDom::elementContains($node, $control) ) {
                continue;
            }
            if ( $labelElement instanceof DOMElement
                && ( SourceDom::elementContains($labelElement, $node) || SourceDom::elementContains($node, $labelElement) )
            ) {
                continue;
            }
            if ( 'true' === strtolower(SourceDom::attr($node, 'aria-hidden')) ) {
                continue;
            }
            $alreadyCounted = false;
            foreach ( $candidates as $existing ) {
                if ( SourceDom::elementContains($existing, $node) ) {
                    $alreadyCounted = true;
                    break;
                }
            }
            if ( $alreadyCounted ) {
                continue;
            }

            $text = $this->collapsedElementText($node);
            if ( '' === $text || self::MAX_DESCRIPTION_LENGTH < strlen($text) ) {
                continue;
            }
            $candidates[] = $node;
        }

        return $candidates;
    }

    private function collapsedElementText(DOMElement $element): string
    {
        return trim(preg_replace('/\s+/', ' ', $element->textContent ?? '') ?? '');
    }

    private function classNames(DOMElement $element): string
    {
        $classes = array();
        foreach ( preg_split('/\s+/', trim(SourceDom::attr($element, 'class'))) ?: array() as $className ) {
            if ( count($classes) >= 16 ) {
                break;
            }
            if ( 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,79}$/D', $className) ) {
                $classes[] = $className;
            }
        }
        return implode(' ', $classes);
    }

    public function submitText(DOMElement $control, string $fallback): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $control->textContent ?? '') ?? '');
        if ( '' !== $text ) {
            return $text;
        }

        $value = trim(SourceDom::attr($control, 'value'));
        return '' !== $value ? $value : $fallback;
    }

    /** @return array<int, array<string, mixed>> */
    public function options(DOMElement $select): array
    {
        $options = array();
        foreach ( $select->getElementsByTagName('option') as $option ) {
            if ( ! $option instanceof DOMElement ) {
                continue;
            }

            $value = SourceDom::attr($option, 'value');
            $optionMetadata = array(
                'label' => trim(preg_replace('/\s+/', ' ', $option->textContent ?? '') ?? ''),
                // An explicit empty value is a placeholder semantic, not a missing value.
                'value' => $option->hasAttribute('value') ? $value : trim($option->textContent ?? ''),
            );
            if ( $option->hasAttribute('selected') ) {
                $optionMetadata['selected'] = true;
            }
            if ( $option->hasAttribute('disabled') ) {
                $optionMetadata['disabled'] = true;
            }
            if ( '' === trim($value) && ( $option->hasAttribute('disabled') || $option->hasAttribute('selected') ) ) {
                $optionMetadata['placeholder'] = true;
            }

            $options[] = $optionMetadata;
        }

        return $options;
    }

    public function labelText(DOMElement $label): string
    {
        $collapsed = preg_replace('/\s+/', ' ', $this->labelTextWithoutControls($label)) ?? '';
        $text = $this->collapseRepeatedLabel(trim($collapsed));
        if ( '' !== $text && 1 === preg_match('/\s$/u', $collapsed) && $this->hasDecorativeRequiredMarker($label) ) {
            return $text . ' ';
        }

        return $text;
    }

    private function hasDecorativeRequiredMarker(DOMElement $label): bool
    {
        foreach ( $label->getElementsByTagName('span') as $marker ) {
            if ( 'true' === strtolower(SourceDom::attr($marker, 'aria-hidden'))
                && 1 === preg_match('/^\*{1,4}$/D', trim($marker->textContent ?? ''))
            ) {
                return true;
            }
        }

        return false;
    }

    private function labelTextWithoutControls(DOMNode $node): string
    {
        if ( XML_TEXT_NODE === $node->nodeType ) {
            return $node->textContent ?? '';
        }
        if ( $node instanceof DOMElement && 'true' === strtolower(SourceDom::attr($node, 'aria-hidden')) ) {
            return '';
        }
        if ( $node instanceof DOMElement && FormControlClassifier::isControlElement($node) ) {
            return '';
        }

        $text = '';
        foreach ( $node->childNodes as $child ) {
            $text .= $this->labelTextWithoutControls($child);
        }

        return $text;
    }

    /**
     * A button can carry its label in a dedicated inline element that authored
     * rules address as a descendant, so the rendered line box belongs to that
     * element rather than the button. Report it, including when it declares no
     * classes, so a consumer can keep the element the source styles.
     *
     */
    private function buttonLabelElement(DOMElement $control, string $text): ?DOMElement
    {
        $labelElement = null;
        foreach ( $control->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                if ( null !== $labelElement ) {
                    return null;
                }
                $labelElement = $child;
                continue;
            }
            if ( $child instanceof DOMText && '' !== trim($child->textContent) ) {
                return null;
            }
        }
        if ( ! $labelElement instanceof DOMElement || 'span' !== strtolower($labelElement->tagName) ) {
            return null;
        }
        if ( trim(preg_replace('/\s+/', ' ', $labelElement->textContent ?? '') ?? '') !== $text ) {
            return null;
        }
        return $labelElement;
    }

    private function buttonText(DOMElement $control): string
    {
        foreach ( array( 'aria-label', 'title' ) as $attribute ) {
            $label = trim(SourceDom::attr($control, $attribute));
            if ( '' !== $label ) {
                return $label;
            }
        }

        $text = '';
        foreach ( $control->childNodes as $child ) {
            $text .= $this->labelTextWithoutControls($child);
        }
        $text = $this->collapseRepeatedLabel(trim(preg_replace('/\s+/', ' ', $text) ?? ''));
        return '' !== $text ? $text : trim(SourceDom::attr($control, 'value'));
    }

    private function collapseRepeatedLabel(string $label): string
    {
        if ( preg_match('/^\s*(.+?)[.!?]\s+\1\s*$/iu', $label, $match) ) {
            return trim($match[1]);
        }
        if ( preg_match('/^(.{2,})\1$/u', $label, $match) ) {
            return $match[1];
        }
        return $label;
    }
}
