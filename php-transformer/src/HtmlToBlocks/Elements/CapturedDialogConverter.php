<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\CapturedDialogBlockGenerator;
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
     */
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly Closure $convertChildren
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        if ( 'dialog' !== $tagName || 'true' !== SourceDom::attr($element, 'data-blocks-engine-captured-dialog') ) {
            return ConversionOutcome::unhandled();
        }

        if ( $this->hasSourceNavigationOwner($element) ) {
            return ConversionOutcome::handled(null);
        }

        return ConversionOutcome::handled($this->block($element, $fallbacks));
    }

    private function hasSourceNavigationOwner(DOMElement $dialog): bool
    {
        $document = $dialog->ownerDocument;
        if ( null === $document || 0 === $dialog->getElementsByTagName('nav')->length ) return false;
        foreach ( preg_split('/\s+/', trim(SourceDom::attr($dialog, 'data-blocks-engine-triggers'))) ?: array() as $triggerId ) {
            foreach ( $document->getElementsByTagName('*') as $trigger ) {
                if ( ! $trigger instanceof DOMElement || SourceDom::attr($trigger, 'id') !== $triggerId ) continue;
                $targetId = trim(SourceDom::attr($trigger, 'aria-controls'));
                if ( '' === $targetId || preg_match('/\s/', $targetId) ) continue;
                foreach ( $document->getElementsByTagName('*') as $target ) {
                    if ( ! $target instanceof DOMElement || SourceDom::attr($target, 'id') !== $targetId ) continue;
                    if ( 'nav' === strtolower($target->tagName) || 0 < $target->getElementsByTagName('nav')->length ) return true;
                }
            }
        }
        return false;
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
            'dialogId' => trim(SourceDom::attr($element, 'id')),
            'triggerIds' => array_values(array_filter(preg_split('/\s+/', trim(SourceDom::attr($element, 'data-blocks-engine-triggers'))) ?: array())),
            'ariaLabel' => trim(SourceDom::attr($element, 'aria-label')),
            'ariaLabelledby' => trim(SourceDom::attr($element, 'aria-labelledby')),
            'ariaDescribedby' => trim(SourceDom::attr($element, 'aria-describedby')),
            'className' => trim(SourceDom::attr($element, 'class')),
            'presentation' => 'dropdown' === SourceDom::attr($element, 'data-blocks-engine-presentation') ? 'dropdown' : '',
            'placement' => in_array(SourceDom::attr($element, 'data-blocks-engine-placement'), array('in-place', 'under-header', 'source'), true) ? SourceDom::attr($element, 'data-blocks-engine-placement') : '',
            'addCloseButton' => 'true' === SourceDom::attr($element, 'data-blocks-engine-add-close'),
            'gallerySelection' => json_decode(SourceDom::attr($element, 'data-blocks-engine-gallery-selection'), true) ?: array(),
            'ancestorState' => json_decode(SourceDom::attr($element, 'data-blocks-engine-ancestor-state'), true) ?: array(),
        ), static fn(mixed $value): bool => false !== $value && '' !== $value && array() !== $value);
        $children = ($this->convertChildren)($element, $fallbacks);
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $opening = '<dialog';
        foreach (array('dialogId' => 'id', 'className' => 'class', 'presentation' => 'data-blocks-engine-presentation', 'placement' => 'data-blocks-engine-placement', 'ariaLabel' => 'aria-label', 'ariaLabelledby' => 'aria-labelledby', 'ariaDescribedby' => 'aria-describedby') as $key => $attribute) {
            if (isset($attrs[$key])) $opening .= ' ' . $attribute . '="' . $escape((string) $attrs[$key]) . '"';
        }
        if (isset($attrs['triggerIds'])) $opening .= ' data-blocks-engine-triggers="' . $escape(implode(' ', $attrs['triggerIds'])) . '"';
        if (isset($attrs['gallerySelection'])) $opening .= ' data-blocks-engine-gallery-selection="' . $escape(json_encode($attrs['gallerySelection'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) . '"';
        if (isset($attrs['ancestorState'])) $opening .= ' data-blocks-engine-ancestor-state="' . $escape(json_encode($attrs['ancestorState'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) . '"';
        $opening .= '>';
        if (! empty($attrs['addCloseButton'])) $opening .= '<button type="button" data-blocks-engine-dialog-close="true" aria-label="Close">Close</button>';
        $innerContent = array($opening);
        foreach ($children as $_) $innerContent[] = null;
        $innerContent[] = '</dialog>';

        return array(
            'blockName' => $blockName,
            'attrs' => $attrs,
            'innerBlocks' => $children,
            'innerHTML' => $opening . '</dialog>',
            'innerContent' => $innerContent,
        );
    }
}
