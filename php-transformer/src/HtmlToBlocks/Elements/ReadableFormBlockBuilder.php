<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;
use Closure;
use DOMElement;

/** Builds the readable static block representation of a form. */
final class ReadableFormBlockBuilder
{
    /** @var array<string, mixed>|null */
    private ?array $layoutGraph = null;

    /**
     * @param Closure(DOMElement): array<string, mixed>                                                     $eventMetadata
     * @param Closure(DOMElement): bool                                                                     $isRuntimeDomTarget
     * @param Closure(DOMElement): array<string, mixed>                                                     $presentationAttributes
     * @param Closure(list<DOMElement>, list<array<string, mixed>>, DOMElement): array<string, mixed>       $layoutShellBlockForElements
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

        foreach ( FormControlClassifier::controlElements($form) as $control ) {
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
        // Labels are the exception: each rides on the control it names.
        foreach ( $parent->childNodes as $node ) {
            if ( ! $node instanceof DOMElement ) {
                continue;
            }
            $entry = $entriesByPath[ (string) $node->getNodePath() ] ?? null;
            if ( null === $entry ) {
                if ( $this->isFlowContent($node) ) {
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
            $ownsShell = ! $ownedLabel && ($element->hasAttributes() || isset($graphNodes[$entry['id']]));
            $elements = array($element);
            $descendants = $children[$entry['id']] ?? array();
            // One layout-shell can retain a consecutive source wrapper chain.
            // Keeping each DOM wrapper as a separate block inflates form entity
            // nesting past the runtime declaration bound without adding editability.
            if ($ownsShell) {
                while (1 === count($descendants) && 'control' !== $descendants[0]['kind']) {
                    $next = $descendants[0];
                    $nextElement = $next['element'];
                    $nextOwnedLabel = 'label' === strtolower($nextElement->tagName) && '' !== $this->metadataBuilder->labelText($nextElement);
                    if ($nextOwnedLabel || (! $nextElement->hasAttributes() && ! isset($graphNodes[$next['id']]))) break;
                    $elements[] = $nextElement;
                    $descendants = $children[$next['id']] ?? array();
                }
            }
            $inner = $this->blocksFromGraphEntries($elements[ count($elements) - 1 ], $descendants, $children, $fallbacks);
            if ( array() === $inner ) {
                continue;
            }
            // A one-control container can own paint, inherited variables and sizing.
            // Text-bearing labels are already represented by the authored control.
            if ( 2 <= count($inner) || $ownsShell ) {
                $blocks[] = ($this->layoutShellBlockForElements)($elements, $inner, $element);
                continue;
            }

            array_push($blocks, ...$inner);
        }

        return $blocks;
    }

    /** Label text, wrapped or referenced, rides on the control it names. */
    private function isFlowContent(DOMElement $element): bool
    {
        if ( null === $this->convertFlowContent ) {
            return false;
        }
        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( 'label' === strtolower($node->tagName) ) {
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

        return $readableControlBlock;
    }
}
