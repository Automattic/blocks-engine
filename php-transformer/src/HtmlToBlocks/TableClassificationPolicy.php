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
     * A nested table can be lowered to responsive columns only when every table
     * in its subtree is a single, headerless layout row. Data and spanning
     * tables intentionally remain outside this narrow conversion.
     */
    public function isNestedLayoutTable(DOMElement $table): bool
    {
        if ( 'table' !== strtolower($table->tagName) || ! $this->hasDescendantTable($table) ) {
            return false;
        }

        return $this->isSingleRowLayoutTable($table);
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
