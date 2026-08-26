<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/** Per-transform CSS generated to bridge source styling into valid blocks. */
final class GeneratedSupportStylesheetState
{
    /** @var array<string, string> */
    private array $nativeSearchTriggerRules = array();

    /** @var array<string, string> */
    private array $nativeButtonRules = array();

    /** @var array<string, string> */
    private array $syntheticHeaderAnchorRules = array();

    /** @var array<string, string> */
    private array $headerRichTextRules = array();

    /** @var array<string, array<string, mixed>> */
    private array $listNavigationPadding = array();

    /** @var array<string, string> */
    private array $navigationLinkColors = array();

    /** @var array<string, string> */
    private array $navigationSubmenuBackgrounds = array();

    /** @var array<string, string> */
    private array $navigationSpacing = array();

    /** @var array<string, string> */
    private array $buttonWrapperSpacing = array();

    /** @var array<string, string> */
    private array $directFlexButtonRules = array();

    /** @var array<string, string> */
    private array $fullWidthButtonRules = array();

    public function registerNativeSearchTrigger(string $className, string $rule): void
    {
        $this->nativeSearchTriggerRules[$className] = $rule;
    }

    public function hasNativeSearchTrigger(string $className): bool
    {
        return isset($this->nativeSearchTriggerRules[$className]);
    }

    public function registerNativeButton(string $marker, string $rule): void
    {
        $this->nativeButtonRules[$marker] = $rule;
    }

    public function registerSyntheticHeaderAnchor(string $className, string $rule): void
    {
        $this->syntheticHeaderAnchorRules[$className] = $rule;
    }

    public function registerHeaderRichText(string $marker, string $rule): void
    {
        $this->headerRichTextRules[$marker] = $rule;
    }

    /** @param array<string, mixed> $padding */
    public function registerListNavigationPadding(string $className, array $padding): void
    {
        $this->listNavigationPadding[$className] = $padding;
    }

    /** @return array<string, mixed> */
    public function listNavigationPadding(string $className): array
    {
        return $this->listNavigationPadding[$className] ?? array();
    }

    public function registerNavigationLinkColor(string $className, string $color): void
    {
        $this->navigationLinkColors[$className] = $color;
    }

    public function navigationLinkColor(string $className): string
    {
        return $this->navigationLinkColors[$className] ?? '';
    }

    public function registerNavigationSubmenuBackground(string $className, string $color): void
    {
        $this->navigationSubmenuBackgrounds[$className] = $color;
    }

    public function registerNavigationSpacing(string $className, string $declarations): void
    {
        $this->navigationSpacing[$className] = $declarations;
    }

    public function registerButtonWrapperSpacing(string $className, string $declarations): void
    {
        $this->buttonWrapperSpacing[$className] = $declarations;
    }

    public function registerDirectFlexButton(string $marker, string $rule): void
    {
        $this->directFlexButtonRules[$marker] = $rule;
    }

    public function registerFullWidthButton(string $marker, string $rule): void
    {
        $this->fullWidthButtonRules[$marker] = $rule;
    }

    public function beforeAuthorCss(): string
    {
        return implode("\n", $this->nativeSearchTriggerRules);
    }

    /** @return list<string> */
    public function conditionalAfterAuthorCss(string $serializedBlocks): array
    {
        $parts = array();
        foreach ($this->navigationSubmenuBackgrounds as $className => $color) {
            if (str_contains($serializedBlocks, $className)) {
                $parts[] = '.wp-block-navigation-item.' . $className . '>.wp-block-navigation__submenu-container{background-color:' . $color . '}';
            }
        }
        foreach ($this->navigationSpacing as $className => $declarations) {
            if (str_contains($serializedBlocks, $className)) {
                $parts[] = '.wp-block-navigation.' . $className . '{' . $declarations . '}';
            }
        }
        foreach ($this->buttonWrapperSpacing as $className => $declarations) {
            if (str_contains($serializedBlocks, $className)) {
                $parts[] = '.wp-block-buttons.' . $className . '{' . $declarations . '}';
            }
        }
        foreach ($this->syntheticHeaderAnchorRules as $className => $rule) {
            if (str_contains($serializedBlocks, $className)) {
                $parts[] = $rule;
            }
        }
        foreach ($this->headerRichTextRules as $marker => $rule) {
            if (str_contains($serializedBlocks, $marker)) {
                $parts[] = $rule;
            }
        }
        return $parts;
    }

    /** @return list<string> */
    public function buttonAfterAuthorCss(): array
    {
        return array_values(array_filter(array(
            implode("\n", $this->nativeButtonRules),
            implode("\n", $this->directFlexButtonRules),
            implode("\n", $this->fullWidthButtonRules),
        ), static fn (string $rules): bool => '' !== $rules));
    }
}
