<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;

/**
 * Centralizes the distinction between document shell landmarks and content-local
 * semantic containers so transformer/materialization decisions stay aligned.
 */
final class ShellLandmarkPolicy
{
    /** @var array<string, string> */
    private const TEMPLATE_PART_AREA_TAGS = array(
        'uncategorized'      => 'div',
        'header'             => 'header',
        'footer'             => 'footer',
        'navigation-overlay' => 'div',
    );

    /**
     * The List View name each landmark kind carries (`metadata.name`).
     *
     * @var array<string, string>
     */
    public const LANDMARK_LABELS = array(
        'header' => 'Header',
        'nav'    => 'Navigation',
        'main'   => 'Main',
        'footer' => 'Footer',
    );

    /** @var array<int, string> */
    private const GLOBAL_SHELL_LANDMARK_TAGS = array( 'header', 'footer', 'nav' );

    /** @var array<int, string> */
    private const SEMANTIC_GROUP_TAGS = array( 'header', 'nav', 'search', 'section', 'article', 'aside', 'footer', 'main' );

    /**
     * Elements whose content model is flow content, so their children convert
     * through the ordinary container strategies. `fieldset` belongs here: HTML
     * defines its content model as an optional `legend` caption followed by flow
     * content, which makes it a grouping wrapper rather than a form control.
     *
     * @var array<int, string>
     */
    private const FLOW_CONTAINER_TAGS = array( 'article', 'aside', 'body', 'center', 'div', 'fieldset', 'footer', 'header', 'main', 'nav', 'search', 'section' );

    /** @var array<int, string> */
    private const WRAPPER_PRESERVING_TAGS = array( 'article', 'aside', 'div', 'footer', 'header', 'main', 'nav', 'search', 'section' );

    /** @var array<int, string> */
    private const INLINE_TOKEN_CONTAINER_TAGS = array( 'div', 'footer', 'header', 'main', 'nav', 'section' );

    /** @var array<int, string> */
    private const INLINE_CONTENT_WRAPPER_TAGS = array( 'article', 'div', 'footer', 'header', 'main', 'section' );

    public static function isGlobalShellLandmarkTag(string $tagName): bool
    {
        return in_array(strtolower($tagName), self::GLOBAL_SHELL_LANDMARK_TAGS, true);
    }

    /**
     * Sectioning content regions own their header/footer: a landmark inside one
     * is section-level, never page chrome. `main` is not listed: whether it is
     * a content region depends on whether it also wraps the page header.
     */
    public static function isContentRegionTag(string $tagName): bool
    {
        return in_array(strtolower($tagName), array( 'article', 'aside', 'section' ), true);
    }

    /**
     * A unique landmark at the document edge can be page chrome even inside
     * main or a top-level section. Article/aside landmarks remain content.
     */
    public static function isDocumentEdgeLandmark(\DOMElement $element): bool
    {
        $area = self::landmarkKind($element->tagName, $element->getAttribute('role'));
        if (!in_array($area, array('header', 'footer'), true)) return false;
        $root = $element;
        for ($ancestor = $element->parentNode; $ancestor instanceof \DOMElement; $ancestor = $ancestor->parentNode) {
            if (in_array(strtolower($ancestor->tagName), array('article', 'aside', 'nav', 'blockquote', 'figure'), true)) return false;
            $root = $ancestor;
            if ('body' === strtolower($ancestor->tagName)) break;
        }
        $count = 0;
        foreach ($root->getElementsByTagName('*') as $node) {
            if (self::landmarkKind($node->tagName, $node->getAttribute('role')) === $area) ++$count;
        }
        if (1 !== $count) return false;
        for ($node = $element; $node !== $root; $node = $node->parentNode) {
            for ($sibling = 'header' === $area ? $node->previousSibling : $node->nextSibling; null !== $sibling; $sibling = 'header' === $area ? $sibling->previousSibling : $sibling->nextSibling) {
                if (self::hasDocumentEdgeContent($sibling)) return false;
            }
        }
        return true;
    }

    /**
     * Empty app containers (for example notification outlets) do not move a
     * landmark away from the document edge. Text, media and controls still do.
     */
    private static function hasDocumentEdgeContent(\DOMNode $node): bool
    {
        if (XML_TEXT_NODE === $node->nodeType) return '' !== trim($node->textContent);
        if (!$node instanceof \DOMElement) return false;
        // Only grouping containers are empty outlets. Keep other elements
        // (including media, controls and document resources) as boundaries.
        if (!self::isFlowContainerTag($node->tagName)) return true;
        foreach ($node->childNodes as $child) {
            if (self::hasDocumentEdgeContent($child)) return true;
        }
        return false;
    }

    public static function isSemanticGroupTag(string $tagName): bool
    {
        return in_array(strtolower($tagName), self::SEMANTIC_GROUP_TAGS, true);
    }

    public static function isFlowContainerTag(string $tagName): bool
    {
        return in_array(strtolower($tagName), self::FLOW_CONTAINER_TAGS, true);
    }

    public static function isWrapperPreservingTag(string $tagName): bool
    {
        return in_array(strtolower($tagName), self::WRAPPER_PRESERVING_TAGS, true);
    }

    public static function isColumnsWrapperTag(string $tagName): bool
    {
        return in_array(strtolower($tagName), self::WRAPPER_PRESERVING_TAGS, true);
    }

    public static function isInlineTokenContainerTag(string $tagName): bool
    {
        return in_array(strtolower($tagName), self::INLINE_TOKEN_CONTAINER_TAGS, true);
    }

    public static function isInlineContentWrapperTag(string $tagName): bool
    {
        return in_array(strtolower($tagName), self::INLINE_CONTENT_WRAPPER_TAGS, true);
    }

    /**
     * Whether a group records a landmark only through its List View name: the
     * source said `role="main"` (or banner, navigation, contentinfo) on a
     * generic element, and the saved tag does not carry the landmark.
     *
     * @param array<string, mixed> $attrs
     */
    public static function namesRoleOnlyLandmark(array $attrs): bool
    {
        $kind = array_search($attrs['metadata']['name'] ?? null, self::LANDMARK_LABELS, true);
        return false !== $kind && $kind !== self::landmarkKind((string) ($attrs['tagName'] ?? 'div'));
    }

    /**
     * Whether block markup holds a group named as the main landmark. This is
     * the only trace a `role="main"` region leaves (see namesRoleOnlyLandmark()).
     */
    public static function containsNamedMainLandmark(string $markup): bool
    {
        return 1 === preg_match('/"metadata":\{(?:[^{}]|\{[^{}]*\})*"name":"' . self::LANDMARK_LABELS['main'] . '"/', $markup);
    }

    public static function landmarkKind(string $tagName, string $role = '', bool $insideContentLocalCitation = false): string
    {
        $tagName = strtolower($tagName);
        if ( 'footer' === $tagName && $insideContentLocalCitation ) {
            return '';
        }

        if ( in_array($tagName, array( 'header', 'nav', 'main', 'footer' ), true) ) {
            return 'nav' === $tagName ? 'nav' : $tagName;
        }

        return match ( strtolower($role) ) {
            'banner' => 'header',
            'navigation' => 'nav',
            'main' => 'main',
            'contentinfo' => 'footer',
            default => '',
        };
    }

    public static function templatePartArea(string $path, string $role): string
    {
        return match ( self::templatePartKind($path, $role) ) {
            'header'     => 'header',
            'footer'     => 'footer',
            'navigation' => 'navigation-overlay',
            default      => 'uncategorized',
        };
    }

    public static function templatePartTagName(string $path, string $role): string
    {
        if ( 'sidebar' === self::templatePartKind($path, $role) ) {
            return 'aside';
        }

        return self::templatePartAreaTagName(self::templatePartArea($path, $role));
    }

    public static function templatePartAreaTagName(string $area): string
    {
        return self::TEMPLATE_PART_AREA_TAGS[$area] ?? self::TEMPLATE_PART_AREA_TAGS['uncategorized'];
    }

    /**
     * Drop capture-time responsive correspondence tokens from shell identity.
     *
     * Desktop/mobile document pairs stamp per-element correspondence classes and
     * source attributes that do not change the authored chrome. Identity
     * comparison must ignore them so equivalent nested headers still cluster.
     */
    public static function withoutResponsiveCorrespondenceMarkup(string $markup): string
    {
        $markup = preg_replace('/\s*(?:' . preg_quote(CaptureEvidence::RESPONSIVE_COUNTERPART_CLASS_PREFIX, '/') . '|be-responsive-counterpart-)[a-f0-9]+/', '', $markup) ?? $markup;
        $markup = preg_replace('/\s*' . preg_quote(CaptureEvidence::RESPONSIVE_SOURCE, '/') . '="[^"]*"/', '', $markup) ?? $markup;
        $markup = preg_replace('/class="\s+/', 'class="', $markup) ?? $markup;
        $markup = preg_replace('/"className":"\s+/', '"className":"', $markup) ?? $markup;
        $markup = preg_replace('/\s*class="\s*"/', '', $markup) ?? $markup;
        $markup = preg_replace('/,"className":""/', '', $markup) ?? $markup;
        $markup = preg_replace('/"className":"",/', '', $markup) ?? $markup;
        $markup = preg_replace('/\{"className":""\}/', '{}', $markup) ?? $markup;
        return $markup;
    }

    private static function templatePartKind(string $path, string $role): string
    {
        if ( preg_match('/\b(header|footer|sidebar|navigation)\b/i', $path . ' ' . $role, $match) ) {
            return strtolower($match[1]);
        }

        return '';
    }
}
