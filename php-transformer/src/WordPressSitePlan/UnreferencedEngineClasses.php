<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;

/**
 * Engine classes in block markup that nothing emitted names.
 *
 * The mirror of DeadElementMarkers: that pass drops selectors naming a marker
 * no markup carries; this one drops selector hooks (EngineMarker::isSelectorHook())
 * from markup when no stylesheet or script delivered to the frontend or the
 * editor canvas, no runtime declaration and no entity record names them. An
 * editor anchor stays while its id is delivered: it is that id in the editor
 * canvas. A class no selector names changes no match, so the
 * markup renders the same and the inspector's Additional CSS class(es) field
 * lists fewer generated names. A hook is kept everywhere or dropped
 * everywhere, so a block comment's className and its saved HTML agree.
 */
final class UnreferencedEngineClasses
{
    /** Engine-generated class names: `blocks-engine-*` and `be-*`. */
    private const ENGINE_CLASS = '/^(?:blocks-engine-|be-)[A-Za-z0-9_-]+$/D';

    /**
     * A navigation instance names the menu itself, not a route's styling: every
     * route serializes the same navigation block, whichever rules it ships.
     */
    private const IDENTITY_KINDS = array('navigation-instance');

    /** An engine identifier in emitted text, read whole. */
    private const ENGINE_IDENT = '/(?<![A-Za-z0-9_-])(?:blocks-engine-|be-)[A-Za-z0-9_-]+/';

    private const HTML_CLASS = '/(\sclass=")([^"]*)(")/';

    private const HTML_ID = '/\sid="([^"]+)"/';

    /** Block comment attributes; WordPress escapes `--` and quotes in their JSON. */
    private const BLOCK_COMMENT = '/<!--\s+wp:[a-z0-9\/-]+\s+\{.*?\}\s+\/?-->/s';

    private const COMMENT_CLASS = '/"(className|class)":"([^"]*)"/';

    /** Class attribute predicates that test the whole attribute text. */
    private const CLASS_PREDICATE = '/\[\s*class\s*([*^$|]?)=\s*(?:"([^"\\\\]*)"|\'([^\'\\\\]*)\'|([A-Za-z0-9_-]+))\s*([iIsS])?\s*\]/';

    /**
     * Everything the plan delivers beside the markup that may name a class:
     * every stylesheet and script asset, whether it loads on the frontend,
     * in the editor canvas or both (editor-only styles included), the
     * stylesheets the theme bootstrap inlines for either, and every string
     * leaf of the other values (runtime declarations and records, theme
     * settings, document roots).
     *
     * Null when a delivered stylesheet or script cannot be read here (its
     * bytes live behind a payload reference): what it names is unknown, so
     * nothing may be dropped.
     *
     * @param array<int,array<string,mixed>> $assets
     */
    public static function references(array $assets, mixed ...$values): ?string
    {
        $text = array(ThemeBootstrap::inlineStylesheets());
        foreach ($assets as $asset) {
            if (!in_array($asset['kind'] ?? null, array('css', 'js'), true)) continue;
            $content = self::assetText($asset);
            if (null === $content) return null;
            $text[] = $content;
        }
        array_walk_recursive($values, static function (mixed $value) use (&$text): void {
            if (is_string($value)) $text[] = $value;
        });
        return implode("\n", $text);
    }

    /** @param array<string,mixed> $asset */
    private static function assetText(array $asset): ?string
    {
        if (is_string($asset['content'] ?? null)) return $asset['content'];
        if (is_string($asset['content_base64'] ?? null)) {
            $decoded = base64_decode($asset['content_base64'], true);
            return false === $decoded ? null : $decoded;
        }
        return null === ($asset['payload_reference'] ?? null) ? '' : null;
    }

    /**
     * The selector hooks to drop from `$markups`: those `$references` does not
     * name. Apply them to each markup with without().
     *
     * A hook stays when dropping it could still change a match: the carrier
     * would be left without classes, a class attribute predicate
     * (`[class*="…"]`) would test differently, or it also appears outside a
     * class list.
     *
     * @param array<array-key,string> $markups
     * @return array<string,true>
     */
    public static function unreferenced(array $markups, ?string $references): array
    {
        if (null === $references) return array();
        $referenced = self::referenced($references) + self::editorCanvasAnchors($markups);
        $lists = array();
        foreach ($markups as $markup) foreach (self::classLists($markup) as $list) $lists[] = $list;
        $candidates = array();
        foreach ($lists as $list) foreach (self::tokens($list['value']) as $token) {
            if (EngineMarker::isSelectorHook($token) && !in_array(EngineMarker::markerKind($token), self::IDENTITY_KINDS, true) && 1 === preg_match(self::ENGINE_CLASS, $token) && !self::isReferenced($token, $referenced)) $candidates[$token] = true;
        }
        $predicates = self::predicates($references);
        do {
            $before = count($candidates);
            foreach ($lists as $list) {
                $tokens = self::tokens($list['value']);
                $kept = array_values(array_filter($tokens, static fn (string $token): bool => !isset($candidates[$token])));
                if (count($kept) === count($tokens)) continue;
                if (('html' === $list['context'] && array() === $kept) || !self::sameMatches($predicates, $list['value'], implode(' ', $kept))) {
                    foreach ($tokens as $token) unset($candidates[$token]);
                }
            }
            // A hook named outside a class list (a style marker, a runtime
            // attribute) is not ours to drop.
            if (array() !== $candidates) foreach ($markups as $markup) {
                foreach (self::referenced(self::without($markup, $candidates)) as $token => $_) unset($candidates[$token]);
            }
        } while (count($candidates) !== $before);
        return $candidates;
    }

    /**
     * How much of the plan's block markup classes are engine-generated, and
     * how many engine classes no emitted stylesheet, script or runtime names.
     *
     * @param array<string,mixed> $plan
     * @return array{unreferenced_engine_class_count:int,engine_class_token_share:float}
     */
    public static function metrics(array $plan): array
    {
        $emitted = array();
        foreach ($plan['writes'] ?? array() as $write) {
            if (!in_array($write['kind'] ?? null, array('theme_template', 'theme_template_part'), true)) $emitted[] = $write['payload']['data'] ?? null;
        }
        $references = self::references($plan['assets'] ?? array(), $emitted, $plan['runtime_declarations'] ?? array(), $plan['runtime_records'] ?? array(), $plan['runtime_entity_records'] ?? array(), $plan['theme'] ?? array(), $plan['visual_repair'] ?? array(), array_column($plan['pages'] ?? array(), 'document_metadata'));
        $markups = array_values(array_filter(array_map(self::markup(...), self::units($plan)), 'is_string'));
        $referenced = self::referenced((string) $references) + self::editorCanvasAnchors($markups);
        $tokens = 0;
        $engine = 0;
        $unreferenced = 0;
        foreach ($markups as $markup) {
            foreach (self::classLists($markup) as $list) foreach (self::tokens($list['value']) as $token) {
                ++$tokens;
                if (1 !== preg_match(self::ENGINE_CLASS, $token)) continue;
                ++$engine;
                if (null !== $references && !self::isReferenced($token, $referenced)) ++$unreferenced;
            }
        }
        return array('unreferenced_engine_class_count' => $unreferenced, 'engine_class_token_share' => 0 === $tokens ? 0.0 : round($engine / $tokens, 4));
    }

    /** @param array<string,mixed> $plan @return list<array<string,mixed>> */
    private static function units(array $plan): array
    {
        $units = array();
        foreach (array('pages', 'template_parts', 'templates', 'synced_patterns', 'menus') as $group) {
            foreach ($plan[$group] ?? array() as $unit) if (is_array($unit)) $units[] = $unit;
        }
        return $units;
    }

    /** @param array<string,mixed> $unit */
    private static function markup(array $unit): ?string
    {
        $markup = $unit['canonical_block_markup'] ?? $unit['block_markup'] ?? null;
        return is_string($markup) ? $markup : null;
    }

    /**
     * The editor canvas copy of every id the markup delivers. Gutenberg does
     * not render a block's anchor as an id in the canvas, so there the
     * editor-anchor class is the element's id: whatever finds the element by
     * that id on the frontend finds it by this class in the editor.
     *
     * @param array<array-key,string> $markups
     * @return array<string,true>
     */
    private static function editorCanvasAnchors(array $markups): array
    {
        $anchors = array();
        foreach ($markups as $markup) {
            if (!preg_match_all(self::HTML_ID, $markup, $matches)) continue;
            foreach ($matches[1] as $id) $anchors[EngineMarker::editorAnchorClass(html_entity_decode($id, ENT_QUOTES | ENT_HTML5))] = true;
        }
        return $anchors;
    }

    /** @return array<string,true> */
    private static function referenced(string $text): array
    {
        return preg_match_all(self::ENGINE_IDENT, $text, $matches) ? array_fill_keys($matches[0], true) : array();
    }

    /**
     * The runtime that replays a carrier's source class tokens adds their
     * subject classes only to carriers with the subject base class, so the
     * base stays wherever a subject class is named.
     *
     * @param array<string,true> $referenced
     */
    private static function isReferenced(string $token, array $referenced): bool
    {
        if (isset($referenced[$token])) return true;
        if (EngineMarker::SUBJECT_BASE_CLASS !== $token) return false;
        foreach ($referenced as $name => $_) if (str_starts_with($name, EngineMarker::SUBJECT_CLASS_PREFIX)) return true;
        return false;
    }

    /**
     * Class lists in block markup: HTML `class` attributes and the `className`
     * or wrapper `class` attributes block comments carry.
     *
     * @return list<array{context:string,value:string}>
     */
    private static function classLists(string $markup): array
    {
        $lists = array();
        if (preg_match_all(self::HTML_CLASS, $markup, $matches)) foreach ($matches[2] as $value) $lists[] = array('context' => 'html', 'value' => html_entity_decode($value, ENT_QUOTES | ENT_HTML5));
        if (preg_match_all(self::BLOCK_COMMENT, $markup, $comments)) foreach ($comments[0] as $comment) {
            if (preg_match_all(self::COMMENT_CLASS, $comment, $matches)) foreach ($matches[2] as $value) $lists[] = array('context' => 'comment', 'value' => $value);
        }
        return $lists;
    }

    /** @return list<string> */
    private static function tokens(string $value): array
    {
        return preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: array();
    }

    /**
     * `$markup` without the `$drop` classes in its class lists. A prefix that
     * ends between tags maps to the prefix of the result.
     *
     * @param array<string,true> $drop
     */
    public static function without(string $markup, array $drop): string
    {
        if (array() === $drop) return $markup;
        $filter = static function (string $value) use ($drop): string {
            $tokens = self::tokens($value);
            $kept = array_values(array_filter($tokens, static fn (string $token): bool => !isset($drop[$token])));
            return count($kept) === count($tokens) ? $value : implode(' ', $kept);
        };
        $markup = preg_replace_callback(self::HTML_CLASS, static fn (array $match): string => $match[1] . $filter($match[2]) . $match[3], $markup) ?? $markup;
        return preg_replace_callback(self::BLOCK_COMMENT, static function (array $comment) use ($filter): string {
            $rewritten = preg_replace_callback(self::COMMENT_CLASS, static function (array $match) use ($filter): string {
                $value = $filter($match[2]);
                return 'className' === $match[1] && '' === $value && '' !== trim($match[2]) ? '"className":null' : '"' . $match[1] . '":"' . $value . '"';
            }, $comment[0]) ?? $comment[0];
            // An emptied className is no attribute at all.
            return (string) preg_replace(array('/,"className":null(?=[,}])/', '/(?<=\{)"className":null,?/', '/\s\{\}(?=\s+\/?-->)/'), '', $rewritten);
        }, $markup) ?? $markup;
    }

    /** @return list<array{operator:string,value:string,insensitive:bool}> */
    private static function predicates(string $references): array
    {
        if (!str_contains($references, 'class') || !preg_match_all(self::CLASS_PREDICATE, $references, $matches, PREG_SET_ORDER)) return array();
        $predicates = array();
        foreach ($matches as $match) {
            $value = ($match[2] ?? '') . ($match[3] ?? '') . ($match[4] ?? '');
            $predicates[$match[1] . "\0" . $value . "\0" . strtolower($match[5] ?? '')] = array('operator' => $match[1], 'value' => $value, 'insensitive' => 'i' === strtolower($match[5] ?? ''));
        }
        return array_values($predicates);
    }

    /** @param list<array{operator:string,value:string,insensitive:bool}> $predicates */
    private static function sameMatches(array $predicates, string $before, string $after): bool
    {
        foreach ($predicates as $predicate) {
            if (self::matches($predicate, $before) !== self::matches($predicate, $after)) return false;
        }
        return true;
    }

    /** @param array{operator:string,value:string,insensitive:bool} $predicate */
    private static function matches(array $predicate, string $text): bool
    {
        $value = $predicate['value'];
        if ($predicate['insensitive']) {
            $value = strtolower($value);
            $text = strtolower($text);
        }
        return match ($predicate['operator']) {
            '' => $text === $value,
            '|' => $text === $value || str_starts_with($text, $value . '-'),
            '*' => '' !== $value && str_contains($text, $value),
            '^' => '' !== $value && str_starts_with($text, $value),
            '$' => '' !== $value && str_ends_with($text, $value),
            default => true,
        };
    }
}
