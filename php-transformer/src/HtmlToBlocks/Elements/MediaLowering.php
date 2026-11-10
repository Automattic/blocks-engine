<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\ImageUrlResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * Lowers source <video> and <audio> elements to core/video and core/audio.
 */
final class MediaLowering
{
    public function __construct(
        private readonly SourceBlockCreator $blocks,
        private readonly StyleResolver $styleResolver,
        private readonly ImageUrlResolver $imageUrls
    ) {
    }

    public function fromElement(DOMElement $element): ?array
    {
        $tagName = strtolower($element->tagName);
        $src = $this->safeMediaUrl(SourceDom::attr($element, 'src'));
        if ( '' === $src ) {
            $source = SourceDom::firstChildElement($element, 'source');
            $src = $source instanceof DOMElement ? $this->safeMediaUrl(SourceDom::attr($source, 'src')) : '';
        }
        if ( '' === $src ) {
            return null;
        }

        $attrs = array_filter(array_merge($this->styleResolver->presentationAttributes($element), array(
            'src'      => $src,
            'poster'   => 'video' === $tagName ? $this->imageUrls->safeUrl(SourceDom::attr($element, 'poster')) : '',
            'preload'  => SourceDom::attr($element, 'preload'),
            'width'    => 'video' === $tagName ? '' : SourceDom::attr($element, 'width'),
            'height'   => 'video' === $tagName ? '' : SourceDom::attr($element, 'height'),
            'controls' => $element->hasAttribute('controls'),
        )), static fn (mixed $value): bool => is_bool($value) ? $value : '' !== $value);

        if ( 'video' === $tagName ) {
            // Jetpack re-registers core/video with comment-sourced playback
            // attributes and defaults controls to true. Preserve the explicit
            // absence of controls so that registration does not change the
            // captured playback state when the block is parsed in the editor.
            $attrs['controls'] = $element->hasAttribute('controls');
            foreach ( array( 'autoplay', 'loop', 'muted' ) as $attribute ) {
                if ( $element->hasAttribute($attribute) ) {
                    $attrs[$attribute] = true;
                }
            }
            if ( $element->hasAttribute('playsinline') ) {
                $attrs['playsInline'] = true;
            }
            $tracks = $this->videoTracks($element);
            if ( array() !== $tracks ) {
                $attrs['tracks'] = $tracks;
            }
        }

        return $this->blocks->createBlock('core/' . $tagName, $attrs, array(), $element);
    }

    private function safeMediaUrl(string $url): string
    {
        $url = trim($url);
        if ( '' === $url || preg_match('/[\x00-\x1f\x7f]|javascript\s*:/i', $url) ) {
            return '';
        }

        return $url;
    }

    /** @return array<int, array<string, mixed>> */
    private function videoTracks(DOMElement $video): array
    {
        $tracks = array();
        foreach ( $video->getElementsByTagName('track') as $track ) {
            if ( ! $track instanceof DOMElement || 32 === count($tracks) ) {
                break;
            }
            $src = $this->safeMediaUrl(SourceDom::attr($track, 'src'));
            if ( '' === $src ) {
                continue;
            }
            $tracks[] = array_filter(array(
                'kind'    => SourceDom::attr($track, 'kind'),
                'src'     => $src,
                'srcLang' => SourceDom::attr($track, 'srclang'),
                'label'   => SourceDom::attr($track, 'label'),
                'default' => $track->hasAttribute('default'),
            ), static fn (mixed $value): bool => is_bool($value) ? $value : '' !== $value);
        }

        return $tracks;
    }
}
