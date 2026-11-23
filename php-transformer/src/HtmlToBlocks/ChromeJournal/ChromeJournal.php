<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ChromeJournal;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use DOMElement;

/**
 * Converts a subtree pages repeat once per site.
 *
 * Converting an element is a function of the element as preparation left it,
 * what author CSS can observe of its context, the page's transform options,
 * and the page path wherever the subtree's links resolve against it. A
 * {@see key()} hashes exactly those inputs. The first page to convert a key
 * records the conversion as a journal: the block, the fallbacks, the session
 * state it changed, the attributes it wrote into the subtree, and the markers
 * it allocated. A page reaching the same key replays the journal instead,
 * rebased onto its own subtree, provenance ids and markers. A key no page has
 * recorded converts normally, so sharing never changes what a page compiles.
 */
final class ChromeJournal
{
    /** Session state that is analysis cache or metrics, never conversion output. */
    private const UNJOURNALED_PROPERTY = '/[Cc]ache|Hits$|Builds$|Misses$|Peak|Checks$|Executions$|Lookups$|matchBytes|matchEntries|Retained$|Skipped$/';

    /** Block and evidence fields holding transformation provenance ids. */
    private const PROVENANCE_ID_FIELDS = array('_source_provenance_id' => true, 'provenance_id' => true);
    private const PROVENANCE_ID_LIST_FIELDS = array('_source_provenance_ids' => true);

    private const ELEMENT = "\0chrome-element";

    /** @var \SplObjectStorage<DOMElement,true> */
    private \SplObjectStorage $candidates;

    /**
     * @param list<string> $selectors {@see SourceDom::elementSelector()} paths of repeated subtrees.
     * @param array<string,mixed> $options The page's transform options.
     */
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly ChromeJournalStore $store,
        DOMElement $body,
        array $selectors,
        array $options
    ) {
        $this->candidates = new \SplObjectStorage();
        foreach ($selectors as $selector) {
            $element = SourceDom::elementAtSelector($body, $selector);
            if (null !== $element) $this->candidates[$element] = true;
        }
        // The source path enters a key only where the subtree resolves links
        // against it; the page HTML through the prepared subtree and its
        // context; box observations per subtree element.
        unset($options['source'], $options['declarative_state_html'], $options['chrome_journal']);
        $options = array_filter($options, static fn (mixed $option): bool => !is_object($option));
        ksort($options);
        // Inline CSS names the page as its source. Key relative URLs by
        // their targets, not by directories that may resolve to the same asset.
        $page = $session->sourcePath();
        $resolvedUrls = array();
        foreach ($options['author_stylesheet_assets'] ?? array() as $asset) {
            if (!is_array($asset) || $page !== ($asset['source_path'] ?? null)) continue;
            \Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\CssUrlRewriter::rewrite((string) ($asset['content'] ?? ''), static function (string $url) use ($page, &$resolvedUrls): string {
                if (1 !== preg_match('~^(?:[a-z][a-z0-9+.-]*:|/|#)~i', $url)) $resolvedUrls[] = ArtifactPath::resolveRelativePath($url, $page);
                return $url;
            });
        }
        $resolvedUrls = array_values(array_unique($resolvedUrls));
        sort($resolvedUrls);
        unset($options['author_stylesheet_assets'], $options['static_css'], $options['stylesheet_payloads'], $options['asset_metadata']);
        array_walk_recursive($options, static function (mixed &$value) use ($page): void {
            if ($value === $page) $value = "\0page";
        });
        $this->pageDigest = hash('sha256', serialize(array($options, $resolvedUrls)));
    }

    private readonly string $pageDigest;
    private bool $recording = false;

    public function covers(DOMElement $element): bool
    {
        return isset($this->candidates[$element]);
    }

    /**
     * Convert a covered element through the journal.
     *
     * @param array<int,array<string,mixed>> $fallbacks
     * @param \Closure(array<int,array<string,mixed>>&):?array<string,mixed> $convert
     * @return array<string,mixed>|null
     */
    public function convert(DOMElement $element, array &$fallbacks, \Closure $convert): ?array
    {
        $this->candidates->detach($element);
        // A journal records one subtree's effects; a candidate inside one
        // being recorded is part of that recording.
        if ($this->recording) return $convert($fallbacks);
        $key = $this->key($element);
        if (null === $key) return $convert($fallbacks);
        $journal = $this->store->journal($key);
        if (null !== $journal) {
            $replay = $this->replay($journal, $element);
            if (null !== $replay) {
                array_push($fallbacks, ...$replay['fallbacks']);
                return $replay['block'];
            }
            return $convert($fallbacks);
        }
        // Most keys one page alone reaches (a post's own heading before it,
        // say); recording starts at a key's second sighting.
        if (!$this->store->sighted($key)) return $convert($fallbacks);
        $before = $this->state();
        $attributes = self::attributes($element);
        $fallbackCount = count($fallbacks);
        $nextSourceId = $this->session->transformationProvenanceState()->nextSourceId();
        $analysis = $this->session->authorStyleAnalysis();
        $analysis->logAllocations();
        $this->recording = true;
        try {
            $block = $convert($fallbacks);
        } finally {
            $this->recording = false;
            $allocations = $analysis->allocations();
        }
        $recorded = $this->record($element, $before, $attributes, array_slice($fallbacks, $fallbackCount), $block, $nextSourceId, $allocations);
        if (null !== $recorded) $this->store->record($key, $recorded);
        return $block;
    }

    /** Hash of every input that converting $element reads. */
    private function key(DOMElement $element): ?string
    {
        $css = $this->session->authorStyleAnalysis()->journalIdentity($element);
        if (null === $css) return null;
        $sourcePath = $this->session->sourcePath();
        $links = array();
        $relative = false;
        foreach (self::elements($element) as $node) {
            foreach (array('href', 'src', 'srcset', 'poster', 'action', 'data-src') as $attribute) {
                if (!$node->hasAttribute($attribute)) continue;
                $value = trim($node->getAttribute($attribute));
                if ('href' === $attribute && ArtifactPath::targetsDocument($value, $sourcePath)) $links[] = $value;
                $relative = $relative || ('' !== $value && 1 !== preg_match('~^(?:[a-z][a-z0-9+.-]*:|/|#)~i', $value));
            }
        }
        return hash('sha256', serialize(array(
            $this->pageDigest,
            $css,
            SubtreeIdentity::normalize((string) $element->ownerDocument?->saveHTML($element)),
            // Source descriptions of the subtree read its root's preceding
            // heading and its ancestors' start tags.
            SourceDom::nearestPreviousHeadingText($element),
            self::ancestorStartTags($element),
            str_contains($sourcePath, '/') ? strtok($sourcePath, '/') : '',
            $relative ? dirname($sourcePath) : '',
            $links,
        )));
    }

    /**
     * @param array<string,array<string,mixed>> $before
     * @param list<array<string,string>> $attributes
     * @param list<array<string,mixed>> $fallbacks
     * @param array<string,mixed>|null $block
     * @param list<array{0:string,1:list<string>,2:string}> $allocations
     * @return array<string,mixed>|null Null when the conversion changed the subtree's shape.
     */
    private function record(DOMElement $element, array $before, array $attributes, array $fallbacks, ?array $block, int $nextSourceId, array $allocations): ?array
    {
        $after = self::attributes($element);
        if (count($after) !== count($attributes)) return null;
        $delta = array();
        foreach ($this->state() as $name => $properties) {
            foreach ($properties as $property => $value) {
                $previous = $before[$name][$property] ?? null;
                if ($previous === $value) continue;
                $delta[] = array($name, $property, self::changes($previous, $value));
            }
        }
        $dom = array();
        foreach ($after as $index => $current) if ($current !== $attributes[$index]) $dom[$index] = $current;
        $indexes = new \SplObjectStorage();
        foreach (self::elements($element) as $index => $node) $indexes[$node] = $index;
        $encoded = self::encodeElements(array('delta' => $delta, 'fallbacks' => $fallbacks, 'block' => $block), $indexes);
        if (null === $encoded) return null;
        return $encoded + array(
            'bound_markers' => self::boundMarkers($before, $element->getNodePath(), serialize($encoded)),
            'dom' => $dom,
            'root_path' => $element->getNodePath(),
            'source_path' => $this->session->sourcePath(),
            'next_source_id' => $nextSourceId,
            'allocations' => $allocations,
        );
    }

    /**
     * @param array<string,mixed> $journal
     * @return array{block:?array<string,mixed>,fallbacks:list<array<string,mixed>>}|null Null when the journal does not fit this page.
     */
    private function replay(array $journal, DOMElement $element): ?array
    {
        $elements = self::elements($element);
        $rootPath = (string) $element->getNodePath();
        $targetPath = $this->session->sourcePath();
        $offset = $this->session->transformationProvenanceState()->nextSourceId() - (int) $journal['next_source_id'];
        $xpath = new \DOMXPath($element->ownerDocument);
        $markers = array();
        $missing = false;
        $translate = function (mixed $value, ?string $field = null) use (&$translate, &$markers, &$missing, $journal, $elements, $rootPath, $targetPath, $offset, $xpath): mixed {
            if (is_string($value)) {
                if ($value === $journal['source_path']) return $targetPath;
                if (str_starts_with($value, $journal['root_path'])) $value = $rootPath . substr($value, strlen($journal['root_path']));
                return array() === $markers ? $value : strtr($value, $markers);
            }
            if (is_int($value)) {
                return isset(self::PROVENANCE_ID_FIELDS[$field]) && $value >= $journal['next_source_id'] ? $value + $offset : $value;
            }
            if (!is_array($value)) return $value;
            if (array_key_exists(self::ELEMENT, $value)) {
                $reference = $value[self::ELEMENT];
                $node = is_int($reference) ? ($elements[$reference] ?? null) : $xpath->query($translate($reference))?->item(0);
                if (!$node instanceof DOMElement) $missing = true;
                return $node;
            }
            $translated = array();
            foreach ($value as $key => $entry) {
                $translated[is_string($key) ? $translate($key) : $key] = isset(self::PROVENANCE_ID_LIST_FIELDS[$field])
                    ? $translate($entry, '_source_provenance_id')
                    : $translate($entry, is_string($key) ? $key : $field);
            }
            return $translated;
        };
        // Validate all node-id translations before allocating markers or applying state.
        $translate(array($journal['delta'], $journal['block'], $journal['fallbacks'], $journal['dom'], $journal['allocations']));
        if ($missing) return null;
        $analysis = $this->session->authorStyleAnalysis();
        foreach ($journal['allocations'] as [$method, $arguments, $marker]) {
            $markers[$marker] = $analysis->$method(...array_map($translate, $arguments));
        }
        // Markers bound to subtree paths before recording began (a navigation
        // pre-pass, say) take this page's names for the same paths.
        $states = $this->session->journaledStates();
        foreach ($journal['bound_markers'] as [$name, $property, $keys, $marker]) {
            $value = self::read($states[$name], $property);
            foreach ($translate($keys) as $key) $value = is_array($value) ? ($value[$key] ?? null) : null;
            if (!is_string($value)) return null;
            $markers[$marker] = $value;
        }
        $markers = array_filter($markers, static fn (string $replacement, string $marker): bool => $replacement !== $marker, ARRAY_FILTER_USE_BOTH);
        $delta = $translate($journal['delta']);
        $block = $translate($journal['block']);
        $fallbacks = $translate($journal['fallbacks']);
        // Markers were allocated against this page; a reference that does not
        // resolve leaves the page to convert normally, with nothing applied.
        if ($missing) return null;
        $provenanceState = $this->session->transformationProvenanceState();
        // A change inside an entry the recording page already held (one more
        // occurrence of an asset, say) completes that entry only where this
        // page holds it too. Without it the page converts normally.
        foreach ($delta as [$name, $property, $changes]) {
            if (!self::fits(self::read($states[$name], $property), $changes, $states[$name] === $provenanceState ? (int) $journal['next_source_id'] : null, $offset)) return null;
        }

        // Namespace declarations are not attributes DOM can set or remove.
        $current = self::attributes($element);
        foreach ($translate($journal['dom']) as $index => $attributes) {
            $node = $elements[$index];
            foreach (array_diff_key($current[$index], $attributes) as $name => $_) if (!str_starts_with($name, 'xmlns')) $node->removeAttribute($name);
            foreach ($attributes as $name => $value) if (!str_starts_with($name, 'xmlns') && ($current[$index][$name] ?? null) !== $value) $node->setAttribute($name, $value);
        }
        foreach ($delta as [$name, $property, $changes]) {
            $state = $states[$name];
            self::write($state, $property, self::apply(self::read($state, $property), $changes, $state === $provenanceState ? (int) $journal['next_source_id'] : null, $offset));
        }
        return array('block' => $block, 'fallbacks' => $fallbacks);
    }

    /**
     * The changes from $previous to $value, addressed by key path. Maps
     * recurse, so a subtree that adds one entry to a nested map records that
     * entry alone and never the entries other elements of its page wrote.
     * Counters record their increment and grown lists their new tail.
     *
     * @return list<array{0:string,1:list<int|string>,2?:mixed}>
     */
    private static function changes(mixed $previous, mixed $value, array $path = array()): array
    {
        if ($previous === $value) return array();
        if (is_int($previous) && is_int($value)) return array(array('add', $path, $value - $previous));
        if (!is_array($value) || !is_array($previous)) return array(array('set', $path, $value));
        if (array_is_list($previous) && array_is_list($value)) {
            return array_slice($value, 0, count($previous)) === $previous
                ? array(array('append', $path, array_slice($value, count($previous))))
                : array(array('set', $path, $value));
        }
        $changes = array();
        foreach (array_diff_key($previous, $value) as $key => $_) $changes[] = array('unset', array(...$path, $key));
        foreach ($value as $key => $entry) {
            array_push($changes, ...(array_key_exists($key, $previous) ? self::changes($previous[$key], $entry, array(...$path, $key)) : array(array('set', array(...$path, $key), $entry))));
        }
        return $changes;
    }

    /**
     * Apply recorded changes. Provenance state keys its entries by source id:
     * ids the journal allocated move by the same offset as the ids inside
     * its values.
     *
     * @param list<array{0:string,1:list<int|string>,2?:mixed}> $changes
     */
    private static function apply(mixed $current, array $changes, ?int $firstSourceId, int $offset): mixed
    {
        foreach ($changes as $change) {
            [$operation, $path] = $change;
            $path = self::rebasedPath($path, $firstSourceId, $offset);
            $last = 'unset' === $operation ? array_pop($path) : null;
            $target = &$current;
            foreach ($path as $key) {
                // An entry this page never wrote has nothing to remove.
                if (null !== $last && !isset($target[$key])) continue 2;
                $target ??= array();
                $target = &$target[$key];
            }
            if (null !== $last) {
                unset($target[$last]);
            } else {
                $target = match ($operation) {
                    'add' => $target + $change[2],
                    'set' => $change[2],
                    'append' => array_merge($target ?? array(), $change[2]),
                };
            }
            unset($target);
        }
        return $current;
    }

    /**
     * Whether every entry the changes reach into exists in $current.
     * {@see changes()} recurses only into entries the recording page held,
     * so a nested change carries just the part of an entry that changed.
     *
     * @param list<array{0:string,1:list<int|string>,2?:mixed}> $changes
     */
    private static function fits(mixed $current, array $changes, ?int $firstSourceId, int $offset): bool
    {
        foreach ($changes as [$operation, $path]) {
            if ('unset' === $operation) continue;
            $target = $current;
            foreach (array_slice(self::rebasedPath($path, $firstSourceId, $offset), 0, -1) as $key) {
                if (!is_array($target) || !array_key_exists($key, $target)) return false;
                $target = $target[$key];
            }
        }
        return true;
    }

    /**
     * @param list<int|string> $path
     * @return list<int|string>
     */
    private static function rebasedPath(array $path, ?int $firstSourceId, int $offset): array
    {
        if (null !== $firstSourceId && is_int($path[0] ?? null) && $path[0] >= $firstSourceId) $path[0] += $offset;
        return $path;
    }

    /**
     * Element references become subtree indexes, or node paths for elements
     * outside the subtree, so a journal is plain data.
     *
     * @param \SplObjectStorage<DOMElement,int> $indexes
     */
    private static function encodeElements(mixed $value, \SplObjectStorage $indexes): mixed
    {
        if ($value instanceof DOMElement) return array(self::ELEMENT => $indexes[$value] ?? $value->getNodePath());
        if (is_object($value)) return null;
        if (!is_array($value)) return $value;
        foreach ($value as $key => $entry) {
            $encoded = self::encodeElements($entry, $indexes);
            if (null === $encoded && null !== $entry) return null;
            $value[$key] = $encoded;
        }
        return $value;
    }

    /**
     * Engine markers the state held, before recording, under a key path that
     * names an element of the subtree and that the recording refers to. Their
     * names can depend on the whole page, so each replay looks its own up.
     *
     * @param array<string,array<string,mixed>> $state
     * @return list<array{0:string,1:string,2:list<int|string>,3:string}>
     */
    private static function boundMarkers(array $state, string $root, string $recording): array
    {
        $bound = array();
        $walk = static function (mixed $value, array $keys, bool $inside) use (&$walk, &$bound, $root, $recording): void {
            if (is_array($value)) {
                foreach ($value as $key => $entry) {
                    $walk($entry, array(...$keys, $key), $inside || (is_string($key) && ($key === $root || str_starts_with($key, $root . '/'))));
                }
            } elseif ($inside && is_string($value) && 1 === preg_match('/^blocks-engine-[a-z-]+-[a-f0-9]{12}-\d+$/', $value) && str_contains($recording, $value)) {
                $bound[] = array($keys[0], $keys[1], array_slice($keys, 2), $value);
            }
        };
        $walk($state, array(), false);
        return $bound;
    }

    /** @return array<string,array<string,mixed>> */
    private function state(): array
    {
        $state = array();
        foreach ($this->session->journaledStates() as $name => $object) {
            foreach (self::properties($object) as $property => $value) {
                if (!is_object($value) && 1 !== preg_match(self::UNJOURNALED_PROPERTY, $property)) $state[$name][$property] = $value;
            }
        }
        return $state;
    }

    /** @return array<string,mixed> */
    private static function properties(object $object): array
    {
        return (fn (): array => get_object_vars($this))->call($object);
    }

    private static function read(object $object, string $property): mixed
    {
        return (fn (): mixed => $this->$property)->call($object);
    }

    private static function write(object $object, string $property, mixed $value): void
    {
        (function () use ($property, $value): void { $this->$property = $value; })->call($object);
    }

    /** @return list<DOMElement> */
    private static function elements(DOMElement $root): array
    {
        $elements = array($root);
        foreach ($root->getElementsByTagName('*') as $element) $elements[] = $element;
        return $elements;
    }

    /**
     * Document classes (WordPress's `postid-N`, say) differ on every page and
     * reach conversion only through CSS, which the author-style identity
     * already matches against each ancestor; they are left out here.
     *
     * @return list<string>
     */
    private static function ancestorStartTags(DOMElement $element): array
    {
        $tags = array();
        for ($parent = $element->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
            $tag = $parent->cloneNode(false);
            if ($tag instanceof DOMElement && in_array(strtolower($tag->tagName), array('body', 'html'), true)) $tag->removeAttribute('class');
            $tags[] = SubtreeIdentity::normalize((string) $parent->ownerDocument?->saveHTML($tag));
        }
        return $tags;
    }

    /** @return list<array<string,string>> */
    private static function attributes(DOMElement $root): array
    {
        $attributes = array();
        foreach (self::elements($root) as $element) {
            $current = array();
            foreach ($element->attributes as $attribute) $current[$attribute->name] = $attribute->value;
            $attributes[] = $current;
        }
        return $attributes;
    }
}
