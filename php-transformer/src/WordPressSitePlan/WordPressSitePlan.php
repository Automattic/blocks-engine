<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use InvalidArgumentException;

/** A complete, destination-independent block-theme materialization contract. */
final class WordPressSitePlan
{
    public const SCHEMA = 'blocks-engine/wordpress-site-plan/v2';
    public const TOKEN_PREFIX = '{{wordpress-site-plan:asset:';

    /** @return array<string,mixed> */
    public function fromResult(TransformerResult|array $result): array
    {
        $data = $result instanceof TransformerResult ? $result->toArray() : $result;
        TransformerResult::assertCanonicalEnvelope($data);
        $compiled = $data['source_reports']['compiled_site'] ?? null;
        $materialization = $data['source_reports']['materialization_plan'] ?? null;
        if ( ! is_array($compiled) || ! is_array($materialization) ) {
            throw new InvalidArgumentException('WordPress site plan requires compiled-site and materialization-plan reports.');
        }

        $assets = $this->assets($compiled['assets'] ?? null);
        $runtimeDeclarations = $compiled['runtime_declarations'] ?? array();
        $assets = $this->applyDeclaredAssetTransformations($assets, $runtimeDeclarations);
        $tokens = $this->tokens($assets);
        $references = new AssetReferenceCanonicalizer($tokens);
        $routeMap = $this->canonicalRoutes($compiled['pages'] ?? null, is_array($materialization['routes'] ?? null) ? $materialization['routes'] : array());
        $pages = $this->documents($compiled['pages'] ?? null, false, $tokens, $references, $routeMap);
        $pages = $this->pageHierarchy($pages, $routeMap);
        $routes = $this->routesForPages($pages);
        // Entry shells remain in compiled-site/v1 for existing consumers; the
        // canonical plan rebuilds them from full page shell candidates.
        $compiledParts = is_array($compiled['template_parts'] ?? null) ? array_values(array_filter($compiled['template_parts'], static fn(mixed $part): bool => !is_array($part) || 'entry_shell' !== ($part['placement']['kind'] ?? null))) : null;
        $existingParts = $this->documents($compiledParts, true, $tokens, $references, $routeMap);
        $shells = $this->sharedShells($pages, array_fill_keys(array_column($existingParts, 'slug'), true));
        $pages = $shells['pages'];
        $parts = array_merge($existingParts, $shells['parts']);
        $templates = $this->templates($pages, $parts);
        $operations = $this->operations($pages);
        $scriptLoading = $this->scriptLoading($pages, $parts, $assets, $tokens, $operations);
        $writes = array_merge($this->scaffoldWrites($assets, $templates, $parts, $scriptLoading['scripts']), $this->assetWrites($assets, $references));
        $plan = array(
            'schema' => self::SCHEMA,
            'source' => array('schema' => $compiled['schema'] ?? null, 'source_hash' => $compiled['source_hash'] ?? null, 'entry_path' => $compiled['entry_path'] ?? null, 'provenance' => $data['provenance']),
            'pages' => $pages,
            'templates' => $templates,
            'template_parts' => $parts,
            'assets' => $assets,
            'reference_tokens' => $tokens,
            'reference_semantics' => array('static_browser_references' => 'declared_tokens_only', 'dynamic_script_references' => array() === $scriptLoading['diagnostics'] ? 'proven' : 'not_proven', 'dynamic_client_assets' => array('status' => array() === $scriptLoading['diagnostics'] ? 'proven' : 'not_proven', 'materializer_may_reject' => array() !== $scriptLoading['diagnostics'])),
            'writes' => $writes,
            'operations' => $operations,
            'routes' => $routes,
            'navigation_links' => $materialization['navigation_links'] ?? null,
            'menus' => $materialization['menus'] ?? null,
            'theme' => array('stylesheet' => 'style.css', 'theme_json' => 'theme.json', 'bootstrap' => self::needsBootstrap($assets, $scriptLoading['scripts']) ? 'functions.php' : null),
            'visual_repair' => $compiled['visual_repair'] ?? array(),
            'runtime_declarations' => $runtimeDeclarations,
            'diagnostics' => array_merge($data['diagnostics'], $shells['diagnostics'], $scriptLoading['diagnostics']),
            'quality' => array('status' => $data['status'], 'pass' => 'failed' !== $data['status'], 'metrics' => array_diff_key($data['metrics'], array('transform_duration_ms' => true)), 'fallbacks' => $data['fallbacks']),
            'reporting' => $this->reporting($pages, $data, array_merge($shells['diagnostics'], $scriptLoading['diagnostics'])),
        );
        self::assertValid($plan);
        return $plan;
    }

    /** @param array<string,mixed> $plan */
    public static function assertValid(array $plan): void
    {
        if ( self::SCHEMA !== ($plan['schema'] ?? null) ) {
            throw new InvalidArgumentException('WordPress site plan has an unsupported schema.');
        }
        foreach ( array('source', 'pages', 'templates', 'template_parts', 'assets', 'reference_tokens', 'reference_semantics', 'writes', 'operations', 'routes', 'navigation_links', 'menus', 'theme', 'visual_repair', 'runtime_declarations', 'diagnostics', 'quality', 'reporting') as $key ) {
            if ( ! is_array($plan[$key] ?? null) ) {
                throw new InvalidArgumentException(sprintf('WordPress site plan %s must be an array.', $key));
            }
        }
        self::assertSource($plan['source']);
        RuntimeDeclarations::assertNormalized($plan['runtime_declarations']);
        if ('declared_tokens_only' !== ($plan['reference_semantics']['static_browser_references'] ?? null) || !in_array($plan['reference_semantics']['dynamic_script_references'] ?? null, array('proven', 'not_proven'), true) || !is_array($plan['reference_semantics']['dynamic_client_assets'] ?? null) || !in_array($plan['reference_semantics']['dynamic_client_assets']['status'] ?? null, array('proven', 'not_proven'), true) || !is_bool($plan['reference_semantics']['dynamic_client_assets']['materializer_may_reject'] ?? null) || ($plan['reference_semantics']['dynamic_script_references'] ?? null) !== ($plan['reference_semantics']['dynamic_client_assets']['status'] ?? null) || ('proven' === $plan['reference_semantics']['dynamic_client_assets']['status'] && true === $plan['reference_semantics']['dynamic_client_assets']['materializer_may_reject'])) throw new InvalidArgumentException('WordPress site plan reference capability semantics are invalid.');
        self::assertRows($plan['routes'], 'route', array('kind', 'source_path', 'target_path', 'target_slug', 'source_relation', 'order'));
        self::assertRows($plan['navigation_links'], 'navigation link', array('kind', 'source_path', 'source_relation', 'order'), array('target_path', 'target_slug'));
        self::assertRows($plan['menus'], 'menu', array('kind', 'source_path', 'target_slug', 'source_relation', 'order', 'items'));
        $assetTargets = array();
        $assetTokens = array();
        $assetIdentities = array();
        foreach ( $plan['assets'] as $asset ) {
            $assetContent = is_array($asset) ? (is_string($asset['content_base64'] ?? null) ? $asset['content_base64'] : ($asset['content'] ?? null)) : null;
            if ( ! is_array($asset) || ! self::safePath($asset['source_path'] ?? null) || ! self::safePath($asset['target_path'] ?? null) || !is_string($asset['source'] ?? null) || !is_string($asset['role'] ?? null) || !is_string($asset['mime_type'] ?? null) || !is_int($asset['bytes'] ?? null) || $asset['bytes'] < 0 || !is_string($asset['token'] ?? null) || !self::hash($asset['reconciliation_identity'] ?? null) || !self::hash($asset['content_hash'] ?? null) || !is_string($assetContent) || $asset['reconciliation_identity'] !== self::identity('asset', $asset['source_path'], $asset['target_path']) || $asset['content_hash'] !== self::contentHash($assetContent) ) {
                throw new InvalidArgumentException('WordPress site plan asset is structurally invalid.');
            }
            self::unique($assetTargets, $asset['target_path'], 'asset target');
            self::unique($assetIdentities, $asset['reconciliation_identity'], 'asset reconciliation identity');
            $assetTokens[strtolower($asset['target_path'])] = $asset['token'];
        }
        $tokens = array();
        foreach ( $plan['reference_tokens'] as $reference ) {
            if ( ! is_array($reference) || ! is_string($reference['token'] ?? null) || ! self::safePath($reference['source_path'] ?? null) || ! self::safePath($reference['target_path'] ?? null) || ! isset($assetTargets[strtolower($reference['target_path'])]) || $assetTokens[strtolower($reference['target_path'])] !== $reference['token'] || ! preg_match('/^asset-[a-f0-9]{16}$/', $reference['token']) ) {
                throw new InvalidArgumentException('WordPress site plan has an invalid reference token declaration.');
            }
            self::unique($tokens, $reference['token'], 'reference token');
        }
        if ( count($tokens) !== count($assetTargets) ) {
            throw new InvalidArgumentException('WordPress site plan must declare exactly one token for each asset.');
        }
        $partSlugs = array();
        foreach ( $plan['template_parts'] as $part ) {
            self::assertDocument($part, 'template part', true, $tokens);
            if ($part['content_hash'] !== self::contentHash($part['canonical_block_markup'])) throw new InvalidArgumentException('WordPress site plan template part has a stale content hash.');
            self::unique($partSlugs, $part['slug'], 'template part slug');
        }
        $pagePaths = array(); $pagesBySource = array(); $documentIdentities = array();
        $entryRoot = self::entryRootFromDocuments($plan['pages']);
        foreach ( $plan['pages'] as $page ) {
            self::assertDocument($page, 'page', false, $tokens);
            if ($page['content_hash'] !== self::contentHash($page['canonical_block_markup'])) throw new InvalidArgumentException('WordPress site plan page has a stale content hash.');
            self::assertRoute($page, $entryRoot);
            self::unique($pagePaths, $page['source_path'], 'page source');
            self::unique($documentIdentities, $page['reconciliation_identity'], 'page reconciliation identity');
            $pagesBySource[$page['source_path']] = $page;
        }
        $routeSources = array(); foreach ($plan['routes'] as $route) { self::unique($routeSources, $route['source_path'], 'route source'); $page = $pagesBySource[$route['source_path']] ?? null; if (!is_array($page) || $route['target_path'] !== $page['route']['path'] || $route['target_slug'] !== $page['slug']) throw new InvalidArgumentException('WordPress site plan routes do not match canonical page routes.'); }
        if (count($routeSources) !== count($pagePaths)) throw new InvalidArgumentException('WordPress site plan must export every canonical page route.');
        self::assertReporting($plan['reporting'], $pagePaths, $tokens, $plan['diagnostics']);
        self::assertOperations($plan['operations'], $plan['pages']);
        $templateTargets = array();
        foreach ( $plan['templates'] as $template ) {
            if ( ! is_array($template) || ! is_string($template['slug'] ?? null) || ! self::safePath($template['target_path'] ?? null) || ! is_string($template['canonical_block_markup'] ?? null) || '' === trim($template['canonical_block_markup']) || !self::hash($template['reconciliation_identity'] ?? null) || !self::hash($template['content_hash'] ?? null) || $template['reconciliation_identity'] !== self::identity('template', 'wordpress-site-plan/' . $template['target_path'], $template['target_path']) || $template['content_hash'] !== self::contentHash($template['canonical_block_markup']) ) {
                throw new InvalidArgumentException('WordPress site plan template is structurally invalid.');
            }
            self::unique($templateTargets, $template['target_path'], 'template target');
            self::assertTokens($template['canonical_block_markup'], $tokens);
            self::assertNoLocalBrowserReferences($template['canonical_block_markup']);
        }
        $writeTargets = array();
        $writesByTarget = array();
        foreach ( $plan['writes'] as $write ) {
            self::assertWrite($write, $tokens);
            self::unique($writeTargets, $write['target_path'], 'write target');
            $writesByTarget[$write['target_path']] = $write;
        }
        self::assertResolution($plan, $tokens, $writesByTarget);
        self::assertScaffold($plan, $writesByTarget);
        foreach ( $plan['templates'] as $template ) {
            $write = $writesByTarget[$template['target_path']] ?? null;
            $expected = isset($plan['resolution']) ? $template['resolved_block_markup'] : $template['canonical_block_markup'];
            if ( ! is_array($write) || 'theme_template' !== ($write['kind'] ?? null) || $write['payload']['data'] !== $expected ) {
                throw new InvalidArgumentException('WordPress site plan template lacks its canonical write.');
            }
        }
        foreach ( $plan['template_parts'] as $part ) {
            $target = 'parts/' . $part['slug'] . '.html';
            $write = $writesByTarget[$target] ?? null;
            $expected = isset($plan['resolution']) ? $part['resolved_block_markup'] : $part['canonical_block_markup'];
            if ( ! is_array($write) || 'theme_template_part' !== ($write['kind'] ?? null) || $write['payload']['data'] !== $expected ) {
                throw new InvalidArgumentException('WordPress site plan template part lacks its canonical write.');
            }
            $boundTemplates = in_array($part['placement']['kind'] ?? null, array('entry_shell', 'shared_shell'), true) ? $part['placement']['template_slugs'] : array();
            foreach ( $plan['templates'] as $template ) {
                $references = substr_count($template['canonical_block_markup'], '"slug":"' . $part['slug'] . '"');
                if (in_array($template['slug'], $boundTemplates, true) && 1 !== $references) throw new InvalidArgumentException('WordPress site plan template part binding is invalid.');
                if (!in_array($template['slug'], $boundTemplates, true) && 0 !== $references) throw new InvalidArgumentException('WordPress site plan has an unproven template part binding.');
            }
        }
        foreach ( $plan['assets'] as $asset ) {
            $target = $asset['target_path'];
            if ( ! isset($writesByTarget[$target]) || 'theme_asset' !== ($writesByTarget[$target]['kind'] ?? null) || $writesByTarget[$target]['source_path'] !== $asset['source_path'] ) {
                throw new InvalidArgumentException('WordPress site plan asset lacks a write.');
            }
        }
        self::assertAssetPublicationDeclarations($plan['runtime_declarations'], $plan['assets'], $writesByTarget);
        if ( ! is_string($plan['theme']['stylesheet'] ?? null) || ! is_string($plan['theme']['theme_json'] ?? null) || (null !== ($plan['theme']['bootstrap'] ?? null) && ! is_string($plan['theme']['bootstrap'])) ) {
            throw new InvalidArgumentException('WordPress site plan theme is structurally invalid.');
        }
        if ( !in_array($plan['quality']['status'] ?? null, array('success', 'success_with_warnings', 'failed'), true) || !is_bool($plan['quality']['pass'] ?? null) || ('failed' !== $plan['quality']['status']) !== $plan['quality']['pass'] || ! is_array($plan['quality']['metrics'] ?? null) || ! is_array($plan['quality']['fallbacks'] ?? null) ) {
            throw new InvalidArgumentException('WordPress site plan quality is structurally invalid.');
        }
    }

    /** @param mixed $documents @param array<int,array<string,string>> $tokens @return array<int,array<string,mixed>> */
    private function documents(mixed $documents, bool $part, array $tokens, AssetReferenceCanonicalizer $references, array $routes): array
    {
        if ( ! is_array($documents) ) {
            throw new InvalidArgumentException('Compiled site documents must be an array.');
        }
        $rows = array();
        foreach ( $documents as $document ) {
            if ( ! is_array($document) || ! self::safePath($document['source_path'] ?? null) || ! is_string($document['block_markup'] ?? null) || '' === trim($document['block_markup']) ) {
                throw new InvalidArgumentException('Compiled site document lacks a safe identity or block markup.');
            }
            $markup = $references->content($document['block_markup'], $document['source_path']);
            $canonical = $this->routeLinks($markup, $document['source_path'], $routes);
            $target = $part ? 'parts/' . self::value($document, 'slug') . '.html' : self::value($document, 'source_path');
            $row = array('source_path' => $document['source_path'], 'slug' => self::value($document, 'slug'), 'title' => self::value($document, 'title'), 'post_type' => self::value((array) ($document['metadata'] ?? array()), 'post_type', 'page'), 'parent_source_path' => self::value((array) ($document['metadata'] ?? array()), 'parent_source_path'), 'entrypoint' => ! empty($document['entrypoint']), 'area' => $part ? self::value($document, 'area', 'uncategorized') : null, 'placement' => $part && is_array($document['placement'] ?? null) ? $document['placement'] : ($part ? array('kind' => 'unbound') : null), 'canonical_block_markup' => $canonical, 'metadata' => is_array($document['metadata'] ?? null) ? $document['metadata'] : array(), 'document_metadata' => $this->documentMetadata($document, $references, $routes), 'provenance' => is_array($document['provenance'] ?? null) ? $document['provenance'] : array(), 'reconciliation_identity' => self::identity($part ? 'template-part' : 'page', $document['source_path'], $target), 'content_hash' => self::contentHash($canonical));
            if ( ! $part ) $row['shell_candidates'] = $this->shellCandidates($document, $references, $routes);
            $rows[] = $row;
        }
        return $rows;
    }

    /** @param array<string,mixed> $document @return array<int,array<string,mixed>> */
    private function shellCandidates(array $document, AssetReferenceCanonicalizer $references, array $routes): array
    {
        $candidates = array();
        foreach ($document['shell_artifacts'] ?? array() as $candidate) {
            if (!is_array($candidate) || !in_array($candidate['area'] ?? null, array('header', 'footer'), true) || !is_string($candidate['block_markup'] ?? null) || '' === trim($candidate['block_markup'])) continue;
            $markup = $this->routeLinks($references->content($candidate['block_markup'], self::value($document, 'source_path')), self::value($document, 'source_path'), $routes);
            $classes = array_values(array_filter($candidate['source_classes'] ?? array(), 'is_string'));
            sort($classes, SORT_STRING);
            $innerMarkup = is_string($candidate['inner_block_markup'] ?? null) ? $this->routeLinks($references->content($candidate['inner_block_markup'], self::value($document, 'source_path')), self::value($document, 'source_path'), $routes) : $markup;
            $candidates[] = array('area' => $candidate['area'], 'markup' => $markup, 'inner_markup' => $innerMarkup, 'classes' => $classes);
        }
        return $candidates;
    }

    /** @param array<int,array<string,mixed>> $pages @param array<string,true> $reservedSlugs @return array{pages:array<int,array<string,mixed>>,parts:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>} */
    private function sharedShells(array $pages, array $reservedSlugs = array()): array
    {
        $parts = array(); $diagnostics = array();
        foreach (array('header', 'footer') as $area) {
            if (isset($reservedSlugs[$area])) {
                $diagnostics[] = array('code' => 'wordpress_site_plan_shell_retained_ambiguous', 'severity' => 'info', 'message' => "{$area} shell conflicts with an existing template part.", 'area' => $area);
                continue;
            }
            $candidates = array();
            $applicable = array_filter($pages, static fn(array $page): bool => empty($page['synthetic']));
            if (array() === $applicable) continue;
            foreach ($applicable as $index => $page) foreach ($page['shell_candidates'] ?? array() as $candidate) if ($area === ($candidate['area'] ?? null)) $candidates[$index][] = $candidate;
            if (count($candidates) !== count($applicable) || array_filter($candidates, static fn(array $rows): bool => 1 !== count($rows))) {
                $diagnostics[] = array('code' => 'wordpress_site_plan_shell_retained_incomplete', 'severity' => 'info', 'message' => "{$area} shell candidates are not present exactly once on every page.", 'area' => $area);
                continue;
            }
            $first = $candidates[array_key_first($candidates)][0];
            $identity = hash('sha256', $area . "\0" . json_encode($first['classes']) . "\0" . $first['markup']);
            foreach ($candidates as $rows) if ($identity !== hash('sha256', $area . "\0" . json_encode($rows[0]['classes']) . "\0" . $rows[0]['markup'])) {
                $diagnostics[] = array('code' => 'wordpress_site_plan_shell_retained_ambiguous', 'severity' => 'info', 'message' => "{$area} shell candidates are not semantically equivalent across every page.", 'area' => $area);
                continue 2;
            }
            foreach ($pages as $index => &$page) {
                if (!empty($page['synthetic'])) continue;
                $withoutShell = $this->withoutTopLevelShell($page['canonical_block_markup'], $area);
                if (null === $withoutShell) {
                    $diagnostics[] = array('code' => 'wordpress_site_plan_shell_retained_ambiguous', 'severity' => 'warning', 'message' => "{$area} shell candidate cannot be removed unambiguously from {$page['source_path']}.", 'area' => $area, 'source_path' => $page['source_path']);
                    unset($page); continue 2;
                }
                $page['canonical_block_markup'] = $withoutShell;
                $page['content_hash'] = self::contentHash($page['canonical_block_markup']);
            }
            unset($page);
            $singlePage = 1 === count($applicable);
            $sourcePath = $singlePage ? $pages[array_key_first($applicable)]['source_path'] : 'wordpress-site-plan/shared/' . $area;
            $placement = $singlePage ? 'entry_shell' : 'shared_shell';
            $templateSlugs = $singlePage ? array('front-page') : array('index', 'page', 'front-page');
            $partMarkup = $first['markup'];
            $parts[] = array('source_path' => $sourcePath . '#' . $area, 'slug' => $area, 'title' => ucfirst($area), 'post_type' => 'wp_template_part', 'parent_source_path' => '', 'entrypoint' => false, 'area' => $area, 'placement' => array('kind' => $placement, 'source_path' => $sourcePath, 'template_slugs' => $templateSlugs), 'canonical_block_markup' => $partMarkup, 'metadata' => array(), 'document_metadata' => array('source_context' => array('source_path' => $sourcePath . '#' . $area, 'kind' => 'template_part'), 'title' => ucfirst($area), 'title_declaration' => array('order' => 0, 'placement' => 'head'), 'meta' => array(), 'links' => array(), 'scripts' => array()), 'provenance' => array('shell_identity' => $identity), 'reconciliation_identity' => self::identity('template-part', $sourcePath . '#' . $area, 'parts/' . $area . '.html'), 'content_hash' => self::contentHash($partMarkup));
            $diagnostics[] = array('code' => $singlePage ? 'wordpress_site_plan_shell_entry_extracted' : 'wordpress_site_plan_shell_extracted', 'severity' => 'info', 'message' => $singlePage ? "Extracted the entry {$area} shell for the front-page template." : "Extracted one semantically equivalent {$area} shell for all pages.", 'area' => $area, 'page_count' => count($applicable));
        }
        foreach ($pages as &$page) unset($page['shell_candidates']); unset($page);
        return array('pages' => $pages, 'parts' => $parts, 'diagnostics' => $diagnostics);
    }

    private function withoutTopLevelShell(string $markup, string $area): ?string
    {
        if (!preg_match_all('/<!--\s*(\/?)wp:([^\s]+)(?:\s+([^>]*?))?\s*-->/s', $markup, $matches, PREG_OFFSET_CAPTURE)) return null;
        $depth = 0; $candidate = null;
        foreach ($matches[0] as $index => $comment) {
            $full = $comment[0]; $offset = $comment[1]; $closing = '' !== $matches[1][$index][0];
            if ($closing) { --$depth; if (is_array($candidate) && null === $candidate['end'] && $depth === $candidate['depth']) $candidate['end'] = $offset + strlen($full); continue; }
            $selfClosing = str_ends_with(trim($full), '/-->');
            $name = $matches[2][$index][0]; $attributes = trim($matches[3][$index][0] ?? '');
            if (0 === $depth && 'group' === $name) {
                $decoded = json_decode($attributes, true);
                if (is_array($decoded) && $area === ($decoded['tagName'] ?? null)) {
                    if (null !== $candidate) return null;
                    $candidate = array('start' => $offset, 'depth' => $depth, 'end' => $selfClosing ? $offset + strlen($full) : null);
                }
            }
            if (!$selfClosing) ++$depth;
        }
        if (!is_array($candidate) || !is_int($candidate['end'])) return null;
        return substr($markup, 0, $candidate['start']) . substr($markup, $candidate['end']);
    }

    /** @param mixed $assets @return array<int,array<string,mixed>> */
    private function assets(mixed $assets): array
    {
        if ( ! is_array($assets) ) throw new InvalidArgumentException('Compiled site assets must be an array.');
        $rows = array();
        foreach ( $assets as $asset ) {
            if ( ! is_array($asset) || ! self::safePath($asset['path'] ?? null) ) throw new InvalidArgumentException('Compiled site asset lacks a safe source identity.');
            // The compiler retains rejected source assets for diagnostics. They have no
            // payload and therefore are not materializable theme artifacts.
            if ( ! is_string($asset['content'] ?? null) && ! is_string($asset['content_base64'] ?? null) ) continue;
            $compiledTarget = $asset['target_path'] ?? $asset['path'];
            if ( ! self::safePath($compiledTarget) ) throw new InvalidArgumentException('Compiled site asset lacks a safe target identity.');
            $target = 'assets/' . str_replace('\\', '/', $compiledTarget);
            if ( ! self::safePath($target) ) throw new InvalidArgumentException('Compiled site asset lacks a safe target identity.');
            $payload = is_string($asset['content_base64'] ?? null) ? $asset['content_base64'] : (string) ($asset['content'] ?? '');
            $rows[] = array('source_path' => $asset['path'], 'target_path' => $target, 'token' => 'asset-' . substr(hash('sha256', $target), 0, 16), 'source' => self::value($asset, 'source'), 'kind' => self::value($asset, 'kind'), 'role' => self::value($asset, 'role'), 'intent' => self::value($asset, 'intent'), 'mime_type' => self::value($asset, 'mime_type'), 'media' => self::value($asset, 'media'), 'bytes' => (int) ($asset['bytes'] ?? 0), 'hash' => self::value($asset, 'hash'), 'content' => $asset['content'] ?? null, 'content_base64' => $asset['content_base64'] ?? null, 'binary' => ! empty($asset['binary']), 'reconciliation_identity' => self::identity('asset', $asset['path'], $target), 'content_hash' => self::contentHash($payload));
        }
        return $rows;
    }

    /** @param array<int,array<string,mixed>> $assets @param array<int,mixed> $declarations @return array<int,array<string,mixed>> */
    private function applyDeclaredAssetTransformations(array $assets, array $declarations): array
    {
        $bySource = array(); foreach ($assets as $index => $asset) $bySource[$asset['source_path']] = $index;
        foreach ($declarations as $declaration) {
            if (!is_array($declaration) || 'asset_publication' !== ($declaration['kind'] ?? null)) continue;
            $assetIndex = $bySource[$declaration['source_path']] ?? null;
            if (!is_int($assetIndex)) throw new InvalidArgumentException('Asset publication references an undeclared asset.');
            $asset = $assets[$assetIndex];
            if ('image/svg+xml' === ($asset['mime_type'] ?? null) && (!is_string($asset['content'] ?? null) || !self::safeSvg($asset['content']))) throw new InvalidArgumentException('Asset publication requires a sanitized SVG source.');
            if (!isset($declaration['transformation'])) continue;
            $transformation = $declaration['transformation'];
            if (!is_string($asset['content'] ?? null) || 'image/svg+xml' !== ($asset['mime_type'] ?? null)) throw new InvalidArgumentException('Asset publication transformation requires a sanitized SVG source.');
            $cssInputs = array();
            foreach ($transformation['css_source_paths'] as $path) {
                $index = $bySource[$path] ?? null;
                if (!is_int($index) || 'text/css' !== ($assets[$index]['mime_type'] ?? null) || !is_string($assets[$index]['content'] ?? null)) throw new InvalidArgumentException('Asset publication transformation references an undeclared local CSS input.');
                $fontFaces = self::fontFaces($assets[$index]['content'], $path, $transformation['font_source_paths'], $assets, $bySource);
                if (array() === $fontFaces) throw new InvalidArgumentException('Asset publication transformation CSS input has no local font-face payload.');
                $cssInputs[] = array('source_path' => $path, 'content_hash' => self::contentHash($assets[$index]['content']), 'font_faces' => $fontFaces);
            }
            $fontInputs = array();
            foreach ($transformation['font_source_paths'] as $path) {
                $index = $bySource[$path] ?? null;
                if (!is_int($index) || !str_starts_with((string) ($assets[$index]['mime_type'] ?? ''), 'font/')) throw new InvalidArgumentException('Asset publication transformation references an undeclared local font input.');
                $fontInputs[] = array('source_path' => $path, 'content_hash' => $assets[$index]['content_hash']);
            }
            $input = array('css' => $cssInputs, 'fonts' => $fontInputs);
            if (RuntimeDeclarations::hash($input) !== $transformation['input_hash']) throw new InvalidArgumentException('Asset publication transformation inputs do not match their declared hash.');
            $faces = array(); foreach ($cssInputs as $input) foreach ($input['font_faces'] as $face) $faces[] = $face;
            $content = preg_replace('~</svg\s*>~i', '<style>' . implode("\n", $faces) . '</style></svg>', $asset['content'], 1);
            if (!is_string($content) || $content === $asset['content'] || !self::safeSvg($content) || self::contentHash($content) !== $transformation['expected_content_hash']) throw new InvalidArgumentException('Asset publication transformation content hash does not match its declaration.');
            $assets[$assetIndex]['content'] = $content; $assets[$assetIndex]['content_hash'] = self::contentHash($content);
        }
        return $assets;
    }

    /** @return array<int,string> */
    private static function fontFaces(string $css, string $cssPath, array $fontPaths, array $assets, array $bySource): array
    {
        if (preg_match('~(?:</style|<!--|-->|/\*|\*/|\\|@import|[<>]|(?:https?:|//|file:|blob:|data:))~i', $css) || !preg_match_all('/@font-face\s*\{([^{}]+)\}\s*/i', $css, $matches) || '' !== trim((string) preg_replace('/@font-face\s*\{[^{}]+\}\s*/i', '', $css))) throw new InvalidArgumentException('Asset publication transformation rejects unsafe or non-font CSS inputs.');
        $faces = array();
        foreach ($matches[1] as $body) {
            $properties = array(); $hasSource = false;
            foreach (explode(';', trim($body)) as $declaration) {
                if ('' === trim($declaration)) continue;
                if (!preg_match('/^\s*(font-family|font-style|font-weight|font-stretch|font-display|src)\s*:\s*(.+?)\s*$/i', $declaration, $pair)) throw new InvalidArgumentException('Asset publication transformation CSS property is not allowed.');
                $name = strtolower($pair[1]); $value = trim($pair[2]); if (isset($properties[$name])) throw new InvalidArgumentException('Asset publication transformation CSS has duplicate properties.');
                if ('src' === $name) {
                    if (!preg_match('~^url\(\s*([a-zA-Z0-9._/-]+)\s*\)$~', $value, $url)) throw new InvalidArgumentException('Asset publication transformation CSS source must be a local font path.');
                    $source = ArtifactPath::resolveRelativePath($url[1], $cssPath); $assetIndex = $bySource[$source] ?? null;
                    if (!in_array($source, $fontPaths, true) || !is_int($assetIndex) || !str_starts_with((string) ($assets[$assetIndex]['mime_type'] ?? ''), 'font/')) throw new InvalidArgumentException('Asset publication transformation CSS source is not a declared font asset.');
                    $value = 'url(' . self::TOKEN_PREFIX . $assets[$assetIndex]['token'] . '}})'; $hasSource = true;
                } elseif (!preg_match('/^[a-z0-9 .,_\'"-]+$/i', $value)) throw new InvalidArgumentException('Asset publication transformation CSS value is not safe.');
                $properties[$name] = $value;
            }
            if (!$hasSource || !isset($properties['font-family'])) throw new InvalidArgumentException('Asset publication transformation font-face is incomplete.');
            $face = '@font-face{'; foreach ($properties as $name => $value) $face .= $name . ':' . $value . ';'; $faces[] = $face . '}';
        }
        return $faces;
    }

    private static function safeSvg(string $svg): bool
    {
        $scan = preg_replace('~\sxmlns(?::[a-z]+)?\s*=\s*["\']http://www\.w3\.org/2000/svg["\']~i', '', $svg) ?? $svg;
        if (1 === preg_match('~(?:<!DOCTYPE|<!ENTITY|<\?xml|<\s*(?:script|foreignObject)\b|\son[a-z]+\s*=|(?:https?:|//|file:|blob:|data:|javascript:)|@import)~i', $scan)) return false;
        if (preg_match_all('~(?:href|xlink:href)\s*=\s*(["\'])(.*?)\1~i', $svg, $matches)) foreach ($matches[2] as $reference) if (!str_starts_with($reference, '#') && !str_starts_with($reference, self::TOKEN_PREFIX)) return false;
        return 1 !== preg_match('~url\((?!\s*\{\{wordpress-site-plan:asset:asset-[a-f0-9]{16}\}\})~i', $svg);
    }

    /** @param array<int,array<string,mixed>> $assets @return array<int,array<string,string>> */
    private function tokens(array $assets): array { return array_map(static fn(array $asset): array => array('token' => $asset['token'], 'source_path' => $asset['source_path'], 'target_path' => $asset['target_path']), $assets); }
    /** @param array<string,mixed> $document @param array<int,array<string,string>> $tokens @return array<string,mixed> */
    private function documentMetadata(array $document, AssetReferenceCanonicalizer $references, array $routes): array { $metadata = is_array($document['document_metadata'] ?? null) ? $document['document_metadata'] : array('source_context' => array('source_path' => self::value($document, 'source_path'), 'kind' => 'document'), 'title' => self::value($document, 'title'), 'title_declaration' => array('order' => 0, 'placement' => 'head'), 'meta' => array(), 'links' => array(), 'scripts' => array()); foreach (array('links', 'scripts') as $kind) { if (!is_array($metadata[$kind] ?? null)) $metadata[$kind] = array(); foreach ($metadata[$kind] as &$row) if (is_array($row) && is_string($row['url'] ?? null)) { $reference = $references->reference($row['url'], self::value($document, 'source_path')); if (null !== $reference) { $row['asset_reference'] = $reference; unset($row['url']); } elseif ('links' === $kind) $row['url'] = $this->routeReference($row['url'], self::value($document, 'source_path'), $routes) ?? $row['url']; } unset($row); } return $metadata; }
    /** @param array<string,mixed> $compiled @param array<string,mixed> $data @return array<string,mixed> */
    private function reporting(array $pages, array $data, array $scriptDiagnostics = array()): array { $documents = array(); foreach ($pages as $page) if (is_array($page)) $documents[] = array('source_path' => $page['source_path'] ?? '', 'kind' => 'page', 'body_format' => 'blocks', 'block_document' => true, 'provenance' => $page['provenance'] ?? array()); return array('source_documents' => $documents, 'metrics' => array('source_document_count' => count($documents), 'block_document_count' => count($documents), 'native_block_count' => $data['metrics']['block_count'] ?? 0, 'fallback_count' => $data['metrics']['fallback_count'] ?? 0), 'diagnostic_codes' => array_values(array_map(static fn(array $diagnostic): string => (string) ($diagnostic['code'] ?? ''), array_merge($data['diagnostics'], $scriptDiagnostics)))); }

    /** @param mixed $documents @param array<int,array<string,mixed>> $legacyRoutes @return array<int,array<string,mixed>> */
    private function canonicalRoutes(mixed $documents, array $legacyRoutes): array { if (!is_array($documents)) throw new InvalidArgumentException('Compiled site documents must be an array.'); $legacy = array(); foreach ($legacyRoutes as $route) if (is_array($route) && is_string($route['source_path'] ?? null)) $legacy[$route['source_path']] = $route; $entryRoot = self::entryRootFromDocuments($documents); $routes = array(); $paths = array(); foreach ($documents as $order => $document) { if (!is_array($document) || !self::safePath($document['source_path'] ?? null)) throw new InvalidArgumentException('Compiled site route source is invalid.'); $metadata = is_array($document['metadata'] ?? null) ? $document['metadata'] : array(); $path = is_string($metadata['route_path'] ?? null) && '' !== $metadata['route_path'] ? self::canonicalRoutePath($metadata['route_path']) : self::pageRoutePath($document['source_path'], $entryRoot); if (isset($paths[$path])) throw new InvalidArgumentException('WordPress site plan has colliding page routes.'); $paths[$path] = true; $previous = $legacy[$document['source_path']] ?? array(); $routes[] = array('kind' => 'route', 'source_path' => $document['source_path'], 'target_path' => $path, 'target_slug' => self::value($document, 'slug', self::routeSlug($path)), 'title' => self::value($document, 'title'), 'parent_source_path' => self::value($metadata, 'parent_source_path'), 'source_relation' => !empty($document['entrypoint']) ? 'entrypoint' : ($previous['source_relation'] ?? 'document'), 'order' => $order); } return $routes; }
    /** @param array<int,array<string,mixed>> $pages @param array<int,array<string,mixed>> $routes @return array<int,array<string,mixed>> */
    private function pageHierarchy(array $pages, array $routes): array
    {
        $byRoute = array(); $sources = array(); foreach ($pages as $page) $sources[$page['source_path']] = true;
        foreach ($pages as $index => &$page) {
            $route = array_values(array_filter($routes, static fn(array $route): bool => $route['source_path'] === $page['source_path']))[0] ?? null; if (!is_array($route)) throw new InvalidArgumentException('WordPress site plan page lacks a canonical route.'); $path = $route['target_path'];
            if (isset($byRoute[$path])) throw new InvalidArgumentException('WordPress site plan has colliding page routes.');
            $page['route'] = array('path' => $path, 'parent_path' => self::parentRoutePath($path), 'slug' => self::routeSlug($path));
            if ('/' !== $path) $page['slug'] = $page['route']['slug'];
            $page['reconciliation_identity'] = self::identity('page', $page['source_path'], $path);
            $byRoute[$path] = $index;
        }
        unset($page);
        foreach (array_keys($byRoute) as $path) foreach (self::routeAncestors($path) as $ancestor) if (!isset($byRoute[$ancestor])) {
            $source = 'wordpress-site-plan/routes/' . trim($ancestor, '/') . '.html';
            if (isset($sources[$source])) throw new InvalidArgumentException('WordPress site plan synthetic route source collides with a document.');
            $markup = '<!-- wp:group {"layout":{"type":"constrained"}} --><!-- /wp:group -->' . "\n";
            $pages[] = array('source_path' => $source, 'slug' => self::routeSlug($ancestor), 'title' => ucwords(str_replace('-', ' ', self::routeSlug($ancestor))), 'post_type' => 'page', 'parent_source_path' => '', 'entrypoint' => false, 'area' => null, 'placement' => null, 'canonical_block_markup' => $markup, 'metadata' => array(), 'document_metadata' => array('source_context' => array('source_path' => $source, 'kind' => 'synthetic_route'), 'title' => self::routeSlug($ancestor), 'title_declaration' => array('order' => 0, 'placement' => 'head'), 'meta' => array(), 'links' => array(), 'scripts' => array()), 'provenance' => array(), 'reconciliation_identity' => self::identity('page', $source, $ancestor), 'content_hash' => hash('sha256', $markup), 'route' => array('path' => $ancestor, 'parent_path' => self::parentRoutePath($ancestor), 'slug' => self::routeSlug($ancestor)), 'synthetic' => true);
            $byRoute[$ancestor] = count($pages) - 1;
            $sources[$source] = true;
        }
        foreach ($pages as &$page) { $parent = $page['route']['parent_path']; $page['parent_source_path'] = '/' === $parent ? '' : $pages[$byRoute[$parent]]['source_path']; }
        unset($page);
        usort($pages, static fn(array $left, array $right): int => substr_count($left['route']['path'], '/') <=> substr_count($right['route']['path'], '/') ?: strcmp($left['route']['path'], $right['route']['path']));
        return $pages;
    }
    /** @param array<int,array<string,mixed>> $pages @return array<int,array<string,mixed>> */
    private function routesForPages(array $pages): array { $routes = array(); foreach ($pages as $page) $routes[] = array('kind' => 'route', 'source_path' => $page['source_path'], 'target_path' => $page['route']['path'], 'target_slug' => $page['slug'], 'title' => $page['title'], 'parent_source_path' => $page['parent_source_path'], 'source_relation' => !empty($page['synthetic']) ? 'synthetic_parent' : (!empty($page['entrypoint']) ? 'entrypoint' : 'document'), 'order' => count($routes)); return $routes; }

    /** @param array<int,array<string,mixed>> $pages @return array<int,array<string,string>> */
    private function templates(array $pages, array $parts): array
    {
        $bound = array_values(array_filter($parts, static fn(array $part): bool => in_array($part['placement']['kind'] ?? '', array('entry_shell', 'shared_shell'), true)));
        usort($bound, static function (array $left, array $right): int {
            $priority = array('header' => 0, 'footer' => 2);
            return (($priority[$left['area']] ?? 1) <=> ($priority[$right['area']] ?? 1)) ?: strcmp($left['slug'], $right['slug']);
        });
        $markup = static function (string $templateSlug) use ($bound): string {
            $before = ''; $after = '';
            foreach ($bound as $part) if (in_array($templateSlug, $part['placement']['template_slugs'] ?? array(), true)) {
                $reference = '<!-- wp:template-part {"slug":"' . $part['slug'] . '","area":"' . $part['area'] . '"} /-->' . "\n";
                if ('footer' === $part['area']) $after .= $reference; else $before .= $reference;
            }
            return $before . '<!-- wp:post-content /-->' . "\n" . $after;
        };
        $make = static function (string $slug, string $target, string $content): array { return array('slug' => $slug, 'target_path' => $target, 'canonical_block_markup' => $content, 'reconciliation_identity' => self::identity('template', 'wordpress-site-plan/' . $target, $target), 'content_hash' => self::contentHash($content)); };
        $templates = array($make('index', 'templates/index.html', $markup('index')));
        if ( array() !== $pages ) $templates[] = $make('page', 'templates/page.html', $markup('page'));
        foreach ( $pages as $page ) if ( ! empty($page['entrypoint']) ) { $templates[] = $make('front-page', 'templates/front-page.html', $markup('front-page')); break; }
        return $templates;
    }

    /** @param array<int,array<string,mixed>> $pages @return array<int,array<string,mixed>> */
    private function operations(array $pages): array
    {
        $operations = array();
        foreach ($pages as $page) $operations[] = array('kind' => 'create_page', 'order' => count($operations), 'source_path' => $page['source_path'], 'reconciliation_identity' => $page['reconciliation_identity'], 'slug' => $page['slug'], 'route_path' => $page['route']['path'], 'parent_source_path' => $page['parent_source_path'], 'synthetic' => !empty($page['synthetic']));
        foreach ($pages as $page) if (!empty($page['entrypoint'])) { $operations[] = array('kind' => 'site_reading', 'order' => count($operations), 'show_on_front' => 'page', 'front_page_source_path' => $page['source_path'], 'front_page_reconciliation_identity' => $page['reconciliation_identity']); break; }
        return $operations;
    }

    /** @param array<int,array<string,mixed>> $assets @param array<int,array<string,string>> $tokens @return array<int,array<string,mixed>> */
    private function assetWrites(array $assets, AssetReferenceCanonicalizer $references): array
    {
        $writes = array();
        foreach ( $assets as $asset ) {
            $content = is_string($asset['content'] ?? null) ? $references->content($asset['content'], $asset['source_path']) : null;
            $data = is_string($asset['content_base64'] ?? null) ? $asset['content_base64'] : (is_string($content) ? (! empty($asset['binary']) || 1 !== preg_match('//u', $content) ? base64_encode($content) : $content) : null);
            if ( ! is_string($data) ) throw new InvalidArgumentException(sprintf('Compiled site asset %s lacks a materializable payload.', $asset['source_path']));
            $writes[] = $this->write('theme_asset', $asset['target_path'], $data, $asset['source_path'], is_string($asset['content_base64'] ?? null) || ! empty($asset['binary']) || 1 !== preg_match('//u', $data) ? 'base64' : 'utf8');
        }
        return $writes;
    }

    /** @param array<int,array<string,mixed>> $pages @param array<int,array<string,mixed>> $parts @param array<int,array<string,mixed>> $assets @param array<int,array<string,string>> $tokens @param array<int,array<string,mixed>> $operations @return array{scripts:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>} */
    private function scriptLoading(array $pages, array $parts, array $assets, array $tokens, array $operations): array
    {
        $targets = array(); foreach ($tokens as $token) $targets[$token['token']] = $token['target_path'];
        $contents = array(); foreach ($assets as $asset) if (is_string($asset['content'] ?? null)) $contents[$asset['target_path']] = $asset['content'];
        $inlineTargets = array(); foreach ($assets as $asset) if ('inline-script' === ($asset['source'] ?? null) && is_string($asset['content'] ?? null)) $inlineTargets[self::contentHash($asset['content'])] = $asset['target_path'];
        $frontPages = array(); foreach ($operations as $operation) if ('site_reading' === ($operation['kind'] ?? null)) $frontPages[$operation['front_page_reconciliation_identity']] = true;
        $scripts = array(); $diagnostics = array(); $instances = array();
        foreach (array_merge($pages, $parts) as $document) foreach ($document['document_metadata']['scripts'] ?? array() as $script) {
            $source = $document['source_path'] . '#' . ($script['order'] ?? '');
            $unsupported = static function (string $code, string $message) use (&$diagnostics, $source): void { $diagnostics[] = array('code' => $code, 'severity' => 'warning', 'message' => $message, 'source_path' => $source); };
            if (!is_array($script)) { $unsupported('wordpress_site_plan_script_invalid', 'Document script metadata is invalid.'); continue; }
            $localTarget = null;
            if ('inline' === ($script['source_kind'] ?? null)) { $localTarget = $inlineTargets[$script['body_hash'] ?? ''] ?? null; if (null === $localTarget) { $unsupported('wordpress_site_plan_script_inline_unbound', 'Inline document script metadata has no matching canonical asset.'); continue; } }
            if (true === ($script['module'] ?? false) && true === ($script['nomodule'] ?? false)) { $unsupported('wordpress_site_plan_script_module_nomodule_conflict', 'A document script cannot combine module and nomodule semantics.'); continue; }
            if (isset($document['placement']) && !in_array($document['placement']['kind'] ?? null, array('entry_shell', 'shared_shell'), true)) { $unsupported('wordpress_site_plan_script_unbound_template_part', 'A template-part script cannot be materialized because its template placement is unbound.'); continue; }
            $suffix = ''; $url = null;
            if (null === $localTarget) {
                if (is_string($script['asset_reference'] ?? null) && preg_match('/^\{\{wordpress-site-plan:asset:([^}]+)\}\}(.*)$/', $script['asset_reference'], $match) && isset($targets[$match[1]])) { $localTarget = $targets[$match[1]]; $suffix = $match[2]; }
                elseif (is_string($script['url'] ?? null) && preg_match('~^(?:https?:)?//[^\x00-\x20]+$~i', $script['url'])) { $url = $script['url']; $unsupported('wordpress_site_plan_script_external_unproven', 'An external script URL is emitted but cannot prove its runtime references without a declared local artifact.'); }
                else { $unsupported('wordpress_site_plan_script_url_unsupported', 'A document script must reference a declared local write or an absolute HTTP(S) URL.'); continue; }
            }
            if (null !== $localTarget && $this->hasDynamicScriptReferences($contents[$localTarget] ?? '')) { $unsupported('wordpress_site_plan_script_dynamic_references', 'A local script contains dynamic imports, script injection, or runtime URL construction that cannot be proven from the canonical write.'); continue; }
            $attributes = array('placement' => $script['placement'], 'local_target' => $localTarget, 'suffix' => $suffix, 'url' => $url, 'async' => $script['async'], 'defer' => $script['defer'], 'module' => $script['module'], 'nomodule' => $script['nomodule'], 'type' => $script['type'] ?? ($script['module'] ? 'module' : null), 'integrity' => $script['integrity'] ?? null, 'crossorigin' => $script['crossorigin'] ?? null, 'referrerpolicy' => $script['referrerpolicy'] ?? null, 'fetchpriority' => $script['fetchpriority'] ?? null);
            $scope = isset($document['placement']) ? array('kind' => 'global', 'order' => $script['order']) : array('kind' => 'page', 'source_path' => $document['source_path'], 'route_path' => trim($document['route']['path'], '/'), 'front_page' => isset($frontPages[$document['reconciliation_identity']]), 'reconciliation_identity' => $document['reconciliation_identity'], 'order' => $script['order']);
            $scopeKey = ($scope['kind'] ?? '') . ':' . ($scope['source_path'] ?? 'global');
            $signature = hash('sha256', serialize($attributes)); $instance = $instances[$scopeKey][$signature] ?? 0; $instances[$scopeKey][$signature] = $instance + 1;
            $identity = $signature . ':' . $instance;
            if (!isset($scripts[$identity])) $scripts[$identity] = array_merge(array('identity' => $identity, 'scopes' => array()), $attributes);
            $scripts[$identity]['scopes'][] = $scope;
        }
        return array('scripts' => array_values($scripts), 'diagnostics' => $diagnostics);
    }

    private function hasDynamicScriptReferences(string $content): bool { return preg_match('/\bimport\s*\(|\b(?:document\s*\.\s*createElement\s*\(\s*["\']script|appendChild\s*\(|insertBefore\s*\(|\.\s*src\s*=|new\s+URL\s*\()/i', $content) === 1; }
    private static function pageRoutePath(string $sourcePath, string $entryRoot = ''): string { if (str_contains($sourcePath, '%')) throw new InvalidArgumentException('WordPress site plan page routes reject encoded source paths.'); $relative = self::stripEntryRoot($sourcePath, $entryRoot); $segments = explode('/', preg_replace('/\.[A-Za-z0-9]+$/', '', $relative) ?? $relative); $segments = array_map(static fn(string $segment): string => trim(strtolower((string) preg_replace('/[^a-z0-9_-]/', '', str_replace('_', '-', $segment))), '-'), $segments); return '/' . implode('/', array_values(array_filter($segments, static fn(string $segment): bool => '' !== $segment && 'index' !== $segment))); }
    // The entrypoint document's directory is the site's web root: a `website/`
    // wrapper around `website/index.html` must not become a `/website` route with
    // every other page nested beneath it. Strip that shared root so `index.html`
    // maps to `/` and its siblings map to top-level routes (`/contact`, `/music`).
    private static function stripEntryRoot(string $sourcePath, string $entryRoot): string { if ('' === $entryRoot) return $sourcePath; $prefix = rtrim($entryRoot, '/') . '/'; return str_starts_with($sourcePath, $prefix) ? substr($sourcePath, strlen($prefix)) : $sourcePath; }
    // Resolve the site root directory from the entrypoint document/page so route
    // derivation and validation agree on the same web root without shared state.
    private static function entryRootFromDocuments(array $documents): string { foreach ($documents as $document) { if (is_array($document) && !empty($document['entrypoint']) && is_string($document['source_path'] ?? null)) { $dir = str_replace('\\', '/', dirname($document['source_path'])); return in_array($dir, array('.', '/', ''), true) ? '' : $dir; } } return ''; }
    private static function canonicalRoutePath(string $path): string { if (!preg_match('~^/(?:[a-z0-9-]+(?:/[a-z0-9-]+)*)?$~', $path)) throw new InvalidArgumentException('WordPress site plan has an unsafe explicit page route.'); return $path; }
    private static function parentRoutePath(string $path): string { $parent = dirname($path); return '.' === $parent || '/' === $parent ? '/' : '/' . trim($parent, '/'); }
    /** @return array<int,string> */
    private static function routeAncestors(string $path): array { $ancestors = array(); for ($parent = self::parentRoutePath($path); '/' !== $parent; $parent = self::parentRoutePath($parent)) $ancestors[] = $parent; return array_reverse($ancestors); }
    private static function routeSlug(string $path): string { return trim((string) basename($path), '/'); }

    /** @param array<int,array<string,mixed>> $assets @param array<int,array<string,string>> $templates @param array<int,array<string,mixed>> $parts @return array<int,array<string,mixed>> */
    private function scaffoldWrites(array $assets, array $templates, array $parts, array $scripts): array
    {
        $writes = array($this->write('theme_scaffold', 'style.css', "/*\nTheme Name: Blocks Engine Site\nText Domain: blocks-engine-site\n*/\n"), $this->write('theme_scaffold', 'theme.json', "{\"version\":3,\"settings\":{},\"styles\":{}}\n"));
        if ( self::needsBootstrap($assets, $scripts) ) $writes[] = $this->write('theme_bootstrap', 'functions.php', self::bootstrap($assets, $scripts));
        foreach ( $templates as $template ) $writes[] = $this->write('theme_template', $template['target_path'], $template['canonical_block_markup']);
        foreach ( $parts as $part ) $writes[] = $this->write('theme_template_part', 'parts/' . $part['slug'] . '.html', $part['canonical_block_markup']);
        return $writes;
    }

    /** @param array<int,array<string,mixed>> $assets */
    private static function needsBootstrap(array $assets, array $scripts = array()): bool { foreach ($assets as $asset) if (in_array($asset['kind'], array('css', 'js'), true)) return true; return array() !== $scripts; }
    /** @param array<int,array<string,mixed>> $assets */
    private static function bootstrap(array $assets, array $scripts = array()): string
    {
        $lines = array("<?php", "add_action( 'wp_enqueue_scripts', static function (): void {");
        foreach ($assets as $asset) {
            $handle = 'blocks-engine-' . substr(hash('sha256', $asset['target_path']), 0, 12);
            if ('css' === $asset['kind']) $lines[] = "    wp_enqueue_style( '{$handle}', get_theme_file_uri( '{$asset['target_path']}' ), array(), null );";
        }
        $attributes = array();
        foreach ($scripts as $script) {
            $handle = 'blocks-engine-script-' . substr(hash('sha256', $script['identity']), 0, 12);
            $source = null !== $script['local_target'] ? "get_theme_file_uri( " . var_export($script['local_target'], true) . " ) . " . var_export($script['suffix'], true) : var_export($script['url'], true);
            $args = array('in_footer' => 'body' === $script['placement']);
            if ($script['async'] && !$script['module']) $args['strategy'] = 'async';
            if ($script['defer'] && !$script['async'] && !$script['module']) $args['strategy'] = 'defer';
            $lines[] = "    wp_register_script( " . var_export($handle, true) . ", {$source}, array(), null, " . var_export($args, true) . " );";
            $attributes[$handle] = array_filter(array('type' => $script['type'], 'nomodule' => $script['nomodule'], 'integrity' => $script['integrity'], 'crossorigin' => $script['crossorigin'], 'referrerpolicy' => $script['referrerpolicy'], 'fetchpriority' => $script['fetchpriority'], 'async' => $script['async'] && $script['module'], 'defer' => $script['defer'] && ($script['async'] || $script['module'])), static fn(mixed $value): bool => false !== $value && null !== $value);
        }
        $lines[] = "}, 1 );";
        foreach ($scripts as $script) {
            $handle = 'blocks-engine-script-' . substr(hash('sha256', $script['identity']), 0, 12);
            foreach ($script['scopes'] as $scope) {
                $condition = 'global' === $scope['kind'] ? 'true' : ($scope['front_page'] ? 'is_front_page()' : 'is_page() && ' . var_export($scope['route_path'], true) . " === trim( get_page_uri( get_queried_object_id() ), '/' )");
                $lines[] = "add_action( 'wp_enqueue_scripts', static function (): void { if ( {$condition} ) wp_enqueue_script( " . var_export($handle, true) . " ); }, " . (10 + $scope['order']) . " );";
            }
        }
        if (array() !== $attributes) {
            $lines[] = "add_filter( 'script_loader_tag', static function ( string \$tag, string \$handle ): string {";
            $lines[] = '    $attributes = ' . var_export($attributes, true) . ';';
            $lines[] = "    if ( ! isset( \$attributes[\$handle] ) ) return \$tag;";
            $lines[] = "    \$rendered = ''; foreach ( \$attributes[\$handle] as \$name => \$value ) \$rendered .= true === \$value ? ' ' . \$name : ' ' . \$name . '=\"' . esc_attr( (string) \$value ) . '\"';";
            $lines[] = "    return preg_replace( '/<script\\b/', '<script' . \$rendered, \$tag, 1 ) ?? \$tag;";
            $lines[] = "}, 10, 2 );";
        }
        return implode("\n", $lines) . "\n";
    }
    /** @return array<string,mixed> */
    private function write(string $kind, string $target, string $content, ?string $sourcePath = null, string $encoding = 'utf8'): array { $sourcePath ??= 'wordpress-site-plan/' . $target; return array('kind' => $kind, 'source_path' => $sourcePath, 'target_path' => $target, 'reconciliation_identity' => self::identity('write', $sourcePath, $target), 'payload_hash' => self::contentHash($content), 'payload' => array('encoding' => $encoding, 'data' => $content)); }
    private static function relativePath(string $origin, string $target): string
    {
        $from = '' === $origin ? array() : explode('/', dirname($origin));
        if (array('.') === $from) $from = array();
        $to = explode('/', $target);
        while (array() !== $from && array() !== $to && $from[0] === $to[0]) { array_shift($from); array_shift($to); }
        return str_repeat('../', count($from)) . implode('/', $to);
    }
    /** @param array<int,array<string,mixed>> $routes */
    private function routeLinks(string $content, string $origin, array $routes): string
    {
        $replace = fn(array $match): string => $match[1] . ($this->routeReference($match[2], $origin, $routes) ?? $match[2]) . $match[3];
        $content = preg_replace_callback('/(\b(?:href|action)\s*=\s*["\'])([^"\']+)(["\'])/i', $replace, $content) ?? $content;
        return preg_replace_callback('/(["\'](?:url|action)["\']\s*:\s*["\'])([^"\']+)(["\'])/i', $replace, $content) ?? $content;
    }
    /** @param array<int,array<string,mixed>> $routes */
    private function routeReference(string $value, string $origin, array $routes): ?string
    {
        if ('' === $value || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#|\?)~i', $value)) return null;
        $suffix = ''; if (preg_match('/^([^?#]*)(.*)$/', $value, $match)) { $value = $match[1]; $suffix = $match[2]; }
        if (str_contains($value, '%') || str_contains($value, '\\')) return null;
        $path = str_starts_with($value, '/') ? ltrim($value, '/') : self::resolveRouteSource($origin, $value);
        if (null === $path) return null;
        foreach ($routes as $route) if (is_array($route) && $path === ($route['source_path'] ?? null)) return $route['target_path'] . $suffix;
        return null;
    }
    private static function resolveRouteSource(string $origin, string $value): ?string { $segments = array_filter(explode('/', dirname($origin)), static fn(string $segment): bool => '' !== $segment && '.' !== $segment); foreach (explode('/', $value) as $segment) { if ('' === $segment || '.' === $segment) continue; if ('..' === $segment) { if (array() === $segments) return null; array_pop($segments); continue; } $segments[] = $segment; } return implode('/', $segments); }
    /** @param array<string,mixed> $plan @param array<string,array<string,mixed>> $writes */
    private static function assertScaffold(array $plan, array $writes): void
    {
        $style = $writes['style.css'] ?? null;
        $themeJson = $writes['theme.json'] ?? null;
        if (!is_array($style) || 'theme_scaffold' !== ($style['kind'] ?? null) || 'wordpress-site-plan/style.css' !== ($style['source_path'] ?? null) || !preg_match('/^\/\*\nTheme Name:\s+[^\n]+\nText Domain:\s+[a-z0-9-]+\n\*\/\n$/', (string) ($style['payload']['data'] ?? ''))) throw new InvalidArgumentException('WordPress site plan style.css scaffold is invalid.');
        if (!is_array($themeJson) || 'theme_scaffold' !== ($themeJson['kind'] ?? null) || 'wordpress-site-plan/theme.json' !== ($themeJson['source_path'] ?? null)) throw new InvalidArgumentException('WordPress site plan theme.json scaffold is invalid.');
        try { $theme = json_decode((string) $themeJson['payload']['data'], true, 512, JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new InvalidArgumentException('WordPress site plan theme.json is not valid JSON.'); }
        if (!is_array($theme) || 3 !== ($theme['version'] ?? null) || !is_array($theme['settings'] ?? null) || !is_array($theme['styles'] ?? null)) throw new InvalidArgumentException('WordPress site plan theme.json shape is unsupported.');
        $bootstrap = $writes['functions.php'] ?? null;
        $scriptLoading = (new self())->scriptLoading($plan['pages'], $plan['template_parts'], $plan['assets'], $plan['reference_tokens'], $plan['operations']);
        if (self::needsBootstrap($plan['assets'], $scriptLoading['scripts'])) {
            if (!is_array($bootstrap) || 'theme_bootstrap' !== ($bootstrap['kind'] ?? null) || 'wordpress-site-plan/functions.php' !== ($bootstrap['source_path'] ?? null) || self::bootstrap($plan['assets'], $scriptLoading['scripts']) !== ($bootstrap['payload']['data'] ?? null)) throw new InvalidArgumentException('WordPress site plan functions.php bootstrap is invalid.');
        } elseif (null !== ($plan['theme']['bootstrap'] ?? null) || isset($bootstrap)) throw new InvalidArgumentException('WordPress site plan declares an unnecessary bootstrap.');
    }
    /** @param array<int,mixed> $declarations @param array<int,array<string,mixed>> $assets @param array<string,array<string,mixed>> $writes */
    private static function assertAssetPublicationDeclarations(array $declarations, array $assets, array $writes): void
    {
        $assetsBySource = array(); foreach ($assets as $asset) $assetsBySource[$asset['source_path']] = $asset;
        foreach ($declarations as $declaration) {
            if (!is_array($declaration) || 'asset_publication' !== ($declaration['kind'] ?? null)) continue;
            $asset = $assetsBySource[$declaration['source_path']] ?? null;
            $provenance = is_array($asset) ? array('source_path' => $asset['source_path'], 'source' => $asset['source'], 'hash' => $asset['hash'], 'mime_type' => $asset['mime_type'], 'role' => $asset['role'], 'bytes' => $asset['bytes']) : null;
            if (!is_array($asset) || !self::hash($asset['hash'] ?? null) || ($asset['role'] ?? null) !== $declaration['source_role'] || ($asset['mime_type'] ?? null) !== $declaration['mime_type'] || ($asset['hash'] ?? null) !== $declaration['source_hash'] || ($asset['content_hash'] ?? null) !== $declaration['expected_content_hash'] || !is_array($declaration['provenance'] ?? null) || RuntimeDeclarations::canonicalJson($declaration['provenance']) !== RuntimeDeclarations::canonicalJson($provenance) || ($declaration['sanitization']['input_hash'] ?? null) !== $asset['hash']) throw new InvalidArgumentException('Asset publication declaration does not match its declared source asset hashes or provenance.');
            if ('image/svg+xml' === $asset['mime_type'] && (!is_string($asset['content'] ?? null) || !self::safeSvg($asset['content']))) throw new InvalidArgumentException('Asset publication SVG payload is unsafe.');
            if (!isset($declaration['transformation']) && $asset['hash'] !== $asset['content_hash']) throw new InvalidArgumentException('Asset publication plain source hash must match its canonical payload.');
            $write = $writes[$asset['target_path']] ?? null;
            $writePayload = is_array($write) ? ($write['canonical_payload'] ?? ($write['payload']['data'] ?? null)) : null;
            if (!is_array($write) || 'theme_asset' !== ($write['kind'] ?? null) || ($write['source_path'] ?? null) !== $declaration['source_path'] || !is_string($writePayload) || self::contentHash($writePayload) !== $asset['content_hash'] || ($write['canonical_payload_hash'] ?? $write['payload_hash'] ?? null) !== $asset['content_hash']) throw new InvalidArgumentException('Asset publication declaration does not resolve to its declared asset write.');
            foreach ($declaration['reference_targets'] as $target) {
                $write = $writes[$target['target_path']] ?? null;
                $token = self::TOKEN_PREFIX . $target['token'] . '}}';
                if (!is_array($write)) throw new InvalidArgumentException('Asset publication declaration references an unbound destination token occurrence.');
                $canonical = $write['canonical_payload'] ?? ($write['payload']['data'] ?? null);
                if ($write['reconciliation_identity'] !== $target['write_reconciliation_identity'] || 'utf8' !== ($write['payload']['encoding'] ?? null) || !is_string($canonical) || $target['count'] !== substr_count($canonical, $token)) throw new InvalidArgumentException('Asset publication declaration references an unbound destination token occurrence.');
                if ('css_url' === $target['context'] && $target['count'] !== preg_match_all('~url\(\s*["\']?' . preg_quote($token, '~') . '["\']?\s*\)~i', $canonical)) throw new InvalidArgumentException('Asset publication declaration reference context does not match its CSS token occurrence.');
            }
            if (isset($declaration['transformation'])) {
                if ($declaration['transformation']['expected_content_hash'] !== $declaration['expected_content_hash']) throw new InvalidArgumentException('Asset publication transformation final hash is contradictory.');
                self::assertPublicationTransformationInputs($declaration['transformation'], $assetsBySource);
            }
        }
    }
    /** @param array<string,mixed> $transformation @param array<string,array<string,mixed>> $assetsBySource */
    private static function assertPublicationTransformationInputs(array $transformation, array $assetsBySource): void
    {
        $css = array(); foreach ($transformation['css_source_paths'] as $path) { $asset = $assetsBySource[$path] ?? null; if (!is_array($asset) || 'text/css' !== ($asset['mime_type'] ?? null) || !is_string($asset['content'] ?? null)) throw new InvalidArgumentException('Asset publication transformation has an unbound CSS input.'); $css[] = array('source_path' => $path, 'content_hash' => self::contentHash($asset['content']), 'font_faces' => self::fontFaces($asset['content'], $path, $transformation['font_source_paths'], array_values($assetsBySource), array_flip(array_keys($assetsBySource)))); }
        $fonts = array(); foreach ($transformation['font_source_paths'] as $path) { $asset = $assetsBySource[$path] ?? null; if (!is_array($asset) || !str_starts_with((string) ($asset['mime_type'] ?? ''), 'font/')) throw new InvalidArgumentException('Asset publication transformation has an unbound font input.'); $fonts[] = array('source_path' => $path, 'content_hash' => $asset['content_hash']); }
        if (RuntimeDeclarations::hash(array('css' => $css, 'fonts' => $fonts)) !== ($transformation['input_hash'] ?? null)) throw new InvalidArgumentException('Asset publication transformation inputs have stale hashes.');
    }
    /** @param array<int,array<string,mixed>> $operations @param array<int,array<string,mixed>> $pages */
    private static function assertOperations(array $operations, array $pages): void
    {
        $pagesBySource = array(); foreach ($pages as $page) $pagesBySource[$page['source_path']] = $page;
        $created = array(); $reading = 0;
        foreach ($operations as $index => $operation) {
            if (!is_array($operation) || $index !== ($operation['order'] ?? null)) throw new InvalidArgumentException('WordPress site plan operation is invalid.');
            if ('create_page' === ($operation['kind'] ?? null)) { $page = $pagesBySource[$operation['source_path'] ?? ''] ?? null; if (!is_array($page) || $page['reconciliation_identity'] !== ($operation['reconciliation_identity'] ?? null) || $page['route']['path'] !== ($operation['route_path'] ?? null) || $page['slug'] !== ($operation['slug'] ?? null) || $page['parent_source_path'] !== ($operation['parent_source_path'] ?? null) || !is_bool($operation['synthetic'] ?? null) || ('' !== $page['parent_source_path'] && !isset($created[$page['parent_source_path']]))) throw new InvalidArgumentException('WordPress site plan create_page operation is invalid.'); $created[$page['source_path']] = true; continue; }
            if ('site_reading' !== ($operation['kind'] ?? null) || ++$reading > 1 || 'page' !== ($operation['show_on_front'] ?? null) || !is_string($operation['front_page_source_path'] ?? null) || !is_string($operation['front_page_reconciliation_identity'] ?? null)) throw new InvalidArgumentException('WordPress site plan operation is invalid.');
            $page = $pagesBySource[$operation['front_page_source_path']] ?? null; if (!is_array($page) || empty($page['entrypoint']) || $page['reconciliation_identity'] !== $operation['front_page_reconciliation_identity'] || !isset($created[$page['source_path']])) throw new InvalidArgumentException('WordPress site plan operation references an invalid front page.');
        }
        if (count($created) !== count($pages) || $reading !== (array() === array_filter($pages, static fn(array $page): bool => !empty($page['entrypoint'])) ? 0 : 1)) throw new InvalidArgumentException('WordPress site plan operations are incomplete.');
    }
    private static function assertNoLocalBrowserReferences(string $content): void
    {
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $patterns = array('/\b(?:src|href|srcset|poster|action)\s*=\s*["\']([^"\']+)["\']/i', '/["\'](?:url|src|href|srcset|poster|action)["\']\s*:\s*["\']([^"\']+)["\']/i', '/(?:url\(\s*["\']?|@import\s+(?:url\(\s*)?["\']?)([^\s\)"\';]+)/i');
        foreach ($patterns as $pattern) if (preg_match_all($pattern, $content, $matches)) foreach ($matches[1] as $value) foreach (preg_match('~^[a-z][a-z0-9+.-]*:~i', trim((string) $value)) ? array($value) : explode(',', (string) $value) as $candidate) {
            $url = trim(preg_split('/\s+/', trim($candidate))[0] ?? '');
            if ('' !== $url && !str_starts_with($url, self::TOKEN_PREFIX) && !preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|/|#|\?)~i', $url)) throw new InvalidArgumentException(sprintf('WordPress site plan contains unresolved local browser reference %s.', $url));
        }
    }
    /** @param array<string,bool> $tokens @param array<string,array<string,mixed>> $writes */
    private static function assertResolution(array $plan, array $tokens, array $writes): void
    {
        if (!isset($plan['resolution'])) return;
        $resolution = $plan['resolution'];
        if (!is_array($resolution) || array_keys($resolution) !== array('schema', 'theme_uri', 'runtime_capabilities', 'asset_publication_references', 'unsupported_optional_capabilities') || WordPressSitePlanResolver::RESOLUTION_SCHEMA !== ($resolution['schema'] ?? null) || !is_string($resolution['theme_uri'] ?? null) || !is_array($resolution['runtime_capabilities'] ?? null) || !is_array($resolution['asset_publication_references'] ?? null) || !is_array($resolution['unsupported_optional_capabilities'] ?? null) || WordPressSitePlanResolver::normalizeThemeUri($resolution['theme_uri']) !== $resolution['theme_uri']) throw new InvalidArgumentException('WordPress site plan resolution is malformed or fabricated.');
        $references = WordPressSitePlanResolver::references($plan['reference_tokens'], $resolution['theme_uri']);
        $expectedPublicationReferences = WordPressSitePlanResolver::publicationReferences($plan['runtime_declarations'], $references);
        try { $capabilities = WordPressSitePlanResolver::normalizeRuntimeCapabilities($resolution['runtime_capabilities']); $unsupported = WordPressSitePlanResolver::unsupportedOptionalCapabilities($plan['runtime_declarations'], $capabilities); } catch (InvalidArgumentException) { throw new InvalidArgumentException('WordPress site plan publication resolution is malformed or stale.'); }
        if ($resolution['runtime_capabilities'] !== $capabilities || $resolution['asset_publication_references'] !== $expectedPublicationReferences || $resolution['unsupported_optional_capabilities'] !== $unsupported) throw new InvalidArgumentException('WordPress site plan publication resolution is malformed or stale.');
        foreach (array('pages', 'template_parts', 'templates') as $kind) foreach ($plan[$kind] as $document) {
            if (!is_array($document) || !is_string($document['canonical_block_markup'] ?? null) || !is_string($document['resolved_block_markup'] ?? null) || WordPressSitePlanResolver::resolvePayload($document['canonical_block_markup'], $references) !== $document['resolved_block_markup']) throw new InvalidArgumentException("WordPress site plan resolved {$kind} payload is not canonical.");
        }
        foreach ($writes as $write) {
            if ('utf8' !== ($write['payload']['encoding'] ?? null)) { if (isset($write['canonical_payload'], $write['canonical_payload_hash'])) throw new InvalidArgumentException('WordPress site plan binary write cannot carry a resolution projection.'); continue; }
            if (!is_string($write['canonical_payload'] ?? null) || !self::hash($write['canonical_payload_hash'] ?? null) || $write['canonical_payload_hash'] !== self::contentHash($write['canonical_payload']) || WordPressSitePlanResolver::resolvePayload($write['canonical_payload'], $references) !== $write['payload']['data']) throw new InvalidArgumentException('WordPress site plan resolved write payload is not canonical.');
        }
        self::assertResolvedMetadata($plan, $references);
    }
    /** @param array<string,string> $references */
    private static function assertResolvedMetadata(array $plan, array $references): void
    {
        foreach (array('pages', 'template_parts') as $kind) foreach ($plan[$kind] as $document) foreach (array('links', 'scripts') as $declarationKind) foreach ($document['document_metadata'][$declarationKind] ?? array() as $declaration) {
            if (!is_array($declaration)) throw new InvalidArgumentException('WordPress site plan resolved metadata declaration is invalid.');
            if (is_string($declaration['asset_reference'] ?? null)) {
                if (!is_string($declaration['resolved_url'] ?? null) || WordPressSitePlanResolver::resolvePayload($declaration['asset_reference'], $references) !== $declaration['resolved_url']) throw new InvalidArgumentException('WordPress site plan resolved metadata URL is missing, stale, or tampered.');
                continue;
            }
            if (array_key_exists('resolved_url', $declaration)) throw new InvalidArgumentException('WordPress site plan external metadata URL must not carry a resolved alias.');
        }
    }
    private static function assertRoute(array $page, string $entryRoot = ''): void { $route = $page['route'] ?? null; $expected = is_string($page['metadata']['route_path'] ?? null) && '' !== $page['metadata']['route_path'] ? self::canonicalRoutePath($page['metadata']['route_path']) : self::pageRoutePath($page['source_path'], $entryRoot); if (!is_array($route) || !is_string($route['path'] ?? null) || !preg_match('~^/(?:[a-z0-9-]+(?:/[a-z0-9-]+)*)?$~', $route['path']) || !is_string($route['parent_path'] ?? null) || !is_string($route['slug'] ?? null) || self::parentRoutePath($route['path']) !== $route['parent_path'] || self::routeSlug($route['path']) !== $route['slug'] || (!isset($page['synthetic']) && $route['path'] !== $expected) || (isset($page['synthetic']) && true !== $page['synthetic'])) throw new InvalidArgumentException('WordPress site plan page route is invalid.'); }
    /** @param array<string,string> $tokens */
    private static function assertDocument(mixed $document, string $kind, bool $part, array $tokens): void { if(!is_array($document)||!self::safePath($document['source_path']??null)||!is_string($document['slug']??null)||!is_string($document['title']??null)||!is_string($document['post_type']??null)||!is_string($document['parent_source_path']??null)||!is_bool($document['entrypoint']??null)||!is_string($document['canonical_block_markup']??null)||''===trim($document['canonical_block_markup'])||!is_array($document['metadata']??null)||!is_array($document['document_metadata']??null)||!is_array($document['provenance']??null)||!self::hash($document['reconciliation_identity']??null)||!self::hash($document['content_hash']??null)||($part&&(!is_string($document['area']??null)||''===$document['area']||!is_array($document['placement']??null)))||(!$part&&(null!==($document['area']??null)||null!==($document['placement']??null))))throw new InvalidArgumentException("WordPress site plan {$kind} is structurally invalid.");if($part&&$document['reconciliation_identity']!==self::identity('template-part',$document['source_path'],'parts/'.$document['slug'].'.html'))throw new InvalidArgumentException('WordPress site plan template part identity is invalid.');if($part&&in_array($document['placement']['kind']??null,array('entry_shell','shared_shell'),true)&&(!is_string($document['placement']['source_path']??null)||!is_array($document['placement']['template_slugs']??null)||array()=== $document['placement']['template_slugs']))throw new InvalidArgumentException('WordPress site plan template part placement is invalid.');self::assertDocumentMetadata($document['document_metadata'],$tokens);self::assertTokens($document['canonical_block_markup'],$tokens);self::assertNoLocalBrowserReferences($document['canonical_block_markup']); }
    /** @param array<string,mixed> $metadata @param array<string,bool> $tokens */
    private static function assertDocumentMetadata(array $metadata, array $tokens): void { if(!is_array($metadata['source_context']??null)||!self::safePath($metadata['source_context']['source_path']??null)||!is_string($metadata['source_context']['kind']??null)||!is_string($metadata['title']??null)||!is_array($metadata['title_declaration']??null)||0!==($metadata['title_declaration']['order']??null)||'head'!==($metadata['title_declaration']['placement']??null)||!is_array($metadata['meta']??null)||!is_array($metadata['links']??null)||!is_array($metadata['scripts']??null))throw new InvalidArgumentException('WordPress site plan document metadata is structurally invalid.');foreach($metadata['meta'] as $index=>$row)if(!is_array($row)||$index!==($row['order']??null)||!in_array($row['placement']??null,array('head','body'),true)||array_diff(array_keys($row),array('order','placement','charset','name','property','http_equiv','content')))throw new InvalidArgumentException('WordPress site plan meta declaration is invalid.');foreach($metadata['links'] as $index=>$row){if(!is_array($row)||$index!==($row['order']??null)||!in_array($row['placement']??null,array('head','body'),true)||(!is_string($row['asset_reference']??null)&&!self::explicitUrl($row['url']??null))||array_diff(array_keys($row),array('order','placement','rel','type','media','integrity','crossorigin','referrerpolicy','as','fetchpriority','sizes','asset_reference','url','resolved_url')))throw new InvalidArgumentException('WordPress site plan link declaration is invalid.');if(is_string($row['asset_reference']??null))self::assertTokens($row['asset_reference'],$tokens);}foreach($metadata['scripts'] as $index=>$row){if(!is_array($row)||$index!==($row['order']??null)||!in_array($row['placement']??null,array('head','body'),true)||!is_bool($row['defer']??null)||!is_bool($row['async']??null)||!is_bool($row['module']??null)||!is_bool($row['nomodule']??null)||!in_array($row['effective_loading']??null,array('blocking','defer','async'),true)||($row['async']&&'async'!==$row['effective_loading'])||(!$row['async']&&($row['defer']||$row['module'])&&'defer'!==$row['effective_loading'])||(!$row['async']&&!$row['defer']&&!$row['module']&&'blocking'!==$row['effective_loading'])||(!is_string($row['asset_reference']??null)&&!self::explicitUrl($row['url']??null)&&'inline'!==($row['source_kind']??null))||array_diff(array_keys($row),array('order','placement','async','defer','module','nomodule','effective_loading','type','integrity','crossorigin','referrerpolicy','fetchpriority','asset_reference','url','resolved_url','source_kind','body_hash')))throw new InvalidArgumentException('WordPress site plan script declaration is invalid.');if(is_string($row['asset_reference']??null))self::assertTokens($row['asset_reference'],$tokens);}}
    /** @param array<string,mixed> $reporting @param array<string,bool> $pagePaths @param array<string,bool> $tokens */
    private static function assertReporting(array $reporting, array $pagePaths, array $tokens, array $diagnostics): void { if(!is_array($reporting['source_documents']??null)||!is_array($reporting['metrics']??null)||!is_array($reporting['diagnostic_codes']??null))throw new InvalidArgumentException('WordPress site plan reporting summary is invalid.');$sources=array();foreach($reporting['source_documents'] as $document){if(!is_array($document)||!self::safePath($document['source_path']??null)||!is_string($document['kind']??null)||!is_string($document['body_format']??null)||!is_bool($document['block_document']??null)||!is_array($document['provenance']??null))throw new InvalidArgumentException('WordPress site plan source document summary is invalid.');self::unique($sources,$document['source_path'],'source document');}if(count($sources)!==count($pagePaths)||array_keys($sources)!==array_keys($pagePaths))throw new InvalidArgumentException('WordPress site plan source document summaries do not match pages.');foreach(array('source_document_count','block_document_count','native_block_count','fallback_count') as $key)if(!is_int($reporting['metrics'][$key]??null))throw new InvalidArgumentException('WordPress site plan reporting metric is invalid.');$linked=array_fill_keys($reporting['diagnostic_codes'],true);foreach($reporting['diagnostic_codes'] as $code)if(!is_string($code)||''===$code)throw new InvalidArgumentException('WordPress site plan diagnostic linkage is invalid.');foreach($diagnostics as $diagnostic)if(is_array($diagnostic)&&is_string($diagnostic['code']??null)&&!isset($linked[$diagnostic['code']]))throw new InvalidArgumentException('WordPress site plan diagnostics are not linked to reporting.');}
    /** @param array<string,string> $tokens */
    private static function assertWrite(mixed $write, array $tokens): void { if (!is_array($write) || !is_string($write['kind'] ?? null) || !self::safePath($write['source_path'] ?? null) || !self::safePath($write['target_path'] ?? null) || !self::hash($write['reconciliation_identity'] ?? null) || !self::hash($write['payload_hash'] ?? null) || !is_array($write['payload'] ?? null) || !in_array($write['payload']['encoding'] ?? null, array('utf8','base64'), true) || !is_string($write['payload']['data'] ?? null) || $write['reconciliation_identity'] !== self::identity('write', $write['source_path'], $write['target_path']) || $write['payload_hash'] !== self::contentHash($write['payload']['data'])) throw new InvalidArgumentException('WordPress site plan write has a stale payload hash or invalid structure.'); if ('base64' === $write['payload']['encoding'] && false === base64_decode($write['payload']['data'], true)) throw new InvalidArgumentException('WordPress site plan write has invalid base64 payload.'); if ('utf8' === $write['payload']['encoding']) { self::assertTokens($write['payload']['data'], $tokens); self::assertNoLocalBrowserReferences($write['payload']['data']); } }
    /** @param array<string,string> $tokens */
    private static function assertTokens(string $content, array $tokens): void { if (preg_match_all('/\{\{wordpress-site-plan:asset:([^}]+)\}\}/', $content, $matches)) foreach ($matches[1] as $token) if (!isset($tokens[$token])) throw new InvalidArgumentException('WordPress site plan contains an undeclared reference token.'); }
    /** @param array<string,bool> $values */
    private static function unique(array &$values, string $value, string $kind): void { $key = strtolower($value); if (isset($values[$key])) throw new InvalidArgumentException("WordPress site plan has colliding {$kind}s."); $values[$key] = true; }
    private static function identity(string $kind, string $source, string $target): string { return hash('sha256', "wordpress-site-plan/{$kind}/v2\n{$source}\n{$target}"); }
    public static function contentHash(string $content): string { return hash('sha256', $content); }
    private static function hash(mixed $value): bool { return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value); }
    /** @param array<int,mixed> $declarations */
    private static function assertRuntimeDeclarations(array $declarations): void { $identities=array(); $keys=array(); foreach($declarations as $declaration){if(!is_array($declaration)||!is_string($declaration['kind']??null)||(!is_string($declaration['type']??null)&&!is_string($declaration['capability']??null))||(isset($declaration['type'])&&isset($declaration['capability']))||!self::safePath($declaration['source_path']??null)||!self::hash($declaration['reconciliation_identity']??null))throw new InvalidArgumentException('WordPress site plan runtime declaration is invalid.');$name=$declaration['type']??$declaration['capability'];$key=$declaration['kind'].':'.$name;if($declaration['reconciliation_identity']!==hash('sha256',"wordpress-site-plan/runtime-declaration/v1\n{$declaration['source_path']}\n{$key}"))throw new InvalidArgumentException('WordPress site plan runtime declaration identity is invalid.');self::unique($identities,$declaration['reconciliation_identity'],'runtime declaration reconciliation identity');self::unique($keys,$key,'runtime declaration key');if(isset($declaration['payload'])&&(!is_array($declaration['payload'])||!is_string($declaration['payload']['schema']??null)))throw new InvalidArgumentException('WordPress site plan runtime declaration payload is invalid.');if('entity_collection'===$declaration['kind']&&(!isset($declaration['type'],$declaration['payload']['entities'])||!is_array($declaration['payload']['entities'])))throw new InvalidArgumentException('WordPress site plan entity collection declaration is invalid.');}foreach($declarations as $declaration)foreach($declaration['required_for']??array() as $required)if(!is_string($required)||!isset($keys[strtolower($required)]))throw new InvalidArgumentException('WordPress site plan runtime declaration required_for is unresolved.'); }
    /** @param array<string,mixed> $source */
    private static function assertSource(array $source): void { if ('blocks-engine/php-transformer/compiled-site/v1' !== ($source['schema'] ?? null) || !is_string($source['source_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/', $source['source_hash']) || !is_string($source['entry_path'] ?? null) || !is_array($source['provenance'] ?? null)) throw new InvalidArgumentException('WordPress site plan source identity is invalid.'); }
    /** @param array<int,mixed> $rows @param array<int,string> $fields @param array<int,string> $optional */
    private static function assertRows(array $rows, string $kind, array $fields, array $optional = array()): void { foreach ($rows as $row) { if (!is_array($row)) throw new InvalidArgumentException("WordPress site plan {$kind} must be an array."); foreach ($fields as $field) if (!array_key_exists($field, $row) || (!is_string($row[$field]) && !is_int($row[$field]))) throw new InvalidArgumentException("WordPress site plan {$kind} lacks {$field}."); foreach ($optional as $field) if (array_key_exists($field, $row) && !is_string($row[$field])) throw new InvalidArgumentException("WordPress site plan {$kind} has invalid {$field}."); } }
    /** @param array<string,mixed> $data */
    private static function value(array $data, string $key, string $default = ''): string { return is_string($data[$key] ?? null) ? $data[$key] : $default; }
    private static function explicitUrl(mixed $url): bool { return is_string($url) && (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $url) === 1 || self::routeUrl($url)); }
    private static function routeUrl(mixed $url): bool { return is_string($url) && preg_match('~^/(?:[a-z0-9-]+(?:/[a-z0-9-]+)*)?(?:[?#].*)?$~', $url) === 1; }
    private static function safePath(mixed $path): bool { if (!is_string($path) || '' === $path || str_contains($path, "\0") || str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:/', $path)) return false; foreach (explode('/', str_replace('\\', '/', $path)) as $segment) if ('' === $segment || '.' === $segment || '..' === $segment) return false; return true; }
}
