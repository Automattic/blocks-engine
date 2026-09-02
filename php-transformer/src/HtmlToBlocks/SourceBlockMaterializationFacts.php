<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use DOMElement;

/** Source evidence computed before a canonical block is materialized. */
final class SourceBlockMaterializationFacts
{
    /**
     * @param array<string, mixed>                                                        $sourceEntry
     * @param array<string, string>                                                       $sourceAttributes
     * @param array<string, mixed>                                                        $structureSignals
     * @param list<array{selector: string, declarations: array<string, string>, specificity: int, order: int, source_path: string, source_hash: string}> $visualTopologyEvidence
     */
    public function __construct(
        public readonly DOMElement $sourceElement,
        public readonly string $sourceTag,
        public readonly string $sourceSelector,
        public readonly array $sourceEntry,
        public readonly bool $startsHidden,
        public readonly array $sourceAttributes,
        public readonly array $structureSignals,
        public readonly array $visualTopologyEvidence
    ) {}
}
