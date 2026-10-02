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
                $shims = $portableRemaining ? array() : array('script' => 'data-dla-collection-runtime', 'style' => 'data-dla-collection-visibility');
                if (!$portableRemaining && $this->localDisclosuresAreNative($document)) $shims['script-disclosure'] = 'data-dla-local-disclosure-runtime';
                foreach ($shims as $tag => $attribute) {
                    $tag = str_starts_with($tag, 'script') ? 'script' : $tag;
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
        if (!is_array($evidence) || strlen(json_encode($evidence) ?: '') > 524288) return false;
        if (array_key_exists('finiteBootstrap', $evidence)) return $this->verifiedFinite($evidence);
        return $this->verifiedWard($evidence);
    }

    private function verifiedWard(mixed $evidence): bool
    {
        if (!is_array($evidence)
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

    private function verifiedFinite(array $evidence): bool
    {
        $bootstrap = $evidence['finiteBootstrap'] ?? null;
        $network = $evidence['network'] ?? null;
        $order = is_array($bootstrap) ? ($bootstrap['order'] ?? null) : null;
        $probes = is_array($bootstrap) ? ($bootstrap['probes'] ?? null) : null;
        if (!is_array($bootstrap) || !is_array($network) || !is_array($order) || !is_array($probes)
            || 'verified' !== ($evidence['restoration'] ?? null) || 'verified' !== ($evidence['replay'] ?? null)
            || 'normalized-text-includes' !== ($evidence['predicate'] ?? null)
            || 'category-or-global-search' !== ($evidence['mode'] ?? null)
            || 'observed-response-replay' !== ($network['dataRequests'] ?? null)
            || 'intercepted-observed-responses' !== ($network['verification'] ?? null)
            || 'data-liberation/finite-bootstrap/v1' !== ($bootstrap['schema'] ?? null)
            || 'category-or-global-search' !== ($bootstrap['mode'] ?? null)
            || true !== ($bootstrap['queryIndependent'] ?? null)
            || 'declared-finite' !== ($bootstrap['completeness'] ?? null)
            || 'complete' !== ($bootstrap['coverage'] ?? null)
            || 'intercepted-observed-responses' !== ($bootstrap['verification'] ?? null)
            || true !== ($bootstrap['unmatchedProbeBlocked'] ?? null)
            || 'hidden' !== ($bootstrap['categoryControlsDuringSearch'] ?? null)
            || true !== ($bootstrap['emptyQueryRestoresCategory'] ?? null)
            || 'text-only' !== ($bootstrap['resources'] ?? null)
            || 'universal-query' !== ($order['proof'] ?? null)
            || !is_string($evidence['field']['selector'] ?? null) || '' !== ($evidence['field']['value'] ?? null)
            || !is_string($evidence['target']['selector'] ?? null)
            || !is_string($evidence['emptyHtml'] ?? null) || '' === trim($evidence['emptyHtml'])
            || !in_array($evidence['emptyPlacement'] ?? null, array('inside', 'after'), true)
            || !is_array($evidence['items'] ?? null) || count($evidence['items']) < 1 || count($evidence['items']) > 100
            || !is_array($evidence['categories'] ?? null) || count($evidence['categories']) < 2 || count($evidence['categories']) > 32
            || !is_int($evidence['initialCategory'] ?? null) || !isset($evidence['categories'][$evidence['initialCategory']])
            || !is_int($bootstrap['declaredCount'] ?? null) || !is_int($bootstrap['observedItemCount'] ?? null)
            || !is_int($bootstrap['replayedResponses'] ?? null) || $bootstrap['replayedResponses'] < 1
            || !is_int($bootstrap['blockedFollowUps'] ?? null) || !is_int($bootstrap['sourceFollowUpsBlocked'] ?? null)
            || $bootstrap['sourceFollowUpsBlocked'] < 0 || $bootstrap['blockedFollowUps'] <= $bootstrap['sourceFollowUpsBlocked']
            || !is_string($order['query'] ?? null) || '' === $order['query']
            || !is_array($order['keys'] ?? null) || !is_array($order['categoryKeys'] ?? null)
            || !is_bool($order['categoriesAgree'] ?? null)
            || !is_array($probes['global'] ?? null) || !is_array($probes['categories'] ?? null)
            || !is_array($evidence['probes'] ?? null)) return false;
        $depth = $evidence['itemDepth'] ?? 0;
        if (!is_int($depth) || $depth < 0 || $depth > 8) return false;
        $count = count($evidence['items']);
        if ($bootstrap['declaredCount'] !== $count || $bootstrap['observedItemCount'] !== $count) return false;
        if (!$this->finiteItems($evidence) || !$this->finiteCategories($evidence)) return false;
        $texts = array_column($evidence['items'], 'text');
        if ($this->sharedOrderQuery($texts) !== $order['query']) return false;
        if ($order['keys'] !== array_column($evidence['items'], 'key') || count($order['keys']) !== count(array_unique($order['keys']))) return false;
        if (!$this->finiteCategoryLists($evidence, $order['categoryKeys'])) return false;
        $agree = true;
        foreach ($order['categoryKeys'] as $keys) if (!$this->isOrderedSubsequence($order['keys'], $keys)) $agree = false;
        if ($order['categoriesAgree'] !== $agree) return false;
        if (!$this->finiteGlobalProbes($evidence, $probes['global'], $order) || !$this->finiteCategoryProbes($evidence, $probes['categories'], $order['categoryKeys'])) return false;
        $answers = $this->answersState($evidence['items']);
        if ($bootstrap['answers'] !== $answers || $bootstrap['answerOnly'] !== $this->answerOnlyState($evidence['items'], $answers)) return false;
        foreach ($evidence['items'] as $item) if ($this->hasResource((string) $item['html'])) return false;
        return true;
    }

    private function finiteItems(array $evidence): bool
    {
        $keys = array();
        foreach ($evidence['items'] as $item) {
            if (!is_array($item) || !is_string($item['key'] ?? null) || '' === $item['key'] || isset($keys[$item['key']])
                || !is_string($item['text'] ?? null) || '' === $this->normalize($item['text']) || $item['text'] !== $this->normalize($item['text'])
                || !is_string($item['html'] ?? null) || !is_array($item['categories'] ?? null) || array() === $item['categories']) return false;
            foreach ($item['categories'] as $category) if (!is_int($category) || !isset($evidence['categories'][$category])) return false;
            $keys[$item['key']] = $item['categories'];
        }
        return count($keys) === count($evidence['items']);
    }

    private function finiteCategories(array $evidence): bool
    {
        foreach ($evidence['categories'] as $index => $category) {
            if (!is_array($category) || $index !== ($category['index'] ?? null) || !is_string($category['selector'] ?? null)
                || !is_string($category['label'] ?? null) || !is_string($category['activeHtml'] ?? null) || !is_string($category['inactiveHtml'] ?? null)) return false;
        }
        return true;
    }

    private function finiteCategoryLists(array $evidence, array $lists): bool
    {
        if (count($lists) !== count($evidence['categories'])) return false;
        foreach ($lists as $index => $keys) {
            if (!is_array($keys) || count($keys) !== count(array_unique($keys))) return false;
            $expected = array();
            foreach ($evidence['items'] as $item) if (in_array($index, $item['categories'], true)) $expected[] = $item['key'];
            $actual = $keys;
            sort($expected);
            sort($actual);
            if ($expected !== $actual) return false;
        }
        return true;
    }

    private function finiteGlobalProbes(array $evidence, array $global, array $order): bool
    {
        $sawUniverse = false;
        foreach ($global as $probe) {
            if (!is_array($probe) || !is_string($probe['query'] ?? null) || !is_array($probe['keys'] ?? null)) return false;
            if ($this->filteredKeys($evidence['items'], $probe['query']) !== $probe['keys']) return false;
            if ($probe['query'] === $order['query'] && $probe['keys'] === $order['keys']) $sawUniverse = true;
        }
        return $sawUniverse;
    }

    private function finiteCategoryProbes(array $evidence, array $categories, array $lists): bool
    {
        if (count($categories) !== count($lists) || count($evidence['probes']) !== count($lists)) return false;
        $seen = array();
        foreach ($categories as $probe) {
            if (!is_array($probe) || !is_int($probe['category'] ?? null) || !isset($lists[$probe['category']]) || isset($seen[$probe['category']])
                || !is_array($probe['keys'] ?? null) || $probe['keys'] !== $lists[$probe['category']]) return false;
            $seen[$probe['category']] = true;
        }
        foreach ($evidence['probes'] as $probe) {
            if (!is_array($probe) || '' !== ($probe['query'] ?? null) || !is_int($probe['category'] ?? null) || !isset($lists[$probe['category']])
                || !is_array($probe['keys'] ?? null) || $probe['keys'] !== $lists[$probe['category']]) return false;
        }
        return count($seen) === count($lists);
    }

    private function filteredKeys(array $items, string $query): array
    {
        $needle = mb_strtolower($query);
        $keys = array();
        foreach ($items as $item) if (str_contains(mb_strtolower($item['text']), $needle)) $keys[] = $item['key'];
        return $keys;
    }

    private function answersState(array $items): string
    {
        foreach ($items as $item) if ($this->disclosureLabel((string) $item['html']) === mb_strtolower($item['text'])) return 'pending-disclosure-integration';
        return 'observed';
    }

    private function answerOnlyState(array $items, string $answers): string
    {
        if ('observed' !== $answers) return 'pending-disclosure-integration';
        $tokens = array();
        foreach ($items as $item) {
            preg_match_all('/[\p{L}]{5,}/u', mb_strtolower($item['text']), $matches);
            foreach ($matches[0] as $token) $tokens[$token] = true;
        }
        foreach (array_keys($tokens) as $token) {
            $count = 0;
            foreach ($items as $item) if (str_contains(mb_strtolower($item['text']), $token)) $count++;
            if ($count < 1 || $count >= count($items)) continue;
            foreach ($items as $item) {
                if (str_contains(mb_strtolower($item['text']), $token) && !str_contains($this->disclosureLabel((string) $item['html']), $token)) return 'verified';
            }
        }
        return 'pending-disclosure-integration';
    }

    private function disclosureLabel(string $html): string
    {
        $document = $this->document($html);
        foreach (array('button', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6') as $tag) {
            $node = $document->getElementsByTagName($tag)->item(0);
            if ($node instanceof DOMElement) return mb_strtolower($this->normalize($node->textContent ?? ''));
        }
        foreach ($document->getElementsByTagName('*') as $node) {
            if ($node instanceof DOMElement && 'button' === strtolower($node->getAttribute('role'))) return mb_strtolower($this->normalize($node->textContent ?? ''));
        }
        return '';
    }

    private function sharedOrderQuery(array $texts): ?string
    {
        if (count($texts) < 2) return null;
        $normalized = array_map(static fn (string $text): string => mb_strtolower($text), $texts);
        $characters = preg_split('//u', $normalized[0], -1, PREG_SPLIT_NO_EMPTY) ?: array();
        $seen = array();
        $shared = array();
        foreach ($characters as $character) {
            if (isset($seen[$character]) || '' === trim($character)) continue;
            $seen[$character] = true;
            foreach ($normalized as $text) if (!str_contains($text, $character)) continue 2;
            $shared[] = $character;
        }
        foreach ($shared as $character) if (1 === preg_match('/\p{L}/u', $character)) return $character;
        return $shared[0] ?? null;
    }

    private function isOrderedSubsequence(array $order, array $subset): bool
    {
        $index = 0;
        $total = count($subset);
        foreach ($order as $key) {
            if ($index < $total && $key === $subset[$index]) $index++;
            if ($index === $total) return true;
        }
        return $index === $total;
    }

    private function hasResource(string $html): bool
    {
        return 1 === preg_match('/<(?:img|video|audio|source|iframe|embed|object|picture)\b/i', $html)
            || 1 === preg_match('/\s(?:src|srcset|poster)\s*=\s*["\'](?!data:|#)/i', $html)
            || 1 === preg_match('/url\s*\(\s*[\'"]?(?!data:)/i', $html);
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
        $finite = is_array($evidence['finiteBootstrap'] ?? null);
        $container = $finite ? $this->itemContainer($target, $evidence['itemDepth'] ?? 0) : $target;
        if (!$container instanceof DOMElement) return false;
        $items = array_values(array_filter(iterator_to_array($container->childNodes), static fn ($node): bool => $node instanceof DOMElement && !$node->hasAttribute('data-dla-collection-empty')));
        if ($finite) {
            $items = $this->finiteNodes($document, $container, $items, $evidence['items']);
            if (null === $items) return false;
        } elseif (count($items) !== count($evidence['items'])) {
            return false;
        } else {
            foreach ($items as $index => $node) {
                if ($this->text($node) !== $evidence['items'][$index]['text']) return false;
                if ($node->hasAttribute('data-dla-collection-item') && $node->getAttribute('data-dla-collection-item') !== $evidence['items'][$index]['key']) return false;
            }
        }
        if ($finite && !$this->collectChoices($document, $root, $controls, $field, $target)) return false;
        $identity = substr(hash('sha256', $path . "\n" . $evidence['target']['selector']), 0, 16);
        $members = array();
        $markerByKey = array();
        foreach ($items as $index => $node) {
            $marker = 'blocks-engine-collection-item-' . $identity . '-' . $index;
            $node->setAttribute('class', SourceDom::mergeClassNames($node->getAttribute('class'), $marker));
            $node->setAttribute('data-blocks-engine-collection-item-marker', $marker);
            $members[] = array('marker' => $marker, 'categories' => $evidence['items'][$index]['categories']);
            $markerByKey[$evidence['items'][$index]['key']] = $marker;
        }
        $payload = array('items' => $members, 'initialCategory' => $evidence['initialCategory'], 'mode' => $finite ? 'category-or-global-search' : 'category-and-query', 'order' => array(), 'categoryOrders' => array());
        if ($finite) {
            $payload['order'] = array_column($members, 'marker');
            foreach ($evidence['finiteBootstrap']['order']['categoryKeys'] as $keys) {
                $row = array();
                foreach ($keys as $key) $row[] = $markerByKey[$key];
                $payload['categoryOrders'][] = $row;
            }
        }
        $root->setAttribute('data-blocks-engine-collection-root', json_encode($payload, JSON_THROW_ON_ERROR));
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

    private function itemContainer(DOMElement $target, int $depth): ?DOMElement
    {
        $node = $target;
        for ($step = 0; $step < $depth; $step++) {
            $children = array_values(array_filter(iterator_to_array($node->childNodes), static fn ($child): bool => $child instanceof DOMElement));
            if (1 !== count($children)) return null;
            $node = $children[0];
        }
        return $node;
    }

    private function finiteNodes(DOMDocument $document, DOMElement $container, array $nodes, array $items): ?array
    {
        $used = array();
        $byKey = array();
        foreach ($nodes as $node) {
            if ($this->hasResource($node->ownerDocument?->saveHTML($node) ?: '')) return null;
            $text = $this->text($node);
            $match = null;
            foreach ($items as $item) {
                if (isset($used[$item['key']]) || $item['text'] !== $text) continue;
                if (null !== $match) return null;
                $match = $item;
            }
            if (null === $match) return null;
            $used[$match['key']] = $node;
            $byKey[$match['key']] = $node;
        }
        foreach ($items as $item) {
            if (isset($byKey[$item['key']])) continue;
            if ($this->hasResource((string) $item['html'])) return null;
            $imported = $this->importItem($document, (string) $item['html']);
            if (!$imported instanceof DOMElement || $this->text($imported) !== $item['text'] || $this->hasResource($imported->ownerDocument?->saveHTML($imported) ?: '')) return null;
            $container->appendChild($imported);
            $byKey[$item['key']] = $imported;
        }
        if (count($byKey) !== count($items)) return null;
        $ordered = array();
        foreach ($items as $item) {
            $node = $byKey[$item['key']] ?? null;
            if (!$node instanceof DOMElement || !$node->parentNode?->isSameNode($container)) return null;
            $container->appendChild($node);
            $ordered[] = $node;
        }
        return $ordered;
    }

    private function importItem(DOMDocument $document, string $html): ?DOMElement
    {
        $fragment = $this->document('<div>' . $html . '</div>');
        $body = $fragment->getElementsByTagName('body')->item(0);
        $holder = $body?->firstChild;
        if (!$holder instanceof DOMElement) return null;
        foreach ($holder->childNodes as $node) {
            if ($node instanceof DOMElement) return $document->importNode($node, true);
        }
        return null;
    }

    private function collectChoices(DOMDocument $document, DOMElement $root, array $controls, DOMElement $field, DOMElement $target): bool
    {
        $parent = $controls[0]->parentNode ?? null;
        $siblings = $parent instanceof DOMElement;
        foreach ($controls as $control) $siblings = $siblings && $control->parentNode instanceof DOMElement && $control->parentNode->isSameNode($parent);
        $wrapper = $siblings && $parent instanceof DOMElement && !$this->containsAll($parent, array($field)) && !$this->containsAll($parent, array($target)) ? $parent : null;
        if (!$wrapper instanceof DOMElement) {
            $wrapper = $document->createElement('div');
            $controls[0]->parentNode?->insertBefore($wrapper, $controls[0]);
            foreach ($controls as $control) $wrapper->appendChild($control);
        }
        if ($this->containsAll($wrapper, array($field)) || $this->containsAll($wrapper, array($target)) || !$this->containsAll($root, array($wrapper))) return false;
        $wrapper->setAttribute('data-blocks-engine-collection-choices', 'true');
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

    private function localDisclosuresAreNative(DOMDocument $document): bool
    {
        $found = false;
        foreach ($document->getElementsByTagName('*') as $node) {
            if (!$node instanceof DOMElement || 'true' !== strtolower($node->getAttribute('data-dla-local-disclosure'))) continue;
            $found = true;
            $item = $node->parentNode;
            while ($item instanceof DOMElement && !$item->hasAttribute('data-blocks-engine-collection-item-marker')) $item = $item->parentNode;
            if (!$item instanceof DOMElement) return false;
        }
        return $found;
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

    private function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
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
