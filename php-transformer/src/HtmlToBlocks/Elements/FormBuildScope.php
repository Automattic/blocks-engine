<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormStepTrackDetector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;
use WeakMap;

/**
 * One form dispatch's shared derivations (#6752).
 *
 * The readable block, its fallback finding and the control inventory both of
 * them read each derive the same facts from the same form: its surviving
 * control inventory and its layout graphs. Inside a dispatch they are
 * computed once and shared, so a form's layout graph is built once per
 * compile instead of once per reader. Outside a scope every call computes
 * afresh, so no derivation outlives the dispatch that read the DOM it holds.
 */
final class FormBuildScope
{
    /** @var WeakMap<DOMElement, array<string, array{inputs: array<mixed>, value: mixed}>>|null */
    private static ?WeakMap $memo = null;

    private static int $depth = 0;

    /**
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    public static function run(Closure $work): mixed
    {
        if ( 0 === self::$depth++ ) {
            self::$memo = new WeakMap();
        }
        try {
            return SourceDom::withLabelIndex($work);
        } finally {
            if ( 0 === --self::$depth ) {
                self::$memo = null;
            }
        }
    }

    /**
     * The form's derivation named `$key`, computed once per scope for the
     * same inputs (compared strictly, so DOM elements by identity).
     *
     * @template T
     * @param array<mixed> $inputs
     * @param Closure(): T $compute
     * @return T
     */
    public static function remember(DOMElement $form, string $key, array $inputs, Closure $compute): mixed
    {
        if ( null === self::$memo ) {
            return $compute();
        }
        $entries = self::$memo[$form] ?? array();
        if ( isset($entries[$key]) && $entries[$key]['inputs'] === $inputs ) {
            return $entries[$key]['value'];
        }
        $value = $compute();
        // The computation may itself remember other derivations of the form.
        $entries = self::$memo[$form] ?? array();
        $entries[$key] = array( 'inputs' => $inputs, 'value' => $value );
        self::$memo[$form] = $entries;

        return $value;
    }

    /**
     * Step-track detection shared by the inventory and fallback finding.
     *
     * @param array<string, mixed> $graph
     * @param list<array<string, mixed>> $stylesheets
     * @return array<string, mixed>|null
     */
    public static function stepTrack(DOMElement $form, array $graph, array $stylesheets, string $inlineCss): ?array
    {
        return self::remember($form, 'step_track', array( $graph, $stylesheets, $inlineCss ), static fn (): ?array => (new FormStepTrackDetector())->detect($form, $graph, $stylesheets, $inlineCss));
    }

    /**
     * The form's layout graph over `$controlElements` (or its presented
     * controls when null), shared within a scope. A graph reads its controls
     * only through the boxes that present them, so inventories presented by
     * the same boxes share one graph.
     *
     * @param list<DOMElement>|null $controlElements
     * @param list<array<string, mixed>> $stylesheets
     * @param list<array<string, mixed>>|null $structure The graph's in-process node list.
     * @return array<string, mixed>
     */
    public static function layoutGraph(DOMElement $form, ?array $controlElements, array $stylesheets, string $inlineCss, ?array &$structure = null): array
    {
        $build = static function () use ($form, $controlElements, $stylesheets, $inlineCss): array {
            $nodes = array();
            $graph = (new FormLayoutGraphBuilder($controlElements))->build($form, $stylesheets, $inlineCss, $nodes);

            return array( $graph, $nodes );
        };
        if ( null === self::$memo ) {
            [ $graph, $structure ] = $build();
            return $graph;
        }
        $presented = null === $controlElements
            ? FormControlClassifier::presentedControlElements($form)
            : array_map(FormControlClassifier::presentingElement(...), $controlElements);
        [ $graph, $structure ] = self::remember(
            $form,
            'layout_graph:' . implode(',', array_map(spl_object_id(...), $presented)),
            array( $presented, $stylesheets, $inlineCss ),
            $build
        );

        return $graph;
    }
}
