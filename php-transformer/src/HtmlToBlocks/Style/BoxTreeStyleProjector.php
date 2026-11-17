<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;

/** Restyle an exact source-node carrier before provenance is discarded. */
final class BoxTreeStyleProjector
{
    public function project(array $attrs, array $observations, array $viewports, LayoutGeometryState $state, array $parents = array()): array
    {
        if (array() === $observations) return $attrs;
        $keys = array_keys($observations);
        usort($keys, static fn(string $a, string $b): int => ($viewports[$b]['width'] ?? 0) <=> ($viewports[$a]['width'] ?? 0));
        $styles = array();
        foreach ($observations as $key => $observation) {
            $styles[$key] = $observation['used_styles'];
            foreach (BoxTree::INHERITED_PROPERTIES as $property) {
                if (isset($parents[$key]['used_styles'][$property]) && $styles[$key][$property] === $parents[$key]['used_styles'][$property]) unset($styles[$key][$property]);
            }
        }
        $base = $styles[$keys[0]];
        $mapper = new StyleAttributeMapper();
        $existing = is_array($attrs['style'] ?? null) ? $attrs['style'] : array();
        $existingDeclarations = SourceDeclarations::parse($mapper->serialize($existing)['style']);
        // Never resurrect authored values for observed (including inherited) properties.
        $unobserved = array_diff_key($existingDeclarations, $observations[$keys[0]]['used_styles']);
        $mapped = $mapper->map(array_replace($unobserved, $this->nonDefaults($base)));
        $attrs['style'] = $mapped['style'];
        $attrs = array_replace($attrs, $mapped['attrs']);
        // Layout declarations are deliberately excluded by StyleAttributeMapper;
        // the captured layout belongs to the engine rule, not a core layout guess.
        $consumed = SourceDeclarations::parse($mapper->serialize($mapped['style'])['style']);
        // Border shorthands can serialize as longhands. Retain their captured
        // declarations unless the mapper round-trip represents the same key.
        $rules = $this->nonDefaults(array_diff_key($base, $consumed));
        $signature = json_encode(array($base, $observations, $viewports, $parents), JSON_THROW_ON_ERROR);
        $class = $state->allocateCarrier('box-tree:' . $signature);
        // Outrank core block/layout selectors without defeating editor inline styles.
        $selector = '.' . $class . '.' . $class;
        $css = $this->rule($selector, $rules) . $this->pseudoRules($selector, $observations[$keys[0]]);
        foreach (array_slice($keys, 1) as $key) {
            $delta = array_diff_assoc($styles[$key], $base);
            foreach (array_diff_key($base, $styles[$key]) as $property => $_) $delta[$property] = 'inherit';
            $pseudo = $this->pseudoRules($selector, $observations[$key], $observations[$keys[0]]);
            if (array() === $delta && '' === $pseudo) continue;
            // Include resets to initial values: omission would leave the desktop value active.
            $css .= '@media (max-width:' . (int) $viewports[$key]['width'] . 'px){' . $this->rule($selector, $delta) . $pseudo . '}';
        }
        $state->registerRule($class, $css);
        $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $class);
        return $attrs;
    }

    private function pseudoRules(string $selector, array $observation, array $base = array()): string
    {
        $css = '';
        foreach (array('before', 'after') as $name) {
            $pseudo = $observation['pseudo'][$name] ?? null;
            if (null !== $pseudo) {
                $styles = $pseudo['used_styles'];
                // Pseudos inherit from their originating element, not its parent.
                foreach (BoxTree::INHERITED_PROPERTIES as $property) {
                    if ($styles[$property] === $observation['used_styles'][$property]) {
                        if (isset($base['pseudo'][$name])) $styles[$property] = 'inherit';
                        else unset($styles[$property]);
                    }
                }
                $css .= $this->rule($selector . '::' . $name, array_merge($styles, array('content' => $pseudo['content'])));
            } elseif (isset($base['pseudo'][$name])) {
                $css .= $this->rule($selector . '::' . $name, array('content' => 'none'));
            }
        }
        return $css;
    }

    private function nonDefaults(array $styles): array
    {
        $defaults = array('position' => 'static', 'box-sizing' => 'content-box', 'opacity' => '1', 'z-index' => 'auto', 'transform' => 'none', 'box-shadow' => 'none', 'background-image' => 'none', 'background-color' => 'rgba(0, 0, 0, 0)', 'filter' => 'none', 'mask-image' => 'none', 'mix-blend-mode' => 'normal', 'overflow-x' => 'visible', 'overflow-y' => 'visible');
        return array_filter($styles, static fn(string $value, string $name): bool => '' !== $value && 'initial' !== $value && 'inherit' !== $value && ($defaults[$name] ?? null) !== $value, ARRAY_FILTER_USE_BOTH);
    }

    private function rule(string $selector, array $styles): string
    {
        if (array() === $styles) return '';
        $declarations = array();
        foreach ($styles as $name => $value) {
            // Evidence is external input, not trusted stylesheet syntax.
            if (preg_match('/[{};<>]/', $value) || str_contains($value, '/*')) continue;
            $declarations[] = $name . ':' . $value;
        }
        return $selector . '{' . implode(';', $declarations) . '}';
    }
}
