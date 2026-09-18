<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredInputBlockGenerator;
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
     * @param Closure(string): string                                                                       $generatedBlockName Resolves a local name through the transform's registry.
     * @param Closure(list<DOMElement>, list<array<string, mixed>>, DOMElement): array<string, mixed>       $layoutShellBlockForElements
     * @param Closure(): list<array<string, mixed>>|null                                                    $stylesheetAssets
     * @param Closure(): string|null                                                                        $formLayoutCss
     */
    public function __construct(
        private readonly FormControlMetadataBuilder $metadataBuilder,
        private readonly ReadableFormControlBlockConverter $controlBlockConverter,
        private readonly FormRuntimeIslandRecorder $runtimeIslandRecorder,
        private readonly Closure $eventMetadata,
        private readonly Closure $isRuntimeDomTarget,
        private readonly Closure $presentationAttributes,
        private readonly SourceBlockCreator $createBlock,
        private readonly Closure $generatedBlockName,
        private readonly Closure $layoutShellBlockForElements,
        private readonly ?Closure $stylesheetAssets = null,
        private readonly ?Closure $formLayoutCss = null
    ) {
    }

    /** @return array<string, mixed>|null */
    public function layoutGraph(): ?array
    {
        return $this->layoutGraph;
    }

    /** @return array<string, mixed>|null */
    public function build(DOMElement $form, bool $allowFormEvents = false): ?array
    {
        $this->layoutGraph = null;
        if ( 0 < $form->getElementsByTagName('script')->length
            || ( ! $allowFormEvents && array() !== ($this->eventMetadata)($form) )
        ) {
            return null;
        }

        $authoredInputName = ($this->generatedBlockName)(AuthoredInputBlockGenerator::LOCAL_NAME);
        foreach ( FormControlClassifier::controlElements($form) as $control ) {
            if ( array() !== ($this->eventMetadata)($control) || ! FormControlClassifier::isReadableControl($control) ) {
                return null;
            }

            if ( ($this->isRuntimeDomTarget)($control) ) {
                $this->runtimeIslandRecorder->recordControl($control);
            }
        }

        $contentBlocks = $this->groupedContentBlocks($form, $authoredInputName);
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
     * @return array<int, array<string, mixed>>
     */
    private function groupedContentBlocks(DOMElement $form, string $authoredInputName): array
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

        return $this->blocksFromGraphEntries($children['form'] ?? array(), $children, $authoredInputName);
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param array<string, list<array<string, mixed>>> $children
     * @return array<int, array<string, mixed>>
     */
    private function blocksFromGraphEntries(array $entries, array $children, string $authoredInputName): array
    {
        $blocks = array();
        foreach ( $entries as $entry ) {
            if ( 'control' === $entry['kind'] ) {
                $block = $this->convertDataEntryControl($entry['element'], $authoredInputName);
                if ( null !== $block ) {
                    $blocks[] = $block;
                }
                continue;
            }

            $inner = $this->blocksFromGraphEntries($children[ $entry['id'] ] ?? array(), $children, $authoredInputName);
            if ( array() === $inner ) {
                continue;
            }
            if ( 2 <= count($inner) ) {
                $blocks[] = ($this->layoutShellBlockForElements)(array( $entry['element'] ), $inner, $entry['element']);
                continue;
            }

            array_push($blocks, ...$inner);
        }

        return $blocks;
    }

    /** @return array<string, mixed>|null */
    private function convertDataEntryControl(DOMElement $control, string $authoredInputName): ?array
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

        return $authoredInputName === ($readableControlBlock['blockName'] ?? '')
            ? $this->createBlock->createBlock('core/group', array(), array( $readableControlBlock ), $control)
            : $readableControlBlock;
    }
}
