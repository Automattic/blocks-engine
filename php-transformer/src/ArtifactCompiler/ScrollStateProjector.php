<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use DOMDocument;
use DOMElement;

/**
 * Projects captured scroll-driven class/style toggle evidence (a header/logo
 * that shrinks or gains a background once the page scrolls past some offset)
 * into the matching source document, as a marker attribute pair consumed by
 * HtmlCompilation's scroll-state dispatch.
 *
 * The report and receipt sidecars are consumed structurally: this projector
 * validates the shape and value types it actually reads and does not assume
 * any particular capture tool produced them, so it carries no vendor schema
 * identity or capture-tool-specific attribute/class names. A capture tool
 * that marks one side of a responsive document pair with its own class
 * tokens (beyond this engine's own `ResponsiveDocumentVariants`-composed
 * `site-document-variant-*` classes) declares those tokens itself via the
 * receipt's optional `document_scope_classes` list.
 */
final class ScrollStateProjector
{
    private const MAX_PAGES = 128;
    private const MAX_TOGGLES_PER_PAGE = 8;
    private const MAX_STYLE_TARGETS_PER_TOGGLE = 4;
    private const MAX_DOCUMENT_SCOPE_CLASSES = 16;

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array{files:array<int, array<string, mixed>>, diagnostics:array<int, array<string, mixed>>, projected_count:int}
     */
    public function project(array $files): array
    {
        $report = $this->jsonFile($files, 'scroll-states.json');
        if (null === $report) {
            return array('files' => $files, 'diagnostics' => array(), 'projected_count' => 0);
        }
        if (! is_array($report['pages'] ?? null)) {
            return array('files' => $files, 'diagnostics' => array($this->diagnostic('captured_scroll_states_invalid', 'warning', 'The captured scroll-state report has a malformed or missing pages list.')), 'projected_count' => 0);
        }
        if (count($report['pages']) > self::MAX_PAGES) {
            return array('files' => $files, 'diagnostics' => array($this->diagnostic('captured_scroll_states_limit_exceeded', 'warning', 'The captured scroll-state report exceeded the page limit.', array('max_pages' => self::MAX_PAGES))), 'projected_count' => 0);
        }

        $receipt = $this->jsonFile($files, 'capture-receipt.json');
        if (null === $receipt || ! is_array($receipt['routes'] ?? null)) {
            return array('files' => $files, 'diagnostics' => array($this->diagnostic('captured_scroll_states_route_map_missing', 'warning', 'Captured scroll states were not projected because the capture receipt route map is unavailable.')), 'projected_count' => 0);
        }

        $routes = array();
        foreach ($receipt['routes'] as $route) {
            if (! is_array($route) || ! is_string($route['url'] ?? null) || ! is_string($route['path'] ?? null)) {
                continue;
            }
            $routes[$this->normalizedUrl($route['url'])] = $route['path'];
        }
        $documentScopeClasses = $this->documentScopeClasses($receipt['document_scope_classes'] ?? null);
        $fileIndexes = array();
        foreach ($files as $index => $file) {
            if (is_string($file['path'] ?? null)) {
                $fileIndexes[$file['path']] = $index;
            }
        }

        $diagnostics = array();
        $projected = 0;
        foreach ($report['pages'] as $page) {
            if (! is_array($page) || ! is_string($page['sourceUrl'] ?? null) || ! is_array($page['toggles'] ?? null)) {
                $diagnostics[] = $this->diagnostic('captured_scroll_state_page_invalid', 'warning', 'A captured scroll-state page was ignored because its source URL or toggles are invalid.');
                continue;
            }
            if (count($page['toggles']) > self::MAX_TOGGLES_PER_PAGE) {
                $diagnostics[] = $this->diagnostic('captured_scroll_state_limit_exceeded', 'warning', 'A captured scroll-state page exceeded the toggle limit.', array('source_url' => $page['sourceUrl'], 'max_toggles' => self::MAX_TOGGLES_PER_PAGE));
                continue;
            }
            $path = $routes[$this->normalizedUrl($page['sourceUrl'])] ?? '';
            $index = $fileIndexes[$path] ?? null;
            if (! is_int($index) || ! is_string($files[$index]['content'] ?? null)) {
                $diagnostics[] = $this->diagnostic('captured_scroll_state_source_unmatched', 'warning', 'A captured scroll-state page did not match an artifact HTML document.', array('source_url' => $page['sourceUrl']));
                continue;
            }

            $projection = $this->projectPage((string) $files[$index]['content'], $page['toggles'], $path, $documentScopeClasses);
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
     * @param array<int, mixed> $toggles
     * @param array<int, string> $documentScopeClasses
     * @return array{html:string, diagnostics:array<int, array<string, mixed>>, projected_count:int}
     */
    private function projectPage(string $html, array $toggles, string $sourcePath, array $documentScopeClasses): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return array('html' => $html, 'diagnostics' => array($this->diagnostic('captured_scroll_state_source_invalid', 'warning', 'A captured scroll state was not projected because the source HTML could not be parsed.', array('source_path' => $sourcePath))), 'projected_count' => 0);
        }

        $diagnostics = array();
        $projected = 0;
        foreach ($toggles as $toggle) {
            if (! is_array($toggle) || 'captured' !== ($toggle['status'] ?? null) || ! is_array($toggle['target'] ?? null)) {
                continue;
            }
            $target = $toggle['target'];
            $thresholdPx = is_numeric($toggle['thresholdPx'] ?? null) ? (float) $toggle['thresholdPx'] : 0.0;
            $classes = is_array($toggle['classes'] ?? null) ? $toggle['classes'] : array();
            $addClasses = $this->stringList($classes['add'] ?? null);
            $removeClasses = $this->stringList($classes['remove'] ?? null);
            $styleTargets = $this->normalizedStyleTargets($toggle['styleTargets'] ?? null);
            if (array() === $addClasses && array() === $removeClasses && array() === $styleTargets) {
                continue;
            }

            $found = $this->findTarget($document, $target, $documentScopeClasses);
            if ('ambiguous' === $found['status']) {
                $diagnostics[] = $this->diagnostic('captured_scroll_state_target_ambiguous', 'warning', 'A captured scroll-state target matched multiple source elements in the same route or responsive document scope.', array('source_path' => $sourcePath, 'selector' => (string) ($target['selector'] ?? '')));
                continue;
            }
            $elements = $found['elements'];
            if (array() === $elements) {
                $diagnostics[] = $this->diagnostic('captured_scroll_state_target_unmatched', 'warning', 'A captured scroll-state target did not match a bounded source element set.', array('source_path' => $sourcePath, 'selector' => (string) ($target['selector'] ?? '')));
                continue;
            }

            $config = array(
                'thresholdPx' => $thresholdPx,
                'addClasses' => $addClasses,
                'removeClasses' => $removeClasses,
                'styleTargets' => $styleTargets,
            );
            $encoded = json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            foreach ($elements as $element) {
                $element->setAttribute('data-blocks-engine-scroll-state', 'true');
                $element->setAttribute('data-blocks-engine-scroll-state-config', $encoded);
                ++$projected;
            }
        }

        $output = $document->saveHTML();
        $output = is_string($output) ? preg_replace('/^<\?xml encoding="UTF-8">/i', '', $output) : null;
        return array('html' => is_string($output) ? $output : $html, 'diagnostics' => $diagnostics, 'projected_count' => $projected);
    }

    /**
     * @param array<int, mixed>|null $targets
     * @return array<int, array{selector:string, properties:array<string, array{rest:string, scrolled:string}>}>
     */
    private function normalizedStyleTargets(?array $targets): array
    {
        if (null === $targets) {
            return array();
        }
        $normalized = array();
        foreach (array_slice($targets, 0, self::MAX_STYLE_TARGETS_PER_TOGGLE) as $target) {
            if (! is_array($target) || ! is_string($target['selector'] ?? null) ) {
                continue;
            }
            $selector = trim($target['selector']);
            if ( '' === $selector ) {
                continue;
            }
            if (! is_array($target['properties'] ?? null)) {
                continue;
            }
            $properties = array();
            foreach ($target['properties'] as $property => $values) {
                if (! is_string($property) || ! is_array($values) || ! is_string($values['rest'] ?? null) || ! is_string($values['scrolled'] ?? null)) {
                    continue;
                }
                $properties[$property] = array('rest' => $values['rest'], 'scrolled' => $values['scrolled']);
            }
            if (array() === $properties) {
                continue;
            }
            $normalized[] = array('selector' => $target['selector'], 'properties' => $properties);
        }
        return $normalized;
    }

    /** @return array<int, string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return array();
        }
        return array_values(array_filter(array_map(
            static fn(mixed $item): string => is_string($item) ? $item : '',
            $value
        ), static fn(string $item): bool => '' !== $item));
    }

    /**
     * @param array<string, mixed> $target
     * @param array<int, string> $documentScopeClasses
     * @return array{status:'matched'|'unmatched'|'ambiguous', elements:array<int, DOMElement>}
     */
    private function findTarget(DOMDocument $document, array $target, array $documentScopeClasses): array
    {
        $scopes = $this->documentScopes($document, $documentScopeClasses);
        if (count($scopes) > self::MAX_TOGGLES_PER_PAGE) {
            return array('status' => 'ambiguous', 'elements' => array());
        }
        $elements = array();
        foreach ($scopes as $scope) {
            $matched = $this->matchInScope($scope, $target);
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

    /**
     * @param array<int, string> $documentScopeClasses
     * @return array<int, DOMElement>
     */
    private function documentScopes(DOMDocument $document, array $documentScopeClasses): array
    {
        $body = $document->getElementsByTagName('body')->item(0) ?? $document->documentElement;
        if (! $body instanceof DOMElement) {
            return array();
        }
        $scopes = array();
        foreach ($body->childNodes as $child) {
            if ($child instanceof DOMElement && $this->isResponsiveDocumentWrapper($child, $documentScopeClasses)) {
                $scopes[] = $child;
            }
        }
        return array() === $scopes ? array($body) : $scopes;
    }

    /**
     * A responsive document scope is either this engine's own
     * `ResponsiveDocumentVariants`-composed wrapper, or a class the capture
     * tool declared itself via the receipt's `document_scope_classes` list.
     * This projector never assumes a class name belongs to any one tool.
     *
     * @param array<int, string> $documentScopeClasses
     */
    private function isResponsiveDocumentWrapper(DOMElement $element, array $documentScopeClasses): bool
    {
        foreach (preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array() as $class) {
            if (str_starts_with($class, ResponsiveDocumentVariants::DOCUMENT_VARIANT_CLASS_PREFIX) || in_array($class, $documentScopeClasses, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Duck-typed: any array of non-empty strings is accepted as consumer-
     * declared document-scope class tokens. Absent or malformed input simply
     * yields no additional tokens (the engine's own prefix still applies).
     *
     * @return array<int, string>
     */
    private function documentScopeClasses(mixed $value): array
    {
        if (! is_array($value)) {
            return array();
        }
        $classes = array();
        foreach (array_slice($value, 0, self::MAX_DOCUMENT_SCOPE_CLASSES) as $class) {
            if (is_string($class) && '' !== trim($class)) {
                $classes[] = trim($class);
            }
        }
        return $classes;
    }

    /**
     * @param array<string, mixed> $target
     * @return array<int, DOMElement>
     */
    private function matchInScope(DOMElement $scope, array $target): array
    {
        $selector = is_string($target['selector'] ?? null) ? trim($target['selector']) : '';
        if (str_starts_with($selector, '#') && 1 === preg_match('/^#[A-Za-z][A-Za-z0-9_.:-]*$/', $selector)) {
            $matches = $this->byId($scope, substr($selector, 1));
            if (array() !== $matches) {
                return $matches;
            }
        }
        return $this->bySelectorPath($scope, $selector);
    }

    /** @return array<int, DOMElement> */
    private function byId(DOMElement $scope, string $id): array
    {
        $document = $scope->ownerDocument;
        if (! $document instanceof DOMDocument) {
            return array();
        }
        $elements = array();
        foreach ($document->getElementsByTagName('*') as $element) {
            if ($element instanceof DOMElement && $element->getAttribute('id') === $id && $this->isWithin($scope, $element)) {
                $elements[] = $element;
            }
        }
        return $elements;
    }

    private function isWithin(DOMElement $scope, DOMElement $element): bool
    {
        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode instanceof DOMElement ? $node->parentNode : null) {
            if ($node === $scope) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int, DOMElement> */
    private function bySelectorPath(DOMElement $scope, string $selector): array
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
        $current = array($scope);
        foreach ($parts as $index => $part) {
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
            if (count($next) > self::MAX_TOGGLES_PER_PAGE) {
                return $next;
            }
            $current = $next;
        }
        return $current;
    }

    /** @param array<int, array<string, mixed>> $files @return array<string, mixed>|null */
    private function jsonFile(array $files, string $path): ?array
    {
        foreach ($files as $file) {
            $filePath = (string) ($file['path'] ?? '');
            if ( $path !== $filePath && basename($filePath) !== $path ) {
                continue;
            }
            if ( ! is_string($file['content'] ?? null) || strlen($file['content']) > 2 * 1024 * 1024 ) {
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
