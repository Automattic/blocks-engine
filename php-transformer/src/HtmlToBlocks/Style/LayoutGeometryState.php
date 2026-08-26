<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/** Per-transform geometry proof and generated carrier state. */
final class LayoutGeometryState
{
    /** @var array<int, array<string, mixed>> */
    private array $proofProvenance = array();

    /** @var array<string, string> */
    private array $rules = array();

    private readonly GeometryCarrierClassAllocator $carrierClassAllocator;

    /** @param array<int, array<string, mixed>> $proofReductions */
    public function __construct(private readonly array $proofReductions = array())
    {
        $this->carrierClassAllocator = new GeometryCarrierClassAllocator();
    }

    public function hasProofReductions(): bool
    {
        return array() !== $this->proofReductions;
    }

    /** @return array<int, array<string, mixed>> */
    public function proofReductions(): array
    {
        return $this->proofReductions;
    }

    /** @param array<string, mixed> $proof */
    public function recordProof(array $proof): void
    {
        $this->proofProvenance[] = $proof;
    }

    /** @return array<int, array<string, mixed>> */
    public function proofProvenance(): array
    {
        return $this->proofProvenance;
    }

    public function allocateCarrier(string $signature): string
    {
        return $this->carrierClassAllocator->allocate($signature);
    }

    public function registerRule(string $className, string $rule): void
    {
        $this->rules[$className] = $rule;
    }

    public function appendRule(string $className, string $rule): void
    {
        $this->rules[$className] = implode("\n", array_filter(array(
            $this->rules[$className] ?? '',
            $rule,
        )));
    }

    public function cssForSerializedBlocks(string $serializedBlocks): string
    {
        $usedRules = array();
        foreach ($this->rules as $className => $rule) {
            if (preg_match('/(?:^|[^a-zA-Z0-9_-])' . preg_quote($className, '/') . '(?:$|[^a-zA-Z0-9_-])/', $serializedBlocks)) {
                $usedRules[] = $rule;
            }
        }

        return implode("\n", $usedRules);
    }
}
