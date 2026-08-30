<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ShellLandmarkPolicy;
use DOMElement;

/**
 * Runtime-target and runtime-island recognition.
 *
 * Decides which elements carry a runtime contract the block output cannot
 * express natively — canvas and DOM targets, app-shell roots, workspace
 * surfaces, bounded data-attribute targets — and records the islands and
 * preservation fallbacks that follow.
 *
 * Previously `RuntimeIslandTrait`, whose own docblock recorded that it had been
 * "moved verbatim out of HtmlTransformer under #242". That move relocated the
 * code without changing anything: as a single-consumer trait every method still
 * resolved against the transformer's `$this`. Here the same logic runs against
 * an explicit {@see RuntimeIslandContext} and is exercised without a
 * transformer.
 */
final class RuntimeIslandAnalyzer
{
    /**
     * Tags a bare runtime selector may name.
     *
     * @var array<int, string>
     */
    private const RUNTIME_TAG_SELECTORS = array( 'button', 'input', 'select', 'textarea', 'ul', 'ol', 'li', 'span', 'menu', 'menuitem' );

    /**
     * Generic class/id tokens that usually mark a JS-owned application surface
     * rather than editorial content. Used only with runtime selector evidence.
     *
     * @var array<int, string>
     */
    private const RUNTIME_APP_ROOT_TOKENS = array(
        'app', 'application', 'board', 'canvas', 'dashboard', 'desktop', 'editor',
        'explorer', 'instrument', 'lab', 'playground', 'rack', 'scene', 'shell',
        'simulator', 'stage', 'studio', 'terminal', 'viewport', 'workspace', 'world',
    );

    public function __construct(
        private readonly RuntimeIslandContext $context,
        private readonly PseudoFormAnalyzer $pseudoFormAnalyzer
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     */
    public function captureCanvasFallback(DOMElement $element, array &$fallbacks): void
    {
        $this->context->fallbackEmitter()->captureCanvasFallback($element, $fallbacks, $this->context->runtimeDom());
    }

    public function isRuntimeCanvasTarget(DOMElement $element): bool
    {
        return $this->context->fallbackEmitter()->isRuntimeCanvasTarget($element);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, bool>
     */
    public function runtimeCanvasSelectorsFromOptions(array $options): array
    {
        return $this->runtimeSelectorsFromOptions($options, 'runtime_canvas_selectors');
    }

    public function isRuntimeDomTarget(DOMElement $element): bool
    {
        $id = trim($this->context->attr($element, 'id'));
        if ( '' !== $id && $this->context->runtimeSelectors()->hasDom('#' . $id) && ! $this->isPresentationalRuntimeSelector('#' . $id) ) {
            return true;
        }

        foreach ( preg_split('/\s+/', trim($this->context->attr($element, 'class'))) ?: array() as $class ) {
            if ( '' !== $class && $this->context->runtimeSelectors()->hasDom('.' . $class) && ! $this->isPresentationalRuntimeSelector('.' . $class) ) {
                return true;
            }
        }

        foreach ( array_keys($this->context->runtimeSelectors()->domSelectors()) as $selector ) {
            if ( ! $this->isPresentationalRuntimeSelector((string) $selector) && $this->elementMatchesRuntimeSelector($element, (string) $selector) ) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int,string> */
    public function runtimeDomSelectorsForElement(DOMElement $element): array
    {
        $selectors = array();
        $id = trim($this->context->attr($element, 'id'));
        if ( '' !== $id && $this->context->runtimeSelectors()->hasDom('#' . $id) ) $selectors[] = '#' . $id;
        foreach ( preg_split('/\s+/', trim($this->context->attr($element, 'class'))) ?: array() as $class ) if ( '' !== $class && $this->context->runtimeSelectors()->hasDom('.' . $class) ) $selectors[] = '.' . $class;
        foreach ( array_keys($this->context->runtimeSelectors()->domSelectors()) as $selector ) {
            if ( str_starts_with((string) $selector, '.') || str_starts_with((string) $selector, '#') || strtolower((string) $selector) === strtolower($element->tagName) ) continue;
            if ( ! $this->isPresentationalRuntimeSelector((string) $selector) && $this->elementMatchesRuntimeSelector($element, (string) $selector) ) $selectors[] = (string) $selector;
        }
        return array_values(array_unique($selectors));
    }

    public function shouldPreserveRuntimeAppShell(DOMElement $element): bool
    {
        if ( ! $this->context->runtimeSelectors()->hasRuntimeTargets() ) {
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
    public function runtimeTargetsInSubtree(DOMElement $element, int $limit): array
    {
        $targets = array();
        foreach ( $this->context->descendantElements($element) as $descendant ) {
            if ( $this->isRuntimeDomTarget($descendant) || $this->isRuntimeCanvasTarget($descendant) ) {
                $targets[] = array_filter(array(
                    'selector'   => $this->context->runtimeIslandSelector($descendant),
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

    public function shouldRecordRuntimeHtmlSubtreeIsland(DOMElement $element): bool
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

        foreach ( $this->context->descendantElements($element) as $descendant ) {
            $tagName = strtolower($descendant->tagName);
            if ( 'form' === $tagName && FormControlClassifier::hasDataEntryControls($descendant) ) {
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
    public function recordRuntimeIslandsForPreservedHtmlBlocks(array $blocks): void
    {
        foreach ( $blocks as $block ) {
            if ( ! is_array($block) ) {
                continue;
            }

            if ( 'core/html' === ($block['blockName'] ?? '') ) {
                $content = is_array($block['attrs'] ?? null) && is_scalar($block['attrs']['content'] ?? null) ? (string) $block['attrs']['content'] : '';
                $element = $this->context->preservedHtmlRootElement($content);
                if ( $element instanceof DOMElement && $this->shouldRecordRuntimeHtmlSubtreeIsland($element) ) {
                    $targets = $this->runtimeTargetsInSubtree($element, 8);
                    $this->recordRuntimeIsland($element, 'app_shell', 'runtime_html_subtree', 'client_script_execution', array(
                        'events'            => $this->context->eventMetadata($element),
                        'target_count'      => count($targets),
                        'targets'           => $targets,
                        'app_shell_signals' => $this->runtimeAppShellSignals($element),
                        'required_scripts'  => $this->context->requiredScriptsForElement($element),
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
    public function runtimeAppShellSignals(DOMElement $element): array
    {
        $signals = array();
        if ( $this->hasRuntimeAppRootToken($element) ) {
            $signals[] = 'app_root_token';
        }
        if ( $this->context->hasWorkspaceSurface($element) ) {
            $signals[] = 'workspace_surface';
        }

        return array_values(array_unique($signals));
    }

    public function hasRuntimeAppRootToken(DOMElement $element): bool
    {
        $tokens = preg_split('/[^A-Za-z0-9]+/', strtolower(trim($this->context->attr($element, 'id') . ' ' . $this->context->attr($element, 'class')))) ?: array();
        foreach ( $tokens as $token ) {
            if ( in_array($token, self::RUNTIME_APP_ROOT_TOKENS, true) ) {
                return true;
            }
        }

        return false;
    }

    public function textareaIsRuntimeWorkspaceSurface(DOMElement $textarea, DOMElement $root): bool
    {
        if ( ! $this->isRuntimeDomTarget($textarea) || FormControlClassifier::hasFormAncestor($textarea) ) {
            return false;
        }

        // A plain wrapper that pairs data entry with a submit action is a
        // pseudo-form, not an editor surface. Only a non-control target inside
        // that same candidate upgrades it to a runtime workspace.
        for ( $ancestor = $textarea->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode ) {
            if ( $this->pseudoFormAnalyzer->isPseudoForm($ancestor) ) {
                return $ancestor === $root && $this->hasNonFormControlRuntimeTarget($ancestor);
            }
            if ( $ancestor === $root ) {
                break;
            }
        }

        return true;
    }

    public function hasNonFormControlRuntimeTarget(DOMElement $element): bool
    {
        foreach ( $this->context->descendantElements($element) as $descendant ) {
            if ( $this->isRuntimeDomTarget($descendant) && ! FormControlClassifier::isControlElement($descendant) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    public function boundedRuntimeTargetAttributes(DOMElement $element): array
    {
        $attributes = array();
        foreach ( array( 'id', 'class', 'role', 'aria-label', 'type', 'name' ) as $name ) {
            $value = trim($this->context->attr($element, $name));
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

    public function shouldPreserveDataAttributeRuntimeTarget(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( in_array($tagName, array( 'canvas', 'form', 'script' ), true) || FormControlClassifier::isControlElement($element) ) {
            return false;
        }

        foreach ( array_keys($this->context->runtimeSelectors()->domSelectors()) as $selector ) {
            if ( str_contains((string) $selector, '[') && $this->elementMatchesRuntimeSelector($element, (string) $selector) ) {
                return true;
            }
        }

        return false;
    }

    public function isPresentationalRuntimeSelector(string $selector): bool
    {
        return $this->context->isPresentationalAnimationSelector($selector) && ! $this->context->runtimeSelectors()->hasBehavioral($selector);
    }

    public function elementMatchesRuntimeSelector(DOMElement $element, string $selector): bool
    {
        $tag = strtolower($element->tagName);
        if ( $selector === $tag && in_array($tag, array_merge(array('canvas', 'svg'), self::RUNTIME_TAG_SELECTORS), true) ) {
            return true;
        }
        if ( preg_match('/^([a-z][a-z0-9-]*)\.([A-Za-z][A-Za-z0-9_-]*)$/', $selector, $match) ) {
            return $tag === strtolower((string) $match[1]) && in_array((string) $match[2], preg_split('/\s+/', trim($this->context->attr($element, 'class'))) ?: array(), true);
        }
        if ( preg_match('/^(?:([a-z][a-z0-9-]*))?\[(data-[A-Za-z][A-Za-z0-9_-]*)(?:=["\'][^"\']{1,80}["\'])?\]$/', $selector, $match) ) {
            return ( '' === (string) ($match[1] ?? '') || $tag === strtolower((string) $match[1]) ) && $element->hasAttribute(strtolower((string) $match[2]));
        }

        return false;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function recordRuntimeIsland(DOMElement $element, string $kind, string $reason, string $runtimeRequirement, array $metadata = array()): void
    {
        $this->context->fallbackEmitter()->recordRuntimeIsland($element, $kind, $reason, $runtimeRequirement, $metadata, $this->context->runtimeDom());
    }

    public function recordNativeRuntimeDomPreservation(DOMElement $element, string $blockName, bool $includeRichTextDescendants = false): void
    {
        $elements = array($element);
        if ($includeRichTextDescendants) {
            foreach ($this->context->descendantElements($element) as $descendant) {
                if ($this->context->isInlineContentElement(strtolower($descendant->tagName))) {
                    $elements[] = $descendant;
                }
            }
        }
        foreach ($elements as $target) {
            foreach ($this->runtimeDomSelectorsForElement($target) as $selector) {
                $this->context->runtimeDom()->recordPreservation($blockName, strtolower($target->tagName), $selector);
            }
        }
    }

    public function recordRuntimeDomFallback(DOMElement $element, string $blockName): void
    {
        foreach ($this->runtimeDomSelectorsForElement($element) as $selector) {
            $this->context->runtimeDom()->recordFallback($blockName, strtolower($element->tagName), $selector);
        }
    }

    public function recordBlockRuntimeDomContract(DOMElement $element, string $blockName): bool
    {
        $tagName = strtolower($element->tagName);
        if ( ! $this->isRuntimeDomTarget($element)
            || FormControlClassifier::isControlElement($element)
            || in_array($tagName, array( 'canvas', 'form', 'script' ), true)
        ) {
            return false;
        }

        if ( ! $this->canRetainRuntimeDomContractNatively($element, $blockName) ) {
            $this->recordRuntimeIsland($element, 'dom', 'runtime_dom_target', 'client_script_execution', array(
                'events'           => $this->context->eventMetadata($element),
                'required_scripts' => $this->context->requiredScriptsForElement($element),
            ));
            $this->recordRuntimeDomFallback($element, $blockName);
        } else {
            $this->recordNativeRuntimeDomPreservation($element, $blockName, in_array($blockName, array( 'core/paragraph', 'core/heading' ), true));
        }

        return true;
    }

    public function canRetainRuntimeDomContractNatively(DOMElement $element, string $blockName): bool
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

        foreach ($this->context->descendantElements($element) as $descendant) {
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
    public function runtimeScriptMetadataFromOptions(array $options): array
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

        return $this->context->dedupeArrayRows($metadata);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, bool>
     */
    public function runtimeSelectorsFromOptions(array $options, string $key): array
    {
        $selectors = array();
        foreach ( $options[$key] ?? array() as $selector ) {
            if ( is_string($selector) && $this->isBoundedRuntimeSelector($selector) ) {
                $selectors[$selector] = true;
            }
        }

        return $selectors;
    }

    public function isBoundedRuntimeSelector(string $selector): bool
    {
        $name = '[A-Za-z][A-Za-z0-9_-]*';
        $runtimeTags = implode('|', self::RUNTIME_TAG_SELECTORS);
        return 1 === preg_match('/^(?:[#.]' . $name . '|' . $name . '\.' . $name . '|\[data-' . $name . '(?:=["\'][^"\']{1,80}["\'])?\]|' . $name . '\[data-' . $name . '(?:=["\'][^"\']{1,80}["\'])?\]|canvas|svg|' . $runtimeTags . ')$/', $selector);
    }
}
