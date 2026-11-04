<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Compiler-owned, source-preserving rule units. No DOM or cascade state. */
final class CssStylesheetStructureCache
{
    /** @var array<string, array<string, mixed>> */
    private array $sheets = array();

    /**
     * PHP retains the string's table hash: looking up an ingested artifact does
     * not run a cryptographic hash over its bytes on every walker invocation.
     * Content, not a caller-supplied path/hash, is authoritative (paths can change).
     *
     * @return array<string, mixed>
     */
    public function sheet(string $css): array
    {
        if (isset($this->sheets[$css])) return $this->sheets[$css];
        $units = array();
        for ($offset = 0, $length = strlen($css); $offset < $length;) {
            $boundary = $this->scanBoundary($css, $offset);
            $end = null === $boundary ? null : (';' === $css[$boundary] ? $boundary : $this->scanBrace($css, $boundary));
            $units[] = array(
                'offset' => $offset, 'boundary' => $boundary, 'end' => $end,
                'prelude' => null === $boundary ? '' : substr($css, $offset, $boundary - $offset),
                'body' => null === $end || $end === $boundary ? '' : substr($css, $boundary + 1, $end - $boundary - 1),
            );
            if (null === $end) break;
            $offset = $end + 1;
        }
        return $this->sheets[$css] = array('identity' => hash('sha256', $css), 'units' => $units, 'valid' => $this->scanWellFormed($css));
    }

    public function inputs(): int { return count($this->sheets); }
    public function parses(string $css): int { return isset($this->sheets[$css]) ? 1 : 0; }

    public function scanBoundary(string $css, int $offset): ?int
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

    public function scanBrace(string $css, int $openingBrace): ?int
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

    public function scanWellFormed(string $css): bool
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
