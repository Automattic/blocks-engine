<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;

/**
 * The source's dominant responsive breakpoints for theme.json `settings.viewport`.
 *
 * WordPress 7.1 reads `settings.viewport.mobile` and `settings.viewport.tablet`
 * (`WP_Theme_JSON::get_viewport_media_queries()`), turning them into
 * `@mobile` = `(width <= mobile)` and `@tablet` = `(mobile < width <= tablet)`
 * media queries for per-breakpoint block child layout and viewport-scoped
 * block styles. Core defaults to 480px/782px; a source that switches its
 * layouts at its own widths only matches those defaults by coincidence.
 *
 * While the theme projection walks the source stylesheets, every width media
 * query (`@media`) boundary that changes at least one layout-relevant
 * declaration on content elements is collected and scored by how many such
 * declarations change there. The analysis reuses the shared stylesheet walker;
 * it never reparses or rewrites CSS.
 *
 * Selection is deliberately conservative: a boundary qualifies only when it
 * holds at least 2 of the layout-changing declarations and at least a quarter
 * of their total, so incidental or one-off switches never move the viewport
 * and a source without a dominant boundary keeps the WordPress defaults.
 */
final class ResponsiveBreakpoints
{
    public const SCHEMA = 'blocks-engine/responsive-breakpoints/v1';

    /** Conditional changes to these properties mark a layout switch. */
    private const LAYOUT_PROPERTIES = array('display', 'grid-template-columns', 'grid-template-areas', 'flex-direction', 'flex-wrap', 'width', 'max-width', 'float', 'position');

    /** Candidate window for the tablet boundary, in px. */
    private const TABLET_MIN = 600.0;
    private const TABLET_MAX = 1200.0;
    /** Candidate window floor for the mobile boundary, in px; its ceiling is tablet - MOBILE_TABLET_GAP. */
    private const MOBILE_MIN = 360.0;
    private const MOBILE_TABLET_GAP = 120.0;
    /** A boundary qualifies only at this score and this share of the total layout-changing score. */
    private const MIN_SCORE = 2;
    private const MIN_SHARE = 0.25;
    /** Core defaults, used to break score ties toward the stock behavior. */
    private const DEFAULT_TABLET = 782.0;
    private const DEFAULT_MOBILE = 480.0;

    /**
     * Score every width media-query boundary that changes a layout-relevant
     * declaration, and select the dominant tablet/mobile boundaries.
     *
     * @param array<int,array<string,mixed>> $assets
     * @return array{viewport:array<string,string>,report:array<string,mixed>|null}
     */
    public static function fromAssets(array $assets): array
    {
        $candidates = self::merge(self::scores($assets));
        if (array() === $candidates) {
            return array('viewport' => array(), 'report' => null);
        }
        $total = array_sum(array_column($candidates, 'score'));
        $tablet = self::select($candidates, $total, self::TABLET_MIN, self::TABLET_MAX, self::DEFAULT_TABLET);
        $mobile = null === $tablet ? null : self::select($candidates, $total, self::MOBILE_MIN, $tablet - self::MOBILE_TABLET_GAP, self::DEFAULT_MOBILE);
        $chosen = array_filter(array(
            'tablet' => null === $tablet ? null : self::length($tablet),
            'mobile' => null === $mobile ? null : self::length($mobile),
        ), static fn(mixed $value): bool => null !== $value);
        $report = array(
            'schema' => self::SCHEMA,
            'chosen' => $chosen,
            'candidates' => array_map(static fn(array $candidate): array => array('width' => self::number($candidate['width']), 'score' => $candidate['score']), $candidates),
            'reason' => self::reason($total, $tablet, $mobile),
        );
        return array('viewport' => $chosen, 'report' => $report);
    }

    /**
     * Every width media-query boundary with the number of layout-relevant
     * declarations whose query switches there, ascending by width. Repeated
     * declarations of one property on one selector at one boundary count once,
     * so duplicated bundled rules cannot inflate a boundary.
     *
     * @param array<int,array<string,mixed>> $assets
     * @return list<array{width:float,score:int}>
     */
    private static function scores(array $assets): array
    {
        $scores = array();
        $counted = array();
        $visitor = new CssStylesheetTransformer();
        foreach ($assets as $asset) {
            if (!is_array($asset) || 'css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null)) continue;
            $visitor->visitStyleRules($asset['content'], static function (string $prelude, string $body, array $ancestors) use (&$scores, &$counted): void {
                $boundaries = self::widthBoundaries($ancestors);
                if (array() === $boundaries) return;
                foreach (self::declarations($body) as $property => $value) {
                    if (!in_array($property, self::LAYOUT_PROPERTIES, true)) continue;
                    foreach ($boundaries as $boundary) {
                        $key = self::length($boundary) . '|' . strtolower(trim($prelude)) . '|' . $property;
                        if (isset($counted[$key])) continue;
                        $counted[$key] = true;
                        $scores[self::length($boundary)] = array('width' => $boundary, 'score' => ($scores[self::length($boundary)]['score'] ?? 0) + 1);
                    }
                }
            });
        }
        $candidates = array_values($scores);
        usort($candidates, static fn(array $left, array $right): int => $left['width'] <=> $right['width']);
        return $candidates;
    }

    /**
     * Every width boundary (px) a rule's enclosing `@media` conditions switch
     * at. `max-width: N` sits at N; `min-width: N` normalizes to the
     * equivalent max-width boundary N - 1. Media Query Level 4 range syntax is
     * accepted in both directions; em and rem use the 16px media-query base.
     * Queries negated at the top level are skipped: a negated condition has no
     * single boundary its layout switch can be attributed to.
     *
     * @param list<string> $ancestors
     * @return list<float>
     */
    private static function widthBoundaries(array $ancestors): array
    {
        $boundaries = array();
        foreach ($ancestors as $ancestor) {
            $condition = strtolower(trim((string) preg_replace('#/\*.*?\*/#s', ' ', $ancestor)));
            if (!str_starts_with($condition, '@media')) continue;
            $condition = trim(substr($condition, 6));
            if (1 === preg_match('/^not\b|[,!\s]not\s/', $condition)) continue;
            if (!preg_match_all('/\(([^()]+)\)/', $condition, $features, PREG_SET_ORDER)) continue;
            foreach ($features as $feature) {
                foreach (self::featureBoundaries(trim((string) $feature[1])) as $boundary) {
                    if ($boundary > 0) $boundaries[] = $boundary;
                }
            }
        }
        return $boundaries;
    }

    /** @return list<float> */
    private static function featureBoundaries(string $feature): array
    {
        if (preg_match('/^(min|max)-width\s*:\s*([0-9.]+)(px|em|rem)$/', $feature, $match)) {
            return array(self::boundary((float) $match[2], $match[3], 'min' === $match[1]));
        }
        if (preg_match('/^width\s*(<=|>=|<|>)\s*([0-9.]+)(px|em|rem)$/', $feature, $match)) {
            return array(self::boundary((float) $match[2], $match[3], in_array($match[1], array('>=', '<'), true)));
        }
        if (preg_match('/^([0-9.]+)(px|em|rem)\s*(<=|>=|<|>)\s*width$/', $feature, $match)) {
            return array(self::boundary((float) $match[1], $match[2], in_array($match[3], array('<=', '>'), true)));
        }
        if (preg_match('/^([0-9.]+)(px|em|rem)\s*(<=|<)\s*width\s*(<=|<)\s*([0-9.]+)(px|em|rem)$/', $feature, $match)) {
            return array(self::boundary((float) $match[1], $match[2], '<=' === $match[3]), self::boundary((float) $match[5], $match[6], '<' === $match[4]));
        }
        return array();
    }

    /**
     * The equivalent max-width boundary of one width comparison: an inclusive
     * minimum (`width >= N`, `min-width: N`) switches just below N.
     */
    private static function boundary(float $value, string $unit, bool $exclusive): float
    {
        return $value * ('px' === $unit ? 1.0 : 16.0) - ($exclusive ? 1.0 : 0.0);
    }

    /**
     * Merge boundaries within 1px of each other: `max-width: 600px` and
     * `min-width: 600px` describe the same switch (at 599/600px), as do
     * sources that mix both conventions. The merged boundary keeps the
     * larger-scoring width and the summed score.
     *
     * @param list<array{width:float,score:int}> $candidates Ascending by width.
     * @return list<array{width:float,score:int}>
     */
    private static function merge(array $candidates): array
    {
        $merged = array();
        foreach ($candidates as $candidate) {
            $last = count($merged) - 1;
            if ($last >= 0 && $candidate['width'] - $merged[$last]['width'] <= 1.0) {
                if ($candidate['score'] > $merged[$last]['own']) {
                    $merged[$last]['width'] = $candidate['width'];
                    $merged[$last]['own'] = $candidate['score'];
                }
                $merged[$last]['score'] += $candidate['score'];
                continue;
            }
            $merged[] = $candidate + array('own' => $candidate['score']);
        }
        return array_map(static fn(array $candidate): array => array('width' => $candidate['width'], 'score' => $candidate['score']), $merged);
    }

    /**
     * The highest-scoring boundary inside the candidate window, ties broken
     * toward the WordPress default. Null when no boundary qualifies.
     *
     * @param list<array{width:float,score:int}> $candidates
     */
    private static function select(array $candidates, int $total, float $minimum, float $maximum, float $default): ?float
    {
        $best = null;
        $bestScore = 0;
        foreach ($candidates as $candidate) {
            $boundary = $candidate['width'];
            $score = $candidate['score'];
            if ($boundary < $minimum || $boundary > $maximum || $score < self::MIN_SCORE || $score * 4 < $total) continue;
            if ($score > $bestScore || ($score === $bestScore && null !== $best && abs($boundary - $default) < abs($best - $default))) {
                $best = $boundary;
                $bestScore = $score;
            }
        }
        return $best;
    }

    /**
     * Top-level declarations of one rule body, lower-cased and unvalidated: the
     * boundary analysis counts authored layout switches, not representable
     * values, so even a forced declaration is evidence that the layout changes.
     *
     * @return array<string,string>
     */
    private static function declarations(string $body): array
    {
        $declarations = array();
        foreach (CssValueSplitter::splitTopLevel($body, array(';')) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (2 !== count($parts)) continue;
            $name = strtolower(trim($parts[0]));
            $value = trim($parts[1]);
            if ('' !== $name && '' !== $value) $declarations[$name] = $value;
        }
        return $declarations;
    }

    /** A whole-pixel boundary as an integer, a fractional one as a float. */
    private static function number(float $px): int|float
    {
        return fmod($px, 1.0) === 0.0 ? (int) $px : $px;
    }

    /** A px length the way WordPress theme.json expects it: `900px`, `56.5px`. */
    private static function length(float $px): string
    {
        return (string) self::number($px) . 'px';
    }

    private static function reason(int $total, ?float $tablet, ?float $mobile): string
    {
        $suffix = ' of ' . $total . ' layout-changing declarations';
        $share = 'at least ' . self::MIN_SCORE . ' declarations and at least 25%' . $suffix;
        if (null !== $tablet && null !== $mobile) {
            return 'tablet ' . self::length($tablet) . ' and mobile ' . self::length($mobile) . ' are the dominant layout-switch boundaries: each holds ' . $share . '.';
        }
        if (null !== $tablet) {
            return 'tablet ' . self::length($tablet) . ' qualifies but no boundary between ' . self::length(self::MOBILE_MIN) . ' and ' . self::length($tablet - self::MOBILE_TABLET_GAP) . ' holds ' . $share . ', so mobile keeps the WordPress default.';
        }
        return 'No boundary between ' . self::length(self::TABLET_MIN) . ' and ' . self::length(self::TABLET_MAX) . ' holds ' . $share . ', so the WordPress viewport defaults apply.';
    }
}
