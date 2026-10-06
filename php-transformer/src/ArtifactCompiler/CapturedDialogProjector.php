<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
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
        if (! $this->hasDialogStates($report['pages'])) {
            return array('files' => $files, 'diagnostics' => array(), 'projected_count' => 0);
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
        $navigationDropdowns = 0;
        $retired = array();
        foreach ($report['pages'] as $page) {
            if (! is_array($page) || ! is_string($page['sourceUrl'] ?? null) || ! is_array($page['states'] ?? null)) {
                $diagnostics[] = $this->diagnostic('captured_interaction_page_invalid', 'warning', 'A captured interaction page was ignored because its source URL or states are invalid.');
                continue;
            }
            $dialogStates = $this->dialogStates($page['states']);
            if (count($dialogStates) > self::MAX_STATES_PER_PAGE) {
                $diagnostics[] = $this->diagnostic('captured_interaction_state_limit_exceeded', 'warning', 'A captured interaction page exceeded the state limit.', array('source_url' => $page['sourceUrl'], 'max_states' => self::MAX_STATES_PER_PAGE));
                continue;
            }
            if (array() === $dialogStates) {
                continue;
            }
            $path = $routes[$this->normalizedUrl($page['sourceUrl'])] ?? '';
            $index = $fileIndexes[$path] ?? null;
            if (! is_int($index) || ! is_string($files[$index]['content'] ?? null)) {
                $diagnostics[] = $this->diagnostic('captured_interaction_source_unmatched', 'warning', 'A captured interaction page did not match an artifact HTML document.', array('source_url' => $page['sourceUrl']));
                continue;
            }

            $projection = $this->projectPage((string) $files[$index]['content'], $dialogStates, $path);
            $diagnostics = array_merge($diagnostics, $projection['diagnostics']);
            if (0 < $projection['projected_count'] || 0 < ($projection['navigation_dropdown_count'] ?? 0) || array() !== $projection['retired_scripts']) {
                $files[$index]['content'] = $projection['html'];
                $files[$index]['bytes'] = strlen($projection['html']);
                $projected += $projection['projected_count'];
                $navigationDropdowns += $projection['navigation_dropdown_count'] ?? 0;
                foreach ($projection['retired_scripts'] as $body) {
                    $retired[$path][] = $body;
                }
            }
        }
        $proofs = $this->omitRetiredDisclosureScripts($files, $retired);

        return array('files' => $files, 'diagnostics' => $diagnostics, 'projected_count' => $projected, 'navigation_dropdown_count' => $navigationDropdowns, 'native_runtime_replacements' => $proofs);
    }

    /** @param array<int, mixed> $pages */
    private function hasDialogStates(array $pages): bool
    {
        foreach ($pages as $page) {
            if (! is_array($page) || ! is_array($page['states'] ?? null)) {
                continue;
            }
            if (array() !== $this->dialogStates($page['states'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, mixed> $states
     * @return array<int, array<string, mixed>>
     */
    private function dialogStates(array $states): array
    {
        $dialogStates = array();
        foreach ($states as $state) {
            if (is_array($state) && $this->isDialogKind($state)) {
                $dialogStates[] = $state;
            }
        }

        return $dialogStates;
    }

    /** @param array<string, mixed> $state */
    private function isDialogKind(array $state): bool
    {
        $kind = is_string($state['kind'] ?? null) ? $state['kind'] : 'dialog';

        return '' === $kind || 'dialog' === $kind;
    }

    /**
     * @param array<int, mixed> $states
     * @return array{html:string, diagnostics:array<int, array<string, mixed>>, projected_count:int, retired_scripts:array<int, string>}
     */
    private function projectPage(string $html, array $states, string $sourcePath): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return array('html' => $html, 'diagnostics' => array($this->diagnostic('captured_interaction_source_invalid', 'warning', 'Captured dialogs were not projected because the source HTML could not be parsed.', array('source_path' => $sourcePath))), 'projected_count' => 0, 'retired_scripts' => array());
        }

        $diagnostics = array();
        $adoption = $this->adoptWiredPanels($document, $sourcePath);
        $adopted = $adoption['triggers'];
        $projected = $adoption['count'];
        $handledNavigationDropdown = false;
        $navigationDropdowns = 0;
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
            if (array() === $triggers && $this->matchesAdoptedTrigger($adopted, $state['trigger'])) {
                continue;
            }
            if (array() === $triggers) {
                $diagnostics[] = $this->diagnostic('captured_dialog_trigger_unmatched', 'warning', 'A captured dialog trigger did not match a bounded source element set.', array('source_path' => $sourcePath, 'selector' => (string) ($state['trigger']['selector'] ?? '')));
                continue;
            }
            if (array() !== $triggers && array() === array_filter($triggers, fn (DOMElement $trigger): bool => ! $this->isAdopted($adopted, $trigger))) {
                continue;
            }
            // A navigation button with its dropdown panel of links stays in place:
            // it becomes a navigation submenu, not a dialog opened from a button.
            if (array() === array_filter($triggers, fn (DOMElement $trigger): bool => ! $this->isNavigationDropdownTrigger($trigger))) {
                $handledNavigationDropdown = true;
                foreach ($triggers as $trigger) {
                    $this->consumeMatchedCloseHelper($document, array($trigger), $trigger->parentNode);
                }
                continue;
            }
            $fragment = $this->safeDialogFragment($dialogHtml);
            if (null === $fragment) {
                $diagnostics[] = $this->diagnostic('captured_dialog_markup_invalid', 'warning', 'A captured dialog was ignored because its markup could not be sanitized.', array('source_path' => $sourcePath));
                continue;
            }

            $identity = substr(hash('sha256', $sourcePath . "\n" . ($state['trigger']['selector'] ?? '') . "\n" . $dialogHtml), 0, 16);
            if ('dropdown' === strtolower(trim((string) ($dialog['presentation'] ?? ''))) && $this->projectCapturedNavigationDropdown($document, $triggers, $fragment, $identity)) {
                $handledNavigationDropdown = true;
                ++$navigationDropdowns;
                foreach ($triggers as $trigger) {
                    $this->consumeMatchedCloseHelper($document, array($trigger), $trigger->parentNode);
                }
                continue;
            }
            if ('listbox' === strtolower(trim((string) ($dialog['role'] ?? '')))) {
                $key = 'blocks-engine-listbox-' . $identity;
                foreach ($triggers as $trigger) {
                    $trigger->setAttribute('data-dla-listbox-trigger', $key);
                    $trigger->setAttribute('type', 'button');
                    $trigger->removeAttribute('aria-expanded');
                    $trigger->removeAttribute('aria-controls');
                }
                $listbox = $document->createElement('div');
                $listbox->setAttribute('hidden', '');
                $listbox->setAttribute('role', 'listbox');
                $listbox->setAttribute('data-dla-listbox-panel', $key);
                foreach ($fragment['nodes'] as $node) {
                    $listbox->appendChild($document->importNode($node, true));
                }
                ($document->getElementsByTagName('body')->item(0) ?? $document->documentElement)?->appendChild($listbox);
                ++$projected;
                continue;
            }
            $triggerIds = array();
            foreach ($triggers as $triggerIndex => $trigger) {
                $triggerId = trim($trigger->getAttribute('id'));
                if ('' === $triggerId) {
                    $triggerId = 'blocks-engine-dialog-trigger-' . $identity . '-' . ($triggerIndex + 1);
                    $trigger->setAttribute('id', $triggerId);
                }
                $triggerIds[] = $triggerId;
            }
            $dialogElement = $this->appendProjectedDialog($document, $fragment, $triggerIds, $identity);
            $this->consumeMatchedCloseHelper($document, $triggers, $dialogElement);
            ++$projected;
        }
        $retired = array();
        if (($projected > 0 || $handledNavigationDropdown) && !$this->hasDialogCloseHelper($document) && $this->everyDialogTriggerIsBound($document)) {
            foreach (iterator_to_array($document->getElementsByTagName('script')) as $script) {
                if (!$script instanceof DOMElement || !$script->hasAttribute('data-dla-disclosure-runtime')) continue;
                $body = trim($script->textContent ?? '');
                if ('' !== $body) $retired[] = $body;
                $script->parentNode?->removeChild($script);
            }
        }

        $output = $document->saveHTML();
        $output = is_string($output) ? preg_replace('/^<\?xml encoding="UTF-8">/i', '', $output) : null;
        return array('html' => is_string($output) ? $output : $html, 'diagnostics' => $diagnostics, 'projected_count' => $projected, 'navigation_dropdown_count' => $navigationDropdowns, 'retired_scripts' => $retired);
    }

    /**
     * @param array{nodes:array<int, \DOMNode>, class:string, aria_label:string, aria_labelledby:string, aria_describedby:string, has_close_control:bool} $fragment
     * @param array<int, string> $triggerIds
     */
    private function appendProjectedDialog(DOMDocument $document, array $fragment, array $triggerIds, string $identity): DOMElement
    {
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
        return $dialogElement;
    }

    /**
     * A capture that already wired its dialogs in the exported document keeps
     * each trigger (`data-dla-dialog-trigger`) beside an in-place hidden panel
     * (`data-dla-dialog-panel`). Adopt that panel as the native dialog bound to
     * its trigger, so the wiring runtime is retired like any other projected
     * dialog instead of leaving an unbound trigger target behind.
     *
     * @return array{triggers:array<int, DOMElement>, count:int}
     */
    private function adoptWiredPanels(DOMDocument $document, string $sourcePath): array
    {
        $xpath = new DOMXPath($document);
        $adopted = array();
        $count = 0;
        $scopes = $this->documentScopes($document);
        if (count($scopes) > self::MAX_STATES_PER_PAGE) {
            return array('triggers' => array(), 'count' => 0);
        }
        foreach ($scopes as $scopeIndex => $scope) {
            $groups = array();
            foreach ($xpath->query('.//*[@data-dla-dialog-trigger]', $scope) ?: array() as $trigger) {
                if (! $trigger instanceof DOMElement || $this->insideProjectedDialog($trigger)) continue;
                if ($this->isNavigationDropdownTrigger($trigger)) continue;
                $key = trim($trigger->getAttribute('data-dla-dialog-trigger'));
                if (1 === preg_match('/^[A-Za-z0-9_-]{1,64}$/', $key) && $key === trim($trigger->getAttribute('aria-controls'))) {
                    $groups[$key][] = $trigger;
                }
            }
            if (count($groups) > self::MAX_STATES_PER_PAGE) continue;
            foreach ($groups as $key => $triggers) {
                if (count($triggers) > self::MAX_STATES_PER_PAGE) continue;
                $panels = $xpath->query('.//*[@data-dla-dialog-panel=' . $this->xpathLiteral((string) $key) . ']', $scope);
                $panel = $panels && 1 === $panels->length ? $panels->item(0) : null;
                if (! $panel instanceof DOMElement || array() !== array_filter($triggers, static fn(DOMElement $trigger): bool => SourceDom::elementContains($panel, $trigger))) continue;
                // Sanitize the panel envelope, retaining its content wrapper:
                // that wrapper can own a menu's hierarchy and presentation.
                $html = (string) $document->saveHTML($panel);
                if (strlen($html) > self::MAX_DIALOG_BYTES) continue;
                $fragment = $this->safeDialogFragment($html);
                if (null === $fragment) continue;
                $identity = substr(hash('sha256', $sourcePath . "\n" . $key . "\n" . $scopeIndex . "\n" . $html), 0, 16);
                $triggerIds = array();
                $menu = false;
                foreach ($triggers as $triggerIndex => $trigger) {
                    $triggerId = trim($trigger->getAttribute('id'));
                    $matches = '' !== $triggerId ? $xpath->query('//*[@id=' . $this->xpathLiteral($triggerId) . ']') : null;
                    if ('' === $triggerId || ! $matches || 1 !== $matches->length) {
                        $triggerId = 'blocks-engine-dialog-trigger-' . $identity . '-' . ($triggerIndex + 1);
                        $trigger->setAttribute('id', $triggerId);
                    }
                    $triggerIds[] = $triggerId;
                    $menu = $menu || 'menu' === strtolower(trim($trigger->getAttribute('aria-haspopup')));
                }
                $dialog = $this->appendProjectedDialog($document, $fragment, $triggerIds, $identity);
                if ($menu) $dialog->setAttribute('data-blocks-engine-captured-menu', 'true');
                $panel->parentNode?->removeChild($panel);
                foreach (iterator_to_array($scope->getElementsByTagName('button')) as $button) {
                    if ($button instanceof DOMElement && $button->getAttribute('data-dla-dialog-close') === (string) $key && $button->hasAttribute('hidden') && ! SourceDom::elementContains($dialog, $button)) {
                        $button->parentNode?->removeChild($button);
                    }
                }
                array_push($adopted, ...$triggers);
                ++$count;
            }
        }

        return array('triggers' => $adopted, 'count' => $count);
    }

    /** @param array<int, DOMElement> $adopted */
    private function isAdopted(array $adopted, DOMElement $trigger): bool
    {
        foreach ($adopted as $candidate) {
            if ($candidate->isSameNode($trigger)) return true;
        }

        return false;
    }

    /**
     * @param array<int, DOMElement> $adopted
     * @param array<string, mixed> $trigger
     */
    private function matchesAdoptedTrigger(array $adopted, array $trigger): bool
    {
        $label = $this->normalizedLabel((string) ($trigger['label'] ?? ''));
        if ('' === $label) return false;
        foreach ($adopted as $candidate) {
            $name = $this->normalizedLabel($candidate->getAttribute('data-dla-disclosure-label'));
            if ('' !== $name && (str_starts_with($name, $label) || str_starts_with($label, $name))) return true;
        }

        return false;
    }

    /**
     * Put a metadata-only menu beside its source trigger so the existing native
     * submenu recognizer can consume it with the header ancestry intact.
     * Validate every candidate before changing any source node.
     *
     * @param array<int, DOMElement> $triggers
     * @param array<string, mixed> $fragment
     */
    private function projectCapturedNavigationDropdown(DOMDocument $document, array $triggers, array $fragment, string $identity): bool
    {
        $items = array();
        foreach ($triggers as $index => $trigger) {
            if ('button' !== strtolower($trigger->tagName) || !in_array(strtolower(trim($trigger->getAttribute('aria-haspopup'))), array('', 'menu'), true)) return false;
            $ancestor = $trigger->parentNode;
            while ($ancestor instanceof DOMElement && 'nav' !== strtolower($ancestor->tagName) && 'navigation' !== strtolower($ancestor->getAttribute('role'))) $ancestor = $ancestor->parentNode;
            if (!$ancestor instanceof DOMElement) return false;
            $key = 'blocks-engine-menu-' . $identity . '-' . $index;
            $item = $document->createElement('div');
            $button = $trigger->cloneNode(true);
            if (!$button instanceof DOMElement) return false;
            $button->setAttribute('aria-haspopup', 'menu');
            $button->setAttribute('aria-controls', $key);
            $button->setAttribute('aria-expanded', 'false');
            $button->setAttribute('data-dla-dialog-trigger', $key);
            $panel = $document->createElement('div');
            $panel->setAttribute('id', $key);
            $panel->setAttribute('data-dla-dialog-panel', $key);
            $panel->setAttribute('hidden', '');
            if (is_string($fragment['class'] ?? null) && '' !== $fragment['class']) $panel->setAttribute('class', $fragment['class']);
            foreach ($fragment['nodes'] as $node) $panel->appendChild($document->importNode($node, true));
            $item->appendChild($button);
            $item->appendChild($panel);
            if (null === NavigationPattern::buttonDropdownItemParts($item)) return false;
            $items[] = array('trigger' => $trigger, 'item' => $item, 'panel' => $panel, 'key' => $key);
        }
        if (array() === $items) return false;
        foreach ($items as $candidate) {
            $trigger = $candidate['trigger'];
            $item = $candidate['item'];
            $item->removeChild($item->firstChild);
            $trigger->parentNode?->replaceChild($item, $trigger);
            $trigger->setAttribute('aria-haspopup', 'menu');
            $trigger->setAttribute('aria-controls', $candidate['key']);
            $trigger->setAttribute('aria-expanded', 'false');
            $trigger->setAttribute('data-dla-dialog-trigger', $candidate['key']);
            $item->insertBefore($trigger, $candidate['panel']);
        }
        return true;
    }

    private function isNavigationDropdownTrigger(DOMElement $trigger): bool
    {
        $item = $trigger->parentNode;

        return $item instanceof DOMElement && null !== NavigationPattern::buttonDropdownItemParts($item);
    }

    /** @param array<int, DOMElement> $triggers */
    private function consumeMatchedCloseHelper(DOMDocument $document, array $triggers, DOMElement $dialog): void
    {
        foreach ($triggers as $trigger) {
            $key = trim($trigger->getAttribute('data-dla-dialog-trigger'));
            if ('' === $key || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $key)) continue;
            foreach (iterator_to_array($document->getElementsByTagName('button')) as $button) {
                if (!$button instanceof DOMElement || $button->getAttribute('data-dla-dialog-close') !== $key || !$button->hasAttribute('hidden')) continue;
                if (SourceDom::elementContains($dialog, $button)) continue;
                if ('close' !== strtolower(trim($button->textContent ?? ''))) continue;
                $button->parentNode?->removeChild($button);
            }
        }
    }

    private function hasDialogCloseHelper(DOMDocument $document): bool
    {
        foreach ($document->getElementsByTagName('button') as $button) {
            if ($button instanceof DOMElement && '' !== trim($button->getAttribute('data-dla-dialog-close'))) return true;
        }
        return false;
    }

    private function everyDialogTriggerIsBound(DOMDocument $document): bool
    {
        $bound = array();
        foreach ($document->getElementsByTagName('dialog') as $dialog) {
            if (!$dialog instanceof DOMElement) continue;
            foreach (preg_split('/\s+/', trim($dialog->getAttribute('data-blocks-engine-triggers'))) ?: array() as $id) {
                if ('' !== $id) $bound[$id] = true;
            }
        }
        foreach ($document->getElementsByTagName('*') as $node) {
            if (!$node instanceof DOMElement || '' === trim($node->getAttribute('data-dla-dialog-trigger'))) continue;
            // A navigation dropdown trigger becomes a submenu, so it needs no dialog binding.
            if ($this->isNavigationDropdownTrigger($node)) continue;
            $id = trim($node->getAttribute('id'));
            if ('' === $id || !isset($bound[$id])) return false;
        }
        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @param array<string, array<int, string>> $retired
     * @return array<int, array<string, string>>
     */
    private function omitRetiredDisclosureScripts(array &$files, array $retired): array
    {
        $proofs = array();
        $kept = array();
        foreach ($files as $file) {
            $sourcePath = ArtifactNormalizer::inlineExpansionSourcePath($file);
            $body = trim((string) ($file['content'] ?? ''));
            $matched = '' !== $sourcePath && 'inline-script' === ($file['source'] ?? null) && in_array($body, $retired[$sourcePath] ?? array(), true);
            if (!$matched) {
                $kept[] = $file;
                continue;
            }
            $proofs[] = array(
                'schema' => 'blocks-engine/native-runtime-replacement/v1',
                'source_path' => $sourcePath,
                'asset_source_path' => (string) ($file['path'] ?? ''),
                'body_hash' => hash('sha256', $body),
                'attribute' => 'data-dla-disclosure-runtime',
                'reason' => 'native_dialog_close_replaces_capture_close_helper',
            );
        }
        $files = $kept;
        return $proofs;
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
        // Captured interaction markup is runtime evidence. Keep that ownership
        // fact after removing executable and endpoint-bearing source details.
        if ('form' === strtolower($container->tagName)) {
            $container->setAttribute('data-blocks-engine-runtime-form-owner', 'captured');
        }
        foreach ($container->getElementsByTagName('form') as $form) {
            $form->setAttribute('data-blocks-engine-runtime-form-owner', 'captured');
        }
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
