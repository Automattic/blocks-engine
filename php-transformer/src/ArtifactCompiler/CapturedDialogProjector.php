<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use DOMDocument;
use DOMElement;
use DOMXPath;

/** Projects bounded captured-dialog evidence into the matching source document. */
final class CapturedDialogProjector
{
    private const REPORT_SCHEMA = 'data-liberation/captured-interactions/v1';
    private const RECEIPT_SCHEMA = 'data-liberation/capture-receipt/v1';
    private const MAX_PAGES = 128;
    private const MAX_STATES_PER_PAGE = 8;
    private const MAX_DIALOG_BYTES = 65536;

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
            return array('files' => $files, 'diagnostics' => array($this->diagnostic('captured_interactions_invalid', 'warning', 'The captured interaction report has an unsupported schema or pages shape.')), 'projected_count' => 0);
        }
        if (count($report['pages']) > self::MAX_PAGES) {
            return array('files' => $files, 'diagnostics' => array($this->diagnostic('captured_interactions_limit_exceeded', 'warning', 'The captured interaction report exceeded the page limit.', array('max_pages' => self::MAX_PAGES))), 'projected_count' => 0);
        }

        $receipt = $this->jsonFile($files, 'capture-receipt.json');
        if (null === $receipt || self::RECEIPT_SCHEMA !== ($receipt['schema'] ?? null) || ! is_array($receipt['routes'] ?? null)) {
            return array('files' => $files, 'diagnostics' => array($this->diagnostic('captured_interactions_route_map_missing', 'warning', 'Captured dialogs were not projected because the capture receipt route map is unavailable.')), 'projected_count' => 0);
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
                $diagnostics[] = $this->diagnostic('captured_interaction_page_invalid', 'warning', 'A captured interaction page was ignored because its source URL or states are invalid.');
                continue;
            }
            if (count($page['states']) > self::MAX_STATES_PER_PAGE) {
                $diagnostics[] = $this->diagnostic('captured_interaction_state_limit_exceeded', 'warning', 'A captured interaction page exceeded the state limit.', array('source_url' => $page['sourceUrl'], 'max_states' => self::MAX_STATES_PER_PAGE));
                continue;
            }
            $path = $routes[$this->normalizedUrl($page['sourceUrl'])] ?? '';
            $index = $fileIndexes[$path] ?? null;
            if (! is_int($index) || ! is_string($files[$index]['content'] ?? null)) {
                $diagnostics[] = $this->diagnostic('captured_interaction_source_unmatched', 'warning', 'A captured interaction page did not match an artifact HTML document.', array('source_url' => $page['sourceUrl']));
                continue;
            }

            $projection = $this->projectPage((string) $files[$index]['content'], $page['states'], $path);
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
     * @return array{html:string, diagnostics:array<int, array<string, mixed>>, projected_count:int}
     */
    private function projectPage(string $html, array $states, string $sourcePath): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return array('html' => $html, 'diagnostics' => array($this->diagnostic('captured_interaction_source_invalid', 'warning', 'Captured dialogs were not projected because the source HTML could not be parsed.', array('source_path' => $sourcePath))), 'projected_count' => 0);
        }

        $diagnostics = array();
        $projected = 0;
        foreach ($states as $state) {
            if (! is_array($state) || 'captured' !== ($state['status'] ?? null) || ! is_array($state['trigger'] ?? null) || ! is_array($state['dialog'] ?? null)) {
                continue;
            }
            $dialog = $state['dialog'];
            $dialogHtml = is_string($dialog['html'] ?? null) ? $dialog['html'] : '';
            $declaredBytes = is_int($dialog['htmlBytes'] ?? null) ? $dialog['htmlBytes'] : -1;
            if ('' === $dialogHtml || ! empty($dialog['htmlTruncated']) || strlen($dialogHtml) > self::MAX_DIALOG_BYTES || $declaredBytes !== strlen($dialogHtml)) {
                $diagnostics[] = $this->diagnostic('captured_dialog_unsafe_or_truncated', 'warning', 'A captured dialog was ignored because its HTML is empty, truncated, or exceeds the byte limit.', array('source_path' => $sourcePath));
                continue;
            }
            $resolved = $this->findTriggers($document, $state['trigger']);
            if ('ambiguous' === $resolved['status']) {
                $diagnostics[] = $this->diagnostic('captured_dialog_trigger_ambiguous', 'warning', 'A captured dialog trigger matched more than one bounded source element.', array('source_path' => $sourcePath, 'selector' => (string) ($state['trigger']['selector'] ?? '')));
                continue;
            }
            $triggers = $resolved['elements'];
            if (array() === $triggers) {
                $diagnostics[] = $this->diagnostic('captured_dialog_trigger_unmatched', 'warning', 'A captured dialog trigger did not match a bounded source element set.', array('source_path' => $sourcePath, 'selector' => (string) ($state['trigger']['selector'] ?? '')));
                continue;
            }
            $fragment = $this->safeDialogFragment($dialogHtml);
            if (null === $fragment) {
                $diagnostics[] = $this->diagnostic('captured_dialog_markup_invalid', 'warning', 'A captured dialog was ignored because its markup could not be sanitized.', array('source_path' => $sourcePath));
                continue;
            }

            $identity = substr(hash('sha256', $sourcePath . "\n" . ($state['trigger']['selector'] ?? '') . "\n" . $dialogHtml), 0, 16);
            $triggerIds = array();
            foreach ($triggers as $triggerIndex => $trigger) {
                $triggerId = trim($trigger->getAttribute('id'));
                if ('' === $triggerId) {
                    $triggerId = 'blocks-engine-dialog-trigger-' . $identity . '-' . ($triggerIndex + 1);
                    $trigger->setAttribute('id', $triggerId);
                }
                $triggerIds[] = $triggerId;
            }
            $dialogId = 'blocks-engine-dialog-' . $identity;
            $dialogElement = $document->createElement('dialog');
            $dialogElement->setAttribute('id', $dialogId);
            $dialogElement->setAttribute('data-blocks-engine-captured-dialog', 'true');
            $dialogElement->setAttribute('data-blocks-engine-triggers', implode(' ', $triggerIds));
            if (is_string($fragment['class']) && '' !== $fragment['class']) $dialogElement->setAttribute('class', $fragment['class']);
            if (is_string($fragment['aria_label']) && '' !== $fragment['aria_label']) $dialogElement->setAttribute('aria-label', $fragment['aria_label']);
            if (is_string($fragment['aria_labelledby']) && '' !== $fragment['aria_labelledby']) $dialogElement->setAttribute('aria-labelledby', $fragment['aria_labelledby']);
            if (is_string($fragment['aria_describedby']) && '' !== $fragment['aria_describedby']) $dialogElement->setAttribute('aria-describedby', $fragment['aria_describedby']);
            if (! $fragment['has_close_control']) $dialogElement->setAttribute('data-blocks-engine-add-close', 'true');
            foreach ($fragment['nodes'] as $node) {
                $dialogElement->appendChild($document->importNode($node, true));
            }
            ($document->getElementsByTagName('body')->item(0) ?? $document->documentElement)?->appendChild($dialogElement);
            ++$projected;
        }

        $output = $document->saveHTML();
        $output = is_string($output) ? preg_replace('/^<\?xml encoding="UTF-8">/i', '', $output) : null;
        return array('html' => is_string($output) ? $output : $html, 'diagnostics' => $diagnostics, 'projected_count' => $projected);
    }

    /**
     * @param array<string, mixed> $trigger
     * @return array{status:'matched'|'unmatched'|'ambiguous', elements:array<int, DOMElement>}
     */
    private function findTriggers(DOMDocument $document, array $trigger): array
    {
        $scopes = $this->documentScopes($document);
        if (count($scopes) > self::MAX_STATES_PER_PAGE) {
            return array('status' => 'ambiguous', 'elements' => array());
        }
        $elements = array();
        foreach ($scopes as $scope) {
            $matched = $this->matchTriggersInRoot($document, $scope, $trigger);
            if (count($matched) > 1) {
                return array('status' => 'ambiguous', 'elements' => array());
            }
            if (1 === count($matched)) {
                $elements[] = $matched[0];
            }
        }
        if (count($elements) > self::MAX_STATES_PER_PAGE) {
            return array('status' => 'ambiguous', 'elements' => array());
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
            if ($child instanceof DOMElement && $this->isResponsiveDocument($child)) {
                $scopes[] = $child;
            }
        }
        return array() === $scopes ? array($body) : $scopes;
    }

    private function isResponsiveDocument(DOMElement $element): bool
    {
        foreach (preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array() as $class) {
            if (1 === preg_match('/^(?:site-document-variant-[a-z0-9_-]+|data-liberation-[a-z0-9]+-document)$/i', $class)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $trigger
     * @return array<int, DOMElement>
     */
    private function matchTriggersInRoot(DOMDocument $document, DOMDocument|DOMElement $root, array $trigger): array
    {
        $xpath = new DOMXPath($document);
        $scoped = $this->scopedQuery($root);
        $candidates = array();
        $selector = is_string($trigger['selector'] ?? null) ? trim($trigger['selector']) : '';
        if (str_starts_with($selector, '#') && 1 === preg_match('/^#[A-Za-z][A-Za-z0-9_.:-]*$/', $selector)) {
            $candidates = $this->queryElements($xpath, $scoped . '[@id=' . $this->xpathLiteral(substr($selector, 1)) . ']', $root);
        }
        if (array() === $candidates) {
            $bindings = is_array($trigger['dataBindings'] ?? null) ? $trigger['dataBindings'] : array();
            foreach ($bindings as $name => $value) {
                if (is_string($name) && is_string($value) && 1 === preg_match('/^data-(?:popup|modal|dialog)(?:id|target)?$/i', $name) && '' !== $value) {
                    $candidates = $this->queryElements($xpath, $scoped . '[@' . strtolower($name) . '=' . $this->xpathLiteral($value) . ']', $root);
                    break;
                }
            }
        }
        if (array() === $candidates) {
            $candidates = $this->childPathElements($xpath, $root, $selector);
        }
        $filtered = $this->filterTriggerEvidence($candidates, $trigger);
        if (array() !== $filtered) {
            return $filtered;
        }
        $label = is_string($trigger['label'] ?? null) ? trim($trigger['label']) : '';
        if ('' === $label) {
            return array();
        }
        $labelLiteral = $this->xpathLiteral($label);
        $evidence = $this->queryElements(
            $xpath,
            $scoped . '[(self::button or self::a or self::summary or translate(@role,"BUTTON","button")="button") and (normalize-space(@aria-label)=' . $labelLiteral . ' or normalize-space(.)=' . $labelLiteral . ')]',
            $root
        );
        return $this->filterTriggerEvidence($evidence, $trigger);
    }

    private function scopedQuery(DOMDocument|DOMElement $root): string
    {
        return $root instanceof DOMDocument ? '//*' : './/*';
    }

    /** @return array<int, DOMElement> */
    private function queryElements(DOMXPath $xpath, string $query, DOMDocument|DOMElement $root): array
    {
        $matches = $root instanceof DOMDocument ? $xpath->query($query) : $xpath->query($query, $root);
        if (false === $matches || 0 === $matches->length || $matches->length > self::MAX_STATES_PER_PAGE) {
            return array();
        }
        $elements = array();
        foreach ($matches as $element) {
            if ($element instanceof DOMElement) {
                $elements[] = $element;
            }
        }
        return $elements;
    }

    /** @return array<int, DOMElement> */
    private function childPathElements(DOMXPath $xpath, DOMDocument|DOMElement $root, string $selector): array
    {
        $relative = preg_replace('/^body\s*>\s*/i', '', $selector);
        $query = $this->childPathQuery(is_string($relative) ? $relative : '');
        if (null === $query) {
            return array();
        }
        $prefix = $root instanceof DOMDocument ? '/' : './';
        return $this->queryElements($xpath, $prefix . $query, $root);
    }

    private function childPathQuery(string $selector): ?string
    {
        $selector = trim($selector);
        if ('' === $selector || 1 !== preg_match('/^[A-Za-z][A-Za-z0-9-]*(?::nth-of-type\([1-9][0-9]*\))?(?: > [A-Za-z][A-Za-z0-9-]*(?::nth-of-type\([1-9][0-9]*\))?)*$/', $selector)) {
            return null;
        }
        $parts = array();
        foreach (explode(' > ', $selector) as $part) {
            if (1 !== preg_match('/^([A-Za-z][A-Za-z0-9-]*)(?::nth-of-type\(([1-9][0-9]*)\))?$/', $part, $match)) {
                return null;
            }
            $parts[] = strtolower($match[1]) . (isset($match[2]) ? '[' . $match[2] . ']' : '');
        }
        return implode('/', $parts);
    }

    /**
     * @param array<int, DOMElement> $elements
     * @param array<string, mixed> $trigger
     * @return array<int, DOMElement>
     */
    private function filterTriggerEvidence(array $elements, array $trigger): array
    {
        $tag = is_string($trigger['tag'] ?? null) ? strtolower($trigger['tag']) : '';
        $label = is_string($trigger['label'] ?? null) ? trim($trigger['label']) : '';
        $capturedPopup = strtolower(trim(is_string($trigger['ariaHaspopup'] ?? null) ? $trigger['ariaHaspopup'] : ''));
        $filtered = array();
        foreach ($elements as $element) {
            if (! $this->compatibleTag($tag, strtolower($element->tagName), $element)) {
                continue;
            }
            if ('' !== $capturedPopup) {
                $popup = strtolower(trim($element->getAttribute('aria-haspopup')));
                if (! in_array($popup, array('dialog', 'true'), true) || ! in_array($capturedPopup, array('dialog', 'true', $popup), true)) {
                    continue;
                }
            }
            if ('' !== $label && $label !== $this->accessibleName($element)) {
                continue;
            }
            if (! $this->bindingsCompatible($element, $trigger)) {
                continue;
            }
            $filtered[] = $element;
        }
        return $filtered;
    }

    /** @param array<string, mixed> $trigger */
    private function bindingsCompatible(DOMElement $element, array $trigger): bool
    {
        $bindings = is_array($trigger['dataBindings'] ?? null) ? $trigger['dataBindings'] : array();
        foreach ($bindings as $name => $value) {
            if (! is_string($name) || ! is_string($value) || '' === $value) {
                continue;
            }
            if (1 !== preg_match('/^data-[a-z0-9_.:-]+$/i', $name)) {
                return false;
            }
            if (strtolower($element->getAttribute($name)) !== strtolower($value)) {
                return false;
            }
        }
        return true;
    }

    private function compatibleTag(string $captured, string $actual, DOMElement $element): bool
    {
        if ('' === $captured || $captured === $actual) {
            return true;
        }
        $controls = array('button', 'summary', 'a');
        if (in_array($captured, $controls, true) && in_array($actual, $controls, true)) {
            return true;
        }
        return in_array($captured, $controls, true) && 'button' === strtolower($element->getAttribute('role'));
    }

    private function accessibleName(DOMElement $element): string
    {
        $aria = trim($element->getAttribute('aria-label'));
        if ('' !== $aria) {
            return $aria;
        }
        return trim((string) preg_replace('/\s+/u', ' ', $element->textContent ?? ''));
    }

    /** @return array{nodes:array<int, \DOMNode>, class:string, aria_label:string, aria_labelledby:string, aria_describedby:string, has_close_control:bool}|null */
    private function safeDialogFragment(string $html): ?array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML('<?xml encoding="UTF-8"><div data-dialog-root="true">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) return null;
        $xpath = new DOMXPath($document);
        foreach (array('script', 'iframe', 'object', 'embed', 'template') as $tag) {
            $matches = $xpath->query('//' . $tag);
            if (false === $matches) continue;
            foreach (iterator_to_array($matches) as $node) $node->parentNode?->removeChild($node);
        }
        foreach ($xpath->query('//*') ?: array() as $element) {
            if (! $element instanceof DOMElement) continue;
            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                $value = trim($attribute->value);
                if (str_starts_with($name, 'on') || 'srcdoc' === $name || ('form' === strtolower($element->tagName) && in_array($name, array('action', 'method'), true)) || (in_array($name, array('href', 'src'), true) && preg_match('/^\s*(?:javascript|data\s*:\s*text\/html)/i', $value))) {
                    $element->removeAttribute($attribute->name);
                }
            }
        }
        $wrapper = $xpath->query('//*[@data-dialog-root="true"]')?->item(0);
        if (! $wrapper instanceof DOMElement) return null;
        $sourceRoot = null;
        foreach ($wrapper->childNodes as $node) {
            if ($node instanceof DOMElement) { $sourceRoot = $node; break; }
        }
        $container = $sourceRoot instanceof DOMElement ? $sourceRoot : $wrapper;
        $hasCloseControl = false;
        foreach ($container->getElementsByTagName('button') as $button) {
            $label = strtolower(trim($button->getAttribute('aria-label')));
            $text = strtolower(trim($button->textContent ?? ''));
            if (str_contains($label, 'close') || in_array($text, array('close', 'x', '×'), true) || $button->hasAttribute('data-close') || $button->hasAttribute('data-dismiss')) {
                $button->setAttribute('data-blocks-engine-dialog-close', 'true');
                $hasCloseControl = true;
                break;
            }
        }
        return array(
            'nodes' => iterator_to_array($container->childNodes),
            'class' => $sourceRoot instanceof DOMElement ? trim($sourceRoot->getAttribute('class')) : '',
            'aria_label' => $sourceRoot instanceof DOMElement ? trim($sourceRoot->getAttribute('aria-label')) : '',
            'aria_labelledby' => $sourceRoot instanceof DOMElement ? trim($sourceRoot->getAttribute('aria-labelledby')) : '',
            'aria_describedby' => $sourceRoot instanceof DOMElement ? trim($sourceRoot->getAttribute('aria-describedby')) : '',
            'has_close_control' => $hasCloseControl,
        );
    }

    /** @param array<int, array<string, mixed>> $files @return array<string, mixed>|null */
    private function jsonFile(array $files, string $path): ?array
    {
        foreach ($files as $file) {
            if ($path !== ($file['path'] ?? null) || ! is_string($file['content'] ?? null) || strlen($file['content']) > 2 * 1024 * 1024) continue;
            $decoded = json_decode($file['content'], true);
            return is_array($decoded) ? $decoded : null;
        }
        return null;
    }

    private function normalizedUrl(string $url): string
    {
        return rtrim(trim($url), '/');
    }

    private function xpathLiteral(string $value): string
    {
        if (! str_contains($value, "'")) return "'" . $value . "'";
        if (! str_contains($value, '"')) return '"' . $value . '"';
        return 'concat(' . implode(', "\'", ', array_map(static fn(string $part): string => "'" . $part . "'", explode("'", $value))) . ')';
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function diagnostic(string $code, string $severity, string $message, array $context = array()): array
    {
        return array_filter(array('code' => $code, 'severity' => $severity, 'message' => $message, 'source' => self::class, 'context' => $context), static fn(mixed $value): bool => array() !== $value);
    }
}
