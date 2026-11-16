<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use DOMElement;

/** Atomic, hash-bound intake of route-local browser observations. */
final class BoxTreeReport
{
    public static function read(array $files): array
    {
        $sources = array();
        foreach ($files as $file) {
            if ('html' === ($file['kind'] ?? null)) $sources[$file['path']] = $file['content'];
        }
        $trees = $diagnostics = $seen = array();
        foreach ($files as $file) {
            if (!preg_match('~(?:^|/)box-tree/.+\.json$~D', $file['path'])) continue;
            $content = $file['content'] ?? '';
            $tree = strlen($content) <= BoxTree::MAX_BYTES ? json_decode($content, true, 32) : null;
            $code = self::validate($tree, $sources);
            if (null === $code) {
                $path = $tree['source_path'];
                if (isset($seen[$path])) {
                    unset($trees[$path]);
                    $code = 'box_tree_duplicate_route';
                }
                $seen[$path] = true;
            }
            if (null !== $code) {
                $diagnostics[] = ArtifactDiagnostics::make($code, 'warning', 'Box-tree evidence was rejected; conversion continues without it.', array('path' => $file['path']));
                continue;
            }
            $trees[$tree['source_path']] = $tree;
        }
        return array('trees' => $trees, 'diagnostics' => $diagnostics);
    }

    private static function validate(mixed $tree, array $sources): ?string
    {
        if (!is_array($tree) || BoxTree::SCHEMA !== ($tree['schema'] ?? null)) return 'box_tree_schema_invalid';
        $path = $tree['source_path'] ?? null;
        if (!is_string($path) || !isset($sources[$path]) || hash('sha256', $sources[$path]) !== ($tree['source_hash'] ?? null)) return 'box_tree_identity_stale';
        $viewports = $tree['viewports'] ?? null;
        $nodes = $tree['nodes'] ?? null;
        if (!is_array($viewports) || !$viewports || count($viewports) > BoxTree::MAX_VIEWPORTS || !is_array($nodes) || !$nodes || count($nodes) > BoxTree::MAX_NODES) return 'box_tree_bounds_invalid';
        foreach ($viewports as $name => $viewport) {
            if (!BoxTree::validId($name) || !is_array($viewport)) return 'box_tree_viewport_invalid';
            foreach (array('width', 'height') as $dimension) {
                $value = $viewport[$dimension] ?? null;
                if (!is_int($value) || $value < 1 || $value > 32768) return 'box_tree_viewport_invalid';
            }
        }
        $dom = HtmlSerialization::parseDocument($sources[$path]);
        $elements = array();
        foreach ($dom->getElementsByTagName('*') as $element) {
            $id = $element->getAttribute(BoxTree::NODE_ATTRIBUTE);
            if (!BoxTree::validId($id) || isset($elements[$id])) return 'box_tree_identity_stale';
            $elements[$id] = $element;
        }
        if (count($nodes) !== count($elements)) return 'box_tree_identity_stale';
        foreach ($nodes as $id => $node) {
            if (!isset($elements[$id]) || !is_array($node)) return 'box_tree_identity_stale';
            $children = array();
            foreach ($elements[$id]->childNodes as $child) {
                if ($child instanceof DOMElement) $children[] = $child->getAttribute(BoxTree::NODE_ATTRIBUTE);
            }
            if ($children !== ($node['children'] ?? null)) return 'box_tree_identity_stale';
            if (array_key_exists('interaction_states', $node)) {
                if (!is_array($node['interaction_states']) || !array_is_list($node['interaction_states']) || count($node['interaction_states']) > 32) return 'box_tree_states_invalid';
                foreach ($node['interaction_states'] as $state) {
                    if (!BoxTree::validId($state)) return 'box_tree_states_invalid';
                }
            }
            $observations = $node['viewports'] ?? null;
            if (!is_array($observations) || count($observations) !== count($viewports)) return 'box_tree_viewport_invalid';
            foreach ($viewports as $name => $_) {
                $observation = $observations[$name] ?? null;
                if (!is_array($observation)) return 'box_tree_viewport_invalid';
                $code = self::validateObservation($observation);
                if (null !== $code) return $code;
                if (array_key_exists('pseudo', $observation)) {
                    $pseudo = $observation['pseudo'];
                    if (!is_array($pseudo) || array_diff(array_keys($pseudo), array('before', 'after'))) return 'box_tree_pseudo_invalid';
                    foreach ($pseudo as $value) {
                        if (!is_array($value) || array_diff(array_keys($value), array('content', 'border_box', 'used_styles'))
                            || !is_string($value['content'] ?? null) || '' === $value['content'] || strlen($value['content']) > 4096
                            || in_array(strtolower(trim($value['content'])), array('none', 'normal'), true)) return 'box_tree_pseudo_invalid';
                        $code = self::validateObservation($value);
                        if (null !== $code) return $code;
                    }
                }
            }
        }
        return null;
    }

    private static function validateObservation(array $observation): ?string
    {
        $box = $observation['border_box'] ?? null;
        if (!is_array($box) || count($box) !== 4) return 'box_tree_geometry_invalid';
        foreach (array('x', 'y', 'width', 'height') as $axis) {
            $value = $box[$axis] ?? null;
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || abs($value) > 100000000 || (in_array($axis, array('width', 'height'), true) && $value < 0)) return 'box_tree_geometry_invalid';
        }
        $styles = $observation['used_styles'] ?? null;
        if (!is_array($styles) || array_diff(array_keys($styles), array_merge(BoxTree::PROPERTIES, BoxTree::MOTION_PROPERTIES))) return 'box_tree_styles_invalid';
        foreach (BoxTree::PROPERTIES as $property) {
            if (!is_string($styles[$property] ?? null) || '' === $styles[$property] || strlen($styles[$property]) > 4096) return 'box_tree_styles_invalid';
        }
        foreach (BoxTree::MOTION_PROPERTIES as $property) {
            if (array_key_exists($property, $styles) && (!is_string($styles[$property]) || '' === $styles[$property] || strlen($styles[$property]) > 4096)) return 'box_tree_styles_invalid';
        }
        return null;
    }

    public static function coverage(array $files): array
    {
        $reports = array();
        foreach ($files as $file) {
            if ('html' !== ($file['kind'] ?? null)) continue;
            $total = HtmlSerialization::parseDocument($file['content'])->getElementsByTagName('*')->length;
            $count = count($file['box_tree']['nodes'] ?? array());
            $reports[$file['path']] = array('nodes_with_evidence' => $count, 'source_nodes' => $total, 'coverage' => $total > 0 ? $count / $total : 0.0,
                'classification' => BoxTreeClassifier::report($file['content'], $file['box_tree'] ?? array()));
        }
        return $reports;
    }
}
