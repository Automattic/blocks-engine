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

    /** @return array<string, mixed>|null */
    public function themePreferenceOwnership(string $script): ?array
    {
        if (strlen($script) > self::MAX_SCRIPT_BYTES) return null;

        $rootNames = array('document');
        if (preg_match_all('/\b(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*documentElement\b/', $script, $aliases)) {
            foreach ($aliases[1] as $alias) $rootNames[] = preg_quote($alias, '/');
        }
        $rootNames = array_values(array_unique($rootNames));
        $root = '(?:' . implode('|', $rootNames) . ')\s*\.\s*documentElement';
        $aliasPattern = '(?:' . implode('|', array_slice($rootNames, 1)) . ')';
        if (count($rootNames) > 1) $root = '(?:document\s*\.\s*documentElement|(?<![A-Za-z0-9_$])' . $aliasPattern . ')';
        else $root = 'document\s*\.\s*documentElement';

        $classValues = array();
        $attributes = array();
        $classMutation = false;
        $invalidClassMutation = false;
        $mutationPattern = '/' . $root . '\s*\.\s*classList\s*\.\s*(?:add|remove|toggle)\s*\(\s*([^)]{0,240})\)/';
        if (preg_match_all($mutationPattern, $script, $mutations)) {
            $classMutation = true;
            foreach ($mutations[1] as $arguments) {
                $hasLiteral = (bool) preg_match('/["\'][^"\']+["\']/', $arguments);
                if (preg_match_all('/["\']([A-Za-z_-][A-Za-z0-9_-]*)["\']/', $arguments, $classes)) $classValues = array_merge($classValues, $classes[1]);
                elseif ($hasLiteral) $invalidClassMutation = true;
            }
        }
        if ($invalidClassMutation) return null;
        $attributePattern = '/' . $root . '\s*\.\s*(?:setAttribute|removeAttribute)\s*\(\s*(["\'])([A-Za-z_:][A-Za-z0-9_.:-]*)\1(?:\s*,\s*(?:(["\'])([^"\']*)\3|[^)]{1,240}))?\s*\)/';
        if (preg_match_all($attributePattern, $script, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attributes[] = array('name' => $match[2], 'value' => $match[4] ?? null);
            }
        }
        $rootMutation = $classMutation || array() !== $attributes;
        if (!$rootMutation || !preg_match('/matchMedia\s*\(/', $script) || !preg_match('/prefers-color-scheme/i', $script)) return null;
        $attributeNames = array_values(array_unique(array_column($attributes, 'name')));
        $attributeValues = array_values(array_unique(array_filter(array_column($attributes, 'value'), static fn(mixed $value): bool => is_string($value) && '' !== $value)));

        $keys = array();
        if (preg_match_all('/\b(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*(["\'])([^"\']+)\2/', $script, $declarations, PREG_SET_ORDER)) {
            foreach ($declarations as $declaration) $keys[$declaration[1]][$declaration[3]] = true;
        }
        if (preg_match_all('/\bstorageKey\s*:\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*(["\'])([^"\']+)\2/', $script, $config, PREG_SET_ORDER)) {
            foreach ($config as $declaration) $keys[$declaration[1]][$declaration[3]] = true;
        }
        $storageGetters = array();
        if (preg_match_all('/\b([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*\(\s*([A-Za-z_$][A-Za-z0-9_$]*)\b[^)]*\)\s*=>[\s\S]{0,1200}?localStorage\s*\.\s*getItem\s*\(\s*\2\s*\)/', $script, $getters, PREG_SET_ORDER)) {
            foreach ($getters as $getter) $storageGetters[$getter[1]][$getter[2]] = true;
        }
        $readKeys = $writeKeys = array();
        foreach (array('getItem' => &$readKeys, 'setItem' => &$writeKeys) as $method => &$found) {
            if (preg_match_all('/\blocalStorage\s*\.\s*' . $method . '\s*\(\s*(?:(["\'])([^"\']+)\1|([A-Za-z_$][A-Za-z0-9_$]*))/', $script, $calls, PREG_SET_ORDER)) {
                foreach ($calls as $call) {
                    $key = isset($call[2]) && '' !== $call[2] ? $call[2] : $this->uniqueStorageKey($keys[$call[3] ?? ''] ?? array());
                    $getterNames = array();
                    if (null === $key && 'getItem' === $method) {
                        foreach ($storageGetters as $functionName => $parameters) if (isset($parameters[$call[3] ?? ''])) $getterNames[] = $functionName;
                    }
                    if ( null === $key && 1 === count($getterNames) ) {
                        $function = preg_quote($getterNames[0], '/');
                        if (preg_match('/\b' . $function . '\s*\(\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*,/', $script, $invocation)) {
                            $key = $this->uniqueStorageKey($keys[$invocation[1]] ?? array());
                        } elseif (preg_match('/\b' . $function . '\s*\(\s*(["\'])([^"\']+)\1\s*,/', $script, $invocation)) {
                            $key = $invocation[2];
                        }
                    }
                    $found[] = $key;
                }
            }
        }
        unset($found);
        $matching = array_values(array_unique(array_intersect(array_filter($readKeys), array_filter($writeKeys))));
        if (1 !== count($matching)) return null;

        return array(
            'storageKey' => $matching[0],
            'rootAttribute' => $classMutation ? 'class' : (1 === count($attributeNames) ? $attributeNames[0] : null),
            'rootAttributeValue' => 1 === count($attributeValues) ? $attributeValues[0] : null,
            'rootAttributeValues' => $attributeValues,
            'rootAttributeRemoved' => 1 === count($attributeNames) && count($attributeValues) < count($attributes),
            'rootClassValues' => $classValues,
            'evidence' => array('root_owned' => true, 'os_media' => true, 'storage_read_write' => true),
            'contract' => 'theme-preference-ownership/v1',
        );
    }

    /** @param array<string, true> $values */
    private function uniqueStorageKey(array $values): ?string
    {
        return 1 === count($values) ? (string) array_key_first($values) : null;
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
        if (preg_match_all('/function\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*\(\s*\)\s*\{[^{}]{0,200}?return\s+document\s*\.\s*querySelectorAll\s*\(\s*(["\'])(' . $this->selectorPattern() . ')\2\s*\)/', $script, $helpers, PREG_SET_ORDER)) {
            foreach ($helpers as $helper) {
                $selector = $this->canonicalSelector($helper[3]);
                $call = preg_quote($helper[1], '/') . '\s*\(\s*\)';
                if ($events || !preg_match('/\b' . $call . '\s*(?:' . $use . '|\.\s*forEach\b)/', $script)) continue;
                $found[$selector] = true;
            }
        }
        if (!preg_match_all('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*(?:getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\2\s*\)|querySelector(?:All)?\s*\(\s*(["\'])(' . $this->selectorPattern() . ')\4\s*\))/', $script, $assignments, PREG_SET_ORDER)) return $events ? $found : array_keys($found);
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
    private function canonicalSelector(string $selector): string { return RuntimeSelectorVocabulary::canonicalScriptSelector($selector); }
    private function selectorKind(string $selector): string { return str_starts_with($selector, '#') ? 'id' : (str_starts_with($selector, '.') ? 'class' : (str_contains($selector, '[') ? 'attribute' : 'element')); }
    /** @return array<int, string> */
    private function dataSelectors(string $selector): array { $out = array(); if (preg_match_all('/(?:^|[\s>+~,])((?:[a-z][a-z0-9-]*)?\[data-[A-Za-z][A-Za-z0-9_-]*(?:\s*=\s*(?:"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|[^\s"\'\]]{1,80}))?\])/i', $selector, $matches)) foreach ($matches[1] as $candidate) { $canonical = RuntimeSelectorVocabulary::canonicalScriptSelector($candidate); if (str_contains($canonical, '[')) $out[] = $canonical; } return array_values(array_unique($out)); }
    /** @return array<int, string> */
    private function scopedElementSelectors(string $script, string $tag, string $use = ''): array { $out = array(); if (preg_match('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\./', $script, $roots)) if (preg_match('/\b' . preg_quote($roots[1], '/') . '\s*\.\s*querySelector\s*\(\s*(["\'])' . $tag . '\1\s*\)(?:\s*' . $use . ')?/', $script)) $out[] = $tag; return $out; }
    /** @return array<int, string> */
    private function appendedRootSelectors(string $script): array { $out = array(); if (preg_match_all('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*(?:getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\2\s*\)|querySelector\s*\(\s*(["\'])(' . $this->selectorPattern() . ')\4\s*\))/', $script, $roots, PREG_SET_ORDER)) foreach ($roots as $root) if (preg_match('/\b' . preg_quote($root[1], '/') . '\s*\.\s*appendChild\s*\(/', $script)) $out[] = '' !== ($root[3] ?? '') ? '#' . $root[3] : $this->canonicalSelector($root[5]); return array_values(array_unique($out)); }
}
