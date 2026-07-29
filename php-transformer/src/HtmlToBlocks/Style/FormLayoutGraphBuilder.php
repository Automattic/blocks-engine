<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use DOMElement;

/** Builds a bounded, declared-CSS layout projection without browser geometry. */
final class FormLayoutGraphBuilder
{
    private const PROPERTIES = array('display', 'grid-template-columns', 'grid-template-rows', 'gap', 'row-gap', 'column-gap', 'grid-column', 'grid-row', 'grid-area', 'flex-direction', 'flex-wrap', 'align-items', 'align-content', 'justify-content', 'align-self', 'justify-self', 'order', 'flex', 'flex-grow', 'flex-shrink', 'flex-basis');

    /** @var list<string> */
    private array $parsingDiagnostics = array();

    /** @param list<array{path:string,content:string,source_hash:string}> $stylesheets @return array<string,mixed> */
    public function build(DOMElement $form, array $stylesheets, string $inlineCss = ''): array
    {
        $this->parsingDiagnostics = array();
        $rules = $this->rules($stylesheets, $inlineCss);
        $controls = array(); $relevant = array();
        foreach ($this->controls($form) as $index => $control) {
            $controls[$control->getNodePath()] = $index;
            for ($node = $control->parentNode; $node instanceof DOMElement && $node !== $form; $node = $node->parentNode) $relevant[$node->getNodePath()] = true;
        }
        $elements = array(); $wrapper = 0; $truncated = false;
        $this->collect($form, null, 0, 0, $controls, $relevant, $elements, $wrapper, $truncated);
        $nodes = array(); $variants = array(); $diagnostics = array();
        foreach ($elements as $entry) {
            $matched = $this->matched($entry['element'], $rules, $diagnostics);
            $layout = $this->layout($matched['base']);
            $conditional = $this->conditional($matched['conditional']);
            if (array() === $layout && array() === $conditional) continue;
            $nodes[$entry['id']] = array_filter(array(
                'id' => $entry['id'], 'kind' => $entry['kind'], 'parent' => $entry['parent'], 'order' => $entry['order'],
                'source' => $this->source($entry['element']), 'layout' => $layout,
                'provenance' => $this->provenance($matched['base']),
            ), static fn(mixed $value): bool => null !== $value && array() !== $value);
            foreach ($conditional as $condition => $ruleset) {
                $patch = $this->layout($ruleset);
                if (array() === $patch) continue;
                $variants[] = array('node' => $entry['id'], 'condition' => json_decode($condition, true), 'layout_patch' => $patch, 'provenance' => $this->provenance($ruleset));
            }
        }
        return array(
            'schema' => 'generic/computed-layout-graph/v1', 'basis' => 'source_css_cascade', 'truncated' => $truncated,
            'limits' => array('nodes' => 128, 'depth' => 8, 'rules_per_node' => 16), 'nodes' => array_values($nodes), 'variants' => $variants,
            'diagnostics' => array_values(array_unique(array_merge($this->parsingDiagnostics, $diagnostics))),
        );
    }

    /** @param array<string,int> $controls @param array<string,bool> $relevant @param list<array<string,mixed>> $elements */
    private function collect(DOMElement $element, ?string $parent, int $order, int $depth, array $controls, array $relevant, array &$elements, int &$wrapper, bool &$truncated): void
    {
        $path = $element->getNodePath();
        $root = 'form' === strtolower($element->tagName) && null === $parent;
        if (!$root && !isset($controls[$path]) && !isset($relevant[$path])) return;
        if ($depth > 8 || count($elements) >= 128) { $truncated = true; return; }
        $id = $root ? 'form' : (isset($controls[$path]) ? 'control-' . $controls[$path] : 'wrapper-' . $wrapper++);
        $elements[] = array('id' => $id, 'kind' => isset($controls[$path]) ? 'control' : 'container', 'parent' => $parent, 'order' => $order, 'element' => $element);
        $childOrder = 0;
        foreach ($element->childNodes as $child) if ($child instanceof DOMElement) {
            $before = count($elements); $this->collect($child, $id, $childOrder, $depth + 1, $controls, $relevant, $elements, $wrapper, $truncated);
            if (count($elements) > $before) ++$childOrder;
        }
    }

    /** @return list<DOMElement> */
    private function controls(DOMElement $form): array { $result = array(); foreach ($form->getElementsByTagName('*') as $element) if (in_array(strtolower($element->tagName), array('input', 'select', 'textarea', 'button'), true)) $result[] = $element; return $result; }

    /** @param list<array{path:string,content:string,source_hash:string}> $stylesheets @return list<array<string,mixed>> */
    private function rules(array $stylesheets, string $inlineCss): array
    {
        $rules = array(); $order = 0;
        foreach ($stylesheets as $sheet) $this->parse($sheet['content'], $sheet['path'], $sheet['source_hash'], null, $rules, $order);
        if ('' !== trim($inlineCss) && array() === $stylesheets) $this->parse($inlineCss, 'inline-style', hash('sha256', $inlineCss), null, $rules, $order);
        return $rules;
    }

    /** @param list<array<string,mixed>> $rules */
    private function parse(string $css, string $path, string $hash, ?array $condition, array &$rules, int &$order): void
    {
        $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? $css; $offset = 0; $length = strlen($css);
        while ($offset < $length) {
            while ($offset < $length && ctype_space($css[$offset])) ++$offset;
            $start = $offset; $quote = ''; $paren = 0;
            while ($offset < $length) { $c = $css[$offset]; if ('' !== $quote) { if ($c === $quote && '\\' !== ($css[$offset - 1] ?? '')) $quote = ''; } elseif ('"' === $c || "'" === $c) $quote = $c; elseif ('(' === $c) ++$paren; elseif (')' === $c) --$paren; elseif ('{' === $c && 0 === $paren) break; ++$offset; }
            if ($offset >= $length) break; $prelude = trim(substr($css, $start, $offset - $start)); ++$offset; $bodyStart = $offset; $depth = 1; $quote = '';
            while ($offset < $length && $depth > 0) { $c = $css[$offset]; if ('' !== $quote) { if ($c === $quote && '\\' !== ($css[$offset - 1] ?? '')) $quote = ''; } elseif ('"' === $c || "'" === $c) $quote = $c; elseif ('{' === $c) ++$depth; elseif ('}' === $c) --$depth; ++$offset; }
            if (0 !== $depth) { $this->parsingDiagnostics[] = 'malformed_stylesheet:' . $path; return; }
            $body = substr($css, $bodyStart, $offset - $bodyStart - 1); $lower = strtolower($prelude);
            if (preg_match('/^@(media|container|supports)\s+(.+)$/i', $prelude, $match)) { $this->parse($body, $path, $hash, array('kind' => strtolower($match[1]), 'query' => trim($match[2])), $rules, $order); continue; }
            if (str_starts_with($lower, '@')) continue;
            $declarations = $this->declarations($body); if (array() === $declarations) continue;
            foreach ($this->selectors($prelude) as $selector) $rules[] = array('selector' => $selector, 'declarations' => $declarations, 'condition' => $condition, 'path' => $path, 'hash' => $hash, 'order' => $order++, 'specificity' => $this->specificity($selector));
        }
    }

    /** @return list<string> */
    private function selectors(string $prelude): array { $result = array(); $start = 0; $depth = 0; for ($i = 0, $length = strlen($prelude); $i < $length; ++$i) { if (in_array($prelude[$i], array('(', '['), true)) ++$depth; elseif (in_array($prelude[$i], array(')', ']'), true)) --$depth; elseif (',' === $prelude[$i] && 0 === $depth) { $result[] = trim(substr($prelude, $start, $i - $start)); $start = $i + 1; } } $result[] = trim(substr($prelude, $start)); return array_values(array_filter($result)); }
    /** @return array<string,string> */
    private function declarations(string $body): array { $result = array(); foreach (explode(';', $body) as $declaration) if (str_contains($declaration, ':')) { [$name, $value] = array_map('trim', explode(':', $declaration, 2)); if (in_array(strtolower($name), self::PROPERTIES, true) && '' !== $value) $result[strtolower($name)] = preg_replace('/\s+/', ' ', $value) ?? $value; } return $result; }
    private function specificity(string $selector): int { return 100 * preg_match_all('/#[A-Za-z0-9_-]+/', $selector) + 10 * preg_match_all('/\.[A-Za-z0-9_-]+|\[[^]]+\]|:[A-Za-z-]+/', $selector) + preg_match_all('/(?<![#.:-])[A-Za-z][A-Za-z0-9_-]*/', $selector); }

    /** @return array{base:array<string,array<string,mixed>>,conditional:array<string,array<string,array<string,mixed>>>} */
    private function matched(DOMElement $element, array $rules, array &$diagnostics): array
    {
        $base = array(); $conditional = array(); $count = 0;
        foreach ($rules as $rule) { $parsed = CssSelectorMatcher::parse($rule['selector']); $match = CssSelectorMatcher::matches($element, $parsed); if (!$match['supported']) { $diagnostics[] = 'unsupported_selector:' . $rule['selector']; continue; } if (!$match['matches']) continue; if ($count++ >= 16) { $diagnostics[] = 'rules_per_node_truncated'; break; } foreach ($rule['declarations'] as $property => $rawValue) { $important = 1 === preg_match('/\s*!important\s*$/i', $rawValue); $value = preg_replace('/\s*!important\s*$/i', '', $rawValue) ?? $rawValue; if (null === $rule['condition']) { $target =& $base; } else { $conditionKey = json_encode($rule['condition']); $conditional[$conditionKey] ??= array(); $target =& $conditional[$conditionKey]; } $current = $target[$property] ?? null; if (is_array($current) && $current['value'] !== $value) $diagnostics[] = 'cascade_conflict:' . $property; if (!is_array($current) || (int) $important > (int) $current['important'] || ((bool) $important === $current['important'] && ($rule['specificity'] > $current['specificity'] || ($rule['specificity'] === $current['specificity'] && $rule['order'] >= $current['order'])))) $target[$property] = array('value' => $value, 'path' => $rule['path'], 'hash' => $rule['hash'], 'selector' => $rule['selector'], 'order' => $rule['order'], 'specificity' => $rule['specificity'], 'important' => $important); unset($target); } }
        return array('base' => $base, 'conditional' => $conditional);
    }
    /** @return array<string,string> */
    private function layout(array $rules): array { $map = array('grid-template-columns' => 'columns', 'grid-template-rows' => 'rows', 'row-gap' => 'row_gap', 'column-gap' => 'column_gap', 'grid-column' => 'column', 'grid-row' => 'row', 'grid-area' => 'area', 'flex-direction' => 'direction', 'flex-wrap' => 'wrap', 'align-items' => 'align_items', 'align-content' => 'align_content', 'justify-content' => 'justify_content', 'align-self' => 'align_self', 'justify-self' => 'justify_self', 'flex-grow' => 'flex_grow', 'flex-shrink' => 'flex_shrink', 'flex-basis' => 'flex_basis'); $result = array(); foreach ($rules as $property => $fact) $result[$map[$property] ?? $property] = $fact['value']; ksort($result); return $result; }
    /** @return list<array<string,mixed>> */
    private function provenance(array $rules): array { $grouped = array(); foreach ($rules as $property => $fact) { $key = $fact['path'] . "\n" . $fact['selector']; $grouped[$key] ??= array('source_path' => $fact['path'], 'source_sha256' => $fact['hash'], 'selector' => $fact['selector'], 'condition' => null, 'properties' => array()); $grouped[$key]['properties'][] = $property; } foreach ($grouped as &$item) sort($item['properties'], SORT_STRING); return array_values($grouped); }
    /** @return array<string,mixed> */
    private function source(DOMElement $element): array { $classes = array_values(array_filter(preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array(), static fn(string $class): bool => 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,79}$/D', $class))); return array('tag' => strtolower($element->tagName), 'id' => 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,79}$/D', $element->getAttribute('id')) ? $element->getAttribute('id') : null, 'classes' => array_slice($classes, 0, 8)); }
    /** @return array<string,array<string,array<string,mixed>>> */
    private function conditional(array $conditional): array { return $conditional; }
}
