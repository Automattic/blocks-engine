<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\CapturedDialogBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;
use LogicException;

/** Materializes a captured dialog into its companion block. */
final class CapturedDialogConverter implements ElementConverter
{
    /**
     * @param Closure(DOMElement, array<int, array<string, mixed>>&): array<int, array<string, mixed>> $convertChildren
     * @param (Closure(DOMElement): string)|null $resolvedStyle The element's authored, cascade-resolved declarations.
     * @param (Closure(DOMElement): array<string, string>)|null $openStateValues Values a state rule gives the panel over its hiding or offsetting rest rules.
     */
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly Closure $convertChildren,
        private readonly ?Closure $resolvedStyle = null,
        private readonly ?Closure $openStateValues = null
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        if ( ! in_array($tagName, array('dialog', 'div'), true) || 'true' !== SourceDom::attr($element, 'data-blocks-engine-captured-dialog') ) {
            return ConversionOutcome::unhandled();
        }

        if ( $this->isSupersededByNativeNavigation($element) ) {
            return ConversionOutcome::handled(null);
        }

        return ConversionOutcome::handled($this->block($element, $fallbacks));
    }

    private function isSupersededByNativeNavigation(DOMElement $dialog): bool
    {
        $document = $dialog->ownerDocument;
        if ( null === $document || 0 === $dialog->getElementsByTagName('nav')->length ) return false;
        foreach ( preg_split('/\s+/', trim(SourceDom::attr($dialog, 'data-blocks-engine-triggers'))) ?: array() as $triggerId ) {
            $trigger = $document->getElementById($triggerId);
            if ( ! $trigger instanceof DOMElement ) continue;
            if ( 'dialog' === strtolower(trim(SourceDom::attr($trigger, 'aria-haspopup')))
                && SourceDom::controlsSourceNavigation($trigger)
                && ! NavigationPattern::ownsCapturedSubmenuTrigger($trigger)
                && $this->session->navigationProjectionState()->hasTargetForControl($trigger)
            ) return true;
        }
        return false;
    }

    /**
     * The dialog's inline style, plus the open-state values a state rule gives
     * the panel over rest rules that hide or offset it. The dialog is the
     * captured open panel, so those rest rules must not win while it is open.
     * Inline and without `!important`: it beats the rest rules, and the block
     * stylesheet's `:not([open]){display:none!important}` still closes it.
     */
    private function openSourceStyle(DOMElement $element): string
    {
        $style = trim(SourceDom::attr($element, 'style'));
        if ( 'dialog' !== strtolower($element->tagName) || '' !== SourceDom::attr($element, 'data-blocks-engine-presentation') || null === $this->openStateValues ) {
            return $style;
        }
        foreach ( ($this->openStateValues)($element) as $property => $value ) {
            if ( 1 === preg_match('/(?:^|;)\s*' . preg_quote($property, '/') . '\s*:/i', $style) ) {
                continue;
            }
            $style = rtrim($style, "; \t\n") . ('' === $style ? '' : ';') . $property . ':' . $value;
        }

        return $style;
    }

    /**
     * A modal whose source panel positions itself (`position:fixed` or
     * `absolute` from its own author rules) drew its own box: the source panel
     * was not a `<dialog>`, so it had no user agent padding, border, auto
     * margins, insets or size caps. Mark it so the block stylesheet drops that
     * geometry and the source rules alone place the panel.
     */
    private function modalPlacement(DOMElement $element): string
    {
        if ( 'dialog' !== strtolower($element->tagName) || '' !== SourceDom::attr($element, 'data-blocks-engine-presentation') || null === $this->resolvedStyle ) {
            return '';
        }

        return 1 === preg_match('/(?:^|;)\s*position\s*:\s*(?:fixed|absolute)\b/i', ($this->resolvedStyle)($element)) ? 'source' : '';
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>
     */
    public function block(DOMElement $element, array &$fallbacks): array
    {
        $registry = $this->session->generatedBlockRegistry()
            ?? throw new LogicException('Generated block registry has not been prepared for this transform.');
        $blockName = $registry->blockName(CapturedDialogBlockGenerator::LOCAL_NAME);
        $registry->register(CapturedDialogBlockGenerator::class, (new CapturedDialogBlockGenerator())->definition($blockName));

        $attrs = array_filter(array(
            'tagName' => 'div' === strtolower($element->tagName) ? 'div' : '',
            'sourceStyle' => $this->openSourceStyle($element),
            'ancestorUnverified' => SourceDom::attr($element, CaptureEvidence::DIALOG_ANCESTOR_UNVERIFIED),
            'dialogId' => trim(SourceDom::attr($element, 'id')),
            'triggerIds' => array_values(array_filter(preg_split('/\s+/', trim(SourceDom::attr($element, 'data-blocks-engine-triggers'))) ?: array())),
            'closerIds' => array_values(array_filter(preg_split('/\s+/', trim(SourceDom::attr($element, 'data-blocks-engine-closers'))) ?: array())),
            'ariaLabel' => trim(SourceDom::attr($element, 'aria-label')),
            'ariaLabelledby' => trim(SourceDom::attr($element, 'aria-labelledby')),
            'ariaDescribedby' => trim(SourceDom::attr($element, 'aria-describedby')),
            'className' => trim(SourceDom::attr($element, 'class')),
            'presentation' => 'dropdown' === SourceDom::attr($element, 'data-blocks-engine-presentation') ? 'dropdown' : '',
            'placement' => in_array(SourceDom::attr($element, 'data-blocks-engine-placement'), array('in-place', 'under-header', 'source'), true) ? SourceDom::attr($element, 'data-blocks-engine-placement') : $this->modalPlacement($element),
            'addCloseButton' => 'true' === SourceDom::attr($element, 'data-blocks-engine-add-close'),
            'gallerySelection' => json_decode(SourceDom::attr($element, 'data-blocks-engine-gallery-selection'), true) ?: array(),
            'ancestorState' => CapturedDialogBlockGenerator::ancestorState(json_decode(SourceDom::attr($element, 'data-blocks-engine-ancestor-state'), true)),
        ), static fn(mixed $value): bool => false !== $value && '' !== $value && array() !== $value);
        $children = ($this->convertChildren)($element, $fallbacks);
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $tag = $attrs['tagName'] ?? 'dialog';
        $opening = '<' . $tag;
        foreach (array('dialogId' => 'id', 'className' => 'class', 'sourceStyle' => 'style', 'ancestorUnverified' => CaptureEvidence::DIALOG_ANCESTOR_UNVERIFIED, 'presentation' => 'data-blocks-engine-presentation', 'placement' => 'data-blocks-engine-placement', 'ariaLabel' => 'aria-label', 'ariaLabelledby' => 'aria-labelledby', 'ariaDescribedby' => 'aria-describedby') as $key => $attribute) {
            if (isset($attrs[$key])) $opening .= ' ' . $attribute . '="' . $escape((string) $attrs[$key]) . '"';
        }
        if (isset($attrs['triggerIds'])) $opening .= ' data-blocks-engine-triggers="' . $escape(implode(' ', $attrs['triggerIds'])) . '"';
        if (isset($attrs['closerIds'])) $opening .= ' data-blocks-engine-closers="' . $escape(implode(' ', $attrs['closerIds'])) . '"';
        if (isset($attrs['gallerySelection'])) $opening .= ' data-blocks-engine-gallery-selection="' . $escape(json_encode($attrs['gallerySelection'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) . '"';
        if (isset($attrs['ancestorState'])) $opening .= ' data-blocks-engine-ancestor-state="' . $escape(json_encode($attrs['ancestorState'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) . '"';
        $opening .= '>';
        if (! empty($attrs['addCloseButton'])) $opening .= '<button type="button" data-blocks-engine-dialog-close="true" aria-label="Close">Close</button>';
        $innerContent = array($opening);
        foreach ($children as $_) $innerContent[] = null;
        $innerContent[] = '</' . $tag . '>';

        return array(
            'blockName' => $blockName,
            'attrs' => $attrs,
            'innerBlocks' => $children,
            'innerHTML' => $opening . '</' . $tag . '>',
            'innerContent' => $innerContent,
        );
    }
}
