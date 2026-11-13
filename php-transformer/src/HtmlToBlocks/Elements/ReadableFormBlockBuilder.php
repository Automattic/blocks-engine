<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\BlockFactory;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredInputBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredButtonBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorLayoutOwnership;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ElementPresentationResolver;
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
 *   presentation. It ends at the frame that holds the caption: the control's
 *   label, or the outermost exclusive frame containing both caption and
 *   control. The finding encloses the control in it, so a provider that
 *   swaps its field in for the control keeps the field's box.
 * - `_form_control_box`: a control's own frames, the exclusive wrappers
 *   between that caption level and the control, with the same shell shape.
 *   The finding encloses the control in them inside the field box, marking
 *   that shell `form_control_box` with the control's index, so a provider
 *   rendering caption and control can present these frames around (or onto)
 *   its control rather than around its caption.
 * - `_form_legend`: marks the block a legend lowered to on the page, so the
 *   finding can state the legend once, as its group's label.
 */
final class ReadableFormBlockBuilder
{
    /** @var array<string, mixed>|null */
    private ?array $layoutGraph = null;

    /** @var array<string, int> */
    private array $controlIndices = array();

    /** @var array<string, DOMElement> Native controls keyed by their presentation node paths. */
    private array $presentedControls = array();

    private ?DOMElement $form = null;

    /** @var list<DOMElement> */
    private array $controlElements = array();

    /** @var array<string, true> Node paths of the labels that name a control. */
    private array $controlLabels = array();

    /** @var array<string, true> Node paths of trap-owned labels and field containers. */
    private array $honeypotCopy = array();

    /** @var array<int, list<DOMElement>> Each control's field box frames the tree has not shelled yet. */
    private array $fieldBoxes = array();

    /** @var array<int, list<DOMElement>> Each control's own frames, between its caption level and the control. */
    private array $controlBoxes = array();

    /**
     * @param Closure(DOMElement): array<string, mixed>                                                     $cssOwnedGroupAttributes
     * @param Closure(list<DOMElement>, list<array<string, mixed>>, DOMElement): array<string, mixed>       $layoutShellBlockForElements
     * @param Closure(list<DOMElement>): (array<string, mixed>|null)                                        $fieldBoxShell
     */
    public function __construct(
        private readonly FormControlMetadataBuilder $metadataBuilder,
        private readonly ReadableFormControlBlockConverter $controlBlockConverter,
        private readonly FormRuntimeIslandRecorder $runtimeIslandRecorder,
        private readonly RuntimeDomTargets $runtimeDomTargets,
        private readonly Closure $cssOwnedGroupAttributes,
        private readonly AuthorLayoutOwnership $authorLayoutOwnership,
        private readonly ElementPresentationResolver $presentationResolver,
        private readonly SourceBlockCreator $createBlock,
        private readonly Closure $layoutShellBlockForElements,
        private readonly Closure $fieldBoxShell,
        private readonly HtmlTransformerSession $session,
        private readonly ?PatternTreeConverter $flowTree = null
    ) {
    }

    private function presentationAttributes(DOMElement $element): array
    {
        return $this->authorLayoutOwnership->isAuthorOwnedLayout($element)
            ? ($this->cssOwnedGroupAttributes)($element) : $this->presentationResolver->presentationAttributes($element);
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
            || ( ! $allowFormEvents && array() !== SourceDom::eventMetadata($form) )
        ) {
            return null;
        }

        $this->controlIndices = array();
        $this->presentedControls = array();
        $this->controlLabels = array();
        $this->honeypotCopy = array();
        foreach ( FormControlClassifier::honeypotCopyElements($form) as $element ) {
            $this->honeypotCopy[(string) $element->getNodePath()] = true;
        }
        $this->fieldBoxes = array();
        $this->controlBoxes = array();
        $this->form = $form;
        $this->controlElements = FormWorkflowControlInventory::elements($form, $this->metadataBuilder, $this->session);
        foreach ( $this->controlElements as $index => $control ) {
            $this->controlIndices[(string) $control->getNodePath()] = $index;
            $presenter = FormControlClassifier::presentingElement($control);
            $this->presentedControls[(string) $presenter->getNodePath()] = $control;
            $label = $this->metadataBuilder->labelElement($control);
            if ( null !== $label ) {
                $this->controlLabels[(string) $label->getNodePath()] = true;
            }
            if ( array() !== SourceDom::eventMetadata($control) || ! FormControlClassifier::isReadableControl($control) ) {
                return null;
            }

            if ( $this->runtimeDomTargets->isRuntimeDomTarget($control) ) {
                $this->runtimeIslandRecorder->recordControl($control);
            }
            // The walk reads each box before it reaches the control, so a
            // wrapper below a frame stays unshelled and the boxes keep the
            // whole exclusive chain in source order.
            $boxes = $this->fieldAndControlBoxes($control, $label);
            $this->fieldBoxes[$index] = $boxes['field'];
            $this->controlBoxes[$index] = $boxes['control'];
        }

        $contentBlocks = $this->groupedContentBlocks($form, $fallbacks);
        if ( array() === $contentBlocks ) {
            return null;
        }

        if ( 'choice_submission' === FormRuntimeRequirementAnalyzer::formIntent($form) ) {
            $contentBlocks = array_map($this->inactiveControlBlock(...), $contentBlocks);
            $contentBlocks[] = $this->createBlock->createBlock('core/paragraph', array('content' => 'This form is not accepting responses yet.'));
        }

        // A degraded form is still a form: keep the source element so its
        // controls stay grouped for assistive technology and for a provider
        // that binds a handler to it later.
        $attributes = $this->presentationAttributes($form);
        if ( 'form' === strtolower($form->tagName) ) {
            $attributes['tagName'] = 'form';
        }

        return $this->createBlock->createBlock('core/group', $attributes, $contentBlocks, $form);
    }

    /** @param array<string, mixed> $block @return array<string, mixed> */
    private function inactiveControlBlock(array $block): array
    {
        foreach ( $block['innerBlocks'] ?? array() as $index => $child ) {
            $block['innerBlocks'][$index] = $this->inactiveControlBlock($child);
        }
        $localName = explode('/', $block['blockName'] ?? '')[1] ?? '';
        $generator = match ($localName) {
            AuthoredInputBlockGenerator::LOCAL_NAME => new AuthoredInputBlockGenerator(),
            AuthoredButtonBlockGenerator::LOCAL_NAME => new AuthoredButtonBlockGenerator(),
            default => null,
        };
        if ( null !== $generator ) {
            $attrs = $block['attrs'];
            $attrs['disabled'] = true;
            $block = array_replace($block, BlockFactory::leaf($block['blockName'], $attrs, $generator->markup($attrs)));
        }
        return $block;
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
        $this->layoutGraph = (new FormLayoutGraphBuilder($this->controlElements))->build(
            $form,
            $this->session->authorStyleAnalysis()->stylesheetAssets(),
            $this->session->sourceStyleResolutionState()->formLayoutCss(),
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
            // Excluded controls must not come back as ordinary authored flow
            // content, nor may their labels and private field containers.
            if ( isset($this->honeypotCopy[(string) $node->getNodePath()])
                || (FormControlClassifier::isControlElement($node) && FormControlClassifier::isHoneypotControl($node)) ) {
                continue;
            }
            // A legend is one group caption even when its nested wrappers
            // also appear in the layout graph. Do not walk those parts again
            // as independent flow content after lowering the whole caption.
            if ('legend' === strtolower($node->tagName)) {
                $legend = $this->controlBlockConverter->convert($node);
                if (null !== $legend) {
                    $legend['_form_legend'] = true;
                    $blocks[] = $legend;
                }
                continue;
            }
            $entry = $entriesByPath[ (string) $node->getNodePath() ] ?? null;
            if ( null === $entry ) {
                if ( $this->isFlowContent($node) ) {
                    $this->pushFlowContent($blocks, $node, $fallbacks);
                }
                continue;
            }
            if ( 'control' === $entry['kind'] ) {
                // The graph follows painted boxes, not necessarily native
                // controls (an offscreen submit can have a visible proxy).
                $presenter = $entry['element'];
                $control = $this->presentedControls[(string) $presenter->getNodePath()] ?? $presenter;
                $block = $this->convertDataEntryControl($control, $presenter);
                if ( null !== $block ) {
                    $blocks[] = $block;
                }
                continue;
            }

            $element = $entry['element'];
            // A control nested below a label's phrasing wrapper cannot be
            // reconstructed as caption + control: the caption contains that
            // same wrapper and control, and moving the wrapper outside the
            // label reverses its source ownership. Preserve this whole native
            // subtree once, before walking any of its painted descendants.
            if ('label' === strtolower($element->tagName)) {
                foreach (FormControlClassifier::controlElements($element) as $control) {
                    $path = $control;
                    for ($wrapper = $control->parentNode; $wrapper instanceof DOMElement && !$wrapper->isSameNode($element); $wrapper = $wrapper->parentNode) {
                        foreach ($wrapper->childNodes as $sibling) {
                            if (!$sibling->isSameNode($path) && '' !== trim($sibling->textContent ?? '')) {
                                $blocks[] = $this->nativeLabelSubtree($element);
                                continue 4;
                            }
                        }
                        $path = $wrapper;
                    }
                }
            }
            if ( ! $this->holdsControl($entry['id'], $children) ) {
                if ( $this->isFlowContent($element) ) {
                    $this->pushFlowContent($blocks, $element, $fallbacks);
                }
                continue;
            }
            $ownedLabel = 'label' === strtolower($element->tagName) && '' !== $this->metadataBuilder->labelText($element);
            $isFieldset = 'fieldset' === strtolower($element->tagName);
            // A wrapper below a frame of the control's field box or of its
            // own box belongs to that box: shelling it would stand the walk's
            // shell above the box's own frame, inverting the source nesting
            // the provider substitutes its field into.
            $inFieldBox = $this->isBelowBoxFrame($entry['id'], $element, $children);
            $ownsShell = ! $ownedLabel && ! $inFieldBox && ($isFieldset || $element->hasAttributes() || isset($graphNodes[$entry['id']]));
            $elements = array($element);
            $descendants = $children[$entry['id']] ?? array();
            // A shell starting at a control's own frame (its caption level
            // holds other content, such as an empty error slot) is that
            // control's box: it takes the whole frame chain down to the
            // control and is marked like the box the finding encloses.
            $controlBox = $ownsShell && ! $isFieldset ? $this->controlBoxIndex($entry['id'], $element, $children) : null;
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
                    $nextIsFrame = null !== $controlBox && $this->isControlBoxFrame($controlBox, $nextElement);
                    if ('fieldset' === strtolower($nextElement->tagName) || $nextOwnedLabel || (! $nextIsFrame && ! $nextElement->hasAttributes() && ! isset($graphNodes[$next['id']]))) break;
                    $elements[] = $nextElement;
                    $descendants = $children[$next['id']] ?? array();
                }
            }
            $inner = $this->blocksFromGraphEntries($elements[ count($elements) - 1 ], $descendants, $children, $fallbacks);
            if ( array() === $inner ) {
                continue;
            }
            if ($isFieldset) {
                // A fieldset's caption is outside its anonymous flex/grid
                // content box. Keep source identity on the content group so
                // projected author layout and field selectors still match.
                $captions = array();
                if ( $this->authorLayoutOwnership->isAuthorOwnedLayout($element) ) {
                    $captions = array_values(array_filter($inner, static fn (array $block): bool => ! empty($block['_form_legend'])));
                    if ( array() !== $captions ) {
                        $inner = array_values(array_filter($inner, static fn (array $block): bool => empty($block['_form_legend'])));
                    }
                }
                $group = $this->createBlock->createBlock('core/group', $this->presentationAttributes($element), $inner, $element);
                if ( array() !== $captions ) {
                    $group = $this->createBlock->createBlock('core/group', array('className' => 'blocks-engine-css-owned-layout blocks-engine-css-owned-flow'), array_merge($captions, array($group)));
                }
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
            if ( ( 2 <= count($inner) && ! $inFieldBox ) || $ownsShell ) {
                if ( 1 === count($inner) ) {
                    $inner = array($this->withoutShelledFieldBox($inner[0], $elements));
                }
                // Native groups retain class identity and resolved presentation for
                // simple field rows. Control boxes must keep their shell contract.
                $classOnlyGroup = 1 === count($inner) && 1 === count($elements)
                    && 'div' === strtolower($element->tagName)
                    && 1 === $element->attributes->length && $element->hasAttribute('class')
                    && null === $controlBox;
                $shell = $classOnlyGroup
                    ? $this->createBlock->createBlock('core/group', $this->presentationAttributes($element), $inner, $element)
                    : ($this->layoutShellBlockForElements)($elements, $inner, $element);
                if ( null !== $controlBox && 1 === count($inner) && $controlBox === ($inner[0]['form_control_index'] ?? null) && ! isset($inner[0]['_form_control_box']) ) {
                    $shell['form_control_box'] = $controlBox;
                }
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
     * Keep mixed label content in source order. The walk owns every wrapper
     * here, so controls must not carry a second copy of their label or boxes.
     *
     * @return array<string, mixed>
     */
    private function nativeLabelSubtree(DOMElement $element): array
    {
        $blocks = array();
        foreach ($element->childNodes as $child) {
            if (XML_TEXT_NODE === $child->nodeType && '' !== trim($child->textContent ?? '')) {
                $blocks[] = $this->createBlock->createBlock('core/paragraph', array(
                    'content' => htmlspecialchars($child->textContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    'className' => SourceBlockAttributeProjector::SYNTHETIC_PARAGRAPH_CLASS,
                ));
            } elseif ($child instanceof DOMElement) {
                if (! FormControlClassifier::isControlElement($child)) {
                    $blocks[] = $this->nativeLabelSubtree($child);
                    continue;
                }
                $block = $this->controlBlockConverter->convert($child, null, true);
                if (null !== $block) {
                    $index = $this->controlIndices[(string) $child->getNodePath()];
                    $block['form_control_index'] = $index;
                    $label = $this->metadataBuilder->labelElement($child);
                    $block['form_field'] = array(
                        'kind' => 'control',
                        'label' => null !== $label ? $this->controlBlockConverter->labelMarkup($label) : '',
                    );
                    $this->fieldBoxes[$index] = array();
                    $this->controlBoxes[$index] = array();
                    $blocks[] = $block;
                }
            }
        }
        return ($this->layoutShellBlockForElements)(array($element), $blocks, $element);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param list<array<string, mixed>> $fallbacks
     */
    private function pushFlowContent(array &$blocks, DOMElement $element, array &$fallbacks): void
    {
        $block = $this->flowTree?->element($element, $fallbacks, false);
        if ( null !== $block ) {
            $blocks[] = $block;
        }
    }

    /**
     * Label text, wrapped or referenced, rides on the control it names. A
     * label that names no control (a caption over a row of fields) is
     * authored content like any other.
     */
    private function isFlowContent(DOMElement $element): bool
    {
        if ( null === $this->flowTree ) {
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

    /**
     * @param array<string, list<array<string, mixed>>> $children
     * @return array<string, mixed>|null The single control entry below the element, or null when it holds several.
     */
    private function soleControlEntry(string $id, array $children): ?array
    {
        $sole = null;
        foreach ( $children[$id] ?? array() as $child ) {
            $control = 'control' === $child['kind'] ? $child : $this->soleControlEntry($child['id'], $children);
            if ( null === $control ) {
                continue;
            }
            if ( null !== $sole ) {
                return null;
            }
            $sole = $control;
        }
        return $sole;
    }

    /**
     * A field box frames its control outermost first, and the control's own
     * frames stack the same way inside it. A container the walk would shell
     * that sits below one of those frames must stay unshelled, so the boxes
     * the finding encloses the control in render the frames in source order
     * instead of below their own descendant.
     *
     * @param array<string, list<array<string, mixed>>> $children
     */
    private function isBelowBoxFrame(string $id, DOMElement $element, array $children): bool
    {
        $control = $this->soleControlEntry($id, $children);
        if ( null === $control ) {
            return false;
        }
        $index = $this->controlIndices[(string) $control['element']->getNodePath()] ?? null;
        foreach ( array_merge($this->fieldBoxes[$index] ?? array(), $this->controlBoxes[$index] ?? array()) as $frame ) {
            if ( ! $frame->isSameNode($element) && $this->contains($frame, $element) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The index of the sole captioned control whose own box the element
     * frames, or null. An uncaptioned control (a wrapped submit button) has
     * no caption level to split its frames from: its wrappers stay the
     * walk's plain shell.
     *
     * @param array<string, list<array<string, mixed>>> $children
     */
    private function controlBoxIndex(string $id, DOMElement $element, array $children): ?int
    {
        $control = $this->soleControlEntry($id, $children);
        if ( null === $control || null === $this->metadataBuilder->labelElement($control['element']) ) {
            return null;
        }
        $index = $this->controlIndices[(string) $control['element']->getNodePath()] ?? null;
        return null !== $index && $this->isControlBoxFrame($index, $element) ? $index : null;
    }

    private function isControlBoxFrame(int $index, DOMElement $element): bool
    {
        foreach ( $this->controlBoxes[$index] ?? array() as $frame ) {
            if ( $frame->isSameNode($element) ) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, mixed>|null */
    private function convertDataEntryControl(DOMElement $control, DOMElement $presenter): ?array
    {
        // A submit control must stay a native submit control: Gutenberg's
        // core/button saves as an anchor, which cannot submit this form.
        // Force the authored companion so even an unstyled submit keeps
        // its type instead of collapsing to a paragraph.
        $forceNative = FormControlClassifier::isSubmitLikeControl($control);
        // Preference islands retain their choice state in the readable tree,
        // including responsive navigation alternatives built from selects.
        for ( $form = $control->parentNode; $form instanceof DOMElement; $form = $form->parentNode ) {
            if ( 'form' === strtolower($form->tagName) ) {
                $forceNative = $forceNative || FormRuntimeRequirementAnalyzer::collectsOnlyChoices($form);
                break;
            }
        }

        // The control's own label is a source fact the degraded form must
        // keep, so it rides on the control block as a real `<label>` rather
        // than being dropped with the rest of the replaced subtree.
        $readableControlBlock = $control->isSameNode($presenter)
            ? $this->controlBlockConverter->convert($control, $this->metadataBuilder->labelElement($control), $forceNative)
            : $this->controlBlockConverter->convertSubmitPresenter($presenter);
        if ( null === $readableControlBlock ) {
            return null;
        }

        $readableControlBlock['form_control_index'] = $this->controlIndices[(string) $control->getNodePath()];
        $label = $this->metadataBuilder->labelElement($control);
        $readableControlBlock['form_field'] = array(
            'kind' => 'control',
            'label' => null !== $label ? $this->controlBlockConverter->labelMarkup($label) : '',
        );
        $index = $readableControlBlock['form_control_index'];
        $this->annotateBox($readableControlBlock, '_form_field_box', $this->fieldBoxes[$index] ?? array());
        $this->annotateBox($readableControlBlock, '_form_control_box', $this->controlBoxes[$index] ?? array());
        return $readableControlBlock;
    }

    /**
     * State one box once: an empty layout shell of the styled frames, or
     * nothing when none of them carries presentation.
     *
     * @param array<string, mixed> $block
     * @param list<DOMElement>     $frames
     */
    private function annotateBox(array &$block, string $annotation, array $frames): void
    {
        $shell = array() === $frames ? null : ($this->fieldBoxShell)($frames);
        if ( null !== $shell ) {
            $block[$annotation] = $shell;
        } else {
            unset($block[$annotation]);
        }
    }

    /**
     * A wrapper the tree shells around one control is already part of that
     * control's field box or of its own box, so each box the finding
     * encloses it in keeps only the frames still unshelled.
     *
     * @param array<string, mixed> $block The shelled block, or a shell around it.
     * @param list<DOMElement>     $elements The wrapper chain the tree shells.
     * @return array<string, mixed>
     */
    private function withoutShelledFieldBox(array $block, array $elements): array
    {
        if ( ! isset($block['form_control_index']) ) {
            $inner = $block['innerBlocks'] ?? array();
            if ( 1 === count($inner) && ('core/group' === ($block['blockName'] ?? '') || str_ends_with((string) ($block['blockName'] ?? ''), '/layout-shell')) ) {
                $block['innerBlocks'] = array($this->withoutShelledFieldBox($inner[0], $elements));
            }
            return $block;
        }
        $index = $block['form_control_index'];
        $fieldFrames = $this->unshelledFrames($this->fieldBoxes[$index] ?? array(), $elements);
        if ( count($fieldFrames) !== count($this->fieldBoxes[$index] ?? array()) ) {
            $this->fieldBoxes[$index] = $fieldFrames;
            $this->annotateBox($block, '_form_field_box', $fieldFrames);
        }
        $controlFrames = $this->unshelledFrames($this->controlBoxes[$index] ?? array(), $elements);
        if ( count($controlFrames) !== count($this->controlBoxes[$index] ?? array()) ) {
            $this->controlBoxes[$index] = $controlFrames;
            $this->annotateBox($block, '_form_control_box', $controlFrames);
        }
        return $block;
    }

    /**
     * @param list<DOMElement> $frames
     * @param list<DOMElement> $elements
     * @return list<DOMElement>
     */
    private function unshelledFrames(array $frames, array $elements): array
    {
        return array_values(array_filter($frames, static fn (DOMElement $frame): bool => array() === array_filter($elements, static fn (DOMElement $shelled): bool => $shelled->isSameNode($frame))));
    }

    /**
     * The elements that frame one field, outermost first, split at the caption
     * level: the field box carries each ancestor that holds only this control
     * and its label, ending at the frame that holds the caption — the
     * control's label, or the outermost exclusive frame containing both.
     * Frames between the caption level and the control hold the control
     * alone, so they are its own box. A fieldset groups fields, so it bounds
     * both boxes; so does any ancestor holding other content.
     *
     * @return array{field: list<DOMElement>, control: list<DOMElement>}
     */
    private function fieldAndControlBoxes(DOMElement $control, ?DOMElement $label): array
    {
        $field = array();
        $own = array();
        $path = $control;
        for ($node = $control->parentNode; $node instanceof DOMElement && ! $node->isSameNode($this->form); $node = $node->parentNode) {
            if ('fieldset' === strtolower($node->tagName) || 1 !== count(array_filter($this->controlElements, fn (DOMElement $element): bool => $this->contains($node, $element)))) {
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
            if ( null !== $label && ( $node->isSameNode($label) || $this->contains($node, $label) ) ) {
                array_unshift($field, $node);
            } else {
                array_unshift($own, $node);
            }
            $path = $node;
        }
        return array('field' => $field, 'control' => $own);
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
