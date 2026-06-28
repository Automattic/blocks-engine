<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Contract;

use InvalidArgumentException;

/**
 * Versioned contract for the generic conversion-finding shape emitted by the
 * transformer's diagnostic producers.
 *
 * The transformer surfaces conversion findings/diagnostics from several
 * producers — the fallback emitter, the flat diagnostics collector, the
 * semantic-parity reporter, the runtime-dependency parity report, and the
 * conversion-report projection. Historically each producer emitted a loose
 * array with informally matched keys, with no formal schema, which is a
 * silent-drift risk: a producer can rename a value, drop the identifier, or
 * emit an out-of-band severity and nothing fails.
 *
 * This contract formalizes that shape. It is intentionally TOLERANT: it
 * captures only the invariants that actually hold across every finding produced
 * today, so existing valid findings keep validating. It mirrors
 * {@see VisualParityReportContract} (a `SCHEMA` version constant plus static
 * `assert*()` methods that throw {@see InvalidArgumentException}).
 *
 * Boundary note: this is blocks-engine's OWN generic output schema. It carries
 * no knowledge of any consumer — producers emit findings conforming to this
 * shape and downstream consumers adapt to it. Nothing here references a consumer
 * concept.
 *
 * Reality of the finding shape (the union observed across producers):
 *  - A stable identifier is the ONLY universally present field. Producers carry
 *    it under `code` (diagnostics collector, semantic parity, runtime
 *    dependency parity) OR `diagnostic_code` (fallback emitter, conversion
 *    report fallback projection). At least one, non-empty, is REQUIRED.
 *  - `severity`, when present, is drawn from a closed set; this is the highest
 *    value invariant the contract guards against drift.
 *  - A human-readable descriptor is carried under `message` OR `summary`; it is
 *    present on the producer-owned findings but absent from the compact
 *    conversion-report fallback projection, so it is OPTIONAL (type-checked when
 *    present).
 *  - Everything else (provenance, classification, repair guidance, structural
 *    counts, nested context) is OPTIONAL and producer-specific; well-known
 *    fields are type-checked when present, and unknown fields are tolerated so
 *    producers can carry additive metadata without breaking the contract.
 */
final class ConversionFindingContract
{
    public const SCHEMA = 'blocks-engine/php-transformer/conversion-finding/v1';

    /**
     * Closed set of finding severities. Forward-compatible with
     * {@see VisualParityReportContract}; producers emit `info` and `warning`
     * today, with the rest reserved.
     */
    private const SEVERITIES = array('none', 'info', 'warning', 'error', 'critical');

    /**
     * Identifier keys, in resolution priority. A finding must carry a non-empty
     * string under at least one of these.
     */
    private const CODE_KEYS = array('code', 'diagnostic_code');

    /**
     * Human-readable descriptor keys. Optional; type-checked when present.
     */
    private const MESSAGE_KEYS = array('message', 'summary');

    /**
     * Well-known optional scalar string fields, validated for type only when
     * present. Unknown fields are tolerated.
     *
     * @var array<int, string>
     */
    private const STRING_FIELDS = array(
        'message', 'summary', 'source', 'source_format', 'scope',
        'reason', 'reason_code', 'tag', 'kind',
        'selector', 'source_selector', 'source_path',
        'pattern_family', 'pattern_family_detail', 'parent_reason', 'ancestor_reason',
        'conversion_classification', 'loss_class', 'diagnostic_class', 'preservation_strategy',
        'runtime_requirement', 'recoverability', 'actionability',
        'repair_bucket', 'suggested_repair_class', 'suggested_generic_repair_class',
        'suggested_primitive', 'materialization_hint',
        'script_role', 'block_name', 'path',
    );

    /**
     * Well-known optional array (object/list) fields, validated for type only
     * when present.
     *
     * @var array<int, string>
     */
    private const ARRAY_FIELDS = array(
        'source_selector_specificity', 'context', 'events', 'classification',
        'controls', 'control', 'form', 'readable_blocks', 'signals',
        'source_items', 'block_items', 'source_item', 'block_item',
    );

    /**
     * Validate a single conversion finding against the contract.
     *
     * @param array<string, mixed> $finding
     */
    public static function assertFinding(array $finding, string $label = 'conversion finding'): void
    {
        if ( '' === self::findingCode($finding) ) {
            throw new InvalidArgumentException(sprintf('%s is missing a non-empty "code"/"diagnostic_code" identifier.', ucfirst($label)));
        }

        // Optional fields may be carried as an explicit `null` placeholder: the
        // flat diagnostics projection emits a fixed key set with `?? null` for
        // fields that do not apply to a given finding, so `null` is a legitimate
        // emitted value and is tolerated wherever a typed value is also allowed.
        if ( array_key_exists('severity', $finding) && null !== $finding['severity'] && ! in_array($finding['severity'], self::SEVERITIES, true) ) {
            throw new InvalidArgumentException(sprintf('%s has an unsupported severity.', ucfirst($label)));
        }

        foreach ( self::STRING_FIELDS as $field ) {
            if ( array_key_exists($field, $finding) && null !== $finding[$field] && ! is_string($finding[$field]) ) {
                throw new InvalidArgumentException(sprintf('%s field "%s" must be a string.', ucfirst($label), $field));
            }
        }

        foreach ( self::ARRAY_FIELDS as $field ) {
            if ( array_key_exists($field, $finding) && null !== $finding[$field] && ! is_array($finding[$field]) ) {
                throw new InvalidArgumentException(sprintf('%s field "%s" must be an array.', ucfirst($label), $field));
            }
        }

        // `observed_block` is the one field producers emit as either a string
        // ("none") or an array (the observed block payload).
        if ( array_key_exists('observed_block', $finding) && null !== $finding['observed_block'] && ! is_string($finding['observed_block']) && ! is_array($finding['observed_block']) ) {
            throw new InvalidArgumentException(sprintf('%s field "observed_block" must be a string or an array.', ucfirst($label)));
        }
    }

    /**
     * Validate every entry in a list of conversion findings.
     *
     * @param array<int, mixed> $findings
     */
    public static function assertFindings(array $findings, string $label = 'conversion findings'): void
    {
        if ( array_values($findings) !== $findings ) {
            throw new InvalidArgumentException(sprintf('%s must be a list.', ucfirst($label)));
        }

        foreach ( $findings as $index => $finding ) {
            if ( ! is_array($finding) ) {
                throw new InvalidArgumentException(sprintf('%s.%d must be an object.', $label, $index));
            }

            self::assertFinding($finding, sprintf('%s.%d', $label, $index));
        }
    }

    /**
     * Resolve a finding's stable identifier, honoring the `code` /
     * `diagnostic_code` producer split. Returns '' when neither is a non-empty
     * string.
     *
     * @param array<string, mixed> $finding
     */
    public static function findingCode(array $finding): string
    {
        foreach ( self::CODE_KEYS as $key ) {
            if ( is_string($finding[$key] ?? null) && '' !== trim($finding[$key]) ) {
                return (string) $finding[$key];
            }
        }

        return '';
    }

    /**
     * Whether a value is a conversion finding that satisfies the contract.
     * Tolerant predicate for walking heterogeneous diagnostic collections.
     */
    public static function isFinding(mixed $finding): bool
    {
        if ( ! is_array($finding) ) {
            return false;
        }

        try {
            self::assertFinding($finding);
        } catch ( InvalidArgumentException ) {
            return false;
        }

        return true;
    }
}
