<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

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
    private array $imageShapeRules = array();

    /** @var array<int, array<string, mixed>> */
    private array $pseudoElementRules = array();

    /** @var array<int, array<string, mixed>> */
    private array $cascadedValueRules = array();

    /** @var array<string, string> */
    private array $customProperties = array();

    /** @var array<string, array<string, mixed>|null> */
    private array $parsedSelectors = array();

    private string $formLayoutCss = '';

    public function __construct()
    {
        $this->selectorMatchCache = new CssSelectorMatchCache();
    }

    public function invalidateSelectorMatches(): void
    {
        $this->selectorMatchCache->clear();
        $this->structuralDeclarations = array();
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
        $this->imageShapeRules = $analysis['image_shape'];
        $this->pseudoElementRules = $analysis['pseudo'];
        $this->cascadedValueRules = $analysis['cascaded_values'] ?? array();
        $this->customProperties = $analysis['custom_properties'];
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
