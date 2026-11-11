<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorCompoundInspector;
use Automattic\BlocksEngine\PhpTransformer\Css\ClassAttributeSelectorProjection;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatchCache;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;
use Automattic\BlocksEngine\PhpTransformer\WordPress\SourceClassIdentity;
use DOMElement;

/** Per-transform author stylesheet inputs, source indexes, and selector state. */
final class AuthorStyleAnalysis
{
    private readonly string $combinedCss;
    private readonly DOMElement $sourceBody;
    /** @var list<DOMElement> */
    private array $sourceElements = array();
    /** @var array<string, list<DOMElement>> */
    private array $sourceElementsByTag = array();
    /** @var array<string, list<DOMElement>> */
    private array $sourceElementsById = array();
    /** @var array<string, list<DOMElement>> */
    private array $sourceElementsByClass = array();
    /** @var array<string, list<DOMElement>> */
    private array $sourceElementsByAttribute = array();
    /** @var array<string, true> */
    private array $sourceTags = array();
    /** @var array<string, true> */
    private array $sourceIds = array();
    /** @var array<string, true> */
    private array $sourceClasses = array();
    /** @var array<string, true> Source `class` attribute values. */
    private array $sourceClassAttributes = array();
    private ?ClassAttributeSelectorProjection $classAttributeSelectors = null;
    /** @var array<string, list<DOMElement>> */
    private array $selectorMatches = array();
    private ?CssSelectorMatchCache $selectorMatchCache;
    /** @var list<array<string, mixed>> */
    private array $styleRules = array();
    /** @var array<string, mixed>|null */
    private ?array $styleRuleCandidateIndex = null;
    /** @var array<string, true>|null */
    private ?array $captureOnlySelectors = null;
    private readonly string $markerSeed;
    private int $markerCounter = 0;
    /** @var array{string, string} */
    private readonly array $markerCollisionTexts;
    /** @var array<string, true>|null */
    private ?array $markerCollisionIndex = null;
    /** @var list<array{path: string, source_path: string, content: string, source_hash: string, media: string}> */
    private readonly array $stylesheetAssets;
    private readonly string $specificityShim;
    private readonly string $classSpecificityShim;
    private readonly string $idSpecificityShim;
    /** @var array<string, string> */
    private array $sourceClassMarkers = array();
    private readonly string $authorCssIdentity;
    private ?\DOMXPath $sourceXPath = null;
    /** @var array{classes: array<string, true>, ids: array<string, true>, predicates: list<array{attribute: string, operator: string, value: string, insensitive: bool}>, raw: bool}|null */
    private ?array $markerContextPolicy = null;
    /** @var \SplObjectStorage<DOMElement, string>|null */
    private ?\SplObjectStorage $contextHashes = null;
    /** @var \SplObjectStorage<DOMElement, true>|null */
    private ?\SplObjectStorage $positionalParents = null;
    /** @var \SplObjectStorage<DOMElement, string>|null */
    private ?\SplObjectStorage $subtreeHashes = null;
    /** @var \SplObjectStorage<DOMElement, string>|null */
    private ?\SplObjectStorage $selectorSubtreeHashes = null;

    /** @param list<array{path: string, source_path: string, content: string, source_hash: string, media: string, cascade_content?: string}> $stylesheetAssets */
    public function __construct(string $html, string $combinedCss, array $stylesheetAssets, DOMElement $sourceBody)
    {
        $this->combinedCss = $combinedCss;
        $this->stylesheetAssets = $stylesheetAssets;
        $this->sourceBody = $sourceBody;
        $this->selectorMatchCache = new CssSelectorMatchCache();
        // Ignore generated-looking markers while hashing each input separately,
        // preserving deterministic identities without duplicating large CSS.
        $markerPattern = '/(?:blocks-engine-(?:source-[a-z][a-z0-9-]*|control|table|media-text-image)-[a-f0-9]+-\d+|' . EngineMarker::specificityPatternBody() . ')/';
        $normalizedHtml = preg_match($markerPattern, $html) ? (preg_replace($markerPattern, '', $html) ?? '') : $html;
        $normalizedCss = preg_match($markerPattern, $combinedCss) ? (preg_replace($markerPattern, '', $combinedCss) ?? '') : $combinedCss;
        $seed = hash_init('sha256');
        hash_update($seed, $normalizedHtml);
        hash_update($seed, "\0");
        hash_update($seed, $normalizedCss);
        $this->markerSeed = substr(hash_final($seed), 0, 12);
        $this->authorCssIdentity = hash('sha256', $normalizedCss);
        $this->markerCollisionTexts = array($html, $combinedCss);
        // Shims only appear inside :not() and are never written onto an element,
        // so they need a name no element uses, not a page-scoped one. A
        // page-seeded name made every page's projection of a shared stylesheet
        // differ in every shimmed rule. The three counter slots they used stay
        // reserved so every other generated marker keeps its established name.
        $this->markerCounter = 3;
        $this->specificityShim = self::allocateSiteMarker('specificity', array($html, $combinedCss));
        $this->classSpecificityShim = self::allocateSiteMarker('specificity-class', array($html, $combinedCss));
        $this->idSpecificityShim = self::allocateSiteMarker('specificity-id', array($html, $combinedCss));

        for ( $ancestor = $sourceBody; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode ) {
            $this->recordSelectorSignals($ancestor);
        }
        foreach ( $sourceBody->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement ) {
                continue;
            }
            $this->sourceElements[] = $element;
            $this->recordSelectorSignals($element);
            $this->sourceElementsByTag[strtolower($element->tagName)][] = $element;
            $id = $element->getAttribute('id');
            if ( '' !== $id ) {
                $this->sourceElementsById[$id][] = $element;
            }
            foreach ( SourceDom::tokens($element->getAttribute('class')) as $class ) {
                if ( '' !== $class ) {
                    $this->sourceElementsByClass[$class][] = $element;
                }
            }
        }
    }

    public function combinedCss(): string { return $this->combinedCss; }
    public function sourceBody(): DOMElement { return $this->sourceBody; }
    /** @return list<array{path: string, source_path: string, content: string, source_hash: string, media: string, cascade_content?: string}> */
    public function stylesheetAssets(): array { return $this->stylesheetAssets; }
    /** @return list<DOMElement> */
    public function sourceElementsByClass(string $class): array { return $this->sourceElementsByClass[$class] ?? array(); }
    /** @return list<string> */
    public function sourceElementIds(): array { return array_keys($this->sourceElementsById); }
    /** @return list<DOMElement> */
    public function sourceElementsById(string $id): array { return $this->sourceElementsById[$id] ?? array(); }
    /** Author class attribute predicates bound to this document's source class tokens. */
    public function classAttributeSelectors(): ClassAttributeSelectorProjection
    {
        return $this->classAttributeSelectors ??= new ClassAttributeSelectorProjection(array_map('strval', array_keys($this->sourceClassAttributes)));
    }
    public function specificityShim(): string { return $this->specificityShim; }
    public function classSpecificityShim(): string { return $this->classSpecificityShim; }
    public function idSpecificityShim(): string { return $this->idSpecificityShim; }
    /** A source-only identity for a class that Core can synthesize independently. */
    public function sourceClassMarker(string $class): string
    {
        if ( ! SourceClassIdentity::needsMarker($class) ) {
            return '';
        }
        return $this->sourceClassMarkers[$class] ??= SourceClassIdentity::marker($class);
    }
    /** @param list<array<string, mixed>> $rules */
    public function installStyleRules(array $rules): void
    {
        $this->styleRules = $rules;
        $this->styleRuleCandidateIndex = null;
        $this->captureOnlySelectors = null;
        $this->positionalParents = null;
        $this->contextHashes = null;
    }

    /** @return list<array<string, mixed>> */
    public function styleRules(): array
    {
        return $this->styleRules;
    }

    /**
     * Selectors only a capture's own replay stylesheet declares. They describe
     * the producer's replay state (an opened panel, a revealed first child),
     * not the author's presentation.
     *
     * @return array<string, true>
     */
    public function captureOnlySelectors(): array
    {
        if ( null !== $this->captureOnlySelectors ) return $this->captureOnlySelectors;
        $capture = array();
        $authored = array();
        foreach ( $this->styleRules as $rule ) {
            foreach ( (array) ( $rule['selectors'] ?? array() ) as $selector ) {
                $selector = is_array($selector) ? ( $selector['selector'] ?? null ) : $selector;
                if ( ! is_string($selector) ) continue;
                if ( empty($rule['capture_only']) ) $authored[trim($selector)] = true;
                else $capture[trim($selector)] = true;
            }
        }
        return $this->captureOnlySelectors = array_diff_key($capture, $authored);
    }

    public function allocateMarker(string $kind): string
    {
        if ( ! EngineMarker::isDeclaredKind($kind) ) {
            throw new \InvalidArgumentException("Undeclared engine marker kind: {$kind}. Declare it in EngineMarker::DOCUMENT_KINDS.");
        }
        do {
            $marker = 'blocks-engine-' . $kind . '-' . $this->markerSeed . '-' . $this->markerCounter++;
        } while ( $this->markerCollides($marker) );
        return $marker;
    }

    public function allocateStableMarker(string $kind, string $identity): string
    {
        if ( ! EngineMarker::isDeclaredKind($kind) ) {
            throw new \InvalidArgumentException("Undeclared engine marker kind: {$kind}.");
        }
        $seed = substr(hash('sha256', $identity), 0, 12);
        $counter = 0;
        do {
            $marker = 'blocks-engine-' . $kind . '-' . $seed . '-' . $counter++;
        } while ( $this->markerCollides($marker) );
        return $marker;
    }

    /** Whether the document HTML or the combined author CSS already states `$marker`. */
    private function markerCollides(string $marker): bool
    {
        return isset($this->markerCollisionIndex()[$marker]);
    }

    /**
     * One index of the engine-marker text already present in the document HTML
     * and the combined author CSS, so allocating a marker is a hash lookup
     * instead of a scan of both texts per candidate.
     *
     * Every allocated marker is `blocks-engine-` followed by lowercase marker
     * characters (the kind, the hex seed and the decimal counter) and ends in
     * its counter's digits. The texts contain a marker exactly when they
     * contain the prefix and the marker characters after that occurrence run
     * on through the whole marker, so indexing every digit-ending prefix of
     * each such run answers the old str_contains() scan exactly. Sources
     * without the prefix — nearly all of them — build an empty index, and a
     * source reusing engine markers indexes each prefix occurrence, including one
     * nested inside another's run.
     * `$markerCollisionTexts` is written only by the constructor, so the lazy
     * index can never go stale.
     */
    private function markerCollisionIndex(): array
    {
        if ( null !== $this->markerCollisionIndex ) {
            return $this->markerCollisionIndex;
        }
        $index = array();
        foreach ( $this->markerCollisionTexts as $text ) {
            $offset = 0;
            while ( false !== ( $position = strpos($text, 'blocks-engine-', $offset) ) ) {
                $start = $position + strlen('blocks-engine-');
                $run = strspn($text, 'abcdefghijklmnopqrstuvwxyz0123456789-', $start);
                for ( $end = 0; $end < $run; $end++ ) {
                    if ( $text[$start + $end] >= '0' && $text[$start + $end] <= '9' ) {
                        $index[substr($text, $position, strlen('blocks-engine-') + $end + 1)] = true;
                    }
                }
                $offset = $position + 1;
            }
        }
        return $this->markerCollisionIndex = $index;
    }

    /**
     * A per-element marker named from what can tell the element apart rather
     * than from the compiled document.
     *
     * A rule arm naming a marker applies to every element that carries it in
     * any document that loads the rule. With document-seeded markers, a
     * stylesheet shared by N pages therefore projected to N different texts.
     * Here the seed hashes the author CSS identity, the document type, the
     * element's subtree (observable attributes and text presence for semantic
     * and RichText targets; full text for other marker contracts), and
     * every ancestor, with its siblings and position wherever a positional
     * selector observes them (positionalParents()). Two
     * documents give one element the same marker only when all of that
     * agrees, so every author selector sees the same element in both and a
     * rule projected for one of them is correct for the other.
     *
     * Around the element, a class or id attribute is reduced to what author
     * CSS can observe of it: the class and id tokens it names, and the outcome
     * of each `[class…=…]` / `[id…=…]` predicate. CMS pages stamp per-page
     * tokens on the body and the content wrappers (`page-id-12`, `post-12`);
     * keeping them would give shared chrome a different marker on every page.
     * (`:has()` could observe any part of the document, but the source
     * matcher never evaluates it, so no marker or rule arm depends on it.)
     *
     * Without a resolvable source element the document seed is used.
     */
    public function allocateContextMarker(string $kind, string $path): string
    {
        $element = '' === $path ? null : $this->sourceElementAtPath($path);
        if ( null === $element ) {
            return $this->allocateMarker($kind);
        }
        return $this->allocateStableMarker(
            $kind,
            "context\0" . $kind . "\0\0" . $this->authorCssIdentity . "\0" . $this->documentTypeIdentity($element) . "\0" . $this->contextHash($element) . "\0" . $this->subtreeHash($element, in_array($kind, array('semantic', 'richtext'), true))
        );
    }

    /**
     * The marker standing for a source tag, named from the tag alone. Every
     * element of the tag carries it, and a type selector means the same thing
     * in any stylesheet on any page, so pages under different author CSS
     * project a shared stylesheet's type rules to the same text. Shared-chrome
     * extraction does not treat these markers as chrome hooks
     * (WordPressSitePlan::projectSharedChromeStylesheets()), so a type rule
     * from one page's own stylesheet is never lifted into a global asset.
     */
    public function allocateTagMarker(string $tagName): string
    {
        return $this->allocateStableMarker('source-' . $tagName, "tag\0" . $tagName);
    }

    public function sourceElementAtPath(string $path): ?DOMElement
    {
        $document = $this->sourceBody->ownerDocument;
        if ( ! $document instanceof \DOMDocument ) {
            return null;
        }
        $this->sourceXPath ??= new \DOMXPath($document);
        $nodes = @$this->sourceXPath->query($path);
        if ( false === $nodes || 1 !== $nodes->length ) {
            return null;
        }
        $node = $nodes->item(0);
        return $node instanceof DOMElement && $node->getNodePath() === $path ? $node : null;
    }

    private function documentTypeIdentity(DOMElement $element): string
    {
        $type = $element->ownerDocument?->doctype;
        return null === $type ? '' : $type->name . "\0" . $type->publicId . "\0" . $type->systemId;
    }

    /**
     * What author CSS can observe of a class or id attribute: the `.name` and
     * `#name` tokens it names, and every `[class…=…]` / `[id…=…]` predicate.
     *
     * @return array{classes: array<string, true>, ids: array<string, true>, predicates: list<array{attribute: string, operator: string, value: string, insensitive: bool}>, raw: bool}
     */
    private function markerContextPolicy(): array
    {
        if ( null !== $this->markerContextPolicy ) {
            return $this->markerContextPolicy;
        }
        $css = $this->combinedCss;
        $policy = array( 'classes' => array(), 'ids' => array(), 'predicates' => array(), 'raw' => false );
        // Any `.name` / `#name` token, escapes resolved. Over-collecting (a
        // hex colour, a file extension) only keeps a token that is harmless.
        if ( preg_match_all('/([.#])((?:[A-Za-z0-9_\x80-\xff-]|\\\\.)+)/s', $css, $tokens, PREG_SET_ORDER) ) {
            foreach ( $tokens as $token ) {
                if ( 1 === preg_match('/\\\\[0-9a-fA-F]/', $token[2]) ) {
                    // A hex escape can spell any token; keep every token.
                    $policy['raw'] = true;
                    continue;
                }
                $policy['.' === $token[1] ? 'classes' : 'ids'][strtolower(stripslashes($token[2]))] = true;
            }
        }
        if ( preg_match_all('/\[\s*(class|id)\s*([~|^$*]?)=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s\]]*))\s*([is]?)\s*\]/i', $css, $attributes, PREG_SET_ORDER) ) {
            foreach ( $attributes as $attribute ) {
                $key = strtolower($attribute[1]) . $attribute[2] . "\0" . $attribute[3] . ($attribute[4] ?? '') . ($attribute[5] ?? '') . "\0" . strtolower($attribute[6] ?? '');
                $policy['predicates'][$key] = array(
                    'attribute' => strtolower($attribute[1]),
                    'operator' => $attribute[2],
                    'value' => stripslashes($attribute[3] . ($attribute[4] ?? '') . ($attribute[5] ?? '')),
                    'insensitive' => 'i' === strtolower($attribute[6] ?? ''),
                );
            }
        }
        $policy['predicates'] = array_values($policy['predicates']);
        return $this->markerContextPolicy = $policy;
    }

    /** Whether `$value` satisfies one CSS attribute-selector predicate. */
    private static function attributePredicate(string $value, string $operator, string $expected, bool $insensitive): bool
    {
        if ( $insensitive ) {
            $value = strtolower($value);
            $expected = strtolower($expected);
        }
        return match ( $operator ) {
            '' => $value === $expected,
            '~' => '' !== $expected && 1 !== preg_match('/\s/', $expected) && in_array($expected, SourceDom::tokens($value), true),
            '|' => $value === $expected || str_starts_with($value, $expected . '-'),
            '^' => '' !== $expected && str_starts_with($value, $expected),
            '$' => '' !== $expected && str_ends_with($value, $expected),
            '*' => '' !== $expected && str_contains($value, $expected),
            default => false,
        };
    }

    /**
     * The element's start tag. In context (`$observable`) its class and id
     * keep only what author CSS can observe of them, plus whether it is empty.
     */
    private function startTagIdentity(DOMElement $element, bool $observable = false): string
    {
        $tag = strtolower($element->tagName);
        $attributes = array();
        foreach ( $element->attributes ?? array() as $attribute ) {
            $attributes[strtolower($attribute->nodeName)] = (string) $attribute->nodeValue;
        }
        if ( $observable ) {
            // An empty style attribute (left behind by scripts) styles nothing.
            if ( isset($attributes['style']) && '' === trim($attributes['style']) ) {
                unset($attributes['style']);
            }
            // Which item is current varies by route while the resting menu is
            // shared, so route state never renames a context marker.
            unset($attributes['aria-current']);
            if ( isset($attributes['class']) ) {
                $classes = SourceDom::tokens($attributes['class']);
                $attributes['class'] = implode(' ', array_diff($classes, SourceClassIdentity::routeState($element)));
            }
            $attributes["\0empty"] = $element->hasChildNodes() ? '0' : '1';
            $policy = $this->markerContextPolicy();
            if ( ! $policy['raw'] ) {
                $predicates = '';
                foreach ( $policy['predicates'] as $predicate ) {
                    $value = $attributes[$predicate['attribute']] ?? null;
                    $predicates .= null !== $value && self::attributePredicate($value, $predicate['operator'], $predicate['value'], $predicate['insensitive']) ? '1' : '0';
                }
                $attributes["\0predicates"] = $predicates;
                if ( isset($attributes['class']) ) {
                    $named = array_filter(
                        SourceDom::tokens($attributes['class']),
                        fn (string $class): bool => isset($policy['classes'][strtolower($class)])
                    );
                    sort($named);
                    $attributes['class'] = implode(' ', array_unique($named));
                }
                if ( isset($attributes['id']) && ! isset($policy['ids'][strtolower($attributes['id'])]) ) {
                    $attributes['id'] = '';
                }
            }
        }
        ksort($attributes);
        return $tag . "\0" . json_encode($attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * The elements whose children's order author CSS observes: the parent of
     * any element matched by a selector up to a compound that reads its
     * position (a structural pseudo-class) or its neighbours (either side of
     * `+` / `~`). A positional rule elsewhere on the page observes nothing
     * around other elements.
     *
     * @return \SplObjectStorage<DOMElement, true>
     */
    private function positionalParents(): \SplObjectStorage
    {
        if ( null !== $this->positionalParents ) {
            return $this->positionalParents;
        }
        $parents = new \SplObjectStorage();
        foreach ( $this->styleRules as $rule ) {
            foreach ( $rule['selectors'] as $selector ) {
                $parsed = ($selector['parsed']['supported'] ?? false) ? $selector['parsed'] : ($selector['direct_child_parsed'] ?? array());
                if ( ! ($parsed['supported'] ?? false) ) {
                    continue;
                }
                $compounds = $parsed['compounds'];
                $combinators = $parsed['combinators'];
                foreach ( $compounds as $index => $compound ) {
                    $sibling = in_array($combinators[$index - 1] ?? '', array('+', '~'), true) || in_array($combinators[$index] ?? '', array('+', '~'), true);
                    if ( ! $sibling && CssSelectorMatcher::dependsOnlyOnSubject(array('supported' => true, 'compounds' => array($compound), 'combinators' => array())) ) {
                        continue;
                    }
                    $prefix = array('supported' => true, 'compounds' => array_slice($compounds, 0, $index + 1), 'combinators' => array_slice($combinators, 0, $index));
                    foreach ( $this->selectorCandidates($prefix) as $candidate ) {
                        $parent = $candidate->parentNode;
                        if ( $parent instanceof DOMElement && ! isset($parents[$parent]) && CssSelectorMatcher::matches($candidate, $prefix)['matches'] ) {
                            $parents[$parent] = true;
                        }
                    }
                }
            }
        }
        return $this->positionalParents = $parents;
    }

    /**
     * The element's ancestor chain as author CSS can observe it. At a level
     * whose children's order a positional selector observes, the siblings and
     * the position among them count too.
     */
    private function contextHash(DOMElement $element): string
    {
        $this->contextHashes ??= new \SplObjectStorage();
        if ( isset($this->contextHashes[$element]) ) {
            return $this->contextHashes[$element];
        }
        $parent = $element->parentNode;
        $identity = $this->startTagIdentity($element, true);
        if ( $parent instanceof DOMElement && isset($this->positionalParents()[$parent]) ) {
            $siblings = array();
            $position = -1;
            foreach ( $parent->childNodes as $child ) {
                if ( $child instanceof DOMElement ) {
                    if ( $child->isSameNode($element) ) {
                        $position = count($siblings);
                    }
                    $siblings[] = $this->startTagIdentity($child, true);
                }
            }
            $identity = $position . "\0" . implode("\0", $siblings);
        }
        $hash = hash('sha256', ( $parent instanceof DOMElement ? $this->contextHash($parent) : '' ) . "\0" . $identity);
        $this->contextHashes[$element] = $hash;
        return $hash;
    }

    /** Semantic targets need CSS-observable text presence, not prose identity. */
    private function subtreeHash(DOMElement $element, bool $ignoreText = false): string
    {
        $cache = $ignoreText
            ? ($this->selectorSubtreeHashes ??= new \SplObjectStorage())
            : ($this->subtreeHashes ??= new \SplObjectStorage());
        if ( isset($cache[$element]) ) {
            return $cache[$element];
        }
        $parts = array($this->startTagIdentity($element, true));
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                $parts[] = $this->subtreeHash($child, $ignoreText);
            } elseif ( XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType ) {
                // CSS can distinguish an empty element, but cannot select by
                // prose. Including text here gives identical repeated labels
                // separate markers and repeats their selectors on every route.
                if ( ! $ignoreText || '' !== (string) $child->nodeValue ) {
                    $parts[] = $ignoreText ? '#text' : '#text:' . (string) $child->nodeValue;
                }
            }
        }
        $hash = hash('sha256', implode("\0", $parts));
        $cache[$element] = $hash;
        return $hash;
    }

    /** @param list<string> $avoidTexts */
    public static function allocateSiteMarker(string $kind, array $avoidTexts): string
    {
        $prefix = EngineMarker::SPECIFICITY_PREFIXES[$kind] ?? 'blocks-engine-' . $kind . '-site-';
        $collisions = array();
        foreach ($avoidTexts as $text) {
            preg_match_all('/' . preg_quote($prefix, '/') . '([0-9]+)/', $text, $matches);
            foreach ($matches[1] as $digits) {
                // prefix1 also occurs in prefix12; leading zeroes only block zero.
                $length = '0' === $digits[0] ? 1 : min(strlen($digits), strlen((string) PHP_INT_MAX));
                for ($size = 1; $size <= $length; ++$size) $collisions[substr($digits, 0, $size)] = true;
            }
        }
        $suffix = 0;
        while (isset($collisions[$suffix])) ++$suffix;
        return $prefix . $suffix;
    }

    public function selectorMatchCache(): CssSelectorMatchCache
    {
        return $this->selectorMatchCache ??= new CssSelectorMatchCache();
    }

    public function releaseSelectorMatchCache(): CssSelectorMatchCache
    {
        $cache = $this->selectorMatchCache();
        $this->selectorMatchCache = null;
        return $cache;
    }

    public function hasSelectorMatches(string $selector): bool { return array_key_exists($selector, $this->selectorMatches); }
    /** @return list<DOMElement> */
    public function selectorMatches(string $selector): array { return $this->selectorMatches[$selector] ?? array(); }
    /** @param list<DOMElement> $matches @return list<DOMElement> */
    public function rememberSelectorMatches(string $selector, array $matches): array
    {
        return $this->selectorMatches[$selector] = $matches;
    }

    /** @param array<string, mixed> $parsed @return list<DOMElement> */
    public function selectorCandidates(array $parsed): array
    {
        $compounds = $parsed['compounds'] ?? array();
        $rightmost = CssSelectorCompoundInspector::rightmost($compounds);
        $candidates = array();
        foreach ( $rightmost['ids'] ?? array() as $id ) {
            // A projected captured dialog stands in for the id's holder through
            // its editor anchor class, so it is a candidate for the id too.
            $candidates[] = array_merge($this->sourceElementsById[$id] ?? array(), $this->sourceElementsByClass[EngineMarker::editorAnchorClass((string) $id)] ?? array());
        }
        foreach ( $rightmost['classes'] ?? array() as $class ) {
            $candidates[] = $this->sourceElementsByClass[$class] ?? array();
        }
        if ( is_string($rightmost['type'] ?? null) && '' !== $rightmost['type'] ) {
            $candidates[] = $this->sourceElementsByTag[strtolower($rightmost['type'])] ?? array();
        }
        foreach ( $rightmost['attributes'] ?? array() as $attribute ) {
            $name = strtolower((string) ($attribute['name'] ?? ''));
            if ( '' !== $name ) {
                $candidates[] = $this->sourceElementsByAttribute($name);
            }
        }
        if ( array() === $candidates ) {
            return $this->sourceElements;
        }
        usort($candidates, static fn (array $left, array $right): int => count($left) <=> count($right));
        return $candidates[0];
    }

    /** @return list<DOMElement> */
    private function sourceElementsByAttribute(string $name): array
    {
        if ( isset($this->sourceElementsByAttribute[$name]) ) {
            return $this->sourceElementsByAttribute[$name];
        }

        return $this->sourceElementsByAttribute[$name] = array_values(array_filter(
            $this->sourceElements,
            static fn (DOMElement $element): bool => $element->hasAttribute($name)
        ));
    }

    /** @param array<string, mixed> $parsed */
    public function selectorCanMatch(array $parsed): bool
    {
        foreach ( $parsed['compounds'] ?? array() as $compound ) {
            if ( is_string($compound['type'] ?? null) && '' !== $compound['type'] && ! isset($this->sourceTags[strtolower($compound['type'])]) ) {
                return false;
            }
            foreach ( $compound['ids'] ?? array() as $id ) {
                if ( ! isset($this->sourceIds[$id]) && ! isset($this->sourceElementsByClass[EngineMarker::editorAnchorClass((string) $id)]) ) {
                    return false;
                }
            }
            foreach ( $compound['classes'] ?? array() as $class ) {
                if ( ! isset($this->sourceClasses[$class]) ) {
                    return false;
                }
            }
        }
        return true;
    }

    /** @return array{universal: list<array<string, mixed>>, ids: array<string, list<array<string, mixed>>>, classes: array<string, list<array<string, mixed>>>, tags: array<string, list<array<string, mixed>>>, attributes: array<string, list<array<string, mixed>>>, total: int} */
    public function styleRuleCandidateIndex(): array
    {
        if ( null !== $this->styleRuleCandidateIndex ) {
            return $this->styleRuleCandidateIndex;
        }
        $index = array('universal' => array(), 'ids' => array(), 'classes' => array(), 'tags' => array(), 'attributes' => array(), 'total' => 0);
        $sequence = 0;
        foreach ( $this->styleRules as $rule ) {
            if ( ! self::rendersOnScreen($rule['conditions'] ?? array()) ) {
                continue;
            }
            foreach ( $rule['selectors'] as $selectorIndex => $selector ) {
                $parsed = $selector['parsed'];
                // Candidates exist to be matched. A selector this matcher cannot
                // evaluate, in either its authored or direct-child form, never
                // matches an element, so it is not a candidate for one.
                if ( ! ($parsed['supported'] ?? false) && ! ($selector['direct_child_parsed']['supported'] ?? false) ) {
                    continue;
                }
                $buckets = \Automattic\BlocksEngine\PhpTransformer\Css\CssSubjectIndex::buckets($parsed, true);
                $entry = array(
                    'order' => $rule['order'],
                    'sequence' => $sequence++,
                    'rule' => array_merge($selector, array('declarations' => $rule['declarations'], 'rule_order' => $rule['order'], 'key' => $rule['order'] . ':' . $selectorIndex, 'source_path' => $rule['source_path'] ?? '', 'source_hash' => $rule['source_hash'] ?? '')),
                );
                \Automattic\BlocksEngine\PhpTransformer\Css\CssSubjectIndex::insert($index, $buckets, $entry);
                ++$index['total'];
            }
        }
        return $this->styleRuleCandidateIndex = $index;
    }

    /**
     * Whether a rule under these conditions can paint on a screen. A rule
     * inside `@media print` states nothing about any rendered viewport, so
     * it describes no element the screen shows.
     *
     * @param array<int, string> $conditions
     */
    private static function rendersOnScreen(array $conditions): bool
    {
        foreach ( $conditions as $condition ) {
            if ( 1 === preg_match('/^@media\s+(.+)$/is', trim((string) $condition), $media) && ! CssCascade::mediaConditionTargetsScreen($media[1]) ) {
                return false;
            }
        }
        return true;
    }

    private function recordSelectorSignals(DOMElement $element): void
    {
        $this->sourceTags[strtolower($element->tagName)] = true;
        $classAttribute = $element->getAttribute('class');
        if ( '' !== trim($classAttribute) ) {
            $this->sourceClassAttributes[$classAttribute] = true;
        }
        $id = $element->getAttribute('id');
        if ( '' !== $id ) {
            $this->sourceIds[$id] = true;
        }
        foreach ( SourceDom::tokens($element->getAttribute('class')) as $class ) {
            if ( '' !== $class ) {
                $this->sourceClasses[$class] = true;
            }
        }
    }
}
