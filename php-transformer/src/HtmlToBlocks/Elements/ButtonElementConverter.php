<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use DOMElement;

/** Converts buttons through search, image-carrier, and generic precedence. */
final class ButtonElementConverter implements ElementConverter
{
    public function __construct(private readonly ButtonElementContext $context)
    {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        if ( 'button' !== $tagName ) {
            return ConversionOutcome::unhandled();
        }

        if ( $this->context->isReplacedSearchClusterControl($element) ) {
            return ConversionOutcome::handled(null);
        }

        if ( $this->context->isImageCarrierButton($element) ) {
            $children = $this->context->convertChildren($element, $fallbacks, true);
            if ( array() !== $children ) {
                return ConversionOutcome::handled(
                    $this->context->createBlock(
                        'core/group',
                        $this->context->presentationAttributes($element),
                        $children,
                        $element
                    )
                );
            }
        }

        return ConversionOutcome::handled($this->context->convertButton($element));
    }
}
