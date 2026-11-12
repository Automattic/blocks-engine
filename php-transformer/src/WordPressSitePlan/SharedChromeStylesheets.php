<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\CssUrlRewriter;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Css\CssIdent;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSubjectIndex;
use Automattic\BlocksEngine\PhpTransformer\Css\DeclarationCascade;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use Automattic\BlocksEngine\PhpTransformer\Support\RenderEquivalentMarkup;
use Automattic\BlocksEngine\PhpTransformer\Support\StylesheetActivation;

/**
 * Projects the author CSS that shared chrome needs into the stylesheets that
 * render it.
 *
 * Headers, footers and other template parts render on every route, while the
 * author rules that style them arrive inside each page's stylesheets. This
 * class finds the rules that target chrome hooks, scopes them to the chrome
 * the plan shares across documents, and keeps detached chrome painting in its
 * source order.
 *
 * Stateless: every method is a pure function of its inputs.
 */
final class SharedChromeStylesheets
{
    private const GENERATED_CLASS_PATTERN = '/blocks-engine-[a-z-]+-[0-9a-f]{12}-\d+/';

    private const BOX_PROPERTIES = '/(?:^|[;{\s])(?:(?:max-)?width|z-index|overflow(?:-[xy])?|inset|top|right|bottom|left)\s*:/i';

    /**
     * Authored classes and ids in shared chrome markup, read from rendered HTML
     * and from block attributes (a dynamic block such as a navigation link keeps
     * its classes only in its comment). Engine and WordPress utility classes are
     * excluded: they are not the author's hooks and are shared with unrelated
     * content. An editor-anchor class stands for the source id it re-expresses.
     *
     * @return array{class:array<string,true>,id:array<string,true>}
     */
    private static function authoredChromeHooks(string $markup): array
    {
        $hooks = array('class' => array(), 'id' => array());
        foreach (self::authoredChromeElementHooks($markup) as $element) {
            $hooks['class'] += $element['class'];
            $hooks['id'] += $element['id'];
        }
        return $hooks;
    }

    /**
     * authoredChromeHooks() grouped by the element (HTML start tag or block
     * comment) that carries them, with the generated marker classes it carries.
     *
     * @return list<array{class:array<string,true>,id:array<string,true>,marker:array<string,true>}>
     */
    private static function authoredChromeElementHooks(string $markup): array
    {
        $elements = array();
        preg_match_all('/<[a-zA-Z][^\s>\/]*((?:[^>"]|"[^"]*")*)>/', $markup, $tags);
        foreach ($tags[1] as $attributes) {
            $classes = preg_match('/\sclass="([^"]*)"/', $attributes, $class) ? preg_split('/\s+/', html_entity_decode($class[1], ENT_QUOTES | ENT_HTML5)) : array();
            $ids = preg_match('/\sid="([^"]+)"/', $attributes, $id) ? array(html_entity_decode($id[1], ENT_QUOTES | ENT_HTML5)) : array();
            if (array() !== $classes || array() !== $ids) $elements[] = self::selectorHooks($classes ?: array(), $ids) + array('marker' => self::generatedMarkers($classes ?: array()));
        }
        preg_match_all('/<!--\s*wp:[a-z0-9\/-]+\s+(\{.*?\})\s*\/?-->/s', $markup, $comments);
        foreach ($comments[1] as $json) {
            $attrs = json_decode($json, true);
            $classes = is_string($attrs['className'] ?? null) ? (preg_split('/\s+/', $attrs['className']) ?: array()) : array();
            $ids = is_string($attrs['anchor'] ?? null) ? array($attrs['anchor']) : array();
            if (array() !== $classes || array() !== $ids) $elements[] = self::selectorHooks($classes, $ids) + array('marker' => self::generatedMarkers($classes));
        }
        return $elements;
    }

    /**
     * @param array<int,mixed> $classes
     * @return array<string,true>
     */
    private static function generatedMarkers(array $classes): array
    {
        $markers = array();
        foreach ($classes as $class) if (is_string($class) && 1 === preg_match(self::GENERATED_CLASS_PATTERN, $class, $match)) $markers[$match[0]] = true;
        return $markers;
    }

    /**
     * @param array<int,mixed> $classes
     * @param array<int,mixed> $ids
     * @return array{class:array<string,true>,id:array<string,true>}
     */
    private static function selectorHooks(array $classes, array $ids): array
    {
        $hooks = array('class' => array(), 'id' => array());
        foreach ($ids as $id) if (is_string($id) && '' !== $id) $hooks['id'][$id] = true;
        foreach ($classes as $class) {
            if (!is_string($class) || '' === $class) continue;
            $hook = self::selectorHook('.', $class);
            if (null !== $hook) $hooks[$hook[0]][$hook[1]] = true;
        }
        return $hooks;
    }

    /** Page markup with its `<header>` and `<footer>` landmarks removed: the route's own content. */
    private static function withoutLandmarks(string $markup): string
    {
        $content = '';
        $depth = 0;
        $offset = 0;
        preg_match_all('/<(\/?)(header|footer)\b[^>]*>/i', $markup, $tags, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($tags as $tag) {
            $closing = '/' === $tag[1][0];
            if (!$closing && 0 === $depth) $content .= substr($markup, $offset, $tag[0][1] - $offset);
            $depth = max(0, $depth + ($closing ? -1 : 1));
            if ($closing && 0 === $depth) $offset = $tag[0][1] + strlen($tag[0][0]);
        }
        return 0 === $depth ? $content . substr($markup, $offset) : $content;
    }

    /**
     * A shared shell part is rendered by the template, outside the ancestors it
     * sat under in the source. A selector that reaches chrome through a leading
     * run of those ancestors (`#page-root .menu .item`) also accepts the
     * template part wrapper in their place. The wrapper arm sits in `:where()`,
     * so the rule keeps the source specificity, and the original ancestors
     * still match where they remain.
     *
     * @param array{class:array<string,true>,id:array<string,true>} $context
     * @param array<int,string> $roots
     */
    private static function reanchoredDetachedContextSelector(string $selector, array $context, array $roots, bool $inheritedSubject = false): string
    {
        if (array() === $roots) return $selector;
        // Reuse the ancestor-run rewrite for declarations on the ancestor
        // itself. The synthetic leaf is removed below; only its wrapper inherits.
        if ($inheritedSubject) $selector .= ' .blocks-engine-inherited-context';
        $compounds = array();
        $current = '';
        $depth = 0;
        $length = strlen($selector);
        for ($i = 0; $i < $length; ++$i) {
            $char = $selector[$i];
            if ('(' === $char || '[' === $char) ++$depth;
            elseif (')' === $char || ']' === $char) --$depth;
            if (0 === $depth && (ctype_space($char) || '>' === $char || '+' === $char || '~' === $char)) {
                if ('' !== $current) $compounds[] = $current;
                if (!ctype_space($char)) $compounds[] = $char;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        if ('' !== $current) $compounds[] = $current;
        // A capture scopes a responsive variant's rules by that variant's root
        // (`:where(.data-blocks-engine-capture-mobile-document) #page-root #footer`). The
        // variant root is not an ancestor the chrome was hoisted out of: the
        // part carries its own variant group. Keep the scope in front of the
        // run being re-anchored, and require it on the wrapper arm, so the
        // rule keeps reaching only that variant's copy of the chrome.
        $scope = array();
        foreach ($compounds as $index => $compound) {
            if (!isset($compounds[$index + 1]) || in_array($compounds[$index + 1], array('>', '+', '~'), true)) break;
            $variantClasses = self::responsiveVariantScopeClasses($compound);
            if (null === $variantClasses) break;
            $scope[] = $variantClasses;
        }
        $first = count($scope);
        $run = $first;
        foreach ($compounds as $index => $compound) {
            if ($index < $first) continue;
            if (!isset($compounds[$index + 1]) || in_array($compounds[$index + 1], array('>', '+', '~'), true)) break;
            // The compound's positive hooks, including those the engine moved
            // into `:where()`/`:is()` when it rewrote an id for editor parity.
            $hooks = str_replace(array(':where(', ':is(', ')'), '', WordPressSitePlan::positiveSelector($compound));
            if (!preg_match('/^(?:[#.][_a-zA-Z][\w-]*)+$/', $hooks)) break;
            preg_match_all('/([#.])([\w-]+)/', $hooks, $tokens, PREG_SET_ORDER);
            $named = false;
            foreach ($tokens as $token) {
                $hook = self::selectorHook($token[1], $token[2]);
                if (null === $hook) continue;
                if (!isset($context[$hook[0]][$hook[1]])) break 2;
                $named = true;
            }
            if (!$named) break;
            $run = $index + 1;
        }
        if ($first === $run) return $inheritedSubject ? '' : $selector;
        $wrappers = array_values(array_unique(array_map(static fn (string $root): string => ':has(> ' . (in_array($root[0], array('#', '.'), true) ? $root : '#' . CssIdent::escape($root)) . ')', $roots)));
        if (array() !== $scope) {
            // The variant group is the part's own child wrapper (`.data-blocks-engine-capture-mobile-document > #footer`),
            // or an ancestor of the wrapper that holds the chrome.
            $scopeClass = implode('', array_map(static fn (string $class): string => '.' . CssIdent::escape($class), array_merge(...$scope)));
            $wrappers = array_merge(
                array_map(static fn (string $wrapper): string => $scopeClass . $wrapper, $wrappers),
                array_map(static fn (string $wrapper): string => $scopeClass . ' ' . $wrapper, $wrappers)
            );
        }
        if ($inheritedSubject) {
            if ($run !== count($compounds) - 1) return '';
            return ':is(' . implode(' ', array_slice($compounds, 0, $run)) . ',:where(' . implode(',', $wrappers) . '))';
        }
        return ':is(' . implode(' ', array_slice($compounds, 0, $run)) . ',:where(' . implode(',', $wrappers) . ')) ' . implode(' ', array_slice($compounds, $run));
    }

    private static function responsiveVariantScopeClasses(string $compound): ?array
    {
        $hooks = str_replace(array(':where(', ':is(', ')'), '', WordPressSitePlan::positiveSelector($compound));
        if (!preg_match('/^(?:\.[_a-zA-Z][\w-]*)+$/', $hooks)) return null;
        $classes = explode('.', ltrim($hooks, '.'));
        foreach ($classes as $class) if (!ShellExtraction::isResponsiveVariantClass($class)) return null;
        return $classes;
    }

    /**
     * Authored chrome hooks that the routes rendering their chrome declare
     * differently: the same selector, in the same media and at-rule context,
     * carries other declarations on another route. A route lacking the rule
     * altogether does not conflict; the lifted rule still styles it.
     *
     * @param array<int,array<string,mixed>> $assets
     * @param array{class:array<string,true>,id:array<string,true>} $authored
     * @param array{class:array<string,array<string,true>>,id:array<string,array<string,true>>} $hookRoutes
     * @return array{class:array<string,true>,id:array<string,true>}
     */
    private static function routeVaryingChromeHooks(array $assets, array $authored, array $hookRoutes): array
    {
        $varying = array('class' => array(), 'id' => array());
        if (array() === $authored['class'] && array() === $authored['id']) return $varying;
        $allRoutes = array();
        foreach ($hookRoutes as $names) foreach ($names as $routes) $allRoutes += $routes;
        if (count($allRoutes) < 2) return $varying;
        $declared = array();
        $hooks = array();
        foreach ($assets as $asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null) || '' === trim($asset['content']) || !StylesheetActivation::active($asset)) continue;
            $routes = array_column($asset['stylesheet_instances'] ?? array(), 'source_path');
            if (array() === $routes) $routes = array_column($asset['scopes'] ?? array(), 'source_path');
            $routes = array() === $routes ? array_keys($allRoutes) : array_values(array_unique(array_map('strval', $routes)));
            $media = (string) ($asset['media'] ?? '');
            (new CssStylesheetTransformer())->visitStyleRules($asset['content'], static function (string $prelude, string $body, array $ancestors) use ($authored, $routes, $media, &$declared, &$hooks): void {
                if (!preg_match_all('/([.#])(-?[_a-zA-Z][\w-]*)/', WordPressSitePlan::positiveSelector($prelude), $matches, PREG_SET_ORDER)) return;
                $named = array();
                foreach ($matches as $match) {
                    $hook = self::selectorHook($match[1], $match[2]);
                    if (null !== $hook && isset($authored[$hook[0]][$hook[1]])) $named[$hook[0] . ':' . $hook[1]] = $hook;
                }
                if (array() === $named) return;
                $key = $media . "\0" . implode("\0", $ancestors) . "\0" . (preg_replace('/\s+/', ' ', trim($prelude)) ?? $prelude);
                $hooks[$key] = $named;
                $canonical = RenderEquivalentMarkup::canonicalStyle($body);
                foreach ($routes as $route) $declared[$key][$route][$canonical] = true;
            });
        }
        foreach ($declared as $key => $byRoute) {
            foreach ($hooks[$key] as [$kind, $name]) {
                if (isset($varying[$kind][$name])) continue;
                $signatures = array();
                foreach (array_intersect_key($byRoute, $hookRoutes[$kind][$name] ?? array()) as $bodies) {
                    ksort($bodies);
                    $signatures[implode("\0", array_keys($bodies))] = true;
                }
                if (count($signatures) > 1) $varying[$kind][$name] = true;
            }
        }
        return $varying;
    }

    /**
     * Whether a selector is written for shared chrome: it names at least one of
     * the chrome's own authored classes or ids, and every class or id it names
     * belongs to that chrome or to the ancestors it was hoisted out of. A
     * selector that also names route-owned hooks stays with the route.
     *
     * @param array{class:array<string,true>,id:array<string,true>} $authored
     * @param array{class:array<string,true>,id:array<string,true>} $context
     */
    private static function selectorTargetsAuthoredChrome(string $selector, array $authored, array $context): bool
    {
        $positive = WordPressSitePlan::positiveSelector($selector);
        if (str_contains($positive, '\\')) return false;
        if (!preg_match_all('/([.#])(-?[_a-zA-Z][\w-]*)/', $positive, $matches, PREG_SET_ORDER)) return false;
        $owned = false;
        foreach ($matches as $match) {
            $hook = self::selectorHook($match[1], $match[2]);
            if (null === $hook) continue;
            if (isset($authored[$hook[0]][$hook[1]])) {
                $owned = true;
                continue;
            }
            if (!isset($context[$hook[0]][$hook[1]])) return false;
        }
        return $owned;
    }

    /**
     * The hook a selector token names, keyed like selectorHooks(): an
     * editor-anchor class is its source id, and utility classes are neutral.
     *
     * @return array{0:'class'|'id',1:string}|null
     */
    private static function selectorHook(string $sigil, string $name): ?array
    {
        if ('#' === $sigil) return array('id', $name);
        // A responsive variant root scopes rules to one variant; it is neither
        // chrome nor an ancestor the chrome was hoisted out of.
        if (ShellExtraction::isResponsiveVariantClass($name)) return null;
        $anchorId = EngineMarker::editorAnchorId($name);
        if (null !== $anchorId) return array('id', $anchorId);
        return 1 === preg_match('/^(?:wp-|blocks-engine-|has-|is-|be-)/', $name) ? null : array('class', $name);
    }

    /**
     * The side of a selector that one stylesheet keeps when its `:is()` or
     * `:where()` lists group generated classes the shared parts carry with
     * generated classes only route content carries
     * (`:where(.blocks-engine-control-a-0,.blocks-engine-control-b-0) > a`).
     * Shared chrome is enqueued after the route stylesheet, so a lifted
     * route alternative would override the route's later rules for that
     * content. The chrome side keeps chrome and neutral alternatives; the
     * route side keeps route and neutral alternatives, or is null when the
     * selector groups no route alternative. A side whose list is left empty
     * matches nothing and is null.
     * @param array{patterns:list<string>,richtext:list<string>} $matcher
     */
    private static function generatedAlternatives(string $selector, array $matcher, bool $chrome): ?string
    {
        $result = ''; $offset = 0; $length = strlen($selector); $routeAlternatives = false;
        while (preg_match('/:(?:is|where)\(/i', $selector, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $open = $match[0][1] + strlen($match[0][0]);
            $depth = 1; $quote = null;
            for ($cursor = $open; $cursor < $length && $depth > 0; ++$cursor) {
                $char = $selector[$cursor];
                if (null !== $quote) { if ('\\' === $char) ++$cursor; elseif ($char === $quote) $quote = null; continue; }
                if ('"' === $char || "'" === $char) $quote = $char;
                elseif ('\\' === $char) ++$cursor;
                elseif ('(' === $char) ++$depth;
                elseif (')' === $char) --$depth;
            }
            if (0 !== $depth) return $chrome ? $selector : null;
            $alternatives = CssStylesheetTransformer::splitSelectorList(substr($selector, $open, $cursor - 1 - $open));
            if (null === $alternatives) return $chrome ? $selector : null;
            $kept = array();
            foreach ($alternatives as $alternative) {
                $generated = 1 === preg_match(self::GENERATED_CLASS_PATTERN, WordPressSitePlan::positiveSelector($alternative));
                $carried = $generated && self::selectorTargetsGeneratedClass($alternative, $matcher);
                if ($generated && !$carried) $routeAlternatives = true;
                if (!$generated || $carried === $chrome) $kept[] = trim($alternative);
            }
            if (array() === $kept) return null;
            $result .= substr($selector, $offset, $open - $offset) . implode(',', $kept) . ')';
            $offset = $cursor;
        }
        if (!$chrome && !$routeAlternatives) return null;
        return $result . substr($selector, $offset);
    }

    private static function selectorTargetsGeneratedClass(string $selector, array $matcher): bool
    {
        $positive = WordPressSitePlan::positiveSelector($selector);
        foreach ($matcher['patterns'] as $pattern) {
            if (1 === preg_match($pattern, $positive)) return true;
        }
        foreach ($matcher['richtext'] as $class) {
            if (str_contains($positive, $class)) return true;
        }
        return false;
    }

    /** Projected native menu state survives even when no captured item is current. */
    private static function selectorTargetsRuntimeNavigationState(string $selector): bool
    {
        $positive = WordPressSitePlan::positiveSelector($selector);
        return 1 === preg_match('/\\.wp-block-navigation(?![\\w-])/', $positive)
            && 1 === preg_match('/\\[\\s*aria-current\\b|\\.current-menu-item(?![\\w-])/', $positive);
    }

    /**
     * Compile the generated-class test once per projection. Every selector of
     * the site stylesheet is checked against every shared-part class, so one
     * regex per class per selector grows with rules x classes; a few
     * alternation patterns keep it linear in the selector count.
     *
     * @param array<string,true> $classes
     * @return array{patterns:list<string>,richtext:list<string>}
     */
    private static function generatedClassMatcher(array $classes): array
    {
        $alternatives = array();
        $richtext = array();
        foreach (array_keys($classes) as $class) {
            $class = (string) $class;
            $alternatives[] = CssIdent::classSelectorRegex($class);
            if (1 === preg_match('/^blocks-engine-richtext-[a-f0-9]+-\d+$/', $class)) $richtext[] = $class;
        }
        $patterns = array();
        foreach (array_chunk($alternatives, 200) as $chunk) {
            $patterns[] = '/(?:' . implode('|', $chunk) . ')(?![\\w-])/';
        }

        return array('patterns' => $patterns, 'richtext' => $richtext);
    }

    /**
     * A header part renders before post-content, yet page content that preceded
     * it in the source (a fixed page background) now follows it and paints over
     * it wherever both are positioned without z-index. Restore the source paint
     * order at zero specificity, so a z-index the author declared still wins and
     * a static root is unaffected. Author rules that reached the part through
     * its detached ancestors are re-anchored by projectSharedChromeStylesheets.
     *
     * @param array<int,array<string,mixed>> $assets
     * @param array<int,array<string,mixed>> $parts
     * @return array<int,array<string,mixed>>
     */
    public static function projectDetachedPaintOrder(array $assets, array $parts, array $pages = array()): array
    {
        $rules = '';
        foreach ($parts as $part) {
            if ('shared_shell' !== ($part['placement']['kind'] ?? null) || 'header' !== ($part['area'] ?? null) || empty($part['ancestor_context']['preceded'])) continue;
            foreach (self::partRootAnchors((string) ($part['canonical_block_markup'] ?? '')) as $anchor) $rules .= ':where(#' . CssIdent::escape($anchor) . '){z-index:1}';
        }
        $rules .= self::detachedChromeBackdropRules($parts, $assets, $pages);
        $rules .= self::detachedViewportShellRules($parts, $assets, $pages);
        $rules .= self::detachedMinimumWidthRules($parts, $assets, $pages);
        $rules .= self::detachedStaticStartRules($parts, $assets, $pages);
        foreach ($parts as $part) {
            if ('shared_shell' !== ($part['placement']['kind'] ?? null) || !in_array($part['area'] ?? null, array('header', 'footer'), true)) continue;
            if (!self::leftPositionedAncestor($part, $assets, $pages)) continue;
            $selectors = array();
            foreach (self::partAllRootBlocks((string) ($part['canonical_block_markup'] ?? '')) as $root) {
                $anchor = $root['attrs']['anchor'] ?? '';
                if (is_string($anchor) && '' !== $anchor) $selectors[] = '#' . CssIdent::escape($anchor);
                else {
                    $classes = preg_split('/\s+/', trim((string) ($root['attrs']['className'] ?? ''))) ?: array();
                    if (array() !== $classes && '' !== $classes[0]) $selectors[] = '.' . implode('.', array_map(array(CssIdent::class, 'escape'), $classes));
                }
            }
            // Unlike backdrop containment transfer, restoring the root's paint
            // layer needs no ancestor id and does not change the backdrop box.
            // Zero specificity preserves authored sticky/absolute positioning.
            if (array() !== $selectors) $rules .= ':where(' . implode(',', array_unique($selectors)) . '){position:relative}';
        }
        $template = null;
        foreach ($assets as $asset) {
            if ('css' === ($asset['kind'] ?? null) && is_string($asset['content'] ?? null) && '' !== trim($asset['content'])) {
                $template = $asset;
                break;
            }
        }
        if (null === $template || '' === $rules) return $assets;
        $context = $template;
        $context['path'] = 'assets/css/shared-chrome-context-' . substr(hash('sha256', $rules), 0, 16) . '.css';
        $context['target_path'] = $context['path'];
        $context['reference_origin'] = (string) ($template['source_path'] ?? $template['path'] ?? '');
        $context['source_path'] = (string) ($template['source_path'] ?? $template['path'] ?? '') . '.shared-chrome-context';
        $context = WordPressSitePlan::withRewrittenTextContent($context, $rules);
        $context['scopes'] = array(array('kind' => 'global'));
        // The paint-order context is route-independent and paints after every
        // authored stylesheet; it is not an occurrence of its template's link.
        $context['stylesheet_placement'] = 'after-author';
        unset($context['stylesheet_instances'], $context['stylesheet_parent'], $context['stylesheet_inline_order']);
        $context['token'] = 'asset-' . substr(hash('sha256', $context['target_path']), 0, 16);
        $context['reconciliation_identity'] = WordPressSitePlan::identity('asset', $context['source_path'], $context['target_path']);
        $assets[] = $context;
        return $assets;
    }

    /**
     * Page content that preceded the chrome inside its layout ancestors (a
     * page background layer) was sized, in the source, to an ancestor box that
     * also held the chrome. That ancestor stays in post-content while the
     * chrome renders from the template, so the backdrop now starts below the
     * header and the band behind the chrome shows the bare canvas.
     *
     * In WordPress the box that spans the chrome and the content is the
     * template root. It takes over the containing-block role of the ancestors
     * that held the backdrop (only on the front end, where the chrome is
     * outside them), and each chrome root stays positioned so it still paints
     * above that backdrop, as it did inside the source ancestors. Only the
     * ancestors every detached part left behind are moved: their source box
     * spanned all of the chrome, as the template root does. When a part root
     * has no anchor it cannot be kept above the backdrop, so nothing moves.
     * Only a positioned ancestor is a containing block to hand over, and one
     * whose own box differs from the template root's (a width cap, an inset,
     * clipping overflow or a stacking z-index, under any media condition)
     * keeps its role: the template root would stand in for a different box.
     *
     * @param array<int,array<string,mixed>> $parts
     * @param array<int,array<string,mixed>> $assets
     * @param array<int,array<string,mixed>> $pages
     */
    private static function detachedChromeBackdropRules(array $parts, array $assets = array(), array $pages = array()): string
    {
        $detached = array_values(array_filter($parts, static fn(array $part): bool => 'shared_shell' === ($part['placement']['kind'] ?? null) && is_array($part['ancestor_context'] ?? null) && in_array($part['area'] ?? null, array('header', 'footer'), true)));
        if (array() === $detached) return '';
        // The backdrop is what precedes the first chrome. Before a footer, the
        // route's own content also precedes it, so a detached header decides.
        $first = array_values(array_filter($detached, static fn(array $part): bool => 'header' === $part['area'])) ?: $detached;
        $backdrop = array();
        foreach ($first as $part) foreach ((array) ($part['ancestor_context']['backdrop_ids'] ?? array()) as $id) if (is_string($id) && '' !== $id) $backdrop[$id] = true;
        if (array() === $backdrop) return '';
        $roots = array();
        foreach ($detached as $part) {
            $markup = (string) ($part['canonical_block_markup'] ?? '');
            $anchors = self::partRootAnchors($markup);
            if (array() === $anchors) return '';
            array_push($roots, ...$anchors);
            // An ancestor the part never sat under did not span it in the source.
            $enclosing = self::selectorHooks((array) ($part['ancestor_context']['classes'] ?? array()), (array) ($part['ancestor_context']['ids'] ?? array()))['id'];
            foreach ((array) ($part['ancestor_context']['backdrop_ids'] ?? array()) as $id) if (is_string($id)) $enclosing[$id] = true;
            foreach (array_keys($backdrop) as $id) if (!isset($enclosing[(string) $id])) unset($backdrop[$id]);
        }
        foreach (array_keys($backdrop) as $id) if (!self::sharesTemplateRootBox((string) $id, $assets, $pages)) unset($backdrop[$id]);
        if (array() === $backdrop) return '';
        $escape = static fn(string $id): string => '#' . CssIdent::escape($id);
        return ':where(.wp-site-blocks){position:relative}'
            . implode(',', array_map(static fn(int|string $id): string => '.wp-site-blocks ' . $escape((string) $id), array_keys($backdrop))) . '{position:static}'
            . ':where(' . implode(',', array_map($escape, array_values(array_unique($roots)))) . '){position:relative}';
    }

    /**
     * Resolve the retained ancestor hooks against route markup, where their
     * inline styles and selector context still exist. Only actual matching
     * positioning rules count; a positioned sibling is not evidence.
     *
     * @param array<string,mixed> $part
     * @param array<int,array<string,mixed>> $assets
     * @param array<int,array<string,mixed>> $pages
     */
    private static function leftPositionedAncestor(array $part, array $assets, array $pages): bool
    {
        $context = $part['ancestor_context'] ?? array();
        $classes = (array) ($context['classes'] ?? array());
        $ids = (array) ($context['ids'] ?? array());
        if (array() === $classes && array() === $ids) return false;
        $rules = array();
        $transformer = new CssStylesheetTransformer();
        foreach ($assets as $asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null)) continue;
            $transformer->visitStyleRules($asset['content'], static function (string $prelude, string $body) use (&$rules): void {
                if (!self::declaresPosition($body)) return;
                foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array($prelude) as $selector) $rules[] = CssSelectorMatcher::parse(trim($selector));
            });
        }
        foreach ($pages as $page) {
            $sourcePaths = $part['placement']['source_paths'] ?? array();
            if (array() !== $sourcePaths && !in_array($page['source_path'] ?? '', $sourcePaths, true)) continue;
            $document = HtmlSerialization::parseDocument('<html><body>' . (string) ($page['canonical_block_markup'] ?? '') . '</body></html>');
            foreach ((new \DOMXPath($document))->query('//*[@class or @id]') ?: array() as $element) {
                if (!$element instanceof \DOMElement) continue;
                $elementClasses = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array();
                if (!in_array($element->getAttribute('id'), $ids, true) && array() === array_intersect($elementClasses, $classes)) continue;
                if (self::declaresPosition($element->getAttribute('style'))) return true;
                foreach ($rules as $rule) if (CssSelectorMatcher::matches($element, $rule, true)['matches']) return true;
            }
        }
        return false;
    }

    /**
     * Route elements that enclosed a detached shared part in the source, each
     * with the id or class hook that selects it.
     *
     * @param array<int,array<string,mixed>> $parts
     * @param array<int,array<string,mixed>> $pages
     * @return list<array{0:\DOMElement,1:string}>
     */
    private static function detachedAncestorElements(array $parts, array $pages): array
    {
        $classes = $ids = array();
        foreach ($parts as $part) {
            if ('shared_shell' !== ($part['placement']['kind'] ?? null)) continue;
            array_push($classes, ...(array) ($part['ancestor_context']['classes'] ?? array()));
            array_push($ids, ...(array) ($part['ancestor_context']['ids'] ?? array()));
        }
        if (array() === $classes && array() === $ids) return array();
        $elements = array();
        foreach ($pages as $page) {
            $document = HtmlSerialization::parseDocument('<html><body>' . ($page['canonical_block_markup'] ?? '') . '</body></html>');
            foreach ((new \DOMXPath($document))->query('//*[@class or @id]') ?: array() as $element) {
                if (!$element instanceof \DOMElement) continue;
                $hooks = array_intersect(preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array(), $classes);
                $id = $element->getAttribute('id');
                if ('' !== $id && in_array($id, $ids, true)) $hook = '#' . CssIdent::escape($id);
                elseif (array() !== $hooks) $hook = '.' . CssIdent::escape((string) reset($hooks));
                else continue;
                $elements[] = array($element, $hook);
            }
        }
        return $elements;
    }

    /**
     * A detached part is no longer included in its ancestor's minimum width
     * (a fixed-width page frame that header, content and footer shared).
     * Transport that minimum to the template, which again contains all three,
     * so the part keeps the box its centering and offsets were authored for.
     * Media conditions survive because the rules are transformed in place.
     *
     * @param array<int,array<string,mixed>> $parts
     * @param array<int,array<string,mixed>> $assets
     * @param array<int,array<string,mixed>> $pages
     */
    private static function detachedMinimumWidthRules(array $parts, array $assets, array $pages): string
    {
        $elements = self::detachedAncestorElements($parts, $pages);
        if (array() === $elements) return '';
        $result = array();
        foreach ($assets as $asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null)) continue;
            (new CssStylesheetTransformer())->visitStyleRules($asset['content'], static function (string $selector, string $body, array $context) use ($elements, &$result): void {
                $minimum = CssRuleAnalyzer::declarations($body, array('min-width'))[0]['value'] ?? null;
                if (!is_string($minimum) || 1 === preg_match('/^\s*(?:0[a-z%]*|auto|none|initial|unset|inherit|revert(?:-layer)?)\s*(?:!important\s*)?$/i', $minimum)) return;
                $rules = array();
                foreach (CssStylesheetTransformer::splitSelectorList($selector) ?? array($selector) as $candidate) {
                    $parsed = CssSelectorMatcher::parse(trim($candidate));
                    foreach ($elements as [$element, $hook]) {
                        if (!CssSelectorMatcher::matches($element, $parsed, true)['matches']) continue;
                        $rules[$hook] = '.wp-site-blocks:has(>.wp-block-post-content ' . $hook . '){min-width:' . trim((string) preg_replace('/\s*!important\s*$/i', '', $minimum)) . '}';
                    }
                }
                $result[self::ruleInContext(implode('', $rules), $context)] = true;
            });
        }
        return implode('', array_keys($result));
    }

    /**
     * A detached part is no longer included in its ancestor's viewport minimum.
     * Transport that sizing relationship to the template, not a second viewport
     * inside post-content. Intermediate document carriers stretch the remaining
     * space with zero-specificity defaults so authored display rules win.
     * Unlike a fixed height or viewport subtraction, this also permits long
     * main content.
     *
     * @param array<int,array<string,mixed>> $parts
     * @param array<int,array<string,mixed>> $assets
     * @param array<int,array<string,mixed>> $pages
     */
    private static function detachedViewportShellRules(array $parts, array $assets, array $pages): string
    {
        $elements = self::detachedAncestorElements($parts, $pages);
        if (array() === $elements) return '';
        // Resolve layout from all matching declarations, including declarations
        // in other assets. Conditional rules only contribute in the same context.
        $layoutRules = array();
        $transformer = new CssStylesheetTransformer();
        foreach ($assets as $asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null)) continue;
            $transformer->visitStyleRules($asset['content'], static function (string $selector, string $body, array $context) use (&$layoutRules): void {
                $declarations = CssRuleAnalyzer::declarations($body, array('display', 'flex-direction'));
                if (array() === $declarations) return;
                foreach (CssStylesheetTransformer::splitSelectorList($selector) ?? array($selector) as $candidate) {
                    $parsed = CssSelectorMatcher::parse(trim($candidate));
                    $layoutRules[] = array($parsed, array_values(CssSelectorMatcher::specificityCounts($parsed)), $declarations, $context);
                }
            });
        }
        $result = array();
        foreach ($assets as $asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null)) continue;
            (new CssStylesheetTransformer())->visitStyleRules($asset['content'], static function (string $selector, string $body, array $context) use ($elements, $layoutRules, &$result): void {
                if (!preg_match('/(?:^|;)\s*min-height\s*:\s*([^;]*(?:vh|svh|dvh|lvh))\s*(?:;|$)/i', $body, $minimum)) return;
                $rules = array();
                foreach (CssStylesheetTransformer::splitSelectorList($selector) ?? array($selector) as $candidate) {
                    $parsed = CssSelectorMatcher::parse(trim($candidate));
                    foreach ($elements as [$element, $hook]) {
                        if (!CssSelectorMatcher::matches($element, $parsed, true)['matches']) continue;
                        $layout = array();
                        $order = 0;
                        foreach ($layoutRules as [$layoutSelector, $specificity, $declarations, $ruleContext]) {
                            if (array() !== $ruleContext && $ruleContext !== $context) continue;
                            if (!CssSelectorMatcher::matches($element, $layoutSelector, true)['matches']) continue;
                            foreach ($declarations as $declaration) {
                                $important = 1 === preg_match('/\s*!important\s*$/i', $declaration['value']);
                                DeclarationCascade::apply($layout, $declaration['name'], array(
                                    'value' => strtolower(trim((string) preg_replace('/\s*!important\s*$/i', '', $declaration['value']))),
                                    'important' => $important, 'specificity' => $specificity, 'order' => $order++,
                                ));
                            }
                        }
                        foreach (CssRuleAnalyzer::declarations($element->getAttribute('style'), array('display', 'flex-direction')) as $declaration) {
                            DeclarationCascade::apply($layout, $declaration['name'], array(
                                'value' => strtolower(trim((string) preg_replace('/\s*!important\s*$/i', '', $declaration['value']))),
                                'important' => 1 === preg_match('/\s*!important\s*$/i', $declaration['value']),
                                'inline' => true, 'specificity' => array(0, 0, 0), 'order' => $order++,
                            ));
                        }
                        if ('flex' !== ($layout['display']['value'] ?? null) || 'column' !== ($layout['flex-direction']['value'] ?? null)) continue;
                        $root = '.wp-site-blocks:has(>.wp-block-post-content ' . $hook . ')';
                        $post = $root . '>.wp-block-post-content';
                        $rules[$hook] = $root . '{min-height:' . trim($minimum[1]) . ';display:flex;flex-direction:column}'
                            . $post . ',' . $post . ' ' . $hook . '{min-height:0}'
                            . ':where(' . $post . ',' . $post . ' ' . $hook . ',' . $post . ' :has(' . $hook . ')){flex:1;display:flex;flex-direction:column}';
                    }
                }
                $result[self::ruleInContext(implode('', $rules), $context)] = true;
            });
        }
        return implode('', array_keys($result));
    }

    /**
     * Wrap a generated rule in the at-rule preludes that conditioned its
     * source rule, so a media-scoped source keeps its scope.
     *
     * @param list<string> $context
     */
    private static function ruleInContext(string $rule, array $context): string
    {
        if ('' === $rule) return '';
        foreach (array_reverse($context) as $prelude) $rule = $prelude . '{' . $rule . '}';
        return $rule;
    }

    /**
     * Auto insets use the hypothetical flow position, which changes when a
     * chrome is detached from post-content. Pin auto-inset positioned boxes
     * that preceded the removed chrome in its source ancestor chain.
     * Explicit insets (including responsive overrides) are never rewritten.
     *
     * @param array<int,array<string,mixed>> $parts
     * @param array<int,array<string,mixed>> $assets
     * @param array<int,array<string,mixed>> $pages
     */
    private static function detachedStaticStartRules(array $parts, array $assets, array $pages): string
    {
        $ids = array();
        foreach ($parts as $part) {
            if (!in_array($part['placement']['kind'] ?? null, array('shared_shell', 'inline_shared_shell'), true)
                || !in_array($part['area'] ?? null, array('header', 'footer'), true)) continue;
            foreach ($part['ancestor_context']['static_start_ids'] ?? array() as $id) $ids[(string) $id] = true;
        }
        if (array() === $ids) return '';
        $properties = array('position', 'inset', 'inset-block', 'inset-inline', 'inset-block-start', 'inset-block-end', 'inset-inline-start', 'inset-inline-end', 'top', 'bottom', 'left', 'right');
        // Only inspected declarations affect the verdict; route projections repeat
        // the same rules, so parse and match each distinct rule just once.
        $rules = array();
        $transformer = new CssStylesheetTransformer();
        foreach ($assets as $asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null)) continue;
            $transformer->visitStyleRules($asset['content'], static function (string $selector, string $body) use (&$rules, $properties): void {
                $declarations = CssRuleAnalyzer::declarations($body, $properties);
                if (array() === $declarations) return;
                foreach (CssStylesheetTransformer::splitSelectorList($selector) ?? array($selector) as $item) {
                    $key = trim($item) . "\0" . $body;
                    if (!isset($rules[$key])) $rules[$key] = array('parsed_selector' => CssSelectorMatcher::parse(trim($item)), 'declarations' => $declarations);
                }
            });
        }
        // Each hooked element repeats on every page; only rules whose subject
        // compound can match it need the full matcher.
        $index = CssSubjectIndex::build(array_values($rules));
        $result = array();
        foreach ($pages as $page) {
            $document = HtmlSerialization::parseDocument('<html><body>' . ($page['canonical_block_markup'] ?? '') . '</body></html>');
            foreach ((new \DOMXPath($document))->query('//*[@id or @class]') ?: array() as $element) {
                if (!$element instanceof \DOMElement) continue;
                $hook = isset($ids[$element->getAttribute('id')]) ? '#' . CssIdent::escape($element->getAttribute('id')) : '';
                if ('' === $hook) foreach (preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array() as $class) {
                    if (isset($ids['.' . $class])) { $hook = '.' . CssIdent::escape($class); break; }
                }
                if ('' === $hook) continue;
                $declarationSets = array(CssRuleAnalyzer::declarations($element->getAttribute('style'), $properties));
                foreach (CssSubjectIndex::entries($element, $index) as $entry) if (CssSelectorMatcher::matches($element, $entry['rule']['parsed_selector'], true)['matches']) $declarationSets[] = $entry['rule']['declarations'];
                $absolute = false; $unsafe = false;
                foreach ($declarationSets as $declarations) {
                    foreach ($declarations as $declaration) {
                        $value = strtolower(trim($declaration['value']));
                        if ('position' === $declaration['name']) {
                            if (in_array($value, array('absolute', 'fixed'), true)) $absolute = true;
                            else $unsafe = true;
                        } elseif ('auto' !== $value) $unsafe = true;
                    }
                }
                if (!$absolute || $unsafe) continue;
                // Eligibility comes from source siblings retained during extraction,
                // not from the post-extraction DOM's first-child position.
                if (!$unsafe) $result[$hook] = '.wp-site-blocks ' . $hook . '{top:0}';
            }
        }
        return implode('', $result);
    }

    /**
     * Whether the element with `$id`, as rendered in a page, is positioned by
     * an author rule or its inline style, and no rule (under any media
     * condition) gives it a box the template root does not share: a width or
     * max-width other than full, a non-zero inset, a z-index, or overflow
     * other than visible. A rule the matcher cannot model counts against it,
     * and so does an element that cannot be found.
     *
     * @param array<int,array<string,mixed>> $assets
     * @param array<int,array<string,mixed>> $pages
     */
    private static function sharesTemplateRootBox(string $id, array $assets, array $pages): bool
    {
        $element = null;
        foreach ($pages as $page) {
            $markup = (string) ($page['canonical_block_markup'] ?? '');
            if (!str_contains($markup, 'id="' . htmlspecialchars($id, ENT_QUOTES) . '"')) continue;
            $document = HtmlSerialization::parseDocument('<html><body><div class="wp-site-blocks"><div class="entry-content wp-block-post-content">' . $markup . '</div></div></body></html>');
            foreach ((new \DOMXPath($document))->query('//*[@id]') ?: array() as $node) {
                if ($node instanceof \DOMElement && $id === $node->getAttribute('id')) {
                    $element = $node;
                    break 2;
                }
            }
        }
        if (!$element instanceof \DOMElement) return false;
        $inline = $element->getAttribute('style');
        if (self::declaresDistinctBox($inline)) return false;
        $positioned = self::declaresPosition($inline);
        // Rules can reach the element through its id or any of its classes
        // (an editor anchor, an inline-geometry class, an authored class).
        $hooks = array($id);
        foreach (preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array() as $class) if ('' !== $class && 1 !== preg_match('/^(?:wp-block-group|is-layout-|wp-container-|wp-block-group-is-layout-)/', $class)) $hooks[] = $class;
        $distinct = false;
        $transformer = new CssStylesheetTransformer();
        foreach ($assets as $asset) {
            if ($distinct) break;
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null)) continue;
            $transformer->visitStyleRules($asset['content'], static function (string $prelude, string $body) use (&$distinct, &$positioned, $element, $hooks): void {
                if ($distinct) return;
                $boxed = 1 === preg_match(self::BOX_PROPERTIES, $body) && self::declaresDistinctBox($body);
                $places = !$positioned && self::declaresPosition($body);
                if (!$boxed && !$places) return;
                $named = false;
                foreach ($hooks as $hook) if (str_contains($prelude, $hook)) $named = true;
                if (!$named) return;
                foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array($prelude) as $selector) {
                    $match = CssSelectorMatcher::matches($element, CssSelectorMatcher::parse(trim($selector)), true);
                    // A selector the matcher cannot model may still apply when
                    // its subject names the element.
                    if ($boxed && ($match['matches'] || (!$match['supported'] && self::subjectNamesAny(trim($selector), $hooks)))) {
                        $distinct = true;
                        return;
                    }
                    if ($places && $match['matches']) $positioned = true;
                }
            });
        }
        return $positioned && !$distinct;
    }

    /**
     * Whether a selector's rightmost compound names one of `$hooks`.
     *
     * @param list<string> $hooks
     */
    private static function subjectNamesAny(string $selector, array $hooks): bool
    {
        $depth = 0;
        $start = 0;
        $length = strlen($selector);
        for ($i = 0; $i < $length; ++$i) {
            $char = $selector[$i];
            if ('(' === $char || '[' === $char) ++$depth;
            elseif (')' === $char || ']' === $char) --$depth;
            elseif (0 === $depth && (ctype_space($char) || '>' === $char || '+' === $char || '~' === $char)) $start = $i + 1;
        }
        $subject = substr($selector, $start);
        foreach ($hooks as $hook) if (1 === preg_match('/[#.]' . preg_quote($hook, '/') . '(?![\w-])/', $subject)) return true;
        return false;
    }

    private static function declaresPosition(string $body): bool
    {
        foreach (CssRuleAnalyzer::declarations($body, array('position')) as $declaration) {
            if (1 !== preg_match('/^(?:static|initial|unset|inherit|revert|revert-layer)(?:\s*!important)?$/i', trim($declaration['value']))) return true;
        }
        return false;
    }

    private static function declaresDistinctBox(string $body): bool
    {
        if ('' === trim($body) || 1 !== preg_match(self::BOX_PROPERTIES, ';' . $body)) return false;
        $neutral = '(?:initial|unset|inherit|revert|revert-layer)';
        foreach (CssRuleAnalyzer::declarations($body, array('width', 'max-width', 'z-index', 'overflow', 'overflow-x', 'overflow-y', 'inset', 'top', 'right', 'bottom', 'left')) as $declaration) {
            $value = strtolower(trim(preg_replace('/\s*!important\s*$/i', '', $declaration['value']) ?? ''));
            $allowed = match ($declaration['name']) {
                'width' => '/^(?:auto|100%|' . $neutral . ')$/',
                'max-width' => '/^(?:none|100%|' . $neutral . ')$/',
                'z-index' => '/^(?:auto|' . $neutral . ')$/',
                'overflow', 'overflow-x', 'overflow-y' => '/^(?:visible|' . $neutral . ')(?:\s+visible)?$/',
                default => '/^(?:(?:0(?:\.0+)?[a-z%]*|auto)(?:\s+|$))+$|^' . $neutral . '$/',
            };
            if (1 !== preg_match($allowed, $value)) return true;
        }
        return false;
    }

    /**
     * The anchors of a shared part's root elements, never of a descendant.
     *
     * The root is the part's single top-level block. A viewport-partitioned
     * part instead holds one visibility group per variant, each wrapping that
     * variant's own root. A root without an anchor contributes nothing: an
     * anchored descendant (a Wix header's positioned background layer, say)
     * is a different element, and lifting it reorders the part's own layers.
     *
     * @return list<string>
     */
    private static function partRootAnchors(string $markup): array
    {
        $anchors = array();
        foreach (self::partRootBlocks($markup) as $root) {
            $anchor = $root['attrs']['anchor'] ?? null;
            if (is_string($anchor) && '' !== $anchor) $anchors[$anchor] = true;
        }
        return array_keys($anchors);
    }

    /** @return list<array{attrs:array<string,mixed>,inner_offset:int,inner_end:int}> */
    private static function partRootBlocks(string $markup): array
    {
        $roots = self::topLevelBlocks($markup, 0, strlen($markup));
        $partitioned = array() !== $roots && array() === array_filter($roots, static fn(array $block): bool => !self::isVariantVisibilityGroup($block['attrs']));
        if (!$partitioned) {
            if (1 !== count($roots)) return array();
        } else {
            $inner = array();
            foreach ($roots as $group) {
                $children = self::topLevelBlocks($markup, $group['inner_offset'], $group['inner_end']);
                if (1 !== count($children)) return array();
                $inner[] = $children[0];
            }
            $roots = $inner;
        }
        return $roots;
    }

    /**
     * Every top-level root needs its paint layer restored, including trailing
     * chrome. Unwrap only variant visibility groups, never ordinary roots.
     * Unlike partRootBlocks(), this transport does not require a single root.
     *
     * @return list<array{attrs:array<string,mixed>,inner_offset:int,inner_end:int}>
     */
    private static function partAllRootBlocks(string $markup): array
    {
        $roots = self::topLevelBlocks($markup, 0, strlen($markup));
        $partitioned = array() !== $roots && array() === array_filter($roots, static fn(array $block): bool => !self::isVariantVisibilityGroup($block['attrs']));
        if (!$partitioned) return $roots;
        $inner = array();
        foreach ($roots as $group) {
            foreach (self::topLevelBlocks($markup, $group['inner_offset'], $group['inner_end']) as $root) $inner[] = $root;
        }
        return $inner;
    }

    /** @param array<string,mixed> $attrs */
    private static function isVariantVisibilityGroup(array $attrs): bool
    {
        // A device document that carries capture attributes partitions the
        // part as a layout-shell with one wrapper instead of a class-only group.
        $wrappers = $attrs['wrappers'] ?? null;
        $className = is_array($wrappers) && 1 === count($wrappers) ? ($wrappers[0]['attributes']['class'] ?? null) : ($attrs['className'] ?? null);
        if (1 !== count($attrs) || !is_string($className)) return false;
        foreach (preg_split('/\s+/', trim($className)) ?: array() as $class) {
            if (ShellExtraction::isResponsiveVariantClass($class)) return true;
        }
        return false;
    }

    /**
     * Blocks whose delimiters sit directly in `$markup[$start, $end)`.
     *
     * @return list<array{attrs:array<string,mixed>,inner_offset:int,inner_end:int}>
     */
    private static function topLevelBlocks(string $markup, int $start, int $end): array
    {
        $segment = substr($markup, $start, $end - $start);
        if (!preg_match_all('/<!--\s*(\/?)wp:[a-z0-9\/_-]+(?:\s+(\{.*?\}))?\s*(\/?)-->/s', $segment, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return array();
        $blocks = array();
        $depth = 0;
        $open = null;
        foreach ($matches as $match) {
            $closing = '/' === $match[1][0];
            $selfClosing = '/' === ($match[3][0] ?? '');
            if ($closing) {
                --$depth;
                if (0 === $depth && null !== $open) {
                    $blocks[] = $open + array('inner_end' => $start + $match[0][1]);
                    $open = null;
                }
                if ($depth < 0) return array();
                continue;
            }
            if (0 === $depth) {
                $attrs = isset($match[2][0]) && '' !== $match[2][0] && -1 !== $match[2][1] ? json_decode($match[2][0], true) : array();
                $attrs = is_array($attrs) ? $attrs : array();
                if ($selfClosing) {
                    $blocks[] = array('attrs' => $attrs, 'inner_offset' => $start + $match[0][1] + strlen($match[0][0]), 'inner_end' => $start + $match[0][1] + strlen($match[0][0]));
                    continue;
                }
                $open = array('attrs' => $attrs, 'inner_offset' => $start + $match[0][1] + strlen($match[0][0]));
            }
            if (!$selfClosing) ++$depth;
        }
        return 0 === $depth ? $blocks : array();
    }

    /**
     * Extract only generated rules needed by shared template parts. Inherited
     * declarations on detached ancestors also reach the part's parent carrier;
     * ancestor box declarations stay on the route, and the part's own styles
     * still take precedence over inheritance. The source
     * stylesheet can contain both those projected rules and route-owned rules;
     * promoting the whole payload changes the cascade on unrelated pages.
     *
     * Cascade rule: the shared-chrome asset is enqueued after the route
     * stylesheet, so an element's competing rules must not be split across the
     * two. A chrome element that carries any hook whose rules stay on the route
     * (one route content also uses, or one no lifted selector can name) keeps
     * all of its authored hooks on the route, so its rules stay in one
     * stylesheet in source order on every route. The one exception is a rule
     * reaching such a hook on a detached part through the ancestors it was
     * hoisted out of: it stays on the route and a re-anchored copy is also
     * lifted, since the route rule can no longer match the detached part.
     *
     * @param array<int,array<string,mixed>> $assets
     * @param array<int,array<string,mixed>> $parts
     * @param list<array<string,string>> $collisions Receives one row per projection given a suffixed target.
     * @return array<int,array<string,mixed>>
     */
    public static function project(array $assets, array $parts, array $pages = array(), ?AssetReferenceCanonicalizer $references = null, array &$collisions = array(), ?array $liveElementMarkers = null): array
    {
        $classes = array();
        $controlRoutes = array();
        $routeIdentities = array();
        foreach ($pages as $page) {
            $source = (string) $page['source_path'];
            $routeIdentities[$source] = WordPressSitePlan::identity('page', $source, $source);
        }
        $routeClasses = DocumentRootContext::routeClasses(array_values($routeIdentities));
        $authored = array('class' => array(), 'id' => array());
        $context = array('class' => array(), 'id' => array());
        $detachedRoots = array();
        $inheritedContexts = array();
        $chromeElements = array();
        $hookRoutes = array('class' => array(), 'id' => array());
        $detachedHooks = array('class' => array(), 'id' => array());
        $hasSharedNavigation = false;
        foreach ($parts as $part) {
            if (!in_array($part['placement']['kind'] ?? '', array('shared_shell', 'inline_shared_shell'), true)) continue;
            // A one-page inline part still lives under its authored page layout.
            // Its ancestor-scoped rules belong to that page, not global chrome.
            if ('inline_shared_shell' === ($part['placement']['kind'] ?? '') && 1 === count($part['placement']['source_paths'] ?? array())) continue;
            $markup = (string) ($part['canonical_block_markup'] ?? '');
            // A deduplicated control keeps the chosen capture's marker, but
            // renders on every route adopting the part, not just that capture.
            $sources = $part['placement']['source_paths'] ?? array_keys($part['provenance']['sources'] ?? array());
            preg_match_all('/\bblocks-engine-control-[a-f0-9]{12}-[0-9]+\b/', $markup, $controls);
            foreach ($controls[0] as $control) {
                foreach ($sources as $source) {
                    if (isset($routeIdentities[$source])) $controlRoutes[$control][$routeClasses[$routeIdentities[$source]]] = true;
                }
            }
            $hasSharedNavigation = $hasSharedNavigation || 1 === preg_match('/<!--\s*wp:navigation(?:\s|\/|-->)/', $markup);
            // A source-tag marker names every element of its tag on every page
            // under any author CSS, not this chrome: a rule reaching it only
            // through such a marker (`p{…}` from one page's own stylesheet)
            // must stay where its stylesheet applies rather than become global.
            // Each page's own type rules already reach the chrome's elements.
            if (preg_match_all(self::GENERATED_CLASS_PATTERN, $markup, $matches)) foreach ($matches[0] as $class) if (!str_starts_with((string) EngineMarker::markerKind($class), 'source-')) $classes[$class] = true;
            // A device island's class is the gate its visibility CSS selects.
            // The part repeats the island around each variant, so that CSS
            // follows the part to every template rendering it.
            foreach (array(CaptureEvidence::DESKTOP_ONLY_CLASS, CaptureEvidence::MOBILE_ONLY_CLASS) as $class) if (1 === preg_match('/\bclass="(?:[^"]*\s)?' . preg_quote($class, '/') . '(?:\s[^"]*)?"/', $markup)) $classes[$class] = true;
            // Geometry carriers are content-addressed instance CSS, not document
            // markers. Structural shell dedupe can choose a different carrier
            // from the adopting page's capture; its rules must follow the part
            // just like generated attribute and semantic marker rules do.
            if (preg_match_all('/\bbe-inline-geometry-[a-f0-9]{16}(?:-[a-f0-9]{16})?\b/', $markup, $matches)) foreach ($matches[0] as $class) $classes[$class] = true;
            // The chrome's own authored hooks: rules written for them styled it
            // on every page, so they follow it into the shared part.
            foreach (self::authoredChromeElementHooks($markup) as $element) {
                $authored['class'] += $element['class'];
                $authored['id'] += $element['id'];
                $chromeElements[] = $element;
                foreach (array('class', 'id') as $kind) foreach (array_keys($element[$kind]) as $name) foreach ($sources as $source) $hookRoutes[$kind][$name][(string) $source] = true;
            }
            $ancestors = self::selectorHooks((array) ($part['ancestor_context']['classes'] ?? array()), (array) ($part['ancestor_context']['ids'] ?? array()));
            $context['class'] += $ancestors['class'];
            $context['id'] += $ancestors['id'];
            if ('shared_shell' === ($part['placement']['kind'] ?? null) && is_array($part['ancestor_context'] ?? null)) {
                $roots = array();
                foreach (self::partRootBlocks($markup) as $root) {
                    $anchor = (string) ($root['attrs']['anchor'] ?? '');
                    $tokens = preg_split('/\s+/', trim((string) ($root['attrs']['className'] ?? ''))) ?: array();
                    if ('' !== $anchor) $roots[] = '#' . CssIdent::escape($anchor);
                    elseif ('' !== ($tokens[0] ?? '')) $roots[] = '.' . implode('.', array_map(array(CssIdent::class, 'escape'), $tokens));
                }
                foreach ($roots as $root) $detachedRoots[$root] = true;
                if (array() !== $roots) {
                    $inheritedContexts[] = array($ancestors, $roots);
                    $detachedHooks = array_merge_recursive($detachedHooks, self::authoredChromeHooks($markup));
                }
            }
        }
        // Hooks that route content also uses belong to that content too; rules
        // for them stay with the route instead of becoming global chrome rules.
        // So are generated markers route content carries, such as an author
        // attribute selector lowered onto a marker class.
        $routeHooks = array('class' => array(), 'id' => array(), 'marker' => array());
        foreach ($pages as $page) {
            foreach (self::authoredChromeElementHooks(self::withoutLandmarks((string) ($page['canonical_block_markup'] ?? ''))) as $element) {
                foreach ($routeHooks as $kind => $names) $routeHooks[$kind] += $element[$kind];
            }
        }
        // A hook whose rules differ between the routes rendering its chrome
        // carries route state, such as where each route's own layout places
        // the part. One global rule cannot declare both, so it stays with the
        // route as well.
        foreach (self::routeVaryingChromeHooks($assets, $authored, $hookRoutes) as $kind => $names) $routeHooks[$kind] += $names;
        // Rules for a hook no lifted selector can name (an escaped class) stay
        // with the route as well.
        foreach ($authored as $kind => $names) {
            foreach (array_keys($names) as $name) {
                $only = array('class' => array(), 'id' => array());
                $only[$kind][$name] = true;
                if (!self::selectorTargetsAuthoredChrome(('id' === $kind ? '#' : '.') . CssIdent::escape((string) $name), $only, array('class' => array(), 'id' => array()))) $routeHooks[$kind][$name] = true;
            }
        }
        // Keep each chrome element's rules in one stylesheet: an element with any
        // route-kept hook keeps all of its hooks on the route (to a fixed point).
        do {
            $grown = false;
            foreach ($chromeElements as $element) {
                if (array() === array_intersect_key($element['class'], $routeHooks['class']) && array() === array_intersect_key($element['id'], $routeHooks['id']) && array() === array_intersect_key($element['marker'], $routeHooks['marker'])) continue;
                foreach (array('class', 'id', 'marker') as $kind) {
                    if (array() === array_diff_key($element[$kind], $routeHooks[$kind])) continue;
                    $routeHooks[$kind] += $element[$kind];
                    $grown = true;
                }
            }
        } while ($grown);
        $classes = array_diff_key($classes, $routeHooks['marker']);
        unset($routeHooks['marker']);
        foreach ($routeHooks as $kind => $names) $authored[$kind] = array_diff_key($authored[$kind], $names);
        // A route-kept hook that a detached part also carries still styles that
        // part wherever a rule reaches it through the ancestors it was hoisted
        // out of (`#container .footer`): those ancestors are not around the
        // template part, so such a rule never matches it from the route. The
        // rule stays on the route for content still carrying the hook inline,
        // and a re-anchored copy follows the part into shared chrome.
        $detachedRouteHooks = array(
            'class' => array_intersect_key($detachedHooks['class'], $routeHooks['class']),
            'id' => array_intersect_key($detachedHooks['id'], $routeHooks['id']),
        );
        if (!$hasSharedNavigation && array() === $classes && array() === $authored['class'] && array() === $authored['id'] && array() === $detachedRouteHooks['class'] && array() === $detachedRouteHooks['id']) return $assets;
        $classMatcher = self::generatedClassMatcher($classes);
        // Current state is assigned by core on the served route, not saved in
        // the shared part. Projected selectors using only native navigation
        // hooks therefore have no authored/generated class to claim them.
        // Lift them with the menu rather than the capture's matching leaf.
        $targetsChrome = static fn (string $selector): bool => self::selectorTargetsGeneratedClass($selector, $classMatcher)
            || self::selectorTargetsAuthoredChrome($selector, $authored, $context)
            || ($hasSharedNavigation && self::selectorTargetsRuntimeNavigationState($selector));
        $reachesDetachedPart = static fn (string $selector): bool => self::selectorTargetsAuthoredChrome($selector, $detachedRouteHooks, $context)
            && self::reanchoredDetachedContextSelector(trim($selector), $context, array_keys($detachedRoots)) !== trim($selector);
        $liftsToChrome = static fn (string $selector): bool => $targetsChrome($selector) || $reachesDetachedPart($selector);
        $reanchor = static function (string $selector) use ($context, $detachedRoots, $controlRoutes): string {
            $routes = array();
            foreach ($controlRoutes as $control => $classes) {
                if (str_contains($selector, '.' . $control . ')')) $routes += $classes;
            }
            if ($routes) {
                // Rewrite only compiler-owned route gates. Authored root state
                // and all specificity shims remain exactly as projected.
                $scope = ':where(:root:is(.' . implode(',.', array_keys($routes)) . '))';
                $selector = preg_replace('/:where\(:root(?::(?:is|not)\((?:\.be-r-[a-f0-9]+,?)+\)|\.be-r(?::not\((?:\.be-r-[a-f0-9]+,?)+\))?)\)/', $scope, $selector) ?? $selector;
                $root = substr($scope, strlen(':where('), -1);
                $selector = preg_replace('/:where\((:root(?:\.be-rs-[a-f0-9]+|:not\(\.be-rs-[a-f0-9]+\))),\1 \*\)/', ':where(' . $root . ',' . $root . ' *)', $selector) ?? $selector;
            }
            return self::reanchoredDetachedContextSelector(trim($selector), $context, array_keys($detachedRoots));
        };

        // Lift chrome once per occurrence media contract, while retaining one
        // authored payload resource. Route instances still own their conditions.
        $chromeAssets = array();
        foreach ($assets as $asset) {
            $media = array();
            foreach ($asset['inline_references'] ?? array() as $reference) $media[(string) ($reference['source_media'] ?? $reference['media'] ?? '')] = true;
            if (count($media) > 1) {
                foreach (array_keys($media) as $condition) {
                    $copy = $asset;
                    $copy['media'] = $condition;
                    $copy['_inline_media_projection'] = array_key_exists('media', $asset) ? array($asset['media']) : array();
                    $chromeAssets[] = $copy;
                }
            } else {
                if (1 === count($media)) $asset['media'] = (string) array_key_first($media);
                $chromeAssets[] = $asset;
            }
        }
        $projected = array();
        $emittedShared = array();
        $emittedSources = array();
        // A per-media copy exists only to lift chrome under its condition; the
        // authored payload it came from is still one asset with one target.
        $keptInline = array();
        $keep = static function (array $asset) use (&$projected, &$keptInline): void {
            if (isset($asset['_inline_media_projection'])) {
                if (isset($keptInline[$asset['source_path']])) return;
                $keptInline[$asset['source_path']] = true;
                $media = $asset['_inline_media_projection'];
                unset($asset['_inline_media_projection'], $asset['media']);
                if (array() !== $media) $asset['media'] = $media[0];
            }
            $projected[] = $asset;
        };
        foreach ($chromeAssets as $asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null) || '' === trim($asset['content']) || !StylesheetActivation::active($asset)) {
                $keep($asset);
                continue;
            }
            $matched = false;
            $unparseable = false;
            $shared = (new CssStylesheetTransformer())->transformStyleRules(
                $asset['content'],
                static function (string $prelude, string $body) use ($liftsToChrome, $reanchor, $inheritedContexts, $liveElementMarkers, $classMatcher, &$matched, &$unparseable): string {
                    $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
                    if (null === $selectors) {
                        $unparseable = true;
                        return '';
                    }
                    $inherited = '';
                    $declarations = \Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer::declarations($body, array(
                        '--*', 'color', 'font', 'font-family', 'font-size', 'font-style', 'font-weight', 'font-variant', 'font-stretch',
                        'font-feature-settings', 'font-variation-settings', 'font-kerning', 'direction', 'hyphens', 'letter-spacing',
                        'line-height', 'tab-size', 'text-align', 'text-indent', 'text-shadow', 'text-transform',
                        'visibility', 'white-space', 'word-spacing', 'writing-mode',
                    ));
                    if (array() !== $declarations) {
                        $subjects = array();
                        foreach ($inheritedContexts as [$ancestorHooks, $roots]) foreach ($selectors as $selector) {
                            $subject = self::reanchoredDetachedContextSelector(trim($selector), $ancestorHooks, $roots, true);
                            if ('' !== $subject) $subjects[$subject] = true;
                        }
                        if (array() !== $subjects) {
                            $inherited = implode(',', array_keys($subjects)) . '{' . implode(';', array_map(static fn(array $declaration): string => $declaration['name'] . ':' . $declaration['value'], $declarations)) . '}';
                            $matched = true;
                        }
                    }
                    $kept = array_values(array_filter($selectors, $liftsToChrome));
                    if (array() === $kept) return $inherited;
                    // A grouped selector lifts only the alternatives the parts carry.
                    $kept = array_values(array_filter(array_map(static fn (string $selector): ?string => self::generatedAlternatives($selector, $classMatcher, true), $kept), 'is_string'));
                    if (array() === $kept) return $inherited;
                    if (null !== $liveElementMarkers) {
                        $kept = array_values(array_filter(array_map(static fn (string $selector): ?string => DeadElementMarkers::withoutDead($selector, $liveElementMarkers), $kept), 'is_string'));
                        // Every selector named only replaced elements: the rule matches nothing.
                        if (array() === $kept) return $inherited;
                    }
                    $matched = true;
                    return $inherited . implode(',', array_map($reanchor, $kept)) . '{' . $body . '}';
                }
            );
            if (!$matched || $unparseable) {
                $keep($asset);
                continue;
            }
            $route = (new CssStylesheetTransformer())->transformStyleRules(
                $asset['content'],
                static function (string $prelude, string $body) use ($targetsChrome, $classMatcher): string {
                    $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
                    if (null === $selectors) return $prelude . '{' . $body . '}';
                    // Alternatives naming only route content stay in source order on the route.
                    $kept = array_values(array_filter(array_map(static fn (string $selector): ?string => ! $targetsChrome($selector) ? $selector : self::generatedAlternatives($selector, $classMatcher, false), $selectors), 'is_string'));
                    return array() === $kept ? '' : implode(',', $kept) . '{' . $body . '}';
                }
            );
            $contract = self::sharedChromeContract($asset);
            $sharedAsset = $asset;
            // Address the target by what is enqueued, not only the text: the same
            // rules under another media condition, target or capture device are another asset.
            $sharedAsset['path'] = 'assets/css/shared-chrome-' . substr(hash('sha256', $contract . "\0" . $shared), 0, 16) . '.css';
            $sharedAsset['target_path'] = $sharedAsset['path'];
            $sharedAsset['source_path'] = (string) ($asset['source_path'] ?? $asset['path'] ?? '') . '.shared-chrome';
            // Relative url() references still resolve against the stylesheet the
            // rules were projected from, not the synthetic shared-chrome identity.
            $sharedAsset['reference_origin'] = (string) ($asset['source_path'] ?? $asset['path'] ?? '');
            // Static capture-device projections freeze source ancestry, unlike
            // live navigation state that WordPress supplies on every route.
            // Lifting those page-owned editor rules must not widen their scope.
            $scopeClasses = array();
            $global = false;
            foreach ($asset['scopes'] ?? array() as $scope) {
                if ('global' === ($scope['kind'] ?? null)) $global = true;
                $source = $scope['source_path'] ?? '';
                if (isset($routeIdentities[$source])) $scopeClasses[$routeClasses[$routeIdentities[$source]]] = true;
            }
            if ('editor-static-state' === ($asset['source'] ?? null) && !$global && $scopeClasses) {
                $gate = ':where(:root:is(.' . implode(',.', array_keys($scopeClasses)) . '))';
                $shared = (new CssStylesheetTransformer())->transformStyleRules($shared, static function (string $prelude, string $body) use ($gate): string {
                    $arms = CssStylesheetTransformer::splitSelectorList($prelude) ?? array($prelude);
                    return implode(',', array_map(static fn(string $arm): string => preg_match('/\\[\\s*(?:' . preg_quote(EngineMarker::DEVICE_SCOPE_ATTRIBUTE, '/') . '|' . preg_quote(CaptureEvidence::DEVICE_DOCUMENT, '/') . ')\\b/i', $arm) ? $gate . ' ' . $arm : $arm, $arms)) . '{' . $body . '}';
                });
                $sharedAsset['path'] = $sharedAsset['target_path'] = 'assets/css/shared-chrome-' . substr(hash('sha256', $contract . "\0" . $shared), 0, 16) . '.css';
            }
            $sharedAsset = WordPressSitePlan::withRewrittenTextContent($sharedAsset, $shared);
            $sharedAsset['scopes'] = array(array('kind' => 'global'));
            $sharedAsset['token'] = 'asset-' . substr(hash('sha256', $sharedAsset['target_path']), 0, 16);
            $sharedAsset['reconciliation_identity'] = WordPressSitePlan::identity('asset', $sharedAsset['source_path'], $sharedAsset['target_path']);
            // Global chrome is not an occurrence of the stylesheet it was lifted
            // from; PageStylesheets::place() gives it a slot on every
            // route, next to whichever contributing stylesheet that route applies.
            unset($sharedAsset['inline_references'], $sharedAsset['stylesheet_instances'], $sharedAsset['stylesheet_parent'], $sharedAsset['stylesheet_inline_order']);
            $sharedAsset['_stylesheet_projection_origins'] = array(self::stylesheetProjectionOrigin($asset));
            $asset = WordPressSitePlan::withRewrittenTextContent($asset, $route);
            $keep($asset);
            unset($sharedAsset['_inline_media_projection']);
            // Media/target contracts and relative URL resolution must agree:
            // otherwise the slices cannot share a resource or reference origin.
            // Consolidate compatible chrome slices at the planning boundary,
            // before tokens and enqueue instances are consumed. Keep their
            // existing cascade order; only earlier identical rules are redundant.
            foreach ($emittedShared as $emissions) {
                foreach ($emissions as $emitted) {
                    $index = $emitted['index'];
                    $existing = $projected[$index];
                    if ($emitted['contract'] !== $contract
                        || !self::sharedChromeOriginsAgree($shared, $emitted['origin'], (string) $sharedAsset['reference_origin'], $references)) continue;
                    $combined = (new CssStylesheetTransformer())->concatenateWithoutRedundantRules(array($existing['content'], $shared));
                    $existing = WordPressSitePlan::withRewrittenTextContent($existing, $combined);
                    $existing['path'] = $existing['target_path'] = 'assets/css/shared-chrome-' . substr(hash('sha256', $contract . "\0" . $combined), 0, 16) . '.css';
                    $existing['token'] = 'asset-' . substr(hash('sha256', $existing['target_path']), 0, 16);
                    $existing['reconciliation_identity'] = WordPressSitePlan::identity('asset', $existing['source_path'], $existing['target_path']);
                    $existing['_stylesheet_projection_origins'][] = self::stylesheetProjectionOrigin($asset);
                    $projected[$index] = $existing;
                    continue 3;
                }
            }
            // Per-media copies of one inline stylesheet lift under different
            // contracts, so they stay separate assets and need separate sources.
            // Number repeats like target collisions; keep the .shared-chrome end.
            $sharedSource = (string) $sharedAsset['source_path'];
            $emittedSources[$sharedSource] = ($emittedSources[$sharedSource] ?? 0) + 1;
            if (1 < $emittedSources[$sharedSource]) {
                $sharedAsset['source_path'] = substr($sharedSource, 0, -strlen('.shared-chrome')) . '-' . $emittedSources[$sharedSource] . '.shared-chrome';
                $sharedAsset['reconciliation_identity'] = WordPressSitePlan::identity('asset', $sharedAsset['source_path'], $sharedAsset['target_path']);
            }
            $sharedKey = strtolower((string) $sharedAsset['path']);
            if (isset($emittedShared[$sharedKey])) {
                $collidingTarget = (string) $sharedAsset['target_path'];
                $sharedAsset['path'] = $sharedAsset['target_path'] = substr($collidingTarget, 0, -strlen('.css')) . '-' . (count($emittedShared[$sharedKey]) + 1) . '.css';
                $sharedAsset['token'] = 'asset-' . substr(hash('sha256', $sharedAsset['target_path']), 0, 16);
                $sharedAsset['reconciliation_identity'] = WordPressSitePlan::identity('asset', $sharedAsset['source_path'], $sharedAsset['target_path']);
                $collisions[] = array('source_path' => (string) $sharedAsset['source_path'], 'colliding_source_path' => (string) $projected[$emittedShared[$sharedKey][0]['index']]['source_path'], 'target_path' => $collidingTarget, 'resolved_target_path' => (string) $sharedAsset['target_path']);
            }
            $emittedShared[$sharedKey][] = array('origin' => (string) $sharedAsset['reference_origin'], 'contract' => $contract, 'index' => count($projected));
            $projected[] = $sharedAsset;
        }
        return $projected;
    }

    /**
     * The resource whose instances position a projection lifted out of $asset:
     * the asset itself, or the stylesheet a projected page slice joins. The
     * slice's scopes still bound which documents apply it.
     *
     * @param array<string,mixed> $asset
     * @return array{source_path:string,scopes:array<int,array<string,mixed>>}
     */
    private static function stylesheetProjectionOrigin(array $asset): array
    {
        return array('source_path' => (string) ($asset['stylesheet_parent'] ?? $asset['source_path'] ?? ''), 'scopes' => is_array($asset['scopes'] ?? null) ? $asset['scopes'] : array(array('kind' => 'global')));
    }

    private static function sharedChromeContract(array $asset): string
    {
        $devices = array();
        foreach ($asset['stylesheet_instances'] ?? array() as $instance) {
            foreach (PageStylesheets::deviceAttributes($instance) as $attribute => $value) {
                if (is_string($value)) $devices[$attribute . "\0" . $value] = true;
            }
        }
        $devices = array_keys($devices);
        sort($devices, SORT_STRING);
        return (string) ($asset['media'] ?? '') . "\0" . (string) ($asset['stylesheet_target'] ?? 'both') . "\0" . ('engine-support' === ($asset['source'] ?? '') ? 'engine' : 'author') . "\0" . implode("\0", $devices);
    }

    private static function sharedChromeOriginsAgree(string $css, string $origin, string $other, ?AssetReferenceCanonicalizer $references): bool
    {
        if ($origin === $other || self::stylesheetDirectory($origin) === self::stylesheetDirectory($other)) return true;
        foreach (self::originSensitiveReferences($css) as $reference) {
            $left = $references?->reference($reference, $origin);
            $right = $references?->reference($reference, $other);
            if ($left !== $right) return false;
            if (null === $left && ArtifactPath::resolveRelativePath($reference, $origin) !== ArtifactPath::resolveRelativePath($reference, $other)) return false;
        }
        return true;
    }

    private static function stylesheetDirectory(string $path): string
    {
        $directory = str_replace('\\', '/', dirname($path));
        return in_array($directory, array('.', '/', ''), true) ? '' : $directory;
    }

    /** @return array<int,string> */
    private static function originSensitiveReferences(string $css): array
    {
        $references = array();
        $collect = static function (string $reference) use (&$references): void {
            $reference = trim($reference);
            if ('' === $reference || 1 === preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#|\?|/)~i', $reference)) return;
            $references[] = $reference;
        };
        CssUrlRewriter::rewrite($css, static function (string $reference) use ($collect): string {
            $collect($reference);
            return $reference;
        });
        if (preg_match_all('/@import\s+(?:url\(\s*)?(?:"([^"]*)"|\'([^\']*)\'|([^\s\)"\';]+))/i', \Automattic\BlocksEngine\PhpTransformer\Css\CssSyntaxScanner::withoutComments($css), $matches, PREG_SET_ORDER)) foreach ($matches as $match) $collect((string) (($match[1] ?? '') ?: ($match[2] ?? '') ?: ($match[3] ?? '')));
        return $references;
    }
}
