<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CodePresentation;
use DOMElement;

final class CodeWindowPatternContext
{
    public function __construct(private readonly CodePresentation $codePresentation)
    {
    }

    /** @return array<string, mixed> */
    public function presentationAttributes(DOMElement $pre, DOMElement $code): array { return $this->codePresentation->attributes($pre, $code); }
    public function content(DOMElement $code): string { return $this->codePresentation->content($code); }
}
