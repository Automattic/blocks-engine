<?php

declare(strict_types=1);

use Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter;

/**
 * @param callable(bool, string): void $assert
 */
function blocks_engine_figma_transformer_run_child_emission_order_contract(callable $assert): void
{
    $card = static function (string $id, string $label, float $x, float $y, array $layout = array(), float $width = 180): array {
        return array(
            'id' => $id,
            'type' => 'FRAME',
            'name' => $label,
            'box' => array('x' => $x, 'y' => $y, 'width' => $width, 'height' => 120),
            'layout' => $layout,
            'children' => array(array('id' => $id . ':text', 'type' => 'TEXT', 'name' => 'Title', 'characters' => $label)),
        );
    };
    $emit = static function (array $children, array $layout = array()) use ($card): string {
        $result = (new StaticHtmlEmitter())->emit(array(
            'name' => 'Child emission order fixture',
            'nodes' => array(array(
                'id' => 'order:articles',
                'type' => 'FRAME',
                'name' => 'Articles',
                'box' => array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 300),
                'layout' => $layout,
                'children' => $children,
            )),
        ));
        return (string) ($result['files'][0]['content'] ?? '');
    };
    $position = static fn (string $html, string $id): int|false => strpos($html, 'data-figma-node-id="' . $id . '"');

    $inferredGridHtml = $emit(array(
        $card('order:right', 'Right article', 210, 20),
        $card('order:left', 'Left article', 10, 20),
    ), array('freeform' => true));
    $assert($position($inferredGridHtml, 'order:left') < $position($inferredGridHtml, 'order:right'), 'child-emission-order-inferred-grid-uses-visual-left-to-right-order');

    $insetGridResult = (new StaticHtmlEmitter())->emit(array(
        'name' => 'Inferred grid geometry fixture',
        'nodes' => array(array(
            'id' => 'order:inset-articles',
            'type' => 'FRAME',
            'name' => 'Articles',
            'box' => array('x' => 0, 'y' => 0, 'width' => 500, 'height' => 300),
            'layout' => array('freeform' => true),
            'children' => array(
                $card('order:inset-right', 'Right article', 330, 20, array(), 140),
                $card('order:inset-left', 'Left article', 40, 20),
            ),
        )),
    ));
    $insetGridCss = (string) ($insetGridResult['files'][1]['content'] ?? '');
    $leftRule = blocks_engine_figma_transformer_contract_css_rule($insetGridCss, '.figma-node-order-inset-left-left-article');
    $rightRule = blocks_engine_figma_transformer_contract_css_rule($insetGridCss, '.figma-node-order-inset-right-right-article');
    $assert(str_contains($leftRule, 'margin-left:40px'), 'child-emission-order-inferred-grid-preserves-leading-source-inset');
    $assert(str_contains($rightRule, 'margin-left:25px'), 'child-emission-order-inferred-grid-preserves-unequal-track-placement');

    $equalPositionHtml = $emit(array(
        $card('order:first', 'First article', 10, 20),
        $card('order:second', 'Second article', 10, 20),
    ));
    $assert($position($equalPositionHtml, 'order:first') < $position($equalPositionHtml, 'order:second'), 'child-emission-order-equal-geometry-keeps-source-order');

    $explicitAutoLayoutHtml = $emit(array(
        $card('order:explicit-right', 'Right article', 210, 20),
        $card('order:explicit-left', 'Left article', 10, 20),
    ), array('display' => 'flex', 'flex_direction' => 'row'));
    $assert($position($explicitAutoLayoutHtml, 'order:explicit-right') < $position($explicitAutoLayoutHtml, 'order:explicit-left'), 'child-emission-order-explicit-auto-layout-keeps-source-order');

    $absoluteHtml = $emit(array(
        $card('order:absolute', 'Absolute article', 210, 20, array('positioning' => 'absolute')),
        $card('order:flow-left', 'Left article', 10, 20),
        $card('order:flow-right', 'Right article', 210, 20),
    ));
    $assert($position($absoluteHtml, 'order:absolute') < $position($absoluteHtml, 'order:flow-left'), 'child-emission-order-absolute-child-keeps-source-layer-position');

    $decorativeHtml = $emit(array(
        array('id' => 'order:underlay', 'type' => 'RECTANGLE', 'name' => 'Decorative underlay', 'box' => array('x' => 0, 'y' => 0, 'width' => 500, 'height' => 280)),
        $card('order:decorative-right', 'Right article', 210, 20),
        $card('order:decorative-left', 'Left article', 10, 20),
    ));
    $assert($position($decorativeHtml, 'order:underlay') < $position($decorativeHtml, 'order:decorative-right'), 'child-emission-order-decorative-child-keeps-source-layer-position');
}
