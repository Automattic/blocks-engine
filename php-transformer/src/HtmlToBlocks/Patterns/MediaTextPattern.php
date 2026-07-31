<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

/**
 * Recognizes strict two-pane media/text containers and emits core/media-text.
 *
 * Gate decision tree:
 *
 * exactly two element children? -------- no --> null
 * |
 * +-- exactly one pure img/video side? -- no --> null
 * |
 * +-- strict media/layout gates pass? ---- no --> null
 * |
 * +-- convert text child once
 * |
 * +-- text-bearing block? -------------- no --> null
 * |
 * `-- core/media-text
 */
final class MediaTextPattern
{
    use PatternDomHelpersTrait;
    use PatternGateHelpersTrait;

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @param callable(DOMElement, array<int, array<string, mixed>>&, bool): array<int, array<string, mixed>> $convertChildren
     * @param callable(DOMElement, array<int, array<string, mixed>>&, bool): (array<string, mixed>|null) $convertElement
     * @param callable(DOMElement, array<int, string>): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $mergedPresentationStyle
     * @param callable(DOMElement): array<string, string> $htmlAttributes
     * @param callable(string): string $resolveAssetUrl
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>|null
     */
    public function match(
        DOMElement $element,
        array &$fallbacks,
        callable $convertChildren,
        callable $convertElement,
        callable $presentationAttributes,
        callable $mergedPresentationStyle,
        callable $htmlAttributes,
        callable $resolveAssetUrl,
        callable $createBlock
    ): ?array {
        $elementChildren = $this->strictElementChildren($element);
        if ( null === $elementChildren || 2 !== count($elementChildren) ) {
            return null;
        }

        $mediaCandidates = array();
        foreach ( $elementChildren as $index => $child ) {
            $resolution = $this->pureMediaResolution($child);
            if ( null !== $resolution ) {
                $mediaCandidates[ $index ] = $resolution;
            }
        }

        if ( 1 !== count($mediaCandidates) ) {
            return null;
        }

        $mediaIndex = (int) array_key_first($mediaCandidates);
        $textIndex  = 0 === $mediaIndex ? 1 : 0;
        $resolution = $mediaCandidates[ $mediaIndex ];
        if ( $this->containsMediaElement($elementChildren[ $textIndex ]) ) {
            return null;
        }

        $mediaType = strtolower($resolution['media']->tagName);
        if ( 'video' === $mediaType && $resolution['anchor'] instanceof DOMElement ) {
            return null;
        }

        try {
            $containerStyle = $mergedPresentationStyle($element);
        } catch ( \Throwable ) {
            return null;
        }

        $displayType = $this->containerDisplayType($containerStyle);
        $flexDirection = strtolower($this->normalizedCssValue((string) ($this->styleDeclarations($containerStyle)['flex-direction'] ?? '')));
        if (
            ( 'flex' === $displayType && in_array($flexDirection, array( 'column', 'column-reverse', 'row-reverse' ), true) )
            || $this->styleValueEquals($containerStyle, 'direction', 'rtl')
        ) {
            return null;
        }

        $childStyles = array();
        try {
            foreach ( $elementChildren as $index => $child ) {
                $childStyles[ $index ] = $mergedPresentationStyle($child);
                if ( array_key_exists('order', $this->styleDeclarations($childStyles[ $index ])) ) {
                    return null;
                }
            }
        } catch ( \Throwable ) {
            return null;
        }

        try {
            $mediaAttributes = $htmlAttributes($resolution['media']);
            $sourceUrl       = trim((string) ($mediaAttributes['src'] ?? ''));
            if ( '' === $sourceUrl ) {
                return null;
            }
            $mediaUrl = trim($resolveAssetUrl($sourceUrl));
            if ( '' === $this->safeMediaUrl($mediaUrl) ) {
                return null;
            }
        } catch ( \Throwable ) {
            return null;
        }

        $localFallbacks = array();
        try {
            $textBlock = $convertElement($elementChildren[ $textIndex ], $localFallbacks, true);
        } catch ( \Throwable ) {
            return null;
        }

        $innerBlocks = null === $textBlock ? array() : array( $textBlock );
        if (
            'core/group' === ($textBlock['blockName'] ?? null)
            && array() === ($textBlock['attrs'] ?? array())
            && is_array($textBlock['innerBlocks'] ?? null)
        ) {
            $innerBlocks = $textBlock['innerBlocks'];
        }

        if ( array() === $innerBlocks || ! $this->containsTextBearingBlock($innerBlocks) ) {
            return null;
        }

        try {
            $attrs = $presentationAttributes(
                $element,
                array( 'display', 'grid-template-columns', 'align-items', 'gap' )
            );
        } catch ( \Throwable ) {
            return null;
        }
        unset($attrs['layout']);

        $attrs['mediaType'] = 'img' === $mediaType ? 'image' : 'video';
        $attrs['mediaUrl']  = $mediaUrl;

        if ( 'img' === $mediaType && '' !== (string) ($mediaAttributes['alt'] ?? '') ) {
            $attrs['mediaAlt'] = (string) $mediaAttributes['alt'];
        }
        if ( 1 === $mediaIndex ) {
            $attrs['mediaPosition'] = 'right';
        }

        $mediaWidth = 'grid' === $displayType
            ? $this->mediaWidthFromContainerStyle($containerStyle, $mediaIndex)
            : null;
        if ( null === $mediaWidth && ( 'grid' !== $displayType || ! $this->hasGridTemplateColumns($containerStyle) ) ) {
            $mediaWidth = $this->mediaWidthFromMediaStyle($childStyles[ $mediaIndex ]);
        }
        if ( null !== $mediaWidth && 15 <= $mediaWidth && 85 >= $mediaWidth && 50 !== $mediaWidth ) {
            $attrs['mediaWidth'] = $mediaWidth;
        }

        $verticalAlignment = in_array($displayType, array( 'flex', 'grid' ), true)
            ? $this->verticalAlignmentFromStyle($containerStyle)
            : null;
        if ( null !== $verticalAlignment ) {
            $attrs['verticalAlignment'] = $verticalAlignment;
        }

        if ( $resolution['anchor'] instanceof DOMElement ) {
            try {
                $anchorAttributes = $htmlAttributes($resolution['anchor']);
            } catch ( \Throwable ) {
                return null;
            }

            $href = $this->safeLinkUrl((string) ($anchorAttributes['href'] ?? ''));
            if ( '' !== $href ) {
                $attrs['href'] = $href;
            }

            foreach ( array(
                'target' => 'linkTarget',
                'rel'    => 'rel',
                'class'  => 'linkClass',
            ) as $sourceName => $attributeName ) {
                $value = trim((string) ($anchorAttributes[ $sourceName ] ?? ''));
                if ( '' !== $value ) {
                    $attrs[ $attributeName ] = $value;
                }
            }
        }

        try {
            $block = $createBlock('core/media-text', $attrs, $innerBlocks, $element);
        } catch ( \Throwable ) {
            return null;
        }

        array_push($fallbacks, ...$localFallbacks);

        return $block;
    }

    /**
     * @return array<int, DOMElement>|null
     */
    private function strictElementChildren(DOMElement $element): ?array
    {
        $children = array();
        foreach ( $element->childNodes as $child ) {
            if ( XML_COMMENT_NODE === $child->nodeType ) {
                continue;
            }
            if ( XML_TEXT_NODE === $child->nodeType ) {
                if ( '' !== trim($child->textContent ?? '') ) {
                    return null;
                }
                continue;
            }
            if ( ! $child instanceof DOMElement ) {
                return null;
            }
            $children[] = $child;
        }

        return $children;
    }

    private function safeLinkUrl(string $url): string
    {
        return $this->safeUrlWithSchemes($url, array( 'http', 'https', 'mailto', 'tel' ), false);
    }

    private function safeMediaUrl(string $url): string
    {
        return $this->safeUrlWithSchemes($url, array( 'http', 'https' ), true);
    }

    /**
     * @param array<int, string> $allowedSchemes
     */
    private function safeUrlWithSchemes(string $url, array $allowedSchemes, bool $allowImageData): string
    {
        $url = trim($url);
        if ( '' === $url || preg_match('/[\x00-\x1f\x7f]/', $url) ) {
            return '';
        }

        if ( str_starts_with($url, '//') ) {
            return $url;
        }

        if ( ! preg_match('/^([a-z][a-z0-9+.-]*)\s*:/i', $url, $matches) ) {
            return $url;
        }

        $scheme = strtolower($matches[1]);
        if ( in_array($scheme, $allowedSchemes, true) && preg_match('/^' . preg_quote($scheme, '/') . ':/i', $url) ) {
            return $url;
        }

        if ( $allowImageData && 'data' === $scheme && preg_match('/^data:image\/[a-z0-9.+-]+(?:[;,])/i', $url) ) {
            return $url;
        }

        return '';
    }

    private function containsMediaElement(DOMElement $element): bool
    {
        if ( in_array(strtolower($element->tagName), array( 'img', 'video' ), true) ) {
            return true;
        }

        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $this->containsMediaElement($child) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{media: DOMElement, anchor: DOMElement|null}|null
     */
    private function pureMediaResolution(DOMElement $element, ?DOMElement $anchor = null): ?array
    {
        $tagName = strtolower($element->tagName);
        if ( in_array($tagName, array( 'img', 'video' ), true) ) {
            if ( ! $this->hasOnlyIgnorableChildren($element) ) {
                return null;
            }

            return array(
                'media'  => $element,
                'anchor' => $anchor,
            );
        }

        if ( ! in_array($tagName, array( 'figure', 'div', 'a', 'picture' ), true) ) {
            return null;
        }
        if ( 'a' === $tagName ) {
            if ( $anchor instanceof DOMElement ) {
                return null;
            }
            $anchor = $element;
        }

        if ( 'picture' === $tagName ) {
            $image = $this->purePictureImage($element);
            return $image instanceof DOMElement
                ? array( 'media' => $image, 'anchor' => $anchor )
                : null;
        }

        $child = $this->strictSingleMediaChild($element);
        if ( ! $child instanceof DOMElement ) {
            return null;
        }

        return $this->pureMediaResolution($child, $anchor);
    }

    private function purePictureImage(DOMElement $picture): ?DOMElement
    {
        $image = null;
        if ( ! $this->collectPurePictureImage($picture, $image) ) {
            return null;
        }

        return $image;
    }

    private function collectPurePictureImage(DOMElement $element, ?DOMElement &$image): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( XML_COMMENT_NODE === $child->nodeType ) {
                continue;
            }
            if ( XML_TEXT_NODE === $child->nodeType ) {
                if ( '' !== trim($child->textContent ?? '') ) {
                    return false;
                }
                continue;
            }
            if ( ! $child instanceof DOMElement ) {
                return false;
            }

            $tagName = strtolower($child->tagName);
            if ( 'source' === $tagName ) {
                if ( ! $this->collectPurePictureImage($child, $image) ) {
                    return false;
                }
                continue;
            }
            if ( 'img' !== $tagName || $image instanceof DOMElement || ! $this->hasOnlyIgnorableChildren($child) ) {
                return false;
            }
            $image = $child;
        }

        return true;
    }

    private function hasOnlyIgnorableChildren(DOMElement $element): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( XML_COMMENT_NODE === $child->nodeType ) {
                continue;
            }
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }

            return false;
        }

        return true;
    }

    private function strictSingleMediaChild(DOMElement $element): ?DOMElement
    {
        $candidate = null;
        foreach ( $element->childNodes as $child ) {
            if ( XML_COMMENT_NODE === $child->nodeType ) {
                continue;
            }
            if ( XML_TEXT_NODE === $child->nodeType ) {
                if ( '' !== trim($child->textContent ?? '') ) {
                    return null;
                }
                continue;
            }
            if ( ! $child instanceof DOMElement ) {
                return null;
            }
            if ( $candidate instanceof DOMElement ) {
                return null;
            }
            $candidate = $child;
        }

        return $candidate;
    }

    /**
     * @return array<string, string>
     */
    private function styleDeclarations(string $style): array
    {
        $declarations = array();
        foreach ( \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter::splitTopLevel($style, array( ';' )) as $declaration ) {
            $separator = strpos($declaration, ':');
            if ( false === $separator ) {
                continue;
            }

            $name  = strtolower(trim(substr($declaration, 0, $separator)));
            $value = trim(substr($declaration, $separator + 1));
            if ( '' !== $name && '' !== $value ) {
                $declarations[ $name ] = $value;
            }
        }

        return $declarations;
    }

    private function hasGridTemplateColumns(string $style): bool
    {
        $declarations = $this->styleDeclarations($style);
        return '' !== $this->normalizedCssValue((string) ($declarations['grid-template-columns'] ?? ''));
    }

    private function mediaWidthFromContainerStyle(string $style, int $mediaIndex): ?int
    {
        $declarations = $this->styleDeclarations($style);
        $template = $this->normalizedCssValue((string) ($declarations['grid-template-columns'] ?? ''));
        if ( '' === $template ) {
            return null;
        }

        $tracks = \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter::splitTopLevelWhitespace($template);
        if ( 2 !== count($tracks) ) {
            return null;
        }

        $mediaPercentage = $this->percentageValue($tracks[ $mediaIndex ]);
        if ( null !== $mediaPercentage ) {
            return $mediaPercentage;
        }

        $firstFr  = $this->frValue($tracks[0]);
        $secondFr = $this->frValue($tracks[1]);
        if ( null === $firstFr || null === $secondFr || 0.0 >= $firstFr + $secondFr ) {
            return null;
        }

        return (int) round(100 * ( 0 === $mediaIndex ? $firstFr : $secondFr ) / ($firstFr + $secondFr));
    }

    private function mediaWidthFromMediaStyle(string $style): ?int
    {
        $declarations = $this->styleDeclarations($style);
        foreach ( array( 'flex-basis', 'width' ) as $property ) {
            $value = $this->percentageValue($this->normalizedCssValue((string) ($declarations[ $property ] ?? '')));
            if ( null !== $value ) {
                return $value;
            }
        }

        return null;
    }

    private function containerDisplayType(string $style): ?string
    {
        $display = strtolower($this->normalizedCssValue((string) ($this->styleDeclarations($style)['display'] ?? '')));
        return in_array($display, array( 'flex', 'grid' ), true) ? $display : null;
    }

    private function styleValueEquals(string $style, string $property, string $expected): bool
    {
        $value = strtolower($this->normalizedCssValue((string) ($this->styleDeclarations($style)[ $property ] ?? '')));
        return $expected === $value;
    }

    private function verticalAlignmentFromStyle(string $style): ?string
    {
        $declarations = $this->styleDeclarations($style);
        $alignItems = strtolower($this->normalizedCssValue((string) ($declarations['align-items'] ?? '')));

        return array(
            'flex-start' => 'top',
            'start'      => 'top',
            'center'     => 'center',
            'flex-end'   => 'bottom',
            'end'        => 'bottom',
        )[ $alignItems ] ?? null;
    }

    private function normalizedCssValue(string $value): string
    {
        return trim(preg_replace('/\s*!\s*important\s*$/i', '', $value) ?? $value);
    }

    private function percentageValue(string $value): ?int
    {
        if ( ! preg_match('/^(?:\d+(?:\.\d*)?|\.\d+)%$/', trim($value), $matches) ) {
            return null;
        }

        $percentage = (float) rtrim($matches[0], '%');
        if ( 0 > $percentage || 100 < $percentage ) {
            return null;
        }

        return (int) round($percentage);
    }

    private function frValue(string $value): ?float
    {
        if ( ! preg_match('/^(?:\d+(?:\.\d*)?|\.\d+)fr$/i', trim($value), $matches) ) {
            return null;
        }

        return (float) substr($matches[0], 0, -2);
    }
}
