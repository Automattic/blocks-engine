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
    private array $nativeNavigationToggleRules = array();

    /** @var array<string, string> */
    private array $nativeNavigationOverlayRules = array();

    /** @var array<string, string> */
    private array $disclosureSummaryPresentation = array();

    /** @var array<string, array<string, string>> */
    private array $disclosureControlConditionalDisplay = array();

    /** @var array<string, string> */
    private array $accordionTogglePresentation = array();

    /** @var array<string, string> */
    private array $accordionTitlePresentation = array();
    /** @var array<string, array<string, string>> */
    private array $accordionIconPresentation = array();

    /** @var array<string, array<string, string>> */
    private array $disclosureControlConditionalPresentation = array();

    /** @var array<string, string> */
    private array $syntheticHeaderAnchorRules = array();

    /** @var array<string, string> */
    private array $headerRichTextRules = array();

    /** @var array<string, array<string, mixed>> */
    private array $listNavigationPadding = array();

    /** @var array<string, string> */
    private array $navigationLinkColors = array();

    /** @var array<string, array{content: string, item_reset: string}> */
    private array $navigationLinkBoxes = array();

    /** @var array<string, string> */
    private array $navigationAnchorLineHeights = array();

    /** @var array<string, string> */
    private array $navigationLinkIcons = array();

    /** @var array<string, string> */
    private array $navigationLinkLeadingIcons = array();

    /** @var array<string, string> */
    private array $navigationSubmenuBackgrounds = array();

    /** @var array<string, string> */
    private array $navigationSpacing = array();

    /** @var array<string, string> */
    private array $buttonWrapperSpacing = array();

    /** @var array<string, string> */
    private array $directFlexButtonRules = array();

    /** @var array<string, string> */
    private array $buttonWidthRules = array();

    /** @var array<string, array{base: string, conditional: array<string, string>}> */
    private array $responsiveTypographyRules = array();

    /** @var array<string, array{marker: string, selector: string, conditions: list<string>, declarations: array<string, string>}> */
    private array $sourceCustomPropertyRules = array();

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

    public function appendNativeButton(string $marker, string $rule): void
    {
        $this->nativeButtonRules[$marker] = ($this->nativeButtonRules[$marker] ?? '') . $rule;
    }

    public function registerNativeNavigationToggle(string $marker, string $rule): void
    {
        $this->nativeNavigationToggleRules[$marker] = $rule;
    }

    public function registerNativeNavigationOverlay(string $marker, string $rule): void
    {
        $this->nativeNavigationOverlayRules[$marker] = $rule;
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

    /**
     * The source anchor's box restated on the anchor core/navigation-link
     * renders, plus the carried padding sides reset on the item so the box is
     * not painted twice.
     */
    public function registerNavigationLinkBox(string $className, string $content, string $itemReset = ''): void
    {
        $this->navigationLinkBoxes[$className] = array(
            'content' => $content,
            'item_reset' => $itemReset,
        );
    }

    /** @return array{content: string, item_reset: string} */
    public function navigationLinkBox(string $className): array
    {
        return $this->navigationLinkBoxes[$className] ?? array(
            'content' => '',
            'item_reset' => '',
        );
    }

    public function registerNavigationAnchorLineHeight(string $className, string $value): void
    {
        $this->navigationAnchorLineHeights[$className] = $value;
    }

    public function navigationAnchorLineHeight(string $className): string
    {
        return $this->navigationAnchorLineHeights[$className] ?? '';
    }

    public function registerDisclosureSummaryPresentation(string $className, string $declarations): void
    {
        $this->disclosureSummaryPresentation[$className] = $declarations;
    }

    /** @param array<string, string> $rules */
    public function registerDisclosureControlConditionalDisplay(string $className, array $rules): void
    {
        $this->disclosureControlConditionalDisplay[$className] = $rules;
    }

    public function registerAccordionTogglePresentation(string $className, string $declarations): void
    {
        $this->accordionTogglePresentation[$className] = $declarations;
    }

    public function registerAccordionTitlePresentation(string $className, string $declarations): void
    {
        $this->accordionTitlePresentation[$className] = $declarations;
    }

    /** @param array<string, string> $states */
    public function registerAccordionIconPresentation(string $className, array $states): void
    {
        $this->accordionIconPresentation[$className] = $states;
    }

    /** @param array<string, string> $rules */
    public function registerDisclosureControlConditionalPresentation(string $className, array $rules): void
    {
        $this->disclosureControlConditionalPresentation[$className] = $rules;
    }

    public function registerNavigationLinkIcon(string $className, string $declarations): void
    {
        $this->navigationLinkIcons[$className] = $declarations;
    }

    public function navigationLinkIcon(string $className): string
    {
        return $this->navigationLinkIcons[$className] ?? '';
    }

    /**
     * A recovered icon for a navigation anchor that ALSO shows a text label —
     * projected as a leading `::before` mark beside the kept label, unlike
     * {@see self::registerNavigationLinkIcon()}'s icon-only replacement.
     */
    public function registerNavigationLinkLeadingIcon(string $className, string $declarations): void
    {
        $this->navigationLinkLeadingIcons[$className] = $declarations;
    }

    public function navigationLinkLeadingIcon(string $className): string
    {
        return $this->navigationLinkLeadingIcons[$className] ?? '';
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

    public function registerButtonWidth(string $marker, string $rule): void
    {
        $this->buttonWidthRules[$marker] = $rule;
    }

    /** @param array<string, string> $conditional */
    public function registerResponsiveTypography(string $className, string $base, array $conditional): void
    {
        $this->responsiveTypographyRules[$className] = array(
            'base' => $base,
            'conditional' => $conditional,
        );
    }

    /** @param list<string> $conditions @param array<string, string> $declarations */
    public function registerSourceCustomPropertyScope(string $marker, string $selector, array $conditions, array $declarations): void
    {
        if (array() === $declarations) {
            return;
        }
        ksort($declarations, SORT_STRING);
        $key = hash('sha256', $marker . "\n" . $selector . "\n" . serialize($conditions) . "\n" . serialize($declarations));
        $this->sourceCustomPropertyRules[$key] = array(
            'marker' => $marker,
            'selector' => $selector,
            'conditions' => array_values($conditions),
            'declarations' => $declarations,
        );
    }

    public function beforeAuthorCss(): string
    {
        return implode("\n", $this->nativeSearchTriggerRules);
    }

    /**
     * Captured per-component styling restated onto the descendant element
     * core generates to hold it — tagged {@see CascadeLayer::COMPONENT_STYLE_RESTATEMENT}.
     *
     * @return list<CascadeRule>
     */
    public function conditionalAfterAuthorCss(string $serializedBlocks): array
    {
        $parts = array();
        foreach ($this->navigationSubmenuBackgrounds as $className => $color) {
            if (str_contains($serializedBlocks, $className)) {
                $parts[] = '.wp-block-navigation-item.' . $className . '>.wp-block-navigation__submenu-container{background-color:' . $color . '}';
            }
        }
        foreach ($this->disclosureSummaryPresentation as $className => $declarations) {
            if (str_contains($serializedBlocks, $className)) {
                // core/details owns the summary element, so the source toggle's box is
                // restated on it from here rather than carried as markup.
                $parts[] = '.wp-block-details.' . $className . '>summary{' . $declarations . '}';
            }
        }
        foreach ($this->disclosureControlConditionalDisplay as $className => $rules) {
            if (!str_contains($serializedBlocks, $className)) continue;
            // A responsive utility states the control's visibility per viewport.
            // The conditions travel with the values so a control the source only
            // shows on small screens stays hidden on large ones.
            // The condition hid the whole control in the source, so it applies
            // to the block that now holds the control's slot. Hiding only the
            // inner trigger would leave a zero-sized block still taking part in
            // its parent's layout.
            $selector = str_starts_with($className, 'blocks-engine-accordion-toggle-')
                ? '.wp-block-accordion-heading.' . $className
                : '.wp-block-details.' . $className;
            foreach ($rules as $condition => $display) {
                $parts[] = $condition . '{' . $selector . '{display:' . $display . '}' . str_repeat('}', substr_count($condition, '{') + 1);
            }
        }
        foreach ($this->accordionTogglePresentation as $className => $declarations) {
            if (str_contains($serializedBlocks, $className)) {
                // core/accordion-heading saves its own bare toggle button, so the
                // source trigger's box is restated on it from here rather than
                // carried as markup.
                $parts[] = '.wp-block-accordion-heading.' . $className . '>.wp-block-accordion-heading__toggle{' . $declarations . '}';
            }
        }
        foreach ($this->disclosureControlConditionalPresentation as $className => $rules) {
            if (!str_contains($serializedBlocks, $className)) continue;
            $selector = str_starts_with($className, 'blocks-engine-accordion-toggle-')
                ? '.wp-block-accordion-heading.' . $className . '>.wp-block-accordion-heading__toggle'
                : '.wp-block-details.' . $className . '>summary';
            foreach ($rules as $condition => $declarations) {
                $parts[] = $condition . '{' . $selector . '{' . $declarations . '}' . str_repeat('}', substr_count($condition, '{') + 1);
            }
        }
        foreach ($this->accordionTitlePresentation as $className => $declarations) {
            if (str_contains($serializedBlocks, $className)) {
                $parts[] = '.wp-block-accordion-heading.' . $className . '>.wp-block-accordion-heading__toggle>.wp-block-accordion-heading__toggle-title{' . $declarations . '}';
            }
        }
        foreach ($this->accordionIconPresentation as $className => $states) {
            if (!str_contains($serializedBlocks, $className)) continue;
            $toggle = '.wp-block-accordion-heading.' . $className . '>.wp-block-accordion-heading__toggle';
            $parts[] = $toggle . '>.wp-block-accordion-heading__toggle-icon{' . $states['closed'] . '}';
            $parts[] = $toggle . '[aria-expanded="true"]>.wp-block-accordion-heading__toggle-icon{' . $states['open'] . '}';
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
        foreach ($this->responsiveTypographyRules as $className => $rules) {
            if (!str_contains($serializedBlocks, $className)) {
                continue;
            }
            if ('' !== $rules['base']) {
                $parts[] = ':root .' . $className . '{font-size:' . $rules['base'] . '}';
            }
            foreach ($rules['conditional'] as $condition => $value) {
                $parts[] = $condition . '{:root .' . $className . '{font-size:' . $value . '}'
                    . str_repeat('}', substr_count($condition, '{') + 1);
            }
        }
        $sourceCustomPropertyScopes = array_values($this->sourceCustomPropertyRules);
        usort($sourceCustomPropertyScopes, static function (array $left, array $right): int {
            $hasViewportCondition = static fn (array $scope): int => (int) (bool) array_filter(
                $scope['conditions'],
                static fn (string $condition): bool => 1 === preg_match('/^@(media|container)\b/i', $condition)
            );
            return $hasViewportCondition($left) <=> $hasViewportCondition($right);
        });
        foreach ($sourceCustomPropertyScopes as $scope) {
            if (!str_contains($serializedBlocks, $scope['marker'])) {
                continue;
            }
            $declarations = array();
            foreach ($scope['declarations'] as $property => $value) {
                $declarations[] = $property . ':' . $value;
            }
            $css = $scope['selector'] . '{' . implode(';', $declarations) . '}';
            foreach (array_reverse($scope['conditions']) as $condition) {
                $css = $condition . '{' . $css . str_repeat('}', substr_count($condition, '{') + 1);
            }
            $parts[] = $css;
        }
        foreach ($this->nativeNavigationToggleRules as $marker => $rule) {
            if (str_contains($serializedBlocks, $marker)) {
                $parts[] = $rule;
            }
        }
        foreach ($this->nativeNavigationOverlayRules as $marker => $rule) {
            if (str_contains($serializedBlocks, $marker)) {
                $parts[] = $rule;
            }
        }

        return array_map(
            static fn (string $css): CascadeRule => new CascadeRule(CascadeLayer::COMPONENT_STYLE_RESTATEMENT, $css),
            $parts
        );
    }

    /**
     * Repairs specific to native/direct-flex/width-carrying button markup —
     * tagged {@see CascadeLayer::BUTTON_REPAIR}.
     *
     * @return list<CascadeRule>
     */
    public function buttonAfterAuthorCss(): array
    {
        $parts = array_values(array_filter(array(
            implode("\n", $this->nativeButtonRules),
            implode("\n", $this->directFlexButtonRules),
            implode("\n", $this->buttonWidthRules),
        ), static fn (string $rules): bool => '' !== $rules));

        return array_map(
            static fn (string $css): CascadeRule => new CascadeRule(CascadeLayer::BUTTON_REPAIR, $css),
            $parts
        );
    }
}
