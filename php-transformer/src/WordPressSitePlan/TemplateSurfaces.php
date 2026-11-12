<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use InvalidArgumentException;

/**
 * The template surfaces a site plan declares, and the source documents they
 * are proven against.
 *
 * A document can declare that it renders a theme template surface (a role
 * such as `archive` or `404`, and a slug). Documents that declare the same
 * surface are its variants: they must share block markup, and the first by
 * source path is selected. Every variant's provenance must name its own
 * source, so each surface can be checked against the plan's source document
 * catalog.
 */
final class TemplateSurfaces
{
    /** @param array<int,array<string,mixed>> $documents @return array<int,array<string,mixed>> */
    public static function select(array $documents): array
    {
        $groups = array();
        foreach ($documents as $document) if (is_array($document['template_surface'] ?? null)) $groups[$document['template_surface']['role'] . "\0" . $document['template_surface']['slug']][] = $document;
        $surfaces = array();
        foreach ($groups as $key => $candidates) {
            $metadataCandidates = array_values(array_filter($candidates, static fn(array $candidate): bool => 'artifact_metadata' === ($candidate['template_surface']['declaration_provenance']['kind'] ?? null)));
            if (array() !== $metadataCandidates) $candidates = $metadataCandidates;
            usort($candidates, static fn(array $a, array $b): int => strcmp((string) $a['source_path'], (string) $b['source_path']));
            $hashes = array_unique(array_map(static fn(array $document): string => hash('sha256', (string) ($document['block_markup'] ?? '')), $candidates));
            if (count($hashes) > 1) throw new ValidationException('Declared template surface variants have different block markup.', array('source_path' => (string) $candidates[0]['source_path'], 'document_kind' => 'template_surface', 'declaration_kind' => 'template_surface', 'reason' => 'template_surface_ambiguous', 'fields' => array('surface' => str_replace("\0", ':', $key), 'candidate_count' => count($candidates))));
            $selected = $candidates[0];
            $provenance = $selected['provenance'] ?? null;
            if (!self::validSourceProvenance($provenance, (string) $selected['source_path'])) throw new ValidationException('A declared template surface source provenance is detached from its selected source.', array('source_path' => (string) $selected['source_path'], 'document_kind' => 'template_surface', 'declaration_kind' => 'template_surface', 'reason' => 'invalid_template_surface_provenance'));
            $variants = array();
            foreach ($candidates as $candidate) {
                $candidateProvenance = $candidate['provenance'] ?? null;
                if (!self::validSourceProvenance($candidateProvenance, (string) $candidate['source_path'])) throw new ValidationException('A declared template surface variant provenance is detached from its source.', array('source_path' => (string) $candidate['source_path'], 'document_kind' => 'template_surface', 'declaration_kind' => 'template_surface', 'reason' => 'invalid_template_surface_variant'));
                $variants[] = array('source_path' => $candidate['source_path'], 'source_hash' => $candidateProvenance['hash'], 'source_provenance' => $candidateProvenance);
            }
            $selected['template_surface']['source_variants'] = $variants;
            $selected['template_surface']['selected_source_path'] = $selected['source_path'];
            $selected['template_surface']['selected_source_hash'] = $provenance['hash'];
            $selected['template_surface']['source_provenance'] = $provenance;
            $surfaces[] = $selected;
        }
        return $surfaces;
    }

    /** @param mixed $documents @return array<int,array<string,mixed>> */
    public static function sourceCatalog(mixed $documents): array
    {
        if (!is_array($documents)) throw new InvalidArgumentException('Compiled site documents must be an array.');
        $catalog = array();
        foreach ($documents as $document) {
            $sourcePath = is_array($document) && is_string($document['source_path'] ?? null) ? $document['source_path'] : '';
            $provenance = is_array($document) ? ($document['provenance'] ?? null) : null;
            if (!WordPressSitePlan::safePath($sourcePath) || !self::validSourceProvenance($provenance, $sourcePath)) throw new InvalidArgumentException('Compiled site source document catalog entry is invalid.');
            $catalog[] = array('source_path' => $sourcePath, 'source_hash' => $provenance['hash'], 'source_provenance' => $provenance);
        }
        usort($catalog, static fn(array $left, array $right): int => strcmp($left['source_path'], $right['source_path']));
        return $catalog;
    }

    /** @param array<string,mixed> $surface */
    public static function validDeclaration(array $surface, string $sourcePath): bool
    {
        $identifier = static fn(mixed $value): bool => is_string($value) && 0 < strlen($value) && strlen($value) <= 128 && 1 === preg_match('/^[a-z0-9][a-z0-9._:-]*$/', $value);
        $provenance = $surface['declaration_provenance'] ?? null;
        return 'blocks-engine/template-surface/v1' === ($surface['schema'] ?? null) && in_array($surface['role'] ?? null, array('404', 'archive', 'attachment', 'author', 'category', 'date', 'home', 'search', 'single', 'tag', 'taxonomy'), true) && $identifier($surface['slug'] ?? null) && ($surface['logical_surface_id'] ?? null) === $surface['role'] . ':' . $surface['slug'] && $identifier($surface['responsive_variant_id'] ?? null) && is_array($provenance) && 'blocks-engine/template-surface-provenance/v1' === ($provenance['schema'] ?? null) && in_array($provenance['kind'] ?? null, array('artifact_metadata', 'html_attributes', 'schema_org'), true) && $sourcePath === ($provenance['source_path'] ?? null);
    }

    /** @param array<string,mixed> $surface */
    public static function valid(array $surface, mixed $sourcePath, array $sourceCatalog): bool
    {
        if (!is_string($sourcePath) || !self::validDeclaration($surface, $sourcePath) || !is_array($surface['source_provenance'] ?? null) || !self::validSourceProvenance($surface['source_provenance'], $sourcePath) || !is_array($surface['source_variants'] ?? null) || !array_is_list($surface['source_variants']) || array() === $surface['source_variants'] || !is_string($surface['selected_source_path'] ?? null) || $sourcePath !== $surface['selected_source_path'] || !is_string($surface['selected_source_hash'] ?? null) || $surface['selected_source_hash'] !== $surface['source_provenance']['hash']) return false;
        $paths = array(); $selected = null; foreach ($surface['source_variants'] as $variant) { if (!is_array($variant) || !is_string($variant['source_path'] ?? null) || !WordPressSitePlan::safePath($variant['source_path']) || isset($paths[$variant['source_path']]) || !WordPressSitePlan::hash($variant['source_hash'] ?? null) || !self::validSourceProvenance($variant['source_provenance'] ?? null, $variant['source_path']) || $variant['source_hash'] !== $variant['source_provenance']['hash'] || !isset($sourceCatalog[$variant['source_path']]) || RuntimeDeclarations::canonicalJson($sourceCatalog[$variant['source_path']]) !== RuntimeDeclarations::canonicalJson($variant)) return false; $paths[$variant['source_path']] = true; if ($sourcePath === $variant['source_path']) $selected = $variant; }
        $ordered = array_keys($paths); $sorted = $ordered; sort($sorted, SORT_STRING);
        return is_array($selected) && $selected['source_hash'] === $surface['selected_source_hash'] && RuntimeDeclarations::canonicalJson($selected['source_provenance']) === RuntimeDeclarations::canonicalJson($surface['source_provenance']) && $ordered === $sorted;
    }

    /** @param array<string,mixed>|mixed $provenance */
    private static function validSourceProvenance(mixed $provenance, string $sourcePath): bool
    {
        return is_array($provenance) && $sourcePath === ($provenance['source_path'] ?? null) && is_string($provenance['source'] ?? null) && '' !== $provenance['source'] && WordPressSitePlan::hash($provenance['hash'] ?? null);
    }

    /** @param array<string,mixed> $source @return array<string,array<string,mixed>> */
    public static function planSourceCatalog(array $source): array
    {
        if (!isset($source['source_documents'])) return array();
        $catalog = array();
        foreach ($source['source_documents'] as $row) {
            if (!is_array($row) || !is_string($row['source_path'] ?? null) || !WordPressSitePlan::safePath($row['source_path']) || isset($catalog[$row['source_path']]) || !WordPressSitePlan::hash($row['source_hash'] ?? null) || !self::validSourceProvenance($row['source_provenance'] ?? null, $row['source_path']) || $row['source_hash'] !== $row['source_provenance']['hash']) throw new InvalidArgumentException('WordPress site plan source document catalog is invalid.');
            $catalog[$row['source_path']] = $row;
        }
        $paths = array_keys($catalog); $sorted = $paths; sort($sorted, SORT_STRING); if ($paths !== $sorted) throw new InvalidArgumentException('WordPress site plan source document catalog must be sorted.');
        return $catalog;
    }
}
