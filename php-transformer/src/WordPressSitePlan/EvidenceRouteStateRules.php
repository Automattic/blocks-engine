<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleTree;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;

/**
 * The part of a retired author sheet that box-tree carriers cannot replace.
 *
 * Carriers record only the resting pose. Rules that apply in a non-resting
 * state (an interaction pseudo-class, or a runtime state attribute the
 * capture replays) still override that pose the way they overrode the
 * resting author rule, and blocks keep their source classes, so those rules
 * are kept in their cascade position and conditional context. Resting rules
 * that bind an animation through a custom property a state rule defines are
 * kept down to their motion declarations. So are resting rules that bind
 * a scroll- or time-driven animation through a custom property whose value
 * names one of the sheets' `@keyframes` (Wix parallax: `--anm-0` carries the
 * keyframes, `--anm-tmln-0` the view timeline). The custom properties kept
 * rules and kept keyframes read are closed over: every rule that defines one
 * keeps that definition. Every `@keyframes` and `@property` the kept rules
 * reach is kept. Rules whose subject names an id no emitted element carries
 * are dropped. Everything else is dropped, except `@font-face` and `@layer`
 * order statements.
 *
 * Shared template parts carry no carriers: the capture observes a route,
 * not the part every route renders. Resting rules whose selectors can only
 * reach the parts' elements keep styling them, scoped to the parts so they
 * never restyle the route content its carriers own. Custom properties that
 * delivered CSS or emitted markup read (an engine rule's `var(--text-4xl)`,
 * a block's inline `var(--background)`) are closed over like those kept
 * rules read: their definitions are what the carriers' resting pose was
 * computed against.
 */
final class EvidenceRouteStateRules
{
    private const INTERACTION = '/:(?:hover|focus|focus-visible|focus-within|active|checked|target)(?![\w-])/i';
    private const ATTRIBUTE = '\[(?:[^\]"\']|"[^"]*"|\'[^\']*\')*\]';
    private const MOTION = '/^(?:animation|view-timeline|scroll-timeline|timeline-scope)(?:-|$)/i';
    /** Shared template parts as WordPress renders them, outside the route content they surround. */
    private const CHROME_SCOPE = '@scope (.wp-block-template-part) to (.wp-block-post-content)';

    /** @var array{classes: array<string, true>, ids: array<string, true>, tags: array<string, true>}|null */
    private ?array $chromeHooks = null;

    /** @var array<string, true> Custom properties a kept state rule defines. */
    private array $stateProperties = array();
    /** @var array<string, true> Identifiers kept rules (or carriers) bind as animation names. */
    private array $names = array();
    /** @var array<string, true> Custom properties kept rules reference or define. */
    private array $customProperties = array();
    /** @var array<string, true> Custom properties kept rules or keyframes read through var(). */
    private array $referenced = array();
    /** @var array<string, true> Keyframes names the sheets define. */
    private array $keyframes = array();
    /** @var array<string, true> Custom properties that carry an animation binding. */
    private array $motionProperties = array();

    /**
     * @param list<string> $stateAttributes Runtime state attributes the capture replays.
     * @param string $markup Emitted route markup; an ancestor compound of attributes it never carries is dropped.
     * @param string|null $reach Every markup the route renders (route and template parts); a rule
     *                           whose subject names an id it never carries matches nothing. Defaults to `$markup`.
     * @param string $chrome Shared template part markup every route renders around its content.
     */
    public function __construct(private readonly array $stateAttributes, private readonly string $markup = '', private readonly ?string $reach = null, string $chrome = '')
    {
        if ('' !== $chrome) $this->chromeHooks = self::chromeHooks($chrome);
    }

    /**
     * Classes, ids and element names the parts render. A dynamic block saves
     * only its comment, so its `className` and the elements core renders for
     * it count too.
     *
     * @return array{classes: array<string, true>, ids: array<string, true>, tags: array<string, true>}
     */
    private static function chromeHooks(string $markup): array
    {
        $hooks = array('classes' => array(), 'ids' => array(), 'tags' => array('div' => true));
        preg_match_all('/<([a-zA-Z][\w-]*)\b[^>]*>/', $markup, $elements, PREG_SET_ORDER);
        foreach ($elements as [$element, $tag]) {
            $hooks['tags'][strtolower($tag)] = true;
            if (preg_match('/\sclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $element, $class)) foreach (preg_split('/\s+/', html_entity_decode($class[1] . ($class[2] ?? ''), ENT_QUOTES | ENT_HTML5)) ?: array() as $name) if ('' !== $name) $hooks['classes'][$name] = true;
            if (preg_match('/\sid\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $element, $id)) $hooks['ids'][html_entity_decode($id[1] . ($id[2] ?? ''), ENT_QUOTES | ENT_HTML5)] = true;
        }
        preg_match_all('/<!--\s*wp:([a-z][a-z0-9_\/-]*)\s+(\{.*?\})\s*\/?-->/s', $markup, $blocks, PREG_SET_ORDER);
        foreach ($blocks as [, $name, $json]) {
            $attributes = json_decode($json, true);
            if (!is_array($attributes)) continue;
            foreach (preg_split('/\s+/', (string) ($attributes['className'] ?? '')) ?: array() as $class) if ('' !== $class) $hooks['classes'][$class] = true;
            if ('' !== (string) ($attributes['anchor'] ?? '')) $hooks['ids'][(string) $attributes['anchor']] = true;
            if ('navigation' === $name || 'core/navigation' === $name) foreach (array('nav', 'ul', 'li', 'a', 'span', 'button', 'svg') as $tag) $hooks['tags'][$tag] = true;
        }
        return $hooks;
    }

    /**
     * Runtime state attributes, from the capture's viewport entrance evidence.
     *
     * @return list<string>
     */
    public static function stateAttributes(string $markup): array
    {
        if (!str_contains($markup, CaptureEvidence::VIEWPORT_ENTRANCE)) return array();
        // Evidence arrives HTML-escaped, JSON-escaped, or both.
        for ($i = 0, $text = $markup; $i < 3; ++$i) $text = str_replace(array('\\u0022', '\\"', '&quot;', '&#34;', '&#x22;'), '"', html_entity_decode($text, ENT_QUOTES | ENT_HTML5));
        $names = array();
        $offset = 0;
        while (false !== ($at = strpos($text, CaptureEvidence::VIEWPORT_ENTRANCE, $offset))) {
            $offset = $at + strlen(CaptureEvidence::VIEWPORT_ENTRANCE);
            if (!preg_match('/\G[\s"]*[=:]\s*"?\s*\{.*?"attributes"\s*:\s*\{/s', $text, $match, 0, $offset)) continue;
            $start = $offset + strlen($match[0]);
            $depth = 1;
            for ($end = $start, $length = strlen($text); $end < $length && $depth > 0; ++$end) {
                if ('{' === $text[$end]) ++$depth;
                elseif ('}' === $text[$end]) --$depth;
            }
            preg_match_all('/"([A-Za-z_][\w.:-]*)"\s*:\s*\{/', substr($text, $start, $end - $start), $keys);
            foreach ($keys[1] as $key) if (!in_array(strtolower($key), array('before', 'after'), true)) $names[strtolower($key)] = true;
        }
        return array_keys($names);
    }

    /**
     * @param list<string> $sheets Retired author sheets, in cascade order.
     * @param string $context Delivered CSS (for example carriers) whose animation names stay bound.
     * @return list<string> Each sheet's retained bytes.
     */
    public function retain(array $sheets, string $context = ''): array
    {
        $trees = array_map(fn(string $css): array => $this->nodes($css), $sheets);
        foreach ($trees as $tree) $this->collectKeyframes($tree);
        foreach ($trees as $tree) $this->collectMotionProperties($tree);
        // State rules first: they define the custom properties resting
        // animation consumers read.
        foreach ($trees as &$tree) $this->markState($tree);
        unset($tree);
        $this->motionProperties += $this->stateProperties;
        foreach ($trees as &$tree) $this->markConsumers($tree);
        unset($tree);
        if (null !== $this->chromeHooks) {
            foreach ($trees as &$tree) $this->markChrome($tree);
            unset($tree);
        }
        if (preg_match_all('/(?<![\w-])animation(?:-name)?\s*:([^;{}]*)/i', $context, $bound)) foreach ($bound[1] as $value) $this->bindNames($value);
        // Delivered CSS and emitted markup still read the author's custom
        // properties; carriers were observed with them defined.
        if (preg_match_all('/var\(\s*(--[\w-]+)/', $context . ($this->reach ?? $this->markup), $reads)) foreach ($reads[1] as $var) $this->referenced[$var] = $this->customProperties[$var] = true;
        // Close over the custom properties kept rules and kept keyframes read.
        do {
            $before = count($this->referenced) + count($this->names);
            foreach ($trees as $tree) $this->referenceKeyframes($tree);
            foreach ($trees as &$tree) $this->markDefinitions($tree);
            unset($tree);
        } while (count($this->referenced) + count($this->names) !== $before);
        return array_map(fn(array $tree): string => $this->emit($tree), $trees);
    }

    /** @param list<array<string, mixed>> $nodes */
    private function collectKeyframes(array $nodes): void
    {
        foreach ($nodes as $node) {
            if ('group' === $node['type']) $this->collectKeyframes($node['children']);
            elseif ('keyframes' === $node['type']) $this->keyframes[$node['name']] = true;
        }
    }

    /** @param list<array<string, mixed>> $nodes */
    private function collectMotionProperties(array $nodes): void
    {
        if (array() === $this->keyframes) return;
        foreach ($nodes as $node) {
            if ('group' === $node['type']) $this->collectMotionProperties($node['children']);
            if ('style' !== $node['type']) continue;
            foreach ($this->declarations($node['body']) as [$property, $value]) {
                if (!str_starts_with($property, '--') || isset($this->motionProperties[$property])) continue;
                if (preg_match_all('/(?<![\w-])-?[A-Za-z_][\w-]*/', $value, $identifiers)) foreach ($identifiers[0] as $identifier) if (isset($this->keyframes[$identifier])) {
                    $this->motionProperties[$property] = true;
                    break;
                }
            }
        }
    }

    /** @param list<array<string, mixed>> $nodes */
    private function referenceKeyframes(array $nodes): void
    {
        foreach ($nodes as $node) {
            if ('group' === $node['type']) $this->referenceKeyframes($node['children']);
            elseif ('keyframes' === $node['type'] && isset($this->names[$node['name']]) && preg_match_all('/var\(\s*(--[\w-]+)/', $node['text'], $vars)) foreach ($vars[1] as $var) $this->referenced[$var] = $this->customProperties[$var] = true;
        }
    }

    /**
     * Resting definitions of a custom property a kept rule or keyframes reads.
     *
     * @param list<array<string, mixed>> $nodes
     */
    private function markDefinitions(array &$nodes): void
    {
        foreach ($nodes as &$node) {
            if ('group' === $node['type']) $this->markDefinitions($node['children']);
            if ('style' !== $node['type'] || isset($node['keep']['body'])) continue;
            $added = array();
            foreach ($this->declarations($node['body']) as [$property, $value]) {
                $declaration = $property . ':' . $value;
                if (!isset($this->referenced[$property]) || in_array($declaration, $node['keep']['declarations'] ?? array(), true)) continue;
                $added[] = $declaration;
            }
            if (array() === $added) continue;
            if (null === $node['keep']) {
                $selectors = $this->reachable(CssStylesheetTransformer::splitSelectorList($node['prelude']) ?? array($node['prelude']));
                if (array() === $selectors) continue;
                $node['keep'] = array('selectors' => $selectors, 'declarations' => array(), 'state' => false);
            }
            $node['keep']['declarations'] = array_merge($node['keep']['declarations'], $added);
            $this->bind(implode(';', $added));
        }
    }

    /** @return list<array<string, mixed>> */
    private function nodes(string $css, ?CssRuleTree $tree = null, int $scope = -1): array
    {
        $tree ??= new CssRuleTree($css);
        $nodes = array();
        foreach ($tree->rules($scope) as $rule) {
            $prelude = trim(substr($css, $rule['start'], $rule['boundary'] - $rule['start']));
            $prelude = trim((string) preg_replace('#/\*.*?\*/#s', '', $prelude));
            if ($rule['boundary'] === $rule['end']) {
                // Statement: only cascade layer order survives.
                if (preg_match('/^@layer\s+[^{]+$/i', $prelude)) $nodes[] = array('type' => 'statement', 'text' => $prelude . ';');
                continue;
            }
            $body = substr($css, $rule['boundary'] + 1, $rule['end'] - $rule['boundary'] - 1);
            if (preg_match('/^@font-face$/i', $prelude)) $nodes[] = array('type' => 'verbatim', 'text' => $prelude . '{' . $body . '}');
            elseif (preg_match('/^@(?:-[a-z]+-)?keyframes\s+(["\']?)([^\s"\']+)\1$/i', $prelude, $match)) $nodes[] = array('type' => 'keyframes', 'name' => $match[2], 'text' => $prelude . '{' . $body . '}');
            elseif (preg_match('/^@property\s+(--[\w-]+)$/i', $prelude, $match)) $nodes[] = array('type' => 'property', 'name' => $match[1], 'text' => $prelude . '{' . $body . '}');
            elseif (preg_match('/^@(?:media|supports|layer|container|scope|document|-moz-document)\b/i', $prelude)) $nodes[] = array('type' => 'group', 'prelude' => $prelude, 'children' => $this->nodes($css, $tree, $rule['boundary']));
            elseif ('' !== $prelude && '@' !== $prelude[0]) $nodes[] = array('type' => 'style', 'prelude' => $prelude, 'body' => $body, 'keep' => null);
        }
        return $nodes;
    }

    /** @param list<array<string, mixed>> $nodes */
    private function markState(array &$nodes): void
    {
        foreach ($nodes as &$node) {
            if ('group' === $node['type']) $this->markState($node['children']);
            if ('style' !== $node['type']) continue;
            $selectors = $this->reachable(array_values(array_filter(CssStylesheetTransformer::splitSelectorList($node['prelude']) ?? array(), fn(string $selector): bool => $this->isState($selector))));
            if (array() === $selectors) continue;
            $node['keep'] = array('selectors' => $selectors, 'body' => $node['body'], 'state' => true);
            foreach ($this->declarations($node['body']) as [$property]) if (str_starts_with($property, '--')) $this->stateProperties[$property] = true;
            $this->bind($node['body'], false);
        }
    }

    /** @param list<array<string, mixed>> $nodes */
    private function markConsumers(array &$nodes): void
    {
        if (array() === $this->motionProperties) return;
        foreach ($nodes as &$node) {
            if ('group' === $node['type']) $this->markConsumers($node['children']);
            if ('style' !== $node['type'] || null !== $node['keep']) continue;
            $motion = array();
            $consumes = false;
            foreach ($this->declarations($node['body']) as [$property, $value]) {
                if (!preg_match(self::MOTION, $property)) continue;
                $motion[] = $property . ':' . $value;
                if (preg_match_all('/var\(\s*(--[\w-]+)/', $value, $vars)) foreach ($vars[1] as $var) $consumes = $consumes || isset($this->motionProperties[$var]);
            }
            if (!$consumes) continue;
            $selectors = $this->reachable(CssStylesheetTransformer::splitSelectorList($node['prelude']) ?? array($node['prelude']));
            if (array() === $selectors) continue;
            $node['keep'] = array('selectors' => $selectors, 'declarations' => $motion, 'state' => false);
            $this->bind(implode(';', $motion));
        }
    }

    /**
     * Resting rules that can reach only what the shared parts render: every
     * compound names classes, ids and elements the parts carry.
     *
     * @param list<array<string, mixed>> $nodes
     */
    private function markChrome(array &$nodes): void
    {
        foreach ($nodes as &$node) {
            if ('group' === $node['type']) $this->markChrome($node['children']);
            if ('style' !== $node['type'] || null !== $node['keep']) continue;
            $selectors = array_values(array_filter(CssStylesheetTransformer::splitSelectorList($node['prelude']) ?? array(), fn(string $selector): bool => $this->reachesChrome(trim($selector))));
            if (array() === $selectors) continue;
            $node['keep'] = array('selectors' => $selectors, 'body' => $node['body'], 'state' => false, 'chrome' => true);
            $this->bind($node['body']);
        }
    }

    private function reachesChrome(string $selector): bool
    {
        $host = CssSelectorMatcher::pseudoElementHost($selector);
        $parsed = null !== $host ? $host['parsed'] : CssSelectorMatcher::parse($selector);
        if (!($parsed['supported'] ?? false) || null !== ($parsed['pseudo_state_suffix_span'] ?? null)) return false;
        foreach ($parsed['compounds'] as $compound) {
            if ($compound['root'] || (null !== $compound['type'] && !isset($this->chromeHooks['tags'][strtolower((string) $compound['type'])]))) return false;
            foreach ($compound['classes'] as $class) if (!isset($this->chromeHooks['classes'][$class])) return false;
            foreach ($compound['ids'] as $id) if (!isset($this->chromeHooks['ids'][$id])) return false;
        }
        return true;
    }

    private function isState(string $selector): bool
    {
        // Attribute values and strings never name a state.
        $bare = (string) preg_replace('/"[^"]*"|\'[^\']*\'/', '""', $selector);
        if (preg_match(self::INTERACTION, $bare)) return true;
        foreach ($this->stateAttributes as $attribute) {
            if (preg_match('/\[\s*' . preg_quote($attribute, '/') . '\s*(?:[~|^$*]?=|\])/i', $bare)) return true;
        }
        return false;
    }

    /** @return list<array{0: string, 1: string}> */
    private function declarations(string $body): array
    {
        if (str_contains($body, '{')) return array();
        $declarations = array();
        foreach (CssValueSplitter::splitTopLevel($body, array(';')) as $declaration) {
            $colon = strpos($declaration, ':');
            if (false === $colon) continue;
            $declarations[] = array(trim(substr($declaration, 0, $colon)), trim(substr($declaration, $colon + 1)));
        }
        return $declarations;
    }

    /**
     * @param bool $follow Close over every custom property the body reads. A
     *                     state rule's body is delivered whole: only its motion
     *                     reads are followed, the rest resolve as they did.
     */
    private function bind(string $body, bool $follow = true): void
    {
        foreach ($this->declarations($body) as [$property, $value]) {
            $lower = strtolower($property);
            if (str_starts_with($property, '--')) $this->customProperties[$property] = true;
            $motion = $follow || preg_match(self::MOTION, $property) || isset($this->motionProperties[$property]);
            if (preg_match_all('/var\(\s*(--[\w-]+)/', $value, $vars)) foreach ($vars[1] as $var) {
                $this->customProperties[$var] = true;
                if ($motion) $this->referenced[$var] = true;
            }
            // Custom properties carry animation shorthands to the rule that
            // applies them, so their identifiers may name keyframes too.
            if ('animation' === $lower || 'animation-name' === $lower || str_starts_with($property, '--')) $this->bindNames($value);
        }
    }

    private function bindNames(string $value): void
    {
        $value = (string) preg_replace('/var\(\s*--[\w-]+/', '', $value);
        if (preg_match_all('/(?<![\w-])-?[A-Za-z_][\w-]*/', $value, $identifiers)) foreach ($identifiers[0] as $identifier) $this->names[$identifier] = true;
        if (preg_match_all('/"([^"]+)"|\'([^\']+)\'/', $value, $strings)) foreach (array_filter(array_merge($strings[1], $strings[2])) as $string) $this->names[$string] = true;
    }

    /** @param list<array<string, mixed>> $nodes */
    private function emit(array $nodes): string
    {
        $css = '';
        // Adjacent part-scoped rules share one scope block.
        $chrome = '';
        foreach ($nodes as $node) {
            if ('style' === $node['type'] && true === ($node['keep']['chrome'] ?? false)) {
                if ('' !== ($body = $this->keptBody($node))) $chrome .= implode(',', array_map(fn(string $selector): string => $this->matchable(trim($selector)), $node['keep']['selectors'])) . '{' . $body . '}';
                continue;
            }
            if ('' !== $chrome) $css .= self::CHROME_SCOPE . '{' . $chrome . '}';
            $chrome = '';
            $css .= match ($node['type']) {
                'verbatim', 'statement' => $node['text'],
                'keyframes' => isset($this->names[$node['name']]) ? $node['text'] : '',
                'property' => isset($this->customProperties[$node['name']]) ? $node['text'] : '',
                'group' => '' !== ($inner = $this->emit($node['children'])) && '' !== trim((string) preg_replace('/@layer\s[^{};]+;/i', '', $inner)) ? $node['prelude'] . '{' . $inner . '}' : '',
                'style' => '' !== ($body = $this->keptBody($node)) ? implode(',', array_map(fn(string $selector): string => $this->matchable(trim($selector)), $node['keep']['selectors'])) . '{' . $body . '}' : '',
                default => '',
            };
        }
        return '' !== $chrome ? $css . self::CHROME_SCOPE . '{' . $chrome . '}' : $css;
    }

    /** @param array<string, mixed> $node */
    private function keptBody(array $node): string
    {
        if (null === $node['keep']) return '';
        if (isset($node['keep']['body'])) {
            // A whole state body, without its source indentation.
            $declarations = $this->declarations((string) $node['keep']['body']);
            if (array() === $declarations || str_contains((string) $node['keep']['body'], '/*')) return trim((string) $node['keep']['body']);
            return implode(';', array_map(static fn(array $declaration): string => $declaration[0] . ':' . (str_contains($declaration[1], '"') || str_contains($declaration[1], "'") ? $declaration[1] : (string) preg_replace('/\s+/', ' ', $declaration[1])), $declarations));
        }
        // Kept declarations, in their source order.
        $kept = array();
        foreach ($this->declarations($node['body']) as [$property, $value]) if (in_array($property . ':' . $value, $node['keep']['declarations'], true)) $kept[] = $property . ':' . $value;
        return implode(';', $kept);
    }

    /**
     * Selector list members whose subject can match an emitted element. Ids
     * are route identities: a subject that requires an id the route never
     * renders matches nothing there. Classes may be added at runtime, so only
     * ids are tested.
     *
     * @param list<string> $selectors
     * @return list<string>
     */
    private function reachable(array $selectors): array
    {
        $reach = $this->reach ?? $this->markup;
        if ('' === $reach) return $selectors;
        return array_values(array_filter($selectors, fn(string $selector): bool => $this->subjectReachable($selector, $reach)));
    }

    private function subjectReachable(string $selector, string $reach): bool
    {
        // The subject is the last compound outside parentheses and brackets.
        $depth = 0;
        $start = 0;
        $length = strlen($selector);
        for ($i = 0; $i < $length; ++$i) {
            $char = $selector[$i];
            if ('\\' === $char) { ++$i; continue; }
            if ('"' === $char || "'" === $char) { $end = strpos($selector, $char, $i + 1); $i = false === $end ? $length : $end; continue; }
            if ('(' === $char || '[' === $char) ++$depth;
            elseif (')' === $char || ']' === $char) --$depth;
            elseif (0 === $depth && (ctype_space($char) || '>' === $char || '+' === $char || '~' === $char)) $start = $i + 1;
        }
        $compound = trim(substr($selector, $start));
        if ('' === $compound) return true;
        // Walk the compound: top-level ids must be rendered; an :is()/:where()
        // matches when any member can.
        for ($i = 0, $length = strlen($compound); $i < $length; ++$i) {
            $char = $compound[$i];
            if ('#' === $char && preg_match('/\G#(-?[A-Za-z_][\w-]*)(?![\w\\\\-])/', $compound, $id, 0, $i)) {
                if (!str_contains($reach, $id[1])) return false;
                $i += strlen($id[0]) - 1;
                continue;
            }
            if ('[' === $char || '(' === $char) {
                $close = $this->closing($compound, $i);
                $inner = substr($compound, $i + 1, $close - $i - 1);
                if ('[' === $char && preg_match('/^\s*id\s*[\^]?=\s*(["\']?)([A-Za-z_][\w-]*)\1\s*$/', $inner, $attribute) && !str_contains($reach, $attribute[2])) return false;
                if ('(' === $char && preg_match('/:(?:is|where|matches|-webkit-any)$/i', substr($compound, 0, $i))) {
                    $members = CssStylesheetTransformer::splitSelectorList($inner);
                    if (null !== $members && array() !== $members) {
                        $any = false;
                        foreach ($members as $member) $any = $any || $this->subjectReachable(trim($member), $reach);
                        if (!$any) return false;
                    }
                }
                $i = $close;
            }
        }
        return true;
    }

    private function closing(string $text, int $open): int
    {
        $depth = 0;
        for ($i = $open, $length = strlen($text); $i < $length; ++$i) {
            $char = $text[$i];
            if ('\\' === $char) { ++$i; continue; }
            if ('"' === $char || "'" === $char) { $end = strpos($text, $char, $i + 1); $i = false === $end ? $length : $end; continue; }
            if ('(' === $char || '[' === $char) ++$depth;
            elseif (')' === $char || ']' === $char) { --$depth; if (0 === $depth) return $i; }
        }
        return $length;
    }

    /**
     * A leading ancestor compound made only of attributes the emitted markup
     * never carries names a source wrapper conversion removed (for example
     * Wix's `<interact-element data-interact-key>`). Dropping it lets the rule
     * reach the emitted block, which still carries the subject's identity.
     */
    private function matchable(string $selector): string
    {
        if ('' === $this->markup) return $selector;
        while (preg_match('/^((?:' . self::ATTRIBUTE . ')+)(\s*>\s*|\s+)(?=\S)/', $selector, $match)) {
            preg_match_all('/\[\s*([\w-]+)/', $match[1], $attributes);
            foreach ($attributes[1] as $attribute) if (in_array(strtolower($attribute), $this->stateAttributes, true) || str_contains($this->markup, $attribute)) return $selector;
            $selector = substr($selector, strlen($match[0]));
        }
        return $selector;
    }
}
