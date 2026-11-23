<?php
declare(strict_types=1);

/**
 * Builds `blocks-engine/box-tree/v2` sidecars for contract tests.
 *
 * Every node defaults to a visible, unpainted, static block with the used
 * styles a browser reports for an unstyled `div`; a test overrides only the
 * properties and boxes its case is about.
 */

use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;

const BOX_TREE_FIXTURE_VIEWPORTS = array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 390, 'height' => 844));

/** @return array<string, string> */
function box_tree_fixture_styles(): array
{
    $styles = array_fill_keys(BoxTree::PROPERTIES, 'normal');
    $defaults = array(
        'display' => 'block', 'position' => 'static', 'box-sizing' => 'content-box', 'float' => 'none', 'clear' => 'none', 'z-index' => 'auto',
        'top' => 'auto', 'right' => 'auto', 'bottom' => 'auto', 'left' => 'auto',
        'overflow-x' => 'visible', 'overflow-y' => 'visible', 'visibility' => 'visible',
        'background-color' => 'rgba(0, 0, 0, 0)', 'background-image' => 'none',
        'border-top' => '0px none rgb(0, 0, 0)', 'border-right' => '0px none rgb(0, 0, 0)', 'border-bottom' => '0px none rgb(0, 0, 0)', 'border-left' => '0px none rgb(0, 0, 0)',
        'border-radius' => '0px', 'outline' => 'rgb(0, 0, 0) none 0px', 'box-shadow' => 'none', 'opacity' => '1',
        'transform' => 'none', 'filter' => 'none', 'mask-image' => 'none', 'clip-path' => 'none', 'backdrop-filter' => 'none',
        'width' => 'auto', 'height' => 'auto', 'min-width' => 'auto', 'max-width' => 'none', 'min-height' => 'auto', 'max-height' => 'none',
    );
    foreach (array('padding', 'margin') as $box) {
        foreach (array('top', 'right', 'bottom', 'left') as $side) $defaults[$box . '-' . $side] = '0px';
    }
    return array_replace($styles, $defaults);
}

/**
 * @param array<string, array{box?: array<int, float|int>, styles?: array<string, string>, viewports?: array<string, array{box?: array<int, float|int>, styles?: array<string, string>}>}> $nodes
 *        Per node id: a default border box `[x, y, width, height]`, style overrides, and per-viewport overrides.
 * @return array{path: string, kind: string, content: string}
 */
function box_tree_fixture_sidecar(string $sourcePath, string $html, array $nodes, string $path = ''): array
{
    $records = array();
    foreach ($nodes as $id => $node) {
        $observations = array();
        foreach (BOX_TREE_FIXTURE_VIEWPORTS as $viewport => $size) {
            $override = $node['viewports'][$viewport] ?? array();
            $box = $override['box'] ?? $node['box'] ?? array(0, 0, $size['width'], 100);
            $observations[$viewport] = array(
                'border_box' => array('x' => $box[0], 'y' => $box[1], 'width' => $box[2], 'height' => $box[3]),
                'used_styles' => array_replace(box_tree_fixture_styles(), $node['styles'] ?? array(), $override['styles'] ?? array()),
            );
        }
        $records[(string) $id] = array('children' => array(), 'viewports' => $observations);
    }
    $tree = array(
        'schema' => BoxTree::SCHEMA,
        'source_path' => $sourcePath,
        'source_hash' => hash('sha256', $html),
        'viewports' => BOX_TREE_FIXTURE_VIEWPORTS,
        'nodes' => $records,
    );
    return array('path' => '' !== $path ? $path : 'box-tree/' . preg_replace('/\.html$/', '', $sourcePath) . '.json', 'kind' => 'json', 'content' => (string) json_encode($tree, JSON_THROW_ON_ERROR));
}
