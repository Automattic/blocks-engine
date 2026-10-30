<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorSelectorProjectionState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\LayoutGeometryState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceStyleResolutionState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\NavigationToggleSuppressor;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * Builds the `core/table` attributes for a source table.
 *
 * Reads the header, body and footer rows into table cells, keeps the fixed
 * layout flag, and registers the presentation and cell geometry rules that
 * core's table styles would otherwise override.
 * {@see TableElementConverter} decides whether a table becomes `core/table`.
 */
final class TableAttributesBuilder
{
    public function __construct(
        private readonly StyleResolver $styleResolver,
        private readonly NavigationToggleSuppressor $navigationToggleSuppressor,
        private readonly HtmlTransformerSession $session
    ) {
    }

    /** Source markup changed after selector analysis, so cached inputs are stale. */
    private function onSourceMarkupMutated(): void
    {
        $this->sourceStyles()->invalidateSelectorMatches();
        $this->navigationToggleSuppressor->forgetCoreNavigationRecognitions();
    }

    /**
     * @return array<string, mixed>
     */
    public function tableAttributes(DOMElement $table): array
    {
        $native = self::isNativeSavedTable($table);
        $attrs = array(
            'hasFixedLayout' => $native
                ? in_array('has-fixed-layout', self::classTokens($table), true)
                : 'fixed' === strtolower(trim((string) ($this->styleResolver->structuralPresentationDeclarations($table)['table-layout'] ?? ''))),
        );
        // core/table's own saved markup already looks the way core styles it, so
        // only captured tables are normalized away from core's table defaults.
        if ( ! $native ) {
            $this->registerTablePresentationNormalization($table);
        }
        $this->registerTableCellGeometry($table);
        foreach ( array( 'thead' => 'head', 'tbody' => 'body', 'tfoot' => 'foot' ) as $sectionTag => $attrName ) {
            $rows = array();
            foreach ( $table->getElementsByTagName($sectionTag) as $section ) {
                if ( ! SourceDom::belongsToTable($section, $table) ) {
                    continue;
                }
                foreach ( $section->getElementsByTagName('tr') as $row ) {
                    if ( ! SourceDom::belongsToTable($row, $table) ) {
                        continue;
                    }
                    $rows[] = array( 'cells' => $this->tableCells($row) );
                }
            }
            if ( array() !== $rows ) {
                $attrs[$attrName] = $rows;
            }
        }

        if ( empty($attrs['body']) ) {
            $rows = array();
            foreach ( $table->getElementsByTagName('tr') as $row ) {
                if ( ! SourceDom::belongsToTable($row, $table) ) {
                    continue;
                }
                if ( in_array($this->closestTagName($row), array( 'thead', 'tfoot' ), true) ) {
                    continue;
                }
                $rows[] = array( 'cells' => $this->tableCells($row) );
            }
            if ( array() !== $rows ) {
                $attrs['body'] = $rows;
            }
        }

        $caption = $this->firstChildElement($table, 'caption');
        if ( $caption instanceof DOMElement ) {
            $attrs['caption'] = $this->innerHtml($caption);
        }

        return $attrs;
    }

    /**
     * A table inside the figure core/table saves that the engine did not
     * produce: WordPress's own markup. Engine output carries its table marker
     * and keeps the normalization that marker scopes.
     */
    public static function isNativeSavedTable(DOMElement $table): bool
    {
        $figure = $table->parentNode;
        if ( ! $figure instanceof DOMElement
            || 'figure' !== strtolower($figure->tagName)
            || ! in_array('wp-block-table', self::classTokens($figure), true)
        ) {
            return false;
        }
        foreach ( array_merge(self::classTokens($figure), self::classTokens($table)) as $class ) {
            if ( str_starts_with($class, 'blocks-engine-table-') ) {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> */
    private static function classTokens(DOMElement $element): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array(), static fn (string $class): bool => '' !== $class));
    }

    private function registerTablePresentationNormalization(DOMElement $table): void
    {
        $path = SourceDom::sourceElementIdentity($table);
        $marker = $this->authorSelectorProjections()->ensureTableMarker($path, $table);
        $tableDeclarations = $this->styleResolver->structuralPresentationDeclarations($table);
        // A single marker class ties core's .wp-block-table margin while later
        // source classes promoted onto the figure retain their authored margins.
        $rules = array( '.' . $marker . '{margin:0}' );
        $borderModel = array();
        foreach ( array( 'border-collapse', 'border-spacing' ) as $property ) {
            $value = trim((string) ($tableDeclarations[$property] ?? ''));
            if ( '' !== $value ) {
                $borderModel[] = $property . ':' . $value;
            }
        }
        if ( array() !== $borderModel ) {
            $rules[] = '.' . $marker . '>table{' . implode(';', $borderModel) . '}';
        }

        // Core supplies borders on every cell. Clear them before projected author
        // CSS restores only the source-declared cell sides.
        $rules[] = '.' . $marker . '>table th,.' . $marker . '>table td{border:0}';

        // Core's cell padding and border are unlayered, so they beat author
        // cell rules inside an `@layer` (Tailwind utilities) whatever their
        // specificity. When every author rule setting a property family on
        // these cells is layered, `revert-layer` drops the unlayered values
        // (core's and the reset above) and lets the author's layered cascade
        // decide, layer order and media queries included. A family an
        // unlayered author rule also sets keeps the existing behavior.
        $layering = array();
        foreach ( $table->getElementsByTagName('*') as $cell ) {
            if ( ! $cell instanceof DOMElement || ! in_array(strtolower($cell->tagName), array( 'td', 'th' ), true) || ! SourceDom::belongsToTable($cell, $table) ) {
                continue;
            }
            foreach ( $this->styleResolver->tableCellBoxLayering($cell) as $family => $layered ) {
                $layering[ $family ] = ( $layering[ $family ] ?? true ) && $layered;
            }
        }
        $reverted = array_keys(array_filter($layering));
        sort($reverted);
        if ( array() !== $reverted ) {
            $rules[] = '.' . $marker . '>table th,.' . $marker . '>table td{' . implode(';', array_map(static fn (string $family): string => $family . ':revert-layer', $reverted)) . '}';
        }

        $head = $this->firstChildElement($table, 'thead');
        if ( $head instanceof DOMElement ) {
            $headDeclarations = $this->styleResolver->structuralPresentationDeclarations($head);
            if ( ! isset($headDeclarations['border']) && ! isset($headDeclarations['border-bottom']) && ! isset($headDeclarations['border-bottom-width']) ) {
                // core/table adds a 3px header separator that did not exist in source.
                $rules[] = '.' . $marker . '>table>thead{border-bottom:0}';
            }
        }

        $this->layoutGeometry()->registerRule($marker, implode("\n", $rules));
    }

    private function registerTableCellGeometry(DOMElement $table): void
    {
        $rules = array();
        $sectionRows = array( 'thead' => 0, 'tbody' => 0, 'tfoot' => 0 );
        foreach ( $table->getElementsByTagName('tr') as $row ) {
            if ( ! $row instanceof DOMElement || ! SourceDom::belongsToTable($row, $table) ) {
                continue;
            }
            $section = $this->closestTagName($row);
            $section = isset($sectionRows[$section]) ? $section : 'tbody';
            $rowIndex = ++$sectionRows[$section];
            $cellIndex = 0;
            foreach ( $row->childNodes as $cell ) {
                if ( ! $cell instanceof DOMElement || ! in_array(strtolower($cell->tagName), array( 'td', 'th' ), true) ) {
                    continue;
                }
                ++$cellIndex;
                $declarations = $this->styleResolver->cssDeclarations($this->attr($cell, 'style'));
                $geometry = array();
                $width = trim((string) ($declarations['width'] ?? ''));
                if ( '' !== $width && preg_match('/^(?:\d+(?:\.\d+)?(?:%|px|em|rem|vw|ch)|calc\(.+\)|var\(.+\))$/i', $width) ) {
                    $geometry[] = 'width:' . $width . '!important';
                }
                foreach ( array( 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left' ) as $property ) {
                    $value = trim((string) ($declarations[$property] ?? ''));
                    if ( '' !== $value && preg_match('/^(?:-?\d+(?:\.\d+)?(?:px|em|rem|%|vw|vh|ch)?)(?:\s+-?\d+(?:\.\d+)?(?:px|em|rem|%|vw|vh|ch)?){0,3}$/i', $value) ) {
                        $geometry[] = $property . ':' . $value . '!important';
                    }
                }
                if ( array() !== $geometry ) {
                    $rules[] = $section . '>tr:nth-child(' . $rowIndex . ')>' . strtolower($cell->tagName) . ':nth-child(' . $cellIndex . '){' . implode(';', $geometry) . '}';
                }
            }
        }
        if ( array() === $rules ) {
            return;
        }

        $path = SourceDom::sourceElementIdentity($table);
        $marker = $this->authorSelectorProjections()->ensureTableMarker($path, $table);
        $scopedRules = array_map(static fn (string $rule): string => '.' . $marker . '>table>' . $rule, $rules);
        $this->layoutGeometry()->appendRule($marker, implode("\n", $scopedRules));
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function tableCells(DOMElement $row): array
    {
        $cells = array();
        $sourceMarkupMutated = false;
        foreach ( $row->childNodes as $cell ) {
            if ( ! $cell instanceof DOMElement || ! in_array(strtolower($cell->tagName), array( 'td', 'th' ), true) ) {
                continue;
            }
            foreach ( $cell->getElementsByTagName('*') as $descendant ) {
                if ( ! $descendant instanceof DOMElement ) {
                    continue;
                }
                $sourceTagName = strtolower($descendant->tagName);
                $sourceTagMarker = $this->authorSelectorProjections()->tagMarker($sourceTagName);
                if ( '' !== $sourceTagMarker ) {
                    $descendant->setAttribute('class', $this->mergeClassNames($this->attr($descendant, 'class'), $sourceTagMarker));
                    $sourceMarkupMutated = true;
                }
            }
            $entry = array(
                'content' => $this->innerHtml($cell),
                'tag'     => strtolower($cell->tagName),
            );
            // core/table cells carry their own spans, so merged and ragged
            // rows stay one editable table.
            foreach ( array( 'colspan', 'rowspan' ) as $span ) {
                $value = trim($this->attr($cell, $span));
                if ( 1 === preg_match('/^[1-9][0-9]*$/', $value) && '1' !== $value ) {
                    $entry[$span] = $value;
                }
            }
            $cells[] = $entry;
        }
        if ( $sourceMarkupMutated ) {
            $this->onSourceMarkupMutated();
        }
        return $cells;
    }

    private function attr(DOMElement $element, string $name): string
    {
        return SourceDom::attr($element, $name);
    }

    private function closestTagName(DOMElement $element): ?string
    {
        return SourceDom::closestTagName($element);
    }

    private function firstChildElement(DOMElement $element, string $tagName): ?DOMElement
    {
        return SourceDom::firstChildElement($element, $tagName);
    }

    private function innerHtml(DOMElement $element): string
    {
        return SourceDom::innerHtml($element);
    }

    private function mergeClassNames(string ...$classNames): string
    {
        return SourceDom::mergeClassNames(...$classNames);
    }

    private function layoutGeometry(): LayoutGeometryState
    {
        return $this->session->layoutGeometryState();
    }

    private function sourceStyles(): SourceStyleResolutionState
    {
        return $this->session->sourceStyleResolutionState();
    }

    private function authorSelectorProjections(): AuthorSelectorProjectionState
    {
        return $this->session->authorSelectorProjectionState();
    }
}
