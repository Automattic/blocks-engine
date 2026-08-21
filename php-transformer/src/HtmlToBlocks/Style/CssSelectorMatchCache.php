<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use DOMElement;

/** Caches immutable-DOM selector inputs for one author-selector discovery pass. */
final class CssSelectorMatchCache
{
    /** @var array<string, list<string>> */
    private array $classTokens = array();

    /** @var array<string, array<string, string|null>> */
    private array $attributes = array();

    public int $classTokenBuilds = 0;

    public int $classTokenHits = 0;

    public int $attributeReads = 0;

    /** @return list<string> */
    public function classTokens(DOMElement $element): array
    {
        $key = $this->elementKey($element);
        if ( array_key_exists($key, $this->classTokens) ) {
            ++$this->classTokenHits;
            return $this->classTokens[$key];
        }

        ++$this->classTokenBuilds;
        return $this->classTokens[$key] = preg_split('/[\x09\x0A\x0C\x0D\x20]+/', trim($this->attribute($element, 'class') ?? '')) ?: array();
    }

    public function attribute(DOMElement $element, string $name): ?string
    {
        $key = $this->elementKey($element);
        if ( array_key_exists($name, $this->attributes[$key] ?? array()) ) {
            return $this->attributes[$key][$name];
        }

        ++$this->attributeReads;
        return $this->attributes[$key][$name] = $element->hasAttribute($name) ? $element->getAttribute($name) : null;
    }

    private function elementKey(DOMElement $element): string
    {
        return (string) spl_object_id($element);
    }
}
