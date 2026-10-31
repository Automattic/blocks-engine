<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatchCache;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use DOMDocument;
use DOMElement;

/** Per-transform source stylesheet matching and declaration state. */
final class SourceStyleResolutionState
{
    public readonly CssSelectorMatchCache $selectorMatchCache;

    /** @var array<string, array<string, mixed>> */
    public array $ruleCandidateIndexes = array();

    /** @var array<string, array<string, array<int, string>>> */
    public array $authorDeclaredPropertyValues = array();

    /** @var array<string, array<string, string>> */
    public array $structuralDeclarations = array();

    /** @var array<string, array<int, string>> */
    private array $classPromotions = array();

    /** @var array<int, array<string, mixed>> */
    private array $staticRules = array();

    /** @var array<int, array<string, mixed>> */
    private array $conditionalRules = array();

    /** @var array<int, array<string, mixed>> */
    private array $navigationStateRules = array();

    /** @var array<int, array<string, mixed>> */
    private array $revealStateRules = array();

    /** @var array<int, array<string, mixed>> */
    private array $imageShapeRules = array();

    /** @var array<int, array<string, mixed>> */
    private array $pseudoElementRules = array();

    /** @var array<int, array<string, mixed>> */
    private array $cascadedValueRules = array();

    /** @var array<string, string> */
    private array $customProperties = array();

    /** @var list<string> */
    private array $layerNames = array();

    /** @var array<string, array<string, mixed>|null> */
    private array $parsedSelectors = array();

    /** @var array<int, array<string, true>> */
    private array $ariaControlledTargetIds = array();

    /**
     * Each source element's interaction-state families, keyed by node path and
     * reused only for the same node; dropped with the selector match cache
     * that decided them.
     *
     * @var array<string, array{node: DOMElement, families: array<string, true>}>
     */
    private array $stateStyleFamilies = array();

    /**
     * Each source element's matched cascaded-value declarations, keyed by node
     * path. An entry is reused only for the same node with the same inline
     * style, so a node replaced at that path or restyled in place is
     * recomputed; the selector matches behind it are dropped with the
     * selector match cache.
     *
     * @var array<string, array{node: DOMElement, style: string, declarations: array<string, string>, custom: array<string, string>}>
     */
    private array $cascadedDeclarations = array();

    /**
     * Each source element's matching rules per collection, keyed by collection
     * and node path.
     *
     * @var array<string, array{node: DOMElement, rules: list<array<string, mixed>>}>
     */
    private array $matchedRules = array();

    private string $formLayoutCss = '';

    public function __construct()
    {
        $this->selectorMatchCache = new CssSelectorMatchCache();
    }

    public function invalidateSelectorMatches(): void
    {
        $this->selectorMatchCache->clear();
        $this->structuralDeclarations = array();
        $this->stateStyleFamilies = array();
        $this->cascadedDeclarations = array();
        $this->matchedRules = array();
        $this->ariaControlledTargetIds = array();
    }

    /**
     * @param array<string, array<int, string>> $classPromotions
     * @param array<string, mixed> $analysis
     */
    public function installStylesheetAnalysis(array $classPromotions, array $analysis): void
    {
        $this->classPromotions = $classPromotions;
        $this->staticRules = $analysis['static'];
        $this->conditionalRules = $analysis['conditional'];
        $this->navigationStateRules = $analysis['navigation_state'];
        $this->ruleCandidateIndexes = array();
        $this->stateStyleFamilies = array();
        $this->revealStateRules = $analysis['reveal_state'];
        $this->imageShapeRules = $analysis['image_shape'];
        $this->pseudoElementRules = $analysis['pseudo'];
        $this->cascadedValueRules = $analysis['cascaded_values'] ?? array();
        $this->cascadedDeclarations = array();
        $this->matchedRules = array();
        $this->customProperties = $analysis['custom_properties'];
        $this->layerNames = array_values(array_map('strval', $analysis['layer_names'] ?? array()));
    }

    /**
     * Retain only the rules whose selectors can match this source document.
     *
     * @param callable(array<string, mixed>): bool $isMatchable
     */
    public function retainMatchableRules(callable $isMatchable): void
    {
        // Rule keys carry source order, so filtering preserves them.
        $this->staticRules = array_filter($this->staticRules, $isMatchable);
        $this->conditionalRules = array_filter($this->conditionalRules, $isMatchable);
        $this->pseudoElementRules = array_filter($this->pseudoElementRules, $isMatchable);
        $this->ruleCandidateIndexes = array();
        $this->matchedRules = array();
    }

    /**
     * The element's remembered cascaded-value declarations and their custom
     * properties, computing and remembering them on a miss.
     *
     * @param callable(DOMElement): array<string, string> $compute
     * @return array{declarations: array<string, string>, custom: array<string, string>}
     */
    public function cascadedDeclarations(DOMElement $element, callable $compute): array
    {
        if ( ! self::isInDocument($element) ) {
            $declarations = $compute($element);
            return array( 'node' => $element, 'style' => $element->getAttribute('style'), 'declarations' => $declarations, 'custom' => self::customDeclarations($declarations) );
        }
        $path = (string) $element->getNodePath();
        $style = $element->getAttribute('style');
        $entry = $this->cascadedDeclarations[$path] ?? null;
        if ( null === $entry || $entry['style'] !== $style || ! $entry['node']->isSameNode($element) ) {
            $declarations = $compute($element);
            $entry = $this->cascadedDeclarations[$path] = array( 'node' => $element, 'style' => $style, 'declarations' => $declarations, 'custom' => self::customDeclarations($declarations) );
        }
        return $entry;
    }

    /**
     * The element's remembered rules of one collection that match it,
     * computing and remembering them on a miss. Entries are reused only for
     * the same node and dropped with the selector match cache that decided
     * them.
     *
     * @param callable(DOMElement, string): list<array<string, mixed>> $compute
     * @return list<array<string, mixed>>
     */
    public function matchedRules(DOMElement $element, string $collection, callable $compute): array
    {
        if ( ! self::isInDocument($element) ) {
            return $compute($element, $collection);
        }
        $key = $collection . "\0" . $element->getNodePath();
        $entry = $this->matchedRules[$key] ?? null;
        if ( null === $entry || ! $entry['node']->isSameNode($element) ) {
            $entry = $this->matchedRules[$key] = array( 'node' => $element, 'rules' => $compute($element, $collection) );
        }
        return $entry['rules'];
    }

    /**
     * Whether the element is attached to its document. Per-element memos
     * remember only attached elements: a detached clone is transient, and
     * PHP 8.2 frees its subtree with the clone even while a remembered
     * wrapper still refers to it.
     */
    private static function isInDocument(DOMElement $element): bool
    {
        $node = $element;
        while ( null !== $node->parentNode ) {
            $node = $node->parentNode;
        }
        return $node instanceof \DOMDocument;
    }

    /**
     * @param array<string, string> $declarations
     * @return array<string, string>
     */
    private static function customDeclarations(array $declarations): array
    {
        $custom = array();
        foreach ( $declarations as $name => $value ) {
            if ( str_starts_with((string) $name, '--') ) {
                $custom[$name] = $value;
            }
        }
        return $custom;
    }

    /**
     * @param callable(DOMElement): array<string, true> $compute
     * @return array<string, true>
     */
    public function stateStyleFamilies(DOMElement $element, callable $compute): array
    {
        if ( ! self::isInDocument($element) ) {
            return $compute($element);
        }
        $path = (string) $element->getNodePath();
        $entry = $this->stateStyleFamilies[$path] ?? null;
        if ( null === $entry || ! $entry['node']->isSameNode($element) ) {
            $entry = $this->stateStyleFamilies[$path] = array( 'node' => $element, 'families' => $compute($element) );
        }
        return $entry['families'];
    }

    /** @return array<int, string> */
    public function classPromotions(string $className): array
    {
        return $this->classPromotions[$className] ?? array();
    }

    public function hasClassPromotions(): bool
    {
        return array() !== $this->classPromotions;
    }

    /** @return array<int, array<string, mixed>> */
    public function staticRules(): array
    {
        return $this->staticRules;
    }

    /** @return array<int, array<string, mixed>> */
    public function conditionalRules(): array
    {
        return $this->conditionalRules;
    }

    /** @return array<int, array<string, mixed>> */
    public function navigationStateRules(): array
    {
        return $this->navigationStateRules;
    }

    /** @return array<int, array<string, mixed>> */
    public function revealStateRules(): array
    {
        return $this->revealStateRules;
    }

    public function isAriaControlledTarget(DOMElement $element): bool
    {
        $id = trim($element->getAttribute('id'));
        $document = $element->ownerDocument;
        if ('' === $id || ! $document instanceof DOMDocument) {
            return false;
        }

        $key = spl_object_id($document);
        if (! isset($this->ariaControlledTargetIds[$key])) {
            $controlled = array();
            foreach ($document->getElementsByTagName('*') as $control) {
                if (! $control instanceof DOMElement || ! $control->hasAttribute('aria-expanded')) {
                    continue;
                }
                foreach (preg_split('/\s+/', trim($control->getAttribute('aria-controls'))) ?: array() as $controlledId) {
                    if ('' !== $controlledId) {
                        $controlled[$controlledId] = true;
                    }
                }
            }
            $this->ariaControlledTargetIds[$key] = $controlled;
        }

        return isset($this->ariaControlledTargetIds[$key][$id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function imageShapeRules(): array
    {
        return $this->imageShapeRules;
    }

    /** @return array<int, array<string, mixed>> */
    public function pseudoElementRules(): array
    {
        return $this->pseudoElementRules;
    }

    /** @return array<int, array<string, mixed>> */
    public function cascadedValueRules(): array
    {
        return $this->cascadedValueRules;
    }

    /** @return array<string, string> */
    public function customProperties(): array
    {
        return $this->customProperties;
    }

    /** Whether any source rule is scoped to a cascade `@layer`. */
    public function hasLayeredRules(): bool
    {
        return array() !== $this->layerNames;
    }

    /** @return array<string, mixed>|null */
    public function parsedSelector(string $selector): ?array
    {
        return $this->parsedSelectors[$selector] ??= CssSelectorMatcher::parse($selector);
    }

    public function setFormLayoutCss(string $css): void
    {
        $this->formLayoutCss = $css;
    }

    public function formLayoutCss(): string
    {
        return $this->formLayoutCss;
    }
}
