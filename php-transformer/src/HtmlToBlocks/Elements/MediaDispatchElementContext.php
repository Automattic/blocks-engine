<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Closure;
use DOMElement;

/** Explicit transformer collaborator surface for {@see MediaDispatchElementConverter}. */
final class MediaDispatchElementContext
{
    public function __construct(
        private readonly Closure $recognizePlaceholder,
        private readonly Closure $convertImage,
        private readonly Closure $convertPicture,
        private readonly Closure $convertIframe,
        private readonly Closure $convertMedia,
        private readonly Closure $linkedImageBlock
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks @return array<string, mixed>|null */
    public function recognizePlaceholder(DOMElement $element, array &$fallbacks): ?array
    {
        return ($this->recognizePlaceholder)($element, $fallbacks);
    }

    /** @return array<string, mixed>|null */
    public function convertImage(DOMElement $element): ?array
    {
        return ($this->convertImage)($element);
    }

    /** @return array<string, mixed>|null */
    public function convertPicture(DOMElement $element): ?array
    {
        return ($this->convertPicture)($element);
    }

    /** @param array<int, array<string, mixed>> $fallbacks @return array<string, mixed>|null */
    public function convertIframe(DOMElement $element, array &$fallbacks): ?array
    {
        return ($this->convertIframe)($element, $fallbacks);
    }

    /** @return array<string, mixed>|null */
    public function convertMedia(DOMElement $element): ?array
    {
        return ($this->convertMedia)($element);
    }

    /** @return array<string, mixed>|null */
    public function linkedImageBlock(DOMElement $element): ?array
    {
        return ($this->linkedImageBlock)($element);
    }
}
