<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\SrcsetParser;
use Automattic\BlocksEngine\PhpTransformer\Contract\RichTextInlineTags;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\LinkUrlSanitizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;

/**
 * Projects one image-plus-label anchor into linked-content attributes.
 *
 * Recognition stays here so HtmlCompilation only supplies URL resolution and
 * runtime checks. Inline styles round-trip through StyleResolver; a style the
 * parser cannot keep is declined instead of converted without its geometry.
 */
final class LinkedResponsiveContentProjector
{
    /** @var array<int, string> */
    private const LABEL_TAGS = array( 'span', 'strong', 'em', 'b', 'i', 'small', 'mark' );

    /** @var array<int, string> */
    private const RICH_TEXT_TAGS = array( 'b', 'br', 'cite', 'code', 'del', 'em', 'i', 'ins', 'kbd', 'mark', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var' );

    /** @var array<int, string> */
    private const CONTROL_TAGS = array( 'a', 'audio', 'button', 'canvas', 'embed', 'form', 'iframe', 'img', 'input', 'object', 'select', 'svg', 'textarea', 'video' );

    /**
     * @param Closure(DOMElement): string $imageUrl
     * @param Closure(string): string    $resolveUrl
     * @param Closure(string): int       $assetId
     * @param Closure(DOMElement): bool  $isRuntimeTarget
     * @param Closure(DOMElement): bool  $hasEvents
     * @param Closure(DOMElement): bool  $hasInteractive
     * @param Closure(DOMElement): bool  $hasRouteData
     */
    public function __construct(
        private readonly StyleResolver $styles,
        private readonly Closure $imageUrl,
        private readonly Closure $resolveUrl,
        private readonly Closure $assetId,
        private readonly Closure $isRuntimeTarget,
        private readonly Closure $hasEvents,
        private readonly Closure $hasInteractive,
        private readonly Closure $hasRouteData,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function project(DOMElement $anchor): ?array
    {
        if ( ! $this->admits($anchor) ) {
            return null;
        }
        $image = null;
        $label = null;
        $imageFirst = null;
        foreach ( $anchor->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            if ( 'img' === strtolower($child->tagName) ) {
                $image = $child;
                $imageFirst ??= true;
                continue;
            }
            $label = $child;
            $imageFirst ??= false;
        }
        if ( ! $image instanceof DOMElement || ! $label instanceof DOMElement || null === $imageFirst ) {
            return null;
        }

        $href = LinkUrlSanitizer::sanitize(SourceDom::attr($anchor, 'href'));
        $sourceUrl = ($this->imageUrl)($image);
        $src = ($this->resolveUrl)($sourceUrl);
        $labelHtml = $this->labelHtml($label);
        if ( '' === $href || '' === $src || '' === $labelHtml || ! SourceDom::safeFallbackUrl($src, 'src') ) {
            return null;
        }
        $srcset = $this->srcset(SourceDom::attr($image, 'srcset'));
        if ( '' !== trim(SourceDom::attr($image, 'srcset')) && '' === $srcset ) {
            return null;
        }

        $attrs = array(
            'href' => $href,
            'src' => $src,
            'srcset' => $srcset,
            'sizes' => $this->sizes(SourceDom::attr($image, 'sizes')),
            'alt' => SourceDom::attr($image, 'alt'),
            'width' => $this->pixel(SourceDom::attr($image, 'width')),
            'height' => $this->pixel(SourceDom::attr($image, 'height')),
            'loading' => $this->token(SourceDom::attr($image, 'loading'), array( 'lazy', 'eager' )),
            'decoding' => $this->token(SourceDom::attr($image, 'decoding'), array( 'async', 'sync', 'auto' )),
            'className' => trim(SourceDom::attr($anchor, 'class')),
            'anchorStyle' => $this->style($anchor),
            'imageClassName' => trim(SourceDom::attr($image, 'class')),
            'imageStyle' => $this->style($image),
            'label' => $labelHtml,
            'labelClassName' => trim(SourceDom::attr($label, 'class')),
            'labelStyle' => $this->style($label),
            'labelTag' => strtolower($label->tagName),
            'linkTarget' => $this->token(SourceDom::attr($anchor, 'target'), array( '_blank', '_self', '_parent', '_top' )),
            'rel' => $this->rel(SourceDom::attr($anchor, 'rel')),
            'anchorId' => SourceDom::anchorAttributeValue(SourceDom::attr($anchor, 'id')),
            'anchorTitle' => $this->plain(SourceDom::attr($anchor, 'title')),
            'ariaLabel' => $this->plain(SourceDom::attr($anchor, 'aria-label')),
            'ariaLabelledBy' => $this->idRef(SourceDom::attr($anchor, 'aria-labelledby')),
            'ariaDescribedBy' => $this->idRef(SourceDom::attr($anchor, 'aria-describedby')),
            'imageId' => SourceDom::anchorAttributeValue(SourceDom::attr($image, 'id')),
            'imageTitle' => $this->plain(SourceDom::attr($image, 'title')),
            'imageLabelledBy' => $this->idRef(SourceDom::attr($image, 'aria-labelledby')),
            'imageDescribedBy' => $this->idRef(SourceDom::attr($image, 'aria-describedby')),
            'labelId' => SourceDom::anchorAttributeValue(SourceDom::attr($label, 'id')),
            'labelTitle' => $this->plain(SourceDom::attr($label, 'title')),
            'labelLabelledBy' => $this->idRef(SourceDom::attr($label, 'aria-labelledby')),
            'labelDescribedBy' => $this->idRef(SourceDom::attr($label, 'aria-describedby')),
            'contentOrder' => $imageFirst ? 'image-first' : 'label-first',
            'anchorData' => $this->dataAttributes($anchor),
            'imageData' => $this->dataAttributes($image),
            'labelData' => $this->dataAttributes($label),
        );
        if ( in_array(null, array( $attrs['anchorStyle'], $attrs['imageStyle'], $attrs['labelStyle'], $attrs['anchorData'], $attrs['imageData'], $attrs['labelData'] ), true) ) {
            return null;
        }
        $assetId = ($this->assetId)($sourceUrl);
        if ( 0 !== $assetId ) {
            $attrs['mediaId'] = $assetId;
        }
        if ( 'span' === $attrs['labelTag'] ) {
            unset($attrs['labelTag']);
        }
        if ( 'image-first' === $attrs['contentOrder'] ) {
            unset($attrs['contentOrder']);
        }

        return array_filter(
            $attrs,
            static fn (mixed $value): bool => is_int($value) ? 0 !== $value : (is_array($value) ? array() !== $value : '' !== $value)
        );
    }

    private function admits(DOMElement $anchor): bool
    {
        if ( 'a' !== strtolower($anchor->tagName)
            || '' === LinkUrlSanitizer::sanitize(SourceDom::attr($anchor, 'href'))
            || $anchor->hasAttribute('download')
            || '' !== SourceDom::attr($anchor, 'role')
            || ($this->isRuntimeTarget)($anchor)
            || ($this->hasEvents)($anchor)
            || ($this->hasInteractive)($anchor)
            || ($this->hasRouteData)($anchor)
            || ! $this->identityIsPreservable($anchor, array( 'class', 'href', 'id', 'style', 'target', 'rel', 'title', 'aria-label', 'aria-labelledby', 'aria-describedby' ))
        ) {
            return false;
        }

        $image = null;
        $label = null;
        foreach ( $anchor->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                if ( '' !== trim($child->textContent ?? '') ) {
                    return false;
                }
                continue;
            }
            $tagName = strtolower($child->tagName);
            if ( 'img' === $tagName ) {
                if ( $image instanceof DOMElement ) {
                    return false;
                }
                $image = $child;
                continue;
            }
            if ( in_array($tagName, self::LABEL_TAGS, true) ) {
                if ( $label instanceof DOMElement ) {
                    return false;
                }
                $label = $child;
                continue;
            }

            return false;
        }

        return $image instanceof DOMElement
            && $label instanceof DOMElement
            && '' !== trim($label->textContent ?? '')
            && $this->labelIsEditable($label)
            && ! ($this->isRuntimeTarget)($image)
            && ! ($this->hasEvents)($image)
            && ! ($this->hasInteractive)($image)
            && ! ($this->hasRouteData)($image)
            && $this->identityIsPreservable($image, array( 'alt', 'class', 'decoding', 'height', 'id', 'loading', 'sizes', 'src', 'srcset', 'style', 'title', 'width', 'aria-labelledby', 'aria-describedby' ))
            && '' !== ($this->imageUrl)($image)
            && SourceDom::safeFallbackUrl(($this->imageUrl)($image), 'src')
            && ( $this->hasBox($image) || $this->isGroupedRow($anchor) );
    }

    private function labelIsEditable(DOMElement $label): bool
    {
        if ( ! $this->identityIsPreservable($label, array( 'class', 'id', 'style', 'title', 'aria-labelledby', 'aria-describedby' )) ) {
            return false;
        }
        foreach ( $label->getElementsByTagName('*') as $descendant ) {
            if ( ! $descendant instanceof DOMElement ) {
                continue;
            }
            $tagName = strtolower($descendant->tagName);
            if ( in_array($tagName, self::CONTROL_TAGS, true) || ! in_array($tagName, self::RICH_TEXT_TAGS, true) || ! RichTextInlineTags::isAllowed($tagName) ) {
                return false;
            }
            if ( $descendant->attributes->length > 0 ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, string> $allowed */
    private function identityIsPreservable(DOMElement $element, array $allowed): bool
    {
        foreach ( $element->attributes ?? array() as $attribute ) {
            $name = strtolower($attribute->name);
            if ( str_starts_with($name, 'data-') || in_array($name, $allowed, true) ) {
                continue;
            }

            return false;
        }

        return null !== $this->style($element) && null !== $this->dataAttributes($element);
    }

    private function hasBox(DOMElement $image): bool
    {
        return '' !== $this->pixel(SourceDom::attr($image, 'width'))
            || '' !== $this->pixel(SourceDom::attr($image, 'height'))
            || '' !== trim(SourceDom::attr($image, 'srcset'))
            || '' !== trim(SourceDom::attr($image, 'sizes'));
    }

    private function isGroupedRow(DOMElement $anchor): bool
    {
        $display = strtolower(trim((string) preg_replace(
            '/\s*!important\s*$/i',
            '',
            (string) ($this->styles->structuralPresentationDeclarations($anchor)['display'] ?? '')
        )));

        return in_array($display, array( 'flex', 'inline-flex', 'grid', 'inline-grid' ), true);
    }

    private function srcset(string $srcset): string
    {
        $safe = SourceDom::safeFallbackSrcset($srcset);
        if ( '' === $safe ) {
            return '';
        }
        $candidates = array();
        foreach ( SrcsetParser::parse($safe) as $candidate ) {
            $url = ($this->resolveUrl)($candidate['url']);
            if ( '' === $url || ! SourceDom::safeFallbackUrl($url, 'src') ) {
                continue;
            }
            $candidates[] = $url . ( '' !== $candidate['descriptor'] ? ' ' . $candidate['descriptor'] : '' );
        }
        $resolved = implode(', ', $candidates);

        return strlen($resolved) <= 4096 ? $resolved : '';
    }

    private function sizes(string $sizes): string
    {
        $sizes = trim($sizes);

        return '' === $sizes || strlen($sizes) > 300 || 1 === preg_match('/[<>]|javascript\s*:/i', $sizes) ? '' : $sizes;
    }

    /**
     * @return string|null Null when an authored declaration would be dropped.
     */
    private function style(DOMElement $element): ?string
    {
        $authored = trim(SourceDom::attr($element, 'style'));
        if ( '' === $authored ) {
            return '';
        }
        $parts = array_values(array_filter(
            CssValueSplitter::splitTopLevel($authored, array( ';' )),
            static fn (string $part): bool => str_contains($part, ':')
        ));
        $parsed = $this->styles->verbatimCssDeclarations($authored);
        if ( array() === $parsed || count($parsed) !== count($parts) ) {
            return null;
        }

        return $this->styles->cssDeclarationString($parsed);
    }

    /** @return array<string, string>|null */
    private function dataAttributes(DOMElement $element): ?array
    {
        $attributes = array();
        foreach ( $element->attributes ?? array() as $attribute ) {
            $name = strtolower($attribute->name);
            if ( ! str_starts_with($name, 'data-') ) {
                continue;
            }
            if ( ! preg_match('/^data-[a-z0-9-]+$/', $name) || preg_match('/url$/i', $name) || preg_match('/javascript\s*:|<|>/i', $attribute->value) || strlen($attribute->value) > 200 ) {
                return null;
            }
            $attributes[$name] = $attribute->value;
        }
        if ( count($attributes) > 8 ) {
            return null;
        }
        ksort($attributes);

        return $attributes;
    }

    private function labelHtml(DOMElement $label): string
    {
        $html = '';
        foreach ( $label->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                $tagName = strtolower($child->tagName);
                if ( 'br' === $tagName ) {
                    $html .= '<br>';
                    continue;
                }
                $html .= '<' . $tagName . '>' . $this->labelHtml($child) . '</' . $tagName . '>';
                continue;
            }
            $html .= htmlspecialchars($child->textContent ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return $html;
    }

    private function pixel(string $value): string
    {
        return 1 === preg_match('/^\d{1,5}$/', trim($value)) ? trim($value) : '';
    }

    /** @param array<int, string> $allowed */
    private function token(string $value, array $allowed): string
    {
        $value = strtolower(trim($value));

        return in_array($value, $allowed, true) ? $value : '';
    }

    private function rel(string $rel): string
    {
        $tokens = array();
        foreach ( preg_split('/\s+/', strtolower(trim($rel))) ?: array() as $token ) {
            if ( in_array($token, array( 'noopener', 'noreferrer', 'nofollow', 'external', 'ugc', 'sponsored', 'me', 'license' ), true) ) {
                $tokens[] = $token;
            }
        }

        return implode(' ', array_values(array_unique($tokens)));
    }

    private function plain(string $value): string
    {
        $value = trim($value);

        return strlen($value) > 300 ? '' : $value;
    }

    private function idRef(string $value): string
    {
        $ids = array();
        foreach ( preg_split('/\s+/', trim($value)) ?: array() as $id ) {
            $safe = SourceDom::anchorAttributeValue($id);
            if ( '' === $safe || $safe !== $id ) {
                return '';
            }
            $ids[] = $safe;
        }

        return implode(' ', $ids);
    }
}
