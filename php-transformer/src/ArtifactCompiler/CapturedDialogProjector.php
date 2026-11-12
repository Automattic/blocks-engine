<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\CapturedDialogBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/** Projects bounded captured-dialog evidence into the matching source document. */
final class CapturedDialogProjector
{
    use \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedDocumentScopes;

    private const MAX_STATES_PER_PAGE = 8;
    // Data Liberation emits at most 8 trigger dialogs plus 8 initially-open dialogs per page.
    private const MAX_DIALOG_STATES_PER_PAGE = 16;
    private const MAX_GALLERY_STATES_PER_PAGE = 8;
    private const MAX_DIALOG_BYTES = 65536;
    /** The viewport overlay the capture disclosure runtime opens a modal panel as. */
    private const MODAL_OVERLAY_STYLE = 'display:block;position:fixed;inset:0;z-index:2147483646;overflow:auto;background:#fff';

    /** @var list<DOMElement> Panels proven by the existing complete gallery contract. */
    private array $nativeGalleryPanels = array();

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array{files:array<int, array<string, mixed>>, diagnostics:array<int, array<string, mixed>>, projected_count:int}
     */
    public function project(array $files): array
    {
        $result = $this->projectReportFiles($files);
        $result['diagnostics'] = array_merge(CapturedInteractionReport::diagnostics($files), $result['diagnostics']);
        return $result;
    }

    private function projectReportFiles(array $files): array
    {
        $components = $this->projectComponentCarousels($files);
        $popovers = $this->adoptSourcePopovers($components['files']);
        $result = $this->projectCapturedStates($popovers['files']);
        $this->restoreNavigationPanels($result['files']);
        $this->restoreRemainingPanels($result['files']);
        $result['diagnostics'] = array_merge($result['diagnostics'], $components['diagnostics'] ?? array());
        $result['projected_count'] += $popovers['projected_count'] + $components['projected_count'];
        $result['native_runtime_replacements'] = array_merge($result['native_runtime_replacements'] ?? array(), $components['native_runtime_replacements']);

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array{files:array<int, array<string, mixed>>, diagnostics:array<int, array<string, mixed>>, projected_count:int}
     */
    private function projectComponentCarousels(array $files): array
    {
        $report = CapturedInteractionReport::read($files);
        $receipt = CaptureReceiptReport::read($files);
        $count = 0;
        $diagnostics = array();
        $assets = array();
        foreach (($this->assetEvidence($files)['assets'] ?? array()) as $asset) {
            if ('included' === ($asset['portable'] ?? null) && is_string($asset['id'] ?? null) && is_string($asset['path'] ?? null)
                && !preg_match('~(^/|\.\.|^[a-z]+:)~i', $asset['path'])) {
                foreach ($files as $candidate) if (($candidate['path'] ?? null) === $asset['path']) $assets[$asset['id']] = $asset['path'];
            }
        }
        $retired = array();
        if (!CapturedInteractionReport::valid($report)
            || null === $receipt) {
            return array('files' => $files, 'projected_count' => 0, 'native_runtime_replacements' => array());
        }
        $routes = CaptureReceiptRoutes::forFiles($files, $receipt);
        foreach (($report['pages'] ?? array()) as $page) {
            $path = $routes->path((string) ($page['sourceUrl'] ?? ''));
            $index = $routes->pathIndex($path);
            if (null === $index) continue;
            $file = &$files[$index];
            $document = HtmlSerialization::parseDocument($file['content']);
            $changed = false;
            $selectors = array();
            $bindings = new ScriptOccurrenceBindings($document);
            foreach (array_slice($page['components'] ?? array(), 0, self::MAX_GALLERY_STATES_PER_PAGE) as $component) {
                if ('carousel' === ($component['kind'] ?? null) && 'slideshow' === ($component['function'] ?? null)) {
                    if (!in_array($component['status'] ?? null, array('captured', 'partial'), true) || !is_array($component['slideshow'] ?? null)) continue;
                    foreach ($this->documentScopes($document) as $scope) {
                        $selector = (string) ($component['selector'] ?? '');
                        $roots = str_starts_with($selector, '#') ? $this->identityMatches($scope, array('selector' => $selector)) : $this->selectorMatches($scope, array('selector' => $selector));
                        if (1 !== count($roots)) continue;
                        $root = $roots[0];
                        $show = $component['slideshow'] ?? array();
                        $native = $document->createElement('div');
                        $slides = array();
                        $missing = array();
                        $initial = 0;
                        foreach ($show['frames'] ?? array() as $index => $frame) {
                            if (!empty($frame['clone']) || !empty($frame['loopClone']) || preg_match('/(?:data-clone(?:d)?=|slick-cloned|swiper-slide-duplicate|aria-roledescription=["\']clone)/', (string) ($frame['html'] ?? ''))) continue;
                            $item = $document->createElement('div');
                            $paths = array();
                            foreach ($frame['media'] ?? array() as $media) {
                                $assetPath = $assets[$media['asset'] ?? ''] ?? null;
                                if (null === $assetPath) continue;
                                $paths[] = str_repeat('../', max(0, substr_count($file['path'], '/'))) . $assetPath;
                            }
                            if (count($paths) < count($frame['media'] ?? array()) || !$paths) $missing[] = $show['slots'][$index] ?? $frame['id'] ?? (string) $index;
                            if (!$paths) continue;
                            // Preserve editable captions and other frame content, but not the
                            // capture's translated/hidden geometry or source media URLs.
                            $snapshot = HtmlSerialization::parseDocument('<html><body>' . SourceDom::safeFallbackHtmlString(substr((string) ($frame['html'] ?? ''), 0, 524288)) . '</body></html>');
                            $used = 0;
                            foreach (iterator_to_array($snapshot->getElementsByTagName('*')) as $node) {
                                foreach (iterator_to_array($node->attributes) as $attribute) {
                                    if (!in_array($attribute->name, array('alt', 'title', 'href'), true)) $node->removeAttribute($attribute->name);
                                }
                                if (in_array(strtolower($node->tagName), array('script', 'style', 'iframe', 'source', 'video', 'audio'), true)) {
                                    $node->parentNode?->removeChild($node);
                                } elseif ('img' === strtolower($node->tagName)) {
                                    if (!isset($paths[$used])) $node->parentNode?->removeChild($node);
                                    else $node->setAttribute('src', $paths[$used++]);
                                }
                            }
                            $body = $snapshot->getElementsByTagName('body')->item(0);
                            foreach ($body?->childNodes ?? array() as $child) $item->appendChild($document->importNode($child, true));
                            foreach (array_slice($paths, $used) as $extraPath) {
                                $image = $document->createElement('img');
                                $image->setAttribute('src', $extraPath);
                                $image->setAttribute('alt', '');
                                $item->appendChild($image);
                            }
                            if ($index === ($show['initial'] ?? 0)) $initial = count($slides);
                            $slides[] = $item;
                        }
                        if ($missing) $diagnostics[] = $this->diagnostic('captured_slideshow_partial', 'warning', 'Slideshow unresolved slots: ' . implode(', ', $missing), array('slots' => $missing));
                        if (!$slides) continue;
                        $modes = array_column($show['navigation'] ?? array(), 'mode');
                        $attributes = array('presentation' => 'slideshow', 'itemsPerView' => 1, 'slideCount' => count($slides), 'initialSlide' => $initial,
                            'showArrows' => !$modes || in_array('arrows', $modes, true), 'showDots' => in_array('dots', $modes, true),
                            'keyboard' => in_array('keyboard', $modes, true), 'swipe' => in_array('swipe', $modes, true), 'capturedViews' => $show['views'] ?? array());
                        foreach ($show['navigation'] ?? array() as $mode) if ('autoplay' === ($mode['mode'] ?? '') && ($mode['intervalMs'] ?? 0) > 0) $attributes['autoplayInterval'] = (int) $mode['intervalMs'];
                        $native->setAttribute('data-blocks-engine-slideshow', json_encode($attributes, JSON_THROW_ON_ERROR));
                        foreach ($slides as $slide) $native->appendChild($slide);
                        $root->parentNode?->replaceChild($native, $root);
                        $changed = true;
                        $selectors[] = (string) ($component['selector'] ?? '');
                        ++$count;
                    }
                    continue;
                }
                if ('carousel' !== ($component['kind'] ?? null) || !in_array($component['status'] ?? null, array('captured', 'partial'), true)
                    || !is_array($component['states'] ?? null) || array() !== CapturedComponentStates::evidence(array($component), array('carousel'))) continue;
                foreach ($this->documentScopes($document) as $scope) {
                    $selector = (string) ($component['selector'] ?? '');
                    $roots = str_starts_with($selector, '#')
                        ? $this->identityMatches($scope, array('selector' => $selector))
                        : $this->selectorMatches($scope, array('selector' => $selector));
                    if (1 !== count($roots)) continue;
                    $root = $roots[0];
                    $slides = array();
                    $initial = 0;
                    foreach (array_slice($component['states'], 0, 24) as $state) {
                        if (!is_string($state['html'] ?? null) || strlen($state['html']) > 524288) continue;
                        $snapshot = HtmlSerialization::parseDocument(SourceDom::safeFallbackHtmlString($state['html']));
                        $active = array();
                        foreach ($snapshot->getElementsByTagName('*') as $slide) {
                            if ('slide' === $slide->getAttribute('aria-roledescription') && !$slide->hasAttribute('hidden')
                                && !preg_match('/display\s*:\s*none/i', $slide->getAttribute('style'))) $active[] = $slide;
                        }
                        if (1 !== count($active) || !is_string($state['id'] ?? null) || isset($slides[$state['id']])) continue;
                        if ($state['id'] === ($component['initial'] ?? null)) $initial = count($slides);
                        $slides[$state['id']] = $active[0];
                    }
                    if (count($slides) < 2) continue;
                    $oldSlides = array();
                    foreach ($root->getElementsByTagName('*') as $slide) if ('slide' === $slide->getAttribute('aria-roledescription')) $oldSlides[] = $slide;
                    if (array() === $oldSlides) continue;
                    $parent = $oldSlides[0]->parentNode;
                    if (!$parent instanceof DOMElement) continue;
                    foreach ($oldSlides as $slide) if (!$slide->parentNode?->isSameNode($parent)) continue 2;
                    $list = $document->createElement('div');
                    $list->setAttribute('role', 'list');
                    $list->setAttribute(CaptureEvidence::GALLERY_INITIAL, (string) $initial);
                    $parent->insertBefore($list, $oldSlides[0]);
                    foreach ($slides as $slide) {
                        $item = $document->importNode($slide, true);
                        $item->removeAttribute('hidden');
                        $item->setAttribute('role', 'listitem');
                        $list->appendChild($item);
                    }
                    foreach ($oldSlides as $slide) $parent->removeChild($slide);
                    $changed = true;
                    $selectors[] = $selector;
                    ++$count;
                }
            }
            if ($changed) {
                $retired[$path] = CapturedComponentStates::retireRuntime($document, CaptureEvidence::COMPONENT_CAROUSEL_RUNTIME, $selectors);
                $file = ArtifactNormalizer::withTextPayload($file, (string) HtmlSerialization::documentHtml($document));
                $bindings->rebind($files, $path);
            }
            unset($file);
        }
        $proofs = CapturedComponentStates::omitScripts($files, $retired);
        return array('files' => $files, 'diagnostics' => $diagnostics, 'projected_count' => $count, 'native_runtime_replacements' => $proofs);
    }

    private function projectCapturedStates(array $files): array
    {
        $diagnostics = array();
        $report = CapturedInteractionReport::read($files);
        if (null === $report) {
            return $this->adoptNativeDialogsWithoutReport($files, array());
        }
        if (!CapturedInteractionReport::valid($report) || ! is_array($report['pages'] ?? null)) {
            return array('files' => $files, 'diagnostics' => array($this->diagnostic('captured_interactions_invalid', 'warning', 'The captured interaction report has an unsupported schema or pages shape.')), 'projected_count' => 0);
        }
        if (! $this->hasDialogStates($report['pages'])) {
            return $this->adoptNativeDialogsWithoutReport($files, $diagnostics);
        }

        $receipt = CaptureReceiptReport::read($files);
        if (null === $receipt || ! is_array($receipt['routes'] ?? null)) {
            return $this->adoptNativeDialogsWithoutReport($files, array_merge($diagnostics, array($this->diagnostic('captured_interactions_route_map_missing', 'warning', 'Captured dialogs were not projected because the capture receipt route map is unavailable.'))));
        }

        $routes = CaptureReceiptRoutes::forFiles($files, $receipt);

        $projected = 0;
        $retired = array();
        foreach ($report['pages'] as $page) {
            if (! is_array($page) || ! is_string($page['sourceUrl'] ?? null) || ! is_array($page['components'] ?? null)) {
                $diagnostics[] = $this->diagnostic('captured_interaction_page_invalid', 'warning', 'A captured interaction page was ignored because its source URL or components are invalid.');
                continue;
            }
            $dialogStates = $this->dialogStates(CapturedComponentStates::evidence($page['components'], array('dialog', 'listbox', 'menu', 'disclosure', 'carousel')));
            $galleryCount = count(array_filter($dialogStates, static fn (array $state): bool => 'gallery' === ($state['kind'] ?? '')));
            $dialogCount = count($dialogStates) - $galleryCount;
            $exceededLimit = $dialogCount > self::MAX_DIALOG_STATES_PER_PAGE ? self::MAX_DIALOG_STATES_PER_PAGE : ($galleryCount > self::MAX_GALLERY_STATES_PER_PAGE ? self::MAX_GALLERY_STATES_PER_PAGE : null);
            if (null !== $exceededLimit) {
                $diagnostics[] = $this->diagnostic('captured_interaction_state_limit_exceeded', 'warning', 'A captured interaction page exceeded the state limit.', array('source_url' => $page['sourceUrl'], 'max_states' => $exceededLimit));
                continue;
            }
            if (array() === $dialogStates) {
                continue;
            }
            $path = $routes->path($page['sourceUrl']);
            $index = $routes->pathIndex($path);
            if (null === $index) {
                $diagnostics[] = $this->diagnostic('captured_interaction_source_unmatched', 'warning', 'A captured interaction page did not match an artifact HTML document.', array('source_url' => $page['sourceUrl']));
                continue;
            }

            $projection = $this->projectPage((string) $files[$index]['content'], $dialogStates, $path);
            $diagnostics = array_merge($diagnostics, $projection['diagnostics']);
            if (0 < $projection['projected_count'] || array() !== $projection['retired_scripts']) {
                $files[$index] = ArtifactNormalizer::withTextPayload($files[$index], $projection['html']);
                $projection['script_bindings']?->rebind($files, $path);
                $projected += $projection['projected_count'];
                foreach ($projection['retired_scripts'] as $body) {
                    $retired[$path][] = $body;
                }
            }
        }
        $proofs = $this->omitRetiredDisclosureScripts($files, $retired);

        return array('files' => $files, 'diagnostics' => $diagnostics, 'projected_count' => $projected, 'native_runtime_replacements' => $proofs);
    }

    /**
     * An element carrying the `popover` attribute is closed on load by the user
     * agent, whatever it holds, and opens only from a `popovertarget` control.
     * Adopt each such element in place as the native captured dialog bound to
     * its openers, so it stays closed at every viewport. Its own
     * `popovertargetaction="hide"` (or toggle) controls close it, recognized by
     * that attribute rather than by label language, and Escape closes the modal
     * dialog natively. A capture panel nested in the popover and hidden only
     * because no trigger of its own exists is part of the popover's open
     * content, so it is shown whenever the popover is.
     *
     * @param array<int, array<string, mixed>> $files
     * @return array{files:array<int, array<string, mixed>>, projected_count:int}
     */
    private function adoptSourcePopovers(array $files): array
    {
        $projected = 0;
        foreach ($files as $index => $file) {
            $content = $file['content'] ?? null;
            $path = (string) ($file['path'] ?? '');
            if (! preg_match('/\.html?$/i', $path) || ! is_string($content) || 1 !== preg_match('/\spopover(?:\s|=|>)/i', $content) || ! str_contains(strtolower($content), 'popovertarget')) continue;
            $document = HtmlSerialization::parseFragment($content);
            if ($this->hasRestingInlinePanel($document)) continue;
            $count = 0;
            $xpath = new DOMXPath($document);
            foreach (iterator_to_array($xpath->query('//*[@popover][@id]') ?: array()) as $popover) {
                if ($popover instanceof DOMElement && $this->adoptSourcePopover($document, $popover)) ++$count;
            }
            if (0 === $count) continue;
            $html = HtmlSerialization::documentHtml($document);
            if (! is_string($html)) continue;
            $files[$index] = ArtifactNormalizer::withTextPayload($files[$index], $html);
            $projected += $count;
        }

        return array('files' => $files, 'projected_count' => $projected);
    }

    private function adoptSourcePopover(DOMDocument $document, DOMElement $popover): bool
    {
        $id = SourceDom::safeAnchor($popover->getAttribute('id'));
        if ('' === $id || $this->insideProjectedDialog($popover)) return false;
        $xpath = new DOMXPath($document);
        $ids = $xpath->query('//*[@id=' . $this->xpathLiteral($id) . ']');
        if (! $ids || 1 !== $ids->length) return false;
        $openers = array();
        $closers = array();
        foreach ($xpath->query('//*[@popovertarget=' . $this->xpathLiteral($id) . ']') ?: array() as $control) {
            if (! $control instanceof DOMElement) continue;
            $action = strtolower(trim($control->getAttribute('popovertargetaction')));
            if (SourceDom::elementContains($popover, $control)) {
                if ('show' !== $action) $closers[] = $control;
            } elseif ('hide' !== $action) {
                $openers[] = $control;
            }
        }
        if (array() === $openers || count($openers) > self::MAX_STATES_PER_PAGE) return false;
        foreach (iterator_to_array($popover->getElementsByTagName('*')) as $panel) {
            if (! $panel instanceof DOMElement || ! $panel->hasAttribute('hidden')) continue;
            $key = trim($panel->getAttribute(CaptureEvidence::DIALOG_PANEL));
            if ('' === $key) continue;
            $triggers = $xpath->query('//*[@' . CaptureEvidence::DIALOG_TRIGGER . '=' . $this->xpathLiteral($key) . ']');
            if (! $triggers) continue;
            $triggers = array_values(array_filter(iterator_to_array($triggers), static fn ($node): bool => $node instanceof DOMElement));
            if (array() !== array_filter($triggers, static fn (DOMElement $trigger): bool => ! in_array($trigger, $openers, true))) continue;
            // Opening the popover is opening this panel: it is the popover's
            // open content, and the popover dialog now owns its binding.
            $panel->removeAttribute('hidden');
            foreach (array(CaptureEvidence::DIALOG_PANEL, CaptureEvidence::EXISTING_PANEL) as $attribute) $panel->removeAttribute($attribute);
            $classes = array_diff(SourceDom::tokens($panel->getAttribute('class')), array(CaptureEvidence::DROPDOWN_CLASS, CaptureEvidence::DIALOG_CLASS));
            if (array() === array_filter($classes)) $panel->removeAttribute('class'); else $panel->setAttribute('class', implode(' ', $classes));
            foreach ($triggers as $trigger) {
                foreach (array(CaptureEvidence::DIALOG_TRIGGER, CaptureEvidence::DIALOG_PANEL_STATE, CaptureEvidence::DIALOG_ANCESTOR_STATE, CaptureEvidence::DISCLOSURE_LABEL) as $attribute) $trigger->removeAttribute($attribute);
            }
        }
        $identity = self::dialogIdentity("popover\n" . $id);
        $closerIds = $this->boundControlIds($xpath, $closers, 'blocks-engine-dialog-close-' . $identity);
        $html = (string) $document->saveHTML($popover);
        if (strlen($html) > self::MAX_DIALOG_BYTES) return false;
        $fragment = $this->safeDialogFragment($html);
        if (null === $fragment) return false;
        $fragment['has_close_control'] = $fragment['has_close_control'] || array() !== $closerIds;
        $triggerIds = $this->boundControlIds($xpath, $openers, 'blocks-engine-dialog-trigger-' . $identity);
        $dialog = $this->appendProjectedDialog($document, $fragment, $openers, $triggerIds, $identity, false, $popover);
        // The copied close controls keep the ids they are bound by.
        if (array() !== $closerIds) $dialog->setAttribute('data-blocks-engine-closers', implode(' ', $closerIds));
        // Replaced in place, the dialog keeps the popover's source id for author rules.
        $dialog->setAttribute('id', $id);

        return true;
    }

    /**
     * Give each control a document-unique id to bind by, keeping its own.
     *
     * @param array<int, DOMElement> $controls
     * @return array<int, string>
     */
    private function boundControlIds(DOMXPath $xpath, array $controls, string $prefix): array
    {
        $ids = array();
        foreach (array_values($controls) as $index => $control) {
            $id = trim($control->getAttribute('id'));
            $matches = '' !== $id ? $xpath->query('//*[@id=' . $this->xpathLiteral($id) . ']') : null;
            if ('' === $id || ! $matches || 1 !== $matches->length) {
                $id = $prefix . '-' . ($index + 1);
                $control->setAttribute('id', $id);
            }
            $ids[] = $id;
        }

        return $ids;
    }

    /** @param array<int, mixed> $pages */
    private function hasDialogStates(array $pages): bool
    {
        foreach ($pages as $page) {
            if (! is_array($page) || ! is_array($page['components'] ?? null)) {
                continue;
            }
            if (array() !== $this->dialogStates(CapturedComponentStates::evidence($page['components'], array('dialog', 'listbox', 'menu', 'disclosure', 'carousel')))) {
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

        return in_array($kind, array('', 'dialog', 'gallery', 'listbox'), true);
    }

    /**
     * @param array<int, mixed> $states
     * @return array{html:string, diagnostics:array<int, array<string, mixed>>, projected_count:int, retired_scripts:array<int, array{body:string, attribute:string, reason:string}>, script_bindings:?ScriptOccurrenceBindings}
     */
    private function projectPage(string $html, array $states, string $sourcePath): array
    {
        // Implied document containers keep sibling fragment panels outside the
        // first trigger element; NOIMPLIED can nest them inside that button.
        $document = HtmlSerialization::parseDocument($html);

        // Native replacement retires the capture runtime that drives this
        // page's wiring. A runtime body Blocks Engine does not recognize keeps
        // running, so it keeps the wiring it drives too.
        if ($this->hasUnrecognizedRuntime($document)) {
            return array('html' => $html, 'diagnostics' => array(), 'projected_count' => 0, 'retired_scripts' => array(), 'script_bindings' => null);
        }
        if ($this->hasRestingInlinePanel($document)) {
            return array('html' => $html, 'diagnostics' => array($this->restingInlineDiagnostic($sourcePath)), 'projected_count' => 0, 'retired_scripts' => array(), 'script_bindings' => null);
        }
        $scriptBindings = new ScriptOccurrenceBindings($document);
        $diagnostics = array();
        $this->nativeGalleryPanels = array();
        $galleryCount = $this->prepareGalleries($document, $states, $sourcePath, $diagnostics);
        $adoption = $this->adoptWiredPanels($document, $sourcePath);
        $adopted = $adoption['triggers'];
        $projected = $adoption['count'];
        $handledNavigationDropdown = false;
        foreach ($states as $state) {
            if ('gallery' === ($state['kind'] ?? '')) continue; // The verified in-place panels were adopted above.
            if (! is_array($state) || 'captured' !== ($state['status'] ?? null) || ! is_array($state['trigger'] ?? null) || ! is_array($state['dialog'] ?? null)) {
                continue;
            }
            $dialog = $state['dialog'];
            $dialogHtml = ArrayField::string($dialog, 'html');
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
            $triggers = array_values(array_filter($triggers, fn (DOMElement $trigger): bool => ! $this->isAdopted($adopted, $trigger)));
            if (array() === $triggers) {
                continue;
            }
            // A navigation button with its dropdown panel of links stays in place:
            // it becomes a navigation submenu, not a dialog opened from a button.
            if (array() === array_filter($triggers, fn (DOMElement $trigger): bool => ! NavigationPattern::ownsCapturedSubmenuTrigger($trigger))) {
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

            $identity = self::dialogIdentity(($state['trigger']['selector'] ?? '') . "\n" . self::identityHtml($dialogHtml));
            if ('listbox' === ($state['kind'] ?? '') || 'listbox' === strtolower(trim((string) ($dialog['role'] ?? '')))) {
                $key = 'blocks-engine-listbox-' . $identity;
                foreach ($triggers as $trigger) {
                    $trigger->setAttribute(CaptureEvidence::LISTBOX_TRIGGER, $key);
                    $trigger->setAttribute('type', 'button');
                    $trigger->removeAttribute('aria-expanded');
                    $trigger->removeAttribute('aria-controls');
                }
                $listbox = $document->createElement('div');
                $listbox->setAttribute('hidden', '');
                $listbox->setAttribute('role', 'listbox');
                $listbox->setAttribute(CaptureEvidence::LISTBOX_PANEL, $key);
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
            $dialogElement = $this->appendProjectedDialog($document, $fragment, $triggers, $triggerIds, $identity, 'dropdown' === strtolower(trim((string) ($dialog['presentation'] ?? ''))));
            $this->consumeMatchedCloseHelper($document, $triggers, $dialogElement);
            ++$projected;
        }
        $retired = array();
        if ($galleryCount > 0) {
            $retired = $this->retireRecognizedRuntime($document, CaptureEvidence::GALLERY_RUNTIME, 'native_carousel_and_dialog_replace_capture_gallery');
        }
        if ($this->hasNavigationOwnedDialogTrigger($document)) {
            $this->consumeNavigationOwnedCloseHelpers($document);
            $handledNavigationDropdown = true;
        }
        if (($projected > 0 || $handledNavigationDropdown) && !$this->hasDialogCloseHelper($document) && $this->everyDialogTriggerIsBound($document)) {
            $reason = 0 < $projected ? 'native_dialog_close_replaces_capture_close_helper' : 'native_navigation_submenu_replaces_capture_disclosure';
            $retired = array_merge($retired, $this->retireRecognizedRuntime($document, CaptureEvidence::DISCLOSURE_RUNTIME, $reason));
        }

        $output = HtmlSerialization::documentHtml($document);
        return array('html' => is_string($output) ? $output : $html, 'diagnostics' => $diagnostics, 'projected_count' => $projected, 'retired_scripts' => $retired, 'script_bindings' => is_string($output) ? $scriptBindings : null);
    }

    /** Consume viewport-scoped, observed image selection before the ordinary dialog adoption. */
    private function prepareGalleries(DOMDocument $document, array $states, string $path, array &$diagnostics): int
    {
        $xpath = new DOMXPath($document);
        $count = 0;
        foreach ($states as $state) {
            if ('gallery' !== ($state['kind'] ?? '') || !is_array($state['gallery'] ?? null)) continue;
            $gallery = $state['gallery'];
            $inline = $gallery['inline'] ?? array();
            $lightbox = $gallery['lightbox'] ?? array();
            $selection = $gallery['selection'] ?? array();
            $frames = $inline['frames'] ?? array();
            $fullFrames = $lightbox['frames'] ?? array();
            $validCycle = static fn(array $cycle): bool => 'complete' === ($cycle['coverage'] ?? '') && 'verified' === ($cycle['restoration'] ?? '') && is_array($cycle['frames'] ?? null) && count($cycle['frames']) >= 2 && count($cycle['frames']) <= 24;
            if (!$validCycle($inline)) continue;
            $roots = $xpath->query('//*[@' . CaptureEvidence::GALLERY_SOURCE . '=' . $this->xpathLiteral((string) ($inline['selector'] ?? '')) . ' and @' . CaptureEvidence::GALLERY_CAPTURE_WIDTH . '=' . $this->xpathLiteral((string) ($inline['viewport']['width'] ?? '')) . ']');
            if ($roots && 0 === $roots->length) continue; // A responsive collapse can retain only one captured viewport.
            if (!$roots || 1 !== $roots->length) {
                $diagnostics[] = $this->diagnostic('captured_gallery_source_unmatched', 'warning', 'The observed gallery did not match one viewport-scoped authoring root.', array('source_path' => $path));
                continue;
            }
            $root = $roots->item(0);
            $stage = $xpath->query('.//*[@' . CaptureEvidence::GALLERY_STAGE . ']', $root)?->item(0);
            if (!$stage instanceof DOMElement) continue;
            $stage->setAttribute('role', 'list');
            foreach ($stage->childNodes as $item) if ($item instanceof DOMElement) $item->setAttribute('role', 'listitem');
            ++$count;
            if ('captured' !== ($state['status'] ?? '') || empty($gallery['closed']) || !$validCycle($lightbox)) continue;
            $expected = range(0, count($fullFrames) - 1);
            $sorted = $selection;
            sort($sorted);
            if (count($frames) !== count($fullFrames) || $sorted !== $expected || count($selection) !== count($frames)) {
                $diagnostics[] = $this->diagnostic('captured_gallery_selection_invalid', 'warning', 'The captured image-to-lightbox selection is not a complete bijection.', array('source_path' => $path));
                continue;
            }
            foreach ($selection as $index => $selected) {
                if (($frames[$index]['fullImage'] ?? $frames[$index]['key'] ?? null) !== ($fullFrames[$selected]['key'] ?? null)) {
                    $diagnostics[] = $this->diagnostic('captured_gallery_selection_invalid', 'warning', 'The selected full image does not match the observed inline image identity.', array('source_path' => $path));
                    continue 2;
                }
            }
            $key = $stage->getAttribute(CaptureEvidence::DIALOG_TRIGGER);
            $scope = $document->documentElement;
            foreach ($this->documentScopes($document) as $candidateScope) if (SourceDom::elementContains($candidateScope, $root)) { $scope = $candidateScope; break; }
            $panels = $xpath->query('.//*[@' . CaptureEvidence::DIALOG_PANEL . '=' . $this->xpathLiteral($key) . ']', $scope);
            $panel = $panels && 1 === $panels->length ? $panels->item(0) : null;
            if (!$panel instanceof DOMElement) continue;
            $fullStage = $xpath->query('.//*[@' . CaptureEvidence::GALLERY_STAGE . ']', $panel)?->item(0);
            if (!$fullStage instanceof DOMElement) continue;
            $children = array_values(array_filter(iterator_to_array($fullStage->childNodes), static fn($child): bool => $child instanceof DOMElement));
            if (count($children) !== count($fullFrames)) continue;
            $native = $document->createElement('div');
            $native->setAttribute('aria-label', 'Image gallery carousel');
            $native->setAttribute('style', 'width:100%');
            $list = $document->createElement('div');
            $list->setAttribute('class', 'slideshow');
            $list->setAttribute('role', 'list');
            $list->setAttribute(CaptureEvidence::GALLERY_INITIAL, (string) ($lightbox['initial'] ?? 0));
            foreach ($children as $index => $child) {
                $image = $child->getElementsByTagName('img')->item(0);
                if (!$image instanceof DOMElement || '' === $image->getAttribute('src')) continue 2;
                $item = $document->createElement('div');
                $item->setAttribute('role', 'listitem');
                $item->setAttribute('aria-hidden', $index === ($lightbox['initial'] ?? 0) ? 'false' : 'true');
                $image = $image->cloneNode(true);
                $image->setAttribute('style', 'display:block;width:100%;height:auto;max-height:80vh;object-fit:contain');
                $item->appendChild($image);
                $list->appendChild($item);
            }
            $native->appendChild($list);
            foreach (array('Previous slide', 'Next slide') as $label) {
                $button = $document->createElement('button', $label);
                $button->setAttribute('aria-label', $label);
                $native->appendChild($button);
            }
            while ($panel->firstChild) $panel->removeChild($panel->firstChild);
            $panel->appendChild($native);
            $close = $document->createElement('button', 'Close');
            $close->setAttribute('aria-label', 'Close gallery');
            $close->setAttribute('data-blocks-engine-dialog-close', 'true');
            $panel->appendChild($close);
            $panel->setAttribute('data-blocks-engine-gallery-selection', json_encode($selection, JSON_THROW_ON_ERROR));
            $this->nativeGalleryPanels[] = $panel;
        }
        return $count;
    }

    /**
     * A projected dialog is named by its content and its handle within the
     * route document, never by the route: the same dialog on many routes then
     * serializes identically and shared templates and parts can carry it.
     * Handles (trigger selector, panel key, popover id) are unique within one
     * document, so one route never repeats an identity.
     */
    private static function dialogIdentity(string $content): string
    {
        return substr(hash('sha256', $content), 0, 16);
    }

    private static function identityHtml(string $html): string
    {
        $html = preg_replace('/(<a\b[^>]*?)\s+aria-current\s*=\s*(["\'])page\2/i', '$1', $html) ?? $html;
        return preg_replace_callback('/\bclass\s*=\s*(["\'])(.*?)\1/s', static function (array $match): string {
            $classes = SourceDom::tokens($match[2]);
            $classes = array_values(array_filter($classes, static fn(string $class): bool => !preg_match('/^blocks-engine-(?:current-navigation-(?:item|underline)|navigation-current-color-[a-f0-9]{64})$/', $class)));
            return 'class=' . $match[1] . implode(' ', $classes) . $match[1];
        }, $html) ?? $html;
    }

    /**
     * @param array{nodes:array<int, \DOMNode>, class:string, id:string, aria_label:string, aria_labelledby:string, aria_describedby:string, has_close_control:bool, self_positioned:bool} $fragment
     * @param array<int, DOMElement> $triggers
     * @param array<int, string> $triggerIds
     * @param array{closed?:array{class:?string, style:?string}, opened?:array{class:?string, style:?string}, hidden?:bool} $resting
     */
    private function appendProjectedDialog(DOMDocument $document, array $fragment, array $triggers, array $triggerIds, string $identity, bool $dropdown, ?DOMElement $sourcePlace = null, bool $scopedPanel = false, array $resting = array()): DOMElement
    {
        $dialogId = 'blocks-engine-dialog-' . $identity;
        // A scoped nonmodal panel retains its source mount slot. This
        // keeps type/child selectors and the source containing block intact.
        // A panel adopted with its resting presentation is wrapped whole, so
        // the wrapper is a box-less div rather than a dialog.
        $dialogElement = $document->createElement($scopedPanel || array() !== $resting ? 'div' : 'dialog');
        if ($scopedPanel && $sourcePlace instanceof DOMElement) {
            $ids = (new DOMXPath($document))->query('//*[@id=' . $this->xpathLiteral($sourcePlace->getAttribute('id')) . ']');
            if ($ids && 1 === $ids->length) $dialogId = $sourcePlace->getAttribute('id');
            $dialogElement->setAttribute('style', $sourcePlace->getAttribute('style'));
            foreach ($triggers as $trigger) $trigger->setAttribute('aria-controls', $dialogId);
        }
        $dialogElement->setAttribute('id', $dialogId);
        foreach ($triggers as $trigger) if ($trigger->hasAttribute('aria-controls')) $trigger->setAttribute('aria-controls', $dialogId);
        $dialogElement->setAttribute('data-blocks-engine-captured-dialog', 'true');
        $dialogElement->setAttribute('data-blocks-engine-triggers', implode(' ', $triggerIds));
        if (is_string($fragment['class']) && '' !== $fragment['class']) $dialogElement->setAttribute('class', $fragment['class']);
        // The dialog copies a source panel whose id another element of the
        // document still holds (the capture's own hidden copy), so it cannot
        // take that id. Author rules that name the panel by id (its width,
        // offset, paint variables) would then reach only the hidden copy.
        // Carry the id as the editor anchor class the author stylesheet
        // projector already keeps such id selectors live through.
        $sourceId = $fragment['id'];
        if ('' !== $sourceId && $sourceId !== $dialogId && $this->documentHoldsId($document, $sourceId)) {
            $dialogElement->setAttribute('class', trim($dialogElement->getAttribute('class') . ' ' . EngineMarker::editorAnchorClass($sourceId)));
        }
        // The producer observed whether the source panel was modal or a
        // dropdown. A dropdown opens without trapping the page and keeps the
        // source trigger as its toggle. Where it sits follows the evidence:
        // the panel's observed place in the source, its own positioning, or
        // (with neither) under the trigger's header.
        if ($dropdown) {
            $dialogElement->setAttribute('data-blocks-engine-presentation', 'dropdown');
            $dialogElement->setAttribute('data-blocks-engine-placement', $sourcePlace instanceof DOMElement ? 'in-place' : ($fragment['self_positioned'] ? 'source' : 'under-header'));
        }
        if (array() !== $resting) {
            $dialogElement->setAttribute('data-blocks-engine-resting', $resting['hidden'] ? 'hidden' : 'authored');
            $dialogElement->setAttribute('data-blocks-engine-resting-state', json_encode(array('closed' => $resting['closed'], 'opened' => $resting['opened']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        $ancestorState = $this->triggerAncestorState($triggers);
        if (array() !== $ancestorState) $dialogElement->setAttribute('data-blocks-engine-ancestor-state', json_encode($ancestorState, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if (is_string($fragment['aria_label']) && '' !== $fragment['aria_label']) $dialogElement->setAttribute('aria-label', $fragment['aria_label']);
        if (is_string($fragment['aria_labelledby']) && '' !== $fragment['aria_labelledby']) $dialogElement->setAttribute('aria-labelledby', $fragment['aria_labelledby']);
        if (is_string($fragment['aria_describedby']) && '' !== $fragment['aria_describedby']) $dialogElement->setAttribute('aria-describedby', $fragment['aria_describedby']);
        // A modal hides its trigger behind the page, so it needs a close
        // control; a dropdown's source trigger stays reachable and closes it.
        if (! $dropdown && ! $fragment['has_close_control']) $dialogElement->setAttribute('data-blocks-engine-add-close', 'true');
        foreach ($fragment['nodes'] as $node) {
            $dialogElement->appendChild($document->importNode($node, true));
        }
        if ($sourcePlace instanceof DOMElement && $sourcePlace->parentNode instanceof DOMNode) {
            $sourcePlace->parentNode->replaceChild($dialogElement, $sourcePlace);
            return $dialogElement;
        }
        // By default a modal mounts in its triggers' responsive document and a
        // dropdown in the body. Shared headers below carry their panels instead;
        // under-header placement remains fixed and is owned by the view script.
        $mount = $dropdown ? ($document->getElementsByTagName('body')->item(0) ?? $document->documentElement) : $this->triggerDocumentScope($document, $triggers);
        // Keep shared chrome self-contained when it is extracted as a template
        // part. The dialog must travel with its trigger, not remain page content.
        foreach ($document->getElementsByTagName('header') as $header) {
            if ($triggers && !array_filter($triggers, static fn(DOMElement $trigger): bool => !SourceDom::elementContains($header, $trigger))) {
                $mount = $header;
                break;
            }
        }
        $mount->appendChild($dialogElement);
        return $dialogElement;
    }

    /**
     * The responsive document (desktop, mobile, ...) that holds every trigger,
     * else the body. A dialog mounted there keeps the document's variant-scoped
     * author rules (`:where([data-blocks-engine-capture-device-document="mobile"]) .panel`) and
     * its display state, as the source panel had them. A modal still renders in
     * the top layer, so the mount point does not move it on screen.
     *
     * @param array<int, DOMElement> $triggers
     */
    private function triggerDocumentScope(DOMDocument $document, array $triggers): DOMElement
    {
        foreach (array() === $triggers ? array() : $this->documentScopes($document) as $scope) {
            if (array() === array_filter($triggers, static fn(DOMElement $trigger): bool => ! SourceDom::elementContains($scope, $trigger))) return $scope;
        }
        return $document->getElementsByTagName('body')->item(0) ?? $document->documentElement;
    }

    /**
     * Source-proven open/closed changes the producer recorded on a trigger's
     * ancestors (for example a header that paints itself only while its menu
     * is open, or a row that switches `data-section-theme`). Keep the bounded
     * copy the native dialog can replay; anything outside that shape is
     * dropped rather than guessed.
     *
     * @param array<int, DOMElement> $triggers
     * @return array<int, array{tag:string, closed:array<string, string|null>, opened:array<string, string|null>}>
     */
    private function triggerAncestorState(array $triggers): array
    {
        foreach ($triggers as $trigger) {
            $state = CapturedDialogBlockGenerator::ancestorState(json_decode($trigger->getAttribute(CaptureEvidence::DIALOG_ANCESTOR_STATE), true));
            if (array() !== $state) return $state;
        }
        return array();
    }

    /**
     * A capture that already wired its dialogs in the exported document keeps
     * each trigger (`data-blocks-engine-capture-dialog-trigger`) bound to a local panel
     * (`data-blocks-engine-capture-dialog-panel`). Adopt that panel as the native dialog bound to
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
            $nativeScopedWiring = $this->canReplaceScopedDisclosure($document, $scope);
            $groups = array();
            foreach ($xpath->query('.//*[@' . CaptureEvidence::DIALOG_TRIGGER . ']', $scope) ?: array() as $trigger) {
                if (! $trigger instanceof DOMElement || $this->insideProjectedDialog($trigger)) continue;
                if (NavigationPattern::ownsCapturedSubmenuTrigger($trigger)) continue;
                $key = trim($trigger->getAttribute(CaptureEvidence::DIALOG_TRIGGER));
                if (1 === preg_match('/^[A-Za-z0-9_-]{1,64}$/', $key) && $key === trim($trigger->getAttribute('aria-controls'))) {
                    $groups[$key][] = $trigger;
                }
            }
            if (count($groups) > self::MAX_STATES_PER_PAGE) continue;
            foreach ($groups as $key => $triggers) {
                if (count($triggers) > self::MAX_STATES_PER_PAGE) continue;
                $panels = $xpath->query('.//*[@' . CaptureEvidence::DIALOG_PANEL . '=' . $this->xpathLiteral((string) $key) . ']', $scope);
                $panel = $panels && 1 === $panels->length ? $panels->item(0) : null;
                if (! $panel instanceof DOMElement || array() !== array_filter($triggers, static fn(DOMElement $trigger): bool => SourceDom::elementContains($panel, $trigger))) continue;
                if (array() !== array_filter($triggers, fn(DOMElement $trigger): bool => $this->isCapturedNavigationOverlayTrigger($trigger))) continue;
                // Sanitize the panel envelope, retaining its content wrapper:
                // that wrapper can own a menu's hierarchy and presentation.
                $html = (string) $document->saveHTML($panel);
                if (strlen($html) > self::MAX_DIALOG_BYTES) continue;
                // A declared document scope owns its wired panel's ancestry,
                // stylesheet selectors and runtime. Moving it outside that
                // scope into a modal changes the captured document contract.
                // Keep the bounded source wiring and let normal runtime proof
                // validate it rather than replacing it from a second report.
                if ($scope->hasAttribute(CaptureEvidence::DOCUMENT_SCOPE)) {
                    if (!$nativeScopedWiring) {
                        array_push($adopted, ...$triggers);
                        continue;
                    }
                }
                $resting = $this->restingPresentation($panel);
                // A panel with a resting snapshot is adopted whole: the panel
                // itself, at its resting presentation, is the dialog content.
                $fragment = array() !== $resting ? $this->safeDialogFragment('<div>' . $this->restPanel($document, $panel, $resting, $triggers[0]) . '</div>') : $this->safeDialogFragment($html, (string) $key);
                if (null === $fragment) continue;
                $identity = self::dialogIdentity($key . "\n" . $scopeIndex . "\n" . self::identityHtml($html));
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
                // The producer marks an in-place dropdown panel with `data-blocks-engine-capture-dropdown`.
                // A panel the source already rendered in place (an existing-panel
                // snapshot) is a dropdown too, whatever it rests as.
                $dropdown = array() !== $resting || $panel->hasAttribute(CaptureEvidence::OBSERVED_PLACEMENT) || SourceDom::hasClass($panel, CaptureEvidence::DROPDOWN_CLASS);
                // The producer placed a dropdown panel where the source rendered
                // it (`data-blocks-engine-capture-observed-placement`); the dialog stays there.
                $scopedPanel = array() === $resting && $nativeScopedWiring && $scope->hasAttribute(CaptureEvidence::DOCUMENT_SCOPE) && $dropdown;
                $sourcePlace = array() !== $resting || ($dropdown && ($scopedPanel || $panel->hasAttribute(CaptureEvidence::OBSERVED_PLACEMENT))) || $this->isNativeGalleryPanel($panel) ? $panel : null;
                $dialog = $this->appendProjectedDialog($document, $fragment, $triggers, $triggerIds, $identity, $dropdown, $sourcePlace, $scopedPanel, $resting);
                // The resting panel now holds the opened snapshot as its open
                // content, so the trigger's copy of it is spent capture evidence.
                if (array() !== $resting) {
                    foreach ($triggers as $trigger) $trigger->removeAttribute(CaptureEvidence::DIALOG_PANEL_STATE);
                }
                if ($scopedPanel) {
                    foreach ($triggers as $trigger) {
                        foreach (array(CaptureEvidence::DIALOG_PANEL_STATE, CaptureEvidence::DIALOG_ANCESTOR_STATE, CaptureEvidence::DISCLOSURE_LABEL) as $attribute) $trigger->removeAttribute($attribute);
                    }
                    if ($triggers[0]->hasAttribute(CaptureEvidence::DIALOG_ANCESTOR_UNVERIFIED)) {
                        $dialog->setAttribute(CaptureEvidence::DIALOG_ANCESTOR_UNVERIFIED, $triggers[0]->getAttribute(CaptureEvidence::DIALOG_ANCESTOR_UNVERIFIED));
                    }
                }
                $selection = json_decode($panel->getAttribute('data-blocks-engine-gallery-selection'), true);
                if (is_array($selection) && array() !== $selection) {
                    $dialog->setAttribute('data-blocks-engine-gallery-selection', json_encode(array_map(static fn(string $id): array => array('triggerId' => $id, 'indices' => $selection), $triggerIds), JSON_THROW_ON_ERROR));
                }
                if ($menu) $dialog->setAttribute('data-blocks-engine-captured-menu', 'true');
                $panel->parentNode?->removeChild($panel);
                foreach (iterator_to_array($scope->getElementsByTagName('button')) as $button) {
                    if ($button instanceof DOMElement && $button->getAttribute(CaptureEvidence::DIALOG_CLOSE) === (string) $key && $button->hasAttribute('hidden') && ! SourceDom::elementContains($dialog, $button)) {
                        $button->parentNode?->removeChild($button);
                    }
                }
                array_push($adopted, ...$triggers);
                ++$count;
            }
        }

        return array('triggers' => $adopted, 'count' => $count);
    }

    /** Each document's generated bindings must have local, bounded native endpoints. */
    private function canReplaceScopedDisclosure(DOMDocument $document, DOMElement $scope): bool
    {
        $known = false;
        foreach ($document->getElementsByTagName('script') as $script) {
            if ($script->hasAttribute(CaptureEvidence::DISCLOSURE_RUNTIME)) {
                if (!self::isRecognizedRuntimeScript($script, CaptureEvidence::DISCLOSURE_RUNTIME)) return false;
                $known = true;
            } elseif ($script->hasAttribute(CaptureEvidence::GALLERY_RUNTIME)) {
                if (!self::isRecognizedRuntimeScript($script, CaptureEvidence::GALLERY_RUNTIME)) return false;
            } elseif (!$script->hasAttribute(CaptureEvidence::NATIVE_CONTROL_RUNTIME)
                && \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentScripts::isExecutableType($script->getAttribute('type'), 'module' === $script->getAttribute('type'))
            ) {
                return false;
            }
        }
        if (!$known) return false;
        $xpath = new DOMXPath($document);
        $triggers = $xpath->query('.//*[@' . CaptureEvidence::DIALOG_TRIGGER . ']', $scope);
        if (!$triggers || $triggers->length > self::MAX_STATES_PER_PAGE) return false;
        $snapshots = array();
        foreach ($triggers as $trigger) {
            if (!$trigger instanceof DOMElement) return false;
            $key = $trigger->getAttribute(CaptureEvidence::DIALOG_TRIGGER);
            if (1 !== preg_match('/^[A-Za-z0-9_-]{1,64}$/', $key) || $key !== $trigger->getAttribute('aria-controls')) return false;
            $panels = $xpath->query('.//*[@id=' . $this->xpathLiteral($key) . ']', $scope);
            $panel = $panels && 1 === $panels->length ? $panels->item(0) : null;
            $gallery = $panel instanceof DOMElement && $this->isNativeGalleryPanel($panel);
            if (!$gallery && !in_array(strtolower($trigger->tagName), array('button', 'a', 'summary'), true) && 'button' !== strtolower($trigger->getAttribute('role'))) return false;
            $sourcePlaced = $panel instanceof DOMElement && ($panel->hasAttribute(CaptureEvidence::EXISTING_PANEL) || $panel->hasAttribute(CaptureEvidence::OBSERVED_PLACEMENT));
            if (!$panel instanceof DOMElement || (!$sourcePlaced && 'div' !== strtolower($panel->tagName))
                || $key !== $panel->getAttribute(CaptureEvidence::DIALOG_PANEL)
                || (!$gallery && !$sourcePlaced && !SourceDom::hasClass($panel, CaptureEvidence::DROPDOWN_CLASS))
                || (!$sourcePlaced && !$panel->hasAttribute('hidden'))
                || (!$gallery && !$sourcePlaced && !$trigger->parentNode?->isSameNode($panel->parentNode))
                || strlen((string) $document->saveHTML($panel)) > self::MAX_DIALOG_BYTES
                || 0 < $panel->getElementsByTagName('script')->length
            ) return false;
            foreach (array_merge(array($trigger, $panel), iterator_to_array($panel->getElementsByTagName('*'))) as $node) {
                if (array() !== SourceDom::eventMetadata($node) || $node->hasAttribute('jsaction')) return false;
            }
            $snapshot = $trigger->getAttribute(CaptureEvidence::DIALOG_PANEL_STATE);
            if (isset($snapshots[$key]) && $snapshots[$key] !== $snapshot) return false;
            $snapshots[$key] = $snapshot;
        }
        return true;
    }

    private function isNativeGalleryPanel(DOMElement $panel): bool
    {
        foreach ($this->nativeGalleryPanels as $owned) if ($owned->isSameNode($panel)) return true;
        return false;
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
            $name = $this->normalizedLabel($candidate->getAttribute(CaptureEvidence::DISCLOSURE_LABEL));
            if ('' !== $name && (str_starts_with($name, $label) || str_starts_with($label, $name))) return true;
        }

        return false;
    }

    /**
     * The presentation an existing source panel rests with and opens to, from
     * the producer's snapshot (`data-blocks-engine-capture-existing-panel`).
     * `resting.hidden` reflects only the `hidden` attribute: a panel hidden by
     * a class or by authored CSS records `false` too, so it does not prove the
     * panel shows at rest. Either way the source runtime that toggled it is
     * gone after import, so the panel always becomes a dropdown its trigger
     * opens; the snapshot only decides how it presents while closed. Without
     * an opened snapshot, the exported panel (captured open) stands for it.
     *
     * @return array{closed?:array{class:?string, style:?string}, opened?:array{class:?string, style:?string}, hidden?:bool}
     */
    private function restingPresentation(DOMElement $panel): array
    {
        $snapshot = json_decode($panel->getAttribute(CaptureEvidence::EXISTING_PANEL), true);
        $resting = is_array($snapshot) ? ($snapshot['resting'] ?? null) : null;
        if (! is_array($resting)) return array();
        $opened = is_array($snapshot['opened'] ?? null) ? $snapshot['opened'] : array('class' => $panel->getAttribute('class'), 'style' => $panel->getAttribute('style'));
        // A modal panel (`aria-modal`, neither dropdown nor source-placed) is
        // opened by the capture runtime as a viewport overlay. Its content
        // (a fixed drawer without insets, say) is laid out against that
        // overlay, so the adopted panel opens with the same geometry.
        if ('true' === strtolower(trim($panel->getAttribute('aria-modal'))) && ! SourceDom::hasClass($panel, CaptureEvidence::DROPDOWN_CLASS) && ! $panel->hasAttribute(CaptureEvidence::OBSERVED_PLACEMENT) && is_array($opened)) {
            $opened['style'] = trim(rtrim(trim((string) ($opened['style'] ?? '')), ';') . ';' . self::MODAL_OVERLAY_STYLE, ';');
        }
        $state = CapturedDialogBlockGenerator::restingState(array('closed' => $resting, 'opened' => $opened));
        if (array() === $state) return array();
        $state['hidden'] = true === ($resting['hidden'] ?? null);

        return $state;
    }

    /**
     * The panel's markup at its resting presentation, without capture-only
     * attributes. The dialog wrapping it opens it to its opened presentation.
     *
     * @param array{closed:array{class:?string, style:?string}} $resting
     */
    private function restPanel(DOMDocument $document, DOMElement $panel, array $resting, DOMElement $trigger): string
    {
        $copy = $panel->cloneNode(true);
        if (! $copy instanceof DOMElement) return '';
        foreach (array('class', 'style') as $name) {
            $value = $resting['closed'][$name] ?? null;
            if (null === $value) $copy->removeAttribute($name); else $copy->setAttribute($name, $value);
        }
        $snapshot = json_decode($panel->getAttribute(CaptureEvidence::EXISTING_PANEL), true);
        $closedHtml = $snapshot['resting']['html'] ?? null;
        if (is_string($closedHtml)) {
            // Keep both observed states editable. CSS selects the closed copy
            // again after replay instead of leaving the opened content behind.
            $openedContent = $document->createElement('div');
            $openedContent->setAttribute('class', 'captured-dialog-open-content');
            $opened = json_decode($trigger->getAttribute(CaptureEvidence::DIALOG_PANEL_STATE), true);
            if (is_string($opened['html'] ?? null)) {
                $fragment = $this->safeDialogFragment('<div>' . $opened['html'] . '</div>');
                foreach ($fragment['nodes'] ?? array() as $child) {
                    $openedContent->appendChild($document->importNode($child, true));
                }
                while ($copy->firstChild) $copy->removeChild($copy->firstChild);
            } else {
                while ($copy->firstChild) $openedContent->appendChild($copy->firstChild);
            }
            $closedContent = $document->createElement('div');
            $closedContent->setAttribute('class', 'captured-dialog-closed-content');
            $fragment = $this->safeDialogFragment('<div>' . $closedHtml . '</div>');
            if (null !== $fragment) {
                foreach ($fragment['nodes'] as $child) {
                    $closedContent->appendChild($document->importNode($child, true));
                }
            }
            $copy->appendChild($closedContent);
            $copy->appendChild($openedContent);
        }
        foreach (array('hidden', 'data-dropdown-shown', CaptureEvidence::EXISTING_PANEL, CaptureEvidence::DIALOG_PANEL, CaptureEvidence::OBSERVED_PLACEMENT) as $attribute) $copy->removeAttribute($attribute);

        return (string) $document->saveHTML($copy);
    }

    /** Native submenus own interaction; capture's opened envelope is not static markup. */
    private function restoreNavigationPanels(array &$files): void
    {
        foreach ($files as &$file) {
            $html = $file['content'] ?? null;
            if (!is_string($html) || !preg_match('/\.html?$/i', (string) ($file['path'] ?? '')) || !str_contains($html, CaptureEvidence::EXISTING_PANEL)) continue;
            $document = HtmlSerialization::parseDocument($html);
            if ($this->hasUnrecognizedRuntime($document)) continue;
            $xpath = new DOMXPath($document);
            $changed = false;
            foreach ($document->getElementsByTagName('*') as $trigger) {
                if (!NavigationPattern::ownsCapturedSubmenuTrigger($trigger)) continue;
                $key = $trigger->getAttribute(CaptureEvidence::DIALOG_TRIGGER);
                foreach ($this->documentScopes($document) as $scope) {
                    if (!SourceDom::elementContains($scope, $trigger)) continue;
                    $panels = $xpath->query('.//*[@' . CaptureEvidence::DIALOG_PANEL . '=' . $this->xpathLiteral($key) . ']', $scope);
                    if (!$panels || 1 !== $panels->length) continue;
                    $panel = $panels->item(0);
                    if (!$panel instanceof DOMElement || !SourceDom::hasClass($panel, CaptureEvidence::DROPDOWN_CLASS)) continue;
                    $state = $this->restingPresentation($panel);
                    if (array() === $state) continue;
                    // Keep capture tokens and metadata until navigation lowering
                    // consumes them; only replace the observed presentation.
                    $snapshot = json_decode($panel->getAttribute(CaptureEvidence::EXISTING_PANEL), true);
                    foreach (array('class', 'style') as $name) {
                        if (!array_key_exists($name, $snapshot['resting'])) continue;
                        $value = $snapshot['resting'][$name];
                        if (null === $value) $panel->removeAttribute($name); else $panel->setAttribute($name, $value);
                    }
                    if ($state['hidden']) $panel->setAttribute('hidden', ''); else $panel->removeAttribute('hidden');
                    $panel->removeAttribute('data-dropdown-shown');
                    $changed = true;
                }
            }
            if ($changed) $file = ArtifactNormalizer::withTextPayload($file, HtmlSerialization::documentHtml($document));
        }
        unset($file);
    }

    /** Close only unowned capture dropdowns still carrying their opened envelope. */
    private function restoreRemainingPanels(array &$files): void
    {
        foreach ($files as &$file) {
            $html = $file['content'] ?? null;
            if (!is_string($html) || !preg_match('/\.html?$/i', (string) ($file['path'] ?? '')) || !str_contains($html, CaptureEvidence::EXISTING_PANEL)) continue;
            $document = HtmlSerialization::parseDocument($html);
            if ($this->hasUnrecognizedRuntime($document)) continue;
            $xpath = new DOMXPath($document);
            $changed = false;
            foreach ($xpath->query('//*[@' . CaptureEvidence::DIALOG_PANEL . '][@' . CaptureEvidence::EXISTING_PANEL . ']') ?: array() as $panel) {
                if (!$panel instanceof DOMElement || !SourceDom::hasClass($panel, CaptureEvidence::DROPDOWN_CLASS)) continue;
                $state = $this->restingPresentation($panel);
                if (array() === $state) continue;
                $owned = false;
                $key = trim($panel->getAttribute(CaptureEvidence::DIALOG_PANEL));
                foreach ($this->documentScopes($document) as $scope) {
                    if ('' === $key || !SourceDom::elementContains($scope, $panel)) continue;
                    $panels = $xpath->query('.//*[@' . CaptureEvidence::DIALOG_PANEL . '=' . $this->xpathLiteral($key) . ']', $scope);
                    if (!$panels || 1 !== $panels->length) continue;
                    foreach ($xpath->query('.//*[@' . CaptureEvidence::DIALOG_TRIGGER . '=' . $this->xpathLiteral($key) . ']', $scope) ?: array() as $trigger) {
                        if ($trigger instanceof DOMElement) $owned = true;
                    }
                }
                if ($owned) continue;
                $snapshot = json_decode($panel->getAttribute(CaptureEvidence::EXISTING_PANEL), true);
                $opened = $snapshot['opened'] ?? null;
                $hasOpenedEnvelope = $panel->hasAttribute('data-dropdown-shown');
                foreach (array('class', 'style') as $name) {
                    if (is_array($opened) && is_string($opened[$name] ?? null)
                        && $opened[$name] !== ($snapshot['resting'][$name] ?? null)
                        && $panel->hasAttribute($name) && $panel->getAttribute($name) === $opened[$name]) {
                        $hasOpenedEnvelope = true;
                    }
                }
                if (!$hasOpenedEnvelope) continue;
                foreach (array('class', 'style') as $name) {
                    if (!array_key_exists($name, $snapshot['resting'])) continue;
                    $value = $snapshot['resting'][$name];
                    if (null === $value) $panel->removeAttribute($name); else $panel->setAttribute($name, $value);
                }
                // hidden:false only describes the attribute, not visibility.
                // Without a verified native owner there is no safe way to open it.
                $panel->setAttribute('hidden', '');
                $panel->removeAttribute('data-dropdown-shown');
                $changed = true;
            }
            if ($changed) $file = ArtifactNormalizer::withTextPayload($file, HtmlSerialization::documentHtml($document));
        }
        unset($file);
    }

    private function isCapturedNavigationOverlayTrigger(DOMElement $trigger): bool
    {
        return 'dialog' === strtolower(trim($trigger->getAttribute('aria-haspopup')))
            && SourceDom::controlsSourceNavigation($trigger);
    }

    private function hasNavigationOwnedDialogTrigger(DOMDocument $document): bool
    {
        foreach ($document->getElementsByTagName('*') as $node) {
            if ($node instanceof DOMElement && '' !== trim($node->getAttribute(CaptureEvidence::DIALOG_TRIGGER)) && NavigationPattern::ownsCapturedSubmenuTrigger($node)) return true;
        }
        return false;
    }

    private function consumeNavigationOwnedCloseHelpers(DOMDocument $document): void
    {
        foreach (iterator_to_array($document->getElementsByTagName('*')) as $node) {
            $parent = $node->parentNode;
            if ($node instanceof DOMElement && $parent instanceof DOMElement && NavigationPattern::ownsCapturedSubmenuTrigger($node)) {
                $this->consumeMatchedCloseHelper($document, array($node), $parent);
            }
        }
    }

    /**
     * Remove the capture runtime scripts carrying `$marker` that Blocks Engine
     * recognizes. Every retirement path goes through here; a marked script with
     * any other body stays with the page.
     *
     * @return array<int, array{body:string, attribute:string, reason:string}>
     */
    private function retireRecognizedRuntime(DOMDocument $document, string $marker, string $reason): array
    {
        $retired = array();
        foreach (iterator_to_array($document->getElementsByTagName('script')) as $script) {
            if (!$script instanceof DOMElement || !self::isRecognizedRuntimeScript($script, $marker)) continue;
            $retired[] = array('body' => trim($script->textContent ?? ''), 'attribute' => $marker, 'reason' => $reason);
            $script->parentNode?->removeChild($script);
        }
        return $retired;
    }

    /**
     * A capture runtime Blocks Engine may replace: a script carrying `$marker`
     * whose trimmed body is the published body for that marker
     * (docs/contracts/capture-evidence.md, "Runtime scripts"). The marker alone
     * does not establish ownership of an arbitrary source script.
     */
    private static function isRecognizedRuntimeScript(DOMElement $script, string $marker): bool
    {
        return 'script' === strtolower($script->tagName) && $script->hasAttribute($marker)
            && CaptureEvidence::isRecognizedRuntime($script->textContent ?? '', $marker);
    }

    /**
     * Reused non-dropdown panels can rest inline when their trigger is concealed.
     * The native resting wrapper treats these as dropdowns and cannot reproduce
     * the producer's computed-style/geometry check. Keep the entire page's wiring
     * intact, including panels currently hidden at the captured viewport.
     */
    private function hasRestingInlinePanel(DOMDocument $document): bool
    {
        foreach ($document->getElementsByTagName('*') as $panel) {
            if ($panel->hasAttribute(CaptureEvidence::DIALOG_PANEL)
                && $panel->hasAttribute(CaptureEvidence::EXISTING_PANEL)
                && !SourceDom::hasClass($panel, CaptureEvidence::DROPDOWN_CLASS)) {
                $snapshot = json_decode($panel->getAttribute(CaptureEvidence::EXISTING_PANEL), true);
                if (is_array($snapshot) && is_array($snapshot['resting'] ?? null) && true !== ($snapshot['resting']['hidden'] ?? false)) return true;
            }
        }
        return false;
    }

    private function restingInlineDiagnostic(string $path): array
    {
        return $this->diagnostic('captured_disclosure_resting_inline_runtime_kept', 'warning', 'The capture disclosure runtime was kept for this page because native dialogs cannot reproduce a reused non-dropdown panel\'s responsive inline resting presentation.', array('source_path' => $path));
    }

    /** Whether a page carries a capture runtime marker whose body is not the published one. */
    private function hasUnrecognizedRuntime(DOMDocument $document): bool
    {
        foreach ($document->getElementsByTagName('script') as $script) {
            foreach (array_keys(CaptureEvidence::RECOGNIZED_RUNTIMES) as $marker) {
                if ($script instanceof DOMElement && $script->hasAttribute($marker) && !self::isRecognizedRuntimeScript($script, $marker)) return true;
            }
        }
        return false;
    }

    /** Adopt only marked native panels left unclaimed by navigation retirement. */
    private function adoptNativeDialogsWithoutReport(array $files, array $diagnostics): array
    {
        $result = $this->retireOwnedDisclosureWithoutProjection($files, $diagnostics);
        $retired = array();
        foreach ($result['files'] as $index => $file) {
            $content = $file['content'] ?? null;
            $path = (string) ($file['path'] ?? '');
            if (!preg_match('/\.html?$/i', $path) || !is_string($content)
                || $content !== ($files[$index]['content'] ?? null)
                || !str_contains($content, CaptureEvidence::DIALOG_PANEL)
                || !str_contains($content, CaptureEvidence::DIALOG_TRIGGER)) continue;
            $document = HtmlSerialization::parseFragment($content);
            $xpath = new DOMXPath($document);
            $matched = false;
            foreach ($document->getElementsByTagName('dialog') as $panel) {
                $key = trim($panel->getAttribute(CaptureEvidence::DIALOG_PANEL));
                if ('' === $key) continue;
                $triggers = $xpath->query('//*[@' . CaptureEvidence::DIALOG_TRIGGER . '=' . $this->xpathLiteral($key) . ']');
                if ($triggers && $triggers->length > 0) { $matched = true; break; }
            }
            if (!$matched) continue;
            $projection = $this->projectPage($content, array(), $path);
            $result['diagnostics'] = array_merge($result['diagnostics'], $projection['diagnostics']);
            if (0 === $projection['projected_count'] && array() === $projection['retired_scripts']) continue;
            $result['files'][$index] = ArtifactNormalizer::withTextPayload($file, $projection['html']);
            $projection['script_bindings']?->rebind($result['files'], $path);
            $result['projected_count'] += $projection['projected_count'];
            $retired[$path] = $projection['retired_scripts'];
        }
        $result['native_runtime_replacements'] = array_merge($result['native_runtime_replacements'], $this->omitRetiredDisclosureScripts($result['files'], $retired));
        return $result;
    }

    /**
     * Navigation ownership is proven from in-place controls, independently of
     * interaction probing. Non-navigation triggers still prevent retirement.
     *
     * @param array<int, array<string, mixed>> $files
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array{files:array<int, array<string, mixed>>, diagnostics:array<int, array<string, mixed>>, projected_count:int, native_runtime_replacements:array<int, array<string, string>>}
     */
    private function retireOwnedDisclosureWithoutProjection(array $files, array $diagnostics): array
    {
        $retired = array();
        foreach ($files as $index => $file) {
            $content = $file['content'] ?? null;
            $path = (string) ($file['path'] ?? '');
            if (!preg_match('/\.html?$/i', $path) || !is_string($content) || !str_contains($content, CaptureEvidence::DISCLOSURE_RUNTIME) || !str_contains($content, CaptureEvidence::DIALOG_TRIGGER)) continue;
            $document = HtmlSerialization::parseFragment($content);
            if ($this->hasUnrecognizedRuntime($document)) continue;
            if ($this->hasRestingInlinePanel($document)) {
                $diagnostics[] = $this->restingInlineDiagnostic($path);
                continue;
            }
            if (!$this->hasNavigationOwnedDialogTrigger($document) || !$this->everyDialogTriggerIsBound($document)) continue;
            $scriptBindings = new ScriptOccurrenceBindings($document);
            $this->consumeNavigationOwnedCloseHelpers($document);
            if ($this->hasDialogCloseHelper($document)) continue;
            $pageRetired = $this->retireRecognizedRuntime($document, CaptureEvidence::DISCLOSURE_RUNTIME, 'native_navigation_submenu_replaces_capture_disclosure');
            if (array() === $pageRetired) continue;
            $html = HtmlSerialization::documentHtml($document);
            if (!is_string($html)) continue;
            $files[$index] = ArtifactNormalizer::withTextPayload($files[$index], $html);
            $scriptBindings->rebind($files, $path);
            $retired[$path] = $pageRetired;
        }

        $proofs = $this->omitRetiredDisclosureScripts($files, $retired);
        return array('files' => $files, 'diagnostics' => $diagnostics, 'projected_count' => 0, 'native_runtime_replacements' => $proofs);
    }

    /** @param array<int, DOMElement> $triggers */
    private function consumeMatchedCloseHelper(DOMDocument $document, array $triggers, DOMElement $dialog): void
    {
        foreach ($triggers as $trigger) {
            $key = trim($trigger->getAttribute(CaptureEvidence::DIALOG_TRIGGER));
            if ('' === $key || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $key)) continue;
            foreach (iterator_to_array($document->getElementsByTagName('button')) as $button) {
                if (!$button instanceof DOMElement || $button->getAttribute(CaptureEvidence::DIALOG_CLOSE) !== $key || !$button->hasAttribute('hidden')) continue;
                if (SourceDom::elementContains($dialog, $button)) continue;
                if ('close' !== strtolower(trim($button->textContent ?? ''))) continue;
                $button->parentNode?->removeChild($button);
            }
        }
    }

    private function hasDialogCloseHelper(DOMDocument $document): bool
    {
        foreach ($document->getElementsByTagName('button') as $button) {
            if ($button instanceof DOMElement && '' !== trim($button->getAttribute(CaptureEvidence::DIALOG_CLOSE))) return true;
        }
        return false;
    }

    private function everyDialogTriggerIsBound(DOMDocument $document): bool
    {
        $bound = array();
        foreach ((new DOMXPath($document))->query('//*[@data-blocks-engine-captured-dialog="true"]') ?: array() as $dialog) {
            if (!$dialog instanceof DOMElement) continue;
            foreach (SourceDom::tokens($dialog->getAttribute('data-blocks-engine-triggers')) as $id) {
                if ('' !== $id) $bound[$id] = true;
            }
        }
        foreach ($document->getElementsByTagName('*') as $node) {
            if (!$node instanceof DOMElement || '' === trim($node->getAttribute(CaptureEvidence::DIALOG_TRIGGER))) continue;
            // A navigation dropdown trigger becomes a submenu, so it needs no dialog binding.
            if (NavigationPattern::ownsCapturedSubmenuTrigger($node)) continue;
            $id = trim($node->getAttribute('id'));
            if ('' === $id || !isset($bound[$id])) return false;
        }
        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @param array<string, array<int, array{body:string, attribute:string, reason:string}>> $retired
     * @return array<int, array<string, string>>
     */
    private function omitRetiredDisclosureScripts(array &$files, array $retired): array
    {
        $proofs = array();
        $kept = array();
        foreach (ArtifactNormalizer::inlineOccurrences($files) as $file) {
            $sourcePath = (string) ($file['source_path'] ?? '');
            $body = trim((string) ($file['content'] ?? ''));
            $replacement = null;
            foreach ($retired[$sourcePath] ?? array() as $record) if ($body === $record['body']) $replacement = $record;
            $matched = '' !== $sourcePath && 'inline-script' === ($file['source'] ?? null) && null !== $replacement;
            if (!$matched) {
                $kept[] = $file;
                continue;
            }
            $proofs[] = array(
                'schema' => 'blocks-engine/native-runtime-replacement/v1',
                'source_path' => $sourcePath,
                'asset_source_path' => (string) ($file['path'] ?? ''),
                'body_hash' => hash('sha256', $body),
                'attribute' => $replacement['attribute'],
                'reason' => $replacement['reason'],
            );
        }
        $files = (new ArtifactNormalizer())->contentAddressInlineFiles($kept);
        return $proofs;
    }

    /**
     * @param array<string, mixed> $trigger
     * @return array{status:'matched'|'unmatched'|'ambiguous', elements:array<int, DOMElement>}
     */
    private function findTriggers(DOMDocument $document, array $trigger): array
    {
        return $this->uniqueScopeMatches($document, fn(DOMElement $scope): array => $this->matchInScope($scope, $trigger), self::MAX_STATES_PER_PAGE);
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
            $bindings = ArrayField::array($trigger, 'dataBindings');
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
        return CapturedSelectorPath::matches(
            $scope,
            is_string($trigger['selector'] ?? null) ? trim($trigger['selector']) : '',
            limit: self::MAX_STATES_PER_PAGE,
            lastTagMatches: fn (string $tag, string $childTag): bool => $this->tagCompatible($tag, $childTag),
        );
    }

    /**
     * @param array<string, mixed> $trigger
     * @return array<int, DOMElement>
     */
    private function evidenceMatches(DOMElement $scope, array $trigger): array
    {
        $label = $this->normalizedLabel(ArrayField::string($trigger, 'label'));
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
        $label = $this->normalizedLabel(ArrayField::string($trigger, 'label'));
        $capturedPopup = strtolower(trim(ArrayField::string($trigger, 'ariaHaspopup')));
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
        $bindings = ArrayField::array($trigger, 'dataBindings');
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
        if ('button' === SourceDom::role($element)) {
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

    /** @return array{nodes:array<int, \DOMNode>, class:string, id:string, aria_label:string, aria_labelledby:string, aria_describedby:string, has_close_control:bool, self_positioned:bool}|null */
    private function safeDialogFragment(string $html, string $panelId = ''): ?array
    {
        $document = HtmlSerialization::parseFragment('<div data-dialog-root="true">' . $html . '</div>');
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
        // A capture panel envelope around the source's own native `<dialog>`:
        // the projected dialog replaces that dialog, so its content and
        // identity are the dialog's, not a second dialog nested inside.
        $inner = $sourceRoot instanceof DOMElement && 'dialog' !== strtolower($sourceRoot->tagName) ? $this->soleElementChild($sourceRoot) : null;
        if ($inner instanceof DOMElement && 'dialog' === strtolower($inner->tagName)) {
            $sourceRoot = $inner;
        } elseif ('' !== $panelId && $sourceRoot instanceof DOMElement && 'dialog' !== strtolower($sourceRoot->tagName)) {
            $matches = $xpath->query('.//dialog[@' . CaptureEvidence::DIALOG_PANEL . '=' . $this->xpathLiteral($panelId) . ']', $sourceRoot);
            if ($matches && 1 === $matches->length) $sourceRoot = $matches->item(0);
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
            'id' => $sourceRoot instanceof DOMElement ? SourceDom::safeAnchor($sourceRoot->getAttribute('id')) : '',
            'aria_label' => $sourceRoot instanceof DOMElement ? trim($sourceRoot->getAttribute('aria-label')) : '',
            'aria_labelledby' => $sourceRoot instanceof DOMElement ? trim($sourceRoot->getAttribute('aria-labelledby')) : '',
            'aria_describedby' => $sourceRoot instanceof DOMElement ? trim($sourceRoot->getAttribute('aria-describedby')) : '',
            'has_close_control' => $hasCloseControl,
            'self_positioned' => $sourceRoot instanceof DOMElement && (
                1 === preg_match('/(?:^|\s)(?:absolute|fixed|sticky)(?:\s|$)/', $sourceRoot->getAttribute('class'))
                || 1 === preg_match('/(?:^|;)\s*position\s*:\s*(?:absolute|fixed|sticky)/i', $sourceRoot->getAttribute('style'))
            ),
        );
    }

    /**
     * Whether a source element still holds the id. The capture's own panel
     * envelope does not count: its id is the wiring key, not the source
     * panel's identity, and no author rule names it.
     */
    private function documentHoldsId(DOMDocument $document, string $id): bool
    {
        $matches = (new DOMXPath($document))->query('//*[@id=' . $this->xpathLiteral($id) . '][not(@' . CaptureEvidence::DIALOG_PANEL . ')]');

        return $matches instanceof \DOMNodeList && 0 < $matches->length;
    }

    /** @param array<int, array<string, mixed>> $files @return array<string, mixed>|null */
    private function assetEvidence(array $files): ?array
    {
        foreach ($files as $file) {
            if ('asset-evidence.json' !== ($file['path'] ?? null) || ! is_string($file['content'] ?? null) || strlen($file['content']) > 2 * 1024 * 1024) continue;
            $decoded = json_decode($file['content'], true);
            return is_array($decoded) ? $decoded : null;
        }
        return null;
    }

    private function soleElementChild(DOMElement $element): ?DOMElement
    {
        $sole = null;
        foreach ($element->childNodes as $node) {
            if ($node instanceof DOMElement) {
                if (null !== $sole) return null;
                $sole = $node;
            } elseif (XML_TEXT_NODE === $node->nodeType && '' !== trim((string) $node->textContent)) {
                return null;
            }
        }

        return $sole;
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
