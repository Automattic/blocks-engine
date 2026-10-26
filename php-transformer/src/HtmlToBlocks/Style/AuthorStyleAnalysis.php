<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatchCache;
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
    /** @var array<string, list<DOMElement>> */
    private array $selectorMatches = array();
    private ?CssSelectorMatchCache $selectorMatchCache;
    /** @var list<array<string, mixed>> */
    private array $styleRules = array();
    /** @var array<string, mixed>|null */
    private ?array $styleRuleCandidateIndex = null;
    private readonly string $markerSeed;
    private int $markerCounter = 0;
    /** @var array{string, string} */
    private readonly array $markerCollisionTexts;
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
    /** @var \SplObjectStorage<DOMElement, string>|null */
    private ?\SplObjectStorage $subtreeHashes = null;

    /** @param list<array{path: string, source_path: string, content: string, source_hash: string, media: string}> $stylesheetAssets */
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
            foreach ( preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array() as $class ) {
                if ( '' !== $class ) {
                    $this->sourceElementsByClass[$class][] = $element;
                }
            }
        }
    }

    public function combinedCss(): string { return $this->combinedCss; }
    public function sourceBody(): DOMElement { return $this->sourceBody; }
    /** @return list<array{path: string, source_path: string, content: string, source_hash: string, media: string}> */
    public function stylesheetAssets(): array { return $this->stylesheetAssets; }
    /** @return list<DOMElement> */
    public function sourceElementsByClass(string $class): array { return $this->sourceElementsByClass[$class] ?? array(); }
    /** @return list<string> */
    public function sourceElementIds(): array { return array_keys($this->sourceElementsById); }
    /** @return list<DOMElement> */
    public function sourceElementsById(string $id): array { return $this->sourceElementsById[$id] ?? array(); }
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
    }

    /** @return list<array<string, mixed>> */
    public function styleRules(): array
    {
        return $this->styleRules;
    }

    public function allocateMarker(string $kind): string
    {
        if ( ! EngineMarker::isDeclaredKind($kind) ) {
            throw new \InvalidArgumentException("Undeclared engine marker kind: {$kind}. Declare it in EngineMarker::DOCUMENT_KINDS.");
        }
        do {
            $marker = 'blocks-engine-' . $kind . '-' . $this->markerSeed . '-' . $this->markerCounter++;
        } while ( str_contains($this->markerCollisionTexts[0], $marker) || str_contains($this->markerCollisionTexts[1], $marker) );
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
        } while ( str_contains($this->markerCollisionTexts[0], $marker) || str_contains($this->markerCollisionTexts[1], $marker) );
        return $marker;
    }

    /**
     * A per-element marker named from what can tell the element apart rather
     * than from the compiled document.
     *
     * A rule arm naming a marker applies to every element that carries it in
     * any document that loads the rule. With document-seeded markers, a
     * stylesheet shared by N pages therefore projected to N different texts.
     * Here the seed hashes the author CSS identity, the document type, the
     * element's source path and full subtree (every attribute and text node),
     * and every ancestor with all its siblings and their emptiness. Two
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
            "context\0" . $kind . "\0" . $path . "\0" . $this->authorCssIdentity . "\0" . $this->documentTypeIdentity($element) . "\0" . $this->contextHash($element) . "\0" . $this->subtreeHash($element)
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
            '~' => '' !== $expected && 1 !== preg_match('/\s/', $expected) && in_array($expected, preg_split('/\s+/', trim($value)) ?: array(), true),
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
                        preg_split('/\s+/', trim($attributes['class'])) ?: array(),
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

    /** The element's ancestors, each with all its siblings and its position among them. */
    private function contextHash(DOMElement $element): string
    {
        $this->contextHashes ??= new \SplObjectStorage();
        if ( isset($this->contextHashes[$element]) ) {
            return $this->contextHashes[$element];
        }
        $parent = $element->parentNode;
        $siblings = array();
        $position = -1;
        foreach ( $parent?->childNodes ?? array() as $child ) {
            if ( $child instanceof DOMElement ) {
                if ( $child->isSameNode($element) ) {
                    $position = count($siblings);
                }
                $siblings[] = $this->startTagIdentity($child, true);
            }
        }
        $hash = hash('sha256', ( $parent instanceof DOMElement ? $this->contextHash($parent) : '' ) . "\0" . $position . "\0" . implode("\0", $siblings));
        $this->contextHashes[$element] = $hash;
        return $hash;
    }

    /** The element's full subtree: every start tag, attribute and text node. */
    private function subtreeHash(DOMElement $element): string
    {
        $this->subtreeHashes ??= new \SplObjectStorage();
        if ( isset($this->subtreeHashes[$element]) ) {
            return $this->subtreeHashes[$element];
        }
        $parts = array($this->startTagIdentity($element));
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                $parts[] = $this->subtreeHash($child);
            } elseif ( XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType ) {
                $parts[] = '#text:' . (string) $child->nodeValue;
            }
        }
        $hash = hash('sha256', implode("\0", $parts));
        $this->subtreeHashes[$element] = $hash;
        return $hash;
    }

    /** @param list<string> $avoidTexts */
    public static function allocateSiteMarker(string $kind, array $avoidTexts): string
    {
        $suffix = 0;
        do {
            $marker = (EngineMarker::SPECIFICITY_PREFIXES[$kind] ?? 'blocks-engine-' . $kind . '-site-') . $suffix++;
            $collision = false;
            foreach ($avoidTexts as $text) {
                if (str_contains($text, $marker)) {
                    $collision = true;
                    break;
                }
            }
        } while ($collision);
        return $marker;
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
        $rightmost = $compounds[array_key_last($compounds)] ?? array();
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
            foreach ( $rule['selectors'] as $selectorIndex => $selector ) {
                $parsed = $selector['parsed'];
                // Candidates exist to be matched. A selector this matcher cannot
                // evaluate, in either its authored or direct-child form, never
                // matches an element, so it is not a candidate for one.
                if ( ! ($parsed['supported'] ?? false) && ! ($selector['direct_child_parsed']['supported'] ?? false) ) {
                    continue;
                }
                $compounds = $parsed['compounds'] ?? array();
                $rightmost = array() === $compounds ? null : $compounds[array_key_last($compounds)];
                $target = 'universal';
                $key = '';
                if ( $parsed['supported'] && is_array($rightmost) ) {
                    if ( array() !== ($rightmost['ids'] ?? array()) ) {
                        $target = 'ids';
                        $key = (string) $rightmost['ids'][0];
                    } elseif ( array() !== ($rightmost['classes'] ?? array()) ) {
                        $target = 'classes';
                        $key = (string) $rightmost['classes'][0];
                    } elseif ( is_string($rightmost['type'] ?? null) && '' !== $rightmost['type'] ) {
                        $target = 'tags';
                        $key = strtolower((string) $rightmost['type']);
                    } elseif ( array() !== ($rightmost['attributes'] ?? array()) ) {
                        $name = (string) ($rightmost['attributes'][0]['name'] ?? '');
                        if ( 1 === preg_match('/^[a-z][a-z0-9_-]*$/', $name) ) {
                            $target = 'attributes';
                            $key = $name;
                        }
                    }
                }
                $entry = array(
                    'order' => $rule['order'],
                    'sequence' => $sequence++,
                    'rule' => array_merge($selector, array('declarations' => $rule['declarations'], 'rule_order' => $rule['order'], 'key' => $rule['order'] . ':' . $selectorIndex, 'source_path' => $rule['source_path'] ?? '', 'source_hash' => $rule['source_hash'] ?? '')),
                );
                if ( 'universal' === $target ) {
                    $index['universal'][] = $entry;
                } else {
                    $index[$target][$key][] = $entry;
                }
                ++$index['total'];
            }
        }
        return $this->styleRuleCandidateIndex = $index;
    }

    private function recordSelectorSignals(DOMElement $element): void
    {
        $this->sourceTags[strtolower($element->tagName)] = true;
        $id = $element->getAttribute('id');
        if ( '' !== $id ) {
            $this->sourceIds[$id] = true;
        }
        foreach ( preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array() as $class ) {
            if ( '' !== $class ) {
                $this->sourceClasses[$class] = true;
            }
        }
    }
}
