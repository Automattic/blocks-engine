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

            $projection = $this->projectPage((string) $files[$index]['content'], $page['states'], $path, is_array($page['viewport'] ?? null) ? $page['viewport'] : null);
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
     * @param array<string, mixed>|null $viewport
     * @return array{html:string, diagnostics:array<int, array<string, mixed>>, projected_count:int}
     */
    private function projectPage(string $html, array $states, string $sourcePath, ?array $viewport): array
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
            $match = $this->matchTriggers($document, $state['trigger'], $viewport);
            if ('matched' !== $match['status']) {
                $ambiguous = 'ambiguous' === $match['status'];
                $diagnostics[] = $this->diagnostic(
                    $ambiguous ? 'captured_dialog_trigger_ambiguous' : 'captured_dialog_trigger_unmatched',
                    'warning',
                    $ambiguous ? 'A captured dialog trigger matched more than one bounded source element.' : 'A captured dialog trigger did not match a bounded source element set.',
                    array('source_path' => $sourcePath, 'selector' => (string) ($state['trigger']['selector'] ?? ''))
                );
                continue;
            }
            $triggers = $match['elements'];
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
     * @param array<string, mixed>|null $viewport
     * @return array{status:'matched'|'unmatched'|'ambiguous', elements:array<int, DOMElement>}
     */
    private function matchTriggers(DOMDocument $document, array $trigger, ?array $viewport): array
    {
        $scope = $this->responsiveScope($document, $viewport);
        $candidates = $this->declarativeMatches($document, $scope, $trigger);
        if (array() === $candidates) {
            $candidates = $this->identityMatches($document, $scope, $trigger);
        }
        $candidates = $this->uniqueElements($candidates);
        if (1 === count($candidates)) {
            return array('status' => 'matched', 'elements' => array_values($candidates));
        }
        if (1 < count($candidates)) {
            return array('status' => 'ambiguous', 'elements' => array());
        }
        return array('status' => 'unmatched', 'elements' => array());
    }

    /** @param array<string, mixed>|null $viewport */
    private function responsiveScope(DOMDocument $document, ?array $viewport): DOMElement
    {
        $fallback = $document->documentElement instanceof DOMElement ? $document->documentElement : $document->createElement('body');
        $roots = $this->responsiveRoots($document);
        if (array() === $roots) {
            return $fallback;
        }
        $width = $this->viewportWidth($viewport);
        if (null !== $width) {
            foreach ($this->preferredResponsiveTokens($width) as $token) {
                foreach ($roots as $root) {
                    if ($token === $root['token']) {
                        return $root['element'];
                    }
                }
            }
        }
        return 1 === count($roots) ? $roots[0]['element'] : $fallback;
    }

    /**
     * @return array<int, array{token:string, element:DOMElement}>
     */
    private function responsiveRoots(DOMDocument $document): array
    {
        $roots = array();
        foreach ($document->getElementsByTagName('*') as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }
            $token = $this->responsiveToken($element);
            if (null === $token) {
                continue;
            }
            $roots[] = array('token' => $token, 'element' => $element);
        }
        return $roots;
    }

    private function responsiveToken(DOMElement $element): ?string
    {
        foreach (preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array() as $class) {
            if (1 === preg_match('/^data-liberation-([a-z0-9_-]+)-document$/', $class, $match)) {
                return strtolower($match[1]);
            }
            if (1 === preg_match('/^site-document-variant-([a-z0-9_-]+)$/', $class, $match)) {
                return strtolower($match[1]);
            }
        }
        return null;
    }

    /** @param array<string, mixed>|null $viewport */
    private function viewportWidth(?array $viewport): ?int
    {
        if (! is_array($viewport) || ! isset($viewport['width']) || ! is_numeric($viewport['width'])) {
            return null;
        }
        return (int) $viewport['width'];
    }

    /** @return array<int, string> */
    private function preferredResponsiveTokens(int $width): array
    {
        if ($width <= 600) {
            return array('mobile', 'compact', 'narrow');
        }
        if ($width <= 1024) {
            return array('tablet', 'mobile', 'default');
        }
        return array('desktop', 'default', 'wide');
    }

    /**
     * @param array<string, mixed> $trigger
     * @return array<int, DOMElement>
     */
    private function declarativeMatches(DOMDocument $document, DOMElement $scope, array $trigger): array
    {
        $query = $this->declarativeQuery($trigger);
        if ('' === $query) {
            return array();
        }
        $matches = (new DOMXPath($document))->query($query);
        if (false === $matches || 0 === $matches->length) {
            return array();
        }
        if ($matches->length > self::MAX_STATES_PER_PAGE) {
            return array();
        }
        $elements = array();
        foreach ($matches as $element) {
            if (! $element instanceof DOMElement || ! $this->isWithin($element, $scope) || ! $this->corroborates($element, $trigger, true)) {
                continue;
            }
            $elements[] = $element;
        }
        return $elements;
    }

    /** @param array<string, mixed> $trigger */
    private function declarativeQuery(array $trigger): string
    {
        $selector = is_string($trigger['selector'] ?? null) ? trim($trigger['selector']) : '';
        if (str_starts_with($selector, '#') && 1 === preg_match('/^#[A-Za-z][A-Za-z0-9_.:-]*$/', $selector)) {
            return '//*[@id=' . $this->xpathLiteral(substr($selector, 1)) . ']';
        }
        foreach ($this->capturedBindings($trigger) as $name => $value) {
            return '//*[@' . $name . '=' . $this->xpathLiteral($value) . ']';
        }
        return '';
    }

    /**
     * @param array<string, mixed> $trigger
     * @return array<int, DOMElement>
     */
    private function identityMatches(DOMDocument $document, DOMElement $scope, array $trigger): array
    {
        if ('' === $this->capturedLabel($trigger) && '' === $this->capturedPopup($trigger) && array() === $this->capturedBindings($trigger)) {
            return array();
        }
        $matches = (new DOMXPath($document))->query('.//*[self::button or self::summary or self::a or self::input or translate(@role,"BUTTON","button")="button" or @aria-haspopup]', $scope);
        if (false === $matches) {
            return array();
        }
        $elements = array();
        foreach ($matches as $element) {
            if (! $element instanceof DOMElement || ! $this->isControlLike($element) || ! $this->corroborates($element, $trigger, false)) {
                continue;
            }
            $elements[] = $element;
            if (count($elements) > self::MAX_STATES_PER_PAGE) {
                return $elements;
            }
        }
        return $elements;
    }

    /**
     * @param array<string, mixed> $trigger
     */
    private function corroborates(DOMElement $element, array $trigger, bool $declarative): bool
    {
        $label = $this->capturedLabel($trigger);
        if (! $this->tagMatches($element, $trigger, '' !== $label || $declarative)) {
            return false;
        }
        if (! $this->popupMatches($element, $trigger, $declarative)) {
            return false;
        }
        if (! $this->bindingMatches($element, $trigger)) {
            return false;
        }
        return '' === $label || $this->normalizedName($this->accessibleName($element)) === $this->normalizedName($label);
    }

    /** @param array<string, mixed> $trigger */
    private function tagMatches(DOMElement $element, array $trigger, bool $allowEquivalent): bool
    {
        $captured = is_string($trigger['tag'] ?? null) ? strtolower(trim($trigger['tag'])) : '';
        $tag = strtolower($element->tagName);
        if ('' === $captured || $tag === $captured) {
            return true;
        }
        if (! $allowEquivalent) {
            return false;
        }
        $role = strtolower(trim($element->getAttribute('role')));
        $capturedControl = in_array($captured, array('button', 'summary'), true);
        $elementControl = in_array($tag, array('button', 'summary'), true) || 'button' === $role;
        return $capturedControl && $elementControl;
    }

    /** @param array<string, mixed> $trigger */
    private function popupMatches(DOMElement $element, array $trigger, bool $declarative): bool
    {
        $captured = $this->capturedPopup($trigger);
        $popup = strtolower(trim($element->getAttribute('aria-haspopup')));
        if ('' === $captured) {
            return ! $declarative || in_array($popup, array('', 'dialog', 'true', 'menu'), true);
        }
        $accepted = in_array($captured, array('dialog', 'true'), true) ? array('dialog', 'true') : array($captured);
        return in_array($popup, $accepted, true);
    }

    /** @param array<string, mixed> $trigger */
    private function bindingMatches(DOMElement $element, array $trigger): bool
    {
        foreach ($this->capturedBindings($trigger) as $name => $value) {
            if ($value !== $element->getAttribute($name)) {
                return false;
            }
        }
        return true;
    }

    private function isControlLike(DOMElement $element): bool
    {
        $tag = strtolower($element->tagName);
        if (in_array($tag, array('button', 'summary'), true)) {
            return true;
        }
        if ('a' === $tag && 'button' === strtolower(trim($element->getAttribute('role')))) {
            return true;
        }
        if ('input' === $tag && in_array(strtolower($element->getAttribute('type')), array('button', 'submit', 'image'), true)) {
            return true;
        }
        return 'button' === strtolower(trim($element->getAttribute('role'))) || '' !== trim($element->getAttribute('aria-haspopup'));
    }

    private function accessibleName(DOMElement $element): string
    {
        $label = trim($element->getAttribute('aria-label'));
        if ('' !== $label) {
            return $label;
        }
        $labelledBy = trim($element->getAttribute('aria-labelledby'));
        if ('' !== $labelledBy && $element->ownerDocument instanceof DOMDocument) {
            $parts = array();
            foreach (preg_split('/\s+/', $labelledBy) ?: array() as $id) {
                $node = $element->ownerDocument->getElementById($id);
                if ($node instanceof DOMElement) {
                    $parts[] = trim($node->textContent ?? '');
                }
            }
            $joined = trim(implode(' ', $parts));
            if ('' !== $joined) {
                return $joined;
            }
        }
        return trim($element->textContent ?? '');
    }

    private function normalizedName(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }

    /** @param array<string, mixed> $trigger */
    private function capturedLabel(array $trigger): string
    {
        return is_string($trigger['label'] ?? null) ? trim($trigger['label']) : '';
    }

    /** @param array<string, mixed> $trigger */
    private function capturedPopup(array $trigger): string
    {
        return is_string($trigger['ariaHaspopup'] ?? null) ? strtolower(trim($trigger['ariaHaspopup'])) : '';
    }

    /**
     * @param array<string, mixed> $trigger
     * @return array<string, string>
     */
    private function capturedBindings(array $trigger): array
    {
        $bindings = is_array($trigger['dataBindings'] ?? null) ? $trigger['dataBindings'] : array();
        $captured = array();
        foreach ($bindings as $name => $value) {
            if (is_string($name) && is_string($value) && 1 === preg_match('/^data-(?:popup|modal|dialog)(?:id|target)?$/i', $name) && '' !== $value) {
                $captured[strtolower($name)] = $value;
            }
        }
        return $captured;
    }

    private function isWithin(DOMElement $element, DOMElement $scope): bool
    {
        return $element === $scope || $scope->contains($element);
    }

    /**
     * @param array<int, DOMElement> $elements
     * @return array<int, DOMElement>
     */
    private function uniqueElements(array $elements): array
    {
        $unique = array();
        foreach ($elements as $element) {
            $unique[spl_object_id($element)] = $element;
        }
        return array_values($unique);
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
