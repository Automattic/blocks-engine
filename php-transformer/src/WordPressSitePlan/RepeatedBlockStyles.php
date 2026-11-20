<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlTagScanner;

/**
 * Moves a block style value that repeats across the blocks of one type into
 * a theme.json block-type style (`styles.blocks.<block>.spacing.margin`) and
 * drops it from those blocks' inline style (#7120).
 *
 * A captured route keeps the vertical margins of its paragraphs, headings,
 * list items, images and groups even when they are zero, since the user agent
 * and WordPress would otherwise set them (#7099). Under a global reset every
 * such block carries the same `margin-top:0px;margin-bottom:0px`. WordPress
 * prints the block-type style as `:root :where(<selector>){…}` in the global
 * styles, on the frontend and in the editor canvas, after the root layout
 * rules (`:root :where(.is-layout-flow) > *`) at the same 0-1-0 specificity,
 * so it wins over those as the inline value did.
 *
 * theme.json is site-wide, so a value is hoisted only when it cannot restyle
 * anything else:
 *
 * - Every element the block type's style selector reaches in the emitted
 *   documents is a block of that type, and every one of them carries its own
 *   value for the side (a box with no user-agent margin counts as carrying
 *   zero). A block with a different value keeps it inline, which still wins.
 * - Blocks lose the value only on engine-owned routes, whose stylesheets the
 *   engine writes, and only where nothing else competes for the side: no
 *   delivered rule names the block's or its parent's classes with that
 *   margin, its parent has no block gap (core's per-container gap rule is
 *   printed after global styles), it is not aligned, no device runtime
 *   rewrites its style, and no runtime binding anchors on its markup.
 * - At least two blocks lose it: a value one block carries is not repeated.
 *
 * @internal
 */
final class RepeatedBlockStyles
{
    /**
     * The elements each hoistable block type's theme.json selector reaches:
     * `p` for core/paragraph, the block class for the others
     * (`.wp-block-list > li` is approximated by every `li`).
     */
    private const MEMBERS = array(
        'core/paragraph' => array('tag' => 'p'),
        'core/heading' => array('class' => 'wp-block-heading'),
        'core/list-item' => array('tag' => 'li'),
        'core/image' => array('class' => 'wp-block-image'),
        'core/group' => array('class' => 'wp-block-group'),
    );

    /** Dynamic blocks whose server render is a bare `<p>` the paragraph style would reach. */
    private const DYNAMIC_PARAGRAPHS = array('core/site-title', 'core/site-tagline', 'core/post-excerpt', 'core/comment-content', 'core/term-description', 'core/tag-cloud', 'core/latest-comments', 'core/rss');

    /** Group tags with no user-agent margin. */
    private const MARGINLESS_GROUP_TAGS = array('div', 'section', 'article', 'main', 'header', 'footer', 'aside', 'nav');

    /** Declarations that set each physical margin side (horizontal, left-to-right writing). */
    private const SIDE_PROPERTIES = array(
        'top' => array('margin', 'margin-top', 'margin-block', 'margin-block-start'),
        'bottom' => array('margin', 'margin-bottom', 'margin-block', 'margin-block-end'),
        'left' => array('margin', 'margin-left', 'margin-inline', 'margin-inline-start'),
        'right' => array('margin', 'margin-right', 'margin-inline', 'margin-inline-end'),
    );

    private const MIN_REPEATS = 2;

    /**
     * @param array<string,array{markup:string,strippable:bool}> $documents Emitted documents by key.
     * @param list<string> $observed Other emitted markup the theme renders, read but never rewritten.
     * @param list<array<string,mixed>> $assets Delivered stylesheet assets.
     * @param list<string> $anchors Block markup runtime bindings search for.
     * @return array{markup:array<string,string>,blocks:array<string,array<string,mixed>>}
     */
    public static function hoist(array $documents, array $observed, array $assets, array $anchors = array()): array
    {
        $blocks = array();
        $exposure = array();
        foreach ($documents as $key => $document) {
            foreach (self::blocks($document['markup']) as $block) $blocks[] = $block + array('document' => $key, 'strippable' => $document['strippable']);
            self::countMembers($document['markup'], $exposure);
        }
        foreach ($observed as $markup) {
            foreach (self::blocks($markup) as $block) $blocks[] = $block + array('document' => null, 'strippable' => false);
            self::countMembers($markup, $exposure);
        }
        $classRules = self::classRules($assets);
        $protected = array();
        foreach ($documents as $key => $document) $protected[$key] = self::protectedRanges($document['markup'], $anchors);

        $theme = array();
        $edits = array();
        foreach (self::MEMBERS as $type => $_) {
            $typed = array_values(array_filter($blocks, static fn(array $block): bool => $block['name'] === $type || ('core/paragraph' === $type && in_array($block['name'], self::DYNAMIC_PARAGRAPHS, true))));
            $statics = count(array_filter($typed, static fn(array $block): bool => $block['name'] === $type && $block['member']));
            // An element the selector reaches that is no block of the type.
            if (($exposure[$type] ?? 0) > $statics) continue;
            foreach (array_keys(self::SIDE_PROPERTIES) as $side) {
                $value = self::dominant($typed, $side);
                if (null === $value || !self::everyCarries($typed, $side, $value)) continue;
                $stripped = array();
                foreach ($typed as $block) {
                    if ($block['name'] !== $type || $value !== ($block['margin'][$side] ?? null) || !$block['strippable']) continue;
                    if (!self::strippable($block, $side, $classRules, $protected[$block['document']] ?? array())) continue;
                    $stripped[] = $block;
                }
                if (count($stripped) < self::MIN_REPEATS) continue;
                $theme[$type]['spacing']['margin'][$side] = $value;
                foreach ($stripped as $block) $edits[$block['document']][$block['offset']]['sides'][$side] = self::declaration($side, $value);
                foreach ($stripped as $block) $edits[$block['document']][$block['offset']]['block'] = $block;
            }
        }

        $markup = array();
        foreach ($edits as $key => $byOffset) {
            krsort($byOffset);
            $text = $documents[$key]['markup'];
            foreach ($byOffset as $edit) $text = self::withoutSides($text, $edit['block'], $edit['sides']);
            $markup[$key] = $text;
        }
        return array('markup' => $markup, 'blocks' => $theme);
    }

    /**
     * Each block's opening delimiter, attributes, root tag and parent.
     *
     * @return list<array<string,mixed>>
     */
    private static function blocks(string $markup): array
    {
        $blocks = array();
        $stack = array();
        if (!preg_match_all('/<!--\s+(\/)?wp:([a-z0-9-]+(?:\/[a-z0-9-]+)?)(?:\s+(\{.*?\}))?\s+(\/)?-->/s', $markup, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return array();
        foreach ($matches as $match) {
            if ('/' === ($match[1][0] ?? '')) { array_pop($stack); continue; }
            $name = str_contains($match[2][0], '/') ? $match[2][0] : 'core/' . $match[2][0];
            $json = (string) ($match[3][0] ?? '');
            $attributes = '' === $json ? new \stdClass() : json_decode($json, false);
            $end = $match[0][1] + strlen($match[0][0]);
            $selfClosing = '/' === ($match[4][0] ?? '');
            $tag = null;
            if (!$selfClosing && 1 === preg_match('/\G\s*(<([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*>)/', $markup, $tagMatch, PREG_OFFSET_CAPTURE, $end)) {
                $tag = array('offset' => $tagMatch[1][1], 'html' => $tagMatch[1][0], 'name' => strtolower($tagMatch[2][0]));
            }
            $parent = array() === $stack ? null : $blocks[end($stack)];
            $margin = $attributes instanceof \stdClass && isset($attributes->style->spacing->margin) && $attributes->style->spacing->margin instanceof \stdClass ? array_filter(get_object_vars($attributes->style->spacing->margin), 'is_string') : array();
            $blocks[] = array(
                'name' => $name,
                'offset' => $match[0][1],
                'delimiter' => $match[0][0],
                'json' => $json,
                'attributes' => $attributes instanceof \stdClass ? $attributes : null,
                'tag' => $tag,
                'member' => null !== $tag && self::isMember($name, $tag),
                'margin' => $margin,
                'parent_gap' => null !== $parent && null !== ($parent['attributes']->style->spacing->blockGap ?? null),
                'parent_classes' => null === $parent || null === $parent['tag'] ? array() : self::classes($parent['tag']['html']),
            );
            if (!$selfClosing) $stack[] = count($blocks) - 1;
        }
        return $blocks;
    }

    /** @param array{name:string,html:string} $tag */
    private static function isMember(string $type, array $tag): bool
    {
        $member = self::MEMBERS[$type] ?? null;
        if (null === $member) return false;
        return isset($member['tag']) ? $member['tag'] === $tag['name'] : in_array($member['class'], self::classes($tag['html']), true);
    }

    /** @return list<string> */
    private static function classes(string $tag): array
    {
        return preg_split('/\s+/', trim(HtmlTagScanner::attribute($tag, 'class')), -1, PREG_SPLIT_NO_EMPTY) ?: array();
    }

    /** @param array<string,int> $exposure Elements each block type's selector reaches. */
    private static function countMembers(string $markup, array &$exposure): void
    {
        if (!preg_match_all('/<([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*>/', $markup, $matches, PREG_SET_ORDER)) return;
        foreach ($matches as $match) {
            $tag = array('name' => strtolower($match[1]), 'html' => $match[0]);
            foreach (array_keys(self::MEMBERS) as $type) if (self::isMember($type, $tag)) $exposure[$type] = ($exposure[$type] ?? 0) + 1;
        }
    }

    /** The value most blocks of the type carry for a side, the first one on a tie. */
    private static function dominant(array $blocks, string $side): ?string
    {
        $counts = array();
        foreach ($blocks as $block) if (isset($block['margin'][$side])) $counts[$block['margin'][$side]] = ($counts[$block['margin'][$side]] ?? 0) + 1;
        if (array() === $counts) return null;
        arsort($counts);
        return (string) array_key_first($counts);
    }

    /**
     * Whether the block-type value leaves every block the selector reaches
     * as it renders now: each carries its own value, or has no margin on that
     * side for the user agent to set and the value is zero.
     */
    private static function everyCarries(array $blocks, string $side, string $value): bool
    {
        $zero = 1 === preg_match('/^0(?:\.0+)?(?:px|em|rem|%)?$/', $value);
        foreach ($blocks as $block) {
            if (isset($block['margin'][$side])) continue;
            if ($zero && 'core/list-item' === $block['name']) continue;
            if ($zero && 'core/group' === $block['name'] && in_array(strtolower((string) ($block['attributes']->tagName ?? 'div')), self::MARGINLESS_GROUP_TAGS, true)) continue;
            return false;
        }
        return true;
    }

    /**
     * Whether the block-type style alone renders this block's side as its
     * inline value does.
     *
     * @param array<string,array<string,true>> $classRules
     * @param list<array{0:int,1:int}> $protected
     */
    private static function strippable(array $block, string $side, array $classRules, array $protected): bool
    {
        $attributes = $block['attributes'];
        if (!$attributes instanceof \stdClass || null === $block['tag'] || !$block['member'] || $block['parent_gap']) return false;
        if (BlockAttributes::encode($attributes) !== $block['json']) return false;
        if (isset($attributes->align) || isset($attributes->metadata->blocksEngineRuntimeAttributes)) return false;
        foreach ($protected as [$start, $end]) if ($block['offset'] >= $start && $block['offset'] < $end) return false;
        foreach (array_merge(self::classes($block['tag']['html']), $block['parent_classes']) as $class) {
            foreach (self::SIDE_PROPERTIES[$side] as $property) if (isset($classRules[$class][$property])) return false;
        }
        $declarations = CssRuleAnalyzer::declarationList(HtmlTagScanner::attribute($block['tag']['html'], 'style'));
        $expected = self::declaration($side, $block['margin'][$side]);
        $matching = array_filter($declarations, static fn(array $declaration): bool => in_array($declaration['name'], self::SIDE_PROPERTIES[$side], true));
        return array(array('name' => $expected[0], 'value' => $expected[1])) === array_values($matching);
    }

    /** @return array{0:string,1:string} The inline declaration core serializes for a margin side. */
    private static function declaration(string $side, string $value): array
    {
        $css = (string) preg_replace_callback('/var:preset\|([a-z0-9-]+)\|([a-z0-9-]+)/i', static fn(array $match): string => 'var(--wp--preset--' . $match[1] . '--' . $match[2] . ')', $value);
        return array('margin-' . $side, $css);
    }

    /**
     * Class => margin properties some delivered rule naming the class declares.
     *
     * @return array<string,array<string,true>>
     */
    private static function classRules(array $assets): array
    {
        $margins = array_fill_keys(array_merge(...array_values(self::SIDE_PROPERTIES)), true);
        $rules = array();
        $visitor = new CssStylesheetTransformer();
        foreach ($assets as $asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null) || !str_contains($asset['content'], 'margin')) continue;
            $visitor->visitStyleRules($asset['content'], static function (string $prelude, string $body) use (&$rules, $margins): void {
                $properties = array();
                foreach (CssRuleAnalyzer::declarationList($body) as $declaration) if (isset($margins[$declaration['name']])) $properties[$declaration['name']] = true;
                if (array() === $properties || !preg_match_all('/\.(-?[_a-zA-Z][\w-]*)/', $prelude, $classes)) return;
                foreach ($classes[1] as $class) $rules[$class] = ($rules[$class] ?? array()) + $properties;
            });
        }
        return $rules;
    }

    /**
     * Byte ranges of a document that runtime bindings anchor on by exact markup.
     *
     * @param list<string> $anchors
     * @return list<array{0:int,1:int}>
     */
    private static function protectedRanges(string $markup, array $anchors): array
    {
        $ranges = array();
        foreach ($anchors as $anchor) {
            if ('' === $anchor) continue;
            for ($offset = strpos($markup, $anchor); false !== $offset; $offset = strpos($markup, $anchor, $offset + 1)) $ranges[] = array($offset, $offset + strlen($anchor));
        }
        return $ranges;
    }

    /**
     * The document with the sides dropped from one block's attributes and
     * root tag's inline style.
     *
     * @param array<string,array{0:string,1:string}> $sides
     */
    private static function withoutSides(string $markup, array $block, array $sides): string
    {
        $attributes = $block['attributes'];
        $margin = $attributes->style->spacing->margin;
        foreach (array_keys($sides) as $side) unset($margin->{$side});
        if (array() === get_object_vars($margin)) unset($attributes->style->spacing->margin);
        if (array() === get_object_vars($attributes->style->spacing)) unset($attributes->style->spacing);
        if (array() === get_object_vars($attributes->style)) unset($attributes->style);
        $name = str_starts_with($block['name'], 'core/') ? substr($block['name'], 5) : $block['name'];
        $delimiter = '<!-- wp:' . $name . (array() === get_object_vars($attributes) ? '' : ' ' . BlockAttributes::encode($attributes)) . ' -->';

        $html = $block['tag']['html'];
        $dropped = array_map(static fn(array $declaration): string => $declaration[0], $sides);
        $kept = array_filter(CssRuleAnalyzer::declarationList(HtmlTagScanner::attribute($html, 'style')), static fn(array $declaration): bool => !in_array($declaration['name'], $dropped, true));
        $style = implode(';', array_map(static fn(array $declaration): string => $declaration['name'] . ':' . $declaration['value'], $kept));
        $tag = '' === $style
            ? (string) preg_replace('/\sstyle="[^"]*"/i', '', $html, 1)
            : (string) preg_replace_callback('/\sstyle="[^"]*"/i', static fn(): string => ' style="' . htmlspecialchars($style, ENT_QUOTES, 'UTF-8') . '"', $html, 1);

        $between = substr($markup, $block['offset'] + strlen($block['delimiter']), $block['tag']['offset'] - $block['offset'] - strlen($block['delimiter']));
        return substr($markup, 0, $block['offset']) . $delimiter . $between . $tag . substr($markup, $block['tag']['offset'] + strlen($html));
    }
}
