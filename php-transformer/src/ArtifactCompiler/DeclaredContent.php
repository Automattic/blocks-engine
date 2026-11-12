<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\PublicationDate;

/**
 * An artifact file's declared content record (`metadata.declared_content`):
 * the source slug, publication and modification dates, author, taxonomy
 * terms, and whether the file is a term archive. Invalid fields are dropped;
 * a record with no valid field is dropped entirely.
 */
final class DeclaredContent
{
    public const PROVENANCE = 'metadata:content_index';

    /** @return array{slug?:string,published?:string,updated?:string,author?:string,terms?:list<string>,term_archive?:array{name:string}}|null */
    public static function sanitize(mixed $value): ?array
    {
        if (!is_array($value)) return null;
        $declared = array();
        $slug = is_string($value['slug'] ?? null) ? strtolower(trim($value['slug'])) : '';
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,199}$/', $slug)) $declared['slug'] = $slug;
        foreach (array('published', 'updated') as $key) {
            $date = is_string($value[$key] ?? null) ? trim($value[$key]) : '';
            if ('' !== $date && null !== PublicationDate::normalize($date)) $declared[$key] = $date;
        }
        $author = self::name($value['author'] ?? null);
        if (null !== $author) $declared['author'] = $author;
        if (is_array($value['terms'] ?? null)) {
            $terms = array();
            foreach (array_slice(array_values($value['terms']), 0, 256) as $term) {
                $name = self::name($term);
                if (null !== $name) $terms[$name] = true;
            }
            if (array() !== $terms) $declared['terms'] = array_map('strval', array_keys($terms));
        }
        $archiveName = is_array($value['term_archive'] ?? null) ? self::name($value['term_archive']['name'] ?? null) : null;
        if (null !== $archiveName) $declared['term_archive'] = array('name' => $archiveName);
        return array() === $declared ? null : $declared;
    }

    private static function name(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $name = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        return '' === $name || mb_strlen($name, 'UTF-8') > 200 ? null : $name;
    }
}
