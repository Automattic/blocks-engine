<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

/**
 * The one definition of document-scoped engine marker classes.
 *
 * `AuthorStyleAnalysis::allocateMarker()` hands out classes shaped
 * `blocks-engine-<kind>-<document seed>-<counter>`. The seed is derived from the
 * compiled document, so the same element compiled in two documents (two routes,
 * or the whole-artifact and staged compilation paths) carries different
 * markers. Anything that compares output across documents must ignore them.
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
