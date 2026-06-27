<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use DOMDocument;
use DOMElement;

final class RuntimeDependencyParityReport
{
    public const SCHEMA = 'blocks-engine/php-transformer/runtime-dependency-parity/v1';

    /**
     * @param array<int, array<string, mixed>> $files
     * @param array<int, array<string, mixed>> $runtimeIslands
     * @return array<string, mixed>
     */
    public function fromArtifact(array $files, string $sourceHtml, string $generatedHtml, string $sourcePath = '', array $runtimeIslands = array()): array
    {
        $sourceTargets = $this->sourceTargets($sourceHtml, $sourcePath);
        $generatedTargets = $this->withRuntimeIslandTargets($this->htmlTargets($generatedHtml), $runtimeIslands);
        $dependencies = array();
        $findings = array();

        foreach ( $files as $file ) {
            if ( ! $this->isScriptFile($file) ) {
                continue;
            }

            $scriptPath = (string) ($file['path'] ?? '');
            $script = (string) ($file['content'] ?? '');
            if ( '' === trim($script) ) {
                continue;
            }

            $scriptKind = $this->scriptKind($scriptPath, $script);
            foreach ( $this->scriptDependencies($script) as $dependency ) {
                $selector = (string) $dependency['selector'];
                $target = $sourceTargets[$selector] ?? array();
                $exists = $this->targetExists($dependency, $generatedTargets);
                $canvasApi = true === $dependency['canvas_api'] && 'canvas' === ($target['tag'] ?? '');
                $dependencyRow = array_filter(array(
                    'source_path'       => $target['source_path'] ?? $sourcePath,
                    'script_path'       => $scriptPath,
                    'script_kind'       => $scriptKind,
                    'selector'          => $selector,
                    'target_id'         => $target['id'] ?? '',
                    'target_class'      => $target['class'] ?? '',
                    'target_kind'       => $target['tag'] ?? '',
                    'dependency_kind'   => $dependency['kind'],
                    'events'            => $dependency['events'],
                    'canvas_api'        => $canvasApi,
                    'source_present'    => array() !== $target,
                    'generated_present' => $exists,
                ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value);
                $dependencies[] = $dependencyRow;

                if ( $exists ) {
                    continue;
                }

                if ( $this->isFormControlTarget($target) && true !== ( $dependency['control_runtime'] ?? false ) ) {
                    continue;
                }

                $severity = 'telemetry' === $scriptKind ? 'info' : 'warning';
                $repairBucket = $canvasApi ? 'runtime_canvas_target_preservation' : 'runtime_dom_target_preservation';
                $findings[] = array_filter(array(
                    'code'              => 'runtime_dependency_target_missing',
                    'severity'          => $severity,
                    'source_path'       => $target['source_path'] ?? $sourcePath,
                    'script_path'       => $scriptPath,
                    'script_kind'       => $scriptKind,
                    'selector'          => $selector,
                    'target_id'         => $target['id'] ?? '',
                    'target_class'      => $target['class'] ?? '',
                    'target_kind'       => $target['tag'] ?? '',
                    'dependency_kind'   => $dependency['kind'],
                    'events'            => $dependency['events'],
                    'canvas_api'        => $canvasApi,
                    'repair_bucket'     => $repairBucket,
                    'suggested_primitive' => $canvasApi ? 'runtime_canvas' : 'runtime_dom_target',
                    'actionability'     => $canvasApi ? 'preserve_canvas_markup_with_matching_script_runtime_or_rebuild_canvas_behavior' : 'preserve_or_recreate_the_referenced_dom_target_for_script_runtime',
                    'materialization_hint' => $canvasApi ? 'preserve_canvas_id_class_and_markup_for_runtime_mapping' : 'preserve_id_class_or_wrapper_markup_required_by_first_party_script',
                    'message'           => sprintf('Script %s references %s, but the generated block markup does not expose that DOM target.', $scriptPath, $selector),
                ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value);
            }
        }

        return array_filter(array(
            'schema'       => self::SCHEMA,
            'status'       => array() === $findings ? 'pass' : 'warning',
            'dependencies' => $this->dedupeRows($dependencies),
            'findings'     => $this->dedupeRows($findings),
        ), static fn (mixed $value): bool => array() !== $value);
    }

    /**
     * @param array<string, mixed> $file
     */
    private function isScriptFile(array $file): bool
    {
        return in_array($file['kind'] ?? '', array('js', 'mjs'), true)
            || 'script' === ($file['role'] ?? '')
            || in_array($file['mime_type'] ?? '', array('application/javascript', 'text/javascript', 'application/ecmascript', 'text/ecmascript'), true);
    }

    /**
     * @return array<string, array{tag: string, source_path: string, id?: string, class?: string}>
     */
    private function sourceTargets(string $html, string $sourcePath): array
    {
        $targets = array();
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ( ! $loaded ) {
            return array();
        }

        foreach ( $document->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement ) {
                continue;
            }
            $tag = strtolower($element->tagName);
            $id = trim($element->hasAttribute('id') ? $element->getAttribute('id') : '');
            if ( '' !== $id ) {
                $targets['#' . $id] = array('tag' => $tag, 'source_path' => $sourcePath, 'id' => $id);
            }
            foreach ( preg_split('/\s+/', trim($element->hasAttribute('class') ? $element->getAttribute('class') : '')) ?: array() as $class ) {
                if ( '' !== $class ) {
                    $targets['.' . $class] = array('tag' => $tag, 'source_path' => $sourcePath, 'class' => $class);
                }
            }
        }

        return $targets;
    }

    /**
     * @return array{ids: array<string, bool>, classes: array<string, bool>}
     */
    private function htmlTargets(string $html): array
    {
        $targets = array('ids' => array(), 'classes' => array());
        if ( preg_match_all('/\sid\s*=\s*(["\'])(.*?)\1/is', $html, $matches) ) {
            foreach ( $matches[2] as $id ) {
                $id = trim(html_entity_decode((string) $id, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ( '' !== $id ) {
                    $targets['ids'][$id] = true;
                }
            }
        }
        if ( preg_match_all('/\sclass\s*=\s*(["\'])(.*?)\1/is', $html, $matches) ) {
            foreach ( $matches[2] as $classList ) {
                foreach ( preg_split('/\s+/', trim(html_entity_decode((string) $classList, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?: array() as $class ) {
                    if ( '' !== $class ) {
                        $targets['classes'][$class] = true;
                    }
                }
            }
        }

        return $targets;
    }

    /**
     * @param array{ids: array<string, bool>, classes: array<string, bool>} $targets
     * @param array<int, array<string, mixed>> $runtimeIslands
     * @return array{ids: array<string, bool>, classes: array<string, bool>}
     */
    private function withRuntimeIslandTargets(array $targets, array $runtimeIslands): array
    {
        foreach ( $runtimeIslands as $island ) {
            if ( ! is_array($island) ) {
                continue;
            }

            $selector = is_string($island['selector'] ?? null) ? trim($island['selector']) : '';
            if ( str_starts_with($selector, '#') ) {
                $targets['ids'][substr($selector, 1)] = true;
            }
            if ( str_starts_with($selector, '.') ) {
                $targets['classes'][substr($selector, 1)] = true;
            }

            $attributes = is_array($island['attributes'] ?? null) ? $island['attributes'] : array();
            $id = is_string($attributes['id'] ?? null) ? trim($attributes['id']) : '';
            if ( '' !== $id ) {
                $targets['ids'][$id] = true;
            }
            $classList = is_string($attributes['class'] ?? null) ? trim($attributes['class']) : '';
            foreach ( preg_split('/\s+/', $classList) ?: array() as $class ) {
                if ( '' !== $class ) {
                    $targets['classes'][$class] = true;
                }
            }
        }

        return $targets;
    }

    /**
     * @return array<int, array{kind: string, selector: string, events: array<int, string>, canvas_api: bool}>
     */
    private function scriptDependencies(string $script): array
    {
        $dependencies = array();
        $eventsBySelector = $this->eventsBySelector($script);
        $canvasSelectors = $this->scriptCanvasSelectors($script);
        $controlRuntimeSelectors = $this->scriptControlRuntimeSelectors($script);

        if ( preg_match_all('/document\s*\.\s*getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1\s*\)/', $script, $matches) ) {
            foreach ( $matches[2] as $id ) {
                $selector = '#' . (string) $id;
                $dependencies[] = array(
                    'kind'       => 'id',
                    'selector'   => $selector,
                    'events'     => $eventsBySelector[$selector] ?? array(),
                    'canvas_api' => isset($canvasSelectors[$selector]),
                    'control_runtime' => isset($controlRuntimeSelectors[$selector]),
                );
            }
        }

        if ( preg_match_all('/document\s*\.\s*querySelector(?:All)?\s*\(\s*(["\'])([#.][A-Za-z][A-Za-z0-9_-]*)\1\s*\)/', $script, $matches) ) {
            foreach ( $matches[2] as $selector ) {
                $selector = (string) $selector;
                $dependencies[] = array(
                    'kind'       => str_starts_with($selector, '#') ? 'id' : 'class',
                    'selector'   => $selector,
                    'events'     => $eventsBySelector[$selector] ?? array(),
                    'canvas_api' => isset($canvasSelectors[$selector]),
                    'control_runtime' => isset($controlRuntimeSelectors[$selector]),
                );
            }
        }

        if ( preg_match_all('/\b(?!document\b)[A-Za-z_$][A-Za-z0-9_$]*\s*\.\s*querySelector(?:All)?\s*\(\s*(["\'])([#.][A-Za-z][A-Za-z0-9_-]*)\1\s*\)/', $script, $matches) ) {
            foreach ( $matches[2] as $selector ) {
                $selector = (string) $selector;
                $dependencies[] = array(
                    'kind'       => str_starts_with($selector, '#') ? 'id' : 'class',
                    'selector'   => $selector,
                    'events'     => $eventsBySelector[$selector] ?? array(),
                    'canvas_api' => isset($canvasSelectors[$selector]),
                    'control_runtime' => isset($controlRuntimeSelectors[$selector]),
                );
            }
        }

        if ( preg_match_all('/\.\s*closest\s*\(\s*(["\'])([#.][A-Za-z][A-Za-z0-9_-]*)\1\s*\)/', $script, $matches) ) {
            foreach ( $matches[2] as $selector ) {
                $selector = (string) $selector;
                $dependencies[] = array(
                    'kind'       => str_starts_with($selector, '#') ? 'id' : 'class',
                    'selector'   => $selector,
                    'events'     => $eventsBySelector[$selector] ?? array(),
                    'canvas_api' => isset($canvasSelectors[$selector]),
                    'control_runtime' => isset($controlRuntimeSelectors[$selector]),
                );
            }
        }

        return $this->dedupeDependencies($dependencies);
    }

    /**
     * @return array<string, bool>
     */
    private function scriptControlRuntimeSelectors(string $script): array
    {
        $selectors = array();
        $runtimeUsePattern = '\.\s*(?:addEventListener|value|checked|selectedIndex|selectedOptions|options|files|validity|setCustomValidity|focus|select|click|dispatchEvent)\b';

        if ( preg_match_all('/document\s*\.\s*getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1\s*\)\s*(?:\.\s*[^;\n]*)?' . $runtimeUsePattern . '/', $script, $matches) ) {
            foreach ( $matches[2] as $id ) {
                $selectors['#' . (string) $id] = true;
            }
        }
        if ( preg_match_all('/document\s*\.\s*querySelector(?:All)?\s*\(\s*(["\'])([#.][A-Za-z][A-Za-z0-9_-]*)\1\s*\)\s*(?:\.\s*[^;\n]*)?' . $runtimeUsePattern . '/', $script, $matches) ) {
            foreach ( $matches[2] as $selector ) {
                $selectors[(string) $selector] = true;
            }
        }
        if ( preg_match_all('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\2\s*\)/', $script, $assignments, PREG_SET_ORDER) ) {
            foreach ( $assignments as $assignment ) {
                if ( preg_match('/\b' . preg_quote((string) $assignment[1], '/') . '\s*' . $runtimeUsePattern . '/', $script) ) {
                    $selectors['#' . (string) $assignment[3]] = true;
                }
            }
        }
        if ( preg_match_all('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*querySelector(?:All)?\s*\(\s*(["\'])([#.][A-Za-z][A-Za-z0-9_-]*)\2\s*\)/', $script, $assignments, PREG_SET_ORDER) ) {
            foreach ( $assignments as $assignment ) {
                if ( preg_match('/\b' . preg_quote((string) $assignment[1], '/') . '\s*' . $runtimeUsePattern . '/', $script) ) {
                    $selectors[(string) $assignment[3]] = true;
                }
            }
        }

        return $selectors;
    }

    /**
     * @return array<string, bool>
     */
    private function scriptCanvasSelectors(string $script): array
    {
        $selectors = array();
        $getContextPattern = '\.\s*getContext\s*\(';

        if ( preg_match_all('/document\s*\.\s*getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1\s*\)\s*' . $getContextPattern . '/', $script, $matches) ) {
            foreach ( $matches[2] as $id ) {
                $selectors['#' . (string) $id] = true;
            }
        }

        if ( preg_match_all('/document\s*\.\s*querySelector\s*\(\s*(["\'])([#.][A-Za-z][A-Za-z0-9_-]*)\1\s*\)\s*' . $getContextPattern . '/', $script, $matches) ) {
            foreach ( $matches[2] as $selector ) {
                $selectors[(string) $selector] = true;
            }
        }

        if ( preg_match_all('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\2\s*\)/', $script, $assignments, PREG_SET_ORDER) ) {
            foreach ( $assignments as $assignment ) {
                if ( preg_match('/\b' . preg_quote((string) $assignment[1], '/') . '\s*' . $getContextPattern . '/', $script) ) {
                    $selectors['#' . (string) $assignment[3]] = true;
                }
            }
        }

        if ( preg_match_all('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*querySelector\s*\(\s*(["\'])([#.][A-Za-z][A-Za-z0-9_-]*)\2\s*\)/', $script, $assignments, PREG_SET_ORDER) ) {
            foreach ( $assignments as $assignment ) {
                if ( preg_match('/\b' . preg_quote((string) $assignment[1], '/') . '\s*' . $getContextPattern . '/', $script) ) {
                    $selectors[(string) $assignment[3]] = true;
                }
            }
        }

        return $selectors;
    }

    /**
     * @param array<string, mixed> $target
     */
    private function isFormControlTarget(array $target): bool
    {
        return in_array((string) ($target['tag'] ?? ''), array('button', 'input', 'select', 'textarea'), true);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function eventsBySelector(string $script): array
    {
        $events = array();
        if ( preg_match_all('/document\s*\.\s*getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1\s*\)\s*\.\s*addEventListener\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\3/', $script, $matches) ) {
            foreach ( $matches[2] as $index => $id ) {
                $events['#' . (string) $id][] = (string) $matches[4][$index];
            }
        }
        if ( preg_match_all('/document\s*\.\s*querySelector(?:All)?\s*\(\s*(["\'])([#.][A-Za-z][A-Za-z0-9_-]*)\1\s*\)\s*\.\s*addEventListener\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\3/', $script, $matches) ) {
            foreach ( $matches[2] as $index => $selector ) {
                $events[(string) $selector][] = (string) $matches[4][$index];
            }
        }
        if ( preg_match_all('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\2\s*\)/', $script, $assignments, PREG_SET_ORDER) ) {
            foreach ( $assignments as $assignment ) {
                if ( preg_match_all('/\b' . preg_quote((string) $assignment[1], '/') . '\s*\.\s*addEventListener\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1/', $script, $matches) ) {
                    foreach ( $matches[2] as $event ) {
                        $events['#' . (string) $assignment[3]][] = (string) $event;
                    }
                }
            }
        }
        if ( preg_match_all('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*querySelector(?:All)?\s*\(\s*(["\'])([#.][A-Za-z][A-Za-z0-9_-]*)\2\s*\)/', $script, $assignments, PREG_SET_ORDER) ) {
            foreach ( $assignments as $assignment ) {
                if ( preg_match_all('/\b' . preg_quote((string) $assignment[1], '/') . '\s*\.\s*addEventListener\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1/', $script, $matches) ) {
                    foreach ( $matches[2] as $event ) {
                        $events[(string) $assignment[3]][] = (string) $event;
                    }
                }
            }
        }

        foreach ( $events as $selector => $selectorEvents ) {
            $events[$selector] = array_values(array_unique($selectorEvents));
        }

        return $events;
    }

    /**
     * @param array{kind: string, selector: string, events: array<int, string>, canvas_api: bool} $dependency
     * @param array{ids: array<string, bool>, classes: array<string, bool>} $targets
     */
    private function targetExists(array $dependency, array $targets): bool
    {
        $selector = (string) $dependency['selector'];
        if ( str_starts_with($selector, '#') ) {
            return isset($targets['ids'][substr($selector, 1)]);
        }
        if ( str_starts_with($selector, '.') ) {
            return isset($targets['classes'][substr($selector, 1)]);
        }

        return false;
    }

    private function scriptKind(string $path, string $script): string
    {
        $haystack = strtolower($path . "\n" . substr($script, 0, 2000));
        if ( str_contains($haystack, 'netlify') || str_contains($haystack, 'rum') || str_contains($haystack, 'analytics') || str_contains($haystack, 'gtag') ) {
            return 'telemetry';
        }

        return 'first_party';
    }

    /**
     * @param array<int, array{kind: string, selector: string, events: array<int, string>, canvas_api: bool, control_runtime?: bool}> $dependencies
     * @return array<int, array{kind: string, selector: string, events: array<int, string>, canvas_api: bool, control_runtime?: bool}>
     */
    private function dedupeDependencies(array $dependencies): array
    {
        $deduped = array();
        foreach ( $dependencies as $dependency ) {
            $selector = $dependency['selector'];
            if ( isset($deduped[$selector]) ) {
                $deduped[$selector]['events'] = array_values(array_unique(array_merge($deduped[$selector]['events'], $dependency['events'])));
                $deduped[$selector]['canvas_api'] = $deduped[$selector]['canvas_api'] || $dependency['canvas_api'];
                $deduped[$selector]['control_runtime'] = ( $deduped[$selector]['control_runtime'] ?? false ) || ( $dependency['control_runtime'] ?? false );
                continue;
            }
            $deduped[$selector] = $dependency;
        }

        return array_values($deduped);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function dedupeRows(array $rows): array
    {
        $seen = array();
        $deduped = array();
        foreach ( $rows as $row ) {
            $key = json_encode($row, JSON_UNESCAPED_SLASHES);
            if ( ! is_string($key) || isset($seen[$key]) ) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $row;
        }

        return $deduped;
    }
}
