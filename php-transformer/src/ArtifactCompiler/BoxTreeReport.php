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
            if ('html' === ($file['kind'] ?? null)) $sources[$file['path']] = $file;
        }
        $trees = $diagnostics = $seen = array();
        foreach ($files as $file) {
            if (!BoxTree::isSidecarPath($file['path'])) continue;
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
                $diagnostics[] = ArtifactDiagnostics::make($code, 'warning', 'Box-tree evidence was rejected; conversion continues without it.', array_filter(array('path' => $file['path'], 'source_path' => is_array($tree) && is_string($tree['source_path'] ?? null) ? $tree['source_path'] : null)));
                continue;
            }
            $trees[$tree['source_path']] = $tree;
        }
        return array('trees' => self::withSharedChromeViewports($trees, $sources), 'diagnostics' => $diagnostics);
    }

    /**
     * A producer may sample extra viewports on some routes only (mobile HTML
     * per template cluster). Chrome every route repeats is one part, so its
     * identity cannot depend on that sampling: within the subtree of a
     * header or footer whose source identity other routes share, a route
     * keeps only the viewports every sharing route observed. The rest of the
     * route, its page content, keeps all of its evidence.
     *
     * @param array<string,array<string,mixed>> $trees @param array<string,array<string,mixed>> $sources
     * @return array<string,array<string,mixed>>
     */
    private static function withSharedChromeViewports(array $trees, array $sources): array
    {
        if (count($trees) < 2) return $trees;
        $groups = array();
        foreach ($trees as $path => $tree) {
            $dom = HtmlSerialization::parseDocument((string) ($sources[$path]['content'] ?? ''));
            $body = $dom->getElementsByTagName('body')->item(0);
            if (!$body instanceof DOMElement) continue;
            $walk = static function (DOMElement $parent) use (&$walk, &$groups, $dom, $path): void {
                foreach ($parent->childNodes as $child) {
                    if (!$child instanceof DOMElement) continue;
                    $tag = strtolower($child->tagName);
                    if (in_array($tag, array('main', 'article', 'section', 'aside'), true)) continue;
                    $area = \Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy::landmarkKind($tag, trim($child->getAttribute('role')));
                    if (!in_array($area, array('header', 'footer'), true)) {
                        $walk($child);
                        continue;
                    }
                    $ids = array();
                    foreach (array_merge(array($child), iterator_to_array($child->getElementsByTagName('*'))) as $node) {
                        if ($node instanceof DOMElement && $node->hasAttribute(BoxTree::NODE_ATTRIBUTE)) $ids[] = $node->getAttribute(BoxTree::NODE_ATTRIBUTE);
                    }
                    if (array() === $ids) continue;
                    $identity = hash('sha256', \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ChromeIdentity::markup(SourceShells::identity((string) $dom->saveHTML($child))));
                    $groups[$area . ':' . $identity][$path][] = $ids;
                }
            };
            $walk($body);
        }
        foreach ($groups as $members) {
            if (count($members) < 2) continue;
            $shared = null;
            foreach (array_keys($members) as $path) $shared = null === $shared ? $trees[$path]['viewports'] : array_intersect_key($shared, $trees[$path]['viewports']);
            if (array() === $shared) continue;
            foreach ($members as $path => $landmarks) {
                if (array() === array_diff_key($trees[$path]['viewports'], $shared)) continue;
                foreach (array_merge(...$landmarks) as $id) {
                    if (isset($trees[$path]['nodes'][$id]['viewports'])) $trees[$path]['nodes'][$id]['viewports'] = array_intersect_key($trees[$path]['nodes'][$id]['viewports'], $shared);
                }
            }
        }
        return $trees;
    }

    private static function validate(mixed $tree, array $sources): ?string
    {
        if (!is_array($tree) || BoxTree::SCHEMA !== ($tree['schema'] ?? null)) return 'box_tree_schema_invalid';
        $path = $tree['source_path'] ?? null;
        if (!is_string($path) || !isset($sources[$path])) return 'box_tree_identity_stale';
        $source = $sources[$path];
        $compilation = $source['metadata']['compilation'] ?? array();
        if (!empty($compilation['unresolved_html_includes'])) return 'box_tree_identity_stale';
        $sourceHash = $compilation['box_tree_source_hash'] ?? hash('sha256', $source['content']);
        $includes = $tree['includes'] ?? array();
        $expectedIncludes = $compilation['box_tree_include_hashes'] ?? array();
        if ($sourceHash !== ($tree['source_hash'] ?? null) || !is_array($includes) || count($includes) !== count($expectedIncludes)) return 'box_tree_identity_stale';
        foreach ($expectedIncludes as $part => $hash) {
            if (($includes[$part] ?? null) !== $hash) return 'box_tree_identity_stale';
        }
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
        $dom = HtmlSerialization::parseDocument($source['content']);
        $elements = $counts = array();
        foreach ($dom->getElementsByTagName('*') as $element) {
            if (!$element->hasAttribute(BoxTree::NODE_ATTRIBUTE)) continue;
            $id = $element->getAttribute(BoxTree::NODE_ATTRIBUTE);
            $counts[$id] = ($counts[$id] ?? 0) + 1;
            $elements[$id] = $element;
        }
        $elements = array_filter($elements, static fn(DOMElement $element, string|int $id): bool => 1 === $counts[$id] && array_key_exists($id, $nodes), ARRAY_FILTER_USE_BOTH);
        if (count($nodes) !== count($elements)) return 'box_tree_identity_stale';
        $childrenByParent = array();
        foreach ($elements as $id => $element) {
            $parent = $element->parentNode;
            while ($parent instanceof DOMElement && !isset($elements[$parent->getAttribute(BoxTree::NODE_ATTRIBUTE)])) $parent = $parent->parentNode;
            if ($parent instanceof DOMElement) $childrenByParent[$parent->getAttribute(BoxTree::NODE_ATTRIBUTE)][] = $id;
        }
        foreach ($nodes as $id => $node) {
            if (!BoxTree::validId($id) || !isset($elements[$id]) || !is_array($node)) return 'box_tree_identity_stale';
            $children = $childrenByParent[$id] ?? array();
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
