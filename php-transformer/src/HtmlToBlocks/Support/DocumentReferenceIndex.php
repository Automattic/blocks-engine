<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use DOMDocument;
use DOMElement;
use WeakMap;

/** Reverse references in the stable source DOM, scoped to child conversion. */
final class DocumentReferenceIndex
{
    /** @var WeakMap<DOMDocument, self>|null */
    private static ?WeakMap $documents = null;
    /** @var array<string, list<DOMElement>> */
    private array $fragments = array();
    /** @var array<string, list<DOMElement>> */
    private array $ids = array();
    /** @var array<string, array<string, true>> */
    private array $dialogControls = array();

    /** @var array<string, true> */
    private array $cssFragments = array();

    public function referencesCssFragment(string $id): bool
    {
        return isset($this->cssFragments[$id]);
    }

    public static function install(DOMDocument $document, string $sourceCss = ''): void
    {
        $index = new self();
        if (preg_match_all('/\burl\s*\(\s*["\']?#([^\s"\')]+)["\']?\s*\)/i', $sourceCss, $matches)) {
            foreach ($matches[1] as $id) $index->cssFragments[rawurldecode($id)] = true;
        }
        foreach ($document->getElementsByTagName('*') as $candidate) {
            $identity = trim(SourceDom::attr($candidate, 'id'));
            if ('' !== $identity) $index->ids[$identity][] = $candidate;
            $ids = array();
            $href = SourceDom::attr($candidate, 'href');
            $hash = strpos($href, '#');
            if (false !== $hash) $ids[] = SourceDom::anchorAttributeValue(rawurldecode(substr($href, $hash + 1)));
            foreach (array('aria-labelledby', 'aria-describedby', 'aria-controls') as $attribute) {
                foreach (SourceDom::tokens(SourceDom::attr($candidate, $attribute)) as $id) $ids[] = $id;
            }
            $ids[] = SourceDom::attr($candidate, 'for');
            foreach (array_unique($ids) as $id) {
                if ('' !== $id) $index->fragments[$id][] = $candidate;
            }
            if ('true' === SourceDom::attr($candidate, 'data-blocks-engine-captured-dialog')) {
                foreach (array('data-blocks-engine-triggers', 'data-blocks-engine-closers') as $binding) {
                    foreach (SourceDom::tokens(SourceDom::attr($candidate, $binding)) as $id) {
                        if ('' !== $id) $index->dialogControls[$binding][$id] = true;
                    }
                }
            }
        }
        self::$documents ??= new WeakMap();
        self::$documents[$document] = $index;
    }

    public static function release(DOMDocument $document): void
    {
        if (null !== self::$documents) unset(self::$documents[$document]);
    }

    public static function forDocument(?DOMDocument $document): ?self
    {
        return null === $document ? null : (self::$documents[$document] ?? null);
    }

    /** @return list<DOMElement> */
    public function elementsWithId(string $id): array
    {
        return $this->ids[$id] ?? array();
    }

    public function referencesFragment(DOMElement $element, string $id, bool $ignoreCopiedSymbolUses = false): bool
    {
        foreach ($this->fragments[$id] ?? array() as $candidate) {
            if (!$candidate->isSameNode($element) && !($ignoreCopiedSymbolUses && SourceDom::isCopiedSymbolUse($candidate, $element))) return true;
        }
        return false;
    }

    public function bindsDialogControl(string $binding, string $id): bool
    {
        return isset($this->dialogControls[$binding][$id]);
    }
}
