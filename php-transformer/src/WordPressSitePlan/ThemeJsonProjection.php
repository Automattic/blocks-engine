<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter;

/** Projects source CSS that has an exact Global Styles equivalent. */
final class ThemeJsonProjection
{
    /**
     * Only global element selectors are removed from carrier CSS. Class, state,
     * and responsive selectors remain authored CSS because theme.json cannot
     * reproduce their cascade semantics.
     *
     * @param array<int,array<string,mixed>> $assets
     * @return array{assets:array<int,array<string,mixed>>,theme:array<string,mixed>,provenance:array<int,array<string,mixed>>,presets:array<string,array<string,string>>}
     */
    public function project(array $assets): array
    {
        $candidates = array();
        foreach ($assets as $assetIndex => $asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null)) continue;
            $path = (string) ($asset['source_path'] ?? $asset['path'] ?? '');
            $hash = (string) ($asset['content_hash'] ?? $asset['hash'] ?? hash('sha256', $asset['content']));
            (new CssStylesheetTransformer())->transformTopLevelStyleRules($asset['content'], function (string $prelude, string $body) use (&$candidates, $assetIndex, $path, $hash): string {
                $selector = strtolower(trim($prelude));
                $target = $this->target($selector);
                if (null === $target) return $prelude . '{' . $body . '}';
                foreach ($this->declarations($body) as $name => $value) {
                    if ($this->representable($target, $name, $value)) $candidates[] = array('asset' => $assetIndex, 'path' => $path, 'hash' => $hash, 'selector' => $selector, 'target' => $target, 'property' => $name, 'value' => $value);
                }
                return $prelude . '{' . $body . '}';
            });
        }

        $counts = array_count_values(array_map(static fn(array $candidate): string => $candidate['property'] . "\n" . strtolower($candidate['value']), $candidates));
        $selected = array_values(array_filter($candidates, static fn(array $candidate): bool => 1 < $counts[$candidate['property'] . "\n" . strtolower($candidate['value'])] || 'body' === $candidate['target'] || 'layout' === $candidate['target'] || str_starts_with($candidate['target'], 'element:')));
        $presets = $this->presets($selected);

        return array('assets' => $assets, 'theme' => $this->theme($selected, $presets), 'provenance' => array_values(array_map(static fn(array $candidate): array => array('source_path' => $candidate['path'], 'source_hash' => $candidate['hash'], 'selector' => $candidate['selector'], 'property' => $candidate['property'], 'value' => $candidate['value']), $selected)), 'presets' => $presets);
    }

    /** @return array<string,string>|null */
    private function target(string $selector): ?string
    {
        if ('body' === $selector) return 'body';
        if ('main' === $selector) return 'layout';
        if (preg_match('/^h([1-6])$/', $selector, $match)) return 'element:h' . $match[1];
        return in_array($selector, array('a', 'button'), true) ? 'element:' . $selector : null;
    }

    /** @return array<string,string> */
    private function declarations(string $body): array
    {
        $declarations = array();
        foreach (CssValueSplitter::splitTopLevel($body, array(';')) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (2 !== count($parts)) continue;
            $name = strtolower(trim($parts[0])); $value = trim($parts[1]);
            if ('' !== $name && '' !== $value && !str_contains(strtolower($value), '!important')) $declarations[$name] = $value;
        }
        return $declarations;
    }

    private function representable(string $target, string $property, string $value): bool
    {
        if (str_contains($value, 'var(') || str_contains($value, 'calc(')) return false;
        if (in_array($property, array('color', 'background-color'), true)) return (bool) preg_match('/^(?:#[0-9a-f]{3,8}|(?:rgb|rgba|hsl|hsla)\([^;]+\)|[a-z]+)$/i', $value);
        if (in_array($property, array('font-family', 'font-size', 'line-height', 'font-weight', 'letter-spacing', 'text-transform', 'font-style'), true)) return true;
        if ('body' === $target && in_array($property, array('padding', 'margin', 'gap'), true)) return (bool) preg_match('/^[0-9.]+(?:px|rem|em|%|vw|vh)$/i', $value);
        if ('layout' === $target && 'max-width' === $property) return (bool) preg_match('/^[0-9.]+(?:px|rem|em|%|vw|vh)$/i', $value);
        return false;
    }

    /** @param array<int,array<string,mixed>> $selected @return array<string,array<string,string>> */
    private function presets(array $selected): array
    {
        $presets = array('color' => array(), 'font-family' => array(), 'font-size' => array(), 'spacing' => array());
        foreach ($selected as $candidate) {
            $group = match ($candidate['property']) {
                'color', 'background-color' => 'color', 'font-family' => 'font-family', 'font-size' => 'font-size', 'padding', 'margin', 'gap' => 'spacing', default => '',
            };
            if ('' !== $group) $presets[$group][$candidate['value']] = $this->slug($group, $candidate['value']);
        }
        foreach ($presets as &$values) ksort($values, SORT_STRING); unset($values);
        return $presets;
    }

    /** @param array<int,array<string,mixed>> $selected @param array<string,array<string,string>> $presets @return array<string,mixed> */
    private function theme(array $selected, array $presets): array
    {
        $settings = array();
        if (array() !== $presets['color']) $settings['color']['palette'] = array_map(static fn(string $value, string $slug): array => array('slug' => $slug, 'name' => $slug, 'color' => $value), array_keys($presets['color']), $presets['color']);
        if (array() !== $presets['font-family']) $settings['typography']['fontFamilies'] = array_map(static fn(string $value, string $slug): array => array('slug' => $slug, 'name' => $slug, 'fontFamily' => $value), array_keys($presets['font-family']), $presets['font-family']);
        if (array() !== $presets['font-size']) $settings['typography']['fontSizes'] = array_map(static fn(string $value, string $slug): array => array('slug' => $slug, 'name' => $slug, 'size' => $value), array_keys($presets['font-size']), $presets['font-size']);
        if (array() !== $presets['spacing']) $settings['spacing']['spacingSizes'] = array_map(static fn(string $value, string $slug): array => array('slug' => $slug, 'name' => $slug, 'size' => $value), array_keys($presets['spacing']), $presets['spacing']);
        $styles = array();
        foreach ($selected as $candidate) {
            $target = $candidate['target']; $property = $candidate['property']; $value = $candidate['value'];
            if ('layout' === $target) { $settings['layout']['contentSize'] = $value; continue; }
            $destination = 'body' === $target ? $styles : ($styles['elements'][substr($target, 8)] ?? array());
            if ('color' === $property) $destination['color']['text'] = $this->presetValue($presets['color'], $value, 'color');
            elseif ('background-color' === $property) $destination['color']['background'] = $this->presetValue($presets['color'], $value, 'color');
            elseif ('font-family' === $property) $destination['typography']['fontFamily'] = $this->presetValue($presets['font-family'], $value, 'font-family');
            elseif ('font-size' === $property) $destination['typography']['fontSize'] = $this->presetValue($presets['font-size'], $value, 'font-size');
            elseif (in_array($property, array('line-height', 'font-weight', 'letter-spacing', 'text-transform', 'font-style'), true)) $destination['typography'][str_replace(array('line-height', 'font-weight', 'letter-spacing', 'text-transform', 'font-style'), array('lineHeight', 'fontWeight', 'letterSpacing', 'textTransform', 'fontStyle'), $property)] = $value;
            elseif ('body' === $target) $destination['spacing'][('gap' === $property ? 'blockGap' : $property)] = $this->presetValue($presets['spacing'], $value, 'spacing');
            if ('body' === $target) $styles = $destination; else $styles['elements'][substr($target, 8)] = $destination;
        }
        return array('version' => 3, 'settings' => $settings, 'styles' => $styles);
    }

    /** @param array<string,string> $presets */
    private function presetValue(array $presets, string $value, string $type): string { return isset($presets[$value]) ? 'var:preset|' . $type . '|' . $presets[$value] : $value; }
    private function slug(string $group, string $value): string
    {
        $digest = substr(hash('sha256', strtolower($value)), 0, 10);
        return $group . '-' . (preg_replace('/(?<=\d)(?=[a-f])|(?<=[a-f])(?=\d)/', '-', $digest) ?? $digest);
    }
}
