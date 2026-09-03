<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

/** Converts source subtrees while pattern recognizers accumulate local diagnostics. */
interface PatternTreeConverter
{
    /**
     * @param list<array<string, mixed>> $fallbacks
     * @return list<array<string, mixed>>
     */
    public function children(DOMElement $element, array &$fallbacks, bool $captureUnsupported): array;

    /**
     * @param list<array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function element(DOMElement $element, array &$fallbacks, bool $captureUnsupported): ?array;

    /**
     * @param list<array<string, mixed>> $fallbacks
     * @param list<string> $excludedTags
     * @return list<array<string, mixed>>
     */
    public function childrenWithoutTags(DOMElement $element, array &$fallbacks, array $excludedTags): array;
}
