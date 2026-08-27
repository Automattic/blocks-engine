<?php

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ShellLandmarkPolicy;
use DOMElement;

/**
 * Runtime-target and runtime-island recognition.
 *
 * Decides which elements carry a runtime contract the block output cannot
 * express natively — canvas and DOM targets, app-shell roots, workspace
 * surfaces, bounded data-attribute targets — and records the islands and
 * preservation fallbacks that follow from that. Moved verbatim out of
 * HtmlTransformer under #242; behaviour is unchanged.
 */
trait RuntimeIslandTrait
{
    /**
     * @param array<int, array<string, mixed>> $fallbacks
     */
    private function captureCanvasFallback(DOMElement $element, array &$fallbacks): void
    {
        $this->fallbackEmitter()->captureCanvasFallback($element, $fallbacks, $this->runtimeDom());
    }

    private function isRuntimeCanvasTarget(DOMElement $element): bool
    {
        return $this->fallbackEmitter()->isRuntimeCanvasTarget($element);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, bool>
     */
    private function runtimeCanvasSelectorsFromOptions(array $options): array
    {
        return $this->runtimeSelectorsFromOptions($options, 'runtime_canvas_selectors');
    }

    private function isRuntimeDomTarget(DOMElement $element): bool
    {
        $id = trim($this->attr($element, 'id'));
        if ( '' !== $id && $this->runtimeSelectors()->hasDom('#' . $id) && ! $this->isPresentationalRuntimeSelector('#' . $id) ) {
            return true;
        }

        foreach ( preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array() as $class ) {
            if ( '' !== $class && $this->runtimeSelectors()->hasDom('.' . $class) && ! $this->isPresentationalRuntimeSelector('.' . $class) ) {
                return true;
            }
        }

        foreach ( array_keys($this->runtimeSelectors()->domSelectors()) as $selector ) {
            if ( ! $this->isPresentationalRuntimeSelector((string) $selector) && $this->elementMatchesRuntimeSelector($element, (string) $selector) ) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int,string> */
    private function runtimeDomSelectorsForElement(DOMElement $element): array
    {
        $selectors = array();
        $id = trim($this->attr($element, 'id'));
        if ( '' !== $id && $this->runtimeSelectors()->hasDom('#' . $id) ) $selectors[] = '#' . $id;
        foreach ( preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array() as $class ) if ( '' !== $class && $this->runtimeSelectors()->hasDom('.' . $class) ) $selectors[] = '.' . $class;
        foreach ( array_keys($this->runtimeSelectors()->domSelectors()) as $selector ) {
            if ( str_starts_with((string) $selector, '.') || str_starts_with((string) $selector, '#') || strtolower((string) $selector) === strtolower($element->tagName) ) continue;
            if ( ! $this->isPresentationalRuntimeSelector((string) $selector) && $this->elementMatchesRuntimeSelector($element, (string) $selector) ) $selectors[] = (string) $selector;
        }
        return array_values(array_unique($selectors));
    }

    private function shouldPreserveRuntimeAppShell(DOMElement $element): bool
    {
        if ( ! $this->runtimeSelectors()->hasRuntimeTargets() ) {
            return false;
        }

        $tagName = strtolower($element->tagName);
        if ( ShellLandmarkPolicy::isGlobalShellLandmarkTag($tagName) ) {
            return false;
        }

        $targets = $this->runtimeTargetsInSubtree($element, 4);
        if ( count($targets) < 2 ) {
            return false;
        }

        $signals = $this->runtimeAppShellSignals($element);
        if ( in_array($tagName, array( 'body', 'main' ), true) && ! in_array('app_root_token', $signals, true) ) {
            return false;
        }

        return in_array('app_root_token', $signals, true) || in_array('workspace_surface', $signals, true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function runtimeTargetsInSubtree(DOMElement $element, int $limit): array
    {
        $targets = array();
        foreach ( $this->descendantElements($element) as $descendant ) {
            if ( $this->isRuntimeDomTarget($descendant) || $this->isRuntimeCanvasTarget($descendant) ) {
                $targets[] = array_filter(array(
                    'selector'   => $this->runtimeIslandSelector($descendant),
                    'tag'        => strtolower($descendant->tagName),
                    'attributes' => $this->boundedRuntimeTargetAttributes($descendant),
                ), static fn (mixed $value): bool => '' !== $value && array() !== $value);
            }

            if ( count($targets) >= $limit ) {
                break;
            }
        }

        return $targets;
    }

    private function shouldRecordRuntimeHtmlSubtreeIsland(DOMElement $element): bool
    {
        if ( ! in_array(strtolower($element->tagName), array( 'article', 'aside', 'div', 'main', 'section' ), true) ) {
            return false;
        }

        if ( $this->isRuntimeDomTarget($element) ) {
            return false;
        }

        if ( 0 < count($this->runtimeTargetsInSubtree($element, 1)) ) {
            return true;
        }

        foreach ( $this->descendantElements($element) as $descendant ) {
            $tagName = strtolower($descendant->tagName);
            if ( 'form' === $tagName && $this->formHasDataEntryControls($descendant) ) {
                return true;
            }
            if ( in_array($tagName, array( 'canvas', 'template' ), true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    private function recordRuntimeIslandsForPreservedHtmlBlocks(array $blocks): void
    {
        foreach ( $blocks as $block ) {
            if ( ! is_array($block) ) {
                continue;
            }

            if ( 'core/html' === ($block['blockName'] ?? '') ) {
                $content = is_array($block['attrs'] ?? null) && is_scalar($block['attrs']['content'] ?? null) ? (string) $block['attrs']['content'] : '';
                $element = $this->preservedHtmlRootElement($content);
                if ( $element instanceof DOMElement && $this->shouldRecordRuntimeHtmlSubtreeIsland($element) ) {
                    $targets = $this->runtimeTargetsInSubtree($element, 8);
                    $this->recordRuntimeIsland($element, 'app_shell', 'runtime_html_subtree', 'client_script_execution', array(
                        'events'            => $this->eventMetadata($element),
                        'target_count'      => count($targets),
                        'targets'           => $targets,
                        'app_shell_signals' => $this->runtimeAppShellSignals($element),
                        'required_scripts'  => $this->requiredScriptsForElement($element),
                    ));
                }
            }

            if ( isset($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
                $this->recordRuntimeIslandsForPreservedHtmlBlocks($block['innerBlocks']);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function runtimeAppShellSignals(DOMElement $element): array
    {
        $signals = array();
        if ( $this->hasRuntimeAppRootToken($element) ) {
            $signals[] = 'app_root_token';
        }
        if ( $this->hasWorkspaceSurface($element) ) {
            $signals[] = 'workspace_surface';
        }

        return array_values(array_unique($signals));
    }

    private function hasRuntimeAppRootToken(DOMElement $element): bool
    {
        $tokens = preg_split('/[^A-Za-z0-9]+/', strtolower(trim($this->attr($element, 'id') . ' ' . $this->attr($element, 'class')))) ?: array();
        foreach ( $tokens as $token ) {
            if ( in_array($token, self::RUNTIME_APP_ROOT_TOKENS, true) ) {
                return true;
            }
        }

        return false;
    }

    private function textareaIsRuntimeWorkspaceSurface(DOMElement $textarea, DOMElement $root): bool
    {
        if ( ! $this->isRuntimeDomTarget($textarea) || $this->hasFormAncestor($textarea) ) {
            return false;
        }

        // A plain wrapper that pairs data entry with a submit action is a
        // pseudo-form, not an editor surface. Only a non-control target inside
        // that same candidate upgrades it to a runtime workspace.
        for ( $ancestor = $textarea->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode ) {
            if ( $this->isDivBasedPseudoForm($ancestor) ) {
                return $ancestor === $root && $this->hasNonFormControlRuntimeTarget($ancestor);
            }
            if ( $ancestor === $root ) {
                break;
            }
        }

        return true;
    }

    private function hasNonFormControlRuntimeTarget(DOMElement $element): bool
    {
        foreach ( $this->descendantElements($element) as $descendant ) {
            if ( $this->isRuntimeDomTarget($descendant) && ! $this->isFormControlElement($descendant) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function boundedRuntimeTargetAttributes(DOMElement $element): array
    {
        $attributes = array();
        foreach ( array( 'id', 'class', 'role', 'aria-label', 'type', 'name' ) as $name ) {
            $value = trim($this->attr($element, $name));
            if ( '' !== $value ) {
                $attributes[$name] = substr($value, 0, 160);
            }
        }

        foreach ( $element->attributes ?? array() as $attribute ) {
            if ( str_starts_with(strtolower($attribute->name), 'data-') ) {
                $attributes[$attribute->name] = substr((string) $attribute->value, 0, 160);
            }
        }

        return $attributes;
    }

    private function shouldPreserveDataAttributeRuntimeTarget(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( in_array($tagName, array( 'canvas', 'form', 'script' ), true) || $this->isFormControlElement($element) ) {
            return false;
        }

        foreach ( array_keys($this->runtimeSelectors()->domSelectors()) as $selector ) {
            if ( str_contains((string) $selector, '[') && $this->elementMatchesRuntimeSelector($element, (string) $selector) ) {
                return true;
            }
        }

        return false;
    }

    private function isPresentationalRuntimeSelector(string $selector): bool
    {
        return $this->isPresentationalAnimationSelector($selector) && ! $this->runtimeSelectors()->hasBehavioral($selector);
    }

    private function elementMatchesRuntimeSelector(DOMElement $element, string $selector): bool
    {
        $tag = strtolower($element->tagName);
        if ( $selector === $tag && in_array($tag, array_merge(array('canvas', 'svg'), self::RUNTIME_TAG_SELECTORS), true) ) {
            return true;
        }
        if ( preg_match('/^([a-z][a-z0-9-]*)\.([A-Za-z][A-Za-z0-9_-]*)$/', $selector, $match) ) {
            return $tag === strtolower((string) $match[1]) && in_array((string) $match[2], preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array(), true);
        }
        if ( preg_match('/^(?:([a-z][a-z0-9-]*))?\[(data-[A-Za-z][A-Za-z0-9_-]*)(?:=["\'][^"\']{1,80}["\'])?\]$/', $selector, $match) ) {
            return ( '' === (string) ($match[1] ?? '') || $tag === strtolower((string) $match[1]) ) && $element->hasAttribute(strtolower((string) $match[2]));
        }

        return false;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function recordRuntimeIsland(DOMElement $element, string $kind, string $reason, string $runtimeRequirement, array $metadata = array()): void
    {
        $this->fallbackEmitter()->recordRuntimeIsland($element, $kind, $reason, $runtimeRequirement, $metadata, $this->runtimeDom());
    }

    private function recordNativeRuntimeDomPreservation(DOMElement $element, string $blockName, bool $includeRichTextDescendants = false): void
    {
        $elements = array($element);
        if ($includeRichTextDescendants) {
            foreach ($this->descendantElements($element) as $descendant) {
                if ($this->isInlineContentElement(strtolower($descendant->tagName))) {
                    $elements[] = $descendant;
                }
            }
        }
        foreach ($elements as $target) {
            foreach ($this->runtimeDomSelectorsForElement($target) as $selector) {
                $this->runtimeDom()->recordPreservation($blockName, strtolower($target->tagName), $selector);
            }
        }
    }

    private function recordRuntimeDomFallback(DOMElement $element, string $blockName): void
    {
        foreach ($this->runtimeDomSelectorsForElement($element) as $selector) {
            $this->runtimeDom()->recordFallback($blockName, strtolower($element->tagName), $selector);
        }
    }

    private function canRetainRuntimeDomContractNatively(DOMElement $element, string $blockName): bool
    {
        if ( ! in_array($blockName, array('core/group', 'core/paragraph', 'core/heading'), true) ) {
            return false;
        }

        // Group can serialize these semantic wrappers exactly. Generic div app
        // surfaces retain their existing bounded-island treatment.
        if ('core/group' === $blockName && ! in_array(strtolower($element->tagName), array('article', 'aside', 'footer', 'header', 'main', 'section'), true)) {
            return false;
        }

        if (array_intersect($this->runtimeAppShellSignals($element), array('app_root_token', 'workspace_surface'))) {
            return false;
        }

        foreach ($this->descendantElements($element) as $descendant) {
            if (in_array(strtolower($descendant->tagName), array('button', 'input', 'select', 'textarea', 'canvas', 'form', 'template'), true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    private function runtimeScriptMetadataFromOptions(array $options): array
    {
        $metadata = array();
        foreach ( $options['runtime_script_metadata'] ?? array() as $script ) {
            if ( ! is_array($script) ) {
                continue;
            }

            $metadata[] = array_filter(array(
                'path'               => is_string($script['path'] ?? null) ? $script['path'] : '',
                'selector'           => is_string($script['selector'] ?? null) ? $script['selector'] : '',
                'attributes'         => is_array($script['attributes'] ?? null) ? $script['attributes'] : array(),
                'script_role'        => 'runtime',
                'script_source_kind' => is_string($script['script_source_kind'] ?? null) ? $script['script_source_kind'] : 'external',
            ), static fn (mixed $value): bool => '' !== $value && array() !== $value);
        }

        return $this->dedupeArrayRows($metadata);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, bool>
     */
    private function runtimeSelectorsFromOptions(array $options, string $key): array
    {
        $selectors = array();
        foreach ( $options[$key] ?? array() as $selector ) {
            if ( is_string($selector) && $this->isBoundedRuntimeSelector($selector) ) {
                $selectors[$selector] = true;
            }
        }

        return $selectors;
    }

    private function isBoundedRuntimeSelector(string $selector): bool
    {
        $name = '[A-Za-z][A-Za-z0-9_-]*';
        $runtimeTags = implode('|', self::RUNTIME_TAG_SELECTORS);
        return 1 === preg_match('/^(?:[#.]' . $name . '|' . $name . '\.' . $name . '|\[data-' . $name . '(?:=["\'][^"\']{1,80}["\'])?\]|' . $name . '\[data-' . $name . '(?:=["\'][^"\']{1,80}["\'])?\]|canvas|svg|' . $runtimeTags . ')$/', $selector);
    }
}
