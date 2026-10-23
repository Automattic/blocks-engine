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
