<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use DOMElement;

/**
 * A `picture` with no image candidate (no `img`, no `source[srcset]`) is not
 * media: authors use it as a plain styled box. Lower it the way a generic
 * container lowers so its children and author CSS survive instead of the
 * media dispatch dropping the subtree.
 */
final class ImagelessPictureConverter implements ElementConverter
{
    public function __construct(
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly FlowContainerElementConverter $flowContainers
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        if ( 'picture' !== $tagName || $this->sourceElementClassifier->hasPictureImageCandidate($element) ) {
            return ConversionOutcome::unhandled();
        }

        return ConversionOutcome::handled($this->flowContainers->convertGenericContainer($element, $fallbacks)->block);
    }
}
