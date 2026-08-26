<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\LinkUrlSanitizer;
use DOMElement;

/** Lowers explicit social-profile clusters to the core social-links family. */
final class SocialLinksPattern implements PatternRecognizerInterface
{
    use PatternDomHelpersTrait;

    /** @var array<string,string> */
    private const HOST_SERVICES = array(
        'behance.net' => 'behance', 'bluesky.app' => 'bluesky', 'discord.com' => 'discord',
        'discord.gg' => 'discord', 'dribbble.com' => 'dribbble', 'facebook.com' => 'facebook',
        'github.com' => 'github', 'gitlab.com' => 'gitlab', 'instagram.com' => 'instagram',
        'linkedin.com' => 'linkedin', 'mastodon.social' => 'mastodon', 'pinterest.com' => 'pinterest',
        'reddit.com' => 'reddit', 'soundcloud.com' => 'soundcloud', 'spotify.com' => 'spotify',
        'telegram.me' => 'telegram', 'telegram.org' => 'telegram', 'threads.net' => 'threads',
        'tiktok.com' => 'tiktok', 'tumblr.com' => 'tumblr', 'twitch.tv' => 'twitch',
        'twitter.com' => 'twitter', 'vimeo.com' => 'vimeo', 'whatsapp.com' => 'whatsapp',
        'x.com' => 'x', 'youtube.com' => 'youtube', 'youtu.be' => 'youtube',
    );

    public function recognize(DOMElement $element, PatternContext $context): ?PatternRecognitionResult
    {
        // A source navigation landmark carries menu semantics that core/social-links
        // cannot retain; NavigationPattern owns that dynamic landmark contract.
        if ( 'nav' === strtolower($element->tagName) || 'navigation' === strtolower($this->attr($element, 'role')) ) {
            return null;
        }
        $anchors = $this->anchors($element);
        if ( array() === $anchors || ! $this->isSocialCluster($element, $anchors) ) {
            return null;
        }

        $links = array();
        $showLabels = false;
        $iconOnly = true;
        $structuralItems = true;
        foreach ( $anchors as $anchor ) {
            $url = LinkUrlSanitizer::sanitize($this->attr($anchor, 'href'));
            if ( '' === $url ) {
                return null;
            }

            $label = trim($this->attr($anchor, 'aria-label'));
            if ( '' === $label ) {
                $label = trim($this->attr($anchor, 'title'));
            }
            $text = trim((string) $anchor->textContent);
            if ( '' === $label ) {
                $label = $text;
            }
            $showLabels = $showLabels || '' !== $text;
            $iconOnly = $iconOnly && '' === $text && $this->hasIcon($anchor);
            $sourceElement = $this->structuralItem($anchor, $element);
            $structuralItems = $structuralItems && ! $sourceElement->isSameNode($anchor);
            $links[] = $context->createBlock('core/social-link', array_merge(
                $context->presentationAttributes($sourceElement),
                array_filter(array(
                'url' => $url,
                'service' => $this->service($url) ?? 'chain',
                'label' => $label,
                ), static fn(string $value): bool => '' !== $value)
            ), array(), $sourceElement);
        }

        $attrs = $context->presentationAttributes($element);
        if ( $showLabels ) {
            $attrs['showLabels'] = true;
        }
        if ( $iconOnly ) {
            $attrs['className'] = trim((string) ($attrs['className'] ?? '') . ' is-style-logos-only');
            $size = $this->iconSize($anchors);
            if ( null !== $size ) {
                $attrs['size'] = $size;
            }
        }
        if ( $structuralItems ) {
            $attrs['className'] = trim((string) ($attrs['className'] ?? '') . ' blocks-engine-source-social-item-spacing');
        }
        return new PatternRecognitionResult(
            $context->createBlock('core/social-links', $attrs, $links, $element)
        );
    }

    /** @param array<int,DOMElement> $anchors */
    private function isSocialCluster(DOMElement $element, array $anchors): bool
    {
        $identity = strtolower($this->attr($element, 'class') . ' ' . $this->attr($element, 'aria-label') . ' ' . $this->attr($element, 'role'));
        $explicit = 1 === preg_match('/(?:^|[^a-z])social(?:[^a-z]|$)/', $identity);
        if ( $explicit ) {
            return true;
        }

        if ( count($anchors) < 2 ) {
            return false;
        }
        foreach ( $anchors as $anchor ) {
            if ( null === $this->service(LinkUrlSanitizer::sanitize($this->attr($anchor, 'href'))) ) {
                return false;
            }
        }
        return true;
    }

    /** @return array<int,DOMElement> */
    private function anchors(DOMElement $element): array
    {
        $anchors = array();
        foreach ( $element->getElementsByTagName('a') as $anchor ) {
            if ( ! $anchor instanceof DOMElement || ! $anchor->hasAttribute('href') ) {
                continue;
            }
            $ancestor = $anchor->parentNode;
            $nested = false;
            while ( $ancestor instanceof DOMElement && ! $ancestor->isSameNode($element) ) {
                if ( 'a' === strtolower($ancestor->tagName) ) {
                    $nested = true;
                    break;
                }
                $ancestor = $ancestor->parentNode;
            }
            if ( ! $nested ) {
                $anchors[] = $anchor;
            }
        }
        return $anchors;
    }

    private function service(string $url): ?string
    {
        $host = strtolower((string) parse_url(str_contains($url, '://') ? $url : 'https://' . $url, PHP_URL_HOST));
        foreach ( self::HOST_SERVICES as $domain => $service ) {
            if ( $host === $domain || str_ends_with($host, '.' . $domain) ) {
                return $service;
            }
        }
        return null;
    }

    private function structuralItem(DOMElement $anchor, DOMElement $cluster): DOMElement
    {
        $parent = $anchor->parentNode;
        return $parent instanceof DOMElement
            && $parent->parentNode instanceof DOMElement
            && $parent->parentNode->isSameNode($cluster)
            && 'li' === strtolower($parent->tagName)
                ? $parent
                : $anchor;
    }

    private function hasIcon(DOMElement $anchor): bool
    {
        return 0 < $anchor->getElementsByTagName('img')->length
            || 0 < $anchor->getElementsByTagName('svg')->length;
    }

    /** @param array<int,DOMElement> $anchors */
    private function iconSize(array $anchors): ?string
    {
        $dimensions = array();
        foreach ( $anchors as $anchor ) {
            foreach ( array( 'img', 'svg' ) as $tagName ) {
                $icon = $anchor->getElementsByTagName($tagName)->item(0);
                if ( ! $icon instanceof DOMElement ) {
                    continue;
                }
                $width = (float) $this->attr($icon, 'width');
                $height = (float) $this->attr($icon, 'height');
                if ( 0 < $width && 0 < $height ) {
                    $dimensions[] = min($width, $height);
                }
                break;
            }
        }
        if ( array() === $dimensions ) {
            return null;
        }

        sort($dimensions, SORT_NUMERIC);
        $sourceSize = $dimensions[(int) floor((count($dimensions) - 1) / 2)];
        $presets = array( 'small' => 16.0, 'normal' => 24.0, 'large' => 36.0, 'huge' => 48.0 );
        $closest = 'normal';
        foreach ( $presets as $preset => $pixels ) {
            if ( abs($sourceSize - $pixels) < abs($sourceSize - $presets[ $closest ]) ) {
                $closest = $preset;
            }
        }
        return $closest;
    }
}
