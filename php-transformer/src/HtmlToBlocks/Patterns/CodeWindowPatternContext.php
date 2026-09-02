<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Closure;
use DOMElement;

final class CodeWindowPatternContext
{
    /**
     * @param Closure(DOMElement, DOMElement): array<string, mixed> $presentationAttributes
     * @param Closure(DOMElement): string $content
     */
    public function __construct(
        private readonly Closure $presentationAttributes,
        private readonly Closure $content
    ) {
    }

    /** @return array<string, mixed> */
    public function presentationAttributes(DOMElement $pre, DOMElement $code): array { return ($this->presentationAttributes)($pre, $code); }
    public function content(DOMElement $code): string { return ($this->content)($code); }
}
