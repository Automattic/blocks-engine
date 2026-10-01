<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use DOMDocument;
use DOMElement;
use DOMXPath;

/** Projects verified portable collection annotations, retaining the localized authoring tree. */
final class CapturedCollectionFilterProjector
{
    public function project(array $files): array
    {
        $report = null;
        $receipt = null;
        foreach ($files as $file) {
            if (!is_string($file['content'] ?? null) || strlen($file['content']) > 2097152) continue;
            if ('interaction-states.json' === ($file['path'] ?? '')) $report = json_decode($file['content'], true);
            if ('capture-receipt.json' === ($file['path'] ?? '')) $receipt = json_decode($file['content'], true);
        }
        if ('data-liberation/captured-interactions/v1' !== ($report['schema'] ?? '') || 'data-liberation/capture-receipt/v1' !== ($receipt['schema'] ?? '')) return $files;
        $routes = array();
        foreach ($receipt['routes'] ?? array() as $route) $routes[rtrim($route['url'] ?? '', '/')] = $route['path'] ?? '';
        if (count($report['pages'] ?? array()) > 128) return $files;
        foreach ($report['pages'] ?? array() as $page) {
            if (!is_array($page) || !is_array($page['states'] ?? null)) continue;
            $states = array_values(array_filter($page['states'], static fn($state): bool => is_array($state) && 'typed-search' === ($state['kind'] ?? '') && 'captured' === ($state['status'] ?? '') && is_array($state['collectionFilter'] ?? null)));
            if (array() === $states) continue;
            $path = $routes[rtrim($page['sourceUrl'] ?? '', '/')] ?? '';
            foreach ($files as &$file) {
                if ($path !== ($file['path'] ?? '') || ! str_ends_with($path, '.html')) continue;
                $previous = libxml_use_internal_errors(true);
                $document = new DOMDocument('1.0', 'UTF-8');
                $document->loadHTML('<?xml encoding="UTF-8">' . $file['content'], LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
                $xpath = new DOMXPath($document);
                $key = 0;
                foreach ($states as $state) {
                    $evidence = $state['collectionFilter'] ?? array();
                    if (!is_array($evidence['items'] ?? null) || !is_array($evidence['categories'] ?? null) || !is_string($evidence['target']['selector'] ?? null)) continue;
                    foreach ($evidence['items'] as $item) {
                        if (!is_array($item) || !is_string($item['key'] ?? null) || !is_array($item['categories'] ?? null)) continue 2;
                    }
                    foreach ($evidence['categories'] as $category) {
                        if (!is_array($category) || !is_string($category['activeHtml'] ?? null) || '' === $category['activeHtml'] || !is_string($category['inactiveHtml'] ?? null) || '' === $category['inactiveHtml']) continue 2;
                    }
                    if ('typed-search' !== ($state['kind'] ?? '') || 'captured' !== ($state['status'] ?? '') || 'verified' !== ($evidence['replay'] ?? '') || 'verified' !== ($evidence['restoration'] ?? '') || 'normalized-text-includes' !== ($evidence['predicate'] ?? '') || 'blocked' !== ($evidence['network']['dataRequests'] ?? '') || empty($evidence['items']) || count($evidence['items']) > 100 || empty($evidence['categories']) || count($evidence['categories']) > 32 || !is_int($evidence['initialCategory'] ?? null) || !isset($evidence['categories'][$evidence['initialCategory']]) || strlen(json_encode($evidence)) > 524288) continue;
                    $id = (string) $key++;
                    $targets = $xpath->query('//*[@data-dla-collection="' . $id . '"]');
                    $fields = $xpath->query('//*[@data-dla-collection-field="' . $id . '"]');
                    $controls = $xpath->query('//*[@data-dla-collection-category-control="' . $id . '"]');
                    $empty = $xpath->query('//*[@data-dla-collection-empty="' . $id . '"]');
                    if (1 !== $targets->length || 1 !== $fields->length || 1 !== $empty->length || count($evidence['categories']) !== $controls->length) continue;
                    $target = $targets->item(0);
                    $items = $xpath->query('./*[@data-dla-collection-item]', $target);
                    if (count($evidence['items']) !== $items->length) continue;
                    foreach ($items as $index => $item) {
                        if ($item->getAttribute('data-dla-collection-item') !== (string) $evidence['items'][$index]['key'] || json_decode($item->getAttribute('data-dla-collection-members'), true) !== $evidence['items'][$index]['categories']) continue 2;
                    }
                    $nodes = array_merge(array($target, $fields->item(0), $empty->item(0)), iterator_to_array($controls));
                    $root = $target->parentNode;
                    while ($root instanceof DOMElement) {
                        foreach ($nodes as $node) {
                            for ($ancestor = $node; $ancestor && ! $ancestor->isSameNode($root); $ancestor = $ancestor->parentNode) {}
                            if (null === $ancestor) { $root = $root->parentNode; continue 2; }
                        }
                        break;
                    }
                    if (! $root instanceof DOMElement || in_array(strtolower($root->tagName), array('body', 'html'), true)) continue;
                    $categories = array();
                    foreach ($evidence['categories'] as $category) {
                        $shapes = array();
                        foreach (array('active', 'inactive') as $shape) {
                            $fragment = new DOMDocument();
                            $previous = libxml_use_internal_errors(true);
                            $fragment->loadHTML('<?xml encoding="UTF-8">' . $category[$shape . 'Html'], LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
                            libxml_clear_errors(); libxml_use_internal_errors($previous);
                            $node = $fragment->documentElement;
                            $shapes[$shape] = array();
                            foreach (array('class', 'style', 'aria-selected', 'data-state') as $name) $shapes[$shape][$name] = $node->hasAttribute($name) ? $node->getAttribute($name) : null;
                        }
                        $categories[] = $shapes;
                    }
                    $root->setAttribute('data-blocks-engine-collection', json_encode(array('initialCategory' => $evidence['initialCategory'], 'memberships' => array_column($evidence['items'], 'categories'), 'categories' => $categories), JSON_THROW_ON_ERROR));
                    $target->setAttribute('data-blocks-engine-collection-target', 'true');
                    $target->setAttribute('data-blocks-engine-collection-projected-selector', $evidence['target']['selector']);
                    $fields->item(0)->setAttribute('data-blocks-engine-collection-control', 'field');
                    foreach ($controls as $control) $control->setAttribute('data-blocks-engine-collection-control', 'category');
                    $empty->item(0)->setAttribute('data-blocks-engine-collection-control', 'empty');
                }
                $file['content'] = preg_replace('/^<\?xml encoding="UTF-8">/', '', $document->saveHTML());
                $file['bytes'] = strlen($file['content']);
            }
            unset($file);
        }
        return $files;
    }
}
