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
