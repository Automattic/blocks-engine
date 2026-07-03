<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class ButtonsPattern
{
    private const BLOCK_LEVEL_LABEL_TAGS = 'address|article|aside|blockquote|div|dl|fieldset|figcaption|figure|footer|form|h[1-6]|header|hr|main|nav|ol|p|pre|section|table|ul';

    private readonly ButtonStyleResolver $styleResolver;

    public function __construct()
    {
        $this->styleResolver = new ButtonStyleResolver();
    }

    /**
     * @param callable(DOMElement): array<string, mixed>|null $fileBlockFromAnchor
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $resolvedStyle
     * @param callable(DOMElement): string $innerHtml
     * @param callable(DOMElement, string): string $attr
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>|null
     */
    public function matchAnchor(DOMElement $anchor, callable $fileBlockFromAnchor, callable $presentationAttributes, callable $resolvedStyle, callable $innerHtml, callable $attr, callable $createBlock): ?array
    {
        $fileBlock = $fileBlockFromAnchor($anchor);
        if ( null !== $fileBlock ) {
            return $fileBlock;
        }

        if ( ! $this->hasButtonSignal($anchor) ) {
            return null;
        }

        return $createBlock('core/buttons', array(), array( $this->buttonBlockFromAnchor($anchor, $presentationAttributes, $resolvedStyle, $innerHtml, $attr, $createBlock) ), $anchor);
    }

    /**
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $resolvedStyle
     * @param callable(DOMElement): string $innerHtml
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>
     */
    public function matchButton(DOMElement $button, callable $presentationAttributes, callable $resolvedStyle, callable $innerHtml, callable $createBlock): array
    {
        return $createBlock('core/buttons', array(), array(
            $createBlock('core/button', array_merge(
                $this->buttonPresentationAttributes($button, $presentationAttributes, $resolvedStyle),
                $this->buttonRuntimeAttributes($button),
                array(
                    'tagName' => 'button',
                    'text'    => $this->buttonText($button, $innerHtml($button)),
                )
            ), array(), $button),
        ), $button);
    }

    /**
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $resolvedStyle
     * @param callable(DOMElement): string $innerHtml
     * @param callable(DOMElement, string): string $attr
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>|null
     */
    public function matchContainer(DOMElement $element, callable $presentationAttributes, callable $resolvedStyle, callable $innerHtml, callable $attr, callable $createBlock): ?array
    {
		$containerHasButtonSignal = $this->hasContainerButtonSignal($element) || $this->isDirectAnchorRow($element);
        $buttons = array();
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && 'a' === strtolower($child->tagName) && '' !== trim($child->textContent ?? '') && ( $containerHasButtonSignal || $this->hasButtonSignal($child) ) ) {
                $buttons[] = $this->buttonBlockFromAnchor($child, $presentationAttributes, $resolvedStyle, $innerHtml, $attr, $createBlock);
            }
        }

        if ( count($buttons) <= 1 ) {
            return null;
        }

        return $createBlock('core/buttons', $presentationAttributes($element), $buttons, $element);
    }

    /**
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $resolvedStyle
     * @param callable(DOMElement): string $innerHtml
     * @param callable(DOMElement, string): string $attr
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>
     */
    private function buttonBlockFromAnchor(DOMElement $anchor, callable $presentationAttributes, callable $resolvedStyle, callable $innerHtml, callable $attr, callable $createBlock): array
    {
        return $createBlock('core/button', array_filter(array_merge($this->buttonPresentationAttributes($anchor, $presentationAttributes, $resolvedStyle), array(
            'text' => $this->buttonText($anchor, $innerHtml($anchor)),
            'url'  => $attr($anchor, 'href'),
        )), static fn ($value): bool => is_array($value) ? array() !== $value : '' !== $value), array(), $anchor);
    }

    private function buttonText(DOMElement $element, string $html): string
    {
        $html = preg_replace('/<svg\b[^>]*>.*?<\/svg>/is', '', $html) ?? $html;
        $html = preg_replace('/<img\b[^>]*\balt\s*=\s*(["\'])(.*?)\1[^>]*>/is', '$2', $html) ?? $html;
        $html = preg_replace('/<img\b[^>]*>/is', '', $html) ?? $html;
        $html = preg_replace('/<([a-z][a-z0-9]*)\b[^>]*\baria-hidden\s*=\s*(["\'])?true\2[^>]*>\s*<\/\1>/i', '', $html) ?? $html;
        $html = preg_replace('/<\/?(?:' . self::BLOCK_LEVEL_LABEL_TAGS . ')\b[^>]*>/i', '', $html) ?? $html;
        $html = $this->unwrapPresentationalSpan(trim($html));
        if ( '' !== trim($this->plainText($html)) ) {
            return $html;
        }

        return $this->accessibleFallbackLabel($element);
    }

    private function plainText(string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private function accessibleFallbackLabel(DOMElement $element): string
    {
        foreach ( array( 'aria-label', 'title' ) as $attribute ) {
            $label = trim($element->hasAttribute($attribute) ? $element->getAttribute($attribute) : '');
            if ( '' !== $label ) {
                return htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        $image = $element->getElementsByTagName('img')->item(0);
        if ( $image instanceof DOMElement ) {
            $alt = trim($image->hasAttribute('alt') ? $image->getAttribute('alt') : '');
            if ( '' !== $alt ) {
                return htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        $title = $element->getElementsByTagName('title')->item(0);
        if ( $title instanceof DOMElement && '' !== trim($title->textContent ?? '') ) {
            return htmlspecialchars(trim($title->textContent ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return '';
    }

    /**
     * Strip a presentational <span> that wraps the entire button label.
     *
     * core/button stores its label as RichText, which only recognizes a fixed
     * set of inline formats (e.g. <strong>, <em>). A bare wrapping <span> is not
     * a format, so RichText drops it on parse: the saved markup no longer matches
     * the re-serialized block ("unexpected or invalid content") and the label is
     * captured as literal `<span>…</span>` markup instead of text. Removing the
     * non-format wrapper keeps the label as valid RichText that round-trips, while
     * genuine inline formats inside the span are preserved.
     */
    private function unwrapPresentationalSpan(string $html): string
    {
        while ( preg_match('/^<span\b[^>]*>(.*)<\/span>$/is', $html, $matches) === 1 && $this->spanWrapsEntireContent($matches[1]) ) {
            $html = trim($matches[1]);
        }

        return $html;
    }

    /**
     * True when the outer span's matching close is the final </span>, i.e. a
     * single span wraps the whole label rather than two sibling spans
     * (`<span>A</span> <span>B</span>`), which must not be unwrapped.
     */
    private function spanWrapsEntireContent(string $inner): bool
    {
        $depth = 0;
        if ( preg_match_all('/<(\/?)span\b[^>]*>/i', $inner, $matches) ) {
            foreach ( $matches[1] as $slash ) {
                $depth += '' === $slash ? 1 : -1;
                if ( $depth < 0 ) {
                    return false;
                }
            }
        }

        return 0 === $depth;
    }

    /**
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $resolvedStyle
     * @return array<string, mixed>
     */
    private function buttonPresentationAttributes(DOMElement $element, callable $presentationAttributes, callable $resolvedStyle): array
    {
        $attrs = $presentationAttributes($element);
        // Buttons resolve styling from the raw merged CSS string, not the canonical
        // block style object, so the (now object-shaped) presentation `style` is
        // dropped and re-derived via ButtonStyleResolver below.
        unset($attrs['style']);
        // core/button does not support a `layout` attribute (a flex/grid layout
        // belongs on the parent core/buttons, not each button). Emitting it here
        // produces an unsupported attribute and invalid block markup, so drop it.
        unset($attrs['layout']);
        $resolvedStyle = (string) $resolvedStyle($element);
        if ( $this->hasOutlineSignal($element, $resolvedStyle) ) {
            $attrs['className'] = trim((string) ($attrs['className'] ?? '') . ' is-style-outline');
        }

        // Translate the resolved source CSS (inline style merged with the matched
        // <style>/linked CSS rules) into native core/button block attributes so the
        // button renders with its source colors/border instead of the theme default.
        // A button with no paintable styling resolves to no native attributes and
        // stays a default button.
        $native = $this->styleResolver->nativeAttributes($resolvedStyle);
        if ( array() !== $native ) {
            $attrs = array_merge($attrs, $native);
        }

        if ( $this->hasOutlineSignal($element, $resolvedStyle) ) {
            $attrs['style']['color']['background'] = 'transparent';
        }

        return $attrs;
    }

    /**
     * @return array<string, string>
     */
    private function buttonRuntimeAttributes(DOMElement $button): array
    {
        $attrs = array();
        foreach ( array( 'type', 'role', 'aria-label', 'aria-controls', 'aria-expanded', 'aria-haspopup' ) as $name ) {
            if ( $button->hasAttribute($name) ) {
                $attrs[$name] = $button->getAttribute($name);
            }
        }

        foreach ( $button->attributes ?? array() as $attribute ) {
            if ( str_starts_with(strtolower($attribute->nodeName), 'data-') ) {
                $attrs[$attribute->nodeName] = $attribute->nodeValue ?? '';
            }
        }

        return $attrs;
    }

    private function hasOutlineSignal(DOMElement $element, string $style): bool
    {
        if ( $this->hasAnyToken($element, array( 'outline', 'ghost', 'hollow', 'bordered' )) ) {
            return true;
        }

        $normalized = strtolower($style);
        if ( ! preg_match('/(?:^|;)\s*border(?:-[a-z-]+)?\s*:\s*[^;]+/', $normalized) ) {
            return false;
        }

        return preg_match('/(?:^|;)\s*background(?:-color)?\s*:\s*(?:transparent|none|rgba\(\s*0\s*,\s*0\s*,\s*0\s*,\s*0\s*\))/i', $normalized) === 1;
    }

    private function hasButtonSignal(DOMElement $anchor): bool
    {
        if ( 'button' === strtolower($anchor->hasAttribute('role') ? $anchor->getAttribute('role') : '') ) {
            return true;
        }

		return $this->hasButtonClassSignal($anchor)
			|| $this->hasAnyToken($anchor, array( 'cta', 'action' ))
			|| $this->hasPhrase($anchor, array( 'call-to-action', 'primary-action', 'secondary-action' ))
			|| $this->hasActionText($anchor)
			|| $this->hasButtonStyleSignal($anchor);
    }

	/**
	 * Detect button-like class/id tokens generically.
	 *
	 * Keys off the generic "btn"/"button" substring rather than any one specific
	 * class string, so framework variants are all recognized: btn, btn-primary,
	 * hero-btn, link-btn, btnPrimary, actionButton, icon-button, roundedbtn, etc.
	 */
	private function hasButtonClassSignal(DOMElement $element): bool
	{
		foreach ( array( 'class', 'id' ) as $attribute ) {
			$value = strtolower($element->hasAttribute($attribute) ? $element->getAttribute($attribute) : '');
			if ( str_contains($value, 'btn') || str_contains($value, 'button') ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Detect button-like inline styling.
	 *
	 * Treats an element as a button when it carries padding plus a button shape
	 * signal (a filled, non-transparent background or a border radius). This lets
	 * styled anchors with no recognizable class still be promoted to buttons,
	 * while plain text links (no padding/fill) stay links.
	 */
	private function hasButtonStyleSignal(DOMElement $element): bool
	{
		$style = strtolower($element->hasAttribute('style') ? $element->getAttribute('style') : '');
		if ( '' === $style || ! preg_match('/(?:^|;)\s*padding(?:-[a-z]+)?\s*:\s*[^;]+/', $style) ) {
			return false;
		}

		if ( preg_match('/(?:^|;)\s*border[a-z-]*radius\s*:\s*[^;]+/', $style) ) {
			return true;
		}

		return preg_match('/(?:^|;)\s*background(?:-color)?\s*:\s*[^;]+/', $style) === 1
			&& preg_match('/(?:^|;)\s*background(?:-color)?\s*:\s*(?:transparent|none|inherit|initial|rgba\(\s*0\s*,\s*0\s*,\s*0\s*,\s*0\s*\))\s*(?:;|$)/', $style) !== 1;
	}

	private function hasContainerButtonSignal(DOMElement $element): bool
	{
		return $this->hasAnyToken($element, array( 'buttons', 'button', 'btns', 'cta', 'actions' )) || $this->hasPhrase($element, array( 'button-group', 'button-row', 'cta-group', 'call-to-action' ));
	}

	private function isDirectAnchorRow(DOMElement $element): bool
	{
		$anchors = 0;
		$buttonSignals = 0;
		foreach ( $element->childNodes as $child ) {
			if ( $child instanceof DOMElement ) {
				if ( 'a' !== strtolower($child->tagName) || '' === trim($child->textContent ?? '') ) {
					return false;
				}
				if ( ! $this->isSimpleAnchor($child) ) {
					return false;
				}
				++$anchors;
				if ( $this->hasButtonSignal($child) ) {
					++$buttonSignals;
				}
				continue;
			}

			if ( '' !== trim($child->textContent ?? '') ) {
				return false;
			}
		}

		return $anchors > 1 && 0 === $buttonSignals;
	}

	private function isSimpleAnchor(DOMElement $anchor): bool
	{
		foreach ( $anchor->childNodes as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$tagName = strtolower($child->tagName);
			if ( ! in_array($tagName, array( 'abbr', 'b', 'br', 'cite', 'code', 'em', 'i', 'mark', 'small', 'span', 'strong', 'sub', 'sup', 'svg', 'time' ), true) ) {
				return false;
			}

			if ( 'svg' === $tagName ) {
				continue;
			}

			if ( ! $this->isSimpleAnchor($child) ) {
				return false;
			}
		}

		return true;
	}

    /**
     * @param array<int, string> $tokens
     */
    private function hasAnyToken(DOMElement $element, array $tokens): bool
    {
        foreach ( array( 'class', 'id' ) as $attribute ) {
            $value = $element->hasAttribute($attribute) ? $element->getAttribute($attribute) : '';
            foreach ( preg_split('/[^a-z0-9]+/', strtolower($value)) ?: array() as $token ) {
                if ( in_array($token, $tokens, true) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $phrases
     */
	private function hasPhrase(DOMElement $element, array $phrases): bool
	{
        foreach ( array( 'class', 'id' ) as $attribute ) {
            $value = strtolower($element->hasAttribute($attribute) ? $element->getAttribute($attribute) : '');
            foreach ( $phrases as $phrase ) {
                if ( str_contains($value, $phrase) ) {
                    return true;
                }
            }
        }

		return false;
	}

	private function hasActionText(DOMElement $element): bool
	{
		$text = strtolower(trim(preg_replace('/\s+/', ' ', $element->textContent ?? '') ?? ''));
		if ( '' === $text ) {
			return false;
		}

		return in_array($text, array(
			'add to cart',
			'buy now',
			'checkout',
			'shop now',
			'get started',
			'sign up',
			'subscribe',
			'donate',
			'register',
			'book now',
		), true);
	}
}
