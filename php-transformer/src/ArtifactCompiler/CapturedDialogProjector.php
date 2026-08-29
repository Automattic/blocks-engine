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
            $found = $this->findTriggers($document, $state['trigger']);
            if ('ambiguous' === $found['status']) {
                $diagnostics[] = $this->diagnostic('captured_dialog_trigger_ambiguous', 'warning', 'A captured dialog trigger matched multiple source elements in the same route or responsive document scope.', array('source_path' => $sourcePath, 'selector' => (string) ($state['trigger']['selector'] ?? '')));
                continue;
            }
            $triggers = $found['elements'];
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
            $matched = $this->matchInScope($scope, $trigger);
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

    /**
     * @param array<string, mixed> $trigger
     * @return array<int, DOMElement>
     */
    private function matchInScope(DOMElement $scope, array $trigger): array
    {
        $identity = $this->identityMatches($scope, $trigger);
        if (array() !== $identity) {
            return $this->filterCandidates($identity, $trigger);
        }
        $selector = $this->selectorMatches($scope, $trigger);
        if (array() !== $selector) {
            return $this->filterCandidates($selector, $trigger);
        }
        return $this->filterCandidates($this->evidenceMatches($scope, $trigger), $trigger);
    }

    /**
     * @param array<string, mixed> $trigger
     * @return array<int, DOMElement>
     */
    private function identityMatches(DOMElement $scope, array $trigger): array
    {
        $document = $scope->ownerDocument;
        if (! $document instanceof DOMDocument) {
            return array();
        }
        $xpath = new DOMXPath($document);
        $selector = is_string($trigger['selector'] ?? null) ? trim($trigger['selector']) : '';
        $query = '';
        if (str_starts_with($selector, '#') && 1 === preg_match('/^#[A-Za-z][A-Za-z0-9_.:-]*$/', $selector)) {
            $query = './/*[@id=' . $this->xpathLiteral(substr($selector, 1)) . ']';
        } else {
            $bindings = is_array($trigger['dataBindings'] ?? null) ? $trigger['dataBindings'] : array();
            foreach ($bindings as $name => $value) {
                if (is_string($name) && is_string($value) && 1 === preg_match('/^data-(?:popup|modal|dialog)(?:id|target)?$/i', $name) && '' !== $value) {
                    $query = './/*[@' . strtolower($name) . '=' . $this->xpathLiteral($value) . ']';
                    break;
                }
            }
        }
        if ('' === $query) {
            return array();
        }
        $matches = $xpath->query($query, $scope);
        if (false === $matches || 0 === $matches->length) {
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

    /**
     * @param array<string, mixed> $trigger
     * @return array<int, DOMElement>
     */
    private function selectorMatches(DOMElement $scope, array $trigger): array
    {
        $selector = is_string($trigger['selector'] ?? null) ? trim($trigger['selector']) : '';
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
            $isLast = $index === array_key_last($parts);
            $next = array();
            foreach ($current as $node) {
                $seen = array();
                foreach ($node->childNodes as $child) {
                    if (! $child instanceof DOMElement) {
                        continue;
                    }
                    $childTag = strtolower($child->tagName);
                    $seen[$childTag] = ($seen[$childTag] ?? 0) + 1;
                    $tagMatches = $childTag === $tag || ($isLast && $this->tagCompatible($tag, $childTag));
                    if (! $tagMatches) {
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
            if (count($next) > self::MAX_STATES_PER_PAGE) {
                return $next;
            }
            $current = $next;
        }
        return $current;
    }

    /**
     * @param array<string, mixed> $trigger
     * @return array<int, DOMElement>
     */
    private function evidenceMatches(DOMElement $scope, array $trigger): array
    {
        $label = $this->normalizedLabel(is_string($trigger['label'] ?? null) ? $trigger['label'] : '');
        if ('' === $label) {
            return array();
        }
        $elements = array();
        foreach ($scope->getElementsByTagName('*') as $element) {
            if (! $element instanceof DOMElement || $this->insideProjectedDialog($element) || ! $this->isInteractiveControl($element)) {
                continue;
            }
            if ($label !== $this->accessibleName($element)) {
                continue;
            }
            $elements[] = $element;
        }
        return $elements;
    }

    /**
     * @param array<int, DOMElement> $elements
     * @param array<string, mixed> $trigger
     * @return array<int, DOMElement>
     */
    private function filterCandidates(array $elements, array $trigger): array
    {
        $tag = is_string($trigger['tag'] ?? null) ? strtolower(trim($trigger['tag'])) : '';
        $label = $this->normalizedLabel(is_string($trigger['label'] ?? null) ? $trigger['label'] : '');
        $capturedPopup = strtolower(trim(is_string($trigger['ariaHaspopup'] ?? null) ? $trigger['ariaHaspopup'] : ''));
        $filtered = array();
        foreach ($elements as $element) {
            if (! $this->tagCompatible($tag, strtolower($element->tagName)) || ! $this->isInteractiveControl($element)) {
                continue;
            }
            if ('' !== $label && $label !== $this->accessibleName($element)) {
                continue;
            }
            $popup = strtolower(trim($element->getAttribute('aria-haspopup')));
            if ('' !== $capturedPopup && $popup !== $capturedPopup && ! (in_array($capturedPopup, array('dialog', 'true'), true) && in_array($popup, array('dialog', 'true'), true))) {
                continue;
            }
            if (! $this->bindingsCompatible($element, $trigger)) {
                continue;
            }
            $filtered[] = $element;
        }
        return array_values($filtered);
    }

    /** @param array<string, mixed> $trigger */
    private function bindingsCompatible(DOMElement $element, array $trigger): bool
    {
        $bindings = is_array($trigger['dataBindings'] ?? null) ? $trigger['dataBindings'] : array();
        foreach ($bindings as $name => $value) {
            if (! is_string($name) || ! is_string($value) || '' === $value || 1 !== preg_match('/^data-[a-z0-9_.:-]+$/i', $name)) {
                if (is_string($value) && '' !== $value) {
                    return false;
                }
                continue;
            }
            if (strtolower($element->getAttribute($name)) !== strtolower($value)) {
                return false;
            }
        }
        return true;
    }

    private function tagCompatible(string $captured, string $actual): bool
    {
        if ('' === $captured || $captured === $actual) {
            return true;
        }
        $interactive = array('a', 'button', 'input', 'summary');
        return in_array($captured, $interactive, true) && in_array($actual, $interactive, true);
    }

    private function isInteractiveControl(DOMElement $element): bool
    {
        $tag = strtolower($element->tagName);
        if (in_array($tag, array('button', 'summary'), true)) {
            return true;
        }
        if ('a' === $tag) {
            return true;
        }
        if ('input' === $tag && in_array(strtolower($element->getAttribute('type')), array('button', 'submit'), true)) {
            return true;
        }
        if ('button' === strtolower(trim($element->getAttribute('role')))) {
            return true;
        }
        return in_array(strtolower(trim($element->getAttribute('aria-haspopup'))), array('dialog', 'menu', 'true'), true);
    }

    private function accessibleName(DOMElement $element): string
    {
        $label = $this->normalizedLabel($element->getAttribute('aria-label'));
        if ('' !== $label) {
            return $label;
        }
        return $this->normalizedLabel($element->textContent ?? '');
    }

    private function normalizedLabel(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }

    private function insideProjectedDialog(DOMElement $element): bool
    {
        for ($parent = $element->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
            if ('dialog' === strtolower($parent->tagName) && 'true' === $parent->getAttribute('data-blocks-engine-captured-dialog')) {
                return true;
            }
        }
        return false;
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
