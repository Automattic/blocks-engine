<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use DOMElement;

/** Core owns the template's keyboard skip control, including in reduced mode, so the source skip link is omitted. */
final class FocusRevealedSkipLinkConverter implements ElementConverter
{
    public function __construct(private readonly StyleResolver $styleResolver, private readonly HtmlTransformerSession $session)
    {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        return 'a' === $tagName && $this->styleResolver->isFocusRevealedSkipLink($element, $this->session->sourcePath())
            ? ConversionOutcome::handled(null)
            : ConversionOutcome::unhandled();
    }
}
