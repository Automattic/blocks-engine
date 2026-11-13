<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;

/** Per-transform CSS generated to bridge source styling into valid blocks. */
final class GeneratedSupportStylesheetState
{
    /** @var array<string, string> */
    private array $globalZeroBoxRules = array();

    public function registerGlobalZeroBox(string $marker, string $declarations): void
    {
        // Beat core block margins and layout block-gap selectors, but let
        // nonzero inline instance values continue to override the shared reset.
        $this->globalZeroBoxRules[$marker] = 'body .' . $marker . '.' . $marker . '{' . $declarations . '}';
    }

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

    /** @var array<string, bool> */
    private array $disclosureSummaryMarkerSuppression = array();

    /** @var array<string, string> */
    private array $disclosureSummaryContentCarrierPresentation = array();

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
    private array $videoSizeHintRules = array();

    /** @var array<string, string> */
    private array $headerRichTextRules = array();

    /** @var array<string, string> */
    private array $richTextPresentationalHintRules = array();

    /** @var array<string, array<string, mixed>> */
    private array $listNavigationPadding = array();

    /** @var array<string, string> */
    private array $navigationLinkColors = array();

    /** @var array<string, array{content: string, item_reset: string}> */
    private array $navigationLinkBoxes = array();

    /** @var array<string, string> */
    private array $navigationAnchorLineHeights = array();

    /** @var array<string, array{marker: string, declarations: string, conditions: list<string>}> Editor-only inheritance across omitted label boxes. */
    private array $navigationEditorTypography = array();

    /** @param list<string> $conditions */
    public function registerNavigationEditorTypography(string $marker, string $declarations, array $conditions = array()): void
    {
        $this->navigationEditorTypography[$marker . serialize($conditions)] = compact('marker', 'declarations', 'conditions');
    }

    /** @var array<string, array{marker: string, declarations: string}> Editor-only resting state of navigation items. */
    private array $navigationEditorItemStyles = array();

    /**
     * A source item's inline style reaches Core's LI only through a frontend
     * render filter. The editor renders the item itself, so it needs the same
     * resting declarations, at inline precedence, from its static-state sheet.
     * Every item is recorded, styled or not: its editable label carries the
     * editor's own inline wrapping, which the frontend label never has.
     */
    public function registerNavigationEditorItemStyle(string $marker, string $declarations): void
    {
        if ('' === $marker) return;
        $this->navigationEditorItemStyles[$marker] = compact('marker', 'declarations');
    }

    /** @return list<string> */
    public function navigationEditorItemStyleRules(): array
    {
        $rules = array();
        foreach ($this->navigationEditorItemStyles as $entry) {
            if ('' !== $entry['declarations']) {
                $rules[] = ':root .wp-block-navigation .wp-block-navigation-item.' . $entry['marker'] . '{' . $entry['declarations'] . '}';
            }
        }
        if (array() !== $this->navigationEditorItemStyles) {
            // RichText writes white-space:pre-wrap inline on the editable label.
            // Its whitespace preservation is what editing needs, but its line
            // wrapping lets a nowrap item break, which shrinks the menu's
            // min-content and lets its flex parent squeeze it. The frontend
            // label inherits wrapping from the item, so the editor does too.
            $markers = implode(',', array_map(static fn (array $entry): string => '.' . $entry['marker'], $this->navigationEditorItemStyles));
            $rules[] = ':root .wp-block-navigation .wp-block-navigation-item:is(' . $markers . ') .wp-block-navigation-item__label[contenteditable]{text-wrap-mode:inherit!important}';
        }
        return $rules;
    }

    /** @return list<string> */
    public function navigationEditorTypographyRules(): array
    {
        $rules = array();
        foreach ($this->navigationEditorTypography as $entry) {
            $rule = '.wp-block-navigation .wp-block-navigation-item.' . $entry['marker']
                . ' .wp-block-navigation-item__content{' . $entry['declarations'] . '}';
            foreach (array_reverse($entry['conditions']) as $condition) $rule = $condition . '{' . $rule . '}';
            $rules[] = $rule;
        }
        return $rules;
    }

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

    /** @var array<string, array{base: string, conditional: array<string, string>}> */
    private array $responsiveBlockMarginTopRules = array();

    /** @var array<string, array{marker: string, selector: string, conditions: list<string>, declarations: array<string, string>}> */
    private array $sourceCustomPropertyRules = array();

    /**
     * Commit only the generated support records owned by blocks accepted from
     * an isolated fragment compilation. Records remain structured through this
     * boundary; conditions, state variants, declarations, and family-specific
     * data are interpreted only by their normal stylesheet stage.
     *
     * @param list<array<string, mixed>> $acceptedBlocks
     */
    public function commitAcceptedFrom(self $candidate, array $acceptedBlocks): void
    {
        $identities = array();
        $collect = static function (array $blocks) use (&$collect, &$identities): void {
            foreach ( $blocks as $block ) {
                if ( ! is_array($block) ) continue;
                $className = trim((string) ($block['attrs']['className'] ?? ''));
                foreach ( preg_split('/\s+/', $className) ?: array() as $identity ) {
                    if ( '' !== $identity ) $identities[$identity] = true;
                }
                $collect(ArrayField::array($block, 'innerBlocks'));
            }
        };
        $collect($acceptedBlocks);
        if ( array() === $identities ) return;

        // All instance arrays are structured support-family maps. Walking the
        // state itself means a new family participates in fragment ownership
        // automatically instead of requiring a second family registry.
        foreach ( get_object_vars($candidate) as $family => $records ) {
            if ( ! is_array($records) || ! is_array($this->{$family} ?? null) ) continue;
            foreach ( $records as $key => $record ) {
                $identity = is_string($key) && isset($identities[$key])
                    ? $key
                    : (is_array($record) && is_string($record['marker'] ?? null) ? $record['marker'] : '');
                if ( '' !== $identity && isset($identities[$identity]) ) {
                    $this->{$family}[$key] = $record;
                }
            }
        }
    }

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

    public function registerVideoSizeHint(string $className, string $rule): void
    {
        $this->videoSizeHintRules[$className] = $rule;
    }

    public function registerSyntheticHeaderAnchor(string $className, string $rule): void
    {
        $this->syntheticHeaderAnchorRules[$className] = $rule;
    }

    public function registerHeaderRichText(string $marker, string $rule): void
    {
        $this->headerRichTextRules[$marker] = $rule;
    }

    public function registerRichTextPresentationalHint(string $marker, string $rule): void
    {
        $this->richTextPresentationalHintRules[$marker] = $rule;
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

    public function registerDisclosureSummaryPresentation(string $className, string $declarations, bool $suppressMarker = false): void
    {
        $this->disclosureSummaryPresentation[$className] = $declarations;
        $this->disclosureSummaryMarkerSuppression[$className] = $suppressMarker;
    }

    public function registerDisclosureSummaryContentCarrierPresentation(string $className, string $declarations): void
    {
        $this->disclosureSummaryContentCarrierPresentation[$className] = $declarations;
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

    /** @var array<string, bool> */
    private array $accordionNativeSummaries = array();

    public function registerAccordionNativeSummaryPresentation(string $className): void
    {
        $this->accordionNativeSummaries[$className] = true;
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

    /** @var array<string, list<array{conditions:list<string>,box:string}>> */
    private array $navigationIconBoxes = array();

    /** @param list<array{conditions:list<string>,box:string}> $boxes */
    public function registerNavigationIconBoxes(string $className, array $boxes): void
    {
        $this->navigationIconBoxes[$className] = $boxes;
    }

    public function navigationIconBoxRules(string $className, string $selector): string
    {
        $rules = '';
        foreach ($this->navigationIconBoxes[$className] ?? array() as $box) {
            $css = $selector . '{' . $box['box'] . '}';
            foreach (array_reverse($box['conditions']) as $condition) $css = $condition . '{' . $css . '}';
            $rules .= $css;
        }
        return $rules;
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

    /** @var array<string, array{box: string, glyph: string}> */
    private array $navigationSubmenuIconGeometry = array();

    public function registerNavigationSubmenuIconGeometry(string $className, string $box, string $glyph): void
    {
        $this->navigationSubmenuIconGeometry[$className] = array('box' => $box, 'glyph' => $glyph);
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

    /** @param array<string, string> $conditional */
    public function registerResponsiveBlockMarginTop(string $className, string $base, array $conditional): void
    {
        $this->responsiveBlockMarginTopRules[$className] = array('base' => $base, 'conditional' => $conditional);
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

    public function beforeAuthorCss(string $serializedBlocks): string
    {
        $parts = array_values($this->nativeSearchTriggerRules);
        // Presentational hints precede every author rule, as in the source.
        foreach ($this->richTextPresentationalHintRules as $marker => $rule) {
            if (str_contains($serializedBlocks, $marker)) {
                $parts[] = $rule;
            }
        }

        return implode("\n", $parts);
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
        foreach ($this->globalZeroBoxRules as $marker => $rule) {
            if (str_contains($serializedBlocks, $marker)) $parts[] = $rule;
        }
        foreach ($this->navigationSubmenuIconGeometry as $className => $geometry) {
            if (str_contains($serializedBlocks, $className)) {
                // Core owns overlay spacing; this bridge is only for the
                // non-overlay menu. Do not alter its accessible toggle markup.
                $selector = '.wp-block-navigation .wp-block-navigation-item.' . $className . ':not(.is-menu-open *)>.wp-block-navigation__submenu-icon';
                $parts[] = $selector . '{' . $geometry['box'] . '}';
                $parts[] = $selector . '>svg{' . $geometry['glyph'] . '}';
            }
        }
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
                $carrier = '>span.' . DisclosureControlPresentation::SUMMARY_CONTENT_CARRIER_CLASS;
                $summary = '.wp-block-details.' . $className . '>summary';
                if ( $this->disclosureSummaryMarkerSuppression[$className] ?? false ) {
                    $parts[] = $summary . '::-webkit-details-marker{display:none}';
                }
                $carrierCss = $this->disclosureSummaryContentCarrierPresentation[$className] ?? '';
                if ( preg_match('/(?:^|;)display:/', $carrierCss) ) {
                    $parts[] = $summary . ':has(' . $carrier . '){padding:0!important;border:0!important;background:none!important;box-shadow:none!important}';
                } else {
                    // Native list-item labels are inline content, not a second
                    // box, even when retained source hooks still match rules.
                    // The carrier holds the summary's own hooks, so a size the
                    // summary rule already states would apply twice when relative.
                    $inheritSize = preg_match('/(?:^|;)font-size:/', $declarations) ? 'font-size:inherit!important;' : '';
                    $parts[] = $summary . $carrier . '{' . $inheritSize . 'display:inline!important;padding:0!important;border:0!important;margin:0!important;background:none!important;box-shadow:none!important;width:auto!important;height:auto!important;min-width:0!important;min-height:0!important;max-width:none!important;max-height:none!important;position:static!important;float:none!important}';
                }
            }
        }
        foreach ($this->disclosureSummaryContentCarrierPresentation as $className => $declarations) {
            if (str_contains($serializedBlocks, $className)) {
                $parts[] = ':where(.wp-block-details.' . $className . '>summary>span.' . DisclosureControlPresentation::SUMMARY_CONTENT_CARRIER_CLASS . '){' . $declarations . '}';
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
            $selectors = array($selector);
            if ( str_starts_with($className, 'blocks-engine-disclosure-summary-') ) {
                $selectors[] = $selector . '>summary>span.' . DisclosureControlPresentation::SUMMARY_CONTENT_CARRIER_CLASS;
            }
            foreach ($rules as $condition => $display) {
                foreach ($selectors as $conditionalSelector) {
                    $parts[] = $condition . '{' . $conditionalSelector . '{display:' . $display . '}' . str_repeat('}', substr_count($condition, '{') + 1);
                }
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
        foreach ($this->accordionNativeSummaries as $className => $_native) {
            $heading = '.wp-block-accordion-heading.' . $className;
            // Core heading lacks margin support; keep its valid save shape and
            // neutralize only the wrapper introduced for a native summary.
            $parts[] = $heading . '{margin:0}';
            $toggle = $heading . '>.wp-block-accordion-heading__toggle';
            $parts[] = $toggle . '>.wp-block-accordion-heading__toggle-icon{display:none}';
            $title = $toggle . '>.wp-block-accordion-heading__toggle-title';
            $parts[] = $title . '{display:list-item;list-style:inside disclosure-closed}';
            $parts[] = $toggle . '[aria-expanded="true"]>.wp-block-accordion-heading__toggle-title{list-style-type:disclosure-open}';
        }
        foreach ($this->accordionIconPresentation as $className => $states) {
            if (!str_contains($serializedBlocks, $className)) continue;
            $toggle = '.wp-block-accordion-heading.' . $className . '>.wp-block-accordion-heading__toggle';
            $parts[] = $toggle . '>.wp-block-accordion-heading__toggle-icon{' . $states['closed'] . '}';
            $parts[] = $toggle . '[aria-expanded="true"]>.wp-block-accordion-heading__toggle-icon{' . $states['open'] . '}';
            if ( isset($states['vector_closed'], $states['vector_open']) ) {
                // A rendered source SVG replaces the editor's background artwork
                // and owns the state transform; the slot keeps only its box.
                $parts[] = $toggle . '[aria-expanded]>.wp-block-accordion-heading__toggle-icon:has(>svg){background-image:none;transform:none;rotate:none;translate:none;scale:none;transition:none}';
                $parts[] = $toggle . '>.wp-block-accordion-heading__toggle-icon>svg{' . $states['vector_closed'] . '}';
                $parts[] = $toggle . '[aria-expanded="true"]>.wp-block-accordion-heading__toggle-icon>svg{' . $states['vector_open'] . '}';
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
        foreach ($this->videoSizeHintRules as $className => $rule) {
            if (str_contains($serializedBlocks, $className)) {
                $parts[] = $rule;
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
        foreach ($this->responsiveBlockMarginTopRules as $className => $rules) {
            if (!str_contains($serializedBlocks, $className)) continue;
            if ('' !== $rules['base']) $parts[] = ':root .' . $className . '{margin-top:' . $rules['base'] . '}';
            foreach ($rules['conditional'] as $condition => $value) {
                $parts[] = $condition . '{:root .' . $className . '{margin-top:' . $value . '}'
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
