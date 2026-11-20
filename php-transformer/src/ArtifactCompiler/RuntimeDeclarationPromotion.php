<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;

/**
 * Promotes provider-materializable conversion findings into the artifact's
 * runtime declarations. Explicit caller declarations stay authoritative;
 * detected products enrich caller catalogs; other entities fill missing collections.
 */
final class RuntimeDeclarationPromotion
{
    /**
     * Promote informational cart evidence without adding importer fallbacks.
     * The declaration stays optional: a consumer without a cart-link adapter
     * skips it, so the page imports unchanged.
     */
    public function cartLinkRuntimeDeclarations(array $declarations, array $diagnostics, string $entryPath): array
    {
        foreach ( $declarations as $declaration ) {
            if ( 'entity_collection' === ($declaration['kind'] ?? null) && 'cart_links' === ($declaration['type'] ?? null) ) return $declarations;
        }
        $entities = array();
        foreach ( $diagnostics as $finding ) {
            if ( 'commerce_cart_link_detected' !== ($finding['code'] ?? null) || empty($finding['binding']['search_block_markup']) ) continue;
            $source = $finding['binding']['source_path'] ?? $finding['source_path'];
            $entity = array('source_path' => $source, 'selector' => $finding['selector'], 'bindings' => array(array_merge($finding['binding'], array('source_path' => $source))));
            if ( isset($finding['count_badge']) ) $entity['count_badge'] = $finding['count_badge'];
            $entities[$source . "\n" . $finding['selector']] = $entity;
        }
        if ( array() !== $entities ) {
            $declarations[] = array('kind' => 'entity_collection', 'type' => 'cart_links', 'source_path' => $entryPath, 'payload' => array('schema' => 'generic/cart-links/v1', 'entities' => array_values($entities)));
        }
        return $declarations;
    }

    /**
     * Promote provider-materializable findings into the canonical runtime contract.
     *
     * Caller product commerce data remains authoritative while detection supplies
     * page evidence. Other collections fill only missing declarations.
     *
     * @param array<int,array<string,mixed>> $declarations
     * @param array<int,array<string,mixed>> $fallbacks
     * @param \Closure(): bool $sourceHasPurchaseAffordance Whether the source offers a purchase
     *     affordance; evaluated only when product declarations are considered.
     * @return array<int,array<string,mixed>>
     */
    public function runtimeDeclarationsFromFallbacks(array $declarations, array $fallbacks, string $entryPath, array $files, \Closure $sourceHasPurchaseAffordance, array &$diagnostics = array(), array &$runtimeEntityRecords = array()): array
    {
        if ( '' === $entryPath ) return $declarations;
        foreach ( $declarations as $declaration ) foreach ( $declaration['payload']['entities'] ?? array() as $entity ) if ( is_array($entity) && array_key_exists('superseded_scripts', $entity) ) throw new \InvalidArgumentException('Caller runtime declarations cannot provide compiler-reserved script supersession proofs.');

        $keys = array();
        foreach ( $declarations as $declaration ) {
            if ( ! is_array($declaration) ) continue;
            $name = $declaration['type'] ?? $declaration['capability'] ?? null;
            if ( is_string($declaration['kind'] ?? null) && is_string($name) ) $keys[$declaration['kind'] . ':' . $name] = true;
        }

        $collector = new RuntimeEntityCollector($files, $entryPath, $sourceHasPurchaseAffordance, $diagnostics);
        $collections = $collector->collections($fallbacks);
        $diagnostics = $collector->diagnostics;
        foreach ( $collections as $collection ) {
            $capability = $collection['capability'];
            $requires = $collection['requires'] ?? array();
            if ( array() !== $requires && array() === array_filter($requires, static fn (string $type): bool => isset($keys['entity_collection:' . $type])) ) continue;
            $entityKey = 'entity_collection:' . $collection['type'];
            foreach ( $collection['aliases'] as $alias ) if ( isset($keys['entity_collection:' . $alias]) ) { $entityKey = 'entity_collection:' . $alias; break; }
            $catalogDeclaration = null;
            if ('products' === $collection['type'] && array() !== $collection['entities']) {
                foreach ($declarations as $index => $declaration) {
                    if ('entity_collection' !== ($declaration['kind'] ?? null) || 'products' !== ($declaration['type'] ?? null) || 'generic/products/v1' !== ($declaration['payload']['schema'] ?? null)) continue;
                    $catalogDeclaration = $declaration;
                    $collection['entities'] = $this->mergeCatalogProducts($declaration['payload']['entities'], $collection['entities']);
                    unset($declarations[$index], $keys[$entityKey]);
                    $declarations = array_values($declarations);
                    break;
                }
            }
            $sourcePath = $catalogDeclaration['source_path'] ?? $entryPath;
            if ( array() !== $collection['entities'] && ! isset($keys[$entityKey]) ) {
                $budget = RuntimeEntityManifest::declareCollection($declarations, $collection['type'], $collection['schema'], $collection['entities'], $sourcePath, isset($keys['dependency:' . $capability]) ? null : $capability);
                array_push($diagnostics, ...$budget['diagnostics']);
                if ( null === $budget['payload'] ) continue;
                $runtimeEntityRecords = array_merge($runtimeEntityRecords, $budget['records']);
                $promoted = $catalogDeclaration ?? array('kind' => 'entity_collection', 'type' => $collection['type'], 'source_path' => $sourcePath);
                $promoted['payload'] = $budget['payload'];
                unset($promoted['payload_hash'], $promoted['content_hash']);
                $declarations[] = $promoted;
                $keys[$entityKey] = true;
            }
            $dependencyKey = 'dependency:' . $capability;
            if ( isset($keys[$entityKey]) && ! isset($keys[$dependencyKey]) ) {
                $declarations[] = array('kind' => 'dependency', 'capability' => $capability, 'source_path' => $entryPath, 'required_for' => array($entityKey));
                $keys[$dependencyKey] = true;
            }
        }
        return RuntimeDeclarations::normalizeList($declarations);
    }

    /** Catalog commerce wins; detected rows retain all compiled page evidence. */
    private function mergeCatalogProducts(array $catalog, array $detected): array
    {
        $products = array_column($detected, null, 'slug');
        $merged = array();
        foreach ($catalog as $row) {
            $slug = $row['slug'];
            if (isset($products[$slug])) {
                $product = $products[$slug];
                foreach (array('name', 'regular_price', 'sale_price', 'description', 'sku', 'image', 'images', 'tags', 'variants', 'stock_status', 'category') as $field) {
                    if (array_key_exists($field, $row)) $product[$field] = $row[$field];
                }
                $merged[] = $product;
                unset($products[$slug]);
            } else {
                $merged[] = $row;
            }
        }
        return array_merge($merged, array_values($products));
    }
}
