<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMDocument;
use DOMElement;

/** Projects corroborated local predicates without replacing the editable tree. */
final class CapturedCollectionProjector
{
    public function project(array $files): array
    {
        $report = $receipt = null;
        $indices = array();
        foreach ($files as $index => $file) {
            $path = $file['path'] ?? '';
            if (!is_string($path)) continue;
            $indices[$path] = $index;
            if ('interaction-states.json' === basename($path)) $report = json_decode($file['content'] ?? '', true);
            if ('capture-receipt.json' === basename($path)) $receipt = json_decode($file['content'] ?? '', true);
        }
        if ('data-liberation/captured-interactions/v1' !== ($report['schema'] ?? null)
            || 'data-liberation/capture-receipt/v1' !== ($receipt['schema'] ?? null)
            || !is_array($report['pages'] ?? null) || !is_array($receipt['routes'] ?? null)) {
            return array('files' => $files, 'diagnostics' => array(), 'projected_count' => 0);
        }
        $routes = array();
        foreach ($receipt['routes'] ?? array() as $route) {
            if (is_array($route) && is_string($route['url'] ?? null) && is_string($route['path'] ?? null)) $routes[rtrim($route['url'], '/')] = $route['path'];
        }
        $count = 0;
        $diagnostics = array();
        foreach (array_slice($report['pages'] ?? array(), 0, 128) as $page) {
            if (!is_array($page) || !is_string($page['sourceUrl'] ?? null) || !is_array($page['states'] ?? null)) continue;
            $index = $indices[$routes[rtrim($page['sourceUrl'] ?? '', '/')] ?? ''] ?? null;
            if (!is_int($index) || !is_string($files[$index]['content'] ?? null)) continue;
            $document = null;
            $pageCount = 0;
            foreach (array_slice($page['states'] ?? array(), 0, 128) as $state) {
                if (!is_array($state)) continue;
                if ('typed-search' !== ($state['kind'] ?? null)) continue;
                $evidence = $state['collectionFilter'] ?? null;
                if ('captured' !== ($state['status'] ?? null) || !$this->verified($evidence)) {
                    $diagnostics[] = $this->diagnostic('Captured collection evidence is incomplete; native local filtering remains unproven.');
                    continue;
                }
                $document ??= $this->document($files[$index]['content']);
                $candidate = clone $document;
                if (!$this->annotate($candidate, $evidence, $files[$index]['path'])) {
                    $diagnostics[] = $this->diagnostic('Verified collection evidence did not match one bounded canonical source tree.');
                    continue;
                }
                $document = $candidate;
                ++$count;
                ++$pageCount;
            }
            if ($pageCount && $document instanceof DOMDocument) {
                // These portable shims are superseded only after the owning
                // native collection was established. Keep unrelated scripts.
                $portableRemaining = false;
                foreach ($document->getElementsByTagName('*') as $node) {
                    if ($node->hasAttribute('data-dla-collection') && !$node->hasAttribute('data-blocks-engine-collection-target')) $portableRemaining = true;
                }
                foreach ($portableRemaining ? array() : array('script' => 'data-dla-collection-runtime', 'style' => 'data-dla-collection-visibility') as $tag => $attribute) {
                    foreach (iterator_to_array($document->getElementsByTagName($tag)) as $node) {
                        if ($node instanceof DOMElement && $node->hasAttribute($attribute)) $node->parentNode?->removeChild($node);
                    }
                }
                $html = preg_replace('/^<\?xml encoding="UTF-8">/i', '', $document->saveHTML() ?: '');
                $files[$index]['content'] = $html;
                $files[$index]['bytes'] = strlen($html);
            }
        }
        return array('files' => $files, 'diagnostics' => $diagnostics, 'projected_count' => $count);
    }

    private function verified(mixed $evidence): bool
    {
        if (!is_array($evidence) || strlen(json_encode($evidence) ?: '') > 524288
            || 'verified' !== ($evidence['restoration'] ?? null) || 'verified' !== ($evidence['replay'] ?? null)
            || 'normalized-text-includes' !== ($evidence['predicate'] ?? null)
            || 'blocked' !== ($evidence['network']['dataRequests'] ?? null)
            || !is_string($evidence['field']['selector'] ?? null) || !is_string($evidence['target']['selector'] ?? null)
            || !is_string($evidence['emptyHtml'] ?? null) || !in_array($evidence['emptyPlacement'] ?? null, array('inside', 'after'), true)
            || '' !== ($evidence['field']['value'] ?? null)
            || !is_array($evidence['items'] ?? null) || count($evidence['items']) < 2 || count($evidence['items']) > 100
            || !is_array($evidence['categories'] ?? null) || count($evidence['categories']) < 2 || count($evidence['categories']) > 32
            || !is_int($evidence['initialCategory'] ?? null)
            || !isset($evidence['categories'][$evidence['initialCategory']])
            || !is_array($evidence['probes'] ?? null) || count($evidence['probes']) < 3) return false;
        $keys = array();
        foreach ($evidence['items'] as $item) {
            if (!is_array($item) || !is_string($item['key'] ?? null) || '' === $item['key'] || isset($keys[$item['key']])
                || !is_string($item['text'] ?? null) || '' === trim($item['text']) || !is_array($item['categories'] ?? null)
                || !in_array($evidence['initialCategory'], $item['categories'], true)) return false;
            foreach ($item['categories'] as $category) if (!is_int($category) || !isset($evidence['categories'][$category])) return false;
            $keys[$item['key']] = true;
        }
        foreach ($evidence['categories'] as $index => $category) {
            if ($index !== ($category['index'] ?? null) || !is_string($category['selector'] ?? null)
                || !is_string($category['activeHtml'] ?? null) || !is_string($category['inactiveHtml'] ?? null)) return false;
        }
        foreach ($evidence['probes'] as $probe) {
            if (!is_string($probe['query'] ?? null) || !is_int($probe['category'] ?? null)
                || !isset($evidence['categories'][$probe['category']]) || !is_array($probe['keys'] ?? null)) return false;
            $expected = array();
            foreach ($evidence['items'] as $item) {
                if (in_array($probe['category'], $item['categories'], true)
                    && str_contains(mb_strtolower($item['text']), mb_strtolower($probe['query']))) $expected[] = $item['key'];
            }
            if ($expected !== $probe['keys']) return false;
        }
        return true;
    }

    private function annotate(DOMDocument $document, array $evidence, string $path): bool
    {
        $field = $this->one($document, $evidence['field']['selector'] ?? '');
        $target = $this->one($document, $evidence['target']['selector'] ?? '');
        if (!$field || !$target || 'input' !== strtolower($field->tagName) || str_contains($field->getAttribute('style'), '!important')) return false;
        $controls = array();
        $states = array();
        foreach ($evidence['categories'] as $category) {
            $control = $this->one($document, $category['selector']);
            $active = $this->buttonAttributes($category['activeHtml']);
            $inactive = $this->buttonAttributes($category['inactiveHtml']);
            if (!$control || 'button' !== strtolower($control->tagName) || null === $active || null === $inactive) return false;
            if (str_contains($active['style'], '!important') || str_contains($inactive['style'], '!important')) return false;
            $controls[] = $control;
            $states[] = array('active' => $active, 'inactive' => $inactive);
        }
        $root = $target->parentNode;
        while ($root instanceof DOMElement && !$this->containsAll($root, array_merge(array($field, $target), $controls))) $root = $root->parentNode;
        if (!$root instanceof DOMElement || !in_array(strtolower($root->tagName), array('div', 'section', 'main', 'article'), true)
            || $root->hasAttribute('data-blocks-engine-collection-root') || str_contains($root->getAttribute('style'), '!important')) return false;
        $items = array_values(array_filter(iterator_to_array($target->childNodes), static fn ($node): bool => $node instanceof DOMElement && !$node->hasAttribute('data-dla-collection-empty')));
        if (count($items) !== count($evidence['items'])) return false;
        foreach ($items as $index => $node) {
            if ($this->text($node) !== $evidence['items'][$index]['text']) return false;
            if ($node->hasAttribute('data-dla-collection-item') && $node->getAttribute('data-dla-collection-item') !== $evidence['items'][$index]['key']) return false;
        }
        $identity = substr(hash('sha256', $path . "\n" . $evidence['target']['selector']), 0, 16);
        $members = array();
        foreach ($items as $index => $node) {
            $marker = 'blocks-engine-collection-item-' . $identity . '-' . $index;
            $node->setAttribute('class', SourceDom::mergeClassNames($node->getAttribute('class'), $marker));
            $members[] = array('marker' => $marker, 'categories' => $evidence['items'][$index]['categories']);
        }
        $root->setAttribute('data-blocks-engine-collection-root', json_encode(array('items' => $members, 'initialCategory' => $evidence['initialCategory']), JSON_THROW_ON_ERROR));
        $target->setAttribute('data-blocks-engine-collection-target', $identity);
        $field->setAttribute('data-blocks-engine-collection-field', 'true');
        foreach ($controls as $index => $control) {
            $control->setAttribute('data-blocks-engine-collection-choice', json_encode(array_merge(array('index' => $index, 'initial' => $index === $evidence['initialCategory']), $states[$index]), JSON_THROW_ON_ERROR));
        }
        $empty = null;
        foreach ($root->getElementsByTagName('*') as $node) {
            if ($node instanceof DOMElement && $node->hasAttribute('data-dla-collection-empty')) {
                if ($empty) return false;
                $empty = $node;
            }
        }
        if (!$empty) {
            $empty = $document->createElement('div');
            $fragment = $this->document('<div>' . $evidence['emptyHtml'] . '</div>');
            $body = $fragment->getElementsByTagName('body')->item(0);
            $container = $body?->firstChild;
            if ($container) foreach (iterator_to_array($container->childNodes) as $node) $empty->appendChild($document->importNode($node, true));
            if ('after' === ($evidence['emptyPlacement'] ?? 'inside')) $target->parentNode?->insertBefore($empty, $target->nextSibling);
            else $target->appendChild($empty);
        }
        $empty->setAttribute('data-blocks-engine-collection-empty', 'true');
        $empty->removeAttribute('hidden');
        return true;
    }

    private function buttonAttributes(string $html): ?array
    {
        $document = $this->document($html);
        $buttons = $document->getElementsByTagName('button');
        if (1 !== $buttons->length) return null;
        $button = $buttons->item(0);
        return array('className' => $button->getAttribute('class'), 'style' => $button->getAttribute('style'), 'selected' => $button->hasAttribute('aria-selected') ? $button->getAttribute('aria-selected') : null, 'dataState' => $button->hasAttribute('data-state') ? $button->getAttribute('data-state') : null);
    }

    private function containsAll(DOMElement $root, array $nodes): bool
    {
        foreach ($nodes as $node) {
            for ($parent = $node; $parent && !$parent->isSameNode($root); $parent = $parent->parentNode) {}
            if (!$parent) return false;
        }
        return true;
    }

    private function one(DOMDocument $document, string $selector): ?DOMElement
    {
        if (strlen($selector) > 2048 || '' === $selector) return null;
        $parsed = CssSelectorMatcher::parse($selector);
        $found = null;
        foreach ($document->getElementsByTagName('*') as $node) {
            if (!(CssSelectorMatcher::matches($node, $parsed)['matches'] ?? false)) continue;
            if ($found) return null;
            $found = $node;
        }
        return $found;
    }

    private function text(\DOMNode $node): string
    {
        $parts = array();
        $walk = static function (\DOMNode $node) use (&$walk, &$parts): void {
            if (XML_TEXT_NODE === $node->nodeType) $parts[] = $node->textContent;
            foreach ($node->childNodes as $child) $walk($child);
        };
        $walk($node);
        return trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)) ?? '');
    }

    private function document(string $html): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $document;
    }

    private function diagnostic(string $message): array
    {
        return array('code' => 'captured_collection_unproven', 'severity' => 'warning', 'message' => $message);
    }
}
