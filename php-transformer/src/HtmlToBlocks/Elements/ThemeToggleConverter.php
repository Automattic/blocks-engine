<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
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
     */
    public function __construct(
        private readonly SvgMaterializer $svgMaterializer,
        private readonly HtmlTransformerSession $session,
        private readonly Closure $sanitizeInlineSvgMarkup,
        private readonly Closure $capturedRootTheme
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
        if ( ! preg_match('/\.dark(?:\s|[,:.{])[^{}]*\{[^}]*(?:color-scheme|--(?:background|foreground)|background(?:-color)?\s*:|color\s*:)/i', $css)
            || ! preg_match('/:root(?:\s|[,:.{])[^{}]*\{[^}]*(?:color-scheme|--(?:background|foreground)|background(?:-color)?\s*:|color\s*:)/i', $css) ) {
            return null;
        }
        $runtimeEvidence = '';
        $runtimeBytes = 0;
        foreach ( $this->session->runtimeBehaviorState()->runtimeProjectionScriptAssets() as $asset ) {
            $script = (string) ($asset['content'] ?? '');
            $scriptBytes = strlen($script);
            if ( $scriptBytes > self::MAX_THEME_RUNTIME_EVIDENCE_BYTES || $runtimeBytes + $scriptBytes > self::MAX_THEME_RUNTIME_EVIDENCE_BYTES ) {
                continue;
            }
            $runtimeBytes += $scriptBytes;
            $runtimeEvidence .= "\n" . $script;
        }
        if ( ! preg_match('/localStorage\s*(?:\.\s*|\[\s*[\'"])getItem/i', $runtimeEvidence)
            || ! preg_match('/localStorage\s*(?:\.\s*|\[\s*[\'"])setItem/i', $runtimeEvidence)
            || ! preg_match('/[\'"]theme[\'"]/', $runtimeEvidence)
            || ! preg_match('/prefers-color-scheme/i', $runtimeEvidence)
            || ! preg_match('/classList\s*\.\s*(?:add|remove|toggle)|className\s*=/', $runtimeEvidence) ) {
            return null;
        }
        if ( ! in_array($defaultTheme, array( 'dark', 'light' ), true) ) {
            // The source boot script chooses the OS scheme when no explicit class was captured.
            $defaultTheme = 'system';
        }

        $modes = array( 'light', 'system', 'dark' );
        $modeByLabel = array('light theme' => 'light', 'system theme' => 'system', 'dark theme' => 'dark');
        $entries = array();
        foreach ( $buttons as $button ) {
            $label = trim(SourceDom::attr($button, 'aria-label'));
            $mode = $modeByLabel[strtolower($label)] ?? '';
            if ( '' === $mode || in_array($mode, array_column($entries, 'mode'), true) || 1 !== SourceDom::childElementCount($button) ) {
                return null;
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
            $entries[] = array(
                'mode' => $mode,
                'ariaLabel' => $label,
                'className' => trim(SourceDom::attr($button, 'class')),
                'icon' => $icon,
                'selected' => 'true' === strtolower(SourceDom::attr($button, 'aria-pressed')),
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
        $groupStyle = array();
        foreach ( CssValueSplitter::splitTopLevel(SourceDom::attr($element, 'style'), array(';')) as $declaration ) {
            $parts = explode(':', $declaration, 2);
            if ( 2 !== count($parts) || ! preg_match('/^(?:--[a-z0-9_-]+|-?[a-z][a-z0-9-]*)$/i', trim($parts[0])) ) continue;
            $property = trim($parts[0]);
            if ( ! str_starts_with($property, '--') ) $property = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $property))));
            $groupStyle[$property] = trim($parts[1]);
        }
        $attributes = array(
            'themeModes' => $modes,
            'selectionButtons' => $entries,
            'selectedMode' => false === $selectedIndex ? 'system' : (string) $entries[$selectedIndex]['mode'],
            'groupTag' => strtolower($element->tagName),
            'groupClassName' => trim(SourceDom::attr($element, 'class')),
            'groupStyle' => (string) json_encode($groupStyle, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'rootClass' => 'dark',
            'defaultTheme' => $defaultTheme,
            'storageKey' => 'theme',
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
