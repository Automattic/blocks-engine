<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
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
        $tokens = $this->tokens($assets);
        $references = new AssetReferenceCanonicalizer($tokens);
        $routes = is_array($materialization['routes'] ?? null) ? $materialization['routes'] : array();
        $pages = $this->documents($compiled['pages'] ?? null, false, $tokens, $references, $routes);
        $parts = $this->documents($compiled['template_parts'] ?? null, true, $tokens, $references, $routes);
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
            'diagnostics' => array_merge($data['diagnostics'], $scriptLoading['diagnostics']),
            'quality' => array('status' => $data['status'], 'metrics' => array_diff_key($data['metrics'], array('transform_duration_ms' => true)), 'fallbacks' => $data['fallbacks']),
            'reporting' => $this->reporting($compiled, $data, $scriptLoading['diagnostics']),
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
        foreach ( array('source', 'pages', 'templates', 'template_parts', 'assets', 'reference_tokens', 'reference_semantics', 'writes', 'operations', 'routes', 'navigation_links', 'menus', 'theme', 'visual_repair', 'diagnostics', 'quality', 'reporting') as $key ) {
            if ( ! is_array($plan[$key] ?? null) ) {
                throw new InvalidArgumentException(sprintf('WordPress site plan %s must be an array.', $key));
            }
        }
        self::assertSource($plan['source']);
        if ('declared_tokens_only' !== ($plan['reference_semantics']['static_browser_references'] ?? null) || !in_array($plan['reference_semantics']['dynamic_script_references'] ?? null, array('proven', 'not_proven'), true) || !is_array($plan['reference_semantics']['dynamic_client_assets'] ?? null) || !in_array($plan['reference_semantics']['dynamic_client_assets']['status'] ?? null, array('proven', 'not_proven'), true) || !is_bool($plan['reference_semantics']['dynamic_client_assets']['materializer_may_reject'] ?? null) || ($plan['reference_semantics']['dynamic_script_references'] ?? null) !== ($plan['reference_semantics']['dynamic_client_assets']['status'] ?? null) || ('proven' === $plan['reference_semantics']['dynamic_client_assets']['status'] && true === $plan['reference_semantics']['dynamic_client_assets']['materializer_may_reject'])) throw new InvalidArgumentException('WordPress site plan reference capability semantics are invalid.');
        self::assertRows($plan['routes'], 'route', array('kind', 'source_path', 'target_path', 'target_slug', 'source_relation', 'order'));
        self::assertRows($plan['navigation_links'], 'navigation link', array('kind', 'source_path', 'source_relation', 'order'), array('target_path', 'target_slug'));
        self::assertRows($plan['menus'], 'menu', array('kind', 'source_path', 'target_slug', 'source_relation', 'order', 'items'));
        $assetTargets = array();
        $assetTokens = array();
        foreach ( $plan['assets'] as $asset ) {
            if ( ! is_array($asset) || ! self::safePath($asset['source_path'] ?? null) || ! self::safePath($asset['target_path'] ?? null) || ! is_string($asset['token'] ?? null) ) {
                throw new InvalidArgumentException('WordPress site plan asset is structurally invalid.');
            }
            self::unique($assetTargets, $asset['target_path'], 'asset target');
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
            self::unique($partSlugs, $part['slug'], 'template part slug');
        }
        $pagePaths = array();
        foreach ( $plan['pages'] as $page ) {
            self::assertDocument($page, 'page', false, $tokens);
            self::unique($pagePaths, $page['source_path'], 'page source');
        }
        self::assertReporting($plan['reporting'], $pagePaths, $tokens);
        self::assertOperations($plan['operations'], $plan['pages']);
        $templateTargets = array();
        foreach ( $plan['templates'] as $template ) {
            if ( ! is_array($template) || ! is_string($template['slug'] ?? null) || ! self::safePath($template['target_path'] ?? null) || ! is_string($template['canonical_block_markup'] ?? null) || '' === trim($template['canonical_block_markup']) ) {
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
        self::assertScaffold($plan, $writesByTarget);
        foreach ( $plan['templates'] as $template ) {
            $write = $writesByTarget[$template['target_path']] ?? null;
            if ( ! is_array($write) || 'theme_template' !== ($write['kind'] ?? null) || $write['payload']['data'] !== $template['canonical_block_markup'] ) {
                throw new InvalidArgumentException('WordPress site plan template lacks its canonical write.');
            }
        }
        foreach ( $plan['template_parts'] as $part ) {
            $target = 'parts/' . $part['slug'] . '.html';
            $write = $writesByTarget[$target] ?? null;
            if ( ! is_array($write) || 'theme_template_part' !== ($write['kind'] ?? null) || $write['payload']['data'] !== $part['canonical_block_markup'] ) {
                throw new InvalidArgumentException('WordPress site plan template part lacks its canonical write.');
            }
            $boundTemplates = 'entry_shell' === ($part['placement']['kind'] ?? null) ? $part['placement']['template_slugs'] : array();
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
        if ( ! is_string($plan['theme']['stylesheet'] ?? null) || ! is_string($plan['theme']['theme_json'] ?? null) || (null !== ($plan['theme']['bootstrap'] ?? null) && ! is_string($plan['theme']['bootstrap'])) ) {
            throw new InvalidArgumentException('WordPress site plan theme is structurally invalid.');
        }
        if ( ! is_string($plan['quality']['status'] ?? null) || ! is_array($plan['quality']['metrics'] ?? null) || ! is_array($plan['quality']['fallbacks'] ?? null) ) {
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
            $rows[] = array('source_path' => $document['source_path'], 'slug' => self::value($document, 'slug'), 'title' => self::value($document, 'title'), 'post_type' => self::value((array) ($document['metadata'] ?? array()), 'post_type', 'page'), 'parent_source_path' => self::value((array) ($document['metadata'] ?? array()), 'parent_source_path'), 'entrypoint' => ! empty($document['entrypoint']), 'area' => $part ? self::value($document, 'area', 'uncategorized') : null, 'placement' => $part && is_array($document['placement'] ?? null) ? $document['placement'] : ($part ? array('kind' => 'unbound') : null), 'canonical_block_markup' => $this->routeLinks($markup, $document['source_path'], $routes), 'metadata' => is_array($document['metadata'] ?? null) ? $document['metadata'] : array(), 'document_metadata' => $this->documentMetadata($document, $references), 'provenance' => is_array($document['provenance'] ?? null) ? $document['provenance'] : array(), 'reconciliation_identity' => hash('sha256', $document['source_path'] . "\n" . $document['block_markup']));
        }
        return $rows;
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
            $rows[] = array('source_path' => $asset['path'], 'target_path' => $target, 'token' => 'asset-' . substr(hash('sha256', $target), 0, 16), 'source' => self::value($asset, 'source'), 'kind' => self::value($asset, 'kind'), 'role' => self::value($asset, 'role'), 'intent' => self::value($asset, 'intent'), 'mime_type' => self::value($asset, 'mime_type'), 'media' => self::value($asset, 'media'), 'hash' => self::value($asset, 'hash'), 'content' => $asset['content'] ?? null, 'content_base64' => $asset['content_base64'] ?? null, 'binary' => ! empty($asset['binary']));
        }
        return $rows;
    }

    /** @param array<int,array<string,mixed>> $assets @return array<int,array<string,string>> */
    private function tokens(array $assets): array { return array_map(static fn(array $asset): array => array('token' => $asset['token'], 'source_path' => $asset['source_path'], 'target_path' => $asset['target_path']), $assets); }
    /** @param array<string,mixed> $document @param array<int,array<string,string>> $tokens @return array<string,mixed> */
    private function documentMetadata(array $document, AssetReferenceCanonicalizer $references): array { $metadata = is_array($document['document_metadata'] ?? null) ? $document['document_metadata'] : array('source_context' => array('source_path' => self::value($document, 'source_path'), 'kind' => 'document'), 'title' => self::value($document, 'title'), 'title_declaration' => array('order' => 0, 'placement' => 'head'), 'meta' => array(), 'links' => array(), 'scripts' => array()); foreach (array('links', 'scripts') as $kind) { if (!is_array($metadata[$kind] ?? null)) $metadata[$kind] = array(); foreach ($metadata[$kind] as &$row) if (is_array($row) && is_string($row['url'] ?? null)) { $reference = $references->reference($row['url'], self::value($document, 'source_path')); if (null !== $reference) { $row['asset_reference'] = $reference; unset($row['url']); } } unset($row); } return $metadata; }
    /** @param array<string,mixed> $compiled @param array<string,mixed> $data @return array<string,mixed> */
    private function reporting(array $compiled, array $data, array $scriptDiagnostics = array()): array { $documents = array(); foreach ($compiled['pages'] ?? array() as $page) if (is_array($page)) $documents[] = array('source_path' => $page['source_path'] ?? '', 'kind' => $page['kind'] ?? '', 'body_format' => $page['body_format'] ?? '', 'block_document' => 'blocks' === ($page['body_format'] ?? ''), 'provenance' => $page['provenance'] ?? array()); return array('source_documents' => $documents, 'metrics' => array('source_document_count' => count($documents), 'block_document_count' => count(array_filter($documents, static fn(array $document): bool => !empty($document['block_document']))), 'native_block_count' => $data['metrics']['block_count'] ?? 0, 'fallback_count' => $data['metrics']['fallback_count'] ?? 0), 'diagnostic_codes' => array_values(array_map(static fn(array $diagnostic): string => (string) ($diagnostic['code'] ?? ''), array_merge($data['diagnostics'], $scriptDiagnostics)))); }

    /** @param array<int,array<string,mixed>> $pages @return array<int,array<string,string>> */
    private function templates(array $pages, array $parts): array
    {
        $bound = array_values(array_filter($parts, static fn(array $part): bool => in_array($part['placement']['kind'] ?? '', array('entry_shell'), true)));
        usort($bound, static function (array $left, array $right): int {
            $priority = array('header' => 0, 'footer' => 2);
            return (($priority[$left['area']] ?? 1) <=> ($priority[$right['area']] ?? 1)) ?: strcmp($left['slug'], $right['slug']);
        });
        $markup = static function (string $templateSlug) use ($bound): string {
            $content = '';
            foreach ($bound as $part) if (in_array($templateSlug, $part['placement']['template_slugs'] ?? array(), true)) $content .= '<!-- wp:template-part {"slug":"' . $part['slug'] . '","area":"' . $part['area'] . '"} /-->' . "\n";
            return $content . '<!-- wp:post-content /-->' . "\n";
        };
        $templates = array(array('slug' => 'index', 'target_path' => 'templates/index.html', 'canonical_block_markup' => $markup('index')));
        if ( array() !== $pages ) $templates[] = array('slug' => 'page', 'target_path' => 'templates/page.html', 'canonical_block_markup' => $markup('page'));
        foreach ( $pages as $page ) if ( ! empty($page['entrypoint']) ) { $templates[] = array('slug' => 'front-page', 'target_path' => 'templates/front-page.html', 'canonical_block_markup' => $markup('front-page')); break; }
        return $templates;
    }

    /** @param array<int,array<string,mixed>> $pages @return array<int,array<string,mixed>> */
    private function operations(array $pages): array
    {
        foreach ($pages as $page) {
            if (!empty($page['entrypoint'])) return array(array('kind' => 'site_reading', 'order' => 0, 'show_on_front' => 'page', 'front_page_source_path' => $page['source_path'], 'front_page_reconciliation_identity' => $page['reconciliation_identity']));
        }
        return array();
    }

    /** @param array<int,array<string,mixed>> $assets @param array<int,array<string,string>> $tokens @return array<int,array<string,mixed>> */
    private function assetWrites(array $assets, AssetReferenceCanonicalizer $references): array
    {
        $writes = array();
        foreach ( $assets as $asset ) {
            $content = is_string($asset['content'] ?? null) ? $references->content($asset['content'], $asset['source_path']) : null;
            $data = is_string($asset['content_base64'] ?? null) ? $asset['content_base64'] : (is_string($content) ? (! empty($asset['binary']) || 1 !== preg_match('//u', $content) ? base64_encode($content) : $content) : null);
            if ( ! is_string($data) ) throw new InvalidArgumentException(sprintf('Compiled site asset %s lacks a materializable payload.', $asset['source_path']));
            $writes[] = array('kind' => 'theme_asset', 'source_path' => $asset['source_path'], 'target_path' => $asset['target_path'], 'payload' => array('encoding' => is_string($asset['content_base64'] ?? null) || ! empty($asset['binary']) || 1 !== preg_match('//u', $data) ? 'base64' : 'utf8', 'data' => $data));
        }
        return $writes;
    }

    /** @param array<int,array<string,mixed>> $pages @param array<int,array<string,mixed>> $parts @param array<int,array<string,mixed>> $assets @param array<int,array<string,string>> $tokens @param array<int,array<string,mixed>> $operations @return array{scripts:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>} */
    private function scriptLoading(array $pages, array $parts, array $assets, array $tokens, array $operations): array
    {
        $targets = array(); foreach ($tokens as $token) $targets[$token['token']] = $token['target_path'];
        $contents = array(); foreach ($assets as $asset) if (is_string($asset['content'] ?? null)) $contents[$asset['target_path']] = $asset['content'];
        $frontPages = array(); foreach ($operations as $operation) if ('site_reading' === ($operation['kind'] ?? null)) $frontPages[$operation['front_page_reconciliation_identity']] = true;
        $scripts = array(); $diagnostics = array(); $instances = array();
        foreach (array_merge($pages, $parts) as $document) foreach ($document['document_metadata']['scripts'] ?? array() as $script) {
            $source = $document['source_path'] . '#' . ($script['order'] ?? '');
            $unsupported = static function (string $code, string $message) use (&$diagnostics, $source): void { $diagnostics[] = array('code' => $code, 'severity' => 'warning', 'message' => $message, 'source_path' => $source); };
            if (!is_array($script) || 'inline' === ($script['source_kind'] ?? null)) { $unsupported('wordpress_site_plan_script_inline_unsupported', 'Inline document scripts cannot be materialized because the canonical metadata deliberately retains only their hash.'); continue; }
            if (true === ($script['module'] ?? false) && true === ($script['nomodule'] ?? false)) { $unsupported('wordpress_site_plan_script_module_nomodule_conflict', 'A document script cannot combine module and nomodule semantics.'); continue; }
            if (isset($document['placement']) && 'entry_shell' !== ($document['placement']['kind'] ?? null)) { $unsupported('wordpress_site_plan_script_unbound_template_part', 'A template-part script cannot be materialized because its template placement is unbound.'); continue; }
            $localTarget = null; $suffix = ''; $url = null;
            if (is_string($script['asset_reference'] ?? null) && preg_match('/^\{\{wordpress-site-plan:asset:([^}]+)\}\}(.*)$/', $script['asset_reference'], $match) && isset($targets[$match[1]])) { $localTarget = $targets[$match[1]]; $suffix = $match[2]; }
            elseif (is_string($script['url'] ?? null) && preg_match('~^(?:https?:)?//[^\x00-\x20]+$~i', $script['url'])) { $url = $script['url']; $unsupported('wordpress_site_plan_script_external_unproven', 'An external script URL is emitted but cannot prove its runtime references without a declared local artifact.'); }
            else { $unsupported('wordpress_site_plan_script_url_unsupported', 'A document script must reference a declared local write or an absolute HTTP(S) URL.'); continue; }
            if (null !== $localTarget && $this->hasDynamicScriptReferences($contents[$localTarget] ?? '')) { $unsupported('wordpress_site_plan_script_dynamic_references', 'A local script contains dynamic imports, script injection, or runtime URL construction that cannot be proven from the canonical write.'); continue; }
            $attributes = array('placement' => $script['placement'], 'local_target' => $localTarget, 'suffix' => $suffix, 'url' => $url, 'async' => $script['async'], 'defer' => $script['defer'], 'module' => $script['module'], 'nomodule' => $script['nomodule'], 'type' => $script['type'] ?? ($script['module'] ? 'module' : null), 'integrity' => $script['integrity'] ?? null, 'crossorigin' => $script['crossorigin'] ?? null, 'referrerpolicy' => $script['referrerpolicy'] ?? null, 'fetchpriority' => $script['fetchpriority'] ?? null);
            $scope = isset($document['placement']) ? array('kind' => 'global', 'order' => $script['order']) : array('kind' => 'page', 'source_path' => $document['source_path'], 'route_path' => self::pageRoutePath($document['source_path']), 'front_page' => isset($frontPages[$document['reconciliation_identity']]), 'reconciliation_identity' => $document['reconciliation_identity'], 'order' => $script['order']);
            $scopeKey = ($scope['kind'] ?? '') . ':' . ($scope['source_path'] ?? 'global');
            $signature = hash('sha256', serialize($attributes)); $instance = $instances[$scopeKey][$signature] ?? 0; $instances[$scopeKey][$signature] = $instance + 1;
            $identity = $signature . ':' . $instance;
            if (!isset($scripts[$identity])) $scripts[$identity] = array_merge(array('identity' => $identity, 'scopes' => array()), $attributes);
            $scripts[$identity]['scopes'][] = $scope;
        }
        return array('scripts' => array_values($scripts), 'diagnostics' => $diagnostics);
    }

    private function hasDynamicScriptReferences(string $content): bool { return preg_match('/\bimport\s*\(|\b(?:document\s*\.\s*createElement\s*\(\s*["\']script|appendChild\s*\(|insertBefore\s*\(|\.\s*src\s*=|new\s+URL\s*\()/i', $content) === 1; }
    private static function pageRoutePath(string $sourcePath): string { $segments = explode('/', preg_replace('/\.[A-Za-z0-9]+$/', '', $sourcePath) ?? $sourcePath); $segments = array_map(static fn(string $segment): string => trim(strtolower((string) preg_replace('/[^a-z0-9_-]/', '', str_replace('_', '-', $segment))), '-'), $segments); return implode('/', array_values(array_filter($segments, static fn(string $segment): bool => '' !== $segment && 'index' !== $segment))); }

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
    private function write(string $kind, string $target, string $content): array { return array('kind' => $kind, 'source_path' => 'wordpress-site-plan/' . $target, 'target_path' => $target, 'payload' => array('encoding' => 'utf8', 'data' => $content)); }
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
        foreach ($routes as $route) {
            if (!is_array($route) || !is_string($route['source_path'] ?? null) || !is_string($route['target_path'] ?? null)) continue;
            foreach (array_unique(array($route['source_path'], self::relativePath($origin, $route['source_path']))) as $candidate) {
                $content = preg_replace('~(?<![A-Za-z0-9_.\/-])' . preg_quote($candidate, '~') . '(?![A-Za-z0-9_.\/-])~', $route['target_path'], $content) ?? $content;
            }
        }
        return $content;
    }
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
    /** @param array<int,array<string,mixed>> $operations @param array<int,array<string,mixed>> $pages */
    private static function assertOperations(array $operations, array $pages): void
    {
        $pagesBySource = array(); foreach ($pages as $page) $pagesBySource[$page['source_path']] = $page;
        foreach ($operations as $index => $operation) {
            if (!is_array($operation) || 'site_reading' !== ($operation['kind'] ?? null) || $index !== ($operation['order'] ?? null) || 'page' !== ($operation['show_on_front'] ?? null) || !is_string($operation['front_page_source_path'] ?? null) || !is_string($operation['front_page_reconciliation_identity'] ?? null)) throw new InvalidArgumentException('WordPress site plan operation is invalid.');
            $page = $pagesBySource[$operation['front_page_source_path']] ?? null;
            if (!is_array($page) || empty($page['entrypoint']) || $page['reconciliation_identity'] !== $operation['front_page_reconciliation_identity']) throw new InvalidArgumentException('WordPress site plan operation references an invalid front page.');
        }
    }
    private static function assertNoLocalBrowserReferences(string $content): void
    {
        $patterns = array('/\b(?:src|href|srcset|poster|action)\s*=\s*["\']([^"\']+)["\']/i', '/["\'](?:url|src|href|srcset|poster|action)["\']\s*:\s*["\']([^"\']+)["\']/i', '/(?:url\(\s*["\']?|@import\s+(?:url\(\s*)?["\']?)([^\s\)"\';]+)/i');
        foreach ($patterns as $pattern) if (preg_match_all($pattern, $content, $matches)) foreach ($matches[1] as $value) foreach (explode(',', (string) $value) as $candidate) {
            $url = trim(preg_split('/\s+/', trim($candidate))[0] ?? '');
            if ('' !== $url && !str_starts_with($url, self::TOKEN_PREFIX) && !preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|/|#|\?)~i', $url)) throw new InvalidArgumentException(sprintf('WordPress site plan contains unresolved local browser reference %s.', $url));
        }
    }
    /** @param array<string,string> $tokens */
    private static function assertDocument(mixed $document, string $kind, bool $part, array $tokens): void { if(!is_array($document)||!self::safePath($document['source_path']??null)||!is_string($document['slug']??null)||!is_string($document['title']??null)||!is_string($document['post_type']??null)||!is_string($document['parent_source_path']??null)||!is_bool($document['entrypoint']??null)||!is_string($document['canonical_block_markup']??null)||''===trim($document['canonical_block_markup'])||!is_array($document['metadata']??null)||!is_array($document['document_metadata']??null)||!is_array($document['provenance']??null)||!is_string($document['reconciliation_identity']??null)||($part&&(!is_string($document['area']??null)||''===$document['area']||!is_array($document['placement']??null)))||(!$part&&(null!==($document['area']??null)||null!==($document['placement']??null))))throw new InvalidArgumentException("WordPress site plan {$kind} is structurally invalid.");if($part&&'entry_shell'===($document['placement']['kind']??null)&&(!is_string($document['placement']['source_path']??null)||!is_array($document['placement']['template_slugs']??null)||array()=== $document['placement']['template_slugs']))throw new InvalidArgumentException('WordPress site plan template part placement is invalid.');self::assertDocumentMetadata($document['document_metadata'],$tokens);self::assertTokens($document['canonical_block_markup'],$tokens);self::assertNoLocalBrowserReferences($document['canonical_block_markup']); }
    /** @param array<string,mixed> $metadata @param array<string,bool> $tokens */
    private static function assertDocumentMetadata(array $metadata, array $tokens): void { if(!is_array($metadata['source_context']??null)||!self::safePath($metadata['source_context']['source_path']??null)||!is_string($metadata['source_context']['kind']??null)||!is_string($metadata['title']??null)||!is_array($metadata['title_declaration']??null)||0!==($metadata['title_declaration']['order']??null)||'head'!==($metadata['title_declaration']['placement']??null)||!is_array($metadata['meta']??null)||!is_array($metadata['links']??null)||!is_array($metadata['scripts']??null))throw new InvalidArgumentException('WordPress site plan document metadata is structurally invalid.');foreach($metadata['meta'] as $index=>$row)if(!is_array($row)||$index!==($row['order']??null)||!in_array($row['placement']??null,array('head','body'),true)||array_diff(array_keys($row),array('order','placement','charset','name','property','http_equiv','content')))throw new InvalidArgumentException('WordPress site plan meta declaration is invalid.');foreach($metadata['links'] as $index=>$row){if(!is_array($row)||$index!==($row['order']??null)||!in_array($row['placement']??null,array('head','body'),true)||(!is_string($row['asset_reference']??null)&&!self::explicitUrl($row['url']??null))||array_diff(array_keys($row),array('order','placement','rel','type','media','integrity','crossorigin','referrerpolicy','as','fetchpriority','sizes','asset_reference','url','resolved_url')))throw new InvalidArgumentException('WordPress site plan link declaration is invalid.');if(is_string($row['asset_reference']??null))self::assertTokens($row['asset_reference'],$tokens);}foreach($metadata['scripts'] as $index=>$row){if(!is_array($row)||$index!==($row['order']??null)||!in_array($row['placement']??null,array('head','body'),true)||!is_bool($row['defer']??null)||!is_bool($row['async']??null)||!is_bool($row['module']??null)||!is_bool($row['nomodule']??null)||!in_array($row['effective_loading']??null,array('blocking','defer','async'),true)||($row['async']&&'async'!==$row['effective_loading'])||(!$row['async']&&($row['defer']||$row['module'])&&'defer'!==$row['effective_loading'])||(!$row['async']&&!$row['defer']&&!$row['module']&&'blocking'!==$row['effective_loading'])||(!is_string($row['asset_reference']??null)&&!self::explicitUrl($row['url']??null)&&'inline'!==($row['source_kind']??null))||array_diff(array_keys($row),array('order','placement','async','defer','module','nomodule','effective_loading','type','integrity','crossorigin','referrerpolicy','fetchpriority','asset_reference','url','resolved_url','source_kind','body_hash')))throw new InvalidArgumentException('WordPress site plan script declaration is invalid.');if(is_string($row['asset_reference']??null))self::assertTokens($row['asset_reference'],$tokens);}}
    /** @param array<string,mixed> $reporting @param array<string,bool> $pagePaths @param array<string,bool> $tokens */
    private static function assertReporting(array $reporting, array $pagePaths, array $tokens): void { if(!is_array($reporting['source_documents']??null)||!is_array($reporting['metrics']??null)||!is_array($reporting['diagnostic_codes']??null))throw new InvalidArgumentException('WordPress site plan reporting summary is invalid.');$sources=array();foreach($reporting['source_documents'] as $document){if(!is_array($document)||!self::safePath($document['source_path']??null)||!is_string($document['kind']??null)||!is_string($document['body_format']??null)||!is_bool($document['block_document']??null)||!is_array($document['provenance']??null))throw new InvalidArgumentException('WordPress site plan source document summary is invalid.');self::unique($sources,$document['source_path'],'source document');}if(count($sources)!==count($pagePaths)||array_keys($sources)!==array_keys($pagePaths))throw new InvalidArgumentException('WordPress site plan source document summaries do not match pages.');foreach(array('source_document_count','block_document_count','native_block_count','fallback_count') as $key)if(!is_int($reporting['metrics'][$key]??null))throw new InvalidArgumentException('WordPress site plan reporting metric is invalid.');foreach($reporting['diagnostic_codes'] as $code)if(!is_string($code)||''===$code)throw new InvalidArgumentException('WordPress site plan diagnostic linkage is invalid.');}
    /** @param array<string,string> $tokens */
    private static function assertWrite(mixed $write, array $tokens): void { if (!is_array($write) || !is_string($write['kind'] ?? null) || !self::safePath($write['source_path'] ?? null) || !self::safePath($write['target_path'] ?? null) || !is_array($write['payload'] ?? null) || !in_array($write['payload']['encoding'] ?? null, array('utf8','base64'), true) || !is_string($write['payload']['data'] ?? null)) throw new InvalidArgumentException('WordPress site plan write is structurally invalid.'); if ('base64' === $write['payload']['encoding'] && false === base64_decode($write['payload']['data'], true)) throw new InvalidArgumentException('WordPress site plan write has invalid base64 payload.'); if ('utf8' === $write['payload']['encoding']) { self::assertTokens($write['payload']['data'], $tokens); self::assertNoLocalBrowserReferences($write['payload']['data']); } }
    /** @param array<string,string> $tokens */
    private static function assertTokens(string $content, array $tokens): void { if (preg_match_all('/\{\{wordpress-site-plan:asset:([^}]+)\}\}/', $content, $matches)) foreach ($matches[1] as $token) if (!isset($tokens[$token])) throw new InvalidArgumentException('WordPress site plan contains an undeclared reference token.'); }
    /** @param array<string,bool> $values */
    private static function unique(array &$values, string $value, string $kind): void { $key = strtolower($value); if (isset($values[$key])) throw new InvalidArgumentException("WordPress site plan has colliding {$kind}s."); $values[$key] = true; }
    /** @param array<string,mixed> $source */
    private static function assertSource(array $source): void { if ('blocks-engine/php-transformer/compiled-site/v1' !== ($source['schema'] ?? null) || !is_string($source['source_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/', $source['source_hash']) || !is_string($source['entry_path'] ?? null) || !is_array($source['provenance'] ?? null)) throw new InvalidArgumentException('WordPress site plan source identity is invalid.'); }
    /** @param array<int,mixed> $rows @param array<int,string> $fields @param array<int,string> $optional */
    private static function assertRows(array $rows, string $kind, array $fields, array $optional = array()): void { foreach ($rows as $row) { if (!is_array($row)) throw new InvalidArgumentException("WordPress site plan {$kind} must be an array."); foreach ($fields as $field) if (!array_key_exists($field, $row) || (!is_string($row[$field]) && !is_int($row[$field]))) throw new InvalidArgumentException("WordPress site plan {$kind} lacks {$field}."); foreach ($optional as $field) if (array_key_exists($field, $row) && !is_string($row[$field])) throw new InvalidArgumentException("WordPress site plan {$kind} has invalid {$field}."); } }
    /** @param array<string,mixed> $data */
    private static function value(array $data, string $key, string $default = ''): string { return is_string($data[$key] ?? null) ? $data[$key] : $default; }
    private static function explicitUrl(mixed $url): bool { return is_string($url) && preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $url) === 1; }
    private static function safePath(mixed $path): bool { if (!is_string($path) || '' === $path || str_contains($path, "\0") || str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:/', $path)) return false; foreach (explode('/', str_replace('\\', '/', $path)) as $segment) if ('' === $segment || '.' === $segment || '..' === $segment) return false; return true; }
}
