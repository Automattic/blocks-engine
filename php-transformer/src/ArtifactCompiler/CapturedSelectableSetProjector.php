<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use DOMDocument;
use DOMElement;

/**
 * Projects captured selectable-set evidence (N members driving one shared
 * region) into ARIA tabs markup that converts to core/tabs.
 */
final class CapturedSelectableSetProjector
{
    private const REPORT_SCHEMA = 'data-liberation/captured-interactions/v1';
    private const RECEIPT_SCHEMA = 'data-liberation/capture-receipt/v1';
    private const KIND = 'selectable-set';
    private const MAX_PAGES = 128;
    private const MAX_SETS_PER_PAGE = 8;
    private const MAX_MEMBERS_PER_SET = 32;
    private const MAX_REGION_BYTES = 65536;
    private const MAX_SET_INLINE_BYTES = 262144;
    private const GRAPHIC_HOST_TAGS = array(
        'svg', 'g', 'path', 'text', 'tspan', 'rect', 'circle', 'ellipse',
        'polygon', 'polyline', 'line', 'use', 'image', 'foreignobject', 'canvas', 'area',
    );

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array{files:array<int, array<string, mixed>>, diagnostics:array<int, array<string, mixed>>, projected_count:int}
     */
    public function project(array $files): array
    {
        $diagnostics = array();
        $report = $this->jsonFile($files, 'interaction-states.json');
        if (null === $report) {
            return array('files' => $files, 'diagnostics' => array(), 'projected_count' => 0);
        }
        if (self::REPORT_SCHEMA !== ($report['schema'] ?? null) || ! is_array($report['pages'] ?? null)) {
            return array('files' => $files, 'diagnostics' => array(), 'projected_count' => 0);
        }
        if (count($report['pages']) > self::MAX_PAGES) {
            return array('files' => $files, 'diagnostics' => array($this->diagnostic('captured_selectable_set_limit_exceeded', 'warning', 'The captured interaction report exceeded the page limit.', array('max_pages' => self::MAX_PAGES))), 'projected_count' => 0);
        }

        $hasSelectableSets = false;
        foreach ($report['pages'] as $page) {
            if (! is_array($page) || ! is_array($page['states'] ?? null)) {
                continue;
            }
            foreach ($page['states'] as $state) {
                if (is_array($state) && self::KIND === ($state['kind'] ?? null)) {
                    $hasSelectableSets = true;
                    break 2;
                }
            }
        }
        if (! $hasSelectableSets) {
            return array('files' => $files, 'diagnostics' => array(), 'projected_count' => 0);
        }

        $receipt = $this->jsonFile($files, 'capture-receipt.json');
        if (null === $receipt || self::RECEIPT_SCHEMA !== ($receipt['schema'] ?? null) || ! is_array($receipt['routes'] ?? null)) {
            return array('files' => $files, 'diagnostics' => array($this->diagnostic('captured_selectable_set_route_map_missing', 'warning', 'Captured selectable sets were not projected because the capture receipt route map is unavailable.')), 'projected_count' => 0);
        }

        $routes = array();
        foreach ($receipt['routes'] as $route) {
            if (! is_array($route) || ! is_string($route['url'] ?? null) || ! is_string($route['path'] ?? null)) {
                continue;
            }
            $routes[$this->normalizedUrl($route['url'])] = $route['path'];
        }
        $fileIndexes = array();
        foreach ($files as $index => $file) {
            if (is_string($file['path'] ?? null)) {
                $fileIndexes[$file['path']] = $index;
            }
        }

        $projected = 0;
        foreach ($report['pages'] as $page) {
            if (! is_array($page) || ! is_string($page['sourceUrl'] ?? null) || ! is_array($page['states'] ?? null)) {
                $diagnostics[] = $this->diagnostic('captured_selectable_set_page_invalid', 'warning', 'A captured interaction page was ignored because its source URL or states are invalid.');
                continue;
            }
            $sets = $this->groupedSets($page['states'], $page['sourceUrl'], $diagnostics);
            if (count($sets) > self::MAX_SETS_PER_PAGE) {
                $diagnostics[] = $this->diagnostic('captured_selectable_set_limit_exceeded', 'warning', 'A captured interaction page exceeded the selectable-set limit.', array('source_url' => $page['sourceUrl'], 'max_sets' => self::MAX_SETS_PER_PAGE));
                continue;
            }
            $path = $routes[$this->normalizedUrl($page['sourceUrl'])] ?? '';
            $index = $fileIndexes[$path] ?? null;
            if (! is_int($index) || ! is_string($files[$index]['content'] ?? null)) {
                $diagnostics[] = $this->diagnostic('captured_selectable_set_source_unmatched', 'warning', 'A captured selectable set did not match an artifact HTML document.', array('source_url' => $page['sourceUrl']));
                continue;
            }
            if (array() === $sets) {
                continue;
            }

            $projection = $this->projectPage((string) $files[$index]['content'], $sets, $path);
            $diagnostics = array_merge($diagnostics, $projection['diagnostics']);
            if (0 < $projection['projected_count']) {
                $files[$index]['content'] = $projection['html'];
                $files[$index]['bytes'] = strlen($projection['html']);
                $projected += $projection['projected_count'];
            }
        }

        return array('files' => $files, 'diagnostics' => $diagnostics, 'projected_count' => $projected);
    }

    /**
     * @param array<int, mixed> $states
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, array{selector:string, region_selector:string, members:array<int, array{label:string, html:string, tag:string, selector:string}>}>
     */
    private function groupedSets(array $states, string $sourceUrl, array &$diagnostics): array
    {
        $sets = array();
        foreach ($states as $state) {
            if (! is_array($state) || self::KIND !== ($state['kind'] ?? null)) {
                continue;
            }
            $status = is_string($state['status'] ?? null) ? $state['status'] : '';
            if (in_array($status, array('no-dialog', 'click-failed'), true)) {
                $diagnostics[] = $this->diagnostic('captured_selectable_set_member_failed', 'warning', 'A selectable-set member was omitted because capture did not produce region content.', array('source_url' => $sourceUrl, 'status' => $status));
                continue;
            }
            if ('captured' !== $status || ! is_array($state['trigger'] ?? null) || ! is_array($state['dialog'] ?? null) || ! is_array($state['set'] ?? null)) {
                continue;
            }
            $setSelector = is_string($state['set']['selector'] ?? null) ? trim($state['set']['selector']) : '';
            if ('' === $setSelector) {
                $diagnostics[] = $this->diagnostic('captured_selectable_set_invalid', 'warning', 'A selectable-set member was omitted because its set selector is missing.', array('source_url' => $sourceUrl));
                continue;
            }
            $dialog = $state['dialog'];
            $dialogHtml = is_string($dialog['html'] ?? null) ? $dialog['html'] : '';
            $declaredBytes = is_int($dialog['htmlBytes'] ?? null) ? $dialog['htmlBytes'] : -1;
            if ('' === $dialogHtml || ! empty($dialog['htmlTruncated']) || strlen($dialogHtml) > self::MAX_REGION_BYTES || $declaredBytes !== strlen($dialogHtml)) {
                $diagnostics[] = $this->diagnostic('captured_selectable_set_member_truncated', 'warning', 'A selectable-set member was omitted because its HTML is empty, truncated, or exceeds the byte limit.', array('source_url' => $sourceUrl));
                continue;
            }
            $sanitized = $this->safeRegionHtml($dialogHtml);
            if (null === $sanitized || '' === trim($sanitized)) {
                $diagnostics[] = $this->diagnostic('captured_selectable_set_markup_invalid', 'warning', 'A selectable-set member was omitted because its markup could not be sanitized.', array('source_url' => $sourceUrl));
                continue;
            }
            $index = is_int($state['set']['index'] ?? null) ? $state['set']['index'] : count($sets[$setSelector]['members'] ?? array());
            if (! isset($sets[$setSelector])) {
                $regionSelector = is_string($dialog['selector'] ?? null) ? trim($dialog['selector']) : '';
                $sets[$setSelector] = array('selector' => $setSelector, 'region_selector' => $regionSelector, 'members' => array());
            }
            if (isset($sets[$setSelector]['members'][$index])) {
                continue;
            }
            $sets[$setSelector]['members'][$index] = array(
                'label' => $this->memberLabel($state, $index),
                'html' => $sanitized,
                'tag' => is_string($state['trigger']['tag'] ?? null) ? strtolower($state['trigger']['tag']) : '',
                'selector' => is_string($state['trigger']['selector'] ?? null) ? trim($state['trigger']['selector']) : '',
            );
        }

        $bounded = array();
        foreach ($sets as $key => $set) {
            ksort($set['members'], SORT_NUMERIC);
            $members = array();
            $bytes = 0;
            $omitted = 0;
            foreach ($set['members'] as $member) {
                if (count($members) >= self::MAX_MEMBERS_PER_SET) {
                    ++$omitted;
                    continue;
                }
                $next = $bytes + strlen($member['html']);
                if ($next > self::MAX_SET_INLINE_BYTES) {
                    ++$omitted;
                    continue;
                }
                $members[] = $member;
                $bytes = $next;
            }
            if ($omitted > 0) {
                $diagnostics[] = $this->diagnostic('captured_selectable_set_bounded', 'warning', 'A selectable set exceeded the inline member or byte budget; later members were omitted.', array('source_url' => $sourceUrl, 'omitted' => $omitted, 'max_members' => self::MAX_MEMBERS_PER_SET, 'max_inline_bytes' => self::MAX_SET_INLINE_BYTES));
            }
            if (count($members) < 2) {
                $diagnostics[] = $this->diagnostic('captured_selectable_set_insufficient_members', 'warning', 'A selectable set was not projected because fewer than two captured members remained.', array('source_url' => $sourceUrl));
                continue;
            }
            $set['members'] = $members;
            $bounded[$key] = $set;
        }

        return $bounded;
    }

    /**
     * @param array<string, array{selector:string, region_selector:string, members:array<int, array{label:string, html:string, tag:string, selector:string}>}> $sets
     * @return array{html:string, diagnostics:array<int, array<string, mixed>>, projected_count:int}
     */
    private function projectPage(string $html, array $sets, string $sourcePath): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return array('html' => $html, 'diagnostics' => array($this->diagnostic('captured_selectable_set_source_invalid', 'warning', 'Captured selectable sets were not projected because the source HTML could not be parsed.', array('source_path' => $sourcePath))), 'projected_count' => 0);
        }

        $diagnostics = array();
        $projected = 0;
        foreach ($sets as $set) {
            $identity = substr(hash('sha256', $sourcePath . "\n" . $set['selector']), 0, 16);
            $regions = $this->findRegions($document, $set['region_selector']);
            if ('ambiguous' === $regions['status']) {
                $diagnostics[] = $this->diagnostic('captured_selectable_set_region_ambiguous', 'warning', 'A captured selectable-set region matched multiple source elements in the same route or responsive document scope.', array('source_path' => $sourcePath, 'selector' => $set['region_selector']));
                continue;
            }
            $targets = $regions['elements'];
            if (array() === $targets) {
                $body = $document->getElementsByTagName('body')->item(0) ?? $document->documentElement;
                if (! $body instanceof DOMElement) {
                    $diagnostics[] = $this->diagnostic('captured_selectable_set_region_unmatched', 'warning', 'A captured selectable-set region did not match a source element and could not be appended.', array('source_path' => $sourcePath, 'selector' => $set['region_selector']));
                    continue;
                }
                $fallback = $document->createElement('div');
                $body->appendChild($fallback);
                $targets = array($fallback);
                $diagnostics[] = $this->diagnostic('captured_selectable_set_region_appended', 'warning', 'A captured selectable-set region was unmatched; the tabs were appended to the document.', array('source_path' => $sourcePath, 'selector' => $set['region_selector']));
            }
            $members = $this->withSourceLabels($document, $set['members']);
            $hideTabList = ! $this->hasDistinctVisibleTriggerRow($members);
            foreach ($targets as $scopeIndex => $region) {
                $rowIdentity = $identity . '-' . ($scopeIndex + 1);
                $triggerRow = $hideTabList ? null : $this->triggerRowForRegion($region, $members, $set['selector']);
                $this->fillRegion($document, $region, $members, $rowIdentity, $hideTabList, $triggerRow);
            }
            ++$projected;
        }

        $output = $document->saveHTML();
        $output = is_string($output) ? preg_replace('/^<\?xml encoding="UTF-8">/i', '', $output) : null;
        return array('html' => is_string($output) ? $output : $html, 'diagnostics' => $diagnostics, 'projected_count' => $projected);
    }

    /**
     * @param array<int, array{label:string, html:string, tag:string, selector:string}> $members
     */
    private function fillRegion(DOMDocument $document, DOMElement $region, array $members, string $identity, bool $hideTabList, ?DOMElement $triggerRow): void
    {
        $rowClass = $triggerRow instanceof DOMElement ? trim($triggerRow->getAttribute('class')) : '';
        $rowStyle = $triggerRow instanceof DOMElement ? trim($triggerRow->getAttribute('style')) : '';
        while ($region->firstChild) {
            $region->removeChild($region->firstChild);
        }
        $region->setAttribute('data-blocks-engine-captured-selectable-set', 'true');
        $region->setAttribute('data-tabs', '');
        $tabList = $document->createElement('div');
        $tabList->setAttribute('role', 'tablist');
        $tabList->setAttribute('aria-label', 'Items');
        if ($hideTabList) {
            $tabList->setAttribute('data-blocks-engine-tablist-presentation', 'hidden');
        } elseif ($triggerRow instanceof DOMElement) {
            if ('' !== $rowClass) {
                $tabList->setAttribute('class', $rowClass);
            }
            if ('' !== $rowStyle) {
                $tabList->setAttribute('style', $rowStyle);
            }
            $tabList->setAttribute('data-blocks-engine-tablist-row', $identity);
            if ($triggerRow->parentNode) {
                $triggerRow->setAttribute('data-blocks-engine-tablist-row', $identity);
            }
        }
        foreach ($members as $index => $member) {
            $tabId = 'blocks-engine-set-' . $identity . '-tab-' . $index;
            $panelId = 'blocks-engine-set-' . $identity . '-panel-' . $index;
            $button = $document->createElement('button');
            $button->setAttribute('type', 'button');
            $button->setAttribute('role', 'tab');
            $button->setAttribute('id', $tabId);
            $button->setAttribute('aria-controls', $panelId);
            $button->setAttribute('aria-selected', 0 === $index ? 'true' : 'false');
            $button->appendChild($document->createTextNode($member['label']));
            $tabList->appendChild($button);
        }
        $region->appendChild($tabList);
        foreach ($members as $index => $member) {
            $panel = $document->createElement('section');
            $panel->setAttribute('id', 'blocks-engine-set-' . $identity . '-panel-' . $index);
            $panel->setAttribute('role', 'tabpanel');
            $panel->setAttribute('aria-labelledby', 'blocks-engine-set-' . $identity . '-tab-' . $index);
            foreach ($this->fragmentNodes($member['html']) as $node) {
                $panel->appendChild($document->importNode($node, true));
            }
            $region->appendChild($panel);
        }
    }

    /**
     * @return array{status:'matched'|'unmatched'|'ambiguous', elements:array<int, DOMElement>}
     */
    private function findRegions(DOMDocument $document, string $selector): array
    {
        if ('' === $selector) {
            return array('status' => 'unmatched', 'elements' => array());
        }
        $scopes = $this->documentScopes($document);
        if (count($scopes) > self::MAX_SETS_PER_PAGE) {
            return array('status' => 'ambiguous', 'elements' => array());
        }
        $elements = array();
        foreach ($scopes as $scope) {
            $matched = $this->selectorMatches($scope, $selector);
            if (count($matched) > 1) {
                return array('status' => 'ambiguous', 'elements' => array());
            }
            if (1 === count($matched)) {
                $elements[] = $matched[0];
            }
        }
        if (array() === $elements) {
            return array('status' => 'unmatched', 'elements' => array());
        }
        return array('status' => 'matched', 'elements' => $elements);
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
        if ('' === $selector || str_contains($selector, ',') || ! str_contains($selector, '>')) {
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
        if (1 === preg_match('/^([a-z][a-z0-9]*)/i', $parts[0], $scopeToken) && strtolower($scope->tagName) === strtolower($scopeToken[1])) {
            array_shift($parts);
        }
        if (array() === $parts) {
            return array($scope);
        }
        $current = array($scope);
        foreach ($parts as $part) {
            if (1 !== preg_match('/^([a-z][a-z0-9]*)(#([A-Za-z][A-Za-z0-9_.:-]*))?(:nth-of-type\((\d+)\))?$/i', $part, $tokens)) {
                return array();
            }
            $tag = strtolower($tokens[1]);
            $id = $tokens[3] ?? '';
            $nth = isset($tokens[5]) && '' !== $tokens[5] ? (int) $tokens[5] : 0;
            $next = array();
            foreach ($current as $node) {
                $seen = array();
                foreach ($node->childNodes as $child) {
                    if (! $child instanceof DOMElement) {
                        continue;
                    }
                    $childTag = strtolower($child->tagName);
                    $seen[$childTag] = ($seen[$childTag] ?? 0) + 1;
                    if ($childTag !== $tag) {
                        continue;
                    }
                    if ('' !== $id && $child->getAttribute('id') !== $id) {
                        continue;
                    }
                    if ($nth > 0 && ($seen[$tag] ?? 0) !== $nth) {
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

    /** @param array<string, mixed> $state */
    private function memberLabel(array $state, int $index): string
    {
        $raw = is_string($state['trigger']['label'] ?? null) ? $state['trigger']['label'] : '';
        $label = trim(preg_replace('/\s+/', ' ', strip_tags($raw)) ?? '');
        if ('' === $label) {
            return 'Item ' . ($index + 1);
        }

        return $label;
    }

    /**
     * @param array<int, array{label:string, html:string, tag:string, selector:string}> $members
     * @return array<int, array{label:string, html:string, tag:string, selector:string}>
     */
    private function withSourceLabels(DOMDocument $document, array $members): array
    {
        foreach ($members as $index => $member) {
            $element = $this->triggerElement($document, $member['selector']);
            if (! $element instanceof DOMElement) {
                continue;
            }
            $label = $this->labelFromTriggerElement($element);
            if ('' !== $label) {
                $members[$index]['label'] = $label;
            }
        }

        return $members;
    }

    private function triggerElement(DOMDocument $document, string $selector): ?DOMElement
    {
        if ('' === $selector) {
            return null;
        }
        foreach ($this->documentScopes($document) as $scope) {
            $matched = $this->selectorMatches($scope, $selector);
            if (1 === count($matched)) {
                return $matched[0];
            }
        }

        return null;
    }

    private function labelFromTriggerElement(DOMElement $element): string
    {
        $named = trim($element->getAttribute('aria-label'));
        if ('' !== $named) {
            return trim(preg_replace('/\s+/', ' ', $named) ?? '');
        }
        $title = trim($element->getAttribute('title'));
        if ('' !== $title) {
            return trim(preg_replace('/\s+/', ' ', $title) ?? '');
        }

        return trim(implode(' ', $this->labelParts($element)));
    }

    /** @return array<int, string> */
    private function labelParts(DOMElement $element): array
    {
        $parts = array();
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                if (in_array(strtolower($child->tagName), array('script', 'style', 'desc'), true)) {
                    continue;
                }
                $parts = array_merge($parts, $this->labelParts($child));
                continue;
            }
            if (XML_TEXT_NODE === $child->nodeType) {
                $text = trim(preg_replace('/\s+/', ' ', $child->textContent ?? '') ?? '');
                if ('' !== $text) {
                    $parts[] = $text;
                }
            }
        }

        return $parts;
    }

    /**
     * Closest ancestor that actually laid the triggers out — not the shared
     * region the tab-list is inserted into.
     *
     * @param array<int, array{label:string, html:string, tag:string, selector:string}> $members
     */
    private function triggerRowForRegion(DOMElement $region, array $members, string $setSelector): ?DOMElement
    {
        $scope = $this->scopeRoot($region);
        $triggers = array();
        foreach ($members as $member) {
            $matched = $this->selectorMatches($scope, $member['selector']);
            if (1 !== count($matched)) {
                $triggers = array();
                break;
            }
            $triggers[] = $matched[0];
        }
        $row = array() === $triggers ? null : $this->commonAncestor($triggers);
        if (! $row instanceof DOMElement) {
            $matched = $this->selectorMatches($scope, $setSelector);
            $row = 1 === count($matched) ? $matched[0] : null;
        }
        if (! $row instanceof DOMElement || $row->isSameNode($region) || $this->elementContains($row, $region)) {
            return null;
        }

        return $row;
    }

    private function scopeRoot(DOMElement $element): DOMElement
    {
        $document = $element->ownerDocument;
        if ($document instanceof DOMDocument) {
            foreach ($this->documentScopes($document) as $scope) {
                if ($this->elementContains($scope, $element)) {
                    return $scope;
                }
            }
        }

        return $element;
    }

    /** @param array<int, DOMElement> $elements */
    private function commonAncestor(array $elements): ?DOMElement
    {
        $first = $elements[0] ?? null;
        if (! $first instanceof DOMElement) {
            return null;
        }
        for ($ancestor = $first->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode) {
            foreach ($elements as $element) {
                if (! $this->elementContains($ancestor, $element)) {
                    continue 2;
                }
            }

            return $ancestor;
        }

        return null;
    }

    private function elementContains(DOMElement $container, DOMElement $element): bool
    {
        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
            if ($node->isSameNode($container)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array{label:string, html:string, tag:string, selector:string}> $members
     */
    private function hasDistinctVisibleTriggerRow(array $members): bool
    {
        foreach ($members as $member) {
            if (! $this->isGraphicEmbeddedTrigger($member['tag'], $member['selector'])) {
                return true;
            }
        }

        return false;
    }

    private function isGraphicEmbeddedTrigger(string $tag, string $selector): bool
    {
        if (in_array($tag, self::GRAPHIC_HOST_TAGS, true)) {
            return true;
        }

        return 1 === preg_match('/(?:^|>)\s*(?:svg|canvas|map)(?:\s*[>#.:\[]|\s*$)/i', $selector);
    }

    private function safeRegionHtml(string $html): ?string
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML('<?xml encoding="UTF-8"><div data-region-root="true">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return null;
        }
        $xpath = new \DOMXPath($document);
        foreach (array('script', 'iframe', 'object', 'embed', 'template', 'canvas') as $tag) {
            $matches = $xpath->query('//' . $tag);
            if (false === $matches) {
                continue;
            }
            foreach (iterator_to_array($matches) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
        foreach ($xpath->query('//form') ?: array() as $form) {
            if (! $form instanceof DOMElement || ! $form->parentNode) {
                continue;
            }
            while ($form->firstChild) {
                $form->parentNode->insertBefore($form->firstChild, $form);
            }
            $form->parentNode->removeChild($form);
        }
        foreach ($xpath->query('//*') ?: array() as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }
            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                $value = trim($attribute->value);
                if (str_starts_with($name, 'on') || 'srcdoc' === $name || (in_array($name, array('href', 'src'), true) && preg_match('/^\s*(?:javascript|data\s*:\s*text\/html)/i', $value))) {
                    $element->removeAttribute($attribute->name);
                }
            }
        }
        $wrapper = $xpath->query('//*[@data-region-root="true"]')?->item(0);
        if (! $wrapper instanceof DOMElement) {
            return null;
        }
        $html = '';
        foreach ($wrapper->childNodes as $node) {
            $html .= $document->saveHTML($node);
        }

        return is_string($html) ? $html : null;
    }

    /** @return array<int, \DOMNode> */
    private function fragmentNodes(string $html): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML('<?xml encoding="UTF-8"><div data-region-root="true">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return array();
        }
        $wrapper = (new \DOMXPath($document))->query('//*[@data-region-root="true"]')?->item(0);
        if (! $wrapper instanceof DOMElement) {
            return array();
        }

        return iterator_to_array($wrapper->childNodes);
    }

    /** @param array<int, array<string, mixed>> $files @return array<string, mixed>|null */
    private function jsonFile(array $files, string $path): ?array
    {
        foreach ($files as $file) {
            if ($path !== ($file['path'] ?? null) || ! is_string($file['content'] ?? null) || strlen($file['content']) > 2 * 1024 * 1024) {
                continue;
            }
            $decoded = json_decode($file['content'], true);
            return is_array($decoded) ? $decoded : null;
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
