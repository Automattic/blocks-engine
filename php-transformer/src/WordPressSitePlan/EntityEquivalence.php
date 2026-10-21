<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;

/** Semantic identity shared by shell hoisting and template variant folding. */
final class EntityEquivalence
{
    /** @param array<string,mixed> $entity */
    public static function key(array $entity, string $type = ''): ?string
    {
        $bindings = $entity['bindings'] ?? null;
        if (!is_array($bindings) || 1 !== count($bindings) || !empty($entity['superseded_scripts'])) return null;
        $role = (string) ($bindings[array_key_first($bindings)]['role'] ?? '');
        unset($entity['bindings'], $entity['reconciliation_identity'], $entity['fallback_identity'], $entity['replaced_fallback_identities']);
        // External metric IDs are transport identities, but their provenance
        // establishes trust: retain kind, repository revision and source path.
        if ('external_metrics' === $type && is_array($entity['source'] ?? null) && isset($entity['metric'], $entity['aggregation'], $entity['fallback'])) {
            unset($entity['id']);
            return $role . "\0" . EngineMarker::withoutDocumentSeeds(RuntimeDeclarations::canonicalJson($entity));
        }
        return $role . "\0" . EngineMarker::withoutDocumentSeeds(RuntimeDeclarations::canonicalJson(self::withoutSourcePaths($entity)));
    }

    /**
     * Source locations, cascade order and conversion diagnostics are provenance.
     * Normalize grid area shorthand to the equivalent row/column longhands.
     */
    private static function withoutSourcePaths(array $value): array
    {
        unset($value['source_path'], $value['provenance'], $value['source_order'], $value['diagnostics']);
        if (is_string($value['area'] ?? null) && 4 === count($lines = array_map('trim', explode('/', $value['area'])))) {
            unset($value['area']);
            $value['row'] = $lines[0] . ' / ' . $lines[2];
            $value['column'] = $lines[1] . ' / ' . $lines[3];
        }
        foreach (array('row', 'column') as $line) if (is_string($value[$line] ?? null)) $value[$line] = preg_replace('/\s*\/\s*/', ' / ', trim($value[$line]));
        foreach ($value as $key => $child) if (is_array($child)) $value[$key] = self::withoutSourcePaths($child);
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        return $value;
    }
}
