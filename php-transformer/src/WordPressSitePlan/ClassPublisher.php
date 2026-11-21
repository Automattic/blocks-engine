<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeEntityManifest;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;
use InvalidArgumentException;

/**
 * The publish step: engine vocabulary leaves the plan in the theme's own names.
 *
 * Engine stages name their class hooks in an internal vocabulary
 * (`blocks-engine-*`, `be-*`) and recognize them by prefix. That vocabulary is
 * a protocol between stages, not something the site owner should inherit. Once
 * a site plan is complete, and only when the caller supplies a class namespace
 * (the theme slug), this one pass computes a single site-wide name map and
 * applies it to everything the plan ships:
 *
 * - block content (pages, templates, parts, patterns, menus, archive
 *   presentation): the block comment `className` and the rendered `class`
 *   change together, so serialized blocks stay valid;
 * - every delivered stylesheet and theme.json CSS, selectors only;
 * - delivered scripts and the theme bootstrap, whose emitted runtimes receive
 *   the map instead of matching an engine prefix.
 *
 * Each engine class is decided once. One no shipped stylesheet, script or
 * runtime names is removed from content. A referenced one becomes
 * `<namespace>-<semantic>`, the semantic part taken from a co-occurring
 * source class, then the block's name or landmark, then its block type;
 * collisions take `-2`, `-3`. A class runtime declarations or document roots
 * name is part of a contract this pass cannot see into, so it keeps its
 * internal name. When the published plan would not validate, the plan ships
 * unpublished with a finding instead.
 */
final class ClassPublisher
{
    public const DIAGNOSTIC = 'wordpress_site_plan_class_publish_skipped';

    /** The plan key that maps each renamed engine class to its published name. */
    public const MAP_KEY = 'published_class_names';

    private const ENGINE_CLASS = '/^(?:blocks-engine-|be-)[A-Za-z0-9_-]+$/D';

    /** An engine identifier read whole; `\b` in an emitted regex still bounds it. */
    private const ENGINE_IDENT = '/(?:(?<![A-Za-z0-9_-])|(?<=\\\\b))(?:blocks-engine-|be-)[A-Za-z0-9_-]+/';

    private const HTML_CLASS = '/(\sclass=")([^"]*)(")/';

    private const COMMENT_CLASS = '/"(?:className|class)":"([^"]*)"/';

    /** A block delimiter or a start tag carrying a class attribute, in document order. */
    private const MARKUP_EVENT = '/<!--\s+(\/?)wp:([a-z0-9\/-]+)(?:\s+(\{.*?\}))?\s+(\/?)-->|<([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*?\sclass="([^"]*)"/s';

    private const CLASS_SELECTOR = '/\.(-?[A-Za-z_][A-Za-z0-9_-]*)/';

    private const CLASS_PREDICATE = '/\[\s*class\s*[*^$|~]?=\s*(?:"([^"\\\\]*)"|\'([^\'\\\\]*)\'|([A-Za-z0-9_-]+))/';

    private const LANDMARKS = array('header', 'footer', 'main', 'nav', 'aside', 'section', 'article', 'form');

    private const SEMANTIC_MAX = 40;

    /** Markup groups whose block content ships. */
    private const UNIT_GROUPS = array('pages', 'template_parts', 'templates', 'synced_patterns', 'menus', 'taxonomy_entities', 'author_entities');

    /**
     * The class namespace a compile option names, as a class-name slug; null
     * when it names none.
     */
    public static function namespaceSlug(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $slug = self::slug($value);
        if ('' === $slug) return null;
        // A published name must never read as an engine class.
        return 1 === preg_match(self::ENGINE_CLASS, $slug . '-x') ? 'theme-' . $slug : $slug;
    }

    /**
     * The plan with every shipped artifact in published names, or the plan
     * unchanged with a finding when publishing would leave it invalid.
     *
     * @param array<string,mixed> $plan A complete plan, without its identity.
     * @return array<string,mixed>
     */
    public static function publish(array $plan, string $namespace): array
    {
        try {
            $published = self::published($plan, $namespace);
            $published['plan_identity'] = WordPressSitePlan::planIdentity($published);
            WordPressSitePlan::assertValid($published);
            unset($published['plan_identity']);
            return $published;
        } catch (InvalidArgumentException $exception) {
            return self::skipped($plan, $exception->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private static function published(array $plan, string $namespace): array
    {
        $units = array();
        foreach (self::UNIT_GROUPS as $group) if (is_array($plan[$group] ?? null)) $units[$group] = array_values($plan[$group]);
        $markups = UnreferencedEngineClasses::markups($units);
        $published = self::publishedAssetSources($plan);
        $stylesheets = array();
        $scripts = array();
        foreach ($plan['assets'] as $index => $asset) {
            if (!in_array($asset['kind'] ?? null, array('css', 'js'), true)) continue;
            if (!is_string($asset['content'] ?? null)) {
                if (isset($asset['payload_reference']) || is_string($asset['content_base64'] ?? null)) throw new InvalidArgumentException('A delivered stylesheet or script is not readable text, so what it names is unknown.');
                continue;
            }
            if ('css' === $asset['kind']) $stylesheets[$index] = $asset['content'];
            else $scripts[$index] = $asset['content'];
        }
        $writes = array_column($plan['writes'], null, 'target_path');
        $themeJson = self::themeJson($writes['theme.json'] ?? null);
        $themeCss = null === $themeJson ? array() : self::themeCss($themeJson);
        $bootstrap = is_string($writes['functions.php']['payload']['data'] ?? null) ? $writes['functions.php']['payload']['data'] : '';

        // What names engine classes outside block content.
        $cssText = implode("\n", array_merge($stylesheets, $themeCss));
        $cssClasses = self::selectorClasses($cssText);
        $runtimeText = $bootstrap . "\n" . implode("\n", $scripts);
        $pinned = self::idents((string) json_encode(array($plan['runtime_declarations'], $plan['runtime_records'] ?? array(), $plan['runtime_entity_records'], $plan['theme']['document_roots'] ?? array(), $plan['visual_repair'], array_column($plan['pages'], 'document_metadata'), array_column($plan['template_parts'], 'document_metadata')), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        $predicates = self::predicates($cssText . "\n" . $runtimeText);

        // What block content carries.
        $occurrences = array();
        $order = array();
        $taken = array();
        $listed = array();
        $outside = array();
        foreach ($markups as $markup) {
            foreach (self::occurrences($markup) as $occurrence) {
                $token = $occurrence['token'];
                $listed[$token] = true;
                $order[$token] ??= count($order);
                $occurrences[$token][] = $occurrence;
            }
            foreach (self::classLists($markup) as $list) foreach (self::tokens($list['value']) as $token) if (1 !== preg_match(self::ENGINE_CLASS, $token)) $taken[$token] = true;
            $outside += self::idents(self::withoutClassLists($markup));
        }
        foreach ($cssClasses as $class => $_) {
            if (1 === preg_match(self::ENGINE_CLASS, $class)) $order[$class] ??= count($order);
            else $taken[$class] = true;
        }
        foreach ($order as $token => $_) {
            if (self::predicateNames($token, $predicates)) $pinned[$token] = true;
        }
        $referenced = $cssClasses + self::idents($runtimeText) + $outside + $pinned;
        $drop = self::droppable($markups, array_diff_key($listed, $referenced));

        // One name for each class that stays, in first-occurrence order. A
        // specificity shim matches nothing by construction, whatever names it.
        $map = array();
        foreach (self::selectorShims($cssText) as $shim => $_) {
            $name = $namespace . '-weight-' . str_replace('-', '', substr($shim, 3));
            for ($suffix = 2; isset($taken[$name]); ++$suffix) $name = $namespace . '-weight-' . str_replace('-', '', substr($shim, 3)) . '-' . $suffix;
            $taken[$name] = true;
            $map[$shim] = $name;
        }
        foreach ($order as $token => $_) {
            if (isset($drop[$token]) || isset($pinned[$token]) || isset($map[$token])) continue;
            $semantic = isset($occurrences[$token]) ? self::semantic($occurrences[$token], $namespace) : '';
            if ('' === $semantic) $semantic = self::internalSemantic($token, $namespace);
            $name = $namespace . '-' . $semantic;
            for ($suffix = 2; isset($taken[$name]); ++$suffix) $name = $namespace . '-' . $semantic . '-' . $suffix;
            $taken[$name] = true;
            $map[$token] = $name;
        }
        if (array() === $map && array() === $drop) return $plan + array(self::MAP_KEY => array());

        $rewrite = static fn (string $markup): string => self::renamed(UnreferencedEngineClasses::without($markup, $drop), $map);
        $plan = self::withMarkup($plan, $markups, $rewrite);
        foreach ($plan['assets'] as $index => $asset) {
            if (isset($published[$asset['source_path'] ?? ''])) continue;
            if (isset($stylesheets[$index])) $content = self::renamedSelectors($asset['content'], $map);
            elseif (isset($scripts[$index])) $content = self::renamed($asset['content'], $map);
            else continue;
            if ($content !== $asset['content']) $plan['assets'][$index] = CompiledAssets::withRewrittenTextContent($asset, $content);
        }
        $kinds = array_column($plan['assets'], 'kind', 'target_path');
        foreach ($plan['writes'] as $index => $write) {
            if ('utf8' !== ($write['payload']['encoding'] ?? null) || !is_string($write['payload']['data'] ?? null)) continue;
            $data = $write['payload']['data'];
            $target = (string) $write['target_path'];
            $published_data = match (true) {
                in_array($write['kind'] ?? null, array('theme_template', 'theme_template_part'), true) => $rewrite($data),
                'theme_asset' === ($write['kind'] ?? null) && isset($published[$write['source_path'] ?? '']) => $data,
                'theme_asset' === ($write['kind'] ?? null) && 'css' === ($kinds[$target] ?? null) => self::renamedSelectors($data, $map),
                'theme_asset' === ($write['kind'] ?? null) && 'js' === ($kinds[$target] ?? null) => self::renamed($data, $map),
                'theme.json' === $target => self::publishedThemeJson($data, $map),
                'functions.php' === $target => self::publishedBootstrap($data, $map, $namespace),
                default => $data,
            };
            if ($published_data === $data) continue;
            $plan['writes'][$index]['payload']['data'] = $published_data;
            $plan['writes'][$index]['payload_hash'] = WordPressSitePlan::contentHash($published_data);
        }
        $plan = self::withIntegrity($plan);
        $runtimeRecords = $plan['runtime_records'] ?? array();
        $plan['pages'] = WholePageCandidates::attach(array_map(static function (array $page): array { unset($page['whole_page_candidates']); return $page; }, $plan['pages']), RuntimeDeclarations::materialize($plan['runtime_declarations'], $runtimeRecords), $plan['runtime_entity_records']);
        ksort($map, SORT_STRING);
        $plan[self::MAP_KEY] = $map;
        $plan['quality']['metrics'] = array_merge($plan['quality']['metrics'], UnreferencedEngineClasses::metrics($plan));
        return $plan;
    }

    /**
     * Every shipped markup rewritten, with runtime bindings re-anchored on
     * the rewritten documents.
     *
     * @param array<string,mixed> $plan
     * @param array<string,string> $markups
     * @param callable(string):string $rewrite
     * @return array<string,mixed>
     */
    private static function withMarkup(array $plan, array $markups, callable $rewrite): array
    {
        $before = array();
        $after = array();
        $groups = array();
        foreach ($markups as $key => $markup) {
            [$group, $index, $field] = explode("\0", $key);
            $groups[$group] ??= array_keys($plan[$group]);
            $planIndex = $groups[$group][(int) $index];
            $published = $rewrite($markup);
            if ('archive' === $field) {
                $plan[$group][$planIndex]['archive']['presentation_markup'] = $published;
                continue;
            }
            if (is_string($plan[$group][$planIndex]['source_path'] ?? null) && 'canonical_block_markup' === $field) {
                $before[$plan[$group][$planIndex]['source_path']] = $markup;
                $after[$plan[$group][$planIndex]['source_path']] = $published;
            }
            if ($published === $markup) continue;
            $plan[$group][$planIndex][$field] = $published;
            if (isset($plan[$group][$planIndex]['content_hash']) && ('canonical_block_markup' === $field || !isset($plan[$group][$planIndex]['canonical_block_markup']))) $plan[$group][$planIndex]['content_hash'] = WordPressSitePlan::contentHash($published);
        }
        if (!self::hasMovedBindings($plan, $before, $after)) return $plan;
        $declarations = RuntimeDeclarations::materialize($plan['runtime_declarations'], $plan['runtime_records'] ?? array());
        $rebased = BindingAnchors::rebased($declarations, $plan['runtime_entity_records'], $before, $after, $rewrite);
        if (null === $rebased) throw new InvalidArgumentException('A runtime binding would not survive publishing byte for byte.');
        $factored = RuntimeDeclarations::factor($rebased['declarations']);
        if (array() !== $factored['diagnostics']) throw new InvalidArgumentException('Re-anchored runtime declarations no longer fit the plan.');
        $plan['runtime_declarations'] = $factored['declarations'];
        $plan['runtime_records'] = $factored['records'];
        $plan['runtime_entity_records'] = array() === $factored['entity_records'] ? $rebased['records'] : RuntimeEntityManifest::normalizeRecords(array_values(array_column(array_merge($rebased['records'], $factored['entity_records']), null, 'content_hash')));
        return $plan;
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,string> $before
     * @param array<string,string> $after
     */
    private static function hasMovedBindings(array $plan, array $before, array $after): bool
    {
        $changed = array();
        foreach ($before as $source => $markup) if ($markup !== $after[$source]) $changed[$source] = true;
        if (array() === $changed) return false;
        $declarations = BindingAnchors::anchoredDeclarations($plan, RuntimeDeclarations::normalizeRecords($plan['runtime_records'] ?? array()), $plan['runtime_entity_records']);
        foreach ($declarations as $declaration) foreach ($declaration['payload']['entities'] ?? array() as $entity) {
            foreach (is_array($entity) && is_array($entity['bindings'] ?? null) ? $entity['bindings'] : array() as $binding) {
                if (isset($changed[$binding['source_path'] ?? ''])) return true;
            }
        }
        return false;
    }

    /**
     * Declared integrity describes the served bytes, so it follows the
     * published stylesheets and scripts, in the documents and the bootstrap.
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private static function withIntegrity(array $plan): array
    {
        $integrity = new SubresourceIntegrity($plan['writes'], $plan['reference_tokens']);
        $replacements = array();
        foreach (array('pages', 'template_parts') as $group) {
            $documents = $integrity->documents($plan[$group]);
            foreach ($documents as $index => $document) foreach (array('links', 'scripts') as $kind) foreach ($plan[$group][$index]['document_metadata'][$kind] ?? array() as $row => $declaration) {
                $old = is_array($declaration) ? ($declaration['integrity'] ?? null) : null;
                $new = $document['document_metadata'][$kind][$row]['integrity'] ?? null;
                if (is_string($old) && is_string($new) && $old !== $new) $replacements[$old] = $new;
            }
            $plan[$group] = $documents;
        }
        if (array() === $replacements) return $plan;
        foreach ($plan['writes'] as $index => $write) {
            if ('functions.php' !== ($write['target_path'] ?? null) || !is_string($write['payload']['data'] ?? null)) continue;
            $data = strtr($write['payload']['data'], $replacements);
            $plan['writes'][$index]['payload']['data'] = $data;
            $plan['writes'][$index]['payload_hash'] = WordPressSitePlan::contentHash($data);
        }
        return $plan;
    }

    /**
     * Assets an asset publication declaration pins by content hash.
     *
     * @param array<string,mixed> $plan
     * @return array<string,true>
     */
    private static function publishedAssetSources(array $plan): array
    {
        $sources = array();
        foreach (RuntimeDeclarations::materialize($plan['runtime_declarations'], $plan['runtime_records'] ?? array()) as $declaration) {
            if ('asset_publication' === ($declaration['kind'] ?? null) && is_string($declaration['source_path'] ?? null)) $sources[$declaration['source_path']] = true;
        }
        return $sources;
    }

    /**
     * Every engine class in a class list, with the semantic evidence of the
     * element and block that carry it.
     *
     * @return list<array{token:string,level:int,semantic:string}>
     */
    private static function occurrences(string $markup): array
    {
        if (!preg_match_all(self::MARKUP_EVENT, $markup, $events, PREG_SET_ORDER)) return array();
        $stack = array();
        $occurrences = array();
        foreach ($events as $event) {
            if ('' !== ($event[2] ?? '')) {
                if ('/' === $event[1]) {
                    array_pop($stack);
                    continue;
                }
                $attributes = json_decode($event[3] ?? '', true);
                $attributes = is_array($attributes) ? $attributes : array();
                $block = array('name' => $event[2], 'attributes' => $attributes);
                foreach (array('className', 'class') as $key) {
                    if (is_string($attributes[$key] ?? null)) array_push($occurrences, ...self::listOccurrences($attributes[$key], $block, null));
                }
                if ('/' !== ($event[4] ?? '')) $stack[] = $block;
                continue;
            }
            $block = end($stack);
            array_push($occurrences, ...self::listOccurrences(html_entity_decode($event[6], ENT_QUOTES | ENT_HTML5), false === $block ? null : $block, strtolower($event[5])));
        }
        return $occurrences;
    }

    /**
     * @param array{name:string,attributes:array<string,mixed>}|null $block
     * @return list<array{token:string,level:int,semantic:string}>
     */
    private static function listOccurrences(string $value, ?array $block, ?string $tag): array
    {
        $tokens = self::tokens($value);
        $engine = array_values(array_filter($tokens, static fn (string $token): bool => 1 === preg_match(self::ENGINE_CLASS, $token)));
        if (array() === $engine) return array();
        $candidates = array();
        foreach ($tokens as $token) {
            if (1 === preg_match(self::ENGINE_CLASS, $token) || 1 === preg_match('/^(?:wp-|has-|is-|align(?:left|right|center|wide|full)$)/', $token)) continue;
            $candidates[] = array(0, $token);
            break;
        }
        $name = $block['attributes']['metadata']['name'] ?? null;
        if (is_string($name)) $candidates[] = array(1, $name);
        $landmark = is_string($block['attributes']['tagName'] ?? null) ? strtolower($block['attributes']['tagName']) : $tag;
        if (in_array($landmark, self::LANDMARKS, true)) $candidates[] = array(1, (string) $landmark);
        if (null !== $block) $candidates[] = array(2, substr($block['name'], (int) strrpos('/' . $block['name'], '/')));
        $level = 3;
        $semantic = '';
        foreach ($candidates as [$candidateLevel, $candidate]) {
            $slug = self::slug($candidate);
            if ('' === $slug) continue;
            $level = $candidateLevel;
            $semantic = $slug;
            break;
        }
        return array_map(static fn (string $token): array => array('token' => $token, 'level' => $level, 'semantic' => $semantic), $engine);
    }

    /**
     * The semantic a class's occurrences agree on: the strongest evidence,
     * then the most frequent, then the first.
     *
     * @param list<array{token:string,level:int,semantic:string}> $occurrences
     */
    private static function semantic(array $occurrences, string $namespace): string
    {
        $best = min(array_column($occurrences, 'level'));
        $counts = array();
        foreach ($occurrences as $occurrence) if ($best === $occurrence['level'] && '' !== $occurrence['semantic']) $counts[$occurrence['semantic']] = ($counts[$occurrence['semantic']] ?? 0) + 1;
        if (array() === $counts) return '';
        $top = max($counts);
        foreach ($counts as $semantic => $count) if ($top === $count) return self::bounded((string) $semantic, $namespace);
        return '';
    }

    /** A class only stylesheets or runtimes name reads as what it is for. */
    private static function internalSemantic(string $token, string $namespace): string
    {
        $parts = explode('-', (string) preg_replace('/^(?:blocks-engine-|be-)/', '', $token));
        $parts = array_filter($parts, static fn (string $part): bool => '' !== $part && 1 !== preg_match('/^(?:\d+|[a-f0-9]{8,})$/', $part));
        $semantic = self::bounded(self::slug(implode('-', $parts)), $namespace);
        return '' === $semantic ? 'style' : $semantic;
    }

    private static function bounded(string $semantic, string $namespace): string
    {
        if (str_starts_with($semantic, $namespace . '-')) $semantic = substr($semantic, strlen($namespace) + 1);
        if (strlen($semantic) > self::SEMANTIC_MAX) $semantic = rtrim(substr($semantic, 0, self::SEMANTIC_MAX), '-');
        return $semantic;
    }

    private static function slug(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-');
    }

    /**
     * Unreferenced classes whose removal leaves every carrier at least one
     * class: an emptied class attribute could change what matches it.
     *
     * @param array<string,string> $markups
     * @param array<string,true> $candidates
     * @return array<string,true>
     */
    private static function droppable(array $markups, array $candidates): array
    {
        if (array() === $candidates) return array();
        foreach ($markups as $markup) {
            if (!preg_match_all(self::HTML_CLASS, $markup, $matches)) continue;
            foreach ($matches[2] as $value) {
                $tokens = self::tokens(html_entity_decode($value, ENT_QUOTES | ENT_HTML5));
                if (array() !== $tokens && array() === array_diff($tokens, array_keys($candidates))) foreach ($tokens as $token) unset($candidates[$token]);
            }
        }
        return $candidates;
    }

    /** @param list<string> $predicates */
    private static function predicateNames(string $token, array $predicates): bool
    {
        foreach ($predicates as $predicate) if (str_contains($token, $predicate) || str_contains($predicate, $token)) return true;
        return false;
    }

    /** @return list<string> Values class attribute predicates test. */
    private static function predicates(string $text): array
    {
        if (!str_contains($text, 'class') || !preg_match_all(self::CLASS_PREDICATE, $text, $matches, PREG_SET_ORDER)) return array();
        $values = array();
        foreach ($matches as $match) {
            $value = ($match[1] ?? '') . ($match[2] ?? '') . ($match[3] ?? '');
            if ('' !== trim($value)) $values[$value] = true;
        }
        return array_keys($values);
    }

    /** @return array<string,true> */
    private static function idents(string $text): array
    {
        return preg_match_all(self::ENGINE_IDENT, $text, $matches) ? array_fill_keys($matches[0], true) : array();
    }

    /** @param array<string,string> $map */
    private static function renamed(string $text, array $map): string
    {
        if (array() === $map) return $text;
        return preg_replace_callback(self::ENGINE_IDENT, static fn (array $match): string => $map[$match[0]] ?? $match[0], $text) ?? $text;
    }

    /**
     * Class selectors renamed in each rule prelude; declarations, strings
     * and comments keep their bytes.
     *
     * @param array<string,string> $map
     */
    public static function renamedSelectors(string $css, array $map): string
    {
        if (array() === $map || (!str_contains($css, 'blocks-engine-') && !str_contains($css, 'be-'))) return $css;
        $published = '';
        $prelude = '';
        foreach (self::cssPieces($css) as $piece) {
            if ('{' === $piece) {
                $prelude = preg_replace_callback(self::CLASS_SELECTOR, static fn (array $match): string => isset($map[$match[1]]) ? '.' . $map[$match[1]] : $match[0], $prelude) ?? $prelude;
                // A type or id shim is no class selector, but its name is engine vocabulary too.
                $published .= preg_replace_callback(self::shimPattern(), static fn (array $match): string => $map[$match[0]] ?? $match[0], $prelude) . '{';
                $prelude = '';
            } elseif ('}' === $piece || ';' === $piece) {
                $published .= $prelude . $piece;
                $prelude = '';
            } else {
                $prelude .= $piece;
            }
        }
        return $published . $prelude;
    }

    /** @return array<string,true> Every class a rule prelude in `$css` names. */
    private static function selectorClasses(string $css): array
    {
        $classes = array();
        $prelude = '';
        foreach (self::cssPieces($css) as $piece) {
            if ('{' === $piece) {
                $stripped = (string) preg_replace('~/\*.*?\*/|"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'~s', '', $prelude);
                if (preg_match_all(self::CLASS_SELECTOR, $stripped, $matches)) foreach ($matches[1] as $class) $classes[$class] = true;
                $prelude = '';
            } elseif ('}' === $piece || ';' === $piece) {
                $prelude = '';
            } else {
                $prelude .= $piece;
            }
        }
        return $classes;
    }

    /** @return array<string,true> Every specificity shim a rule prelude in `$css` names, as a class, type or id. */
    private static function selectorShims(string $css): array
    {
        $shims = array();
        $prelude = '';
        foreach (self::cssPieces($css) as $piece) {
            if ('{' === $piece) {
                if (preg_match_all(self::shimPattern(), $prelude, $matches)) foreach ($matches[0] as $shim) $shims[$shim] = true;
                $prelude = '';
            } elseif ('}' === $piece || ';' === $piece) {
                $prelude = '';
            } else {
                $prelude .= $piece;
            }
        }
        return $shims;
    }

    private static function shimPattern(): string
    {
        return '/(?<![A-Za-z0-9_-])' . EngineMarker::specificityPatternBody() . '(?![A-Za-z0-9_-])/';
    }

    /** @return list<string> Text, comments, strings and structural characters, in order. */
    private static function cssPieces(string $css): array
    {
        return preg_split('~(/\*.*?(?:\*/|$)|"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|[{};])~s', $css, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: array();
    }

    private static function themeJson(mixed $write): ?object
    {
        if (!is_array($write) || !is_string($write['payload']['data'] ?? null)) return null;
        $theme = json_decode($write['payload']['data']);
        return $theme instanceof \stdClass ? $theme : null;
    }

    /** @return list<string> */
    private static function themeCss(object $theme): array
    {
        $css = array();
        $walk = static function (mixed $node) use (&$walk, &$css): void {
            if (!is_object($node) && !is_array($node)) return;
            foreach ((array) $node as $key => $value) {
                if ('css' === $key && is_string($value)) $css[] = $value;
                else $walk($value);
            }
        };
        $walk($theme->styles ?? null);
        return $css;
    }

    /** @param array<string,string> $map */
    private static function publishedThemeJson(string $data, array $map): string
    {
        $theme = json_decode($data);
        if (!$theme instanceof \stdClass || !isset($theme->styles)) return $data;
        $changed = false;
        $walk = static function (object|array $node) use (&$walk, &$changed, $map): object|array {
            foreach ($node as $key => $value) {
                if ('css' === $key && is_string($value)) {
                    $published = self::renamedSelectors($value, $map);
                    $changed = $changed || $published !== $value;
                    $value = $published;
                } elseif (is_object($value) || is_array($value)) {
                    $value = $walk($value);
                }
                if (is_object($node)) $node->{$key} = $value;
                else $node[$key] = $value;
            }
            return $node;
        };
        $theme->styles = $walk($theme->styles);
        return $changed ? json_encode($theme, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n" : $data;
    }

    /**
     * The bootstrap in published names. Emitted runtimes that recognize an
     * engine class by prefix read the published class back to its engine name
     * first, and one that composes an engine class reads its published name.
     *
     * @param array<string,string> $map
     */
    private static function publishedBootstrap(string $php, array $map, string $namespace): string
    {
        $function = str_replace('-', '_', preg_match('/^[a-z]/', $namespace) ? $namespace : 'theme_' . $namespace);
        $matchers = array_merge(InlineSvgImageRuntime::PUBLISHED_CLASS_MATCHERS, RuntimeAttributesRuntime::PUBLISHED_CLASS_MATCHERS, NavigationAnchorRuntime::PUBLISHED_CLASS_MATCHERS);
        $rewritten = $php;
        foreach ($matchers as $matcher => $published) $rewritten = str_replace($matcher, str_replace('__PUBLISHED__', $function, $published), $rewritten);
        $helpers = $rewritten !== $php;
        $rewritten = self::renamed($rewritten, $map);
        if (!$helpers || !str_starts_with($rewritten, "<?php\n")) return $rewritten;
        $names = var_export($map, true);
        $source = "function {$function}_published_class_names(): array {\n    static \$names = {$names};\n    return \$names;\n}\n"
            . "function {$function}_published_class( string \$class ): string {\n    return {$function}_published_class_names()[ \$class ] ?? \$class;\n}\n"
            . "function {$function}_engine_class( string \$class ): string {\n    static \$engine = null;\n    \$engine ??= array_flip( {$function}_published_class_names() );\n    return \$engine[ \$class ] ?? \$class;\n}\n";
        return "<?php\n" . $source . substr($rewritten, strlen("<?php\n"));
    }

    /**
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private static function skipped(array $plan, string $reason): array
    {
        $diagnostic = array('code' => self::DIAGNOSTIC, 'severity' => 'warning', 'message' => 'Engine classes keep their internal names: ' . $reason, 'source' => self::class);
        $plan['diagnostics'][] = $diagnostic;
        $plan['reporting']['diagnostic_codes'][] = self::DIAGNOSTIC;
        $plan['quality']['status'] = UnitFindings::status($plan['quality']['status'], array($diagnostic));
        return $plan;
    }

    /** @return list<array{context:string,value:string}> */
    private static function classLists(string $markup): array
    {
        $lists = array();
        if (preg_match_all(self::HTML_CLASS, $markup, $matches)) foreach ($matches[2] as $value) $lists[] = array('context' => 'html', 'value' => html_entity_decode($value, ENT_QUOTES | ENT_HTML5));
        if (preg_match_all(self::COMMENT_CLASS, $markup, $matches)) foreach ($matches[1] as $value) $lists[] = array('context' => 'comment', 'value' => $value);
        return $lists;
    }

    private static function withoutClassLists(string $markup): string
    {
        return (string) preg_replace(array(self::HTML_CLASS, self::COMMENT_CLASS), '', $markup);
    }

    /** @return list<string> */
    private static function tokens(string $value): array
    {
        return preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: array();
    }
}
