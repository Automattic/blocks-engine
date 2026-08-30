<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\DetailsPattern;
use DOMElement;

/** Converts native disclosures while preserving captured-dialog precedence. */
final class DetailsElementConverter implements ElementConverter
{
    public function __construct(private readonly DetailsElementContext $context)
    {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        if ( 'details' !== $tagName ) {
            return ConversionOutcome::unhandled();
        }

        $capturedDialog = $this->context->capturedDisclosureDialog($element);
        if ( $capturedDialog instanceof DOMElement ) {
            return ConversionOutcome::handled($this->context->capturedDialogBlock($capturedDialog, $fallbacks));
        }

        return ConversionOutcome::handled(
            $this->context->recognizePatterns($element, $fallbacks, array( DetailsPattern::class ))
        );
    }
}
