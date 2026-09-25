<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\LinkUrlSanitizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\MonochromeGlyphColor;
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

    /** @var array<string,string> */
    private const LABEL_SERVICES = array(
        'behance' => 'behance', 'bluesky' => 'bluesky', 'discord' => 'discord',
        'dribbble' => 'dribbble', 'facebook' => 'facebook', 'github' => 'github',
        'gitlab' => 'gitlab', 'instagram' => 'instagram', 'linkedin' => 'linkedin',
        'mastodon' => 'mastodon', 'pinterest' => 'pinterest', 'reddit' => 'reddit',
        'soundcloud' => 'soundcloud', 'spotify' => 'spotify', 'telegram' => 'telegram',
        'threads' => 'threads', 'tiktok' => 'tiktok', 'tumblr' => 'tumblr',
        'twitch' => 'twitch', 'twitter' => 'twitter', 'vimeo' => 'vimeo',
        'whatsapp' => 'whatsapp', 'x' => 'x', 'x twitter' => 'x',
        'youtube' => 'youtube', 'email' => 'mail', 'mail' => 'mail',
    );

    public function recognize(DOMElement $element, PatternContext $context): ?PatternRecognitionResult
    {
        return $this->recognizeCluster($element, $context, false);
    }

    private function recognizeCluster(DOMElement $element, PatternContext $context, bool $explicit): ?PatternRecognitionResult
    {
        // A source navigation landmark carries menu semantics that core/social-links
        // cannot retain; NavigationPattern owns that dynamic landmark contract.
        if ( 'nav' === strtolower($element->tagName) || 'navigation' === strtolower($this->attr($element, 'role')) ) {
            return null;
        }
        $anchors = $this->anchors($element);
        $explicit = $explicit || self::isExplicitSocialCluster($element);
        if ( array() === $anchors || (! $explicit && ! $this->isSocialCluster($element, $anchors)) ) {
            return null;
        }

        // A named social region can wrap the actual icon row. Its width or
        // alignment belongs to the outer box, while responsive gap and margins
        // belong to the inner row; flattening them loses the row's CSS hooks.
        $children = $this->directChildElements($element);
        if ( 'div' === strtolower($element->tagName)
            && 1 === count($children)
            && 'div' === strtolower($children[0]->tagName)
        ) {
            $row = $this->recognizeCluster($children[0], $context, $explicit);
            if ( null !== $row ) {
                return new PatternRecognitionResult($context->createBlock(
                    'core/group',
                    $context->presentationAttributes($element),
                    array($row->block()),
                    $element
                ));
            }
        }

        $links = array();
        $linkAnchors = array();
        $showLabels = false;
        $iconOnly = true;
        $structuralItems = true;
        foreach ( $anchors as $anchor ) {
            $url = LinkUrlSanitizer::sanitize($this->attr($anchor, 'href'));
            $label = trim($this->attr($anchor, 'aria-label'));
            if ( '' === $label ) {
                $label = trim($this->attr($anchor, 'title'));
            }
            $text = trim((string) $anchor->textContent);
            if ( '' === $label ) {
                $label = $text;
            }
            $service = $this->service($url) ?? $this->serviceFromLabel($label);
            $labeledPlaceholder = $explicit
                && $this->isLocalPlaceholderUrl($url)
                && null !== $service;
            if ( '' === $url || (! $this->isUsableSocialUrl($url) && ! $labeledPlaceholder) ) {
                continue;
            }
            $showLabels = $showLabels || '' !== $text;
            $iconOnly = $iconOnly && '' === $text && $this->hasIcon($anchor);
            $sourceElement = $this->structuralItem($anchor, $element);
            $structuralItems = $structuralItems && ! $sourceElement->isSameNode($anchor);
            $linkAnchors[] = $anchor;
            $links[] = $context->createBlock('core/social-link', array_merge(
                $context->presentationAttributes($sourceElement),
                array_filter(array(
                'url' => $url,
                'service' => $service ?? 'chain',
                'label' => $label,
                ), static fn(string $value): bool => '' !== $value)
            ), array(), $sourceElement);
        }

        if ( array() === $links ) {
            return null;
        }

        $attrs = $context->presentationAttributes($element);
        for ( $carrier = $element; $carrier instanceof DOMElement && 'body' !== strtolower($carrier->tagName); $carrier = $carrier->parentNode ) {
            if ( preg_match('/(?:^|;)\s*text-align\s*:\s*(left|center|right)\b/i', $this->attr($carrier, 'style'), $alignment) ) {
                $attrs['justifyContent'] = strtolower($alignment[1]);
                break;
            }
        }
        if ( $showLabels ) {
            $attrs['showLabels'] = true;
        }
        if ( $iconOnly ) {
            $attrs['className'] = trim((string) ($attrs['className'] ?? '') . ' is-style-logos-only');
            $attrs['size'] = $this->iconSize($anchors) ?? 'small';
            $iconColor = $this->uniformIconColor($linkAnchors, $context);
            if ( '' !== $iconColor ) {
                $attrs['iconColorValue'] = $iconColor;
                $attrs['customIconColor'] = $iconColor;
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
        if ( self::isExplicitSocialCluster($element) ) {
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

    public static function isExplicitSocialCluster(DOMElement $element): bool
    {
        $identity = strtolower($element->getAttribute('class') . ' ' . $element->getAttribute('aria-label') . ' ' . $element->getAttribute('role'));
        return 1 === preg_match('/(?:^|[^a-z])socials?(?:[^a-z]|$)/', $identity);
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

    private function isUsableSocialUrl(string $url): bool
    {
        if ( str_starts_with(strtolower($url), 'mailto:') ) {
            return true;
        }

        $resolved = str_contains($url, '://') ? $url : (str_starts_with($url, '//') ? 'https:' . $url : 'https://' . $url);
        return '' !== strtolower((string) parse_url($resolved, PHP_URL_HOST));
    }

    private function service(string $url): ?string
    {
        if ( str_starts_with(strtolower($url), 'mailto:') ) {
            return 'mail';
        }

        $host = strtolower((string) parse_url(str_contains($url, '://') ? $url : 'https://' . $url, PHP_URL_HOST));
        foreach ( self::HOST_SERVICES as $domain => $service ) {
            if ( $host === $domain || str_ends_with($host, '.' . $domain) ) {
                return $service;
            }
        }
        return null;
    }

    private function serviceFromLabel(string $label): ?string
    {
        $normalized = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', ' ', $label)));
        $normalized = preg_replace('/^(?:follow us on|follow|visit)\s+/', '', $normalized) ?? $normalized;
        if ( isset(self::LABEL_SERVICES[$normalized]) ) {
            return self::LABEL_SERVICES[$normalized];
        }

        $aliases = array_keys(self::LABEL_SERVICES);
        usort($aliases, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
        foreach ( $aliases as $alias ) {
            if ( 1 === preg_match('/(?:^|\s)' . preg_quote($alias, '/') . '(?:\s|$)/', $normalized) ) {
                return self::LABEL_SERVICES[$alias];
            }
        }
        return null;
    }

    private function isLocalPlaceholderUrl(string $url): bool
    {
        return str_starts_with(ltrim(trim($url), '/'), '#');
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
        if ( 0 < $anchor->getElementsByTagName('img')->length || 0 < $anchor->getElementsByTagName('svg')->length ) {
            return true;
        }

        return '' === trim((string) $anchor->textContent) && 0 < $anchor->getElementsByTagName('span')->length;
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

    /** @param array<int,DOMElement> $anchors */
    private function uniformIconColor(array $anchors, PatternContext $context): string
    {
        $colors = array();
        foreach ( $anchors as $anchor ) {
            $color = $this->iconColor($anchor, $context);
            if ( '' === $color ) {
                return '';
            }
            $colors[$color] = true;
        }

        return 1 === count($colors) ? (string) array_key_first($colors) : '';
    }

    private function iconColor(DOMElement $anchor, PatternContext $context): string
    {
        $image = $anchor->getElementsByTagName('img')->item(0);
        if ( $image instanceof DOMElement ) {
            $glyph = $this->glyphColor($image, $context);
            if ( '' !== $glyph ) {
                return $glyph;
            }

            return $this->ownPaintColor($image) ?: $this->ownPaintColor($anchor);
        }

        $svg = $anchor->getElementsByTagName('svg')->item(0);
        if ( $svg instanceof DOMElement ) {
            $fill = $this->concreteColor($this->attr($svg, 'fill'));
            if ( '' !== $fill ) {
                return $fill;
            }
            $styled = $this->ownPaintColor($svg);
            if ( '' !== $styled ) {
                return $styled;
            }
        }

        $own = $this->ownPaintColor($anchor);
        if ( '' !== $own ) {
            return $own;
        }

        return $this->concreteColor($context->authoredIconColor($anchor));
    }

    private function glyphColor(DOMElement $image, PatternContext $context): string
    {
        $url = trim($this->attr($image, 'src'));
        if ( '' === $url ) {
            return '';
        }
        if ( str_starts_with(strtolower($url), 'data:image/') ) {
            $comma = strpos($url, ',');
            if ( false === $comma ) {
                return '';
            }
            $meta = substr($url, 0, $comma);
            $payload = substr($url, $comma + 1);
            $bytes = str_contains($meta, ';base64') ? base64_decode($payload, true) : rawurldecode($payload);

            return is_string($bytes) ? MonochromeGlyphColor::fromBytes($bytes) : '';
        }

        return $this->concreteColor($context->assetGlyphColor($url));
    }

    private function ownPaintColor(DOMElement $element): string
    {
        $style = $this->attr($element, 'style');
        if ( '' === $style ) {
            return '';
        }
        foreach ( array( 'fill', 'color' ) as $property ) {
            if ( 1 === preg_match('/(?:^|;)\s*' . $property . '\s*:\s*([^;]+)/i', $style, $match) ) {
                $color = $this->concreteColor($match[1]);
                if ( '' !== $color ) {
                    return $color;
                }
            }
        }

        return '';
    }

    private function concreteColor(string $value): string
    {
        $value = strtolower(trim(preg_replace('/\s*!important\s*$/i', '', trim($value)) ?? ''));
        if ( '' === $value || in_array($value, array( 'currentcolor', 'transparent', 'none', 'inherit', 'initial', 'unset', 'revert', 'revert-layer', 'auto' ), true) ) {
            return '';
        }
        if ( 'white' === $value ) {
            return '#ffffff';
        }
        if ( 'black' === $value ) {
            return '#000000';
        }
        if ( 1 === preg_match('/^#([0-9a-f]{3})$/', $value, $short) ) {
            return '#' . $short[1][0] . $short[1][0] . $short[1][1] . $short[1][1] . $short[1][2] . $short[1][2];
        }
        if ( 1 === preg_match('/^#([0-9a-f]{6})$/', $value, $hex) ) {
            return '#' . $hex[1];
        }
        if ( 1 === preg_match('/^rgba?\(\s*(\d{1,3})(?:\s*,\s*|\s+)(\d{1,3})(?:\s*,\s*|\s+)(\d{1,3})/', $value, $rgb) ) {
            $channels = array( (int) $rgb[1], (int) $rgb[2], (int) $rgb[3] );
            foreach ( $channels as $channel ) {
                if ( $channel < 0 || $channel > 255 ) {
                    return '';
                }
            }

            return sprintf('#%02x%02x%02x', $channels[0], $channels[1], $channels[2]);
        }

        return '';
    }
}
