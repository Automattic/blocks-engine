<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ResponsiveDocumentVariants;

/**
 * Declares the persisted responsive correspondence contract for composed
 * responsive documents.
 *
 * A correspondence is only ever declared when BOTH proofs hold:
 *  - stable source provenance: the composer marked exactly one eligible
 *    text/link element on each side of one declared variant pair with a
 *    deterministic token class derived from the source id;
 *  - responsive-document structure: after transformation the token survives
 *    on exactly one block under the default variant root and one block under
 *    the matching variant root, as the same block type with a supported
 *    text/link attribute.
 *
 * Equal text, labels, order, or visual similarity are never consulted, and
 * blocks without a declared counterpart stay fully independent.
 */
final class ResponsiveCorrespondence
{
    public const SCHEMA = 'blocks-engine/responsive-counterpart-contracts/v1';
    public const TOKEN_CLASS_PREFIX = ResponsiveDocumentVariants::CORRESPONDENCE_CLASS_PREFIX;
    private const VARIANT_CLASS_PREFIX = 'site-document-variant-';
    private const MAX_DECLINED = 50;

    /** Block types with a bounded, compatible content attribute a counterpart accepts. */
    private const SUPPORTED_ATTRIBUTES = array(
        'core/paragraph' => array('kind' => 'text', 'attribute' => 'content'),
        'core/heading' => array('kind' => 'text', 'attribute' => 'content'),
        'core/button' => array('kind' => 'link', 'attribute' => 'text'),
    );

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $sourceProvenance
     * @return array<string, mixed> Empty when no correspondence tokens are present.
     */
    public function declare(array $blocks, array $sourceProvenance): array
    {
        $byToken = array();
        $this->collect($blocks, 'blocks', null, $byToken, $this->provenanceByPath($sourceProvenance));
        if (array() === $byToken) {
            return array();
        }

        $contracts = array();
        $declined = array();
        ksort($byToken, SORT_STRING);
        foreach ($byToken as $token => $entries) {
            $declaration = $this->pair((string) $token, $entries);
            if (array() !== $declaration) {
                $contracts[] = $declaration;
            } else {
                $declined[] = $this->declined((string) $token, $entries);
            }
        }
        if (array() === $contracts && array() === $declined) {
            return array();
        }

        $report = array(
            'schema' => self::SCHEMA,
            'pairing_rule' => 'stable_source_id_and_variant_structure_only',
            'counterparts' => $contracts,
            'metrics' => array(
                'declared_count' => count($contracts),
                'declined_count' => count($declined),
            ),
        );
        if (array() !== $declined) {
            $report['declined'] = array_slice($declined, 0, self::MAX_DECLINED);
        }
        if (array() !== $contracts) {
            $report['editor_module'] = (new ResponsiveCounterpartEditorModule())->module();
        }
        return $report;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<string, mixed>
     */
    private function pair(string $token, array $entries): array
    {
        if (2 !== count($entries)) {
            return array();
        }
        $defaultIndex = 'default' === $entries[0]['variant'] ? 0 : ('default' === $entries[1]['variant'] ? 1 : -1);
        if (-1 === $defaultIndex) {
            return array();
        }
        $default = $entries[$defaultIndex];
        $variant = $entries[1 - $defaultIndex];
        if ('default' === $variant['variant']) {
            return array();
        }
        if ($default['name'] !== $variant['name'] || !isset(self::SUPPORTED_ATTRIBUTES[$default['name']])) {
            return array();
        }
        $supported = self::SUPPORTED_ATTRIBUTES[$default['name']];
        return array(
            'token' => self::TOKEN_CLASS_PREFIX . $token,
            'kind' => $supported['kind'],
            'attribute' => $supported['attribute'],
            'source_id' => (string) ($default['provenance']['source_attributes']['id'] ?? ''),
            'variants' => array(
                'default' => $this->side($default),
                $variant['variant'] => $this->side($variant),
            ),
        );
    }

    /** @param array<string, mixed> $entry @return array<string, mixed> */
    private function side(array $entry): array
    {
        return array(
            'block_path' => $entry['path'],
            'block_name' => $entry['name'],
            'anchor' => (string) ($entry['anchor'] ?? ''),
            'source_selector' => (string) ($entry['provenance']['selector'] ?? ''),
        );
    }

    /** @param array<int, array<string, mixed>> $entries @return array<string, mixed> */
    private function declined(string $token, array $entries): array
    {
        return array(
            'token' => self::TOKEN_CLASS_PREFIX . $token,
            'reason' => 2 !== count($entries) ? 'single_structure_occurrence' : 'unsupported_variant_structure',
            'variants' => array_values(array_map(static fn(array $entry): string => (string) $entry['variant'], $entries)),
            'block_names' => array_values(array_map(static fn(array $entry): string => (string) $entry['name'], $entries)),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<string, array<string, mixed>> $byToken
     * @param array<string, array<string, mixed>> $provenanceByPath
     */
    private function collect(array $blocks, string $path, ?string $variant, array &$byToken, array $provenanceByPath): void
    {
        foreach ($blocks as $index => $block) {
            if (!is_array($block)) {
                continue;
            }
            $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : '';
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
            $blockPath = $path . '.' . $index;
            $blockVariant = $this->variantClassContext((string) ($attrs['className'] ?? '')) ?? $variant;
            foreach ($this->tokenClasses((string) ($attrs['className'] ?? '')) as $token) {
                $byToken[$token][] = array(
                    'path' => $blockPath,
                    'name' => $name,
                    'variant' => $blockVariant ?? 'unresolved',
                    'anchor' => is_string($attrs['anchor'] ?? null) ? $attrs['anchor'] : '',
                    'provenance' => $provenanceByPath[$blockPath] ?? array(),
                );
            }
            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $this->collect($block['innerBlocks'], $blockPath . '.innerBlocks', $blockVariant, $byToken, $provenanceByPath);
            }
        }
    }

    /** @return array<int, string> */
    private function tokenClasses(string $className): array
    {
        $tokens = array();
        foreach (preg_split('/\s+/', trim($className)) ?: array() as $class) {
            if (preg_match('/^' . preg_quote(self::TOKEN_CLASS_PREFIX, '/') . '([a-f0-9]{12})$/', (string) $class, $match)) {
                $tokens[] = $match[1];
            }
        }
        return $tokens;
    }

    private function variantClassContext(string $className): ?string
    {
        foreach (preg_split('/\s+/', trim($className)) ?: array() as $class) {
            if (preg_match('/^' . preg_quote(self::VARIANT_CLASS_PREFIX, '/') . '([a-z][a-z0-9_-]{0,31})$/', (string) $class, $match)) {
                return $match[1];
            }
        }
        return null;
    }

    /** @param array<int, array<string, mixed>> $sourceProvenance @return array<string, array<string, mixed>> */
    private function provenanceByPath(array $sourceProvenance): array
    {
        $resolved = array();
        foreach ($sourceProvenance as $entry) {
            if (is_array($entry) && is_string($entry['block_path'] ?? null)) {
                $resolved[$entry['block_path']] = $entry;
            }
        }
        return $resolved;
    }
}
