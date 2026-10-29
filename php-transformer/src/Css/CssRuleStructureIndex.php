<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Source offsets only; nested bodies are indexed lazily by their own text. */
final class CssRuleStructureIndex
{
    private const MAX_CACHE_ENTRIES = 4096;
    private const MAX_CACHE_BYTES = 16777216;

    /** @var array<string, self> */
    private static array $cache = array();
    private static int $cacheBytes = 0;

    /**
     * A null end retains the old walkers' unterminated-block sentinel.
     * The prelude occupies [start, boundary); a statement ends at boundary.
     *
     * @var list<array{start: int, boundary: int, end: ?int}>
     */
    public array $entries = array();
    public bool $wellFormed = false;

    public static function forText(string $css): self
    {
        $key = hash('sha256', $css);
        if ( isset(self::$cache[$key]) ) {
            return self::$cache[$key];
        }
        $index = new self($css);
        // PHP arrays have substantial per-entry overhead. Charge conservatively
        // for it as well as the input bytes, without retaining the source text.
        $bytes = strlen($css) + 1024 * (count($index->entries) + 1);
        if ( $bytes > self::MAX_CACHE_BYTES ) {
            return $index;
        }
        // Batch eviction, like StyleResolver's declaration memo: no LRU index.
        if ( count(self::$cache) >= self::MAX_CACHE_ENTRIES || self::$cacheBytes + $bytes > self::MAX_CACHE_BYTES ) {
            self::$cache = array();
            self::$cacheBytes = 0;
        }
        self::$cacheBytes += $bytes;
        self::$cache[$key] = $index;
        return $index;
    }

    private function __construct(string $css)
    {
        $state = CssSyntaxScanner::state();
        $length = strlen($css);
        $start = 0;
        $depth = 0;
        $braces = 0;
        $valid = true;
        $block = null;
        for ( $offset = 0; $offset < $length; ) {
            $offset = CssSyntaxScanner::inertEnd($css, $offset, $state, '{};');
            if ( $offset >= $length ) break;
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            $next = CssSyntaxScanner::consume($css, $offset, $state);
            if ( null === $next ) {
                return;
            }
            if ( $topLevel && $next === $offset + 1 ) {
                $token = $css[$offset];
                if ( '{' === $token ) {
                    ++$braces;
                    if ( 0 === $depth++ ) {
                        $block = count($this->entries);
                        $this->entries[] = array('start' => $start, 'boundary' => $offset, 'end' => null);
                    }
                } elseif ( '}' === $token ) {
                    if ( --$braces < 0 ) $valid = false;
                    // A stray close was not a nextRuleBoundary delimiter.
                    if ( $depth > 0 && 0 === --$depth ) {
                        $this->entries[$block]['end'] = $offset;
                        $start = $next;
                    }
                } elseif ( ';' === $token && 0 === $depth ) {
                    $this->entries[] = array('start' => $start, 'boundary' => $offset, 'end' => $offset);
                    $start = $next;
                }
            }
            $offset = $next;
        }
        $this->wellFormed = $valid && 0 === $braces && CssSyntaxScanner::isComplete($state);
    }
}
