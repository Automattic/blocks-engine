<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

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
    /** @var list<string> */
    private array $sourceBodyProjectionClasses = array();

    /** @param list<array{path: string, source_path: string, content: string, source_hash: string, media: string}> $stylesheetAssets */
    public function __construct(string $html, string $combinedCss, array $stylesheetAssets, DOMElement $sourceBody)
    {
        $this->combinedCss = $combinedCss;
        $this->stylesheetAssets = $stylesheetAssets;
        $this->sourceBody = $sourceBody;
        $this->selectorMatchCache = new CssSelectorMatchCache();
        // Ignore generated-looking markers while hashing each input separately,
        // preserving deterministic identities without duplicating large CSS.
        $markerPattern = '/blocks-engine-(?:source-[a-z][a-z0-9-]*|control|table|specificity(?:-(?:class|id))?)-[a-f0-9]+-\d+/';
        $normalizedHtml = preg_match($markerPattern, $html) ? (preg_replace($markerPattern, '', $html) ?? '') : $html;
        $normalizedCss = preg_match($markerPattern, $combinedCss) ? (preg_replace($markerPattern, '', $combinedCss) ?? '') : $combinedCss;
        $seed = hash_init('sha256');
        hash_update($seed, $normalizedHtml);
        hash_update($seed, "\0");
        hash_update($seed, $normalizedCss);
        $this->markerSeed = substr(hash_final($seed), 0, 12);
        $this->markerCollisionTexts = array($html, $combinedCss);
        $this->specificityShim = $this->allocateMarker('specificity');
        $this->classSpecificityShim = $this->allocateMarker('specificity-class');
        $this->idSpecificityShim = $this->allocateMarker('specificity-id');

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
    public function specificityShim(): string { return $this->specificityShim; }
    public function classSpecificityShim(): string { return $this->classSpecificityShim; }
    public function idSpecificityShim(): string { return $this->idSpecificityShim; }
    /** @return list<string> */
    public function sourceBodyProjectionClasses(): array { return $this->sourceBodyProjectionClasses; }
    /** @param list<string> $classes */
    public function setSourceBodyProjectionClasses(array $classes): void { $this->sourceBodyProjectionClasses = $classes; }
    /** @param list<array<string, mixed>> $rules */
    public function installStyleRules(array $rules): void
    {
        $this->styleRules = $rules;
        $this->styleRuleCandidateIndex = null;
    }

    public function allocateMarker(string $kind): string
    {
        do {
            $marker = 'blocks-engine-' . $kind . '-' . $this->markerSeed . '-' . $this->markerCounter++;
        } while ( str_contains($this->markerCollisionTexts[0], $marker) || str_contains($this->markerCollisionTexts[1], $marker) );
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
            $candidates[] = $this->sourceElementsById[$id] ?? array();
        }
        foreach ( $rightmost['classes'] ?? array() as $class ) {
            $candidates[] = $this->sourceElementsByClass[$class] ?? array();
        }
        if ( is_string($rightmost['type'] ?? null) && '' !== $rightmost['type'] ) {
            $candidates[] = $this->sourceElementsByTag[strtolower($rightmost['type'])] ?? array();
        }
        if ( array() === $candidates ) {
            return $this->sourceElements;
        }
        usort($candidates, static fn (array $left, array $right): int => count($left) <=> count($right));
        return $candidates[0];
    }

    /** @param array<string, mixed> $parsed */
    public function selectorCanMatch(array $parsed): bool
    {
        foreach ( $parsed['compounds'] ?? array() as $compound ) {
            if ( is_string($compound['type'] ?? null) && '' !== $compound['type'] && ! isset($this->sourceTags[strtolower($compound['type'])]) ) {
                return false;
            }
            foreach ( $compound['ids'] ?? array() as $id ) {
                if ( ! isset($this->sourceIds[$id]) ) {
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
