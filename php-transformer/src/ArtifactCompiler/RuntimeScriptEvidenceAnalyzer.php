<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Support\RuntimeSelectorVocabulary;

/**
 * Bounded, side-effect-free evidence extraction for one runtime script.
 * Consumers decide whether a fact requires DOM preservation or a diagnostic.
 */
final class RuntimeScriptEvidenceAnalyzer
{
    private const MAX_SCRIPT_BYTES = 1048576;

    /** @var array<string, array<string, mixed>> */
    private array $cache = array();

    public function resetCache(): void
    {
        $this->cache = array();
    }

    /** @return array<string, mixed> */
    public function analyze(string $script, string $sourcePath = '', string $scriptPath = ''): array
    {
        $cacheKey = hash('sha256', $sourcePath . "\0" . $scriptPath . "\0" . $script);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $originalBytes = strlen($script);
        $truncated = $originalBytes > self::MAX_SCRIPT_BYTES;
        if ($truncated) {
            $script = substr($script, 0, self::MAX_SCRIPT_BYTES);
        }

        $selectors = $this->selectors($script);
        $events = $this->events($script);
        $controls = $this->controlSelectors($script);
        $canvas = $this->canvasSelectors($script);
        $dependencies = array();
        foreach ($selectors as $selector) {
            $dependencies[] = array(
                'kind' => $this->selectorKind($selector),
                'selector' => $selector,
                'events' => $events[$selector] ?? array(),
                'canvas_api' => isset($canvas[$selector]),
                'control_runtime' => isset($controls[$selector]),
                'presentation_only' => $this->presentationOnly($script, $selector),
            );
        }

        return $this->cache[$cacheKey] = array(
            'schema' => 'blocks-engine/php-transformer/runtime-script-evidence/v1',
            'source_path' => $sourcePath,
            'script_path' => $scriptPath,
            'script_bytes' => $originalBytes,
            'selectors' => $selectors,
            'dependencies' => $dependencies,
            'interaction_selectors' => array_keys($events),
            'control_selectors' => array_keys($controls),
            'canvas_selectors' => array_keys($canvas),
            'mutation_selectors' => $this->mutationSelectors($script, $selectors),
            'diagnostics' => $truncated ? array(array('code' => 'runtime_script_analysis_truncated', 'severity' => 'warning', 'source_path' => $sourcePath, 'script_path' => $scriptPath, 'max_bytes' => self::MAX_SCRIPT_BYTES, 'actual_bytes' => $originalBytes, 'fail_closed' => true)) : array(),
        );
    }

    /** @return array<int, string> */
    private function selectors(string $script): array
    {
        $selectors = array();
        if (preg_match_all('/document\s*\.\s*getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1\s*\)/', $script, $matches)) {
            foreach ($matches[2] as $id) $selectors['#' . $id] = true;
        }
        $pattern = $this->selectorPattern();
        foreach (array('/document\s*\.\s*querySelector(?:All)?\s*\(\s*(["\'])(' . $pattern . ')\1\s*\)/', '/\b(?!document\b)[A-Za-z_$][A-Za-z0-9_$]*\s*\.\s*querySelector(?:All)?\s*\(\s*(["\'])(' . $pattern . ')\1\s*\)/', '/\.\s*closest\s*\(\s*(["\'])(' . $pattern . ')\1\s*\)/') as $expression) {
            if (preg_match_all($expression, $script, $matches)) foreach ($matches[2] as $selector) $selectors[$this->canonicalSelector($selector)] = true;
        }
        if (preg_match_all('/(?:querySelector(?:All)?|closest)\s*\(\s*(["\'`])(.{1,240}?)\1\s*\)/s', $script, $calls, PREG_SET_ORDER)) {
            foreach ($calls as $call) foreach ($this->dataSelectors($call[2]) as $selector) $selectors[$selector] = true;
        }
        foreach (array('canvas', 'svg') as $tag) foreach ($this->scopedElementSelectors($script, $tag) as $selector) $selectors[$selector] = true;
        foreach ($this->appendedRootSelectors($script) as $selector) $selectors[$selector] = true;
        return array_keys($selectors);
    }

    /** @return array<string, bool> */
    private function canvasSelectors(string $script): array
    {
        $selectors = array(); $use = '\.\s*getContext\s*\(';
        if (preg_match_all('/document\s*\.\s*getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1\s*\)\s*' . $use . '/', $script, $matches)) foreach ($matches[2] as $id) $selectors['#' . $id] = true;
        if (preg_match_all('/document\s*\.\s*querySelector\s*\(\s*(["\'])(' . $this->selectorPattern() . ')\1\s*\)\s*' . $use . '/', $script, $matches)) foreach ($matches[2] as $selector) $selectors[$this->canonicalSelector($selector)] = true;
        if (preg_match_all('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*(?:getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\2\s*\)|querySelector\s*\(\s*(["\'])(' . $this->selectorPattern() . ')\4\s*\))/', $script, $assignments, PREG_SET_ORDER)) {
            foreach ($assignments as $assignment) if (preg_match('/\b' . preg_quote($assignment[1], '/') . '\s*' . $use . '/', $script)) $selectors['' !== ($assignment[3] ?? '') ? '#' . $assignment[3] : $this->canonicalSelector($assignment[5])] = true;
        }
        foreach ($this->scopedElementSelectors($script, 'canvas', $use) as $selector) $selectors[$selector] = true;
        if (preg_match_all('/\b[A-Za-z_$][A-Za-z0-9_$]*(?:\s*\.\s*[A-Za-z_$][A-Za-z0-9_$]*)*\s*\([^;)]*document\s*\.\s*getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1\s*\)/', $script, $matches)) foreach ($matches[2] as $id) $selectors['#' . $id] = true;
        return $selectors;
    }

    /** @return array<string, bool> */
    private function controlSelectors(string $script): array
    {
        $selectors = array(); $use = '\.\s*(?:addEventListener|value|checked|selectedIndex|selectedOptions|options|files|validity|setCustomValidity|focus|select|click|dispatchEvent)\b';
        if (preg_match_all('/document\s*\.\s*getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1\s*\)\s*(?:\.\s*[^;\n]*)?' . $use . '/', $script, $matches)) foreach ($matches[2] as $id) $selectors['#' . $id] = true;
        if (preg_match_all('/document\s*\.\s*querySelector(?:All)?\s*\(\s*(["\'])(' . $this->selectorPattern() . ')\1\s*\)\s*(?:\.\s*[^;\n]*)?' . $use . '/', $script, $matches)) foreach ($matches[2] as $selector) $selectors[$this->canonicalSelector($selector)] = true;
        foreach ($this->assignedSelectors($script, $use) as $selector) $selectors[$selector] = true;
        // QuerySelectorAll callbacks frequently use the callback parameter rather
        // than the selector expression. Retain every selected target when that
        // bounded callback contains a control operation.
        if (preg_match('/querySelectorAll\s*\(.*?\)\s*\.\s*forEach\s*\([\s\S]{0,2000}' . $use . '/', $script)) foreach ($this->selectors($script) as $selector) $selectors[$selector] = true;
        return $selectors;
    }

    /** @return array<string, array<int, string>> */
    private function events(string $script): array
    {
        $events = array();
        if (preg_match_all('/document\s*\.\s*getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1\s*\)\s*\.\s*addEventListener\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\3/', $script, $matches)) foreach ($matches[2] as $i => $id) $events['#' . $id][] = $matches[4][$i];
        if (preg_match_all('/document\s*\.\s*querySelector(?:All)?\s*\(\s*(["\'])([#.][A-Za-z][A-Za-z0-9_-]*)\1\s*\)\s*\.\s*addEventListener\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\3/', $script, $matches)) foreach ($matches[2] as $i => $selector) $events[$selector][] = $matches[4][$i];
        foreach ($this->assignedSelectors($script, '\.\s*addEventListener\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1', true) as $selector => $selectorEvents) $events[$selector] = array_values(array_unique(array_merge($events[$selector] ?? array(), $selectorEvents)));
        foreach ($events as $selector => $selectorEvents) $events[$selector] = array_values(array_unique($selectorEvents));
        return $events;
    }

    /** @return array<int, string>|array<string, array<int, string>> */
    private function assignedSelectors(string $script, string $use, bool $events = false): array
    {
        $found = array();
        if (!preg_match_all('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*(?:getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\2\s*\)|querySelector(?:All)?\s*\(\s*(["\'])(' . $this->selectorPattern() . ')\4\s*\))/', $script, $assignments, PREG_SET_ORDER)) return $found;
        foreach ($assignments as $assignment) {
            $selector = '' !== ($assignment[3] ?? '') ? '#' . $assignment[3] : $this->canonicalSelector($assignment[5]);
            if (!preg_match_all('/\b' . preg_quote($assignment[1], '/') . '\s*' . $use . '/', $script, $matches)) continue;
            if ($events) $found[$selector] = array_merge($found[$selector] ?? array(), $matches[2] ?? array()); else $found[$selector] = true;
        }
        return $events ? $found : array_keys($found);
    }

    /** @return array<int, string> */
    private function mutationSelectors(string $script, array $selectors): array
    {
        $mutations = array();
        foreach ($selectors as $selector) if (!$this->presentationOnly($script, $selector)) $mutations[] = $selector;
        return $mutations;
    }

    private function presentationOnly(string $script, string $selector): bool
    {
        if (!RuntimeSelectorVocabulary::isPresentationalAnimation($selector)) return false;
        $quoted = preg_quote($selector, '/');
        if (preg_match_all('/\b(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*(?:(?:document|[A-Za-z_$][A-Za-z0-9_$]*)\s*\.\s*)?querySelector(?:All)?\s*\(\s*(["\'])' . $quoted . '\2\s*\)/', $script, $assignments, PREG_SET_ORDER)) {
            foreach ($assignments as $assignment) {
                $variable = preg_quote((string) $assignment[1], '/');
                if (preg_match('/\b' . $variable . '\s*\.\s*(?:addEventListener|appendChild|removeChild|replaceChildren|insertAdjacentHTML|setAttribute|removeAttribute|toggleAttribute|getContext|submit|fetch)\b|\b' . $variable . '\s*\.\s*(?:textContent|innerHTML|outerHTML|value|checked|selectedIndex|classList|hidden|disabled|style|dataset)\b/', $script)) return false;
            }
        }
        if (!preg_match('/querySelector(?:All)?\s*\(\s*(["\'])' . $quoted . '\1\s*\)([^;]{0,700})/', $script, $matches)) return false;
        if (preg_match('/\b(?:addEventListener|appendChild|removeChild|replaceChildren|insertAdjacentHTML|innerHTML|outerHTML|textContent|value|checked|selectedIndex|setAttribute|removeAttribute|toggleAttribute|getContext|submit|fetch)\b|\.\s*(?:classList|hidden|disabled|style|dataset)\b/', $matches[2])) return false;
        return true;
    }
    private function selectorPattern(): string { return RuntimeSelectorVocabulary::scriptSelectorPattern(); }
    private function canonicalSelector(string $selector): string { $selector = trim($selector); return preg_match('/^(?:([a-z][a-z0-9-]*))?\[(data-[A-Za-z][A-Za-z0-9_-]*)(?:=["\'][^"\']{1,80}["\'])?\]$/', $selector, $match) ? strtolower($match[1] ?? '') . '[' . strtolower($match[2]) . ']' : $selector; }
    private function selectorKind(string $selector): string { return str_starts_with($selector, '#') ? 'id' : (str_starts_with($selector, '.') ? 'class' : (str_contains($selector, '[') ? 'attribute' : 'element')); }
    /** @return array<int, string> */
    private function dataSelectors(string $selector): array { $out = array(); if (preg_match_all('/(?:^|[\s>+~,])([a-z][a-z0-9-]*)?\[(data-[A-Za-z][A-Za-z0-9_-]*)/', $selector, $matches, PREG_SET_ORDER)) foreach ($matches as $match) $out[] = strtolower($match[1] ?? '') . '[' . strtolower($match[2]) . ']'; return array_values(array_unique($out)); }
    /** @return array<int, string> */
    private function scopedElementSelectors(string $script, string $tag, string $use = ''): array { $out = array(); if (preg_match('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\./', $script, $roots)) if (preg_match('/\b' . preg_quote($roots[1], '/') . '\s*\.\s*querySelector\s*\(\s*(["\'])' . $tag . '\1\s*\)(?:\s*' . $use . ')?/', $script)) $out[] = $tag; return $out; }
    /** @return array<int, string> */
    private function appendedRootSelectors(string $script): array { $out = array(); if (preg_match_all('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*(?:getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\2\s*\)|querySelector\s*\(\s*(["\'])(' . $this->selectorPattern() . ')\4\s*\))/', $script, $roots, PREG_SET_ORDER)) foreach ($roots as $root) if (preg_match('/\b' . preg_quote($root[1], '/') . '\s*\.\s*appendChild\s*\(/', $script)) $out[] = '' !== ($root[3] ?? '') ? '#' . $root[3] : $this->canonicalSelector($root[5]); return array_values(array_unique($out)); }
}
