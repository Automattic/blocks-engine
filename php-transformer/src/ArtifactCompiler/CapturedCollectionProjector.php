<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMDocument;
use DOMElement;
use DOMText;

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
        $retired = array();
        $consumed = array();
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
                if ('captured' !== ($state['status'] ?? null) || !is_array($evidence)) {
                    $diagnostics[] = $this->diagnostic('Captured collection evidence is incomplete; native local filtering remains unproven.');
                    continue;
                }
                if ($this->presentStatusInvalid($evidence)) {
                    $diagnostics[] = $this->diagnostic('Present collection status is not portable; native filtering is not claimed. Observed unsupported status ' . $this->statusUnsupportedAttribute($evidence) . '.');
                    continue;
                }
                if (!$this->verified($evidence)) {
                    $diagnostics[] = $this->diagnostic('Captured collection evidence is incomplete; native local filtering remains unproven.');
                    continue;
                }
                $document ??= $this->document($files[$index]['content']);
                $candidate = clone $document;
                $added = $this->annotateAvailable($candidate, $evidence, $files[$index]['path']);
                if ($added < 1) {
                    $diagnostics[] = $this->diagnostic('Verified collection evidence did not match one bounded canonical source tree.');
                    continue;
                }
                $document = $candidate;
                $count += $added;
                $pageCount += $added;
                $this->rememberConsumedBindings($consumed, (string) $files[$index]['path'], $evidence);
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
                        if (!$node instanceof DOMElement || !$node->hasAttribute($attribute)) continue;
                        $body = trim($node->textContent ?? '');
                        if ('' !== $body) {
                            $retired[$files[$index]['path']][] = array(
                                'kind' => 'script' === $tag ? 'inline-script' : 'inline-style',
                                'body' => $body,
                                'attribute' => $attribute,
                                'reason' => str_contains($attribute, 'disclosure') ? 'native_disclosure_replaces_capture_runtime' : 'native_collection_replaces_capture_runtime',
                            );
                        }
                        $node->parentNode?->removeChild($node);
                    }
                }
                $html = preg_replace('/^<\?xml encoding="UTF-8">/i', '', $document->saveHTML() ?: '');
                $files[$index]['content'] = $html;
                $files[$index]['bytes'] = strlen($html);
            }
        }
        $superseded = $this->omitRetiredRuntimeFiles($files, $retired);
        return array('files' => $files, 'diagnostics' => $diagnostics, 'projected_count' => $count, 'superseded_runtime_scripts' => $superseded, 'consumed_selectable_bindings' => $consumed);
    }

    /**
     * Drop only the extracted inline copies of runtime tags this page proved
     * native. A sibling region that still needs the capture script keeps it.
     *
     * @param array<int,array<string,mixed>> $files
     * @param array<string,array<int,array{kind:string,body:string,attribute:string,reason:string}>> $retired
     * @return array<int,array<string,mixed>>
     */
    private function omitRetiredRuntimeFiles(array &$files, array $retired): array
    {
        $proofs = array();
        $kept = array();
        foreach ($files as $file) {
            $sourcePath = ArtifactNormalizer::inlineExpansionSourcePath($file);
            $body = trim((string) ($file['content'] ?? ''));
            $matched = null;
            if ('' !== $sourcePath && '' !== $body) {
                foreach ($retired[$sourcePath] ?? array() as $row) {
                    if ($row['kind'] === ($file['source'] ?? null) && $row['body'] === $body) {
                        $matched = $row;
                        break;
                    }
                }
            }
            if (null === $matched) {
                $kept[] = $file;
                continue;
            }
            $proofs[] = array(
                'schema' => 'blocks-engine/native-runtime-replacement/v1',
                'source_path' => $sourcePath,
                'selector' => (string) ($file['selector'] ?? ''),
                'asset_source_path' => (string) ($file['path'] ?? ''),
                'body_hash' => hash('sha256', $body),
                'attribute' => $matched['attribute'],
                'reason' => $matched['reason'],
            );
        }
        $files = $kept;
        usort($proofs, static fn (array $left, array $right): int => strcmp($left['asset_source_path'] . $left['body_hash'], $right['asset_source_path'] . $right['body_hash']));
        return $proofs;
    }

    /** @param array<string, array<int, array{target:string, categories:array<int, string>}>> $consumed */
    private function rememberConsumedBindings(array &$consumed, string $path, array $evidence): void
    {
        $target = trim((string) ($evidence['target']['selector'] ?? ''));
        $categories = array();
        foreach ($evidence['categories'] ?? array() as $category) {
            $selector = is_array($category) ? trim((string) ($category['selector'] ?? '')) : '';
            if ('' === $selector) return;
            $categories[$selector] = $selector;
        }
        if ('' === $target || array() === $categories) return;
        $row = array('target' => $target, 'categories' => array_values($categories));
        foreach ($consumed[$path] ?? array() as $existing) if ($existing === $row) return;
        $consumed[$path][] = $row;
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
        return $this->verifiedStatus($evidence);
    }

    private function presentStatusInvalid(array $evidence): bool
    {
        $bootstrap = $evidence['finiteBootstrap'] ?? null;
        return is_array($bootstrap) && array_key_exists('status', $bootstrap) && !$this->verifiedStatus($evidence);
    }

    private function statusUnsupportedAttribute(array $evidence): string
    {
        $nodes = $evidence['finiteBootstrap']['status']['nodes'] ?? null;
        if (!is_array($nodes)) return 'schema';
        foreach ($nodes as $node) {
            if (!is_array($node) || !is_string($node['html'] ?? null)) return 'schema';
            $imported = $this->importStatusNode($this->document('<div></div>'), $node['html']);
            if (!$imported) return 'html';
            $attribute = $this->firstUnsafeStatusAttribute($imported);
            if (null !== $attribute) return $attribute;
        }
        return 'schema';
    }

    private function firstUnsafeStatusAttribute(DOMElement $element): ?string
    {
        foreach ($element->attributes ?? array() as $attribute) {
            $name = strtolower($attribute->name);
            if (str_starts_with($name, 'on')) return $name;
            if ('aria-hidden' === $name && !in_array($attribute->value, array('true', 'false'), true)) return $name;
            if (!in_array($name, array('class', 'id', 'style', 'role', 'aria-live', 'aria-atomic', 'aria-hidden', 'data-hook', 'data-dla-status-template', 'data-dla-collection-status', 'data-dla-status-hide-zero', 'data-blocks-engine-collection-status', 'data-blocks-engine-status-hide-zero', 'hidden'), true)) return $name;
        }
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $found = $this->firstUnsafeStatusAttribute($child);
                if (null !== $found) return $found;
            }
        }
        return null;
    }

    private function verifiedStatus(array $evidence): bool
    {
        $bootstrap = $evidence['finiteBootstrap'] ?? null;
        if (!is_array($bootstrap) || !array_key_exists('status', $bootstrap)) return true;
        $status = $bootstrap['status'];
        if (!is_array($status) || array('nodes', 'schema') !== $this->sortedKeys($status)
            || 'data-liberation/collection-status/v1' !== ($status['schema'] ?? null)
            || !is_array($status['nodes'] ?? null) || array() === $status['nodes'] || count($status['nodes']) > 4
            || !array_is_list($status['nodes'])) return false;
        foreach ($status['nodes'] as $node) if (!$this->verifiedStatusNode($node)) return false;
        return true;
    }

    private function verifiedStatusNode(mixed $node): bool
    {
        if (!is_array($node) || array('binds', 'hidesAtZero', 'html', 'placement', 'template') !== $this->sortedKeys($node)) return false;
        if (!is_string($node['html']) || strlen($node['html']) > 4096 || !is_string($node['template']) || strlen($node['template']) > 240
            || !in_array($node['placement'], array('before-items', 'after-items'), true) || true !== $node['hidesAtZero']) return false;
        $tokens = $this->statusTokens($node['template']);
        if (null === $tokens || $tokens !== $node['binds']) return false;
        if (str_contains($node['template'], 'dla-no-match') || str_contains($node['template'], 'dla-finite-probe')) return false;
        $imported = $this->importStatusNode($this->document('<div></div>'), $node['html']);
        if (!$imported || $this->text($imported) !== $node['template'] || 1 !== $this->markStatusTemplates($imported)) return false;
        return $this->statusTemplateValue($imported) === $node['template'] && !$this->unsafeStatus($imported);
    }

    /** @return array<int, string>|null */
    private function statusTokens(string $template): ?array
    {
        if (1 === preg_match('/\{(?!count\}|query\})/', $template) || substr_count($template, '{count}') > 1 || substr_count($template, '{query}') > 1) return null;
        $tokens = array();
        if (str_contains($template, '{count}')) $tokens[] = 'count';
        if (str_contains($template, '{query}')) $tokens[] = 'query';
        return $tokens ?: null;
    }

    /** @return array<int, string> */
    private function sortedKeys(array $value): array
    {
        $keys = array_keys($value);
        sort($keys);
        return $keys;
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

    private function annotateAvailable(DOMDocument $document, array $evidence, string $path): int
    {
        $copies = $this->portableCopies($document, $evidence);
        if ($copies) {
            $added = 0;
            foreach ($copies as $copy) if ($this->annotate($document, $evidence, $path, $copy)) ++$added;
            return $added;
        }
        return $this->annotate($document, $evidence, $path) ? 1 : 0;
    }

    private function portableCopies(DOMDocument $document, array $evidence): array
    {
        $targets = array();
        foreach ($document->getElementsByTagName('*') as $node) {
            if (!$node instanceof DOMElement || !$node->hasAttribute('data-dla-collection')) continue;
            $id = $node->getAttribute('data-dla-collection');
            if (1 !== preg_match('/^[0-9]{1,4}$/', $id) || isset($targets[$id])) return array();
            $targets[$id] = $node;
        }
        $copies = array();
        foreach ($targets as $id => $target) {
            $id = (string) $id;
            $field = $this->marked($document, 'data-dla-collection-field', $id);
            if (!$field || 'input' !== strtolower($field->tagName)) continue;
            $controls = array();
            foreach ($evidence['categories'] as $index => $category) {
                $control = $this->marked($document, 'data-dla-collection-category-control', $id, $index);
                if (!$control || !$this->isChoiceControl($control)) continue 2;
                $controls[] = $control;
            }
            if (!$this->portableItemsMatch($target, $evidence)) continue;
            $copies[] = array('id' => $id, 'field' => $field, 'target' => $target, 'controls' => $controls);
        }
        return $copies;
    }

    private function marked(DOMDocument $document, string $attribute, string $id, ?int $index = null): ?DOMElement
    {
        $found = null;
        foreach ($document->getElementsByTagName('*') as $node) {
            if (!$node instanceof DOMElement || $node->getAttribute($attribute) !== $id) continue;
            if (null !== $index && (string) $index !== $node->getAttribute('data-dla-collection-index')) continue;
            if ($found) return null;
            $found = $node;
        }
        return $found;
    }

    private function portableItemsMatch(DOMElement $target, array $evidence): bool
    {
        $found = array();
        foreach ($target->getElementsByTagName('*') as $node) {
            if (!$node instanceof DOMElement || !$node->hasAttribute('data-dla-collection-item')) continue;
            $key = $node->getAttribute('data-dla-collection-item');
            if (isset($found[$key])) return false;
            $members = json_decode($node->getAttribute('data-dla-collection-members'), true);
            $found[$key] = $members;
        }
        foreach ($evidence['items'] as $item) {
            if (!isset($found[$item['key']]) || $found[$item['key']] !== $item['categories']) return false;
        }
        return count($found) === count($evidence['items']);
    }

    private function isChoiceControl(DOMElement $element): bool
    {
        $role = strtolower($element->getAttribute('role'));
        return 'button' === strtolower($element->tagName) || in_array($role, array('button', 'tab'), true);
    }

    private function annotate(DOMDocument $document, array $evidence, string $path, ?array $resolved = null): bool
    {
        $copyId = is_array($resolved) ? (string) ($resolved['id'] ?? '') : '';
        $field = $resolved['field'] ?? $this->one($document, $evidence['field']['selector'] ?? '');
        $target = $resolved['target'] ?? $this->one($document, $evidence['target']['selector'] ?? '');
        if (!$field || !$target || 'input' !== strtolower($field->tagName) || str_contains($field->getAttribute('style'), '!important')) return false;
        $controls = array();
        $states = array();
        foreach ($evidence['categories'] as $index => $category) {
            $control = is_array($resolved) ? ($resolved['controls'][$index] ?? null) : $this->one($document, $category['selector']);
            $active = null === $resolved ? $this->buttonAttributes($category['activeHtml']) : $this->controlAttributes($category['activeHtml']);
            $inactive = null === $resolved ? $this->buttonAttributes($category['inactiveHtml']) : $this->controlAttributes($category['inactiveHtml']);
            if (!$control || (null === $resolved && 'button' !== strtolower($control->tagName)) || (null !== $resolved && !$this->isChoiceControl($control)) || null === $active || null === $inactive) return false;
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
        if (null !== $resolved) {
            $container = $target;
            $marked = array();
            foreach ($target->getElementsByTagName('*') as $node) {
                if ($node instanceof DOMElement && $node->hasAttribute('data-dla-collection-item')) $marked[$node->getAttribute('data-dla-collection-item')] = $node;
            }
            $items = array();
            foreach ($evidence['items'] as $item) {
                if (!isset($marked[$item['key']])) return false;
                $items[] = $marked[$item['key']];
            }
        } else {
            if (!$container instanceof DOMElement) return false;
            $items = array_values(array_filter(iterator_to_array($container->childNodes), static fn ($node): bool => $node instanceof DOMElement && !$node->hasAttribute('data-dla-collection-empty')));
        }
        if (!$container instanceof DOMElement) return false;
        if ($finite && null === $resolved) {
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
        $identity = substr(hash('sha256', $path . "\n" . $evidence['target']['selector'] . ('' === $copyId ? '' : "\n" . $copyId)), 0, 16);
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
        return $this->projectStatus($document, $root, $target, $evidence, $copyId);
    }

    private function projectStatus(DOMDocument $document, DOMElement $root, DOMElement $target, array $evidence, string $copyId): bool
    {
        $status = $evidence['finiteBootstrap']['status'] ?? null;
        if (!is_array($status)) return true;
        $existing = $this->existingStatus($document, $root, $copyId);
        if ($existing) {
            if (count($existing) !== count($status['nodes'])) return false;
            foreach ($existing as $index => $element) if (!$this->markNativeStatus($element, $status['nodes'][$index])) return false;
            return true;
        }
        $after = array();
        foreach ($status['nodes'] as $node) {
            $imported = $this->importStatusNode($document, $node['html']);
            if (!$imported || !$this->markNativeStatus($imported, $node)) return false;
            if ('before-items' === $node['placement']) {
                if (!$target->parentNode) return false;
                $target->parentNode->insertBefore($imported, $target);
            } else {
                $after[] = $imported;
            }
        }
        $cursor = $target->nextSibling;
        foreach ($after as $imported) {
            if (!$target->parentNode) return false;
            $target->parentNode->insertBefore($imported, $cursor);
            $cursor = $imported->nextSibling;
        }
        return $this->containsAll($root, $this->nativeStatusNodes($root));
    }

    /** @return array<int, DOMElement> */
    private function existingStatus(DOMDocument $document, DOMElement $root, string $copyId): array
    {
        if (!preg_match('/^[0-9]{1,4}$/', $copyId)) return array();
        $found = array();
        foreach ($document->getElementsByTagName('*') as $node) {
            if (!$node instanceof DOMElement || $node->getAttribute('data-dla-collection-status') !== $copyId || !$this->containsAll($root, array($node))) continue;
            $found[] = $node;
        }
        return $found;
    }

    /** @param array<string, mixed> $node */
    private function markNativeStatus(DOMElement $element, array $node): bool
    {
        if ($this->text($element) !== $node['template'] || $this->unsafeStatus($element) || 1 !== $this->markStatusTemplates($element)) return false;
        if ($this->statusTemplateValue($element) !== $node['template']) return false;
        $element->setAttribute('data-blocks-engine-collection-status', 'true');
        $element->setAttribute('hidden', 'hidden');
        $element->setAttribute('data-blocks-engine-status-hide-zero', 'true');
        return true;
    }

    private function markStatusTemplates(DOMElement $element): int
    {
        $count = 0;
        $own = '';
        foreach ($element->childNodes as $child) if (XML_TEXT_NODE === $child->nodeType) $own .= $child->textContent;
        $own = $this->normalize($own);
        if (str_contains($own, '{count}') || str_contains($own, '{query}')) {
            $element->setAttribute('data-dla-status-template', $own);
            $count++;
        }
        foreach ($element->childNodes as $child) if ($child instanceof DOMElement) $count += $this->markStatusTemplates($child);
        return $count;
    }

    private function statusTemplateValue(DOMElement $element): ?string
    {
        $found = null;
        $walk = static function (DOMElement $node) use (&$walk, &$found): void {
            if ($node->hasAttribute('data-dla-status-template')) $found = $node->getAttribute('data-dla-status-template');
            foreach ($node->childNodes as $child) if ($child instanceof DOMElement) $walk($child);
        };
        $walk($element);
        return is_string($found) ? $found : null;
    }

    private function unsafeStatus(DOMElement $element): bool
    {
        $walk = function (DOMElement $node) use (&$walk): bool {
            if (!in_array(strtolower($node->tagName), array('div', 'span', 'p', 'output'), true)) return true;
            if ($this->hasResource($node->ownerDocument?->saveHTML($node) ?: '')) return true;
            foreach ($node->attributes ?? array() as $attribute) {
                $name = strtolower($attribute->name);
                if (str_starts_with($name, 'on') || str_contains(strtolower($attribute->value), 'javascript:')) return true;
                if (!in_array($name, array('class', 'id', 'style', 'role', 'aria-live', 'aria-atomic', 'aria-hidden', 'data-hook', 'data-dla-status-template', 'data-dla-collection-status', 'data-dla-status-hide-zero', 'data-blocks-engine-collection-status', 'data-blocks-engine-status-hide-zero', 'hidden'), true)) return true;
                if ('aria-hidden' === $name && !in_array($attribute->value, array('true', 'false'), true)) return true;
                if ('data-hook' === $name && 1 !== preg_match('/^[A-Za-z0-9_-]{1,80}$/', $attribute->value)) return true;
                if ('style' === $name && (str_contains($attribute->value, '!important') || $this->hasResource($attribute->value))) return true;
                if (str_contains($attribute->value, '<')) return true;
            }
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement && $walk($child)) return true;
                if (!$child instanceof DOMElement && !$child instanceof DOMText) return true;
            }
            return false;
        };
        return $walk($element);
    }

    private function importStatusNode(DOMDocument $document, string $html): ?DOMElement
    {
        $fragment = $this->document('<div>' . $html . '</div>');
        $body = $fragment->getElementsByTagName('body')->item(0);
        $holder = $body?->firstChild;
        if (!$holder instanceof DOMElement) return null;
        $elements = array();
        foreach ($holder->childNodes as $node) {
            if ($node instanceof DOMText && '' === trim($node->textContent ?? '')) continue;
            if (!$node instanceof DOMElement) return null;
            $elements[] = $node;
        }
        if (1 !== count($elements)) return null;
        $imported = $document->importNode($elements[0], true);
        return $imported instanceof DOMElement ? $imported : null;
    }

    /** @return array<int, DOMElement> */
    private function nativeStatusNodes(DOMElement $root): array
    {
        $found = array();
        foreach ($root->getElementsByTagName('*') as $node) {
            if ($node instanceof DOMElement && 'true' === $node->getAttribute('data-blocks-engine-collection-status')) $found[] = $node;
        }
        return $found;
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
        return $this->controlState($buttons->item(0));
    }

    private function controlAttributes(string $html): ?array
    {
        $document = $this->document($html);
        $body = $document->getElementsByTagName('body')->item(0);
        $element = null;
        if ($body) foreach ($body->childNodes as $child) {
            if (!$child instanceof DOMElement) continue;
            if ($element) return null;
            $element = $child;
        }
        return $element && $this->isChoiceControl($element) ? $this->controlState($element) : null;
    }

    private function controlState(DOMElement $element): array
    {
        $state = array('className' => $element->getAttribute('class'), 'style' => $element->getAttribute('style'), 'selected' => $element->hasAttribute('aria-selected') ? $element->getAttribute('aria-selected') : null, 'dataState' => $element->hasAttribute('data-state') ? $element->getAttribute('data-state') : null);
        $role = strtolower(trim($element->getAttribute('role')));
        if (in_array($role, array('tab', 'button'), true)) $state['role'] = $role;
        if (preg_match('/^-?[0-9]{1,4}$/', trim($element->getAttribute('tabindex')))) $state['tabIndex'] = (int) $element->getAttribute('tabindex');
        return $state;
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
