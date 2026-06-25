<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class ButtonsPattern
{
    private const BLOCK_LEVEL_LABEL_TAGS = 'address|article|aside|blockquote|div|dl|fieldset|figcaption|figure|footer|form|h[1-6]|header|hr|main|nav|ol|p|pre|section|table|ul';

    /**
     * @param callable(DOMElement): array<string, mixed>|null $fileBlockFromAnchor
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $innerHtml
     * @param callable(DOMElement, string): string $attr
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>|null
     */
    public function matchAnchor(DOMElement $anchor, callable $fileBlockFromAnchor, callable $presentationAttributes, callable $innerHtml, callable $attr, callable $createBlock): ?array
    {
        $fileBlock = $fileBlockFromAnchor($anchor);
        if ( null !== $fileBlock ) {
            return $fileBlock;
        }

        if ( ! $this->hasButtonSignal($anchor) ) {
            return null;
        }

        return $createBlock('core/buttons', array(), array( $this->buttonBlockFromAnchor($anchor, $presentationAttributes, $innerHtml, $attr, $createBlock) ), $anchor);
    }

    /**
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $innerHtml
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>
     */
    public function matchButton(DOMElement $button, callable $presentationAttributes, callable $innerHtml, callable $createBlock): array
    {
        return $createBlock('core/buttons', array(), array(
            $createBlock('core/button', array_merge(
                $this->buttonPresentationAttributes($button, $presentationAttributes),
                $this->buttonRuntimeAttributes($button),
                array(
                    'tagName' => 'button',
                    'text'    => $this->buttonText($innerHtml($button)),
                )
            ), array(), $button),
        ), $button);
    }

    /**
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $innerHtml
     * @param callable(DOMElement, string): string $attr
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>|null
     */
    public function matchContainer(DOMElement $element, callable $presentationAttributes, callable $innerHtml, callable $attr, callable $createBlock): ?array
    {
		$containerHasButtonSignal = $this->hasContainerButtonSignal($element) || $this->isDirectAnchorRow($element);
        $buttons = array();
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && 'a' === strtolower($child->tagName) && '' !== trim($child->textContent ?? '') && ( $containerHasButtonSignal || $this->hasButtonSignal($child) ) ) {
                $buttons[] = $this->buttonBlockFromAnchor($child, $presentationAttributes, $innerHtml, $attr, $createBlock);
            }
        }

        if ( count($buttons) <= 1 ) {
            return null;
        }

        return $createBlock('core/buttons', $presentationAttributes($element), $buttons, $element);
    }

    /**
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $innerHtml
     * @param callable(DOMElement, string): string $attr
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>
     */
    private function buttonBlockFromAnchor(DOMElement $anchor, callable $presentationAttributes, callable $innerHtml, callable $attr, callable $createBlock): array
    {
        return $createBlock('core/button', array_filter(array_merge($this->buttonPresentationAttributes($anchor, $presentationAttributes), array(
            'text' => $this->buttonText($innerHtml($anchor)),
            'url'  => $attr($anchor, 'href'),
        )), static fn ($value): bool => is_array($value) ? array() !== $value : '' !== $value), array(), $anchor);
    }

    private function buttonText(string $html): string
    {
        $html = preg_replace('/<([a-z][a-z0-9]*)\b[^>]*\baria-hidden\s*=\s*(["\'])?true\2[^>]*>\s*<\/\1>/i', '', $html) ?? $html;
        $html = preg_replace('/<\/?(?:' . self::BLOCK_LEVEL_LABEL_TAGS . ')\b[^>]*>/i', '', $html) ?? $html;
        return trim($html);
    }

    /**
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @return array<string, mixed>
     */
    private function buttonPresentationAttributes(DOMElement $element, callable $presentationAttributes): array
    {
        $attrs = $presentationAttributes($element);
        if ( $this->hasOutlineSignal($element, (string) ($attrs['style'] ?? '')) ) {
            $attrs['className'] = trim((string) ($attrs['className'] ?? '') . ' is-style-outline');
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

		return $this->hasAnyToken($anchor, array( 'button', 'btn', 'cta', 'action' ))
			|| $this->hasPhrase($anchor, array( 'call-to-action', 'primary-action', 'secondary-action' ))
			|| $this->hasActionText($anchor);
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

			if ( ! in_array(strtolower($child->tagName), array( 'abbr', 'b', 'br', 'cite', 'code', 'em', 'i', 'mark', 'small', 'span', 'strong', 'sub', 'sup', 'time' ), true) ) {
				return false;
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
