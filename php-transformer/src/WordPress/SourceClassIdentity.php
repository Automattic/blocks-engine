<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPress;

/** Authored identities distinct from classes WordPress generates at runtime. */
final class SourceClassIdentity
{
    public static function needsMarker(string $class): bool
    {
        // Core emits these theme.json utilities for destination presets too.
        // Project source selectors onto an identity only source elements carry,
        // so a newly bound block cannot join a carried !important utility rule.
        return GeneratedGutenbergClassPolicy::isGeneratedClassName($class)
            || 1 === preg_match('/^has-[a-zA-Z0-9_-]+-(?:color|background-color|border-color|gradient-background|font-size|font-family)$/', $class)
            || in_array($class, array('archive', 'attachment', 'author', 'blog', 'category', 'date', 'error404', 'home', 'page', 'paged', 'privacy-policy', 'search', 'single', 'tag', 'rtl', 'wp-singular', 'page-parent', 'page-child', 'post-type-archive', 'search-results', 'search-no-results', 'logged-in', 'admin-bar', 'customize-support', 'no-customize-support', 'custom-background', 'wp-custom-logo', 'wp-embed-responsive', 'editor-styles-wrapper', 'block-editor-iframe__body', 'is-root-container'), true)
            // get_body_class() generates template identities for every public
            // post type, and route/term/theme identities within these families.
            || 1 === preg_match('/^(?:[a-z0-9_-]+-template(?:-.+)?|(?:page-id|postid|attachmentid|parent-pageid|paged)-[0-9]+|(?:single|attachment|category|tag|author|tax|term|post-type-archive|post-type-paged|page-paged|date-paged|search-paged|wp-theme|wp-child-theme)-.+)$/', $class);
    }

    /** Classes that mark the route being served, not what an element is. */
    public const CURRENT_STATE_CLASSES = array('current', 'active', 'selected');

    public static function isCurrentState(string $class): bool
    {
        return in_array($class, self::CURRENT_STATE_CLASSES, true);
    }

    /** Whether a class or id names a current state by one of its tokens (`is-current`, `menu-item--selected`). */
    public static function containsCurrentStateToken(string $value): bool
    {
        return array() !== array_intersect(preg_split('/[^a-z0-9]+/', strtolower($value)) ?: array(), self::CURRENT_STATE_CLASSES);
    }

    /** Classes a site builder adds to disclose an item, such as the served one. */
    public const DISCLOSURE_CLASSES = array('open', 'is-open', 'expanded', 'is-expanded');

    /**
     * The classes of a source element that mark the route being served. An
     * element marked current (`selected current-item`), or a list item
     * holding the served link (`aria-current`), is the served item or its
     * ancestor, and the builder discloses it too (`expanded`): that
     * disclosure is route state with it, not what the element is.
     *
     * @return list<string>
     */
    public static function routeState(\DOMElement $element): array
    {
        $classes = preg_split('/\s+/', trim($element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: array();
        $current = array_values(array_filter($classes, self::containsCurrentStateToken(...)));
        if (array() === $current && !self::holdsServedLink($element)) return array();
        return array_values(array_unique(array_merge($current, array_intersect($classes, self::DISCLOSURE_CLASSES))));
    }

    private static function holdsServedLink(\DOMElement $element): bool
    {
        if ('li' !== strtolower($element->tagName)) return false;
        foreach ($element->getElementsByTagName('a') as $link) {
            if ($link->hasAttribute('aria-current') && !in_array(strtolower(trim($link->getAttribute('aria-current'))), array('', 'false'), true)) return true;
        }
        return false;
    }

    public static function marker(string $class): string
    {
        return self::needsMarker($class) ? 'blocks-engine-source-class-' . substr(hash('sha256', $class), 0, 12) . '-0' : '';
    }

    /** @param array<string,string> $attributes @return array<string,string> */
    public static function projectRoot(array $attributes): array
    {
        $classes = preg_split('/\s+/', trim($attributes['class'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: array();
        foreach ($classes as $class) if ('' !== ($marker = self::marker($class))) $classes[] = $marker;
        if ($classes) $attributes['class'] = implode(' ', array_unique($classes));
        return $attributes;
    }
}
