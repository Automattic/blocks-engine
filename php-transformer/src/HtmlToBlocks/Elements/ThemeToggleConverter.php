<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeScriptEvidenceAnalyzer;
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
        if ( ! preg_match('/:root(?:\s|,|:|\.|\{|\[)[^{}]*\{[^}]*(?:color-scheme|--(?:background|foreground)|background(?:-color)?\s*:|color\s*:)/i', $css) ) {
            return null;
        }
        $evidenceAnalyzer = new RuntimeScriptEvidenceAnalyzer();
        $ownershipByContract = array();
        $runtimeBytes = 0;
        $sourceLabels = array_map(static fn (DOMElement $button): string => trim(SourceDom::attr($button, 'aria-label')), $buttons);
        foreach ( $this->session->runtimeBehaviorState()->runtimeProjectionScriptAssets() as $asset ) {
            $script = (string) ($asset['content'] ?? '');
            $scriptBytes = strlen($script);
            if ( $scriptBytes > self::MAX_THEME_RUNTIME_EVIDENCE_BYTES || $runtimeBytes + $scriptBytes > self::MAX_THEME_RUNTIME_EVIDENCE_BYTES ) {
                continue;
            }
            $runtimeBytes += $scriptBytes;
            $controlLabelsPresent = true;
            foreach ( $sourceLabels as $label ) {
                if ( '' === $label || ! str_contains($script, $label) ) { $controlLabelsPresent = false; break; }
            }
            if ( ! $controlLabelsPresent ) continue;
            $ownership = $evidenceAnalyzer->themePreferenceOwnership($script);
            if ( null !== $ownership ) {
                $ownershipByContract[json_encode($ownership, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)] = $ownership;
            }
        }
        if ( 1 !== count($ownershipByContract) ) {
            return null;
        }
        $ownership = reset($ownershipByContract);
        $rootAttribute = $ownership['rootAttribute'] ?? null;
        if ( ! is_string($rootAttribute) || ( 'class' !== $rootAttribute && ! preg_match('/^[a-zA-Z_:][a-zA-Z0-9:._-]*$/', $rootAttribute) ) ) return null;
        $darkValue = null;
        $lightValue = '';
        if ('class' === $rootAttribute) {
            if (preg_match('/(?:^|})\.([a-z][a-z0-9_-]*)(?:\s[^{}]*)?\{[^}]*(?:color-scheme|--(?:background|foreground)|background(?:-color)?\s*:|color\s*:)/i', $css, $darkClass)) {
                $darkValue = $darkClass[1];
            }
            foreach ( $ownership['rootClassValues'] ?? array() as $classValue ) {
                if ( is_string($classValue) && $classValue !== $darkValue ) { $lightValue = $classValue; break; }
            }
            if ( '' === $lightValue && 'light' === $defaultTheme ) $lightValue = 'light';
        } else {
            $attributeValues = is_array($ownership['rootAttributeValues'] ?? null) ? $ownership['rootAttributeValues'] : array();
            if ( preg_match_all('/:root[^{}]*\[' . preg_quote($rootAttribute, '/') . '\s*=\s*(["\']?)([A-Za-z0-9_-]+)\1\s*\][^{}]*\{([^}]*)\}/i', $css, $attributeRules, PREG_SET_ORDER) ) {
                foreach ( $attributeRules as $rule ) {
                    $value = $rule[2];
                    $body = $rule[3];
                    if ( null === $darkValue && ('dark' === strtolower($value) || preg_match('/color-scheme\s*:\s*dark\b/i', $body)) ) $darkValue = $value;
                    if ( '' === $lightValue && ('light' === strtolower($value) || preg_match('/color-scheme\s*:\s*light\b/i', $body)) ) $lightValue = $value;
                }
            }
            if ( null === $darkValue || ( '' === $lightValue && empty($ownership['rootAttributeRemoved']) ) ) return null;
        }
        if ( ! is_string($darkValue) || '' === $darkValue ) return null;
        $runtimeClassValues = is_array($ownership['rootClassValues'] ?? null) ? $ownership['rootClassValues'] : array();
        if ( 'class' === $rootAttribute && array() !== $runtimeClassValues && ! in_array($darkValue, $runtimeClassValues, true) ) return null;
        if ( ! in_array($defaultTheme, array( 'dark', 'light' ), true) ) {
            // The source boot script chooses the OS scheme when no explicit class was captured.
            $defaultTheme = 'system';
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
