<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\SrcsetParser;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use DOMElement;

final class ImageUrlResolver
{
    public function __construct(private readonly HtmlTransformerSession $session)
    {
    }

    public function sourceUrl(DOMElement $image): string
    {
        $src = $this->safeUrl(SourceDom::attr($image, 'src'));
        if ( '' !== $src && ! str_starts_with(strtolower($src), 'data:') ) {
            return $src;
        }

        if ( '' === $src ) {
            $largest = '';
            $largestWidth = -1;
            foreach ( SrcsetParser::parse(SourceDom::attr($image, 'srcset')) as $candidate ) {
                if ( 1 !== preg_match('/^(\d+)w$/', $candidate['descriptor'], $matches) || (int) $matches[1] <= $largestWidth ) {
                    continue;
                }
                $url = $this->safeUrl($candidate['url']);
                if ( '' !== $url ) {
                    $largest = $url;
                    $largestWidth = (int) $matches[1];
                }
            }
            if ( '' !== $largest ) {
                return $largest;
            }
        }

        foreach ( array( 'data-src', 'data-lazy-src', 'data-original', 'data-image-src' ) as $attribute ) {
            $candidate = $this->safeUrl(SourceDom::attr($image, $attribute));
            if ( '' !== $candidate && SourceDom::safeFallbackUrl($candidate, 'src', 'img') ) {
                return $candidate;
            }
        }

        $fromHost = $this->safeUrl(SourceDom::imageUrlFromHostMetadata($image));
        if ( '' !== $fromHost ) {
            return $fromHost;
        }

        return $src;
    }

    public function safeUrl(string $url): string
    {
        if ( ! preg_match('#^data:image/svg\+xml(?:[;,][^,]*)?,#i', $url) ) {
            return $url;
        }

        $parts = explode(',', $url, 2);
        if ( 2 !== count($parts) ) {
            return '';
        }

        $metadata = strtolower($parts[0]);
        $svg = str_contains($metadata, ';base64') ? base64_decode($parts[1], true) : rawurldecode($parts[1]);
        if ( false === $svg || ! SourceDom::isSafeSvgContent($svg) ) {
            return '';
        }

        return $url;
    }

    public function resolvedAssetUrl(string $url): string
    {
        if ( '' === $url ) {
            return '';
        }

        $asset = $this->assetMetadata($url);
        if ( ! is_array($asset) || ! isset($asset['url']) || ! is_string($asset['url']) ) {
            return $url;
        }

        $resolvedUrl = $this->safeResolvedAssetImageUrl(trim($asset['url']));
        if ( '' === $resolvedUrl ) {
            return $url;
        }

        preg_match('/^[^?#]*(.*)$/s', $url, $parts);
        $resolvedUrl = $this->safeResolvedAssetImageUrl($resolvedUrl . ($parts[1] ?? ''));
        return '' !== $resolvedUrl ? $resolvedUrl : $url;
    }

    /** @return array<string, mixed>|null */ public function assetMetadata(string $url): ?array {
        return $this->session->assetMaterializationState()->metadataForUrl($url);
    }

    private function safeResolvedAssetImageUrl(string $url): string
    {
        if ( '' === $url || preg_match('/[\x00-\x1f\x7f]|javascript\s*:/i', $url) ) {
            return '';
        }

        return $this->safeUrl($url);
    }
}
