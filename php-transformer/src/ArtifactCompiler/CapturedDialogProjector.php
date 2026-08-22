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
            $trigger = $this->findTrigger($document, $state['trigger']);
            if (! $trigger instanceof DOMElement) {
                $diagnostics[] = $this->diagnostic('captured_dialog_trigger_unmatched', 'warning', 'A captured dialog trigger did not match exactly one source element.', array('source_path' => $sourcePath, 'selector' => (string) ($state['trigger']['selector'] ?? '')));
                continue;
            }
            $fragment = $this->safeDialogFragment($dialogHtml);
            if (null === $fragment) {
                $diagnostics[] = $this->diagnostic('captured_dialog_markup_invalid', 'warning', 'A captured dialog was ignored because its markup could not be sanitized.', array('source_path' => $sourcePath));
                continue;
            }

            $identity = substr(hash('sha256', $sourcePath . "\n" . ($state['trigger']['selector'] ?? '') . "\n" . $dialogHtml), 0, 16);
            $triggerId = trim($trigger->getAttribute('id'));
            if ('' === $triggerId) {
                $triggerId = 'blocks-engine-dialog-trigger-' . $identity;
                $trigger->setAttribute('id', $triggerId);
            }
            $dialogId = 'blocks-engine-dialog-' . $identity;
            $dialogElement = $document->createElement('dialog');
            $dialogElement->setAttribute('id', $dialogId);
            $dialogElement->setAttribute('data-blocks-engine-captured-dialog', 'true');
            $dialogElement->setAttribute('data-blocks-engine-trigger', $triggerId);
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

    /** @param array<string, mixed> $trigger */
    private function findTrigger(DOMDocument $document, array $trigger): ?DOMElement
    {
        $xpath = new DOMXPath($document);
        $selector = is_string($trigger['selector'] ?? null) ? trim($trigger['selector']) : '';
        $query = '';
        if (str_starts_with($selector, '#') && 1 === preg_match('/^#[A-Za-z][A-Za-z0-9_.:-]*$/', $selector)) {
            $query = '//*[@id=' . $this->xpathLiteral(substr($selector, 1)) . ']';
        } else {
            $bindings = is_array($trigger['dataBindings'] ?? null) ? $trigger['dataBindings'] : array();
            foreach ($bindings as $name => $value) {
                if (is_string($name) && is_string($value) && 1 === preg_match('/^data-(?:popup|modal|dialog)(?:id|target)?$/i', $name) && '' !== $value) {
                    $query = '//*[@' . strtolower($name) . '=' . $this->xpathLiteral($value) . ']';
                    break;
                }
            }
        }
        if ('' === $query) return null;
        $matches = $xpath->query($query);
        if (false === $matches || 1 !== $matches->length) return null;
        $element = $matches->item(0);
        if (! $element instanceof DOMElement) return null;
        $tag = is_string($trigger['tag'] ?? null) ? strtolower($trigger['tag']) : '';
        return '' === $tag || $tag === strtolower($element->tagName) ? $element : null;
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
