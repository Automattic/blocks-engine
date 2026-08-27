<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\StaticSite\FontMaterialization;

/**
 * Bounded cache for immutable CSS-derived font analysis.
 *
 * Custom-property values and `font-family` declarations are pure functions of
 * the stylesheet text. One site stylesheet is scanned by several typography
 * consumers on every page, so the derived analysis is keyed by content and
 * retained for the compile that shares it.
 */
final class CssFontAnalysisCache
{
    // Font analysis retains selector strings rather than parsed graphs, so a
    // small entry budget covers one site's stylesheet set without growth.
    private const MAX_ENTRIES = 8;

    /** @var array<string, array<string, string>> */
    private array $variables = array();

    /** @var array<string, list<array{family:string,selector:string,source_snippet:string}>> */
    private array $declarations = array();

    public int $variableBuilds = 0;

    public int $variableHits = 0;

    public int $declarationBuilds = 0;

    public int $declarationHits = 0;

    /**
     * @param list<string> $stylesheets
     * @param callable(): array<string, string> $build
     * @return array<string, string>
     */
    public function variables(array $stylesheets, callable $build): array
    {
        $key = self::key($stylesheets);
        if ( array_key_exists($key, $this->variables) ) {
            ++$this->variableHits;
            return $this->variables[$key];
        }

        ++$this->variableBuilds;
        $variables = $build();
        self::retain($this->variables, $key, $variables);

        return $variables;
    }

    /**
     * @param list<string> $stylesheets
     * @param callable(): list<array{family:string,selector:string,source_snippet:string}> $build
     * @return list<array{family:string,selector:string,source_snippet:string}>
     */
    public function declarations(array $stylesheets, callable $build): array
    {
        $key = self::key($stylesheets);
        if ( array_key_exists($key, $this->declarations) ) {
            ++$this->declarationHits;
            return $this->declarations[$key];
        }

        ++$this->declarationBuilds;
        $declarations = $build();
        self::retain($this->declarations, $key, $declarations);

        return $declarations;
    }

    /** @param list<string> $stylesheets */
    private static function key(array $stylesheets): string
    {
        $key = '';
        foreach ( $stylesheets as $stylesheet ) {
            $key .= hash('sha256', $stylesheet) . "\0";
        }

        return $key;
    }

    /** @param array<string, mixed> $entries */
    private static function retain(array &$entries, string $key, mixed $value): void
    {
        // PHP arrays retain insertion order, making this a compact LRU queue.
        while ( count($entries) >= self::MAX_ENTRIES ) {
            unset($entries[array_key_first($entries)]);
        }
        $entries[$key] = $value;
    }
}
