<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

/**
 * Route-variant projection gates a shared stylesheet's rules with root
 * classes: a route class (`be-r-…`) or a route-set class (`be-rs-…`) on
 * `<html>`. A sheet every route links carries the gated arms of all of them,
 * so each visitor downloads every other route's variants too.
 * {@see AbsentRouteSelectors} cannot prune that sheet: it also loads as the
 * frame fallback on WordPress-made views and in the editor.
 *
 * Each document instance of such a sheet is delivered instead from a copy
 * without the arms that require a route class that document's root does not
 * carry. The copy keeps every other rule in its order, so on that route (and
 * in its page editor, which stamps the same root classes) it matches exactly
 * what the shared sheet matches. The shared sheet stays as it is for the
 * frame fallback, where no route's classes are known. Documents whose roots
 * agree on the sheet's route classes share one copy, and a copy saving less
 * than {@see MIN_SAVED_BYTES} is not written. The root route tests a copy's
 * arms still spell always hold on that root, so the copy writes them out.
 *
 * A copy also leaves out the arms that require an authored class the
 * document cannot carry: one another document's markup names (so it is an
 * authored class, not one WordPress mints at render), but neither that
 * document's own markup and root, nor anything every route renders (parts,
 * templates, patterns, menus, runtime records, the theme) nor any shipped
 * script names. Those arms style other routes' content: on this route they
 * match nothing, yet each visitor downloaded them.
 *
 * Only a sheet whose bytes are its own is copied: not one delivered through
 * parts or replays, a part itself, one another sheet imports, or one with
 * no document instances. A copy sits beside its sheet and keeps its leading
 * `@import`s, so they resolve and order the same.
 */
final class RouteStylesheetDeliveries
{
    /** Asset field mapping an instance's document source path to the target path delivering it. */
    public const FIELD = 'stylesheet_deliveries';

    private const ROUTE_CLASS = '/^be-rs?-[0-9a-f]+$/D';
    /**
     * Classes WordPress or the engine can put on rendered markup that the
     * block markup does not spell out (body and post classes, alignment,
     * navigation and pagination state): never pruned by absence.
     */
    private const GENERATED_CLASS = '/^(?:wp-|is-|has-|blocks-engine-|be-|route-class-|editor-|block-editor-|components-|align|screen-reader|skip-link|current|menu|sub-menu|page|post|type-|status-|format-|category-|tag-|taxonomy-|items-justified-|open-on-|size-|attachment-|columns-|logged-in|admin-bar|home$|blog$|archive|single|search|error404|custom-|comment|even$|odd$|depth-|thread-|bypost|byuser|avatar|emoji|children$|sticky|hentry|nav-)/i';
    /** A copy saving less than this costs more theme bytes than visitors gain. */
    private const MIN_SAVED_BYTES = 4096;

    /**
     * @param list<array<string,mixed>> $assets
     * @param array<string,array<string,mixed>> $documentsBySource Every canonical document by source path.
     * @param string|null $shared Everything any route renders beside its own document: parts, templates, patterns, menus, runtime records and the theme. Null leaves authored class arms alone.
     * @return list<array<string,mixed>>
     */
    public static function specialize(array $assets, array $documentsBySource, ?string $shared = null): array
    {
        $rootClasses = $routeClasses = array();
        foreach ($documentsBySource as $source => $document) {
            $classes = preg_split('/\s+/', trim((string) ($document['document_metadata']['root_attributes']['class'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: array();
            $classes = array_values(array_filter($classes, static fn(string $class): bool => 1 === preg_match(self::ROUTE_CLASS, $class)));
            $rootClasses[(string) $source] = array_fill_keys($classes, true);
            $routeClasses += array_fill_keys($classes, true);
        }
        $carried = null === $shared ? null : self::carriedClasses($assets, $documentsBySource, $shared);
        if (array() === $routeClasses && null === $carried) return $assets;
        $stylesheets = $partTargets = $targets = array();
        foreach ($assets as $index => $asset) {
            $targets[strtolower((string) ($asset['target_path'] ?? ''))] = true;
            foreach ($asset['stylesheet_parts'] ?? array() as $path) $partTargets[(string) $path] = true;
            if ('css' === ($asset['kind'] ?? null) && is_string($asset['content'] ?? null)) $stylesheets[$index] = $asset['content'];
        }
        $copies = array();
        foreach ($assets as $index => &$asset) {
            if (!isset($stylesheets[$index]) || isset($asset['payload_reference']) || 'editor' === ($asset['stylesheet_target'] ?? 'both')
                || empty($asset['stylesheet_instances']) || isset($asset['stylesheet_parts']) || isset($asset['stylesheet_replays']) || isset($asset[self::FIELD])
                || isset($partTargets[(string) ($asset['target_path'] ?? '')])
                || !str_ends_with(strtolower((string) ($asset['target_path'] ?? '')), '.css') || self::imported($asset, $stylesheets, $index)) continue;
            preg_match_all('/(?<!\\\\)\.(be-rs?-[0-9a-f]+)(?![\w\\\\-])/', $asset['content'], $matches);
            $named = array_values(array_filter(array_unique($matches[1]), static fn(string $class): bool => isset($routeClasses[$class])));
            $authored = null === $carried ? array() : self::authoredClasses($asset['content'], $carried);
            if (array() === $named && array() === $authored) continue;
            $deliveries = $byAbsent = array();
            foreach ($asset['stylesheet_instances'] as $instance) {
                $source = is_array($instance) ? ($instance['source_path'] ?? null) : null;
                if (!is_string($source) || !isset($rootClasses[$source]) || isset($deliveries[$source])) continue;
                $absent = array_values(array_filter($named, static fn(string $class): bool => !isset($rootClasses[$source][$class])));
                $present = array_values(array_diff($named, $absent));
                // A document whose markup is not known (an archive root) keeps every authored arm.
                $tokens = $carried['documents'][$source] ?? null;
                $uncarried = null === $tokens ? array() : array_keys(array_filter($authored, static fn(string $class): bool => !isset($tokens[$class])));
                $key = implode(' ', $absent) . "\n" . implode(' ', $uncarried);
                if (!array_key_exists($key, $byAbsent)) {
                    $required = array_merge($absent, $uncarried);
                    $content = array() === $required ? $asset['content'] : AbsentCarrierSelectors::withoutArmsRequiring($asset['content'], '/(?<!\\\\)\.(?:' . implode('|', array_map(static fn(string $class): string => preg_quote($class, '/'), $required)) . ')(?![\w\\\\-])/');
                    $content = self::withoutDecidedRootGates(self::withoutArmsExcludingRoot($content, $present), $present, $absent);
                    $byAbsent[$key] = strlen($asset['content']) - strlen($content) < self::MIN_SAVED_BYTES || '' === trim($content) ? null : self::copy($asset, $content, $targets, $copies);
                }
                if (null === $byAbsent[$key]) continue;
                $deliveries[$source] = $byAbsent[$key];
                $scope = PageStylesheets::documentScope($documentsBySource[$source]);
                if (!in_array($scope, $copies[$byAbsent[$key]]['scopes'], true)) $copies[$byAbsent[$key]]['scopes'][] = $scope;
            }
            if (array() === $deliveries) continue;
            ksort($deliveries, SORT_STRING);
            $asset[self::FIELD] = $deliveries;
        }
        unset($asset);
        return array_merge(array_values($assets), array_values($copies));
    }

    /**
     * Target paths only ever delivered through another asset's instances.
     *
     * @param array<int,array<string,mixed>> $assets
     * @return array<string,true>
     */
    public static function deliveryTargets(array $assets): array
    {
        $targets = array();
        foreach ($assets as $asset) foreach ($asset[self::FIELD] ?? array() as $target) $targets[(string) $target] = true;
        return $targets;
    }

    /**
     * The copy delivering $content for $asset, beside it so relative URLs
     * resolve the same; copies with the same bytes are one resource.
     *
     * @param array<string,mixed> $asset
     * @param array<string,true> $targets
     * @param array<string,array<string,mixed>> $copies
     */
    private static function copy(array $asset, string $content, array &$targets, array &$copies): ?string
    {
        $target = (string) $asset['target_path'];
        $hash = hash('sha256', $target . "\0" . $content);
        for ($length = 12; $length <= 64; ++$length) {
            $suffix = '.route-' . substr($hash, 0, $length) . '.css';
            $path = substr($target, 0, -4) . $suffix;
            if (isset($copies[$path])) return $path;
            if (!isset($targets[strtolower($path)])) break;
        }
        if (!isset($path) || isset($targets[strtolower($path)])) return null;
        $targets[strtolower($path)] = true;
        $copy = CompiledAssets::withRewrittenTextContent($asset, $content);
        $copy['path'] = $copy['target_path'] = $path;
        $copy['source_path'] = (string) preg_replace('/\.css$/i', '', (string) $asset['source_path']) . $suffix;
        // URLs inside the copy still resolve against the source sheet's origin.
        $copy['reference_origin'] = $asset['reference_origin'] ?? $asset['source_path'];
        $copy['token'] = 'asset-' . substr(hash('sha256', $path), 0, 16);
        $copy['reconciliation_identity'] = WordPressSitePlan::identity('asset', $copy['source_path'], $path);
        // Scoped to the documents it delivers; it is enqueued only in their
        // stead (see ThemeBootstrap), never on its own.
        $copy['scopes'] = array();
        unset($copy['stylesheet_instances'], $copy['stylesheet_parent'], $copy['stylesheet_inline_order'], $copy['frame_fallback'], $copy['frame_fallback_handle'], $copy['references'], $copy['inline_references']);
        $copies[$path] = $copy;
        return $path;
    }

    /**
     * The stylesheet without selector arms whose document root compound
     * excludes a route class the root carries (`:root:not(.be-rs-…)`,
     * `:root.be-r:not(.be-r-…,…)`): on that root such an arm matches
     * nothing. Each such exclusion is read as a required stand-in class, so
     * {@see AbsentCarrierSelectors::withoutArmsRequiring()} decides which
     * arms (and `:where()`/`:is()` alternatives) die exactly as it does for
     * an absent class; stand-ins left where they decide nothing (inside
     * `:not()` or `:has()`) are restored.
     *
     * @param list<string> $present Route classes the root carries.
     */
    private static function withoutArmsExcludingRoot(string $css, array $present): string
    {
        if (array() === $present || !str_contains($css, ':not(.be-r')) return $css;
        $present = array_fill_keys($present, true);
        $nonce = substr(hash('sha256', $css), 0, 12);
        $excluded = array();
        $marked = preg_replace_callback(
            '/(?<![\w\\\\-]):root(\.be-r(?![\w\\\\-]))?\K:not\(((?:\.be-rs?-[0-9a-f]+)(?:,\.be-rs?-[0-9a-f]+)*)\)/',
            static function (array $match) use ($present, $nonce, &$excluded): string {
                foreach (explode(',', $match[2]) as $class) {
                    if (!isset($present[substr($class, 1)])) continue;
                    $stand = '.be-root-excludes-' . $nonce . '-' . count($excluded);
                    $excluded[$stand] = $match[0];
                    return $stand;
                }
                return $match[0];
            },
            $css
        );
        if (null === $marked || array() === $excluded) return $css;
        $pruned = AbsentCarrierSelectors::withoutArmsRequiring($marked, '/\.be-root-excludes-' . $nonce . '-\d+(?![\w-])/');
        return preg_replace_callback('/\.be-root-excludes-' . $nonce . '-\d+(?![\w-])/', static fn(array $match): string => $excluded[$match[0]], $pruned) ?? $css;
    }

    /**
     * The stylesheet with each root route gate the copy's root already
     * decides written out. Every arm left after pruning can only require a
     * route class the root carries (`:root.be-rs-…`, `:root:is(.be-r-…)`) or
     * exclude one it does not (`:root:not(.be-rs-…)`): on this root those
     * tests always hold, yet each is spelled once per arm. Each decided test
     * leaves its `:root` compound, and a subject guard reduced to
     * `:where(:root,:root *)` (true of every element of the document) leaves
     * its compound; zero-weight `:where()` means no specificity changes.
     * Tests on undecided classes stay as they are.
     *
     * @param list<string> $present Route classes the root carries.
     * @param list<string> $absent Route classes the root does not carry.
     */
    private static function withoutDecidedRootGates(string $css, array $present, array $absent): string
    {
        if (!str_contains($css, ':root')) return $css;
        $present = array_fill_keys($present, true);
        $absent = array_fill_keys($absent, true);
        $class = '\.be-rs?-[0-9a-f]+(?![\w\\\\-])';
        $rewritten = preg_replace_callback(
            '/(?<![\w\\\\-]):root((?:\.be-r(?![\w\\\\-])|' . $class . '|:(?:is|where|not)\(' . $class . '(?:,' . $class . ')*\))+)/',
            static function (array $match) use ($present, $absent): string {
                preg_match_all('/\.be-r(?![\w\\\\-])|\.be-rs?-[0-9a-f]+|:(?:is|where|not)\([^()]*\)/', $match[1], $pieces);
                $kept = '';
                foreach ($pieces[0] as $piece) {
                    preg_match_all('/\.(be-rs?-[0-9a-f]+)/', $piece, $named);
                    $classes = $named[1];
                    $decided = array() !== $classes && (str_starts_with($piece, ':not(')
                        ? array() === array_filter($classes, static fn(string $name): bool => !isset($absent[$name]))
                        : array() !== array_filter($classes, static fn(string $name): bool => isset($present[$name])));
                    if (!$decided) $kept .= $piece;
                }
                return ':root' . $kept;
            },
            $css
        );
        if (null === $rewritten) return $css;
        // Only a guard trailing a compound leaves it; a lone one is a compound.
        return preg_replace('/(?<=[\w)\]*-]):where\(:root,:root \*\)/', '', $rewritten) ?? $rewritten;
    }

    /**
     * The classes each document's own markup and root name, those every
     * route renders, and the shipped scripts' text; null when a script's
     * bytes are not known, since it could add any class.
     *
     * @param list<array<string,mixed>> $assets
     * @param array<string,array<string,mixed>> $documentsBySource
     * @return array{documents:array<string,array<string,true>>,elsewhere:array<string,true>,shared:array<string,true>,scripts:string}|null
     */
    private static function carriedClasses(array $assets, array $documentsBySource, string $shared): ?array
    {
        $scripts = '';
        foreach ($assets as $asset) {
            if ('css' === ($asset['kind'] ?? null) || !empty($asset['binary'])) continue;
            $script = in_array($asset['kind'] ?? null, array('js', 'script', 'html', 'json'), true) || 1 === preg_match('/\.(?:m?js|html?|json)$/i', (string) ($asset['target_path'] ?? $asset['source_path'] ?? ''));
            if (!$script) continue;
            if (!is_string($asset['content'] ?? null) || isset($asset['payload_reference'])) return null;
            $scripts .= "\n" . $asset['content'];
        }
        $documents = $elsewhere = array();
        foreach ($documentsBySource as $source => $document) {
            if (!is_string($document['canonical_block_markup'] ?? null)) continue;
            $tokens = self::tokens($document['canonical_block_markup'] . "\n" . (string) json_encode($document['document_metadata'] ?? null, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
            $elsewhere += $tokens;
            // A document that renders other posts' content can carry their classes.
            if (1 === preg_match('/<!--\s+wp:(?:query|post-content|post-excerpt|latest-posts)[\s\/]/', $document['canonical_block_markup'])) continue;
            $documents[(string) $source] = $tokens;
        }
        return array('documents' => $documents, 'elsewhere' => $elsewhere, 'shared' => self::tokens($shared . "\n" . $scripts), 'scripts' => $scripts);
    }

    /**
     * The sheet's class selectors (as spelled in it) naming an authored class
     * some document carries, but nothing every route renders and no script.
     *
     * @param array{documents:array<string,array<string,true>>,elsewhere:array<string,true>,shared:array<string,true>,scripts:string} $carried
     * @return array<string,string> Spelling => class.
     */
    private static function authoredClasses(string $css, array $carried): array
    {
        $preludes = '';
        (new CssStylesheetTransformer())->visitStyleRules($css, static function (string $prelude) use (&$preludes): void {
            $preludes .= $prelude . "\n";
        });
        preg_match_all('/(?<![\\\\\w-])\.((?:[\w-]|\\\\[^0-9a-fA-F\s])+)/', $preludes, $matches);
        $classes = array();
        foreach (array_unique($matches[1]) as $spelling) {
            $class = (string) preg_replace('/\\\\(.)/s', '$1', $spelling);
            // A spelling cut at a hex escape (`.a\3a b`) is never followed by
            // the pruning pattern's boundary there, so it prunes nothing.
            if (1 === preg_match('/^-?\d/', $class) || 1 === preg_match(self::GENERATED_CLASS, $class) || !isset($carried['elsewhere'][$class]) || isset($carried['shared'][$class]) || str_contains($carried['scripts'], $class)) continue;
            $classes[$spelling] = $class;
        }
        return $classes;
    }

    /**
     * Every whitespace-, quote- or backslash-separated token of $text, as
     * written and with its JSON and HTML escapes decoded, so a class in a
     * `class` attribute or a block's `className` is one token.
     *
     * @return array<string,true>
     */
    private static function tokens(string $text): array
    {
        $decoded = (string) preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', static fn(array $match): string => (string) mb_chr((int) hexdec($match[1]), 'UTF-8'), $text);
        $decoded = html_entity_decode(str_replace('\\/', '/', $decoded), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tokens = array();
        foreach (array($text, $decoded) as $form) {
            foreach (preg_split('/[\s"\'`\\\\]+/', $form, -1, PREG_SPLIT_NO_EMPTY) ?: array() as $token) $tokens[$token] = true;
        }
        return $tokens;
    }

    /** @param array<string,mixed> $asset @param array<int,string> $stylesheets */
    private static function imported(array $asset, array $stylesheets, int $index): bool
    {
        $name = basename((string) ($asset['target_path'] ?? $asset['source_path'] ?? ''));
        if ('' === $name) return true;
        foreach ($stylesheets as $other => $css) {
            if ($other !== $index && str_contains($css, $name)) return true;
        }
        return false;
    }
}
