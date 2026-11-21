<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlTagScanner;

/**
 * Moves a block style value that repeats across the blocks of one type into
 * a theme.json block-type style (`styles.blocks.<block>.spacing.margin`) and
 * drops it from those blocks' inline style (#7120).
 *
 * A captured route keeps the vertical margins of its paragraphs, headings,
 * list items, images, details and groups even when zero, since the user agent
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
 *   matching delivered class rule sets a different margin, its parent has
 *   no nonzero block gap (core's per-container gap rule is printed after
 *   global styles), alignment does not float the box, no device runtime
 *   rewrites its style, and no runtime binding anchors on its markup.
 * - At least two blocks lose it: a value one block carries is not repeated.
 *
 * The same pass generalizes this to opt-in looks: identical non-margin style
 * sets on two or more paragraphs, details, groups, lists or list items become
 * `styles.blocks.<block>.variations.<slug>` and the blocks get
 * `is-style-<slug>`. Core prints those as
 * `:root :where(.wp-block-x.is-style-slug)`; a variation is used only when no
 * delivered rule competes for one of its properties. Headings stay inline
 * because heading element styles may otherwise outrank the variation.
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
        'core/details' => array('class' => 'wp-block-details'),
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
        // The unrestricted block-type margin is the all-members special case.
        // Resolve it first, retaining its exposure and layout guards, then group
        // the remaining exact style sets behind opt-in variation selectors.
        $result = self::hoistMargins($documents, $observed, $assets, $anchors);
        foreach ($result['markup'] as $key => $markup) $documents[$key]['markup'] = $markup;
        $variations = self::hoistVariations($documents, $assets, $anchors);
        return array('markup' => array_replace($result['markup'], $variations['markup']), 'blocks' => array_replace_recursive($result['blocks'], $variations['blocks']));
    }

    private static function hoistMargins(array $documents, array $observed, array $assets, array $anchors): array
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
                if (null === $value || !self::everyCarries($typed, $side, $value, $classRules)) continue;
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
     * Exact remaining style sets, never partial intersections. Only root style
     * supports with a proven inline serialization are eligible. Custom selectors
     * (notably image borders and heading/link element styles) are not assumed to
     * target the wrapper. Unknown properties leave the entire set inline.
     */
    private static function hoistVariations(array $documents, array $assets, array $anchors): array
    {
        $groups = array();
        $rules = self::classRules($assets, false);
        foreach ($documents as $key => $document) {
            if (!$document['strippable']) continue;
            $protected = self::protectedRanges($document['markup'], $anchors);
            foreach (self::blocks($document['markup']) as $block) {
                // Heading element styles can outrank a variation's 0-1-0;
                // do not remove their inline typography without that proof.
                if (!in_array($block['name'], array('core/paragraph', 'core/details', 'core/group', 'core/list', 'core/list-item'), true)) continue;
                $attrs = $block['attributes'];
                if (!$attrs instanceof \stdClass || null === $block['tag'] || BlockAttributes::encode($attrs) !== $block['json']) continue;
                if (isset($attrs->align) || isset($attrs->metadata->blocksEngineRuntimeAttributes) || str_contains((string) ($attrs->className ?? ''), 'is-style-')) continue;
                $blocked = false;
                foreach ($protected as [$start, $end]) if ($block['offset'] >= $start && $block['offset'] < $end) $blocked = true;
                if ($blocked) continue;
                $style = json_decode(json_encode($attrs->style ?? new \stdClass(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
                // Preset attributes stay on the block: they serialize as `has-*`
                // classes, not inline declarations a variation could replace.
                if (array() === $style) continue;
                // Margin-only sets retain the original site-wide exposure
                // proof, including raw and dynamic selector members.
                if (array('spacing') === array_keys($style) && array('margin') === array_keys($style['spacing'])) continue;
                $declarations = self::styleDeclarations($style);
                if (null === $declarations || array() === $declarations) continue;
                $inline = CssRuleAnalyzer::declarationList(HtmlTagScanner::attribute($block['tag']['html'], 'style'));
                foreach ($declarations as $property => $value) {
                    $matches = array_values(array_filter($inline, static fn(array $d): bool => $d['name'] === $property));
                    // Presets may serialize as a utility class, whose removal is
                    // deliberately not inferred here.
                    if (array(array('name' => $property, 'value' => $value)) !== $matches) $blocked = true;
                    if (str_starts_with($property, 'margin-') && !self::strippable($block, substr($property, 7), $rules, $protected)) $blocked = true;
                    foreach ($rules as $rule) {
                        if (!self::competes($property, $rule['property'])) continue;
                        if (self::sameValue($rule['value'], $value)) continue;
                        $shorthand = $rule['property'];
                        if (in_array($shorthand, array('border-top', 'border-right', 'border-bottom', 'border-left'), true)) {
                            $expanded = implode(' ', array_map(static fn(string $part): string => $declarations[$shorthand . '-' . $part] ?? '', array('width', 'style', 'color')));
                            if ($expanded === $rule['value']) continue;
                        }
                        if (self::ruleReaches($block, $rule)) $blocked = true;
                    }
                }
                if ($blocked) continue;
                self::sortStyle($style);
                $signature = $block['name'] . ':' . json_encode($style, JSON_THROW_ON_ERROR);
                $groups[$signature]['style'] = $style;
                $groups[$signature]['declarations'] = $declarations;
                $groups[$signature]['blocks'][] = $block + array('document' => $key);
            }
        }
        $theme = array();
        $edits = array();
        foreach ($groups as $signature => $group) {
            if (count($group['blocks']) < self::MIN_REPEATS) continue;
            $shared = null;
            foreach ($group['blocks'] as $block) {
                $classes = array_values(array_filter(self::classes($block['tag']['html']), static fn(string $class): bool => !str_starts_with($class, 'be-') && !str_starts_with($class, 'wp-') && !str_starts_with($class, 'has-') && !str_starts_with($class, 'blocks-engine-') && 1 === preg_match('/^[a-z][a-z0-9-]{0,39}$/', $class)));
                $shared = null === $shared ? $classes : array_values(array_intersect($shared, $classes));
            }
            sort($shared);
            $type = $group['blocks'][0]['name'];
            // Hash suffix also prevents two different looks sharing a source
            // class from acquiring the same variation name across routes.
            $slug = ($shared[0] ?? 'look') . '-' . substr(hash('sha256', $signature), 0, 8);
            $theme[$type]['variations'][$slug] = $group['style'];
            foreach ($group['blocks'] as $block) $edits[$block['document']][$block['offset']] = array($block, $slug, $group['declarations']);
        }
        $markup = array();
        foreach ($edits as $key => $changes) {
            krsort($changes);
            $text = $documents[$key]['markup'];
            foreach ($changes as [$block, $slug, $declarations]) $text = self::withVariation($text, $block, $slug, $declarations);
            $markup[$key] = $text;
        }
        return array('markup' => $markup, 'blocks' => $theme);
    }

    private static function sortStyle(array &$style): void
    {
        ksort($style);
        foreach ($style as &$value) if (is_array($value)) self::sortStyle($value);
    }

    /** @return array<string,string>|null */
    private static function styleDeclarations(array $style, string $prefix = ''): ?array
    {
        $map = array(
            'typography.fontWeight' => 'font-weight', 'typography.fontStyle' => 'font-style',
            'typography.fontSize' => 'font-size', 'typography.fontFamily' => 'font-family',
            'typography.lineHeight' => 'line-height', 'typography.letterSpacing' => 'letter-spacing',
            'typography.textTransform' => 'text-transform', 'typography.textDecoration' => 'text-decoration',
            'color.text' => 'color', 'color.background' => 'background-color',
            'border.color' => 'border-color', 'border.style' => 'border-style', 'border.width' => 'border-width', 'border.radius' => 'border-radius',
        );
        foreach (array('top', 'right', 'bottom', 'left') as $side) {
            foreach (array('margin', 'padding') as $box) $map['spacing.' . $box . '.' . $side] = $box . '-' . $side;
            foreach (array('color', 'style', 'width') as $part) $map['border.' . $side . '.' . $part] = 'border-' . $side . '-' . $part;
        }
        $result = array();
        foreach ($style as $key => $value) {
            $path = $prefix . $key;
            if (is_array($value)) {
                $nested = self::styleDeclarations($value, $path . '.');
                if (null === $nested) return null;
                $result += $nested;
            } else {
                if (!isset($map[$path]) || !is_string($value)) return null;
                $result[$map[$path]] = self::declaration('', $value)[1];
            }
        }
        return $result;
    }

    private static function competes(string $property, string $other): bool
    {
        if (in_array($other, array('all', 'font', 'background', 'border', 'margin', 'padding'), true)) return 'all' === $other || str_starts_with($property, $other) || ('font' === $other && in_array($property, array('line-height', 'letter-spacing'), true));
        if ($property === $other) return true;
        if (str_starts_with($property, 'border-') && str_starts_with($other, 'border-')) return true;
        if (str_starts_with($property, 'margin-') && str_starts_with($other, 'margin-')) return true;
        return str_starts_with($property, 'padding-') && str_starts_with($other, 'padding-');
    }

    private static function withVariation(string $markup, array $block, string $slug, array $declarations): string
    {
        $attrs = $block['attributes'];
        unset($attrs->style, $attrs->fontSize, $attrs->fontFamily, $attrs->textColor, $attrs->backgroundColor);
        $class = 'is-style-' . $slug;
        $attrs->className = trim(($attrs->className ?? '') . ' ' . $class);
        $html = $block['tag']['html'];
        $classes = trim(HtmlTagScanner::attribute($html, 'class') . ' ' . $class);
        $tag = str_contains($html, 'class="')
            ? (string) preg_replace_callback('/class="[^"]*"/', static fn(): string => 'class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '"', $html, 1)
            : substr_replace($html, ' class="' . $class . '"', strlen($block['tag']['name']) + 1, 0);
        $kept = array_filter(CssRuleAnalyzer::declarationList(HtmlTagScanner::attribute($html, 'style')), static fn(array $d): bool => !isset($declarations[$d['name']]));
        $style = implode(';', array_map(static fn(array $d): string => $d['name'] . ':' . $d['value'], $kept));
        $tag = (string) preg_replace_callback('/\sstyle="[^"]*"/i', static fn(): string => '' === $style ? '' : ' style="' . htmlspecialchars($style, ENT_QUOTES, 'UTF-8') . '"', $tag, 1);
        $delimiter = '<!-- wp:' . substr($block['name'], 5) . ' ' . BlockAttributes::encode($attrs) . ' -->';
        $between = substr($markup, $block['offset'] + strlen($block['delimiter']), $block['tag']['offset'] - $block['offset'] - strlen($block['delimiter']));
        return substr($markup, 0, $block['offset']) . $delimiter . $between . $tag . substr($markup, $block['tag']['offset'] + strlen($html));
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
                'parent_gap' => null === $parent ? null : ($parent['attributes']->style->spacing->blockGap ?? null),
            );
            if (!$selfClosing) $stack[] = count($blocks) - 1;
        }
        // Match delivered selectors against the actual tree, not against every
        // class mentioned anywhere in a selector (including :not() and ancestors).
        $html = $markup;
        foreach (array_reverse($blocks, true) as $index => $block) {
            if (null === $block['tag']) continue;
            $at = $block['tag']['offset'] + strlen($block['tag']['name']) + 1;
            $html = substr_replace($html, ' data-be-hoist="' . $index . '"', $at, 0);
        }
        $dom = new \DOMDocument();
        @$dom->loadHTML('<!doctype html><html><body>' . $html . '</body></html>');
        foreach ((new \DOMXPath($dom))->query('//*[@data-be-hoist]') as $element) {
            $blocks[(int) $element->getAttribute('data-be-hoist')]['element'] = $element;
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
    private static function everyCarries(array $blocks, string $side, string $value, array $rules): bool
    {
        $zero = 1 === preg_match('/^0(?:\.0+)?(?:px|em|rem|%)?$/', $value);
        foreach ($blocks as $block) {
            if (isset($block['margin'][$side])) continue;
            if (self::deliveredValue($block, $side, $value, $rules)) continue;
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
     * @param list<array<string,mixed>> $classRules
     * @param list<array{0:int,1:int}> $protected
     */
    private static function strippable(array $block, string $side, array $classRules, array $protected): bool
    {
        $attributes = $block['attributes'];
        if (!$attributes instanceof \stdClass || null === $block['tag'] || !$block['member']) return false;
        if (null !== $block['parent_gap'] && (!in_array($side, array('top', 'bottom'), true) || !is_string($block['parent_gap']) || !self::sameValue($block['parent_gap'], '0px') || !self::sameValue($block['margin'][$side], '0px'))) return false;
        if (BlockAttributes::encode($attributes) !== $block['json']) return false;
        if (isset($attributes->align) && (!in_array($side, array('top', 'bottom'), true) || !in_array($attributes->align, array('center', 'wide', 'full'), true))) return false;
        if (isset($attributes->metadata->blocksEngineRuntimeAttributes)) return false;
        foreach ($protected as [$start, $end]) if ($block['offset'] >= $start && $block['offset'] < $end) return false;
        foreach ($classRules as $rule) {
            if (!in_array($rule['property'], self::SIDE_PROPERTIES[$side], true)) continue;
            if (self::sameValue($rule['value'], $block['margin'][$side])) continue;
            if (self::ruleReaches($block, $rule)) return false;
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

    /** Delivered margin rules, retaining selectors and values for conservative matching. */
    private static function classRules(array $assets, bool $marginsOnly = true): array
    {
        $margins = array_fill_keys(array_merge(...array_values(self::SIDE_PROPERTIES)), true);
        $rules = array();
        $visitor = new CssStylesheetTransformer();
        foreach ($assets as $asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null) || ($marginsOnly && !str_contains($asset['content'], 'margin'))) continue;
            $visitor->visitStyleRules($asset['content'], static function (string $prelude, string $body, array $ancestors) use (&$rules, $margins, $marginsOnly): void {
                foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array($prelude) as $selector) {
                    // Type/universal resets were already resolved by the capture.
                    // Only class rules can compete with the block-type style.
                    if ($marginsOnly && !str_contains($selector, '.')) continue;
                    foreach (CssRuleAnalyzer::declarationList($body) as $declaration) {
                        if ($marginsOnly && !isset($margins[$declaration['name']])) continue;
                        $rules[] = array('selector' => $selector, 'parsed' => CssSelectorMatcher::parse($selector), 'property' => $declaration['name'], 'value' => $declaration['value'], 'unconditional' => array() === $ancestors);
                    }
                }
            });
        }
        return $rules;
    }

    /** Equivalent zero units are safe even for a shorthand with several sides. */
    private static function sameValue(string $css, string $value): bool
    {
        $css = trim((string) preg_replace('/\s*!important\s*$/i', '', $css));
        if ($css === $value) return true;
        return 1 === preg_match('/^0(?:\.0+)?(?:px|em|rem|%)?$/', $value)
            && 1 === preg_match('/^0(?:\.0+)?(?:px|em|rem|%)?(?:\s+0(?:\.0+)?(?:px|em|rem|%)?){0,3}$/', $css);
    }

    private static function ruleReaches(array $block, array $rule): bool
    {
        if (!isset($block['element'])) return true;
        $match = CssSelectorMatcher::matches($block['element'], $rule['parsed']);
        return !$match['supported'] || $match['matches'];
    }

    /**
     * Only an unconditional, direct engine reset proves a marginless block
     * carries zero. Conditional rules and ancestor-dependent selectors cannot
     * establish a value across viewport/state changes.
     */
    private static function deliveredValue(array $block, string $side, string $value, array $rules): bool
    {
        $found = false;
        foreach ($rules as $rule) {
            if (!in_array($rule['property'], self::SIDE_PROPERTIES[$side], true) || !self::ruleReaches($block, $rule)) continue;
            if (!self::sameValue($rule['value'], $value)) return false;
            if ($rule['unconditional'] && in_array($rule['selector'], array(':root :where(.blocks-engine-synthetic-paragraph)', '.blocks-engine-synthetic-image-figure'), true)) $found = true;
        }
        return $found;
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
