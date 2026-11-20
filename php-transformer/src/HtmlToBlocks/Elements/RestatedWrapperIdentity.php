<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMDocument;
use DOMElement;

/**
 * What a folding wrapper's identity is read by, for
 * {@see WrapperDecision::restatedWrapperFold()} (#7505).
 *
 * A wrapper folds into its only child by restating what something reads
 * (its classes, a referenced id, a consumed `data-*` attribute, `dir`) on
 * that child. What nothing reads is inert: it is dropped with the wrapper.
 * This class answers, per attribute and per id, which of the two it is, and
 * whether the child block can take what moves.
 */
final class RestatedWrapperIdentity
{
    /** An attribute nothing reads: it is dropped with the wrapper. */
    public const DROP = 'drop';
    /** An attribute something reads, restated on the child. */
    public const MOVE = 'move';
    /**
     * An attribute only author CSS reads: the child takes an engine-owned
     * class in its place, and each rule reading the attribute through the
     * wrapper gains the arm naming that class ({@see RestatedWrapperFold}).
     */
    public const PROJECT = 'project';
    /** An attribute the fold cannot restate: the wrapper is kept. */
    public const KEEP = 'keep';

    /**
     * Core blocks whose saved markup {@see \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\BlockFactory}
     * rebuilds from attributes, so a class and anchor added to the child's
     * attributes reach its saved root.
     */
    private const REBUILDABLE_CHILD_BLOCKS = array(
        'core/group', 'core/paragraph', 'core/heading', 'core/image', 'core/buttons', 'core/list', 'core/quote',
        'core/columns', 'core/separator', 'core/table', 'core/details', 'core/code', 'core/preformatted',
        'core/gallery', 'core/media-text', 'core/cover', 'core/pullquote', 'core/video', 'core/audio', 'core/file',
    );

    /** Attributes whose value names other elements' ids (IDREF or IDREFS). */
    private const IDREF_ATTRIBUTES = array(
        'for', 'form', 'list', 'headers', 'popovertarget', 'commandfor', 'anchor', 'itemref', 'contextmenu',
        'aria-activedescendant', 'aria-controls', 'aria-describedby', 'aria-details', 'aria-errormessage',
        'aria-flowto', 'aria-labelledby', 'aria-owns',
    );

    /** @var \WeakMap<DOMDocument, array{tokens: array<string, string>, urls: array<string, string>}>|null */
    private ?\WeakMap $documentReferences = null;

    private ?Runtime $runtime = null;

    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly RuntimeIslandAnalyzer $runtimeIslands
    ) {
    }

    /** Whether the child block saves a restated class (and, with `$anchor`, an anchor) on its root. */
    public function childBlockTakesIdentity(string $blockName, bool $anchor): bool
    {
        if (! in_array($blockName, self::REBUILDABLE_CHILD_BLOCKS, true)) return false;
        $this->runtime ??= new Runtime();
        $supports = $this->runtime->blockMetadata($blockName)['supports'] ?? null;
        if (! is_array($supports)) return false;
        // `className: false` only drops the generated block class; `customClassName` governs ours.
        if (false === ($supports['customClassName'] ?? true)) return false;
        return ! $anchor || true === ($supports['anchor'] ?? false);
    }

    /**
     * What the fold does with one of the wrapper's authored attributes other
     * than `class` and `id`, as `[disposition, detail]`. A `data-*` attribute
     * no emitted selector, runtime selector or page script reads is inert;
     * one only author CSS reads is projected to an engine-owned class, so it
     * does not matter whether the child already carries the attribute; one
     * the wrapper's block would carry to its saved root moves; any other
     * read keeps the wrapper. `dir` moves. Anything else keeps the wrapper.
     *
     * @return array{0: string, 1: string}
     */
    public function attributeUse(DOMElement $wrapper, string $name): array
    {
        $name = strtolower($name);
        if ('dir' === $name) return array(self::MOVE, $name);
        if (! str_starts_with($name, 'data-') || str_starts_with($name, 'data-blocks-engine-') || str_starts_with($name, 'data-wp-')) {
            return array(self::KEEP, $name);
        }
        $readers = $this->dataAttributeReaders($name);
        if (array() === $readers) return array(self::DROP, $name);
        if (array('css') === $readers) return array(self::PROJECT, $name);
        if (array_key_exists($name, $this->runtimeIslands->runtimeAttributesCarriedBy($wrapper, 'core/group'))) return array(self::MOVE, $name);
        return array(self::KEEP, $name . ':' . implode('+', $readers));
    }

    /**
     * What reads `#$id` or the id of `$element`, if anything: an author or
     * runtime selector, a fragment link or `url(#…)` reference, an IDREF
     * attribute, or page script text. Null when the id is unreferenced.
     */
    public function idReference(DOMElement $element, string $id): ?string
    {
        $id = trim($id);
        if ('' === $id) return null;
        $pattern = '/#' . preg_quote(self::cssEscaped($id), '/') . '(?![\w\-\x80-\xff\\\\])/u';
        if (1 === preg_match($pattern, $this->session->authorStyleAnalysis()->combinedCss())) return 'css';
        if (1 === preg_match('/#' . preg_quote($id, '/') . '(?![\w\-\x80-\xff])/u', $this->session->authorStyleAnalysis()->combinedCss())) return 'css';
        foreach ($this->runtimeSelectors() as $selector) {
            if (str_contains($selector, '#' . $id) || 1 === preg_match('/\[\s*id\b/i', $selector)) return 'runtime_selector';
        }
        $document = $element->ownerDocument;
        if ($document instanceof DOMDocument) {
            $references = $this->documentReferences($document);
            if (isset($references['urls'][$id])) return $references['urls'][$id];
            if (isset($references['tokens'][$id])) return $references['tokens'][$id];
        }
        $script = $this->session->boxTreeWrapperEvidence()->sourceScriptText();
        if ('' !== $script && str_contains($script, $id)) return 'script';
        return null;
    }

    /** @return list<string> What reads a `data-*` attribute, of `css`, `runtime_selector` and `script`; empty when nothing does. */
    private function dataAttributeReaders(string $name): array
    {
        $readers = array();
        $pattern = '/\[\s*' . preg_quote($name, '/') . '\s*[\]~|^$*=]/i';
        if (1 === preg_match($pattern, $this->session->authorStyleAnalysis()->combinedCss())) $readers[] = 'css';
        foreach ($this->runtimeSelectors() as $selector) {
            if (1 !== preg_match($pattern, $selector)) continue;
            $readers[] = 'runtime_selector';
            break;
        }
        $script = $this->session->boxTreeWrapperEvidence()->sourceScriptText();
        $key = (string) preg_replace_callback('/-([a-z])/', static fn (array $match): string => strtoupper($match[1]), substr($name, 5));
        if ('' !== $script && (false !== stripos($script, $name) || ('' !== $key && str_contains($script, $key)))) $readers[] = 'script';
        return $readers;
    }

    /** @return list<string> */
    private function runtimeSelectors(): array
    {
        $state = $this->session->runtimeSelectorState();
        return array_map('strval', array_merge(array_keys($state->domSelectors()), array_keys($state->canvasSelectors()), array_keys($state->behavioralSelectors())));
    }

    /**
     * Ids other attributes of the document reference: IDREF values, and
     * fragment (`#id`) or `url(#id)` references in any attribute.
     *
     * @return array{tokens: array<string, string>, urls: array<string, string>}
     */
    private function documentReferences(DOMDocument $document): array
    {
        $this->documentReferences ??= new \WeakMap();
        if (isset($this->documentReferences[$document])) return $this->documentReferences[$document];
        $tokens = array();
        $urls = array();
        foreach ($document->getElementsByTagName('*') as $node) {
            foreach ($node->attributes ?? array() as $attribute) {
                $name = strtolower($attribute->nodeName);
                $value = (string) $attribute->nodeValue;
                if ('id' === $name || '' === $value) continue;
                if (in_array($name, self::IDREF_ATTRIBUTES, true)) {
                    foreach (preg_split('/\s+/', trim($value)) ?: array() as $token) {
                        if ('' !== $token) $tokens[$token] ??= $name;
                    }
                }
                if (str_contains($value, '#') && preg_match_all('/#([^\s#"\'()<>,;]+)/u', $value, $matches)) {
                    foreach ($matches[1] as $fragment) $urls[rawurldecode($fragment)] ??= $name;
                }
            }
        }
        return $this->documentReferences[$document] = array('tokens' => $tokens, 'urls' => $urls);
    }

    private static function cssEscaped(string $id): string
    {
        $escaped = (string) preg_replace('/([^\w\-\x80-\xff])/u', '\\\\$1', $id);
        return 1 === preg_match('/^\d/', $escaped) ? '\\3' . $escaped[0] . ' ' . substr($escaped, 1) : $escaped;
    }
}
