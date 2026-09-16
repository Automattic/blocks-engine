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

        return ConversionOutcome::handled($this->block($element, $fallbacks));
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
            'addCloseButton' => 'true' === SourceDom::attr($element, 'data-blocks-engine-add-close'),
        ), static fn(mixed $value): bool => false !== $value && '' !== $value && array() !== $value);
        $children = ($this->convertChildren)($element, $fallbacks);
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $opening = '<dialog';
        foreach (array('dialogId' => 'id', 'className' => 'class', 'ariaLabel' => 'aria-label', 'ariaLabelledby' => 'aria-labelledby', 'ariaDescribedby' => 'aria-describedby') as $key => $attribute) {
            if (isset($attrs[$key])) $opening .= ' ' . $attribute . '="' . $escape((string) $attrs[$key]) . '"';
        }
        if (isset($attrs['triggerIds'])) $opening .= ' data-blocks-engine-triggers="' . $escape(implode(' ', $attrs['triggerIds'])) . '"';
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
