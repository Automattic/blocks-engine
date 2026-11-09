<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Css\CssLength;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\BlockFactory;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorLayoutOwnership;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\TableClassificationPolicy;
use Closure;
use DOMElement;
use DOMNode;

/**
 * Lowers source layout tables (tables used for page layout rather than data)
 * to core/columns, core/column and core/group, with the track widths,
 * spacing and stacking the source table expressed.
 */
final class LayoutTableLowering
{
    private const LAYOUT_TABLE_COLUMNS_CLASS = 'blocks-engine-layout-table-columns';

    /**
     * @param Closure(DOMNode, array<int, array<string, mixed>>&, bool=): array<int, array<string, mixed>> $convertChildren
     */
    public function __construct(
        private readonly SourceBlockCreator $blocks,
        private readonly HtmlTransformerSession $session,
        private readonly StyleResolver $styleResolver,
        private readonly BlockFactory $blockFactory,
        private readonly AuthorLayoutOwnership $authorLayoutOwnership,
        private readonly Closure $convertChildren
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>
     */
    public function nestedLayoutTableColumnsBlock(DOMElement $table, array &$fallbacks): array
    {
        $directRows = 0;
        foreach ( $table->getElementsByTagName('tr') as $candidate ) {
            if ( $candidate instanceof DOMElement && SourceDom::belongsToTable($candidate, $table) ) {
                ++$directRows;
                if ( 1 < $directRows ) {
                    return $this->mediaLayoutTableColumnsBlock($table, $fallbacks);
                }
            }
        }

        $rows = $table->getElementsByTagName('tr');
        $row = $rows->item(0);
        if ( ! $row instanceof DOMElement ) {
            return $this->blocks->htmlPreservationBlock($table);
        }

        $columns = array();
        foreach ( $row->childNodes as $cell ) {
            if ( ! $cell instanceof DOMElement || 'td' !== strtolower($cell->tagName) ) {
                continue;
            }

            $column = $this->blocks->createBlock(
                'core/column',
                $this->layoutTableColumnAttributes($cell),
                $this->layoutTableCellBlocks($cell, $fallbacks),
                $cell
            );
            // A blank layout-table cell remains a real native column: removing it
            // changes the rendered Columns topology.
            $column['_editability_visual_owned'] = true;
            $columns[] = $column;
        }

        return $this->blocks->createBlock('core/columns', $this->layoutTableColumnsAttributes($table), $columns, $table);
    }

    /**
     * @return array<string, mixed>
     */
    private function layoutTableColumnAttributes(DOMElement $cell): array
    {
        $attrs = $this->styleResolver->presentationAttributes($cell);
        $width = $this->layoutTableCellTrackWidth($cell);
        if ( null !== $width ) {
            $attrs['width'] = $width;
        }

        $table = $this->authorLayoutOwnership->ancestorElement($cell, 'table');
        if ($table instanceof DOMElement && $this->layoutTableUsesAutoTracks($table)) {
            $attrs = $this->layoutTablePresentationCarrier($cell, $attrs, 'cell');
        }

        return $attrs;
    }

    /** @param array<int, array<string, mixed>> $fallbacks
     * @return array<int, array<string, mixed>>
     */
    private function layoutTableCellBlocks(DOMElement $cell, array &$fallbacks): array
    {
        $blocks = ($this->convertChildren)($cell, $fallbacks, true);
        // Bare cell text needs editable RichText, not a new paragraph box.
        // Authored paragraphs retain their own margins and display contracts.
        $bareText = array();
        foreach ($cell->childNodes as $node) {
            if ($node instanceof \DOMText && '' !== trim($node->textContent)) {
                $bareText[trim($node->textContent)] = true;
            }
        }
        if (0 === $cell->getElementsByTagName('p')->length && array() !== $bareText) {
            foreach ($blocks as &$block) {
                $text = trim(html_entity_decode(strip_tags((string) ($block['attrs']['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ('core/paragraph' === ($block['blockName'] ?? '') && isset($bareText[$text])) {
                    $attrs = $block['attrs'];
                    $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), SourceBlockAttributeProjector::SYNTHETIC_PARAGRAPH_CLASS);
                    $block = $this->blockFactory->rebuild($block, $attrs);
                }
            }
            unset($block);
        }
        return $blocks;
    }

    public function layoutTableUsesAutoTracks(DOMElement $table): bool
    {
        $declarations = $this->styleResolver->structuralPresentationDeclarations($table);
        return 'fixed' !== CssValueInspector::comparable((string) ($declarations['table-layout'] ?? ''));
    }

    /**
     * Keep the source table sizing algorithm on editable native wrappers.
     * Flex cannot share tracks across rows or honor table min-content widths.
     * HTML presentation hints are low-specificity defaults; authored CSS still
     * travels through the ordinary source selector / inline geometry contracts.
     *
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function layoutTablePresentationCarrier(DOMElement $element, array $attrs, string $role): array
    {
        $declarations = array();
        foreach (array('width', 'height') as $dimension) {
            $value = $this->normalizeLayoutTableTrackWidth(SourceDom::attr($element, $dimension));
            if (null !== $value) {
                $declarations[] = $dimension . ':' . $value;
            }
        }
        if ('cell' === $role) {
            $table = $this->authorLayoutOwnership->ancestorElement($element, 'table');
            $padding = $table instanceof DOMElement ? trim(SourceDom::attr($table, 'cellpadding')) : '';
            if ('' !== $padding && is_numeric($padding)) {
                $declarations[] = 'padding:' . $padding . 'px';
            }
            $align = $this->styleResolver->resolvedDeclaration($element, 'text-align');
            if (in_array($align, array('left', 'center', 'right', 'justify'), true)) {
                $declarations[] = 'text-align:' . $align;
            }
            $valign = $this->styleResolver->resolvedDeclaration($element, 'vertical-align');
            if ('' === $valign && $element->parentNode instanceof DOMElement) {
                $valign = $this->styleResolver->resolvedDeclaration($element->parentNode, 'vertical-align');
            }
            if (in_array($valign, array('top', 'middle', 'bottom', 'baseline'), true)) {
                $declarations[] = 'vertical-align:' . $valign;
            }
            if ($element->hasAttribute('nowrap')) {
                $declarations[] = 'white-space:nowrap';
            }
        } elseif ('table' === $role) {
            $spacing = trim(SourceDom::attr($element, 'cellspacing'));
            if ('' !== $spacing && is_numeric($spacing)) {
                $declarations[] = 'border-spacing:' . $spacing . 'px';
            }
        }
        $attrs['className'] = trim((string) ($attrs['className'] ?? '') . ' blocks-engine-layout-table-' . $role);
        if (array() !== $declarations) {
            $rule = '{' . implode(';', $declarations) . '}';
            $carrier = $this->session->layoutGeometryState()->allocateCarrier('layout-table-hints' . "\n" . $rule);
            $this->session->layoutGeometryState()->registerRule($carrier, '.' . $carrier . $rule);
            $attrs['className'] .= ' ' . $carrier;
        }
        return $attrs;
    }

    private function layoutTableCellTrackWidth(DOMElement $cell): ?string
    {
        $declarations = $this->styleResolver->structuralPresentationDeclarations($cell);
        $cssWidth     = $this->normalizeLayoutTableTrackWidth((string) ($declarations['width'] ?? ''));
        if ( null === $cssWidth ) {
            $cssWidth = $this->normalizeLayoutTableTrackWidth(SourceDom::attr($cell, 'width'));
        }
        if ( null === $cssWidth ) {
            return null;
        }
        if ( str_ends_with($cssWidth, '%') || 'border-box' === CssValueInspector::comparable((string) ($declarations['box-sizing'] ?? '')) ) {
            return $cssWidth;
        }

        [ $padStart, $padEnd ] = $this->layoutTableCellInlinePadding($cell);
        [ $borderStart, $borderEnd ] = $this->layoutTableCellInlineBorderWidths($cell);
        return $this->sumLayoutTableAbsoluteLengths($cssWidth, $padStart, $padEnd, $borderStart, $borderEnd) ?? $cssWidth;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function layoutTableCellInlinePadding(DOMElement $cell): array
    {
        $default = '1px';
        $table   = $this->authorLayoutOwnership->ancestorElement($cell, 'table');
        if ( $table instanceof DOMElement ) {
            $cellpadding = trim(SourceDom::attr($table, 'cellpadding'));
            if ( '' !== $cellpadding && is_numeric($cellpadding) ) {
                $default = $cellpadding . 'px';
            }
        }

        $declarations = $this->styleResolver->structuralPresentationDeclarations($cell);
        $shorthand    = CssValueInspector::comparable((string) ($declarations['padding'] ?? ''));
        [ , $end, , $start ] = CssValueInspector::expandBoxShorthand( '' !== $shorthand ? $shorthand : $default );
        if ( isset($declarations['padding-left']) ) {
            $start = CssValueInspector::comparable((string) $declarations['padding-left']);
        }
        if ( isset($declarations['padding-right']) ) {
            $end = CssValueInspector::comparable((string) $declarations['padding-right']);
        }

        return array( $start, $end );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function layoutTableCellInlineBorderWidths(DOMElement $cell): array
    {
        $declarations = $this->styleResolver->structuralPresentationDeclarations($cell);
        $shorthand    = CssValueInspector::comparable((string) ($declarations['border-width'] ?? ''));
        [ , $end, , $start ] = CssValueInspector::expandBoxShorthand( '' !== $shorthand ? $shorthand : '0px' );
        $left  = CssValueInspector::comparable((string) ($declarations['border-left-width'] ?? ''));
        $right = CssValueInspector::comparable((string) ($declarations['border-right-width'] ?? ''));
        if ( null !== $this->parseLayoutTableAbsoluteLength($left) ) {
            $start = $left;
        }
        if ( null !== $this->parseLayoutTableAbsoluteLength($right) ) {
            $end = $right;
        }
        if ( null === $this->parseLayoutTableAbsoluteLength($start) ) {
            $start = '0px';
        }
        if ( null === $this->parseLayoutTableAbsoluteLength($end) ) {
            $end = '0px';
        }

        return array( $start, $end );
    }

    private function normalizeLayoutTableTrackWidth(string $width): ?string
    {
        $width = CssValueInspector::comparable($width);
        if ( 1 === preg_match('/^(\d+(?:\.\d+)?)(%|px|em|rem|ch|vw|vmin|vmax)$/', $width, $matches) ) {
            return $matches[1] . $matches[2];
        }
        if ( 1 === preg_match('/^(\d+(?:\.\d+)?)$/', $width, $matches) ) {
            return $matches[1] . 'px';
        }

        return null;
    }

    /**
     * @return array{0: float, 1: string}|null
     */
    private function parseLayoutTableAbsoluteLength(string $value): ?array
    {
        $value = CssValueInspector::comparable($value);
        if ( 1 === preg_match('/^(\d+(?:\.\d+)?)(px|em|rem|ch|vw|vmin|vmax)$/', $value, $matches) ) {
            return array( (float) $matches[1], $matches[2] );
        }
        if ( 1 === preg_match('/^(\d+(?:\.\d+)?)$/', $value) ) {
            return array( (float) $value, 'px' );
        }

        return null;
    }

    private function sumLayoutTableAbsoluteLengths(string ...$values): ?string
    {
        $sum  = 0.0;
        $unit = null;
        foreach ( $values as $value ) {
            $parsed = $this->parseLayoutTableAbsoluteLength($value);
            if ( null === $parsed ) {
                return null;
            }
            if ( null === $unit ) {
                $unit = $parsed[1];
            } elseif ( $unit !== $parsed[1] ) {
                return null;
            }
            $sum += $parsed[0];
        }
        if ( null === $unit ) {
            return null;
        }
        $number = abs($sum - round($sum)) < 0.0001
            ? (string) (int) round($sum)
            : rtrim(rtrim(sprintf('%.4F', $sum), '0'), '.');

        return $number . $unit;
    }

    /** @return array<string, mixed> */
    private function layoutTableColumnsAttributes(DOMElement $element): array
    {
        $attrs = $this->styleResolver->presentationAttributes($element);
        // A table has no implicit phone breakpoint, so Core's 782px stacking
        // stays off. Rows stack only where the source collapses their cells.
        $attrs['isStackedOnMobile'] = false;
        $attrs['className'] = trim((string) ($attrs['className'] ?? '') . ' ' . self::LAYOUT_TABLE_COLUMNS_CLASS);
        $table = 'table' === strtolower($element->tagName) ? $element : $this->authorLayoutOwnership->ancestorElement($element, 'table');
        if ($table instanceof DOMElement && $this->layoutTableUsesAutoTracks($table)) {
            return $this->layoutTablePresentationCarrier($element, $attrs, 'table' === strtolower($element->tagName) ? 'table' : 'row');
        }
        $stacking = $this->layoutTableSourceStackingCss($element);
        if ( '' !== $stacking ) {
            $className = $this->session->layoutGeometryState()->allocateCarrier('layout-table-stacking' . "\n" . $stacking);
            $this->session->layoutGeometryState()->registerRule($className, str_replace('{carrier}', $className, $stacking));
            $attrs['className'] = trim((string) $attrs['className'] . ' ' . $className);
        }
        $spacing = $this->layoutTableTrackSpacing($element);
        if ( null !== $spacing ) {
            $rule      = '{column-gap:' . $spacing . ';padding-inline:' . $spacing . '}';
            $className = $this->session->layoutGeometryState()->allocateCarrier('layout-table-spacing' . "\n" . $rule);
            $this->session->layoutGeometryState()->registerRule(
                $className,
                ':root .wp-block-columns.' . self::LAYOUT_TABLE_COLUMNS_CLASS . '.' . $className . $rule
            );
            $attrs['className'] = trim((string) ($attrs['className'] ?? '') . ' ' . $className);
        }

        return $attrs;
    }

    /**
     * Stacking rules for a layout-table row whose source CSS turns every cell
     * into a block box under a media condition: the cells stop being table
     * tracks there and stack full width, so the native row wraps its columns
     * under that same condition. `{carrier}` stands for the carrier class.
     */
    private function layoutTableSourceStackingCss(DOMElement $element): string
    {
        $row = 'tr' === strtolower($element->tagName) ? $element : null;
        foreach ( null === $row ? $element->getElementsByTagName('tr') : array() as $candidate ) {
            if ( $candidate instanceof DOMElement && SourceDom::belongsToTable($candidate, $element) ) {
                $row = $candidate;
                break;
            }
        }
        if ( ! $row instanceof DOMElement ) {
            return '';
        }

        $stacks = null;
        foreach ( $row->childNodes as $cell ) {
            if ( ! $cell instanceof DOMElement || 'td' !== strtolower($cell->tagName) ) {
                continue;
            }
            $display = $this->styleResolver->declaredPresentation($cell, 'display');
            $blockStacks = array();
            foreach ( array_keys($display->conditional()) as $key ) {
                $stack = explode('{', $key);
                $value = $display->resolvedValueWhere(static fn (array $conditions): bool => $stack === array_values(array_filter(
                    array_map('trim', $conditions),
                    static fn (string $condition): bool => 1 !== preg_match('/^@layer\b/i', $condition)
                )));
                $value = strtolower(trim((string) preg_replace('/\s*!\s*important\s*$/i', '', $value)));
                if ( in_array($value, array( 'block', 'flex', 'grid', 'flow-root', 'list-item' ), true) ) {
                    $blockStacks[$key] = $stack;
                }
            }
            $stacks = null === $stacks ? $blockStacks : array_intersect_key($stacks, $blockStacks);
            if ( array() === $stacks ) {
                return '';
            }
        }

        $host = ':root .wp-block-columns.' . self::LAYOUT_TABLE_COLUMNS_CLASS . '.{carrier}';
        $css  = '';
        foreach ( $stacks ?? array() as $stack ) {
            $rule = $host . '{flex-wrap:wrap!important}' . $host . '>.wp-block-column{flex-basis:100%!important}';
            foreach ( array_reverse($stack) as $condition ) {
                $rule = $condition . '{' . $rule . '}';
            }
            $css .= $rule;
        }

        return $css;
    }

    private function layoutTableTrackSpacing(DOMElement $element): ?string
    {
        if ( ! $this->layoutTableRowNeedsTrackSpacing($element) ) {
            return null;
        }

        $table = 'table' === strtolower($element->tagName)
            ? $element
            : ( $this->authorLayoutOwnership->ancestorElement($element, 'table') ?? $element );
        $declarations = $this->styleResolver->structuralPresentationDeclarations($table);
        if ( 'collapse' === CssValueInspector::comparable((string) ($declarations['border-collapse'] ?? '')) ) {
            return null;
        }

        $spacing = CssValueInspector::comparable((string) ($declarations['border-spacing'] ?? ''));
        if ( '' === $spacing ) {
            $cellspacing = trim(SourceDom::attr($table, 'cellspacing'));
            $spacing     = '' !== $cellspacing && is_numeric($cellspacing) ? $cellspacing . 'px' : '2px';
        }
        $parts      = preg_split('/\s+/', $spacing) ?: array();
        $horizontal = $this->normalizeLayoutTableTrackWidth($parts[0] ?? '');
        if ( null === $horizontal ) {
            return null;
        }
        $parsed = $this->parseLayoutTableAbsoluteLength($horizontal);
        if ( null === $parsed || $parsed[0] <= 0.0 ) {
            return null;
        }

        return $horizontal;
    }

    private function layoutTableRowNeedsTrackSpacing(DOMElement $element): bool
    {
        $row = $element;
        if ( 'tr' !== strtolower($element->tagName) ) {
            $row = null;
            foreach ( $element->getElementsByTagName('tr') as $candidate ) {
                if ( $candidate instanceof DOMElement && SourceDom::belongsToTable($candidate, $element) ) {
                    $row = $candidate;
                    break;
                }
            }
        }
        if ( ! $row instanceof DOMElement ) {
            return false;
        }
        $count   = 0;
        $unsized = false;
        foreach ( $row->childNodes as $cell ) {
            if ( ! $cell instanceof DOMElement || 'td' !== strtolower($cell->tagName) ) {
                continue;
            }
            ++$count;
            if ( null === $this->layoutTableCellTrackWidth($cell) ) {
                $unsized = true;
            }
        }

        return $unsized && 2 <= $count;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>
     */
    public function singleColumnLayoutTableGroupBlock(DOMElement $table, array &$fallbacks): array
    {
        // The table's cell spacing surrounds and separates the rows, and its
        // cell padding insets each row's content.
        $spacing = $this->layoutTableCellSpacing($table);
        $padding = max(0, (int) ( is_numeric(SourceDom::attr($table, 'cellpadding')) ? SourceDom::attr($table, 'cellpadding') : 1 ));
        $rows = array();
        $cellWidth = null;
        foreach ( $table->getElementsByTagName('tr') as $row ) {
            if ( ! $row instanceof DOMElement || ! SourceDom::belongsToTable($row, $table) ) {
                continue;
            }
            foreach ( $row->childNodes as $cell ) {
                if ( $cell instanceof DOMElement && in_array(strtolower($cell->tagName), array( 'td', 'th' ), true) ) {
                    $blocks = $this->layoutTableCellBlocks($cell, $fallbacks);
                    // An empty, height-sized cell is a vertical spacer between page
                    // regions. A cell's height includes its padding.
                    $height = $this->normalizeLayoutTableTrackWidth(SourceDom::attr($cell, 'height'));
                    $spacer = array() === $blocks && null !== $height && ! str_ends_with($height, '%');
                    if ( $spacer ) {
                        $blocks = array( $this->blocks->createBlock('core/spacer', array( 'height' => $height ), array(), $cell) );
                    }
                    $cellPadding = $spacer || array() !== array_intersect_key($this->styleResolver->resolvedPresentationDeclarations($cell), array_flip(array( 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left' ))) ? 0 : $padding;
                    // A taller cell keeps its height and places its content the way
                    // the cell aligns it: in the middle unless `valign` says otherwise.
                    $boxHeight = ! $spacer && null !== $height && str_ends_with($height, 'px') ? $height : null;
                    $rows[]    = array( $blocks, $cellPadding, $boxHeight, $this->layoutTableCellAlignment($cell) );
                    $cellWidth = $cellWidth ?? $this->normalizeLayoutTableTrackWidth(SourceDom::attr($cell, 'width'));
                }
            }
        }

        // Rows are stacked page regions: one row is the group's content, several keep their own boxes.
        $boxPadding = static fn (int $inset): array => array( 'top' => $inset . 'px', 'right' => $inset . 'px', 'bottom' => $inset . 'px', 'left' => $inset . 'px' );
        $single     = 1 === count($rows);
        $children   = $single && null === $rows[0][2]
            ? $rows[0][0]
            : array_map(
                function (array $row, int $index) use ($boxPadding, $spacing, $table, $single): array {
                    $attrs = array();
                    if ( 0 < $row[1] && ! $single ) {
                        $attrs['style']['spacing']['padding'] = $boxPadding($row[1]);
                    }
                    if ( 0 < $index ) {
                        $attrs['style']['spacing']['margin'] = array( 'top' => $spacing . 'px' );
                    }
                    $blocks = $row[0];
                    if ( null !== $row[2] ) {
                        // The cell height includes the padding, which a single row's table box carries.
                        $attrs['style']['dimensions']['minHeight'] = CssLength::format(max(0.0, (float) $row[2] - ( $single ? 2 * $row[1] : 0 )));
                        if ( 'top' !== $row[3] ) {
                            // A vertical flex box places the content; it stays one flow box, so inline runs keep flowing.
                            $attrs['layout'] = array( 'type' => 'flex', 'orientation' => 'vertical', 'justifyContent' => 'stretch', 'verticalAlignment' => $row[3] );
                            $blocks          = array( $this->blocks->createBlock('core/group', array(), $blocks, $table) );
                        }
                    }
                    return $this->blocks->createBlock('core/group', $attrs, $blocks, $table);
                },
                $rows,
                array_keys($rows)
            );
        $inset = $spacing + ( $single ? $rows[0][1] : 0 );

        // core/table renders at 100% width, so the table box is carried by core layout instead:
        // the resolved table (or cell) width becomes the content size, and the box aligns
        // to the edge its `align`, <center> or <div align> names; an aligned table without
        // a width shrinks to fit. A table width includes the spacing and padding
        // around the cell content; a cell width only its content.
        $attrs     = $this->styleResolver->presentationAttributes($table);
        $tableWidth = $this->normalizeLayoutTableTrackWidth(SourceDom::attr($table, 'width'));
        $width      = $tableWidth ?? $cellWidth;
        if ( null !== $width && str_ends_with($width, 'px') ) {
            $content = null !== $tableWidth ? (float) $width - 2 * $inset : (float) $width + ( $single ? 0 : 2 * $padding );
            $width   = CssLength::format(max(0.0, $content));
        }
        if ( 0 < $inset && ! isset($attrs['style']['spacing']['padding']) ) {
            $attrs['style']['spacing']['padding'] = $boxPadding($inset);
        }
        $alignment = TableClassificationPolicy::boxAlignment($table);
        if ( null !== $width ) {
            $attrs['layout'] = array( 'type' => 'constrained', 'contentSize' => $width, 'justifyContent' => $alignment ?? 'left' );
        } elseif ( null !== $alignment ) {
            $attrs['layout'] = array( 'type' => 'flex', 'orientation' => 'vertical', 'justifyContent' => $alignment );
        }

        return $this->blocks->createBlock('core/group', $attrs, $children, $table);
    }

    /** Where a cell places its content vertically: `top`, `center` (the default) or `bottom`. */
    private function layoutTableCellAlignment(DOMElement $cell): string
    {
        $valign = $this->styleResolver->resolvedDeclaration($cell, 'vertical-align');
        if ( '' === $valign && $cell->parentNode instanceof DOMElement ) {
            $valign = $this->styleResolver->resolvedDeclaration($cell->parentNode, 'vertical-align');
        }
        return match ( $valign ) {
            'top', 'baseline', 'text-top' => 'top',
            'bottom', 'text-bottom' => 'bottom',
            default => 'center',
        };
    }

    /** The table's border spacing in px: `cellspacing` (2 by default), unless author CSS sets it. */
    private function layoutTableCellSpacing(DOMElement $table): int
    {
        $declarations = $this->styleResolver->resolvedPresentationDeclarations($table);
        if ( 'collapse' === strtolower(trim((string) ($declarations['border-collapse'] ?? ''))) ) {
            return 0;
        }
        $authored = trim((string) ($declarations['border-spacing'] ?? ''));
        if ( 1 === preg_match('/^(\d+(?:\.\d+)?)(?:px)?$/i', $authored, $match) ) {
            return (int) round((float) $match[1]);
        }
        $attribute = trim(SourceDom::attr($table, 'cellspacing'));
        return is_numeric($attribute) ? max(0, (int) $attribute) : 2;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>
     */
    public function mediaLayoutTableColumnsBlock(DOMElement $table, array &$fallbacks): array
    {
        $rows = array();
        foreach ($table->getElementsByTagName('tr') as $row) {
            if (! $row instanceof DOMElement || ! SourceDom::belongsToTable($row, $table)) {
                continue;
            }

            $columns = array();
            foreach ($row->childNodes as $cell) {
                if (! $cell instanceof DOMElement || 'td' !== strtolower($cell->tagName)) {
                    continue;
                }
                $columns[] = $this->blocks->createBlock(
                    'core/column',
                    $this->layoutTableColumnAttributes($cell),
                    $this->layoutTableCellBlocks($cell, $fallbacks),
                    $cell
                );
            }
            if (array() !== $columns) {
                $rows[] = $this->blocks->createBlock('core/columns', $this->layoutTableColumnsAttributes($row), $columns, $row);
            }
        }

        $tableAttributes = $this->styleResolver->presentationAttributes($table);
        if ($this->layoutTableUsesAutoTracks($table)) {
            $tableAttributes = $this->layoutTablePresentationCarrier($table, $tableAttributes, 'table');
        }
        if (1 === count($rows)) {
            return array() === $tableAttributes
                ? $rows[0]
                : $this->blocks->createBlock('core/group', $tableAttributes, $rows, $table);
        }

        return $this->blocks->createBlock('core/group', $tableAttributes, $rows, $table);
    }
}
