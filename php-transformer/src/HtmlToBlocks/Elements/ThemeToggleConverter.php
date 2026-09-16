<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\ThemeToggleBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgMaterializer;
use Closure;
use DOMElement;
use LogicException;

/** Promotes a corroborated theme toggle button onto the companion block. */
final class ThemeToggleConverter implements ElementConverter
{
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
            return ConversionOutcome::unhandled();
        }

        $themeToggle = $this->block($element);

        return null === $themeToggle
            ? ConversionOutcome::unhandled()
            : ConversionOutcome::handled($themeToggle);
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
        $registry->register(ThemeToggleBlockGenerator::class, $generator->definition());
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
        $markup = $generator->markup($attributes);
        return array('blockName' => ThemeToggleBlockGenerator::NAME, 'attrs' => $attributes, 'innerBlocks' => array(), 'innerHTML' => $markup, 'innerContent' => array($markup));
    }
}
