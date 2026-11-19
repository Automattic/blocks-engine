<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSyntaxScanner;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;

/**
 * Selector arms that require `.editor-styles-wrapper` (outside any functional
 * pseudo-class) style only the block editor canvas: no rendered front-end
 * page carries that class. Projection writes those editor mirrors into the
 * same stylesheet the front end loads, so every visitor downloads them.
 *
 * Those arms move out of a stylesheet delivered to both contexts into an
 * editor-only extract enqueued directly after it. The extract holds only the
 * moved arms, never a copy of the sheet, so the theme does not grow. In the
 * editor the moved arms now follow the rest of their sheet; an arm moves only
 * when no rule that stays after it declares a property it declares with the
 * same importance at an equal specificity, so no tie changes winner. Relative
 * `url()`s resolve against the source sheet's origin.
 *
 * Sheets whose delivery is shared with other rows (parts, replays), that
 * another sheet `@import`s, or that order rules by cascade layer stay whole.
 */
final class EditorCanvasStylesheets
{
    public const EDITOR_SUFFIX = '.editor-canvas.css';
    /** Below this, an extract saves visitors less than an editor request costs. */
    private const MIN_EXTRACT_BYTES = 200;
    private const EDITOR_ARM = '/(?<!\\\\)\.editor-styles-wrapper(?![\w\\\\-])/';
    /** Shorthand families whose members may override one another. */
    private const FAMILY = array('top' => 'inset', 'right' => 'inset', 'bottom' => 'inset', 'left' => 'inset', 'white' => 'text', 'line' => 'font', 'row' => 'gap', 'column' => 'gap', 'columns' => 'gap', 'align' => 'place', 'justify' => 'place', 'width' => 'size', 'height' => 'size', 'min' => 'size', 'max' => 'size', 'block' => 'size', 'inline' => 'size');

    /**
     * @param list<array<string,mixed>> $assets
     * @return list<array<string,mixed>>
     */
    public static function split(array $assets): array
    {
        $targets = $sources = $partTargets = array();
        foreach ($assets as $asset) {
            $targets[strtolower((string) ($asset['target_path'] ?? ''))] = true;
            $sources[(string) ($asset['source_path'] ?? '')] = true;
            foreach ($asset['stylesheet_parts'] ?? array() as $path) $partTargets[(string) $path] = true;
        }
        $split = array();
        foreach ($assets as $asset) {
            $target = (string) ($asset['target_path'] ?? '');
            $delivery = (string) ($asset['stylesheet_target'] ?? 'both');
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null) || isset($asset['payload_reference']) || 'editor' === $delivery
                || isset($asset['stylesheet_parts']) || isset($asset['stylesheet_replays']) || isset($partTargets[$target]) || self::imported($asset)
                || 1 !== preg_match(self::EDITOR_ARM, $asset['content']) || 1 === preg_match('/@(?:layer|import|scope)\b/i', $asset['content'])) {
                $split[] = $asset;
                continue;
            }
            $editorTarget = (string) preg_replace('/\.css$/i', '', $target) . self::EDITOR_SUFFIX;
            $editorSource = (string) preg_replace('/\.css$/i', '', (string) ($asset['source_path'] ?? '')) . self::EDITOR_SUFFIX;
            $parts = self::separate($asset['content'], 'frontend' === $delivery);
            if (null === $parts || isset($targets[strtolower($editorTarget)]) || isset($sources[$editorSource])) { $split[] = $asset; continue; }
            [$frontend, $editorOnly] = $parts;
            // A handful of bytes is not worth another editor request.
            if ('frontend' !== $delivery && strlen($editorOnly) < self::MIN_EXTRACT_BYTES) { $split[] = $asset; continue; }
            $split[] = CompiledAssets::withRewrittenTextContent($asset, $frontend);
            if ('frontend' === $delivery) continue;
            $targets[strtolower($editorTarget)] = $sources[$editorSource] = true;
            $editor = CompiledAssets::withRewrittenTextContent($asset, $editorOnly);
            $editor['path'] = $editorTarget;
            $editor['target_path'] = $editorTarget;
            $editor['stylesheet_target'] = 'editor';
            $editor['token'] = 'asset-' . substr(hash('sha256', $editorTarget), 0, 16);
            // Plan tokens resolve one source identity each; URLs inside the
            // extract still resolve against the source sheet's origin.
            $editor['source_path'] = $editorSource;
            $editor['reference_origin'] = $asset['reference_origin'] ?? $asset['source_path'];
            $editor['reconciliation_identity'] = WordPressSitePlan::identity('asset', $editorSource, $editorTarget);
            unset($editor['frame_fallback'], $editor['frame_fallback_handle']);
            $split[] = $editor;
        }
        return self::withExtractPositions($split);
    }

    /**
     * Each extract replays directly after its source sheet in every document
     * that links the sheet: it takes the next position and later positions
     * shift by one, so every position still holds one resource per context.
     *
     * @param list<array<string,mixed>> $assets
     * @return list<array<string,mixed>>
     */
    private static function withExtractPositions(array $assets): array
    {
        $slots = array();
        foreach ($assets as $index => $asset) {
            $extract = str_ends_with((string) ($asset['source_path'] ?? ''), self::EDITOR_SUFFIX) && 'editor' === ($asset['stylesheet_target'] ?? null);
            foreach ($asset['stylesheet_instances'] ?? array() as $position => $instance) {
                if (is_array($instance) && is_int($instance['order'] ?? null)) $slots[(string) $instance['source_path']][] = array($instance['order'], $extract ? 1 : 0, $index, $position);
            }
        }
        foreach ($slots as $entries) {
            if (!in_array(1, array_column($entries, 1), true)) continue;
            usort($entries, static fn(array $left, array $right): int => $left[0] <=> $right[0] ?: $left[1] <=> $right[1]);
            foreach ($entries as $order => [, , $index, $position]) $assets[$index]['stylesheet_instances'][$position]['order'] = $order;
        }
        return $assets;
    }

    /**
     * The sheet without its movable editor-only arms, and those arms as an
     * editor stylesheet; null when nothing moves. A front-end-only sheet
     * drops every editor-only arm.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function separate(string $css, bool $frontendOnly = false): ?array
    {
        $transformer = new CssStylesheetTransformer();
        $rules = array();
        $transformer->visitStyleRules($css, static function (string $selector, string $body, array $parents) use (&$rules): void {
            $rules[] = array('selector' => $selector, 'body' => $body, 'parents' => $parents);
        });
        // Decide from the end: a rule that stays shadows the properties it
        // declares for every editor-only arm before it.
        $staying = array();
        $moves = array();
        for ($index = count($rules) - 1; 0 <= $index; --$index) {
            $rule = $rules[$index];
            $declarations = self::declarations($rule['body']);
            $arms = str_contains($rule['body'], '{') || 1 !== preg_match(self::EDITOR_ARM, $rule['selector']) ? null : CssStylesheetTransformer::splitSelectorList($rule['selector']);
            $editorArms = array();
            foreach ($arms ?? array() as $position => $arm) {
                if ('' === trim(AbsentCarrierSelectors::withoutArmsRequiring(trim($arm) . '{}', self::EDITOR_ARM))) $editorArms[$position] = true;
            }
            $weights = array();
            foreach ($arms ?? array($rule['selector']) as $position => $arm) $weights[$position] = self::weight($arm);
            $moved = array_intersect_key($weights, $editorArms);
            $movable = array() !== $editorArms && null !== $declarations && ($frontendOnly || !self::shadowed($declarations, $moved, $staying));
            if ($movable) $moves[$index] = array('arms' => $arms, 'editor' => $editorArms);
            $remaining = $movable ? array_diff_key($weights, $editorArms) : $weights;
            foreach ($declarations ?? array(array('*', false), array('*', true)) as [$family, $important]) {
                foreach ($remaining as $weight) $staying[$family][$important ? 1 : 0][$weight] = true;
            }
        }
        if (array() === $moves) return null;
        $index = -1;
        $frontend = $transformer->transformStyleRules($css, static function (string $prelude, string $body) use (&$index, $moves): string {
            ++$index;
            if (!isset($moves[$index])) return $prelude . '{' . $body . '}';
            $kept = array();
            foreach ($moves[$index]['arms'] as $position => $arm) {
                if (!isset($moves[$index]['editor'][$position])) $kept[] = $arm;
            }
            if (array() === $kept) return '';
            if (!str_starts_with(ltrim($kept[0]), '/*') && preg_match('#^(?:\s*/\*.*?\*/)+\s*#s', $prelude, $leading)) $kept[0] = $leading[0] . ltrim($kept[0]);
            return implode(',', $kept) . '{' . $body . '}';
        });
        if ($index !== count($rules) - 1) return null;
        do {
            $frontend = (string) preg_replace('/@(?:-webkit-)?(?:media|supports|container|document)\b[^{};]*\{\s*\}/i', '', $frontend, -1, $count);
        } while (0 < $count);
        $editor = '';
        ksort($moves);
        foreach ($moves as $ruleIndex => $move) {
            $arms = array();
            foreach ($move['arms'] as $position => $arm) {
                if (isset($move['editor'][$position])) $arms[] = trim(CssSyntaxScanner::withoutComments($arm));
            }
            $text = implode(',', $arms) . '{' . $rules[$ruleIndex]['body'] . '}';
            foreach (array_reverse($rules[$ruleIndex]['parents']) as $parent) $text = $parent . '{' . $text . '}';
            $editor .= $text;
        }
        return array($frontend, $editor);
    }

    /**
     * Property families and importance a block declares, or null when it
     * cannot be read declaration by declaration.
     *
     * @return list<array{0: string, 1: bool}>|null
     */
    private static function declarations(string $body): ?array
    {
        $declarations = array();
        foreach (CssValueSplitter::splitTopLevel(CssSyntaxScanner::withoutComments($body), array(';')) as $declaration) {
            if ('' === trim($declaration)) continue;
            if (1 !== preg_match('/^\s*(-{0,2}[A-Za-z_][\w-]*)\s*:(.*)$/s', $declaration, $match)) return null;
            $property = strtolower($match[1]);
            if (str_starts_with($property, '--')) $family = $property;
            else {
                $property = (string) preg_replace('/^-(?:webkit|moz|ms|o)-/', '', $property);
                $family = explode('-', $property)[0];
                $family = 'all' === $family ? '*' : (self::FAMILY[$family] ?? $family);
            }
            $declarations[] = array($family, 1 === preg_match('/!\s*important\s*$/i', $match[2]));
        }
        return $declarations;
    }

    /**
     * Whether moving arms of these weights past the staying rules could
     * change a tie: a staying rule declares the same property family with the
     * same importance at an equal (or unreadable) specificity.
     *
     * @param list<array{0: string, 1: bool}> $declarations
     * @param array<int,string> $moved
     * @param array<string,array<int,array<string,bool>>> $staying
     */
    private static function shadowed(array $declarations, array $moved, array $staying): bool
    {
        foreach ($declarations as [$family, $important]) {
            $level = $important ? 1 : 0;
            $rivals = $staying['*'][$level] ?? array();
            foreach ('*' === $family ? array_keys($staying) : array($family) as $name) $rivals += $staying[$name][$level] ?? array();
            if (array() === $rivals) continue;
            foreach ($moved as $weight) {
                if ('?' === $weight || isset($rivals[$weight]) || isset($rivals['?'])) return true;
            }
        }
        return false;
    }

    /** An arm's specificity as `ids,classes,types`, or `?` when unreadable. */
    private static function weight(string $arm): string
    {
        $parsed = CssSelectorMatcher::parse(trim(CssSyntaxScanner::withoutComments($arm)));
        if (!($parsed['supported'] ?? false)) return '?';
        return implode(',', CssSelectorMatcher::specificityCounts($parsed));
    }

    /** @param array<string,mixed> $asset */
    private static function imported(array $asset): bool
    {
        foreach (is_array($asset['references'] ?? null) ? $asset['references'] : array() as $reference) {
            if (is_array($reference) && 'css-import' === ($reference['context'] ?? null)) return true;
        }
        return false;
    }
}
