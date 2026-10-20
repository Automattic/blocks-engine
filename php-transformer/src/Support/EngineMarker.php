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
 * Control, semantic and RichText carriers also carry their kind's role class
 * and the subject classes of their source element (see subjectTagClass()). A
 * RichText carrier states its marker in its style or data attribute, not as a
 * class. Neither the role nor the subject classes are markers: both are the same on every page, so arms that only need "every
 * carrier of this kind" or "every carrier whose source matches this
 * context-free compound" name them instead of enumerating markers.
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
    public const ROLE_KINDS = array('control', 'richtext', 'semantic');

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

    /** @param list<string> $markers @return list<string> */
    public static function roleClasses(array $markers): array
    {
        return array_values(array_unique(array_filter(array_map(static fn (string $marker): string => self::roleClass($marker), $markers))));
    }

    /**
     * Every carrier with subject classes also carries this class, so even a
     * universal subject (`*`) addresses only carriers that state their source;
     * a carrier withheld from subject arms keeps only its marker and role.
     */
    public const SUBJECT_BASE_CLASS = 'blocks-engine-subject';
    public const SUBJECT_TAG_PREFIX = 'blocks-engine-subject-tag-';
    public const SUBJECT_CLASS_PREFIX = 'blocks-engine-subject-class-';

    /**
     * Class restating a carrier's source tag, e.g. `blocks-engine-subject-tag-a`.
     *
     * Subject classes describe the source element a marker carrier stands for
     * by what a context-free compound (`a`, `.btn`, `a.btn`, `*`) can test: its
     * tag and its class tokens. They depend on nothing else, so they are the
     * same on every page and under any author CSS. A projected arm that has to
     * reach every carrier whose source matches such a compound names these
     * classes once instead of enumerating each carrier's per-element marker,
     * which differs on every page that has other content.
     */
    public static function subjectTagClass(string $tagName): string
    {
        $tagName = strtolower($tagName);
        return self::SUBJECT_TAG_PREFIX . (1 === preg_match('/^[a-z][a-z0-9-]*$/D', $tagName) ? $tagName : 'x' . substr(hash('sha256', $tagName), 0, 12));
    }

    /** Class restating one source class token; hashed, so any token is a valid class. */
    public static function subjectClassClass(string $className): string
    {
        return self::SUBJECT_CLASS_PREFIX . substr(hash('sha256', $className), 0, 12);
    }

    /**
     * Subject classes for a source element with this tag and class tokens.
     *
     * @param list<string> $classTokens
     * @return list<string>
     */
    public static function subjectClasses(string $tagName, array $classTokens): array
    {
        $classes = array(self::SUBJECT_BASE_CLASS, self::subjectTagClass($tagName));
        foreach ( array_values(array_unique($classTokens)) as $token ) {
            if ( '' !== $token ) {
                $classes[] = self::subjectClassClass($token);
            }
        }
        return $classes;
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
