<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeScriptEvidenceAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\ThemeToggleBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgMaterializer;
use Closure;
use DOMElement;
use LogicException;

/** Promotes corroborated theme controls onto the canonical companion block. */
final class ThemeToggleConverter implements ElementConverter
{
    private const MAX_THEME_CSS_EVIDENCE_BYTES = 2097152;
    private const MAX_THEME_RUNTIME_EVIDENCE_BYTES = 1048576;
    /**
     * @param Closure(DOMElement): string $sanitizeInlineSvgMarkup
     * @param Closure(): string $capturedRootTheme
     * @param Closure(DOMElement): array<string, mixed> $themeControlPresentation
     */
    public function __construct(
        private readonly SvgMaterializer $svgMaterializer,
        private readonly HtmlTransformerSession $session,
        private readonly Closure $sanitizeInlineSvgMarkup,
        private readonly Closure $capturedRootTheme,
        private readonly Closure $themeControlPresentation
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        if ( 'button' !== $tagName ) {
            $themeGroup = $this->selectionGroup($element);
            return null === $themeGroup ? ConversionOutcome::unhandled() : ConversionOutcome::handled($themeGroup);
        }

        $themeToggle = $this->block($element);

        return null === $themeToggle
            ? ConversionOutcome::unhandled()
            : ConversionOutcome::handled($themeToggle);
    }

    /** @return array<string, mixed>|null */
    private function selectionGroup(DOMElement $element): ?array
    {
        if ( ! in_array(strtolower($element->tagName), array('div', 'nav', 'section', 'footer'), true) ) {
            return null;
        }
        $buttons = array();
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }
            if ( ! $child instanceof DOMElement || 'button' !== strtolower($child->tagName) ) {
                return null;
            }
            $buttons[] = $child;
        }
        $defaultTheme = ($this->capturedRootTheme)();
        if ( 3 !== count($buttons) ) {
            return null;
        }

        $css = substr($this->session->authorStyleAnalysis()->combinedCss(), 0, self::MAX_THEME_CSS_EVIDENCE_BYTES);
        if ( ! preg_match('/:root(?:(?:\s|,|:|\.|\[)[^{}]*)?\{[^}]*(?:color-scheme|--(?:background|foreground)|background(?:-color)?\s*:|color\s*:)/i', $css) ) {
            return null;
        }
        $evidenceAnalyzer = new RuntimeScriptEvidenceAnalyzer();
        $runtimeOwners = array();
        $runtimeBytes = 0;
        foreach ( $this->session->runtimeBehaviorState()->runtimeProjectionScriptAssets() as $asset ) {
            $script = (string) ($asset['content'] ?? '');
            $scriptBytes = strlen($script);
            if ( $scriptBytes > self::MAX_THEME_RUNTIME_EVIDENCE_BYTES || $runtimeBytes + $scriptBytes > self::MAX_THEME_RUNTIME_EVIDENCE_BYTES ) {
                continue;
            }
            $runtimeBytes += $scriptBytes;
            $ownership = $evidenceAnalyzer->themePreferenceOwnership($script);
            if ( null !== $ownership ) {
                $runtimeOwners[] = array('path' => (string) ($asset['path'] ?? ''), 'sha256' => hash('sha256', $script), 'ownership' => $ownership);
            }
        }

        $modes = array( 'light', 'system', 'dark' );
        $modeByLabel = array(
            'light' => 'light', 'light theme' => 'light', 'light mode' => 'light',
            'system' => 'system', 'system theme' => 'system', 'system mode' => 'system',
            'dark' => 'dark', 'dark theme' => 'dark', 'dark mode' => 'dark',
        );
        $entries = array();
        foreach ( $buttons as $button ) {
            $label = trim(SourceDom::attr($button, 'aria-label'));
            $normalizedLabel = strtolower(trim((string) preg_replace('/[\s_-]+/', ' ', $label)));
            $mode = $modeByLabel[$normalizedLabel] ?? '';
            $buttonAttributes = SourceDom::htmlAttributes($button);
            $buttonType = strtolower(trim((string) ($buttonAttributes['type'] ?? '')));
            if ( '' === $mode || in_array($mode, array_column($entries, 'mode'), true) || 1 !== SourceDom::childElementCount($button)
                || ( '' !== $buttonType && 'button' !== $buttonType )
                || array_intersect(array_keys($buttonAttributes), array('form', 'formaction', 'formenctype', 'formmethod', 'formnovalidate', 'formtarget', 'name', 'value')) ) {
                return null;
            }
            if ( '' === $buttonType ) {
                for ( $ancestor = $button->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode ) {
                    if ( 'form' === strtolower($ancestor->tagName) ) return null;
                }
            }
            $svg = SourceDom::firstChildElement($button, 'svg');
            if ( ! $svg instanceof DOMElement || ! SourceDom::svgHasDrawableContent($svg) ) {
                return null;
            }
            $identity = strtolower(SourceDom::attr($svg, 'class') . ' ' . SourceDom::attr($svg, 'data-lucide'));
            $semantic = array('light' => 'sun', 'system' => 'monitor', 'dark' => 'moon')[$mode];
            if ( ! preg_match('/(?:^|[^a-z0-9])(?:lucide[-_ ])?' . $semantic . '(?:[^a-z0-9]|$)/', $identity) ) {
                return null;
            }
            $icon = $this->svgMaterializer->restoreSvgCasing(($this->sanitizeInlineSvgMarkup)($svg));
            if ( '' === $icon || ! SourceDom::isSafeSvgContent($icon) ) {
                return null;
            }
            $presentation = ($this->themeControlPresentation)($button);
            $safeAttributes = array();
            foreach ( $buttonAttributes as $name => $value ) {
                $name = strtolower($name);
                if ( in_array($name, array('id', 'title', 'tabindex', 'dir', 'lang', 'role', 'accesskey', 'hidden', 'disabled'), true)
                    || (str_starts_with($name, 'aria-') && ! in_array($name, array('aria-label', 'aria-pressed', 'aria-selected', 'aria-current'), true))
                    || (str_starts_with($name, 'data-') && ! in_array($name, array('data-selected', 'data-state'), true)) ) {
                    $safeAttributes[$name] = in_array($name, array('hidden', 'disabled'), true) ? true : $value;
                }
            }
            $entries[] = array(
                'mode' => $mode,
                'ariaLabel' => $label,
                'iconSemantic' => $semantic,
                'className' => implode(' ', array_values(array_unique(array_filter(preg_split('/\s+/', trim(SourceDom::attr($button, 'class') . ' ' . (string) ($presentation['className'] ?? ''))) ?: array())))),
                'attributes' => $safeAttributes,
                'style' => is_array($presentation['style'] ?? null) ? $presentation['style'] : array(),
                'icon' => $icon,
                'selected' => 'true' === strtolower(SourceDom::attr($button, 'aria-pressed'))
                    || 'true' === strtolower(SourceDom::attr($button, 'aria-selected'))
                    || 'true' === strtolower(SourceDom::attr($button, 'data-selected'))
                    || in_array(strtolower(SourceDom::attr($button, 'data-state')), array('active', 'checked', 'selected'), true)
                    || 'true' === strtolower(SourceDom::attr($button, 'aria-current')),
            );
        }

        if ( 1 < count(array_filter($entries, static fn (array $entry): bool => true === $entry['selected'])) ) {
            return null;
        }
        $qualified = $this->sourceQualifiedThemeOwnership($element, $entries, $runtimeOwners);
        if ( null === $qualified ) return null;
        $ownership = $qualified['runtime_ownership'];
        $operator = $qualified['operator'];
        $rootAttribute = (string) $operator['root']['attribute'];
        $darkValue = (string) $operator['root']['dark_value'];
        $lightValue = (string) $operator['root']['light_value'];
        $defaultTheme = (string) ($operator['default_preference'] ?? $defaultTheme);
        if ( 'class' === $rootAttribute ) {
            if ( ! preg_match('/(?:^|})\.' . preg_quote($darkValue, '/') . '(?:\s[^{}]*)?\{[^}]*(?:color-scheme|--(?:background|foreground)|background(?:-color)?\s*:|color\s*:)/i', $css) ) return null;
            $runtimeClassValues = is_array($ownership['rootClassValues'] ?? null) ? $ownership['rootClassValues'] : array();
            if ( array() !== $runtimeClassValues
                && ( ! in_array($darkValue, $runtimeClassValues, true)
                    || ( '' !== $lightValue && ! in_array($lightValue, $runtimeClassValues, true) ) ) ) return null;
        } else {
            if ( ! preg_match('/:root[^{}]*\[' . preg_quote($rootAttribute, '/') . '\s*=\s*(["\']?)([A-Za-z0-9_-]+)\1\s*\][^{}]*\{[^}]*(?:color-scheme\s*:\s*dark|--(?:background|foreground)|background(?:-color)?\s*:|color\s*:)/i', $css) ) return null;
            if ( '' === $lightValue && empty($ownership['rootAttributeRemoved']) ) return null;
        }
        if ( ! in_array($defaultTheme, array( 'dark', 'light' ), true) ) $defaultTheme = 'system';
        $generator = new ThemeToggleBlockGenerator();
        $registry = $this->session->generatedBlockRegistry()
            ?? throw new LogicException('Generated block registry has not been prepared for this transform.');
        $blockName = $registry->blockName(ThemeToggleBlockGenerator::LOCAL_NAME);
        $registry->register(ThemeToggleBlockGenerator::class, $generator->definition($registry->namespace()));
        $selectedIndex = array_search(true, array_column($entries, 'selected'), true);
        $groupPresentation = ($this->themeControlPresentation)($element);
        $groupClassName = trim(SourceDom::attr($element, 'class') . ' ' . (string) ($groupPresentation['className'] ?? ''));
        $groupStyle = is_array($groupPresentation['style'] ?? null) ? $groupPresentation['style'] : array();
        $attributes = array(
            'themeModes' => $modes,
            'selectionButtons' => $entries,
            'selectedMode' => false === $selectedIndex ? 'system' : (string) $entries[$selectedIndex]['mode'],
            'groupTag' => strtolower($element->tagName),
            'groupClassName' => implode(' ', array_values(array_unique(array_filter(preg_split('/\s+/', $groupClassName) ?: array())))),
            'groupStyle' => (string) json_encode($groupStyle, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'rootClass' => $darkValue,
            'rootAttribute' => $rootAttribute,
            'darkValue' => $darkValue,
            'lightValue' => $lightValue,
            'rootAttributeRemoved' => ! empty($ownership['rootAttributeRemoved']),
            'defaultTheme' => $defaultTheme,
            'storageKey' => (string) $ownership['storageKey'],
        );
        foreach ( SourceDom::htmlAttributes($element) as $name => $value ) {
            if ( in_array(strtolower($name), array('role', 'id', 'title', 'tabindex', 'dir', 'lang'), true)
                || str_starts_with(strtolower($name), 'aria-')
                || str_starts_with(strtolower($name), 'data-') ) {
                $attributes['groupAttributes'][$name] = $value;
            }
        }
        $markup = $generator->markup($attributes, $blockName);
        return array('blockName' => $blockName, 'attrs' => $attributes, 'innerBlocks' => array(), 'innerHTML' => $markup, 'innerContent' => array($markup));
    }

    /** @param array<int, array<string, mixed>> $entries @param array<int, array<string, mixed>> $runtimeOwners @return array{operator:array<string, mixed>,runtime_ownership:array<string, mixed>}|null */
    private function sourceQualifiedThemeOwnership(DOMElement $element, array $entries, array $runtimeOwners): ?array
    {
        $matches = array();
        foreach ( $this->session->runtimeBehaviorState()->themePreferenceOwnership() as $operator ) {
            if ( 'blocks-engine/php-transformer/theme-preference-ownership/v1' !== ($operator['schema'] ?? null)
                || ! is_string($operator['source_path'] ?? null)
                || $operator['source_path'] !== $this->session->sourcePath()
                || ! is_string($operator['group_selector'] ?? null)
                || ! $this->groupMatchesOwnershipSelector($element, $operator['group_selector'])
                || ! is_string($operator['runtime_script_path'] ?? null)
                || ! is_string($operator['runtime_script_sha256'] ?? null)
                || 1 !== preg_match('/^[a-f0-9]{64}$/', $operator['runtime_script_sha256']) ) {
                continue;
            }
            $scriptOwner = null;
            foreach ( $runtimeOwners as $owner ) {
                if ( $operator['runtime_script_path'] === ($owner['path'] ?? null) && $operator['runtime_script_sha256'] === ($owner['sha256'] ?? null) ) {
                    if ( null !== $scriptOwner ) { $scriptOwner = null; break; }
                    $scriptOwner = $owner['ownership'] ?? null;
                }
            }
            if ( ! is_array($scriptOwner) || ($operator['storage_key'] ?? null) !== ($scriptOwner['storageKey'] ?? null)
                || '(prefers-color-scheme: dark)' !== trim((string) ($operator['system_query'] ?? '')) ) {
                continue;
            }
            $root = is_array($operator['root'] ?? null) ? $operator['root'] : array();
            if ( ! in_array($root['selector'] ?? null, array('html', ':root'), true)
                || ($root['attribute'] ?? null) !== ($scriptOwner['rootAttribute'] ?? null)
                || ! is_string($root['dark_value'] ?? null) || '' === $root['dark_value']
                || ! is_string($root['light_value'] ?? null) ) {
                continue;
            }
            $lightOperation = 'class' === $root['attribute']
                ? ('' === $root['light_value'] ? 'remove-theme-class' : 'set-theme-class')
                : ('' === $root['light_value'] ? 'remove-attribute' : 'set-attribute');
            if ( $lightOperation !== ($root['light_operation'] ?? null)
                || ('' === $root['light_value'] && empty($scriptOwner['rootAttributeRemoved']) && 'class' !== $root['attribute']) ) {
                continue;
            }
            $controls = is_array($operator['controls'] ?? null) ? array_values($operator['controls']) : array();
            if ( 3 !== count($controls) ) continue;
            $controlsMatch = true;
            foreach ( $entries as $index => $entry ) {
                $control = $controls[$index] ?? array();
                if ( ! is_array($control) || ($control['mode'] ?? null) !== ($entry['mode'] ?? null)
                    || ($control['accessible_name'] ?? null) !== ($entry['ariaLabel'] ?? null)
                    || ($control['icon'] ?? null) !== ($entry['iconSemantic'] ?? null) ) {
                    $controlsMatch = false;
                    break;
                }
            }
            if ( ! $controlsMatch || ! $this->observedThemeTransitions($operator['observed_transitions'] ?? null, $root) ) continue;
            $matches[] = array('operator' => $operator, 'runtime_ownership' => $scriptOwner);
        }

        return 1 === count($matches) ? $matches[0] : null;
    }

    private function groupMatchesOwnershipSelector(DOMElement $element, string $selector): bool
    {
        if ( 512 < strlen($selector) || str_contains($selector, ',') ) return false;
        $parsed = CssSelectorMatcher::parse($selector);
        if ( ! ($parsed['supported'] ?? false) || null !== ($parsed['pseudo_state_suffix_span'] ?? null) ) return false;
        $document = $element->ownerDocument;
        if ( ! $document instanceof \DOMDocument ) return false;
        $count = 0;
        foreach ( $document->getElementsByTagName('*') as $candidate ) {
            if ( ! $candidate instanceof DOMElement ) continue;
            $matched = CssSelectorMatcher::matches($candidate, $parsed);
            if ( ($matched['supported'] ?? false) && ($matched['matches'] ?? false) ) ++$count;
        }

        $actual = CssSelectorMatcher::matches($element, $parsed);
        return 1 === $count && ($actual['supported'] ?? false) && ($actual['matches'] ?? false);
    }

    private function observedThemeTransitions(mixed $transitions, array $root): bool
    {
        if ( ! is_array($transitions) || 4 !== count($transitions) ) return false;
        $observed = array();
        foreach ( $transitions as $transition ) {
            if ( ! is_array($transition) || ! is_string($transition['mode'] ?? null)
                || ! is_string($transition['storage_value'] ?? null) || ! is_string($transition['resolved'] ?? null) ) return false;
            if ( ! is_array($transition['root_state'] ?? null)
                || ($transition['root_state']['attribute'] ?? null) !== ($root['attribute'] ?? null)
                || ! array_key_exists('value', $transition['root_state']) ) return false;
            $mode = $transition['mode'];
            if ( ! in_array($mode, array('light', 'dark', 'system'), true) || $mode !== $transition['storage_value'] ) return false;
            if ( 'system' === $mode ) {
                $scheme = $transition['os_scheme'] ?? null;
                if ( ! in_array($scheme, array('light', 'dark'), true) || $transition['resolved'] !== $scheme ) return false;
                $key = $mode . ':' . $scheme;
            } else {
                if ( array_key_exists('os_scheme', $transition) || $transition['resolved'] !== $mode ) return false;
                $key = $mode;
            }
            $expectedRootValue = 'dark' === $transition['resolved']
                ? ($root['dark_value'] ?? null)
                : (in_array($root['light_operation'] ?? null, array('remove-theme-class', 'remove-attribute'), true) ? null : ($root['light_value'] ?? null));
            if ($transition['root_state']['value'] !== $expectedRootValue) return false;
            if ( isset($observed[$key]) ) return false;
            $observed[$key] = true;
        }

        return isset($observed['light'], $observed['dark'], $observed['system:light'], $observed['system:dark']);
    }

    /** @return array<string, mixed>|null */
    public function block(DOMElement $element): ?array
    {
        $identity = strtolower(trim(SourceDom::attr($element, 'class') . ' ' . SourceDom::attr($element, 'data-testid')));
        $combinedCss = $this->session->authorStyleAnalysis()->combinedCss();
        if ( 1 !== preg_match('/(?:^|[^a-z0-9])theme[-_ ]?toggle(?:[^a-z0-9]|$)/', $identity)
            || 'toggle theme' !== strtolower(trim(SourceDom::attr($element, 'aria-label')))
            || ! preg_match('/\.dark(?![-_a-z0-9])/i', $combinedCss)
            || ! preg_match('/:root\s*:\s*not\(\s*\.dark\s*\)/i', $combinedCss)
        ) {
            return null;
        }

        $svg = null;
        $label = null;
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            if ( 'svg' === strtolower($child->tagName) && null === $svg ) {
                $svg = $child;
            } elseif ( 'span' === strtolower($child->tagName) && null === $label && '' !== trim($child->textContent ?? '') ) {
                $label = $child;
            }
        }
        if ( ! $svg instanceof DOMElement || ! $label instanceof DOMElement || ! SourceDom::svgHasDrawableContent($svg) ) {
            return null;
        }
        if ( 1 !== preg_match('/(?:^|[^a-z0-9])theme[-_ ]?toggle[-_ ]?label(?:[^a-z0-9]|$)/', strtolower(trim(SourceDom::attr($label, 'class')))) ) {
            return null;
        }

        $icon = $this->svgMaterializer->restoreSvgCasing(($this->sanitizeInlineSvgMarkup)($svg));
        if ( '' === $icon || ! SourceDom::isSafeSvgContent($icon) ) {
            return null;
        }
        $capturedRootTheme = ($this->capturedRootTheme)();
        if ( ! in_array($capturedRootTheme, array( 'dark', 'light' ), true) ) {
            return null;
        }
        $labelText = trim($label->textContent ?? '');
        if ( 0 !== $label->childElementCount || 1 !== preg_match('/^(Light|Dark)\s+Mode$/i', $labelText, $labelMatch) ) {
            return null;
        }
        $sourceOffersLight = 'light' === strtolower($labelMatch[1]);
        $lightLabel = $sourceOffersLight ? $labelText : 'Light' . substr($labelText, strlen($labelMatch[1]));
        $darkLabel = $sourceOffersLight ? 'Dark' . substr($labelText, strlen($labelMatch[1])) : $labelText;
        $iconIdentity = strtolower(SourceDom::attr($svg, 'class') . ' ' . SourceDom::attr($svg, 'data-lucide'));
        $isSun = 1 === preg_match('/(?:^|[^a-z0-9])(?:lucide[-_ ])?sun(?:[^a-z0-9]|$)/', $iconIdentity);
        $isMoon = 1 === preg_match('/(?:^|[^a-z0-9])(?:lucide[-_ ])?moon(?:[^a-z0-9]|$)/', $iconIdentity);
        if ( ! $isSun && ! $isMoon ) {
            return null;
        }
        $sunIcon = '<svg class="lucide lucide-sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"></path></svg>';
        $moonIcon = '<svg class="lucide lucide-moon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.985 12.486a9 9 0 1 1-9.473-9.472c.405-.022.617.46.402.803a6 6 0 0 0 8.268 8.268c.344-.215.825-.004.803.401"></path></svg>';
        $generator = new ThemeToggleBlockGenerator();
        $registry = $this->session->generatedBlockRegistry()
            ?? throw new LogicException('Generated block registry has not been prepared for this transform.');
        $blockName = $registry->blockName(ThemeToggleBlockGenerator::LOCAL_NAME);
        $registry->register(ThemeToggleBlockGenerator::class, $generator->definition($registry->namespace()));
        $attributes = array(
            'ariaLabel' => trim(SourceDom::attr($element, 'aria-label')),
            'className' => trim(SourceDom::attr($element, 'class')),
            'lightIcon' => $isSun ? $icon : $sunIcon,
            'darkIcon' => $isMoon ? $icon : $moonIcon,
            'lightLabel' => $lightLabel,
            'darkLabel' => $darkLabel,
            'labelClassName' => trim(SourceDom::attr($label, 'class')),
            'labelMarker' => trim(SourceDom::attr($label, 'data-blocks-engine-richtext-marker')),
            'rootClass' => 'dark',
            'defaultTheme' => $capturedRootTheme,
            'storageKey' => 'theme',
        );
        $markup = $generator->markup($attributes, $blockName);
        return array('blockName' => $blockName, 'attrs' => $attributes, 'innerBlocks' => array(), 'innerHTML' => $markup, 'innerContent' => array($markup));
    }
}
