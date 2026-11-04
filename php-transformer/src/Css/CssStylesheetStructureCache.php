<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Content-addressed source structure. No DOM, projection or cascade results live here. */
final class CssStylesheetStructureCache
{
    private static ?self $active = null;
    /** @var array<string, array<string, mixed>> */
    private array $sheets = array();
    /** @var array<string, int> */
    private array $parseCounts = array();
    private ?string $lastCss = null;
    private string $lastKey = '';

    public static function activate(?self $cache): ?self
    {
        $previous = self::$active;
        self::$active = $cache;
        return $previous;
    }

    /** @return array<string, mixed> */
    private function &sheet(string $css): array
    {
        // Walkers query consecutive offsets in the same string. Avoid hashing
        // the whole sheet again for every rule (including on cache hits).
        if ( $css === $this->lastCss ) {
            return $this->sheets[$this->lastKey];
        }
        $key = hash('sha256', $css);
        $this->lastCss = $css;
        $this->lastKey = $key;
        if ( ! isset($this->sheets[$key]) ) {
            $this->parseCounts[$key] = ($this->parseCounts[$key] ?? 0) + 1;
            $this->sheets[$key] = array('boundaries' => array(), 'braces' => array());
            $this->indexBlock($css, 0, strlen($css), $this->sheets[$key]);
        }
        return $this->sheets[$key];
    }

    /**
     * Build source-preserving block offsets. Nested bodies are content-addressed
     * separately when queried, so identical bodies share their structure too.
     *
     * @param array<string, mixed> $sheet
     */
    private function indexBlock(string $css, int $start, int $end, array &$sheet): void
    {
        for ( $offset = $start; $offset < $end; ) {
            $boundary = $this->scanBoundary($css, $offset);
            $sheet['boundaries'][$offset] = $boundary;
            if ( null === $boundary || $boundary >= $end ) {
                return;
            }
            if ( ';' === $css[$boundary] ) {
                $offset = $boundary + 1;
                continue;
            }
            $close = $this->scanBrace($css, $boundary);
            $sheet['braces'][$boundary] = $close;
            if ( null === $close ) {
                return;
            }
            $offset = $close + 1;
        }
    }

    public function inputs(): int
    {
        return count($this->sheets);
    }

    /** Distinct source identities observed by this owner, including malformed inputs. */
    public function parses(string $css): int
    {
        return $this->parseCounts[hash('sha256', $css)] ?? 0;
    }

    public static function boundary(string $css, int $offset): ?int
    {
        if ( null === self::$active ) {
            return (new self())->scanBoundary($css, $offset);
        }
        $cache = self::$active;
        $sheet =& $cache->sheet($css);
        if ( ! array_key_exists($offset, $sheet['boundaries']) ) {
            $sheet['boundaries'][$offset] = $cache->scanBoundary($css, $offset);
        }
        return $sheet['boundaries'][$offset];
    }

    public static function brace(string $css, int $offset): ?int
    {
        if ( null === self::$active ) {
            return (new self())->scanBrace($css, $offset);
        }
        $cache = self::$active;
        $sheet =& $cache->sheet($css);
        if ( ! array_key_exists($offset, $sheet['braces']) ) {
            $sheet['braces'][$offset] = $cache->scanBrace($css, $offset);
        }
        return $sheet['braces'][$offset];
    }

    public static function wellFormed(string $css): bool
    {
        if ( null === self::$active ) {
            return (new self())->scanWellFormed($css);
        }
        $cache = self::$active;
        $sheet =& $cache->sheet($css);
        return $sheet['valid'] ??= $cache->scanWellFormed($css);
    }

    private function scanBoundary(string $css, int $offset): ?int
    {
        $state  = CssSyntaxScanner::state();
        $length = strlen($css);
        for ( $index = $offset; $index < $length; ++$index ) {
            $index = CssSyntaxScanner::inertEnd($css, $index, $state, '{;');
            if ( $index >= $length ) break;
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            $next = CssSyntaxScanner::consume($css, $index, $state);
            if ( null === $next ) {
                return null;
            }
            if ( $topLevel && $next === $index + 1 && ('{' === $css[ $index ] || ';' === $css[ $index ]) ) {
                return $index;
            }
            $index = $next - 1;
        }

        return null;
    }

    private function scanBrace(string $css, int $openingBrace): ?int
    {
        $state  = CssSyntaxScanner::state();
        $depth  = 0;
        $length = strlen($css);
        for ( $index = $openingBrace; $index < $length; ++$index ) {
            $index = CssSyntaxScanner::inertEnd($css, $index, $state, '{}');
            if ( $index >= $length ) break;
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            $next = CssSyntaxScanner::consume($css, $index, $state);
            if ( null === $next ) {
                return null;
            }
            if ( $topLevel && $next === $index + 1 && '{' === $css[ $index ] ) {
                ++$depth;
            } elseif ( $topLevel && $next === $index + 1 && '}' === $css[ $index ] && 0 === --$depth ) {
                return $index;
            }
            $index = $next - 1;
        }

        return null;
    }

    private function scanWellFormed(string $css): bool
    {
        $state = CssSyntaxScanner::state();
        $braces = 0;
        $length = strlen($css);
        for ( $offset = 0; $offset < $length; ) {
            $offset = CssSyntaxScanner::inertEnd($css, $offset, $state, '{}');
            if ( $offset >= $length ) break;
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            $next = CssSyntaxScanner::consume($css, $offset, $state);
            if ( null === $next ) {
                return false;
            }
            if ( $topLevel && $next === $offset + 1 ) {
                if ( '{' === $css[ $offset ] ) {
                    ++$braces;
                } elseif ( '}' === $css[ $offset ] ) {
                    if ( --$braces < 0 ) {
                        return false;
                    }
                }
            }
            $offset = $next;
        }
        return 0 === $braces && CssSyntaxScanner::isComplete($state);
    }

}
