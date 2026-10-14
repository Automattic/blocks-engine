<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

/**
 * The one definition of engine marker classes.
 *
 * `AuthorStyleAnalysis::allocateMarker()` hands out classes shaped
 * `blocks-engine-<kind>-<seed>-<counter>`. Per-element markers take their seed
 * from the element's source context (`allocateContextMarker()`): the same
 * element under the same author CSS gets the same marker in every document, so
 * a stylesheet projected for two such documents is byte-identical, while any
 * difference a selector could observe yields a different marker. Without a
 * resolvable context the seed falls back to the compiled document. Source-tag
 * and attribute-state markers use a seed derived from the tag or the source
 * predicate they stand for.
 *
 * Consumers previously each kept their own list of kinds, and the lists drifted:
 * shell identity missed `semantic`, so identical chrome compiled per page never
 * clustered into a shared template part under staged compilation. The allocator
 * now rejects any kind this class does not declare, so a new kind cannot fall
 * outside the consumers' normalization.
 */
final class EngineMarker
{
    /** Fixed marker kinds. `source-<tag>` is the one parameterized kind. */
    public const DOCUMENT_KINDS = array(
        'attribute',
        'attribute-state',
        'control',
        'media-text-image',
        'native-button',
        'navigation-anchor',
        'navigation-item',
        'navigation-box',
        'navigation-artwork',
        'richtext',
        'root-child',
        'semantic',
        'table',
    );

    /** Regex body (no delimiters or anchors) matching any allocated marker. */
    public static function patternBody(): string
    {
        static $body = null;
        return $body ??= 'blocks-engine-(?:' . implode('|', array_map(static fn (string $kind): string => preg_quote($kind, '/'), self::DOCUMENT_KINDS)) . '|source-[a-z][a-z0-9-]*)-[a-f0-9]{12}-\d+';
    }

    public static function pattern(): string
    {
        return '/' . self::patternBody() . '/';
    }

    /**
     * The declared kind a marker was allocated for, or null.
     */
    public static function markerKind(string $marker): ?string
    {
        if ( ! str_starts_with($marker, 'blocks-engine-') ) {
            return null;
        }
        $body = substr($marker, strlen('blocks-engine-'));
        if ( 1 === preg_match('/^(source-[a-z][a-z0-9-]*)-[a-f0-9]{12}-\d+$/', $body, $match) ) {
            return $match[1];
        }
        foreach ( self::DOCUMENT_KINDS as $kind ) {
            if ( 1 === preg_match('/^' . preg_quote($kind, '/') . '-[a-f0-9]{12}-\d+$/', $body) ) {
                return $kind;
            }
        }
        return null;
    }

    /** Marker kinds whose every carrier also carries the kind's role class. */
    public const ROLE_KINDS = array('control', 'semantic');

    /**
     * Shared role class every element carrying `$marker` inherits, e.g.
     * `blocks-engine-role-control`, or '' for a kind without one. A projected
     * rule that only has to keep its authored arm off marker carriers excludes
     * them through this one constant-size class instead of enumerating each
     * per-element marker; the exclusion sits in `:not(:where())`, so it adds
     * no specificity.
     */
    public static function roleClass(string $marker): string
    {
        $kind = self::markerKind($marker);
        return null !== $kind && in_array($kind, self::ROLE_KINDS, true) ? 'blocks-engine-role-' . $kind : '';
    }

    /**
     * The marker followed by its role class, for a carrier's class list.
     *
     * @return list<string>
     */
    public static function withRole(string $marker): array
    {
        if ( '' === $marker ) {
            return array();
        }
        $role = self::roleClass($marker);
        return '' === $role ? array($marker) : array($marker, $role);
    }

    /** @param list<string> $markers @return list<string> */
    public static function roleClasses(array $markers): array
    {
        return array_values(array_unique(array_filter(array_map(static fn (string $marker): string => self::roleClass($marker), $markers))));
    }

    public static function isDeclaredKind(string $kind): bool
    {
        return in_array($kind, self::DOCUMENT_KINDS, true) || 1 === preg_match('/^source-[a-z][a-z0-9-]*$/D', $kind);
    }

    public static function matchesAny(string $text): bool
    {
        return 1 === preg_match(self::pattern(), $text);
    }

    /** @return list<string> */
    public static function all(string $text): array
    {
        return preg_match_all(self::pattern(), $text, $matches) ? $matches[0] : array();
    }

    /**
     * Text with every marker reduced to its kind: `blocks-engine-control-<seed>-7`
     * becomes `blocks-engine-control`. Use it to derive an identity that must
     * agree for the same element compiled in different documents.
     */
    public static function withoutDocumentSeeds(string $text): string
    {
        return preg_replace_callback(self::pattern(), static fn (array $match): string => (string) preg_replace('/-[a-f0-9]{12}-\d+$/', '', $match[0]), $text) ?? $text;
    }

    /**
     * Class that re-expresses a source element id for editor parity. Unlike the
     * document markers above it is stable across documents: it is the id.
     */
    public const EDITOR_ANCHOR_PREFIX = 'blocks-engine-editor-anchor-';

    public static function editorAnchorClass(string $id): string
    {
        return self::EDITOR_ANCHOR_PREFIX . $id;
    }

    /** The source id an editor-anchor class stands for, or null for any other class. */
    public static function editorAnchorId(string $class): ?string
    {
        return str_starts_with($class, self::EDITOR_ANCHOR_PREFIX) && strlen($class) > strlen(self::EDITOR_ANCHOR_PREFIX)
            ? substr($class, strlen(self::EDITOR_ANCHOR_PREFIX))
            : null;
    }
}
