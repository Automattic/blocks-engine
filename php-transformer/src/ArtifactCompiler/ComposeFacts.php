<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

/** Work ledger and immutable input facts, owned by a single composition. */
final class ComposeFacts
{
    private array $counts = array(
        'compose_rule_unit_collections' => 0,
        'compose_route_selector_matches' => 0,
        'compose_marker_carriers' => 0,
        'compose_state_matches' => 0,
    );
    private array $values = array();
    private array $inputIdentities = array();

    /**
     * Intern immutable inputs once. PHP string keys retain the existing string
     * buffer (not a serialized copy), and Zend caches its table hash. All fact
     * tables below use only the fixed-size digest, never CSS or route markup.
     */
    public function inputIdentity(string $input): string
    {
        return $this->inputIdentities[$input] ??= hash('sha256', $input);
    }

    public function inputIdentities(array $inputs): array
    {
        return array_map($this->inputIdentity(...), $inputs);
    }

    public function get(string $kind, string $identity, callable $compute): mixed
    {
        if (!array_key_exists($identity, $this->values[$kind] ?? array())) {
            $this->values[$kind][$identity] = $compute();
        }
        return $this->values[$kind][$identity];
    }

    public function count(string $work): void
    {
        $this->counts['compose_' . $work] = ($this->counts['compose_' . $work] ?? 0) + 1;
    }

    public function metrics(): array
    {
        return $this->counts;
    }
}
