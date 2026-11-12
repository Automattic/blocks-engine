<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\StepTimer;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityPolicy;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeEntityManifest;
use Automattic\BlocksEngine\PhpTransformer\Path\RouteSlug;
use InvalidArgumentException;

/** A complete, destination-independent block-theme materialization contract. */
final class WordPressSitePlan
{
    public const SCHEMA = 'blocks-engine/wordpress-site-plan/v3';
    public const IDENTITY_SCHEMA = 'blocks-engine/wordpress-site-plan-identity/v1';
    /** Shared site shells also serve WordPress routes created after capture. */
    public const NATIVE_TEMPLATE_SLUGS = array('index', 'page', 'front-page', 'single', 'search', 'archive', '404');
    public const NATIVE_QUERY_TEMPLATE_SLUGS = array('index', 'search', 'archive', '404');
    public const TOKEN_PREFIX = '{{wordpress-site-plan:asset:';
    public const NAVIGATION_TOKEN_PREFIX = '{{wordpress-site-plan:navigation:';
    /** A `core/block` ref naming a declared `synced_patterns` entity. */
    public const SYNCED_PATTERN_TOKEN_PREFIX = '{{wordpress-site-plan:pattern:';
    /** Blocks whose serialized `url` attribute names a route rather than an asset. */
    public const ROUTE_URL_BLOCKS = array('navigation-link', 'navigation-submenu', 'button', 'social-link');
    /** Marks the post-content block of a template that holds a page's hoisted layout frame. */
    public const FRAME_CONTENT_CLASS = 'blocks-engine-frame-content';
    public const LISTING_QUERY_CLASS = 'blocks-engine-listing-query';
    public const MAX_UNRESOLVED_NAVIGATION_DIAGNOSTICS = 50;

    /**
     * @param bool $strictMissingMedia Rejects the whole plan when a media
     *        reference names a local file the artifact never packaged, instead
     *        of recovering it as an explicit placeholder and warning.
     *
     * @param StepTimer|null $timer Receives one exclusive step per site-plan stage, so callers
     *                             can always see where composition spends its time.
     */
    public function __construct(private readonly bool $strictMissingMedia = false, private readonly ?StepTimer $timer = null)
    {
    }

    /**
     * Versioned canonical identity for an approval system to bind externally.
     * This is an integrity comparison input, not a signature or authentication proof.
     *
     * @param array<string,mixed> $plan
     * @return array{schema:string,hash:string}
     */
    public static function planIdentity(array $plan): array
    {
        $canonical = $plan;
        unset($canonical['resolution'], $canonical['runtime_entity_resolution']);
        // The identity describes the plan; including it would make its hash recursive.
        unset($canonical['plan_identity']);
        return array('schema' => self::IDENTITY_SCHEMA, 'hash' => RuntimeDeclarations::hash($canonical, ArtifactCompiler::MAX_PLAN_DIGEST_DEPTH));
    }

    /**
     * Compatibility alias for callers that used the pre-identity canonical hash API.
     *
     * @param array<string,mixed> $plan
     */
    public static function canonicalHash(array $plan): string
    {
        return self::planIdentity($plan)['hash'];
    }

    /** @return array<string,mixed> */
    public function fromResult(TransformerResult|array $result): array
    {
        $data = $result instanceof TransformerResult ? $result->toArray() : $result;
        TransformerResult::assertCanonicalEnvelope($data);
        return $this->fromCompilerInput($data, WordPressSitePlanInput::fromCompilerResult($data, $data['source_reports']['conversion_report']['core_html_fallback_evidence'] ?? array()));
    }

    /**
     * Projects compiler-owned result data before its terminal conversion report exists.
     *
     * @internal Pre-report projection. Canonical compile derives plans via fromResult() on the transformer envelope.
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function fromCompilerResult(array $data): array
    {
        return $this->fromCompilerInput($data, WordPressSitePlanInput::fromCompilerResult($data, $data['source_reports']['core_html_fallback_evidence'] ?? array()));
    }

    /**
     * @internal Explicit compiler-to-plan boundary used by fromResult() and fromCompilerResult().
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function fromCompilerInput(array $data, WordPressSitePlanInput $input): array
    {
        return (new SitePlanRun($this->strictMissingMedia, $this->timer))->plan($data, $input);
    }

    /**
     * Record findings measured against a finished plan, such as runtime
     * dependency parity, without discarding the plan. The plan reports them as
     * warnings and its identity is recomputed over the updated diagnostics.
     *
     * @param array<string,mixed> $plan
     * @param array<int,array<string,mixed>> $diagnostics Warning-severity findings.
     * @return array<string,mixed>
     */
    public static function withFindings(array $plan, array $diagnostics): array
    {
        if (array() === $diagnostics) return $plan;
        foreach ($diagnostics as $diagnostic) {
            $plan['diagnostics'][] = $diagnostic;
            $plan['reporting']['diagnostic_codes'][] = (string) ($diagnostic['code'] ?? '');
        }
        $plan['quality']['status'] = UnitFindings::status($plan['quality']['status'], $diagnostics);
        $plan['plan_identity'] = self::planIdentity($plan);
        self::assertValid($plan);
        return $plan;
    }

    /** @param array<string,mixed> $plan */
    public static function assertValid(array $plan): void
    {
        // Hash the actual content, including identity and resolution, so a mutated
        // plan cannot reuse a previous success by retaining its declared identity.
        static $validated = array();
        $contentHash = self::contentHash(serialize($plan));
        if (isset($validated[$contentHash])) return;
        if ( self::SCHEMA !== ($plan['schema'] ?? null) ) {
            throw new InvalidArgumentException('WordPress site plan has an unsupported schema.');
        }
        foreach ( array('plan_identity', 'source', 'pages', 'templates', 'template_parts', 'assets', 'reference_tokens', 'reference_semantics', 'writes', 'operations', 'routes', 'taxonomy_entities', 'author_entities', 'navigation_links', 'menus', 'theme', 'visual_repair', 'runtime_declarations', 'runtime_entity_records', 'diagnostics', 'quality', 'reporting') as $key ) {
            if ( ! is_array($plan[$key] ?? null) ) {
                throw new InvalidArgumentException(sprintf('WordPress site plan %s must be an array.', $key));
            }
        }
        if ($plan['plan_identity']['schema'] !== self::IDENTITY_SCHEMA || !self::hash($plan['plan_identity']['hash'])) {
            throw new InvalidArgumentException('WordPress site plan identity is missing, malformed, or stale.');
        }
        self::assertSource($plan['source']);
        $sourceCatalog = TemplateSurfaces::planSourceCatalog($plan['source']);
        RuntimeDeclarations::assertNormalized($plan['runtime_declarations'], !isset($plan['resolution']));
        $records = RuntimeEntityManifest::normalizeRecords($plan['runtime_entity_records']);
        if ($records !== $plan['runtime_entity_records']) throw new InvalidArgumentException('WordPress site plan runtime entity records are not canonically normalized.');
        foreach ($plan['runtime_declarations'] as $declaration) if (RuntimeEntityManifest::SCHEMA === ($declaration['payload']['schema'] ?? null)) RuntimeEntityManifest::resolve($declaration['payload'], $records);
        if (RuntimeEntityManifest::referencedRecords($plan['runtime_declarations'], $records) !== $records) throw new InvalidArgumentException('WordPress site plan carries runtime entity records no manifest references.');
        $runtimeRecords = RuntimeDeclarations::normalizeRecords($plan['runtime_records'] ?? array());
        if ($runtimeRecords !== ($plan['runtime_records'] ?? array())) throw new InvalidArgumentException('WordPress site plan runtime records are not canonically normalized.');
        BindingAnchors::assertAnchored(BindingAnchors::anchoredDeclarations($plan, $runtimeRecords, $records), $plan['pages'], array_merge($plan['template_parts'], $plan['synced_patterns'] ?? array(), $plan['templates']), $plan['assets']);
        if ('declared_tokens_only' !== ($plan['reference_semantics']['static_browser_references'] ?? null) || !in_array($plan['reference_semantics']['dynamic_script_references'] ?? null, array('proven', 'not_proven'), true) || !is_array($plan['reference_semantics']['dynamic_client_assets'] ?? null) || !in_array($plan['reference_semantics']['dynamic_client_assets']['status'] ?? null, array('proven', 'not_proven'), true) || !is_bool($plan['reference_semantics']['dynamic_client_assets']['materializer_may_reject'] ?? null) || ($plan['reference_semantics']['dynamic_script_references'] ?? null) !== ($plan['reference_semantics']['dynamic_client_assets']['status'] ?? null) || ('proven' === $plan['reference_semantics']['dynamic_client_assets']['status'] && true === $plan['reference_semantics']['dynamic_client_assets']['materializer_may_reject'])) throw new InvalidArgumentException('WordPress site plan reference capability semantics are invalid.');
        self::assertRows($plan['routes'], 'route', array('kind', 'source_path', 'target_path', 'target_slug', 'source_relation', 'order'));
        self::assertRows($plan['navigation_links'], 'navigation link', array('kind', 'source_path', 'source_relation', 'order'), array('target_path', 'target_slug'));
        self::assertRows($plan['menus'], 'menu', array('kind', 'source_path', 'target_slug', 'source_relation', 'order', 'items'), array('title', 'block_markup', 'token', 'reconciliation_identity'));
        NavigationEntityProjection::assertReferences($plan);
        SyncedPatternProjection::assertReferences($plan);
        $assetTargets = array();
        $assetTokens = array();
        $assetIdentities = array();
        $assetMimeTypes = array();
        foreach ( $plan['assets'] as $asset ) {
            $assetContent = is_array($asset) ? (is_string($asset['content_base64'] ?? null) ? $asset['content_base64'] : ($asset['content'] ?? null)) : null;
            $reference = is_array($asset['payload_reference'] ?? null) ? $asset['payload_reference'] : null;
            if ( ! is_array($asset) || ! self::safePath($asset['source_path'] ?? null) || ! self::safePath($asset['target_path'] ?? null) || !is_string($asset['source'] ?? null) || !is_string($asset['role'] ?? null) || !is_string($asset['mime_type'] ?? null) || !is_int($asset['bytes'] ?? null) || $asset['bytes'] < 0 || !is_string($asset['token'] ?? null) || !self::hash($asset['reconciliation_identity'] ?? null) || !self::hash($asset['content_hash'] ?? null) || (!is_string($assetContent) && !CompiledAssets::payloadReference($reference)) || $asset['reconciliation_identity'] !== self::identity('asset', $asset['source_path'], $asset['target_path']) || (is_string($assetContent) && $asset['content_hash'] !== self::contentHash($assetContent)) || (is_string($asset['content_base64'] ?? null) && ($asset['transport_sha256'] ?? null) !== $asset['content_hash']) || (is_array($reference) && (!CompiledAssets::referenceBackedBinary($asset) || isset($asset['content'], $asset['content_base64'], $asset['transport_sha256']) || $asset['content_hash'] !== $reference['sha256'] || ($asset['raw_sha256'] ?? null) !== $reference['sha256']) ) ) {
                throw new InvalidArgumentException('WordPress site plan asset is structurally invalid.');
            }
            if ('css' === $asset['kind']) {
                StylesheetScopes::assertValid($asset['scopes'] ?? null);
                if (!in_array($asset['stylesheet_target'] ?? 'both', array('both', 'frontend', 'editor'), true)) throw new InvalidArgumentException('Stylesheet assets must declare a supported target.');
            }
            elseif (isset($asset['scopes'])) throw new InvalidArgumentException('Only stylesheet assets may declare runtime scopes.');
            self::unique($assetTargets, $asset['target_path'], 'asset target', (string) $asset['source_path']);
            self::unique($assetIdentities, $asset['reconciliation_identity'], 'asset reconciliation identity');
            $assetTokens[strtolower($asset['target_path'])] = $asset['token'];
            $assetMimeTypes[$asset['target_path']] = $asset['mime_type'];
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
        foreach ($plan['menus'] as $menu) if (is_string($menu['block_markup'] ?? null)) {
            self::assertTokens($menu['block_markup'], $tokens);
            LocalBrowserReferences::assertNone($menu['block_markup']);
        }
        foreach ($plan['synced_patterns'] ?? array() as $pattern) {
            self::assertTokens($pattern['canonical_block_markup'], $tokens);
            LocalBrowserReferences::assertNone($pattern['canonical_block_markup'], $pattern['source_path']);
        }
        $partSlugs = array();
        $overrideTemplateSlugs = TemplateSlugs::overrides($plan['template_parts'], array_column(array_filter($plan['templates'], static fn(array $template): bool => array_key_exists('shell_frame', $template)), 'slug'));
        foreach ($plan['pages'] as $page) if (isset($page['route_template'])) $overrideTemplateSlugs[$page['route_template']] = true;
        foreach ( $plan['template_parts'] as $part ) {
            PlanDocuments::assertValid($part, 'template part', true, $tokens);
            if ($part['content_hash'] !== self::contentHash($part['canonical_block_markup'])) throw new InvalidArgumentException('WordPress site plan template part has a stale content hash.');
            self::unique($partSlugs, $part['slug'], 'template part slug');
        }
        $pagePaths = array(); $pagesBySource = array(); $documentIdentities = array(); $routePaths = array();
        $entryRoot = PageRoute::entryRoot($plan['pages']);
        foreach ( $plan['pages'] as $page ) {
            PlanDocuments::assertValid($page, 'page', false, $tokens);
            if ($page['content_hash'] !== self::contentHash($page['canonical_block_markup'])) throw new InvalidArgumentException('WordPress site plan page has a stale content hash.');
            RouteTable::assertPageRoute($page, $entryRoot);
            // Route disambiguation is what keeps a collision from costing the
            // whole plan, so the uniqueness it exists to preserve is asserted.
            if (isset($routePaths[$page['route']['path']])) throw new InvalidArgumentException(sprintf('WordPress site plan has colliding page routes: %s and %s both resolve to %s.', $routePaths[$page['route']['path']], (string) $page['source_path'], (string) $page['route']['path']));
            $routePaths[$page['route']['path']] = (string) $page['source_path'];
            self::unique($pagePaths, $page['source_path'], 'page source');
            self::unique($documentIdentities, $page['reconciliation_identity'], 'page reconciliation identity');
            $pagesBySource[$page['source_path']] = $page;
        }
        $pageParents = array_fill_keys(array_column($plan['pages'], 'parent_source_path'), true);
        $pageTemplateKeys = array_fill_keys(array_map(TemplateSlugs::forPage(...), $plan['pages']), true);
        foreach ($plan['pages'] as $page) if (!empty($page['synthetic']) && !isset($pageParents[$page['source_path']])) throw new InvalidArgumentException('WordPress site plan synthetic parent must have a materialized child.');
        ArchiveEntities::assertArchiveSources(array_merge($plan['taxonomy_entities'], $plan['author_entities']), $sourceCatalog, $pagesBySource);
        ArchiveEntities::assertTerms($plan['taxonomy_entities'], $pagesBySource);
        ArchiveEntities::assertAuthors($plan['author_entities'], $pagesBySource);
        $expectedCandidatePages = WholePageCandidates::attach(array_map(static function (array $page): array { unset($page['whole_page_candidates']); return $page; }, $plan['pages']), RuntimeDeclarations::materialize($plan['runtime_declarations'], $runtimeRecords), $records);
        foreach ($plan['pages'] as $index => $page) if (($expectedCandidatePages[$index]['whole_page_candidates'] ?? null) !== ($page['whole_page_candidates'] ?? null)) throw new InvalidArgumentException('Whole-page candidates are stale, duplicated, or detached from their canonical source pages.');
        $documentsBySource = StylesheetScopes::documentsBySource($plan['pages'], $plan['theme']['document_roots'] ?? array());
        StylesheetScopes::assertOwned($plan['assets'], $documentsBySource);
        PageStylesheets::assertInstances($plan['assets'], $documentsBySource);
        $routeSources = array(); foreach ($plan['routes'] as $route) { self::unique($routeSources, $route['source_path'], 'route source'); $page = $pagesBySource[$route['source_path']] ?? null; if (!is_array($page) || $route['target_path'] !== $page['route']['path'] || $route['target_slug'] !== $page['slug']) throw new InvalidArgumentException('WordPress site plan routes do not match canonical page routes.'); }
        if (count($routeSources) !== count($pagePaths)) throw new InvalidArgumentException('WordPress site plan must export every canonical page route.');
        // Source identities compare case-insensitively, as unique() keys them.
        PlanReporting::assertValid($plan['reporting'], array_merge($pagePaths, array_change_key_case(array_fill_keys(array_filter(array_column($plan['templates'], 'source_path'), 'is_string'), true), CASE_LOWER)), $tokens, $plan['diagnostics']);
        PageOperations::assertValid($plan['operations'], $plan['pages']);
        $templateTargets = array();
        foreach ( $plan['templates'] as $template ) {
            $sourcePath = is_array($template) && is_string($template['source_path'] ?? null) ? $template['source_path'] : 'wordpress-site-plan/' . ($template['target_path'] ?? '');
            if ( ! is_array($template) || ! is_string($template['slug'] ?? null) || ! self::safePath($template['target_path'] ?? null) || ! is_string($template['canonical_block_markup'] ?? null) || '' === trim($template['canonical_block_markup']) || !self::hash($template['reconciliation_identity'] ?? null) || !self::hash($template['content_hash'] ?? null) || $template['reconciliation_identity'] !== self::identity('template', $sourcePath, $template['target_path']) || $template['content_hash'] !== self::contentHash($template['canonical_block_markup']) ) {
                throw new InvalidArgumentException('WordPress site plan template is structurally invalid.');
            }
            if (isset($template['template_surface']) && (!is_array($template['template_surface']) || !TemplateSurfaces::valid($template['template_surface'], $template['source_path'] ?? null, $sourceCatalog) || $template['slug'] !== $template['template_surface']['slug'] || !is_string($template['source_path'] ?? null) || !is_array($template['provenance'] ?? null))) throw new InvalidArgumentException('WordPress site plan declared template surface is structurally invalid.');
            if (str_starts_with($template['slug'], 'page-') && !isset($pageTemplateKeys[$template['slug']])) throw new InvalidArgumentException('WordPress site plan page override must resolve to a materialized page.');
            self::unique($templateTargets, $template['target_path'], 'template target');
            self::assertTokens($template['canonical_block_markup'], $tokens);
            LocalBrowserReferences::assertNone($template['canonical_block_markup']);
        }
        ArchiveEntities::assertArchiveTemplates(array_merge($plan['taxonomy_entities'], $plan['author_entities']), $plan['templates']);
        $writeTargets = array();
        $writesByTarget = array();
        foreach ( $plan['writes'] as $write ) {
            $mimeType = is_array($write) ? ($assetMimeTypes[$write['target_path'] ?? ''] ?? null) : null;
            PlanWrites::assertValid($write, $tokens, !isset($plan['resolution']) && (null === $mimeType || in_array($mimeType, array('text/css', 'text/html', 'image/svg+xml'), true)));
            self::unique($writeTargets, $write['target_path'], 'write target', (string) ($write['source_path'] ?? ''));
            $writesByTarget[$write['target_path']] = $write;
        }
        WordPressSitePlanResolver::assertResolved($plan, $tokens, $writesByTarget);
        ThemeScaffold::assertValid($plan, $writesByTarget);
        foreach ( $plan['templates'] as $template ) {
            $write = $writesByTarget[$template['target_path']] ?? null;
            $expected = isset($plan['resolution']) ? WordPressSitePlanResolver::resolveWritePayload($template['canonical_block_markup'], $plan['reference_tokens'], $plan['resolution']['theme_uri'], $template['target_path'], $plan['resolution']['attachment_resolutions'] ?? array()) : $template['canonical_block_markup'];
            if ( ! is_array($write) || 'theme_template' !== ($write['kind'] ?? null) || $write['payload']['data'] !== $expected ) {
                throw new InvalidArgumentException('WordPress site plan template lacks its canonical write.');
            }
        }
        foreach ( $plan['template_parts'] as $part ) {
            $target = 'parts/' . $part['slug'] . '.html';
            $write = $writesByTarget[$target] ?? null;
            $expected = isset($plan['resolution']) ? WordPressSitePlanResolver::resolveWritePayload($part['canonical_block_markup'], $plan['reference_tokens'], $plan['resolution']['theme_uri'], $target, $plan['resolution']['attachment_resolutions'] ?? array()) : $part['canonical_block_markup'];
            if ( ! is_array($write) || 'theme_template_part' !== ($write['kind'] ?? null) || $write['payload']['data'] !== $expected ) {
                throw new InvalidArgumentException('WordPress site plan template part lacks its canonical write.');
            }
            ThemeTemplates::assertPartBound($part, $plan, $overrideTemplateSlugs);
        }
        // Resolution rewrites tokens to URLs, so a resolved plan keeps the
        // unresolved plan's proof for unwritten assets.
        $unreferenced = isset($plan['resolution']) ? array_fill_keys(array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'none' === ($asset['destination'] ?? null)), 'token'), true) : array_merge(AssetConsumers::unreferencedImages($plan) ?? array(), DocumentScripts::unreferencedInline($plan));
        $fontEmbeddingSvgs = AssetConsumers::fontEmbeddingSvgs($plan);
        foreach ( $plan['assets'] as $asset ) {
            $expectedConsumers = $asset['consumers'] ?? array();
            if (($asset['consumers'] ?? null) !== $expectedConsumers || ($asset['destination'] ?? null) !== AssetConsumers::destination($expectedConsumers, $asset, $unreferenced, $fontEmbeddingSvgs) || ($asset['theme_write_reasons'] ?? null) !== AssetConsumers::themeWriteReasons($expectedConsumers, $asset['destination'])) throw new InvalidArgumentException('Asset consumers or destination are stale.');
            ThemeAssetWrites::assertWritten($asset, $writesByTarget);
        }
        AssetPublications::assertDeclarations($plan['runtime_declarations'], $plan['assets'], $writesByTarget);
        if ( ! is_string($plan['theme']['stylesheet'] ?? null) || ! is_string($plan['theme']['theme_json'] ?? null) || (null !== ($plan['theme']['bootstrap'] ?? null) && ! is_string($plan['theme']['bootstrap'])) ) {
            throw new InvalidArgumentException('WordPress site plan theme is structurally invalid.');
        }
        CompiledAssets::assertFontMaterialization($plan['theme'], $plan['assets'], $writesByTarget);
        $policyStatus = 'failed' === ($plan['quality']['status'] ?? null) ? 'failed' : 'passed';
        if ( !in_array($plan['quality']['status'] ?? null, array('success', 'success_with_warnings', 'failed'), true) || !is_bool($plan['quality']['pass'] ?? null) || ('failed' !== $plan['quality']['status']) !== $plan['quality']['pass'] || ! is_array($plan['quality']['metrics'] ?? null) || ! is_array($plan['quality']['fallbacks'] ?? null) || !is_array($plan['quality']['core_html_fallback_evidence'] ?? null) || EditabilityPolicy::SCHEMA !== ($plan['quality']['editability_policy']['schema'] ?? null) || 'required' !== ($plan['quality']['editability_policy']['enforcement'] ?? null) || $policyStatus !== ($plan['quality']['editability_policy']['status'] ?? null) ) {
            throw new InvalidArgumentException('WordPress site plan quality is structurally invalid.');
        }
        // Bound retention for long-lived processes without an eviction index.
        if (count($validated) >= 4096) $validated = array();
        $validated[$contentHash] = true;
    }

    // A dynamic reference is a URL the script builds or assigns: an import, an
    // injected script, a `src` it sets, a URL it constructs. Inserting a node
    // (`appendChild`, `insertBefore`) names no URL: a copy button's scratch
    // textarea or a rendered list item references nothing the write must hold.
    // Each path segment carries the author's words, so the route grammar folds
    // what it cannot spell instead of deleting it: `RouteSlug` applies
    // WordPress's own `remove_accents()` plus `sanitize_title_with_dashes()`
    // rule, which is what keeps distinct pages on distinct routes and keeps the
    // slug readable. See that class for why deleting was non-injective.
    // A source path segment carries the author's page title, so percent sequences
    // are ordinary punctuation a CMS slugified into a filename: `%3A`, `%2C`, and
    // `%E2%80%99` must reach the slugifier as `:`, `,`, and `’` and become part of
    // the author's words. Decoding is a single pass, and that pass must never give
    // the path structure the raw path did not already have: an encoded separator or
    // dot segment would silently change route identity, so those still fail closed
    // on exactly the rules `safePath()` applies to the undecoded path. Anything a
    // single decode leaves behind (a literal `%`, `%ZZ`, a truncated `%E2%80`) is
    // inert punctuation the slugifier strips.
    // The entrypoint document's directory is the site's web root: a `website/`
    // wrapper around `website/index.html` must not become a `/website` route with
    // every other page nested beneath it. Strip that shared root so `index.html`
    // maps to `/` and its siblings map to top-level routes (`/contact`, `/music`).
    // Resolve the site root directory from the entrypoint document/page so route
    // derivation and validation agree on the same web root without shared state.

    /** @param array<string,string> $tokens */
    public static function assertTokens(string $content, array $tokens): void { if (preg_match_all('/\{\{wordpress-site-plan:asset:([^}]+)\}\}/', $content, $matches)) foreach ($matches[1] as $token) if (!isset($tokens[$token])) throw new InvalidArgumentException('WordPress site plan contains an undeclared reference token.'); }
    /** @param array<string,string> $values */
    public static function unique(array &$values, string $value, string $kind, string $source = ''): void
    {
        $key = strtolower($value);
        if (isset($values[$key])) {
            $shown = mb_strcut($value, 0, 120, 'UTF-8');
            $prior = $values[$key];
            $message = '' !== $prior && '' !== $source
                ? sprintf('WordPress site plan has colliding %ss: %s (%s, %s).', $kind, $shown, mb_strcut($prior, 0, 60, 'UTF-8'), mb_strcut($source, 0, 60, 'UTF-8'))
                : sprintf('WordPress site plan has colliding %ss: %s.', $kind, $shown);
            throw new InvalidArgumentException(mb_strcut($message, 0, 256, 'UTF-8'));
        }
        $values[$key] = $source;
    }
    public static function identity(string $kind, string $source, string $target): string { return hash('sha256', "wordpress-site-plan/{$kind}/v2\n{$source}\n{$target}"); }
    public static function contentHash(string $content): string { return hash('sha256', $content); }

    public static function hash(mixed $value): bool { return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value); }
    /** @param array<string,mixed> $source */
    private static function assertSource(array $source): void { if ('blocks-engine/php-transformer/compiled-site/v1' !== ($source['schema'] ?? null) || !is_string($source['source_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/', $source['source_hash']) || !is_string($source['entry_path'] ?? null) || !is_array($source['provenance'] ?? null) || (isset($source['source_documents']) && (!is_array($source['source_documents']) || !array_is_list($source['source_documents']) || count($source['source_documents']) > 5000))) throw new InvalidArgumentException('WordPress site plan source identity is invalid.'); }

    private static function assertRows(array $rows, string $kind, array $fields, array $optional = array()): void { foreach ($rows as $row) { if (!is_array($row)) throw new InvalidArgumentException("WordPress site plan {$kind} must be an array."); foreach ($fields as $field) if (!array_key_exists($field, $row) || (!is_string($row[$field]) && !is_int($row[$field]))) throw new InvalidArgumentException("WordPress site plan {$kind} lacks {$field}."); foreach ($optional as $field) if (array_key_exists($field, $row) && !is_string($row[$field])) throw new InvalidArgumentException("WordPress site plan {$kind} has invalid {$field}."); } }
    /** @param array<string,mixed> $data */
    public static function value(array $data, string $key, string $default = ''): string { return is_string($data[$key] ?? null) ? $data[$key] : $default; }
    public static function explicitUrl(mixed $url): bool { return is_string($url) && (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $url) === 1 || self::routeUrl($url)); }
    private static function routeUrl(mixed $url): bool { return is_string($url) && preg_match('~^/(?:[a-z0-9_-]+(?:/[a-z0-9_-]+)*)?(?:[?#].*)?$~', $url) === 1; }
    public static function safePath(mixed $path): bool { if (!is_string($path) || '' === $path || str_contains($path, "\0") || str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:/', $path)) return false; foreach (explode('/', str_replace('\\', '/', $path)) as $segment) if ('' === $segment || '.' === $segment || '..' === $segment) return false; return true; }
}
