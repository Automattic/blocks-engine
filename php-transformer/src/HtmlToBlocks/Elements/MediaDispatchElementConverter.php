<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use DOMElement;

/** Routes native media, linked images, and placeholder media to their materializers. */
final class MediaDispatchElementConverter
{
    public function __construct(private readonly MediaDispatchElementContext $context)
    {
    }

    public function handles(string $tagName): bool
    {
        return in_array($tagName, array( 'a', 'audio', 'iframe', 'img', 'picture', 'video' ), true);
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        $placeholder = $this->context->recognizePlaceholder($element, $fallbacks);
        if ( null !== $placeholder ) {
            return ConversionOutcome::handled($placeholder);
        }

        if ( ! $this->handles($tagName) ) {
            return ConversionOutcome::unhandled();
        }

        if ( 'img' === $tagName ) {
            return ConversionOutcome::handled($this->context->convertImage($element));
        }
        if ( 'picture' === $tagName ) {
            return ConversionOutcome::handled($this->context->convertPicture($element));
        }
        if ( 'iframe' === $tagName ) {
            return ConversionOutcome::handled($this->context->convertIframe($element, $fallbacks));
        }
        if ( 'audio' === $tagName || 'video' === $tagName ) {
            return ConversionOutcome::handled($this->context->convertMedia($element));
        }

        $linkedImage = $this->context->linkedImageBlock($element);
        return null !== $linkedImage
            ? ConversionOutcome::handled($linkedImage)
            : ConversionOutcome::unhandled();
    }
}
