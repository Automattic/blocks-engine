<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use DOMElement;

final class TableClassificationPolicy
{
    public const DATA = 'data';
    public const PARAMETER = 'parameter';
    public const LAYOUT_SIMPLE = 'layout_simple';
    public const COMPLEX_NESTED = 'complex_nested';
    public const COMPLEX_SPANNING = 'complex_spanning';

    /**
     * @return array{classification: string, representable: bool, signals: array<string, mixed>}
     */
    public function classify(DOMElement $element): array
    {
        if ( 'table' !== strtolower($element->tagName) ) {
            return array(
                'classification' => self::LAYOUT_SIMPLE,
                'representable'  => false,
                'signals'        => array(),
            );
        }

        $signals = $this->tableSignals($element);
        if ( true === $signals['has_descendant_table'] ) {
            return array(
                'classification' => self::COMPLEX_NESTED,
                'representable'  => false,
                'signals'        => $signals,
            );
        }

        if ( true === $signals['has_colspan'] || true === $signals['has_rowspan'] || false === $signals['rectangular'] ) {
            return array(
                'classification' => self::COMPLEX_SPANNING,
                'representable'  => false,
                'signals'        => $signals,
            );
        }

        return array(
            'classification' => true === $signals['data_signals'] ? self::DATA : self::LAYOUT_SIMPLE,
            'representable'  => true,
            'signals'        => $signals,
        );
    }

    /**
     * Nested layout tables lower to columns. Descendant tables must each be a
     * single headerless layout row. The outer table may stack several such
     * rows (Weebly-style blog chrome) so long as it has no data-table signals.
     * Nested data tables stay outside this conversion.
     */
    public function isNestedLayoutTable(DOMElement $table): bool
    {
        if ( 'table' !== strtolower($table->tagName) || ! $this->hasDescendantTable($table) ) {
            return false;
        }

        $signals = $this->tableSignals($table);
        if ( true === $signals['data_signals'] ) {
            return false;
        }

        foreach ( $table->getElementsByTagName('table') as $descendant ) {
            if ( ! $descendant instanceof DOMElement || $descendant->isSameNode($table) ) {
                continue;
            }
            if ( true === $this->tableSignals($descendant)['data_signals'] ) {
                return false;
            }
        }

        return true;
    }

    public function isNestedLayoutTableMember(DOMElement $table): bool
    {
        for ( $ancestor = $table; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode ) {
            if ( 'table' === strtolower($ancestor->tagName) && $this->isNestedLayoutTable($ancestor) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Legacy tables used for media composition have no tabular semantics, no
     * spanning, and at least one image-bearing cell. Those can become Columns
     * without changing the meaning of genuine data tables.
     */
    public function isMediaLayoutTable(DOMElement $table): bool
    {
        $classification = $this->classify($table);
        if ( self::LAYOUT_SIMPLE !== $classification['classification']
            || false === $classification['representable']
            || empty($classification['signals']['row_count'])
        ) {
            return false;
        }

        foreach ($classification['signals']['column_counts'] as $columnCount) {
            if ($columnCount < 2) {
                return false;
            }
        }

        foreach ($table->getElementsByTagName('img') as $image) {
            if ($image instanceof DOMElement && $this->belongsToTable($image, $table)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A table used to caption a thing rather than to tabulate data.
     *
     * Builders emit these around a file or a media item: one row states the
     * visible label across the whole width, and any remaining rows are
     * label/value metadata the source keeps hidden. Nothing here is tabular -
     * there is no header, no caption, and no cell that shares a row with
     * another visible cell - so a full-width cell is a row rather than a merge
     * that only a spanning grid could express.
     */
    public function isMetadataLayoutTable(DOMElement $table): bool
    {
        if ( 'table' !== strtolower($table->tagName) || $this->hasDescendantTable($table) ) {
            return false;
        }

        $signals = $this->tableSignals($table);
        if ( true === $signals['data_signals'] || true === $signals['has_rowspan'] ) {
            return false;
        }

        $columns = array() === $signals['column_counts'] ? 0 : max($signals['column_counts']);
        if ( 2 > $columns ) {
            return false;
        }

        $visibleFullWidthRows = 0;
        foreach ( $this->rowsForTable($table) as $row ) {
            $cells = $this->cellsForRow($row);
            if ( array() === $cells ) {
                continue;
            }
            if ( $this->isHiddenRow($row) ) {
                continue;
            }
            if ( 1 !== count($cells) || $columns !== (int) ($cells[0]->getAttribute('colspan') ?: 1) ) {
                return false;
            }
            ++$visibleFullWidthRows;
        }

        return 0 < $visibleFullWidthRows;
    }

    private function isHiddenRow(DOMElement $row): bool
    {
        return 1 === preg_match('/(?:^|;)\s*display\s*:\s*none\b/i', $row->getAttribute('style'));
    }

    /**
     * A single headerless row whose cells all declare percentage widths is a
     * layout grid, not a data table.
     */
    public function isPercentLayoutTable(DOMElement $table): bool
    {
        $className = strtolower($table->getAttribute('class'));
        if ( 'table' !== strtolower($table->tagName)
            || $this->hasDescendantTable($table)
            || ! $this->isSingleRowLayoutTable($table)
            || 1 !== preg_match('/(?:^|[\s_-])(?:multi)?col(?:umns?|s)?(?:[\s_-]|$)|multicol/', $className)
        ) {
            return false;
        }

        $rows = $this->rowsForTable($table);
        $cells = $this->cellsForRow($rows[0]);
        if ( count($cells) < 2 ) {
            return false;
        }

        foreach ( $cells as $cell ) {
            $style = strtolower($cell->getAttribute('style'));
            if ( 1 !== preg_match('/(?:^|;)\s*width\s*:\s*\d+(?:\.\d+)?%/i', $style) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function tableSignals(DOMElement $table): array
    {
        $rows = $this->rowsForTable($table);
        $columnCounts = array();
        $hasColspan = false;
        $hasRowspan = false;
        $hasHeaderCell = false;

        foreach ( $rows as $row ) {
            $columnCount = 0;
            foreach ( $this->cellsForRow($row) as $cell ) {
                ++$columnCount;
                $tagName = strtolower($cell->tagName);
                $hasHeaderCell = $hasHeaderCell || 'th' === $tagName;
                $hasColspan = $hasColspan || $cell->hasAttribute('colspan');
                $hasRowspan = $hasRowspan || $cell->hasAttribute('rowspan');
            }
            $columnCounts[] = $columnCount;
        }

        $nonEmptyColumnCounts = array_values(array_filter($columnCounts, static fn (int $count): bool => $count > 0));
        $rectangular = array() !== $nonEmptyColumnCounts && 1 === count(array_unique($nonEmptyColumnCounts));
        $hasCaption = null !== $this->firstDirectChild($table, 'caption');
        $hasSection = null !== $this->firstDirectChild($table, 'thead') || null !== $this->firstDirectChild($table, 'tfoot');

        return array(
            'has_descendant_table' => $this->hasDescendantTable($table),
            'has_colspan'          => $hasColspan,
            'has_rowspan'          => $hasRowspan,
            'row_count'            => count($rows),
            'column_counts'        => $columnCounts,
            'rectangular'          => $rectangular,
            'data_signals'         => $hasHeaderCell || $hasCaption || $hasSection,
        );
    }

    private function isSingleRowLayoutTable(DOMElement $table): bool
    {
        $signals = $this->tableSignals($table);
        if ( 1 !== $signals['row_count']
            || true === $signals['data_signals']
            || true === $signals['has_colspan']
            || true === $signals['has_rowspan']
            || false === $signals['rectangular']
        ) {
            return false;
        }

        foreach ( $this->rowsForTable($table) as $row ) {
            foreach ( $this->cellsForRow($row) as $cell ) {
                if ( 'th' === strtolower($cell->tagName) ) {
                    return false;
                }
            }
        }

        foreach ( $table->getElementsByTagName('table') as $descendant ) {
            if ( $descendant instanceof DOMElement && ! $descendant->isSameNode($table) && ! $this->isSingleRowLayoutTable($descendant) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, DOMElement>
     */
    private function rowsForTable(DOMElement $table): array
    {
        $rows = array();
        foreach ( $table->getElementsByTagName('tr') as $row ) {
            if ( $row instanceof DOMElement && $this->belongsToTable($row, $table) ) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array<int, DOMElement>
     */
    private function cellsForRow(DOMElement $row): array
    {
        $cells = array();
        foreach ( $row->childNodes as $cell ) {
            if ( $cell instanceof DOMElement && in_array(strtolower($cell->tagName), array( 'td', 'th' ), true) ) {
                $cells[] = $cell;
            }
        }

        return $cells;
    }

    private function belongsToTable(DOMElement $element, DOMElement $table): bool
    {
        for ( $node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( 'table' !== strtolower($node->tagName) ) {
                continue;
            }

            return $node->isSameNode($table);
        }

        return false;
    }

    private function hasDescendantTable(DOMElement $table): bool
    {
        foreach ( $table->getElementsByTagName('table') as $descendant ) {
            if ( $descendant instanceof DOMElement && ! $descendant->isSameNode($table) ) {
                return true;
            }
        }

        return false;
    }

    private function firstDirectChild(DOMElement $element, string $tagName): ?DOMElement
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $tagName === strtolower($child->tagName) ) {
                return $child;
            }
        }

        return null;
    }
}
