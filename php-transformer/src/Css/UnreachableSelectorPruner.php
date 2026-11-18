<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

use Automattic\BlocksEngine\PhpTransformer\WordPress\SourceClassIdentity;

/**
 * Drops stylesheet selector arms that require a class, id or custom attribute
 * the site never carries.
 *
 * A theme stylesheet shared by many sites styles layouts, widgets and states
 * this site never renders. Projection then multiplies each such arm into
 * padded, editor and native-block alternatives. An arm that requires (outside
 * any functional pseudo-class) a class or id named by no source document,
 * script or other non-stylesheet file matched nothing on the source site, so
 * it is dropped with all its alternatives. Positive :is(), :where() and
 * :has() lists must have at least one reachable alternative too.
 *
 * Tokens the converter (including route-aligned classes) or WordPress mint
 * are not source evidence and never
 * decide an arm: `wp-`, `is-`, `has-`, editor and engine names. On the
 * source side of a site whose documents are not WordPress output, `is-` and
 * `has-` names are the source framework's own state vocabulary (`is-invalid`,
 * `has-validation`): WordPress mints its states only on the blocks the
 * import emits, which the projection and WordPress's own styles cover, so
 * they need source evidence like any other class. Neither do
 * state tokens that route-state projection strips from the served item.
 * A string ending in `-` or `_` keeps every token it may build live. An arm with an escaped or non-ASCII identifier is
 * kept.
 */
final class UnreachableSelectorPruner
{
    private const MINTED = '/^(?:wp-|is-|has-|blocks-engine-|be-|route-class-|editor-|block-editor-|components-)/';
    /** Names WordPress mints as element state, which its own runtime scripts may add to WordPress output. */
    private const WORDPRESS_STATE = '/^(?:is|has)-/';
    /** A token only WordPress output spells: block classes, the site root or core asset paths. */
    private const WORDPRESS_OUTPUT = '/(?<![\w-])wp-(?:block-|site-blocks\b|content\/|includes\/)/';

    /** Whether `is-` and `has-` names may be WordPress state the evidence cannot show. */
    private bool $wordPressStates = true;

    /** @var array<string, true> */
    private array $live = array();
    /** @var array<string, true> Tokens a complete document spells only as tag or attribute names. */
    private array $markupNames = array();
    /** @var list<string> */
    private array $livePrefixes = array();
    private string $evidenceText;
    /** @var array<string, bool> */
    private array $typeNamed = array();
    /** @var array<string, bool> */
    private array $evidenceContains = array();
    private ?UnusedCustomPropertyPruner $propertyPruner = null;

    /**
     * @param list<string> $texts Every non-stylesheet file of the site, including its metadata.
     * @param bool $pruneTypes Whether evidence still describes source (not converted) element types.
     * @param list<string>|null $stylesheets Whole-site author styles, including inline sheets; null disables property pruning.
     */
    public function __construct(array $texts, private bool $pruneTypes = false, ?array $stylesheets = null)
    {
        $this->evidenceText = implode("\n", $texts);
        // Source documents that are not WordPress output never receive
        // WordPress's runtime state classes before conversion.
        if ($pruneTypes && 1 === preg_match('/<html\b/i', $this->evidenceText)) $this->wordPressStates = 1 === preg_match(self::WORDPRESS_OUTPUT, $this->evidenceText);
        $prefixes = $markup = array();
        foreach ($texts as $text) {
            // Set keys in place: a property union (`+=`) copies the whole set per text.
            $named = self::withoutMarkupNames($text);
            if (preg_match_all('/[\w-]+/', $named, $matches)) foreach ($matches[0] as $token) $this->live[$token] = true;
            if ($named !== $text && preg_match_all('/[\w-]+/', $text, $matches)) foreach ($matches[0] as $token) $markup[$token] = true;
            if (preg_match_all('/[A-Za-z][\w-]*[-_](?=["\'`$+])/', $text, $matches)) $prefixes += array_fill_keys($matches[0], true);
        }
        $this->livePrefixes = array_keys($prefixes);
        // Markup names still evidence attribute subjects, never classes or ids.
        foreach ($markup as $token => $_) {
            if (isset($this->live[$token])) continue;
            $this->live[$token] = $this->markupNames[$token] = true;
        }
        // A property read only by arms that match nothing on the site is
        // read by no element: framework utilities the site never uses would
        // otherwise keep every reset token (and its registration) alive.
        // Readers are judged with the most conservative arm pruning (IDs kept).
        if (null !== $stylesheets) $this->propertyPruner = new UnusedCustomPropertyPruner(array_map(fn (string $css): string => $this->withoutUnreachableArms($css, true), $stylesheets), $texts);
    }

    /**
     * A complete document's class and id evidence without its markup syntax.
     * Tag and attribute names (`<h2`, `placeholder=`) name an element type or
     * attribute, never a class or id: counting them kept framework class
     * aliases such as `.h2` or `.placeholder` alive on every site using the
     * element. Attribute values, text, comments, and script and style bodies
     * stay whole, so a name a script, handler or value spells stays live.
     * Type and attribute reachability still read the whole evidence.
     */
    private static function withoutMarkupNames(string $text): string
    {
        if (1 !== preg_match('/<html\b/i', $text)) return $text;
        $masked = preg_replace_callback(
            '/<!--.*?-->|<(script|style|template|textarea|title|xmp|noscript)\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>.*?<\/\1\s*>|<\/?[A-Za-z][^\s\/>]*+((?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+)>/is',
            static function (array $match): string {
                if (!isset($match[2])) return $match[0];
                preg_match_all('/=\s*+("[^"]*+"|\'[^\']*+\'|[^\s"\'>]++)/', $match[2], $values);
                return ' ' . implode(' ', $values[1]) . ' ';
            },
            $text
        );
        return is_string($masked) ? $masked : $text;
    }

    /** @var array<string, string> Pruned texts shared by analysis and projection across site pages. */
    private array $prunedStylesheets = array();

    /** @param bool $preserveIds Keep arms whose only absent tokens are IDs. */
    public function prune(string $css, bool $preserveIds = false): string
    {
        $key = (int) $preserveIds . ':' . hash('sha256', $css);
        if (isset($this->prunedStylesheets[$key])) return $this->prunedStylesheets[$key];
        // Reset inventories are recognizable before projection expands their
        // universal subjects into native-block and richtext alternatives.
        $css = $this->propertyPruner?->prune($css) ?? $css;
        return $this->prunedStylesheets[$key] = $this->withoutUnreachableArms($css, $preserveIds);
    }

    private function withoutUnreachableArms(string $css, bool $preserveIds): string
    {
        $pruned = (new CssStylesheetTransformer())->transformStyleRules($css, function (string $prelude, string $body) use ($preserveIds): string {
            $arms = CssStylesheetTransformer::splitSelectorList($prelude);
            if (null === $arms) return $prelude . '{' . $body . '}';
            // Matcher support is not selector validity: generated content and
            // browser state pseudos can still have provably absent subjects.
            // Malformed attribute syntax, however, makes a whole list uncertain.
            // Comments are not selector syntax: a bracket in a leading
            // annotation (RTLCSS `stringMap: [ ... ]`) is no attribute.
            foreach ($arms as $arm) {
                $arm = CssSyntaxScanner::withoutComments($arm);
                for ($i = 0, $length = strlen($arm); $i < $length; ++$i) {
                    if ('\\' === $arm[$i]) { ++$i; continue; }
                    if ('[' !== $arm[$i]) continue;
                    $close = CssSyntaxScanner::matchingClose($arm, $i);
                    if (null === $close || !CssSelectorMatcher::parse('*' . substr($arm, $i, $close - $i + 1))['supported']) return $prelude . '{' . $body . '}';
                    $i = $close;
                }
            }
            $kept = array_values(array_filter($arms, fn (string $arm): bool => $this->reachable($arm, $preserveIds)));
            if (count($kept) === count($arms)) return $prelude . '{' . $body . '}';
            // Comments ahead of the rule (licenses, source maps) stay in place.
            $comments = preg_match('#^(?:\s*/\*.*?\*/)+\s*#s', $prelude, $leading) ? $leading[0] : '';
            if (array() === $kept) return $comments;
            if ('' !== $comments && !str_starts_with($kept[0], $comments)) $kept[0] = $comments . ltrim($kept[0]);
            return implode(',', $kept) . '{' . $body . '}';
        });
        if ($pruned === $css) return $css;
        // Grouping rules whose every rule was dropped go with them.
        do {
            $pruned = (string) preg_replace('/@(?:-webkit-)?(?:media|supports|container|layer|document)\b[^{};]*\{\s*\}/i', '', $pruned, -1, $count);
        } while (0 < $count);
        return $pruned;
    }

    public function reachable(string $selector, bool $preserveIds = false): bool
    {
        $selector = (string) preg_replace('#/\*.*?\*/#s', ' ', $selector);
        if (1 === preg_match('/[^\x20-\x7E\t\n\r]/', $selector)) return true;
        // Escaped punctuation belongs to the identifier, not selector syntax.
        // Mask it before walking brackets and functional pseudo-classes so
        // utility names such as md:grid and w-[50%] can be checked too.
        $selector = (string) preg_replace_callback('/\\\\(?:[0-9a-fA-F]{1,6}\s?|[^\r\n\f])/u', static function (array $match): string {
            $escape = substr($match[0], 1);
            $value = preg_match('/^[0-9a-fA-F]/', $escape) ? mb_chr(hexdec(trim($escape)), 'UTF-8') : $escape;
            return 'BEESC' . bin2hex($value) . 'X';
        }, $selector);
        if (str_contains($selector, '\\')) return true;
        foreach (self::requiredTokens($selector, $preserveIds) as $token) {
            if (!$this->sourceNamed($token, true)) return false;
        }
        // Only the source-side pass opts into type pruning. After conversion,
        // native block wrappers may legitimately introduce new element types.
        // Fragments do not prove absence outside their supplied subtree.
        if ($this->pruneTypes && !str_contains($selector, 'BEESC') && preg_match('/<html\b/i', $this->evidenceText)) {
            $host = CssSelectorMatcher::pseudoElementHost($selector);
            $parsed = CssSelectorMatcher::parse($host['selector'] ?? $selector);
            if ($parsed['supported']) {
                foreach ($parsed['compounds'] as $compound) {
                    $type = $compound['type'];
                    // Substring evidence deliberately retains script-created
                    // tags and metadata references, not just literal HTML tags.
                    if (null !== $type && !$this->typeNamed($type)) return false;
                }
            }
        }
        // Positive selector-list pseudos require at least one reachable arm.
        // Compaction wraps otherwise required classes in :is(); ignoring that
        // wrapper kept entire families of absent framework components alive.
        // Never infer a requirement from :not() or an attribute value.
        for ($i = 0, $length = strlen($selector); $i < $length; ++$i) {
            if ('[' === $selector[$i]) {
                $close = CssSyntaxScanner::matchingClose($selector, $i);
                if (null === $close) return true;
                // Attribute subjects are requirements too. Only source-side
                // complete documents can prove absence: conversion introduces
                // attributes of its own, and fragments omit surrounding chrome.
                // Custom data/ARIA attributes have no implicit browser state;
                // runtime and metadata mentions (including constructed prefixes)
                // conservatively keep them live. Never inspect :not() arguments.
                if ($this->pruneTypes && preg_match('/<html\b/i', $this->evidenceText)) {
                    $attribute = CssSelectorMatcher::parse('*' . substr($selector, $i, $close - $i + 1));
                    foreach ($attribute['compounds'][0]['attributes'] ?? array() as $requirement) {
                        $name = strtolower($requirement['name']);
                        if (!preg_match('/^(?:data|aria)-/', $name) || $this->sourceNamed($name)) continue;
                        // DOMStringMap spells data-long-name as longName;
                        // reflected ARIA properties spell aria-hidden as ariaHidden.
                        $data = str_starts_with($name, 'data-');
                        $key = preg_replace_callback('/-([a-z])/', static fn(array $match): string => strtoupper($match[1]), $data ? substr($name, 5) : $name);
                        if ($this->evidenceContains((string) $key) || ($data && $this->evidenceContains('dataset['))) continue;
                        return false;
                    }
                }
                $i = $close;
            } elseif ('(' === $selector[$i]) {
                $close = CssSyntaxScanner::matchingClose($selector, $i);
                if (null === $close) return true;
                if (preg_match('/:(?:is|where|has)$/i', substr($selector, 0, $i))) {
                    $arms = CssStylesheetTransformer::splitSelectorList(substr($selector, $i + 1, $close - $i - 1));
                    if (null !== $arms && array() !== $arms && !array_filter($arms, fn (string $arm): bool => $this->reachable($arm, $preserveIds))) return false;
                }
                $i = $close;
            }
        }
        return true;
    }

    /**
     * Whether any evidence names an element type as a whole word: a tag
     * (`<pre`), a script string (`createElement("pre")`) or a metadata value.
     * A type inside a longer identifier (`pre` in `preview` or `pre-wrap`,
     * `sub` in `subheading`) names no element.
     */
    private function typeNamed(string $type): bool
    {
        return $this->typeNamed[$type = strtolower($type)] ??= 1 === preg_match('/(?<![\w-])' . preg_quote($type, '/') . '(?![\w-])/i', $this->evidenceText);
    }

    /**
     * Whether any evidence contains a substring. Memoized: evidence can be
     * many megabytes and the same keys recur across thousands of arms.
     */
    private function evidenceContains(string $needle): bool
    {
        return $this->evidenceContains[$needle] ??= str_contains($this->evidenceText, $needle);
    }

    /** @param bool $classOrId Whether the token must name a class or id rather than an attribute. */
    private function sourceNamed(string $token, bool $classOrId = false): bool
    {
        if (str_contains($token, 'BEESC')) {
            $decoded = (string) preg_replace_callback('/BEESC([0-9a-f]+)X/', static fn (array $match): string => (string) hex2bin($match[1]), $token);
            // Substring evidence is deliberately conservative: scripts may
            // construct these names without literal class attributes.
            if ($this->evidenceContains($decoded)) return true;
            // Escaped utility identities are complete class names, not state
            // markers. A word inside an arbitrary variant (aria-selected,
            // for example) does not make that absent utility reachable.
            if (!preg_match('/^[\w-]+$/', $decoded)) {
                foreach ($this->livePrefixes as $prefix) {
                    if (str_starts_with($decoded, $prefix)) return true;
                }
                return false;
            }
            $token = $decoded;
        }
        if ((isset($this->live[$token]) && !($classOrId && isset($this->markupNames[$token]))) || (1 === preg_match(self::MINTED, $token) && ($this->wordPressStates || 1 !== preg_match(self::WORDPRESS_STATE, $token))) || 1 === preg_match('/^' . SourceClassIdentity::REVEAL_STATE . '$/i', $token)
            || SourceClassIdentity::containsCurrentStateToken($token)) return true;
        foreach ($this->livePrefixes as $prefix) {
            if (str_starts_with($token, $prefix)) return true;
        }
        return false;
    }

    /**
     * Class and id names every element the selector matches must carry:
     * those outside functional pseudo-class arguments, attribute selectors
     * and strings.
     *
     * @return list<string>
     */
    private static function requiredTokens(string $selector, bool $preserveIds): array
    {
        $outside = '';
        $depth = 0;
        $quote = null;
        $length = strlen($selector);
        for ($i = 0; $i < $length; ++$i) {
            $char = $selector[$i];
            if (null !== $quote) {
                if ($char === $quote) $quote = null;
                continue;
            }
            if ('"' === $char || "'" === $char) $quote = $char;
            elseif ('(' === $char || '[' === $char) ++$depth;
            elseif (')' === $char || ']' === $char) $depth = max(0, $depth - 1);
            elseif (0 === $depth) {
                $outside .= $char;
                continue;
            }
            $outside .= ' ';
        }
        preg_match_all($preserveIds ? '/\.([\w-]+)/' : '/[.#]([\w-]+)/', $outside, $matches);
        return $matches[1];
    }
}
