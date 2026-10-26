<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;

/**
 * Builds the readable static block representation of a form.
 *
 * The tree doubles as the provider substitution input (`readable_blocks`).
 * Each block that stands for a form field carries top-level annotations,
 * never attrs, which page provenance resolution strips before serialization:
 *
 * - `form_control_index`: the control's position in the finding's `controls[]`.
 * - `form_field`: the field a provider places its field block at. `kind` is
 *   `control` on a control block, or `group` on the block of a `<fieldset>`.
 *   `label` is the field's label (a group's legend) as inline rich text,
 *   links and emphasis kept.
 * - `_form_field_box`: a control's field box, the label and wrappers holding
 *   only that control and its label, as an empty layout shell carrying their
 *   presentation. The finding encloses the control in it, so a provider that
 *   swaps its field in for the control keeps the field's box.
 * - `_form_legend`: marks the block a legend lowered to on the page, so the
 *   finding can state the legend once, as its group's label.
 */
final class ReadableFormBlockBuilder
{
    /** @var array<string, mixed>|null */
    private ?array $layoutGraph = null;

    /** @var array<string, int> */
    private array $controlIndices = array();

    private ?DOMElement $form = null;

    /** @var array<string, true> Node paths of the labels that name a control. */
    private array $controlLabels = array();

    /** @var array<int, list<DOMElement>> Each control's field box frames the tree has not shelled yet. */
    private array $fieldBoxes = array();

    /**
     * @param Closure(DOMElement): array<string, mixed>                                                     $eventMetadata
     * @param Closure(DOMElement): bool                                                                     $isRuntimeDomTarget
     * @param Closure(DOMElement): array<string, mixed>                                                     $presentationAttributes
     * @param Closure(list<DOMElement>, list<array<string, mixed>>, DOMElement): array<string, mixed>       $layoutShellBlockForElements
     * @param Closure(list<DOMElement>): (array<string, mixed>|null)                                        $fieldBoxShell
     * @param Closure(): list<array<string, mixed>>|null                                                    $stylesheetAssets
     * @param Closure(): string|null                                                                        $formLayoutCss
     * @param Closure(DOMElement, array<int, array<string, mixed>>&): list<array<string, mixed>>|null       $convertFlowContent
     */
    public function __construct(
        private readonly FormControlMetadataBuilder $metadataBuilder,
        private readonly ReadableFormControlBlockConverter $controlBlockConverter,
        private readonly FormRuntimeIslandRecorder $runtimeIslandRecorder,
        private readonly Closure $eventMetadata,
        private readonly Closure $isRuntimeDomTarget,
        private readonly Closure $presentationAttributes,
        private readonly SourceBlockCreator $createBlock,
        private readonly Closure $layoutShellBlockForElements,
        private readonly Closure $fieldBoxShell,
        private readonly ?Closure $stylesheetAssets = null,
        private readonly ?Closure $formLayoutCss = null,
        private readonly ?Closure $convertFlowContent = null
    ) {
    }

    /** @return array<string, mixed>|null */
    public function layoutGraph(): ?array
    {
        return $this->layoutGraph;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks Findings from the form's flow content.
     * @return array<string, mixed>|null
     */
    public function build(DOMElement $form, bool $allowFormEvents = false, array &$fallbacks = array()): ?array
    {
        $this->layoutGraph = null;
        if ( 0 < $form->getElementsByTagName('script')->length
            || ( ! $allowFormEvents && array() !== ($this->eventMetadata)($form) )
        ) {
            return null;
        }

        $this->controlIndices = array();
        $this->controlLabels = array();
        $this->fieldBoxes = array();
        $this->form = $form;
        foreach ( FormControlClassifier::controlElements($form) as $index => $control ) {
            $this->controlIndices[(string) $control->getNodePath()] = $index;
            $label = $this->metadataBuilder->labelElement($control);
            if ( null !== $label ) {
                $this->controlLabels[(string) $label->getNodePath()] = true;
            }
            if ( array() !== ($this->eventMetadata)($control) || ! FormControlClassifier::isReadableControl($control) ) {
                return null;
            }

            if ( ($this->isRuntimeDomTarget)($control) ) {
                $this->runtimeIslandRecorder->recordControl($control);
            }
        }

        $contentBlocks = $this->groupedContentBlocks($form, $fallbacks);
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
     * Walk the layout graph so a shared container around two or more converted
     * controls stays a layout-shell, instead of re-inferring rows from nesting.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<int, array<string, mixed>>
     */
    private function groupedContentBlocks(DOMElement $form, array &$fallbacks): array
    {
        $structure = array();
        $this->layoutGraph = (new FormLayoutGraphBuilder())->build(
            $form,
            null !== $this->stylesheetAssets ? ($this->stylesheetAssets)() : array(),
            null !== $this->formLayoutCss ? ($this->formLayoutCss)() : '',
            $structure
        );
        $children = array();
        foreach ( $structure as $entry ) {
            $children[ $entry['parent'] ?? '' ][] = $entry;
        }

        return $this->blocksFromGraphEntries($form, $children['form'] ?? array(), $children, $fallbacks);
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param array<string, list<array<string, mixed>>> $children
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<int, array<string, mixed>>
     */
    private function blocksFromGraphEntries(DOMElement $parent, array $entries, array $children, array &$fallbacks): array
    {
        $blocks = array();
        $graphNodes = array_column($this->layoutGraph['nodes'] ?? array(), null, 'id');
        $entriesByPath = array();
        foreach ( $entries as $entry ) {
            $entriesByPath[ (string) $entry['element']->getNodePath() ] = $entry;
        }
        // Form structure is the controls and the containers around them.
        // Everything else in the form (an intro, a privacy note), in the graph
        // or not, is authored content at its source position, so it converts
        // as ordinary flow content instead of disappearing with the structure.
        // Labels that name a control are the exception: each rides on it.
        foreach ( $parent->childNodes as $node ) {
            if ( ! $node instanceof DOMElement ) {
                continue;
            }
            $entry = $entriesByPath[ (string) $node->getNodePath() ] ?? null;
            if ( null === $entry ) {
                if ('legend' === strtolower($node->tagName)) {
                    $legend = $this->controlBlockConverter->convert($node);
                    if (null !== $legend) {
                        $legend['_form_legend'] = true;
                        $blocks[] = $legend;
                    }
                } elseif ( $this->isFlowContent($node) ) {
                    array_push($blocks, ...(($this->convertFlowContent)($node, $fallbacks)));
                }
                continue;
            }
            if ( 'control' === $entry['kind'] ) {
                $block = $this->convertDataEntryControl($entry['element']);
                if ( null !== $block ) {
                    $blocks[] = $block;
                }
                continue;
            }

            $element = $entry['element'];
            if ( ! $this->holdsControl($entry['id'], $children) ) {
                if ( $this->isFlowContent($element) ) {
                    array_push($blocks, ...($this->convertFlowContent)($element, $fallbacks));
                }
                continue;
            }
            $ownedLabel = 'label' === strtolower($element->tagName) && '' !== $this->metadataBuilder->labelText($element);
            $isFieldset = 'fieldset' === strtolower($element->tagName);
            $ownsShell = ! $ownedLabel && ($isFieldset || $element->hasAttributes() || isset($graphNodes[$entry['id']]));
            $elements = array($element);
            $descendants = $children[$entry['id']] ?? array();
            // One layout-shell can retain a consecutive source wrapper chain.
            // Keeping each DOM wrapper as a separate block inflates form entity
            // nesting past the runtime declaration bound without adding editability.
            if ($ownsShell && ! $isFieldset) {
                while (1 === count($descendants) && 'control' !== $descendants[0]['kind']) {
                    // Hoisting through authored flow content would discard it.
                    $hasFlow = false;
                    foreach ($elements[count($elements) - 1]->childNodes as $child) {
                        if ($child instanceof DOMElement && ! $child->isSameNode($descendants[0]['element'])) $hasFlow = true;
                    }
                    if ($hasFlow) break;
                    $next = $descendants[0];
                    $nextElement = $next['element'];
                    $nextOwnedLabel = 'label' === strtolower($nextElement->tagName) && '' !== $this->metadataBuilder->labelText($nextElement);
                    if ('fieldset' === strtolower($nextElement->tagName) || $nextOwnedLabel || (! $nextElement->hasAttributes() && ! isset($graphNodes[$next['id']]))) break;
                    $elements[] = $nextElement;
                    $descendants = $children[$next['id']] ?? array();
                }
            }
            $inner = $this->blocksFromGraphEntries($elements[ count($elements) - 1 ], $descendants, $children, $fallbacks);
            if ( array() === $inner ) {
                continue;
            }
            if ($isFieldset) {
                $group = $this->createBlock->createBlock('core/group', ($this->presentationAttributes)($element), $inner, $element);
                $legend = null;
                foreach ($element->childNodes as $child) {
                    if ($child instanceof DOMElement && 'legend' === strtolower($child->tagName)) {
                        $legend = $child;
                        break;
                    }
                }
                $group['form_field'] = array(
                    'kind' => 'group',
                    'label' => null !== $legend ? $this->controlBlockConverter->labelMarkup($legend) : '',
                );
                $blocks[] = $group;
                continue;
            }
            // A one-control container can own paint, inherited variables and sizing.
            // Text-bearing labels are already represented by the authored control.
            if ( 2 <= count($inner) || $ownsShell ) {
                if ( 1 === count($inner) ) {
                    $inner = array($this->withoutShelledFieldBox($inner[0], $elements));
                }
                $shell = ($this->layoutShellBlockForElements)($elements, $inner, $element);
                foreach ($elements as $source) {
                    if ('true' === strtolower($source->getAttribute('data-blocks-engine-choice-group'))) {
                        $shell['_source_anchor_selector'] = SourceDom::elementSelector($source);
                    }
                }
                $blocks[] = $shell;
                continue;
            }

            array_push($blocks, ...$inner);
        }

        return $blocks;
    }

    /**
     * Label text, wrapped or referenced, rides on the control it names. A
     * label that names no control (a caption over a row of fields) is
     * authored content like any other.
     */
    private function isFlowContent(DOMElement $element): bool
    {
        if ( null === $this->convertFlowContent ) {
            return false;
        }
        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( isset($this->controlLabels[(string) $node->getNodePath()]) ) {
                return false;
            }
            if ( 'form' === strtolower($node->tagName) ) {
                break;
            }
        }
        return true;
    }

    /** @param array<string, list<array<string, mixed>>> $children */
    private function holdsControl(string $id, array $children): bool
    {
        foreach ( $children[$id] ?? array() as $child ) {
            if ( 'control' === $child['kind'] || $this->holdsControl($child['id'], $children) ) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, mixed>|null */
    private function convertDataEntryControl(DOMElement $control): ?array
    {
        // A submit control must stay a native submit control: Gutenberg's
        // core/button saves as an anchor, which cannot submit this form.
        // Force the authored companion so even an unstyled submit keeps
        // its type instead of collapsing to a paragraph.
        $forceNative = FormControlClassifier::isSubmitLikeControl($control);

        // The control's own label is a source fact the degraded form must
        // keep, so it rides on the control block as a real `<label>` rather
        // than being dropped with the rest of the replaced subtree.
        $readableControlBlock = $this->controlBlockConverter->convert($control, $this->metadataBuilder->labelElement($control), $forceNative);
        if ( null === $readableControlBlock ) {
            return null;
        }

        $readableControlBlock['form_control_index'] = $this->controlIndices[(string) $control->getNodePath()];
        $label = $this->metadataBuilder->labelElement($control);
        $readableControlBlock['form_field'] = array(
            'kind' => 'control',
            'label' => null !== $label ? $this->controlBlockConverter->labelMarkup($label) : '',
        );
        $box = $this->fieldBox($control, $label);
        $this->fieldBoxes[$readableControlBlock['form_control_index']] = $box;
        $shell = array() === $box ? null : ($this->fieldBoxShell)($box);
        if (null !== $shell) {
            $readableControlBlock['_form_field_box'] = $shell;
        }
        return $readableControlBlock;
    }

    /**
     * A wrapper the tree shells around one control is already part of that
     * control's field box, so the box the finding encloses it in keeps only
     * the frames still unshelled.
     *
     * @param array<string, mixed> $block The shelled block, or a shell around it.
     * @param list<DOMElement>     $elements The wrapper chain the tree shells.
     * @return array<string, mixed>
     */
    private function withoutShelledFieldBox(array $block, array $elements): array
    {
        if ( ! isset($block['form_control_index']) ) {
            $inner = $block['innerBlocks'] ?? array();
            if ( 1 === count($inner) && str_ends_with((string) ($block['blockName'] ?? ''), '/layout-shell') ) {
                $block['innerBlocks'] = array($this->withoutShelledFieldBox($inner[0], $elements));
            }
            return $block;
        }
        $index = $block['form_control_index'];
        $remaining = array_values(array_filter($this->fieldBoxes[$index] ?? array(), static fn (DOMElement $frame): bool => array() === array_filter($elements, static fn (DOMElement $shelled): bool => $shelled->isSameNode($frame))));
        if ( count($remaining) === count($this->fieldBoxes[$index] ?? array()) ) {
            return $block;
        }
        $this->fieldBoxes[$index] = $remaining;
        unset($block['_form_field_box']);
        $shell = array() === $remaining ? null : ($this->fieldBoxShell)($remaining);
        if ( null !== $shell ) {
            $block['_form_field_box'] = $shell;
        }
        return $block;
    }

    /**
     * The elements that frame one field, outermost first: each ancestor that
     * holds only this control and its label. The shell keeps the styled ones. A fieldset groups fields, so it
     * bounds the box; so does any ancestor holding other content.
     *
     * @return list<DOMElement>
     */
    private function fieldBox(DOMElement $control, ?DOMElement $label): array
    {
        $box = array();
        $path = $control;
        for ($node = $control->parentNode; $node instanceof DOMElement && ! $node->isSameNode($this->form); $node = $node->parentNode) {
            if ('fieldset' === strtolower($node->tagName) || 1 !== count(FormControlClassifier::controlElements($node))) {
                break;
            }
            // A label's own children are its caption; a wrapper's must be the path or the label.
            foreach (null !== $label && $node->isSameNode($label) ? array() : $node->childNodes as $child) {
                if ($child instanceof DOMElement
                    && ! $child->isSameNode($path)
                    && ! (null !== $label && ($child->isSameNode($label) || $this->contains($child, $label)))
                ) {
                    break 2;
                }
            }
            array_unshift($box, $node);
            $path = $node;
        }
        return $box;
    }

    private function contains(DOMElement $ancestor, DOMElement $node): bool
    {
        for ($parent = $node->parentNode; null !== $parent; $parent = $parent->parentNode) {
            if ($parent->isSameNode($ancestor)) {
                return true;
            }
        }
        return false;
    }
}
