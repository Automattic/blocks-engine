<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use DOMDocument;
use DOMElement;

/** Projects verified choice-group transitions into an editable companion marker. */
final class CapturedChoiceGroupProjector
{
    private const REPORT_SCHEMA = 'data-liberation/captured-interactions/v1';
    private const RECEIPT_SCHEMA = 'data-liberation/capture-receipt/v1';
    private const KIND = 'choice-group';
    private const MAX_PAGES = 128;
    private const MAX_GROUPS_PER_PAGE = 8;
    private const MAX_CHOICES = 32;
    private const MAX_STATES = 32;
    private const MAX_STATE_BYTES = 65536;
    private const MAX_GROUP_BYTES = 262144;

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array{files:array<int, array<string, mixed>>, diagnostics:array<int, array<string, mixed>>, projected_count:int}
     */
    public function project(array $files): array
    {
        $report = $this->jsonFile($files, 'interaction-states.json');
        if (null === $report || self::REPORT_SCHEMA !== ($report['schema'] ?? null) || ! is_array($report['pages'] ?? null)) {
            return array('files' => $files, 'diagnostics' => array(), 'projected_count' => 0);
        }
        if (count($report['pages']) > self::MAX_PAGES) {
            return array('files' => $files, 'diagnostics' => array($this->diagnostic('captured_choice_group_limit_exceeded', 'warning', 'The captured interaction report exceeded the page limit.')), 'projected_count' => 0);
        }

        $receipt = $this->jsonFile($files, 'capture-receipt.json');
        if (null === $receipt || self::RECEIPT_SCHEMA !== ($receipt['schema'] ?? null) || ! is_array($receipt['routes'] ?? null)) {
            return array('files' => $files, 'diagnostics' => array(), 'projected_count' => 0);
        }
        $routes = array();
        foreach ($receipt['routes'] as $route) {
            if (is_array($route) && is_string($route['url'] ?? null) && is_string($route['path'] ?? null)) {
                $routes[$this->normalizedUrl($route['url'])] = $route['path'];
            }
        }
        $fileIndexes = array();
        foreach ($files as $index => $file) {
            if (is_string($file['path'] ?? null)) {
                $fileIndexes[$file['path']] = $index;
            }
        }

        $diagnostics = array();
        $projected = 0;
        foreach ($report['pages'] as $page) {
            if (! is_array($page) || ! is_string($page['sourceUrl'] ?? null) || ! is_array($page['states'] ?? null)) {
                continue;
            }
            $groups = $this->groupedGroups($page['states'], $page['sourceUrl'], $diagnostics);
            if (array() === $groups) {
                continue;
            }
            if (count($groups) > self::MAX_GROUPS_PER_PAGE) {
                $diagnostics[] = $this->diagnostic('captured_choice_group_limit_exceeded', 'warning', 'A captured interaction page exceeded the choice-group limit.', array('source_url' => $page['sourceUrl']));
                continue;
            }
            $path = $routes[$this->normalizedUrl($page['sourceUrl'])] ?? '';
            $index = $fileIndexes[$path] ?? null;
            if (! is_int($index) || ! is_string($files[$index]['content'] ?? null)) {
                $diagnostics[] = $this->diagnostic('captured_choice_group_source_unmatched', 'warning', 'A captured choice group did not match an artifact HTML document.', array('source_url' => $page['sourceUrl']));
                continue;
            }
            $result = $this->projectPage((string) $files[$index]['content'], $groups, $path);
            $diagnostics = array_merge($diagnostics, $result['diagnostics']);
            if (0 < $result['projected_count']) {
                $files[$index]['content'] = $result['html'];
                $files[$index]['bytes'] = strlen($result['html']);
                $projected += $result['projected_count'];
            }
        }

        return array('files' => $files, 'diagnostics' => $diagnostics, 'projected_count' => $projected);
    }

    /**
     * @param array<int, mixed> $states
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, array<string, mixed>>
     */
    private function groupedGroups(array $states, string $sourceUrl, array &$diagnostics): array
    {
        $groups = array();
        foreach ($states as $state) {
            if (! is_array($state) || self::KIND !== ($state['kind'] ?? null) || 'captured' !== ($state['status'] ?? null)) {
                continue;
            }
            $evidence = $state['choiceGroup'] ?? null;
            if (! is_array($evidence) || ! is_array($evidence['group'] ?? null) || ! is_array($evidence['choices'] ?? null) || ! is_array($evidence['transition'] ?? null)) {
                $diagnostics[] = $this->diagnostic('captured_choice_group_invalid', 'warning', 'A captured choice group was omitted because its evidence shape is incomplete.', array('source_url' => $sourceUrl));
                continue;
            }
            $selector = is_string($evidence['group']['selector'] ?? null) ? trim($evidence['group']['selector']) : '';
            $replay = $evidence['replay'] ?? null;
            $restoration = $evidence['restoration'] ?? null;
            $coverage = $evidence['coverage'] ?? null;
            $html = is_string($evidence['transition']['html'] ?? null) ? $evidence['transition']['html'] : '';
            $declaredBytes = is_int($evidence['transition']['htmlBytes'] ?? null) ? $evidence['transition']['htmlBytes'] : -1;
            $selectedIndex = is_int($evidence['transition']['selectedIndex'] ?? null) ? $evidence['transition']['selectedIndex'] : -1;
            $selected = $evidence['transition']['selected'] ?? null;
            if ('activation-determined' !== $replay || 'verified' !== $restoration || 'complete' !== $coverage) {
                $diagnostics[] = $this->diagnostic('captured_choice_group_ineligible', 'warning', 'A captured choice group was omitted because its producer replay, restoration, or coverage evidence is not eligible for offline replay.', array('source_url' => $sourceUrl, 'selector' => $selector));
                continue;
            }
            if ('' === $selector || '' === $html || ! empty($evidence['transition']['htmlTruncated']) || strlen($html) !== $declaredBytes || strlen($html) > self::MAX_STATE_BYTES || ! is_array($selected)) {
                $diagnostics[] = $this->diagnostic('captured_choice_group_truncated', 'warning', 'A captured choice group was omitted because its bounded transition is incomplete.', array('source_url' => $sourceUrl));
                continue;
            }
            $sanitized = $this->safeHtml($html);
            if (null === $sanitized || '' === trim($sanitized)) {
                $diagnostics[] = $this->diagnostic('captured_choice_group_markup_invalid', 'warning', 'A captured choice group was omitted because its transition markup could not be sanitized.', array('source_url' => $sourceUrl));
                continue;
            }
            $choices = $this->choices($evidence['choices']);
            if (count($choices) < 2 || count($choices) > self::MAX_CHOICES || $selectedIndex < 0 || $selectedIndex >= count($choices) || count($selected) !== count($choices)) {
                $diagnostics[] = $this->diagnostic('captured_choice_group_invalid', 'warning', 'A captured choice group was omitted because its choices or selected transition are invalid.', array('source_url' => $sourceUrl));
                continue;
            }
            $key = $selector;
            if (! isset($groups[$key])) {
                $groups[$key] = array(
                    'group' => $this->boundedGroup($evidence['group']),
                    'choices' => $choices,
                    'states' => array(),
                    'bytes' => 0,
                );
            }
            if ($groups[$key]['choices'] !== $choices) {
                $diagnostics[] = $this->diagnostic('captured_choice_group_inconsistent', 'warning', 'A captured choice group was omitted because its repeated choice descriptors differ.', array('source_url' => $sourceUrl));
                unset($groups[$key]);
                continue;
            }
            if (isset($groups[$key]['states'][$selectedIndex])) {
                continue;
            }
            $stateBytes = strlen($sanitized);
            if ($groups[$key]['bytes'] + $stateBytes > self::MAX_GROUP_BYTES || count($groups[$key]['states']) >= self::MAX_STATES) {
                $diagnostics[] = $this->diagnostic('captured_choice_group_bounded', 'warning', 'A captured choice group exceeded its transition budget.', array('source_url' => $sourceUrl));
                unset($groups[$key]);
                continue;
            }
            $groups[$key]['states'][$selectedIndex] = array(
                'selectedIndex' => $selectedIndex,
                'selected' => array_values(array_map(static fn(mixed $value): ?bool => is_bool($value) ? $value : null, $selected)),
                'html' => $sanitized,
            );
            $groups[$key]['bytes'] += $stateBytes;
        }

        foreach ($groups as $key => &$group) {
            ksort($group['states'], SORT_NUMERIC);
            if (count($group['states']) !== count($group['choices'])) {
                $diagnostics[] = $this->diagnostic('captured_choice_group_incomplete', 'warning', 'A captured choice group was omitted because not every ordered choice has a replayable observed transition.', array('source_url' => $sourceUrl, 'selector' => $key));
                unset($groups[$key]);
                continue;
            }
            $group['states'] = array_values($group['states']);
            unset($group['bytes']);
        }
        unset($group);

        return $groups;
    }

    /** @param array<int, mixed> $choices @return array<int, array<string, mixed>> */
    private function choices(array $choices): array
    {
        $result = array();
        foreach ($choices as $choice) {
            if (! is_array($choice) || ! is_int($choice['index'] ?? null) || $choice['index'] !== count($result) || ! is_string($choice['selector'] ?? null) || '' === trim($choice['selector']) || ! is_string($choice['tag'] ?? null)) {
                return array();
            }
            $result[] = array_filter(array(
                'index' => $choice['index'],
                'selector' => trim($choice['selector']),
                'tag' => strtolower(trim($choice['tag'])),
                'id' => is_string($choice['id'] ?? null) ? trim($choice['id']) : '',
                'role' => is_string($choice['role'] ?? null) ? trim($choice['role']) : '',
                'label' => is_string($choice['label'] ?? null) ? trim($choice['label']) : '',
                'source_value' => array_key_exists('value', $choice) && null !== $choice['value'] && is_scalar($choice['value']) ? (string) $choice['value'] : null,
                'observed_choice_key' => 'choice-' . $choice['index'],
            ), static fn(mixed $value): bool => null !== $value && '' !== $value);
            if (! array_key_exists('source_value', $result[array_key_last($result)])) {
                $result[array_key_last($result)]['source_value'] = null;
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $group @return array<string, mixed> */
    private function boundedGroup(array $group): array
    {
        return array_filter(array(
            'selector' => is_string($group['selector'] ?? null) ? trim($group['selector']) : '',
            'tag' => is_string($group['tag'] ?? null) ? strtolower(trim($group['tag'])) : '',
            'id' => is_string($group['id'] ?? null) ? trim($group['id']) : '',
            'label' => is_string($group['label'] ?? null) ? trim($group['label']) : '',
            'labelSelector' => is_string($group['labelSelector'] ?? null) ? trim($group['labelSelector']) : '',
            'formSelector' => is_string($group['formSelector'] ?? null) ? trim($group['formSelector']) : '',
        ), static fn(mixed $value): bool => '' !== $value);
    }

    /** @param array<string, array<string, mixed>> $groups @return array{html:string, diagnostics:array<int, array<string, mixed>>, projected_count:int} */
    private function projectPage(string $html, array $groups, string $sourcePath): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return array('html' => $html, 'diagnostics' => array($this->diagnostic('captured_choice_group_source_invalid', 'warning', 'Captured choice groups were not projected because the source HTML could not be parsed.', array('source_path' => $sourcePath))), 'projected_count' => 0);
        }

        $diagnostics = array();
        $projected = 0;
        foreach ($groups as $group) {
            $regions = $this->findRegions($document, (string) ($group['group']['selector'] ?? ''));
            if ('ambiguous' === $regions['status']) {
                $diagnostics[] = $this->diagnostic('captured_choice_group_ambiguous', 'warning', 'A captured choice-group selector matched multiple source elements.', array('source_path' => $sourcePath, 'selector' => $group['group']['selector'] ?? ''));
                continue;
            }
            if (array() === $regions['elements']) {
                $diagnostics[] = $this->diagnostic('captured_choice_group_unmatched', 'warning', 'A captured choice-group selector did not match a source element.', array('source_path' => $sourcePath, 'selector' => $group['group']['selector'] ?? ''));
                continue;
            }
            $stateRoots = array();
            $configStates = array();
            foreach ($group['states'] as $state) {
                $stateRoot = $this->fragmentRoot((string) ($state['html'] ?? ''));
                if (! $stateRoot instanceof DOMElement) {
                    continue 2;
                }
                $stateRoots[] = $stateRoot;
                $configStates[] = array(
                    'selectedIndex' => $state['selectedIndex'],
                    'selected' => $state['selected'],
                    'html' => $this->innerHtml($stateRoot),
                );
            }
            $bindings = $this->stateBindings($stateRoots, $group['choices']);
            if (null === $bindings) {
                $diagnostics[] = $this->diagnostic('captured_choice_group_state_invalid', 'warning', 'A captured choice group was omitted because a replay state did not contain its ordered choice controls.', array('source_path' => $sourcePath, 'selector' => $group['group']['selector'] ?? ''));
                continue;
            }
            foreach ($configStates as $index => &$state) $state['bindings'] = $bindings[$index];
            unset($state);
            $config = array(
                'group' => $group['group'],
                'choices' => $group['choices'],
                'states' => $configStates,
            );
            $encoded = json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            foreach ($regions['elements'] as $region) {
                $this->markRegion($region, $encoded);
                ++$projected;
            }
        }

        $output = $document->saveHTML();
        $output = is_string($output) ? preg_replace('/^<\?xml encoding="UTF-8">/i', '', $output) : null;
        return array('html' => is_string($output) ? $output : $html, 'diagnostics' => $diagnostics, 'projected_count' => $projected);
    }

    private function markRegion(DOMElement $region, string $config): void
    {
        $region->setAttribute('data-blocks-engine-choice-group', 'true');
        $region->setAttribute('data-blocks-engine-choice-config', $config);
    }

    /** @param array<int, DOMElement> $roots @param array<int, array<string, mixed>> $choices @return array<int, array<int, array<string, mixed>>>|null */
    private function stateBindings(array $roots, array $choices): ?array
    {
        $snapshots = array();
        $content = array();
        $groupContent = array();
        foreach ($roots as $root) {
            $groupContent[] = $this->nodeContentFingerprint($root);
            $snapshot = array();
            foreach ($choices as $index => $choice) {
                $nodes = $this->choiceNodes($root, $choice);
                if (! isset($nodes[$index])) return null;
                $snapshot[$index] = $this->nodeSnapshot($nodes[$index]);
                $content[$index][] = $this->nodeContentFingerprint($nodes[$index]);
            }
            $snapshots[] = $snapshot;
        }
        if (1 < count(array_unique($groupContent, SORT_STRING))) return null;
        foreach ($content as $fingerprints) if (1 < count(array_unique($fingerprints, SORT_STRING))) return null;

        $bindings = array_fill(0, count($roots), array());
        foreach ($choices as $choiceIndex => $_choice) {
            $paths = array();
            foreach ($snapshots as $snapshot) foreach ($snapshot[$choiceIndex] as $path => $attributes) {
                $pathKey = (string) $path;
                $paths[$pathKey] ??= array('path' => array_map('intval', '' === $pathKey ? array() : explode('.', $pathKey)), 'attributes' => array());
                foreach ($attributes as $name => $value) $paths[$pathKey]['attributes'][$name] = true;
            }
            foreach ($paths as $path => $descriptor) {
                foreach (array_keys($descriptor['attributes']) as $name) {
                    $values = array_map(static fn(array $snapshot): ?string => $snapshot[$choiceIndex][$path][$name] ?? null, $snapshots);
                    if (1 === count(array_unique($values, SORT_REGULAR))) continue;
                    foreach ($snapshots as $stateIndex => $snapshot) {
                        $nodeIndex = null;
                        foreach ($bindings[$stateIndex] as $candidateIndex => $candidate) if (($candidate['choiceIndex'] ?? null) === $choiceIndex) { $nodeIndex = $candidateIndex; break; }
                        if (null === $nodeIndex) { $bindings[$stateIndex][] = array('choiceIndex' => $choiceIndex, 'nodes' => array()); $nodeIndex = array_key_last($bindings[$stateIndex]); }
                        $bindings[$stateIndex][$nodeIndex]['nodes'][$path] ??= array('path' => $descriptor['path'], 'attributes' => array());
                        $bindings[$stateIndex][$nodeIndex]['nodes'][$path]['attributes'][$name] = $snapshot[$choiceIndex][$path][$name] ?? null;
                    }
                }
            }
        }
        foreach ($bindings as &$stateBindings) {
            foreach ($stateBindings as &$binding) $binding['nodes'] = array_values($binding['nodes']);
            unset($binding);
        }
        unset($stateBindings);
        return $bindings;
    }

    private function nodeContentFingerprint(DOMElement $root): string
    {
        $content = strtolower($root->tagName) . '>';
        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement) $content .= '<' . $this->nodeContentFingerprint($child) . '</' . strtolower($child->tagName) . '>';
            elseif (XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType) $content .= '#' . $child->textContent;
        }

        return $content;
    }

    /** @param array<string, mixed> $choice @return array<int, DOMElement> */
    private function choiceNodes(DOMElement $root, array $choice): array
    {
        $tag = strtolower((string) ($choice['tag'] ?? ''));
        $role = strtolower((string) ($choice['role'] ?? ''));
        if ('' === $tag) return array();
        $nodes = array();
        foreach ($root->getElementsByTagName($tag) as $node) {
            if ($node instanceof DOMElement && strtolower($node->getAttribute('role')) === $role) $nodes[] = $node;
        }
        return $nodes;
    }

    /** @return array<string, array<string, string>> */
    private function nodeSnapshot(DOMElement $root): array
    {
        $snapshot = array();
        $walk = function (DOMElement $node, array $path) use (&$walk, &$snapshot): void {
            $key = implode('.', $path);
            $snapshot[$key] = array();
            foreach ($node->attributes as $attribute) $snapshot[$key][strtolower($attribute->name)] = $attribute->value;
            $elementIndex = 0;
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement) $walk($child, array_merge($path, array($elementIndex++)));
            }
        };
        $walk($root, array());
        return $snapshot;
    }

    private function fragmentRoot(string $html): ?DOMElement
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML('<?xml encoding="UTF-8"><div data-choice-fragment="true">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return null;
        }
        $wrapper = $document->getElementsByTagName('div')->item(0);
        foreach ($wrapper?->childNodes ?? array() as $child) {
            if ($child instanceof DOMElement) {
                return $child;
            }
        }

        return null;
    }

    private function innerHtml(DOMElement $element): string
    {
        $html = '';
        foreach ($element->childNodes as $child) {
            $html .= $element->ownerDocument?->saveHTML($child) ?? '';
        }

        return $html;
    }

    /** @return array{status:'matched'|'unmatched'|'ambiguous', elements:array<int, DOMElement>} */
    private function findRegions(DOMDocument $document, string $selector): array
    {
        if ('' === $selector) {
            return array('status' => 'unmatched', 'elements' => array());
        }
        $elements = array();
        foreach ($this->documentScopes($document) as $scope) {
            $matched = $this->selectorMatches($scope, $selector);
            if (count($matched) > 1) {
                return array('status' => 'ambiguous', 'elements' => array());
            }
            if (1 === count($matched)) {
                $elements[] = $matched[0];
            }
        }
        return array('status' => array() === $elements ? 'unmatched' : 'matched', 'elements' => $elements);
    }

    /** @return array<int, DOMElement> */
    private function documentScopes(DOMDocument $document): array
    {
        $body = $document->getElementsByTagName('body')->item(0) ?? $document->documentElement;
        if (! $body instanceof DOMElement) {
            return array();
        }
        $scopes = array();
        foreach ($body->childNodes as $child) {
            if ($child instanceof DOMElement && $this->isResponsiveDocumentWrapper($child)) {
                $scopes[] = $child;
            }
        }
        return array() === $scopes ? array($body) : $scopes;
    }

    private function isResponsiveDocumentWrapper(DOMElement $element): bool
    {
        foreach (preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array() as $class) {
            if (str_starts_with($class, 'site-document-variant-') || in_array($class, array('data-liberation-desktop-document', 'data-liberation-mobile-document'), true)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int, DOMElement> */
    private function selectorMatches(DOMElement $scope, string $selector): array
    {
        if (str_contains($selector, ',') || ! str_contains($selector, '>')) {
            return array();
        }
        $parts = preg_split('/\s*>\s*/', $selector);
        if (! is_array($parts) || array() === $parts) {
            return array();
        }
        if ('body' === strtolower($parts[0])) {
            array_shift($parts);
        }
        if (array() === $parts) {
            return array();
        }
        if (1 === preg_match('/^([a-z][a-z0-9]*)/i', $parts[0], $token) && strtolower($scope->tagName) === strtolower($token[1])) {
            array_shift($parts);
        }
        $current = array($scope);
        foreach ($parts as $part) {
            if (1 !== preg_match('/^([a-z][a-z0-9]*)(#([A-Za-z][A-Za-z0-9_.:-]*))?(:nth-of-type\((\d+)\))?$/i', $part, $tokens)) {
                return array();
            }
            $tag = strtolower($tokens[1]);
            $id = $tokens[3] ?? '';
            $nth = isset($tokens[5]) ? (int) $tokens[5] : 0;
            $next = array();
            foreach ($current as $node) {
                $seen = 0;
                foreach ($node->childNodes as $child) {
                    if (! $child instanceof DOMElement || strtolower($child->tagName) !== $tag) {
                        continue;
                    }
                    ++$seen;
                    if ('' !== $id && $child->getAttribute('id') !== $id || 0 < $nth && $seen !== $nth) {
                        continue;
                    }
                    $next[] = $child;
                }
            }
            if (array() === $next) {
                return array();
            }
            $current = $next;
        }
        return $current;
    }

    private function safeHtml(string $html): ?string
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML('<?xml encoding="UTF-8"><div data-choice-root="true">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return null;
        }
        $xpath = new \DOMXPath($document);
        foreach (array('script', 'style', 'noscript', 'iframe', 'object', 'embed', 'template') as $tag) {
            foreach ($xpath->query('//' . $tag) ?: array() as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
        foreach ($xpath->query('//*') ?: array() as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }
            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                $value = trim($attribute->value);
                if (str_starts_with($name, 'on') || 'srcdoc' === $name || 'data-dla-choice-index' === $name || (in_array($name, array('href', 'src'), true) && preg_match('/^\s*(?:javascript|data\s*:\s*text\/html)/i', $value))) {
                    $element->removeAttribute($attribute->name);
                }
            }
        }
        $root = $xpath->query('//*[@data-choice-root="true"]')?->item(0);
        if (! $root instanceof DOMElement) {
            return null;
        }
        $result = '';
        foreach ($root->childNodes as $node) {
            $result .= $document->saveHTML($node);
        }
        return is_string($result) ? $result : null;
    }

    /** @param array<int, array<string, mixed>> $files @return array<string, mixed>|null */
    private function jsonFile(array $files, string $path): ?array
    {
        foreach ($files as $file) {
            if ($path === ($file['path'] ?? null) && is_string($file['content'] ?? null) && strlen($file['content']) <= 2 * 1024 * 1024) {
                $decoded = json_decode($file['content'], true);
                return is_array($decoded) ? $decoded : null;
            }
        }
        return null;
    }

    private function normalizedUrl(string $url): string
    {
        return rtrim(trim($url), '/');
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function diagnostic(string $code, string $severity, string $message, array $context = array()): array
    {
        return array_filter(array('code' => $code, 'severity' => $severity, 'message' => $message, 'source' => self::class, 'context' => $context), static fn(mixed $value): bool => array() !== $value);
    }
}
