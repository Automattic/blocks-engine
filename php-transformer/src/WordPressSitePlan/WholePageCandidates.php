<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeEntityManifest;
use InvalidArgumentException;

/**
 * Whole-page candidates: opt-in producer evidence that one entity row stands
 * for one canonical source page (`blocks-engine/whole-page-candidate/v1`).
 *
 * The plan projects each candidate onto its page as a hint to consumers. It is
 * never an instruction to omit the page or claim its route.
 */
final class WholePageCandidates
{
    /**
     * Project opt-in entity evidence onto its canonical page. This is a hint to
     * consumers, never an instruction to omit create_page or claim a route.
     * Works for both inline entities and content-addressed entity manifests.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array<string,mixed>> $declarations
     * @param array<int,array<string,mixed>> $records
     * @return array<int,array<string,mixed>>
     */
    public static function attach(array $pages, array $declarations, array $records): array
    {
        $bySource = array_column($pages, null, 'source_path');
        $candidates = array();
        foreach ($declarations as $declaration) {
            $payload = $declaration['payload'] ?? array();
            $entities = RuntimeEntityManifest::SCHEMA === ($payload['schema'] ?? null)
                ? RuntimeEntityManifest::resolve($payload, $records) : ($payload['entities'] ?? array());
            if (!is_array($entities)) continue;
            foreach ($entities as $entity) {
                if (!is_array($entity) || !array_key_exists('whole_page_candidate', $entity)) continue;
                $source = $entity['source_path'] ?? null;
                $page = is_string($source) ? ($bySource[$source] ?? null) : null;
                $id = $entity['id'] ?? null;
                $route = $entity['source_route'] ?? null;
                if ('blocks-engine/whole-page-candidate/v1' !== $entity['whole_page_candidate']
                    || 'entity_collection' !== ($declaration['kind'] ?? null)
                    || !is_string($id) || '' === $id || strlen($id) > 256
                    || !is_array($page) || !empty($page['synthetic']) || 'page' !== ($page['post_type'] ?? null)
                    || !is_string($route) || $route !== $page['route']['path']) {
                    throw new InvalidArgumentException('Whole-page candidate must name one canonical source page and producer entity row.');
                }
                // One fixed-size candidate per canonical page: the page set bounds the candidate set.
                if (isset($candidates[$source])) throw new InvalidArgumentException('Whole-page candidates must be unique by source page.');
                $candidates[$source][] = array(
                    'schema' => 'blocks-engine/whole-page-candidate/v1',
                    'source_path' => $source,
                    'source_route' => $page['route']['path'],
                    'page_reconciliation_identity' => $page['reconciliation_identity'],
                    'declaration_reconciliation_identity' => $declaration['reconciliation_identity'],
                    'entity_id' => $id,
                );
            }
        }
        foreach ($pages as &$page) {
            if (!isset($candidates[$page['source_path']])) continue;
            $page['whole_page_candidates'] = $candidates[$page['source_path']];
        }
        unset($page);
        return $pages;
    }
}
