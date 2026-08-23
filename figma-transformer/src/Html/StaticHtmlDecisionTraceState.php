<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Deduplicated emitter decision traces for one public emission.
 */
final class StaticHtmlDecisionTraceState
{
    /** @var array<string, array<string, mixed>> */
    private array $traces = array();

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     * @param array<string, mixed> $evidence
     */
    public function record(string $domain, string $reasonCode, array $node, string $decision, ?array $parentNode, array $evidence, string $currentPagePath, ?string $class): void
    {
        DecisionTraceBuilder::recordEmitterTrace(
            $this->traces,
            $domain,
            $reasonCode,
            $node,
            $decision,
            $parentNode,
            $evidence,
            $currentPagePath,
            $class
        );
    }

    public function count(): int
    {
        return count($this->traces);
    }

    /** @return array<int, array<string, mixed>> */
    public function slice(int $offset): array
    {
        return array_slice($this->traces, $offset);
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return DecisionTraceBuilder::summary($this->traces);
    }

}
