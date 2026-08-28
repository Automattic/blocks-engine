<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Closure;
use DOMElement;

final class GalleryPatternContext
{
    /**
     * @param Closure(DOMElement, ?DOMElement, ?DOMElement, ?DOMElement): ?array<string, mixed> $convertImage
     * @param Closure(DOMElement, ?DOMElement, ?DOMElement): ?array<string, mixed> $convertPicture
     * @param Closure(DOMElement): ?DOMElement $linkedMediaAnchor
     */
    public function __construct(
        private readonly Closure $convertImage,
        private readonly Closure $convertPicture,
        private readonly Closure $linkedMediaAnchor
    ) {
    }

    /** @return array<string, mixed>|null */
    public function convertImage(DOMElement $image, ?DOMElement $figure = null, ?DOMElement $picture = null, ?DOMElement $link = null): ?array { return ($this->convertImage)($image, $figure, $picture, $link); }
    /** @return array<string, mixed>|null */
    public function convertPicture(DOMElement $picture, ?DOMElement $figure = null, ?DOMElement $link = null): ?array { return ($this->convertPicture)($picture, $figure, $link); }
    public function linkedMediaAnchor(DOMElement $figure): ?DOMElement { return ($this->linkedMediaAnchor)($figure); }
}
