<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;
use DOMElement;
use DOMText;

/**
 * Projects a List View label onto recognised container blocks via metadata.name.
 *
 * Gutenberg displays metadata.name in List View when supports.renaming is true
 * (the default for core/group and core/cover). The attribute is comment-only:
 * it is not rendered by save() and does not participate in wrapper validity.
 */
final class EditorListViewContainerNamer
{
    private const EMPTY_VISUAL_GROUP_CLASS = 'blocks-engine-empty-visual-group';

    /** @var array<int, string> */
    private const NAMABLE_BLOCKS = array( 'core/group', 'core/cover' );

    /** @var array<int, string> */
    private const NESTED_SECTIONING_TAGS = array( 'article', 'aside', 'nav', 'section' );

    /** @var array<string, string> */
    private const LANDMARK_LABELS = array(
        'header' => 'Header',
        'nav'    => 'Navigation',
        'main'   => 'Main',
        'footer' => 'Footer',
    );

    private const MAX_NAME_LENGTH = 80;

    /**
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    public function apply(string $blockName, array $attrs, DOMElement $sourceElement): array
    {
        $name = $this->name($blockName, $attrs, $sourceElement);
        if ( null === $name ) {
            return $attrs;
        }

        $metadata = is_array($attrs['metadata'] ?? null) ? $attrs['metadata'] : array();
        $metadata['name'] = $name;
        $attrs['metadata'] = $metadata;

        return $attrs;
    }

    /**
     * @param array<string, mixed> $attrs
     */
    public function name(string $blockName, array $attrs, DOMElement $sourceElement): ?string
    {
        if ( ! in_array($blockName, self::NAMABLE_BLOCKS, true) ) {
            return null;
        }
        if ( is_string($attrs['metadata']['name'] ?? null) && '' !== trim((string) $attrs['metadata']['name']) ) {
            return null;
        }
        if ( self::isEmptyVisualGroup($attrs, $sourceElement) ) {
            return null;
        }

        $tag  = strtolower((string) ($attrs['tagName'] ?? $sourceElement->tagName));
        $role = strtolower(SourceDom::attr($sourceElement, 'role'));
        $landmark = ShellLandmarkPolicy::landmarkKind($tag, $role);
        if ( isset(self::LANDMARK_LABELS[ $landmark ]) ) {
            return self::LANDMARK_LABELS[ $landmark ];
        }
        if ( 'aside' === $tag || 'complementary' === $role ) {
            return 'Aside';
        }

        $isSectioningContainer = in_array($tag, array( 'section', 'article', 'li' ), true) || 'core/cover' === $blockName;
        if ( ! $isSectioningContainer ) {
            return null;
        }

        $heading = self::ownedHeadingText($sourceElement);
        if ( '' !== $heading ) {
            return $heading;
        }

        $ariaLabel = self::normalizeLabel(SourceDom::attr($sourceElement, 'aria-label'));
        return '' !== $ariaLabel ? $ariaLabel : null;
    }

    /** @param array<string, mixed> $attrs */
    private static function isEmptyVisualGroup(array $attrs, DOMElement $sourceElement): bool
    {
        $className = (string) ($attrs['className'] ?? '');

        return str_contains($className, self::EMPTY_VISUAL_GROUP_CLASS)
            || SourceDom::hasClass($sourceElement, self::EMPTY_VISUAL_GROUP_CLASS);
    }

    private static function ownedHeadingText(DOMElement $element): string
    {
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if ( 1 === preg_match('/^h[1-6]$/', $tag) ) {
                $text = self::normalizeLabel(self::visibleText($child));
                if ( '' !== $text ) {
                    return $text;
                }
                continue;
            }
            if ( in_array($tag, self::NESTED_SECTIONING_TAGS, true) ) {
                continue;
            }
            $nested = self::ownedHeadingText($child);
            if ( '' !== $nested ) {
                return $nested;
            }
        }

        return '';
    }

    private static function visibleText(DOMElement $element): string
    {
        $parts = array();
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                $tag = strtolower($child->tagName);
                if ( in_array($tag, array( 'script', 'style' ), true) ) {
                    continue;
                }
                $parts[] = 'br' === $tag ? ' ' : self::visibleText($child);
                continue;
            }
            if ( $child instanceof DOMText ) {
                $parts[] = $child->textContent;
            }
        }

        return implode('', $parts);
    }

    private static function normalizeLabel(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ( '' === $text ) {
            return '';
        }
        if ( mb_strlen($text) <= self::MAX_NAME_LENGTH ) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, self::MAX_NAME_LENGTH));
    }
}
