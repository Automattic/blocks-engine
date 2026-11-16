<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;

/** Restyle an exact source-node carrier before provenance is discarded. */
final class BoxTreeStyleProjector
{
    public function project(array $attrs, array $observations, array $viewports, LayoutGeometryState $state): array
    {
        if (array() === $observations) return $attrs;
        $keys = array_keys($observations);
        usort($keys, static fn(string $a, string $b): int => ($viewports[$b]['width'] ?? 0) <=> ($viewports[$a]['width'] ?? 0));
        $base = $observations[$keys[0]]['used_styles'];
        $mapper = new StyleAttributeMapper();
        $existing = is_array($attrs['style'] ?? null) ? $attrs['style'] : array();
        $existingDeclarations = SourceDeclarations::parse($mapper->serialize($existing)['style']);
        // Capture v1 does not contain text typography. Keep properties outside
        // its vocabulary, but do not retain authored paint/spacing defaults.
        $unobserved = array_diff_key($existingDeclarations, $base);
        $mapped = $mapper->map(array_replace($unobserved, $this->nonDefaults($base)));
        $attrs['style'] = $mapped['style'];
        $attrs = array_replace($attrs, $mapped['attrs']);
        // Layout declarations are deliberately excluded by StyleAttributeMapper;
        // the captured layout belongs to the engine rule, not a core layout guess.
        $consumed = SourceDeclarations::parse($mapper->serialize($mapped['style'])['style']);
        // Border shorthands can serialize as longhands. Retain their captured
        // declarations unless the mapper round-trip represents the same key.
        $rules = $this->nonDefaults(array_diff_key($base, $consumed));
        $signature = json_encode(array($base, $observations, $viewports), JSON_THROW_ON_ERROR);
        $class = $state->allocateCarrier('box-tree:' . $signature);
        $selector = '.' . $class;
        $css = $this->rule($selector, $rules);
        foreach (array_slice($keys, 1) as $key) {
            $delta = array_diff_assoc($observations[$key]['used_styles'], $base);
            if (array() === $delta) continue;
            // Include resets to initial values: omission would leave the desktop value active.
            $css .= '@media (max-width:' . (int) $viewports[$key]['width'] . 'px){' . $this->rule($selector, $delta) . '}';
        }
        $state->registerRule($class, $css);
        $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $class);
        return $attrs;
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
            $declarations[] = $name . ':' . $value . '!important';
        }
        return $selector . '{' . implode(';', $declarations) . '}';
    }
}
