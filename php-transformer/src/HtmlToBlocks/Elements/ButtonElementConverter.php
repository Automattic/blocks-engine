<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredButtonBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
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
        $roleButton = AuthoredButtonBlockGenerator::isRoleButton($element);
        if ( 'button' !== $tagName && (!$roleButton || !$this->context->isRuntimeDomTarget($element)) ) {
            return ConversionOutcome::unhandled();
        }

        if ($roleButton) {
            if (!AuthoredButtonBlockGenerator::canRetainRoleButton($element)) return ConversionOutcome::unhandled();
            return ConversionOutcome::handled($this->context->runtimeButton($element));
        }

        if ( $this->context->isReplacedSearchClusterControl($element) ) {
            return ConversionOutcome::handled(null);
        }

        if ( $this->context->isRuntimeDomTarget($element) && $this->preservesUnsafeInlineHandler($element) ) {
            return ConversionOutcome::handled($this->context->convertButton($element));
        }

        if ( $this->context->isRuntimeDomTarget($element) && ( array() !== AuthoredButtonBlockGenerator::sourceSafeAttributes($element) || ! $this->context->isRichTextButtonLabel($element) ) ) {
            $runtimeButton = $this->context->runtimeButton($element);
            if ( null !== $runtimeButton ) {
                return ConversionOutcome::handled($runtimeButton);
            }
        }

        if ( $this->context->isImageCarrierButton($element) && '' !== trim(SourceDom::attr($element, 'aria-label')) ) {
            return ConversionOutcome::handled($this->context->runtimeButton($element));
        }

        if ( $this->context->isImageCarrierButton($element) || ! $this->context->isRichTextButtonLabel($element) ) {
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

            return ConversionOutcome::unhandled();
        }

        return ConversionOutcome::handled($this->context->convertButton($element));
    }

    private function preservesUnsafeInlineHandler(DOMElement $element): bool
    {
        if ( array() !== AuthoredButtonBlockGenerator::sourceSafeAttributes($element) ) {
            return false;
        }

        if ( array() !== SourceDom::eventMetadata($element) ) {
            return true;
        }

        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && array() !== SourceDom::eventMetadata($descendant) ) {
                return true;
            }
        }

        return false;
    }
}
