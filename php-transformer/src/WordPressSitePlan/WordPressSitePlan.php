<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityPolicy;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeEntityManifest;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeIslandPackageBuilder;
use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\SrcsetParser;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use Automattic\BlocksEngine\PhpTransformer\Path\RouteSlug;
use Automattic\BlocksEngine\PhpTransformer\Css\CssIdent;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\StaticSite\FontMaterialization\FontMaterializationPlanBuilder;
use InvalidArgumentException;

/** A complete, destination-independent block-theme materialization contract. */
final class WordPressSitePlan
{
    public const SCHEMA = 'blocks-engine/wordpress-site-plan/v2';
    public const IDENTITY_SCHEMA = 'blocks-engine/wordpress-site-plan-identity/v1';
    public const TOKEN_PREFIX = '{{wordpress-site-plan:asset:';
    /** Blocks whose serialized `url` attribute names a route rather than an asset. */
    public const ROUTE_URL_BLOCKS = array('navigation-link', 'navigation-submenu', 'button', 'social-link');
    public const MAX_DOCUMENT_IDENTITY_DIAGNOSTICS = 50;
    /** Generated, document-namespaced class names the projected author CSS selects on. */
    private const GENERATED_CLASS_PATTERN = '/blocks-engine-[a-z-]+-[0-9a-f]{12}-\d+/';
    public const EDITOR_CORE_IMAGE_INTERACTION_CSS = ':root .block-editor-block-list__block.wp-block-image img{pointer-events:auto!important}';
    public const EDITOR_POST_TITLE_INTERACTION_CSS = ':root .editor-post-title{position:relative;z-index:100000;pointer-events:auto!important}';
    public const EDITOR_LINK_INTERACTION_CSS = ':root .editor-styles-wrapper a[href]{pointer-events:none!important}';
    /**
     * A generated theme reproduces captured text, so WordPress typographic
     * rewriting stays off while it is active. Static block content survives
     * texturization only because its punctuation is entity encoded; text that a
     * dynamic block decodes and prints is rewritten, so the guarantee belongs to
     * the theme rather than to any one block.
     */
    public const SOURCE_TEXT_TYPOGRAPHY = "add_filter( 'run_wptexturize', '__return_false' );";
    private string $sourceOrigin = '';
    private string $sourceUrl = '';
    private const MAX_UNRESOLVED_NAVIGATION_DIAGNOSTICS = 50;
    private const MAX_ROUTE_COLLISION_DIAGNOSTICS = 50;
    /** @var array<string,array<string,mixed>> */
    private array $unresolvedNavigationDiagnostics = array();
    private int $omittedUnresolvedNavigationDiagnostics = 0;
    /** @var array<int,array<string,mixed>> */
    private array $routeCollisions = array();
    private int $omittedRouteCollisionDiagnostics = 0;
    /** @var array<string,string> */
    private array $routeSources = array();
    /** @var array<string,string> */
    private array $routeTargets = array();
    /** @var array<string,string|false> */
    private array $routeReferenceCache = array();
    private readonly ShellExtraction $shellExtraction;
    private MissingMediaRecovery $missingMedia;

    /**
     * @param bool $strictMissingMedia Rejects the whole plan when a media
     *        reference names a local file the artifact never packaged, instead
     *        of recovering it as an explicit placeholder and warning.
     */
    public function __construct(private readonly bool $strictMissingMedia = false)
    {
        $this->shellExtraction = new ShellExtraction($this);
        $this->missingMedia = new MissingMediaRecovery($strictMissingMedia);
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
        return array('schema' => self::IDENTITY_SCHEMA, 'hash' => RuntimeDeclarations::hash($canonical));
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
        $this->sourceUrl = $this->sourceUrlFromProvenance($data['provenance'] ?? array());
        $this->sourceOrigin = $this->urlOrigin($this->sourceUrl);
        $this->unresolvedNavigationDiagnostics = array();
        $this->omittedUnresolvedNavigationDiagnostics = 0;
        $editabilityPolicy = $input->editabilityPolicy;
        if (!is_array($editabilityPolicy) || EditabilityPolicy::SCHEMA !== ($editabilityPolicy['schema'] ?? null) || 'required' !== ($editabilityPolicy['enforcement'] ?? null) || !in_array($editabilityPolicy['status'] ?? null, array('passed', 'failed'), true)) {
            throw new InvalidArgumentException('WordPress site plan requires a versioned editability policy.');
        }
        $compiled = $input->compiledSite;
        if ( array() === $compiled ) {
            throw new InvalidArgumentException('WordPress site plan requires a compiled-site report.');
        }
        $identityFailures = self::compiledSiteIdentityFailures($compiled);
        if ( array() !== $identityFailures ) {
            throw new DocumentIdentityException($identityFailures);
        }

        $runtimeDeclarations = $compiled['runtime_declarations'] ?? array();
        $runtimeRecords = RuntimeDeclarations::normalizeRecords($compiled['runtime_records'] ?? array());
        $runtimeDeclarations = RuntimeDeclarations::materialize($runtimeDeclarations, $runtimeRecords);
        $runtimeScriptOwnership = $this->runtimeScriptOwnership($input->runtimeIslandPackage, $compiled['pages'] ?? array(), $runtimeDeclarations);
        $documents = $this->withoutOwnedRuntimeScripts($this->decideDocuments($compiled['pages'] ?? null), $runtimeScriptOwnership['documents']);
        $documentScriptAssets = $this->documentScriptAssets($documents);
        $assets = array_values(array_filter(
            $this->assets($compiled['assets'] ?? null),
            static fn(array $asset): bool => !isset($runtimeScriptOwnership['assets'][(string) ($asset['source_path'] ?? '')]) || isset($documentScriptAssets[(string) ($asset['source_path'] ?? '')])
        ));
        $assets = $this->applyDeclaredAssetTransformations($assets, $runtimeDeclarations);
        $tokens = $this->tokens($assets);
        $surfaces = $this->templateSurfaces($documents);
        $documents = array_values(array_filter($documents, static fn(array $document): bool => !isset($document['template_surface'])));
        $routeMap = $this->canonicalRoutes($documents, $input->routes);
        $this->routeSources = array();
        $this->routeTargets = array();
        $this->routeReferenceCache = array();
        foreach ($routeMap as $route) {
            $sourcePath = (string) ($route['source_path'] ?? '');
            $targetPath = (string) ($route['target_path'] ?? '');
            if ('' !== $sourcePath && '' !== $targetPath) $this->routeSources[$sourcePath] = $targetPath;
            if ('' !== $targetPath) $this->routeTargets['/' === $targetPath ? '/' : '/' . trim($targetPath, '/')] = $targetPath;
        }
        $this->missingMedia = new MissingMediaRecovery($this->strictMissingMedia, array_column($assets, 'target_path'));
        $references = new AssetReferenceCanonicalizer($tokens, self::entryRootFromDocuments($documents), $this->missingMedia);
        $pages = $this->documents($documents, false, $tokens, $references, $routeMap);
        // Restore the semantic shell candidates before deriving binding positions.
        // Extracted parts intentionally contain only their inner markup; the page
        // representation owns the landmark wrapper until extraction is accepted.
        foreach ($pages as &$page) {
            foreach ($page['shell_candidates'] ?? array() as $candidate) {
                $restored = $this->shellExtraction->replaceTopLevelShell($page['canonical_block_markup'], (string) ($candidate['area'] ?? ''), (string) ($candidate['markup'] ?? ''));
                if (null !== $restored) $page['canonical_block_markup'] = $restored;
            }
            $page['content_hash'] = self::contentHash($page['canonical_block_markup']);
        }
        unset($page);
        // Bindings anchor on the final canonical page markup before any shell
        // extraction. Asset and route projection can make source anchors equal,
        // so assign occurrences only after that shared projection is complete.
        $runtimeDeclarations = $this->canonicalEntityBindings($runtimeDeclarations, $references, $routeMap, $pages);
        $factoredRuntimeDeclarations = RuntimeDeclarations::factor($runtimeDeclarations);
        $runtimeDeclarations = $factoredRuntimeDeclarations['declarations'];
        $runtimeRecords = $factoredRuntimeDeclarations['records'];
        $pages = $this->pageHierarchy($pages, $routeMap);
        $assets = $this->scopeAssets($assets, $pages);
        $projector = new ThemeJsonProjection();
        $themeProjection = $projector->project($assets);
        $assets = $themeProjection['assets'];
        $routes = $this->routesForPages($pages);
        // Entry shells remain in compiled-site/v1 for existing consumers; the
        // canonical plan rebuilds them from full page shell candidates.
        $compiledParts = is_array($compiled['template_parts'] ?? null) ? array_values(array_filter($compiled['template_parts'], static fn(mixed $part): bool => !is_array($part) || 'entry_shell' !== ($part['placement']['kind'] ?? null))) : null;
        $existingParts = $this->documents($compiledParts, true, $tokens, $references, $routeMap);
        $reservedPartSlugs = array_fill_keys(array_column($existingParts, 'slug'), true);
        $canonicalInlineParts = $this->documents(is_array($compiled['inline_shell_artifacts'] ?? null) ? $compiled['inline_shell_artifacts'] : array(), true, $tokens, $references, $routeMap);
        $inlineShells = $this->shellExtraction->inlineSharedShells($pages, $reservedPartSlugs, $runtimeDeclarations, $canonicalInlineParts);
        $reservedPartSlugs += array_fill_keys(array_column($inlineShells['parts'], 'slug'), true);
        $shells = $this->shellExtraction->sharedShells($inlineShells['pages'], $reservedPartSlugs, $inlineShells['runtime_declarations']);
        $inlineAreas = array_fill_keys(array_column($inlineShells['parts'], 'area'), true);
        $shells['diagnostics'] = array_values(array_filter($shells['diagnostics'], static fn(array $diagnostic): bool => !isset($inlineAreas[$diagnostic['area'] ?? '']) || 'wordpress_site_plan_shell_retained_incomplete' !== ($diagnostic['code'] ?? null)));
        $pages = $shells['pages'];
        $parts = array_merge($existingParts, $inlineShells['parts'], $shells['parts']);
        $assets = self::projectSharedChromeStylesheets($assets, $parts);
        $tokens = $this->tokens($assets);
        if (array() !== $parts) $themeProjection['theme']['templateParts'] = array_values(array_map(static fn(array $part): array => array('name' => $part['slug'], 'title' => $part['title'], 'area' => $part['area']), $parts));
        $runtimeDeclarations = $shells['runtime_declarations'];
        $runtimeDeclarations = $this->canonicalEntityBindings($runtimeDeclarations, $references, $routeMap, $pages);
        foreach ($pages as &$page) unset($page['_projected_source_block_markup']); unset($page);
        self::assertEntityBindingsRemainPageOwned($runtimeDeclarations, $pages, $assets);
        $templates = $this->templates($pages, $parts, $surfaces, $tokens, $references, $routeMap);
        $operations = $this->operations($pages);
        $scriptLoading = $this->scriptLoading($pages, $parts, $assets, $tokens, $operations, $runtimeDeclarations);
        // Asset payloads are the last canonicalization pass, so the placeholder
        // backing recovered media is declared once every reference is known.
        $assetWrites = $this->assetWrites($assets, $references);
        foreach ($assets as &$asset) unset($asset['reference_origin']); unset($asset);
        $placeholderAssets = $this->assets($this->missingMedia->assets());
        if (array() !== $placeholderAssets) {
            $assets = array_merge($assets, $placeholderAssets);
            $tokens = array_merge($tokens, $this->tokens($placeholderAssets));
            $assetWrites = array_merge($assetWrites, $this->assetWrites($placeholderAssets, $references));
        }
        $writes = array_merge($this->scaffoldWrites($assets, $templates, $parts, $scriptLoading['scripts'], $themeProjection['theme'], $tokens, $pages), $assetWrites);
        $recoveryDiagnostics = array_merge($this->routeCollisionDiagnostics(), $this->unresolvedNavigationDiagnostics(), $this->missingMedia->diagnostics());
        $plan = array(
            'schema' => self::SCHEMA,
            'source' => array('schema' => $compiled['schema'] ?? null, 'source_hash' => $compiled['source_hash'] ?? null, 'entry_path' => $compiled['entry_path'] ?? null, 'provenance' => $data['provenance'], 'source_documents' => $this->sourceDocumentCatalog($compiled['pages'] ?? array())),
            'pages' => $pages,
            'templates' => $templates,
            'template_parts' => $parts,
            'assets' => $assets,
            'reference_tokens' => $tokens,
            'reference_semantics' => array('static_browser_references' => 'declared_tokens_only', 'dynamic_script_references' => array() === $scriptLoading['diagnostics'] ? 'proven' : 'not_proven', 'dynamic_client_assets' => array('status' => array() === $scriptLoading['diagnostics'] ? 'proven' : 'not_proven', 'materializer_may_reject' => array() !== $scriptLoading['diagnostics'])),
            'writes' => $writes,
            'operations' => $operations,
            'routes' => $routes,
            'navigation_links' => $input->navigationLinks,
            'menus' => $input->menus,
            'theme' => array_merge(array('stylesheet' => 'style.css', 'theme_json' => 'theme.json', 'bootstrap' => 'functions.php', 'design_token_provenance' => $themeProjection['provenance']), null !== ($themeProjection['responsive_breakpoints'] ?? null) ? array('responsive_breakpoints' => $themeProjection['responsive_breakpoints']) : array(), array() === $input->fontMaterialization ? array() : array('font_materialization' => $input->fontMaterialization)),
            'visual_repair' => $compiled['visual_repair'] ?? array(),
            'runtime_declarations' => $runtimeDeclarations,
            'runtime_records' => $runtimeRecords,
            'runtime_entity_records' => $compiled['runtime_entity_records'] ?? array(),
            'diagnostics' => array_merge($data['diagnostics'], $inlineShells['diagnostics'], $shells['diagnostics'], $scriptLoading['diagnostics'], $recoveryDiagnostics),
            'quality' => array('status' => $data['status'], 'pass' => 'failed' !== $data['status'], 'metrics' => array_diff_key($data['metrics'], array('transform_duration_ms' => true)), 'fallbacks' => $data['fallbacks'], 'core_html_fallback_evidence' => $input->coreHtmlFallbackEvidence, 'editability_policy' => $editabilityPolicy),
            'reporting' => $this->reporting($pages, $data, $input->coreHtmlFallbackEvidence, array_merge($inlineShells['diagnostics'], $shells['diagnostics'], $scriptLoading['diagnostics'], $recoveryDiagnostics), $surfaces),
        );
        $plan['plan_identity'] = self::planIdentity($plan);
        self::assertValid($plan);
        return $plan;
    }

    /** @param array<int,array<string,mixed>> $declarations @param array<int,array<string,mixed>> $pages @param array<int,array<string,mixed>> $assets */
    private static function assertEntityBindingsRemainPageOwned(array $declarations, array $pages, array $assets): void
    {
        $markupBySource = array();
        foreach ($pages as $page) if (is_string($page['source_path'] ?? null) && is_string($page['canonical_block_markup'] ?? null)) $markupBySource[$page['source_path']] = is_string($page['resolved_block_markup'] ?? null) ? $page['resolved_block_markup'] : $page['canonical_block_markup'];
        $assetsBySource = array_column($assets, null, 'source_path');
        $scriptsBySource = array();
        foreach ( $pages as $page ) foreach ( $page['document_metadata']['scripts'] ?? array() as $script ) if ( is_array($script) && is_string($script['selector'] ?? null) ) $scriptsBySource[$page['source_path'] . "\n" . $script['selector']] = $script;
        foreach ( $declarations as $declaration ) {
            foreach ( $declaration['payload']['entities'] ?? array() as $entity ) {
                $bindings = is_array($entity) && is_array($entity['bindings'] ?? null) ? $entity['bindings'] : array();
                $bindingSources = array_fill_keys(array_filter(array_column($bindings, 'source_path'), 'is_string'), true);
                foreach ( $bindings as $binding ) {
                    $source = $binding['source_path'] ?? null; $search = $binding['search_block_markup'] ?? null; $occurrence = $binding['occurrence'] ?? null;
                    $ownedMarkup = $markupBySource[$source] ?? null;
                    $position = $binding['position'] ?? null;
                    $offset = is_string($ownedMarkup) && is_string($search) && is_int($occurrence) ? self::occurrenceOffset($ownedMarkup, $search, $occurrence) : null;
                    if ( !is_string($source) || !is_string($search) || '' === $search || !is_int($occurrence) || $occurrence < 1 || !is_string($ownedMarkup) || null === $offset || (null !== $position && (!self::bindingPosition($position, $ownedMarkup, $search) || $position['offset'] !== $offset)) ) throw new InvalidArgumentException('A runtime entity binding no longer has its declared source-page block anchor after shell extraction: ' . (is_string($source) ? $source : 'unknown') . ' (' . (is_string($binding['role'] ?? null) ? $binding['role'] : 'unknown') . ').');
                }
                $formId = is_array($entity) && is_array($entity['form'] ?? null) && is_string($entity['form']['id'] ?? null) ? $entity['form']['id'] : '';
                foreach ( is_array($entity) && is_array($entity['superseded_scripts'] ?? null) ? $entity['superseded_scripts'] : array() as $supersession ) {
                    if ( !is_array($supersession) || array('asset_source_path','body_hash','reason','schema','selector','source_path','target_selector') !== array_keys($supersession) || 'blocks-engine/provider-script-supersession/v1' !== $supersession['schema'] || !isset($bindingSources[$supersession['source_path']]) || !preg_match('/^script:nth-of-type\([1-9][0-9]*\)$/', $supersession['selector']) || !self::safePath($supersession['asset_source_path']) || !self::hash($supersession['body_hash']) || '#' . $formId !== $supersession['target_selector'] || 'provider_binding_replaces_form_behavior' !== $supersession['reason'] ) throw new InvalidArgumentException('A provider script supersession proof is malformed or detached from its bound form.');
                    $script = $scriptsBySource[$supersession['source_path'] . "\n" . $supersession['selector']] ?? null;
                    $asset = $assetsBySource[$supersession['asset_source_path']] ?? null;
                    $assetReference = is_array($asset) && is_string($asset['token'] ?? null) ? '{{wordpress-site-plan:asset:' . $asset['token'] . '}}' : null;
                    if ( !is_array($script) || ('inline' !== ($script['source_kind'] ?? null) && $assetReference !== ($script['asset_reference'] ?? null)) || $supersession['body_hash'] !== ($script['body_hash'] ?? null) || $supersession['target_selector'] !== ($script['superseded_by'] ?? null) || !is_array($asset) || 'inline-script' !== ($asset['source'] ?? null) || !is_string($asset['content'] ?? null) || $supersession['body_hash'] !== hash('sha256', trim($asset['content'])) || $supersession['body_hash'] !== ($asset['hash'] ?? null) ) throw new InvalidArgumentException('A provider script supersession proof does not match its source inline-script asset and document metadata.');
                }
            }
        }
    }

    /** @param array<string,mixed> $plan */
    public static function assertValid(array $plan): void
    {
        if ( self::SCHEMA !== ($plan['schema'] ?? null) ) {
            throw new InvalidArgumentException('WordPress site plan has an unsupported schema.');
        }
        foreach ( array('plan_identity', 'source', 'pages', 'templates', 'template_parts', 'assets', 'reference_tokens', 'reference_semantics', 'writes', 'operations', 'routes', 'navigation_links', 'menus', 'theme', 'visual_repair', 'runtime_declarations', 'runtime_entity_records', 'diagnostics', 'quality', 'reporting') as $key ) {
            if ( ! is_array($plan[$key] ?? null) ) {
                throw new InvalidArgumentException(sprintf('WordPress site plan %s must be an array.', $key));
            }
        }
        if ($plan['plan_identity']['schema'] !== self::IDENTITY_SCHEMA || !self::hash($plan['plan_identity']['hash'])) {
            throw new InvalidArgumentException('WordPress site plan identity is missing, malformed, or stale.');
        }
        self::assertSource($plan['source']);
        $sourceCatalog = self::sourceDocumentCatalogFromSource($plan['source']);
        RuntimeDeclarations::assertNormalized($plan['runtime_declarations']);
        $records = RuntimeEntityManifest::normalizeRecords($plan['runtime_entity_records']);
        if ($records !== $plan['runtime_entity_records']) throw new InvalidArgumentException('WordPress site plan runtime entity records are not canonically normalized.');
        foreach ($plan['runtime_declarations'] as $declaration) if (RuntimeEntityManifest::SCHEMA === ($declaration['payload']['schema'] ?? null)) RuntimeEntityManifest::resolve($declaration['payload'], $records);
        self::assertEntityBindingsRemainPageOwned($plan['runtime_declarations'], $plan['pages'], $plan['assets']);
        if ('declared_tokens_only' !== ($plan['reference_semantics']['static_browser_references'] ?? null) || !in_array($plan['reference_semantics']['dynamic_script_references'] ?? null, array('proven', 'not_proven'), true) || !is_array($plan['reference_semantics']['dynamic_client_assets'] ?? null) || !in_array($plan['reference_semantics']['dynamic_client_assets']['status'] ?? null, array('proven', 'not_proven'), true) || !is_bool($plan['reference_semantics']['dynamic_client_assets']['materializer_may_reject'] ?? null) || ($plan['reference_semantics']['dynamic_script_references'] ?? null) !== ($plan['reference_semantics']['dynamic_client_assets']['status'] ?? null) || ('proven' === $plan['reference_semantics']['dynamic_client_assets']['status'] && true === $plan['reference_semantics']['dynamic_client_assets']['materializer_may_reject'])) throw new InvalidArgumentException('WordPress site plan reference capability semantics are invalid.');
        self::assertRows($plan['routes'], 'route', array('kind', 'source_path', 'target_path', 'target_slug', 'source_relation', 'order'));
        self::assertRows($plan['navigation_links'], 'navigation link', array('kind', 'source_path', 'source_relation', 'order'), array('target_path', 'target_slug'));
        self::assertRows($plan['menus'], 'menu', array('kind', 'source_path', 'target_slug', 'source_relation', 'order', 'items'));
        $assetTargets = array();
        $assetTokens = array();
        $assetIdentities = array();
        $assetMimeTypes = array();
        foreach ( $plan['assets'] as $asset ) {
            $assetContent = is_array($asset) ? (is_string($asset['content_base64'] ?? null) ? $asset['content_base64'] : ($asset['content'] ?? null)) : null;
            $reference = is_array($asset['payload_reference'] ?? null) ? $asset['payload_reference'] : null;
            if ( ! is_array($asset) || ! self::safePath($asset['source_path'] ?? null) || ! self::safePath($asset['target_path'] ?? null) || !is_string($asset['source'] ?? null) || !is_string($asset['role'] ?? null) || !is_string($asset['mime_type'] ?? null) || !is_int($asset['bytes'] ?? null) || $asset['bytes'] < 0 || !is_string($asset['token'] ?? null) || !self::hash($asset['reconciliation_identity'] ?? null) || !self::hash($asset['content_hash'] ?? null) || (!is_string($assetContent) && !self::payloadReference($reference)) || $asset['reconciliation_identity'] !== self::identity('asset', $asset['source_path'], $asset['target_path']) || (is_string($assetContent) && $asset['content_hash'] !== self::contentHash($assetContent)) || (is_string($asset['content_base64'] ?? null) && ($asset['transport_sha256'] ?? null) !== $asset['content_hash']) || (is_array($reference) && (!self::referenceBackedBinaryAsset($asset) || isset($asset['content'], $asset['content_base64'], $asset['transport_sha256']) || $asset['content_hash'] !== $reference['sha256'] || ($asset['raw_sha256'] ?? null) !== $reference['sha256']) ) ) {
                throw new InvalidArgumentException('WordPress site plan asset is structurally invalid.');
            }
            if ('css' === $asset['kind']) {
                self::assertAssetScopes($asset['scopes'] ?? null);
                if (!in_array($asset['stylesheet_target'] ?? 'both', array('both', 'frontend', 'editor'), true)) throw new InvalidArgumentException('Stylesheet assets must declare a supported target.');
            }
            elseif (isset($asset['scopes'])) throw new InvalidArgumentException('Only stylesheet assets may declare runtime scopes.');
            self::unique($assetTargets, $asset['target_path'], 'asset target');
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
        $partSlugs = array();
        $overrideTemplateSlugs = array();
        foreach ($plan['template_parts'] as $part) foreach ($part['placement']['excluded_template_slugs'] ?? array() as $slug) if (is_string($slug)) $overrideTemplateSlugs[$slug] = true;
        foreach ( $plan['template_parts'] as $part ) {
            self::assertDocument($part, 'template part', true, $tokens);
            if ($part['content_hash'] !== self::contentHash($part['canonical_block_markup'])) throw new InvalidArgumentException('WordPress site plan template part has a stale content hash.');
            self::unique($partSlugs, $part['slug'], 'template part slug');
        }
        $pagePaths = array(); $pagesBySource = array(); $documentIdentities = array(); $routePaths = array();
        $entryRoot = self::entryRootFromDocuments($plan['pages']);
        foreach ( $plan['pages'] as $page ) {
            self::assertDocument($page, 'page', false, $tokens);
            if ($page['content_hash'] !== self::contentHash($page['canonical_block_markup'])) throw new InvalidArgumentException('WordPress site plan page has a stale content hash.');
            self::assertRoute($page, $entryRoot);
            // Route disambiguation is what keeps a collision from costing the
            // whole plan, so the uniqueness it exists to preserve is asserted.
            if (isset($routePaths[$page['route']['path']])) throw new InvalidArgumentException(sprintf('WordPress site plan has colliding page routes: %s and %s both resolve to %s.', $routePaths[$page['route']['path']], (string) $page['source_path'], (string) $page['route']['path']));
            $routePaths[$page['route']['path']] = (string) $page['source_path'];
            self::unique($pagePaths, $page['source_path'], 'page source');
            self::unique($documentIdentities, $page['reconciliation_identity'], 'page reconciliation identity');
            $pagesBySource[$page['source_path']] = $page;
        }
        foreach ($plan['assets'] as $asset) foreach ($asset['scopes'] ?? array() as $scope) if ('global' !== $scope['kind']) {
            $page = $pagesBySource[$scope['source_path']] ?? null;
            if (!is_array($page) || $scope['kind'] !== ('post' === $page['post_type'] ? 'post' : 'page') || $scope['route_path'] !== trim($page['route']['path'], '/') || $scope['reconciliation_identity'] !== $page['reconciliation_identity'] || $scope['front_page'] !== ('/' === $page['route']['path'])) throw new InvalidArgumentException('A page asset scope does not match its canonical page.');
        }
        $routeSources = array(); foreach ($plan['routes'] as $route) { self::unique($routeSources, $route['source_path'], 'route source'); $page = $pagesBySource[$route['source_path']] ?? null; if (!is_array($page) || $route['target_path'] !== $page['route']['path'] || $route['target_slug'] !== $page['slug']) throw new InvalidArgumentException('WordPress site plan routes do not match canonical page routes.'); }
        if (count($routeSources) !== count($pagePaths)) throw new InvalidArgumentException('WordPress site plan must export every canonical page route.');
        self::assertReporting($plan['reporting'], array_merge($pagePaths, array_fill_keys(array_filter(array_column($plan['templates'], 'source_path'), 'is_string'), true)), $tokens, $plan['diagnostics']);
        self::assertOperations($plan['operations'], $plan['pages']);
        $templateTargets = array();
        foreach ( $plan['templates'] as $template ) {
            $sourcePath = is_array($template) && is_string($template['source_path'] ?? null) ? $template['source_path'] : 'wordpress-site-plan/' . ($template['target_path'] ?? '');
            if ( ! is_array($template) || ! is_string($template['slug'] ?? null) || ! self::safePath($template['target_path'] ?? null) || ! is_string($template['canonical_block_markup'] ?? null) || '' === trim($template['canonical_block_markup']) || !self::hash($template['reconciliation_identity'] ?? null) || !self::hash($template['content_hash'] ?? null) || $template['reconciliation_identity'] !== self::identity('template', $sourcePath, $template['target_path']) || $template['content_hash'] !== self::contentHash($template['canonical_block_markup']) ) {
                throw new InvalidArgumentException('WordPress site plan template is structurally invalid.');
            }
            if (isset($template['template_surface']) && (!is_array($template['template_surface']) || !self::validTemplateSurface($template['template_surface'], $template['source_path'] ?? null, $sourceCatalog) || $template['slug'] !== $template['template_surface']['slug'] || !is_string($template['source_path'] ?? null) || !is_array($template['provenance'] ?? null))) throw new InvalidArgumentException('WordPress site plan declared template surface is structurally invalid.');
            self::unique($templateTargets, $template['target_path'], 'template target');
            self::assertTokens($template['canonical_block_markup'], $tokens);
            self::assertNoLocalBrowserReferences($template['canonical_block_markup']);
        }
        $writeTargets = array();
        $writesByTarget = array();
        foreach ( $plan['writes'] as $write ) {
            $mimeType = is_array($write) ? ($assetMimeTypes[$write['target_path'] ?? ''] ?? null) : null;
            self::assertWrite($write, $tokens, !isset($plan['resolution']) && (null === $mimeType || in_array($mimeType, array('text/css', 'text/html', 'image/svg+xml'), true)));
            self::unique($writeTargets, $write['target_path'], 'write target');
            $writesByTarget[$write['target_path']] = $write;
        }
        self::assertResolution($plan, $tokens, $writesByTarget);
        self::assertScaffold($plan, $writesByTarget);
        foreach ( $plan['templates'] as $template ) {
            $write = $writesByTarget[$template['target_path']] ?? null;
            $expected = $template['canonical_block_markup'];
            if ( ! is_array($write) || 'theme_template' !== ($write['kind'] ?? null) || $write['payload']['data'] !== $expected ) {
                throw new InvalidArgumentException('WordPress site plan template lacks its canonical write.');
            }
        }
        foreach ( $plan['template_parts'] as $part ) {
            $target = 'parts/' . $part['slug'] . '.html';
            $write = $writesByTarget[$target] ?? null;
            $expected = $part['canonical_block_markup'];
            if ( ! is_array($write) || 'theme_template_part' !== ($write['kind'] ?? null) || $write['payload']['data'] !== $expected ) {
                throw new InvalidArgumentException('WordPress site plan template part lacks its canonical write.');
            }
            $boundTemplates = in_array($part['placement']['kind'] ?? null, array('entry_shell', 'shared_shell'), true) ? $part['placement']['template_slugs'] : array();
            foreach (array_keys($overrideTemplateSlugs) as $slug) if (!in_array($slug, $part['placement']['excluded_template_slugs'] ?? array(), true)) $boundTemplates[] = $slug;
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
            $write = $writesByTarget[$target];
            $assetReference = self::payloadReference($asset['payload_reference'] ?? null);
            $writeReference = self::payloadReference($write['payload']['reference'] ?? null);
            if ((null !== $assetReference) !== ('reference' === ($write['payload']['encoding'] ?? null)) || (null !== $assetReference && (null === $writeReference || RuntimeDeclarations::canonicalJson($assetReference) !== RuntimeDeclarations::canonicalJson($writeReference) || ($write['raw_sha256'] ?? null) !== $asset['raw_sha256']))) {
                throw new InvalidArgumentException('WordPress site plan reference asset and write do not match.');
            }
        }
        self::assertAssetPublicationDeclarations($plan['runtime_declarations'], $plan['assets'], $writesByTarget);
        if ( ! is_string($plan['theme']['stylesheet'] ?? null) || ! is_string($plan['theme']['theme_json'] ?? null) || (null !== ($plan['theme']['bootstrap'] ?? null) && ! is_string($plan['theme']['bootstrap'])) ) {
            throw new InvalidArgumentException('WordPress site plan theme is structurally invalid.');
        }
        self::assertFontMaterialization($plan['theme'], $plan['assets'], $writesByTarget);
        $policyStatus = 'failed' === ($plan['quality']['status'] ?? null) ? 'failed' : 'passed';
        if ( !in_array($plan['quality']['status'] ?? null, array('success', 'success_with_warnings', 'failed'), true) || !is_bool($plan['quality']['pass'] ?? null) || ('failed' !== $plan['quality']['status']) !== $plan['quality']['pass'] || ! is_array($plan['quality']['metrics'] ?? null) || ! is_array($plan['quality']['fallbacks'] ?? null) || !is_array($plan['quality']['core_html_fallback_evidence'] ?? null) || EditabilityPolicy::SCHEMA !== ($plan['quality']['editability_policy']['schema'] ?? null) || 'required' !== ($plan['quality']['editability_policy']['enforcement'] ?? null) || $policyStatus !== ($plan['quality']['editability_policy']['status'] ?? null) ) {
            throw new InvalidArgumentException('WordPress site plan quality is structurally invalid.');
        }
    }

    /** @param array<string,mixed> $theme @param array<int,array<string,mixed>> $assets @param array<string,array<string,mixed>> $writesByTarget */
    private static function assertFontMaterialization(array $theme, array $assets, array $writesByTarget): void
    {
        if (!array_key_exists('font_materialization', $theme)) return;
        $fontMaterialization = $theme['font_materialization'];
        if (!is_array($fontMaterialization)) throw new InvalidArgumentException('WordPress site plan font materialization is structurally invalid.');
        $assetsBySource = array_column($assets, null, 'source_path');
        $fontAssets = array();
        foreach ($assets as $asset) {
            $targetPath = (string) ($asset['target_path'] ?? '');
            $fontAssets[] = array('source' => $asset['source'] ?? null, 'path' => $asset['source_path'] ?? null, 'target_path' => str_starts_with($targetPath, 'assets/') ? substr($targetPath, 7) : $targetPath, 'mime_type' => $asset['mime_type'] ?? null, 'content' => $asset['content'] ?? null);
        }
        foreach (($fontMaterialization['webfont_contract']['svg_consumers'] ?? array()) as $consumer) {
            $asset = is_array($consumer) ? ($assetsBySource[$consumer['source_path'] ?? ''] ?? null) : null;
            $targetPath = is_array($asset) ? (string) ($asset['target_path'] ?? '') : '';
            $writePath = str_starts_with($targetPath, 'assets/') ? substr($targetPath, 7) : $targetPath;
            if (!is_array($asset) || $writePath !== ($consumer['write_path'] ?? null) || !isset($writesByTarget[$targetPath])) throw new InvalidArgumentException('WordPress site plan webfont SVG consumer is detached from canonical assets or writes.');
        }
        FontMaterializationPlanBuilder::assertPlan($fontMaterialization, $fontAssets);
    }

    /**
     * Collect every compiled page or template part that cannot become a site-plan document.
     *
     * @param array<string,mixed> $compiledSite
     * @return array<int,array{source_path:string,reason:string,document_kind:string}>
     */
    public static function compiledSiteIdentityFailures(array $compiledSite): array
    {
        $pages = is_array($compiledSite['pages'] ?? null) ? $compiledSite['pages'] : array();
        $parts = is_array($compiledSite['template_parts'] ?? null) ? $compiledSite['template_parts'] : array();
        $parts = array_values(array_filter($parts, static fn (mixed $part): bool => is_array($part) && 'entry_shell' !== ($part['placement']['kind'] ?? null)));

        return array_merge(
            self::documentIdentityFailures($pages, 'page'),
            self::documentIdentityFailures($parts, 'template_part')
        );
    }

    /**
     * @param mixed $documents
     * @return array<int,array{source_path:string,reason:string,document_kind:string}>
     */
    public static function documentIdentityFailures(mixed $documents, string $documentKind): array
    {
        if ( ! is_array($documents) ) {
            return array();
        }
        $failures = array();
        foreach ( $documents as $document ) {
            if ( ! is_array($document) || ! self::safePath($document['source_path'] ?? null) ) {
                $failures[] = array(
                    'source_path' => is_array($document) && is_string($document['source_path'] ?? null) ? $document['source_path'] : '',
                    'reason' => 'unsafe_identity',
                    'document_kind' => $documentKind,
                );
                continue;
            }
            if ( ! is_string($document['block_markup'] ?? null) || '' === trim($document['block_markup']) ) {
                $failures[] = array(
                    'source_path' => $document['source_path'],
                    'reason' => 'empty_block_markup',
                    'document_kind' => $documentKind,
                );
            }
        }

        return $failures;
    }

    /**
     * @param array<int,array{source_path:string,reason:string,document_kind:string}> $failures
     * @return array<int,array<string,mixed>>
     */
    public static function documentIdentityDiagnostics(array $failures): array
    {
        $total = count($failures);
        if ( 0 === $total ) {
            return array();
        }
        $retained = array_slice($failures, 0, self::MAX_DOCUMENT_IDENTITY_DIAGNOSTICS);
        $diagnostics = array();
        foreach ( $retained as $failure ) {
            $diagnostics[] = self::documentIdentityDiagnostic($failure, $total);
        }
        if ( $total > count($retained) ) {
            $diagnostics[] = array(
                'code' => 'wordpress_site_plan_not_self_contained',
                'severity' => 'error',
                'message' => sprintf('%d compiled site documents lack a safe identity or block markup; %d omitted from this diagnostic list.', $total, $total - count($retained)),
                'reason' => 'truncated',
                'document_count' => $total,
                'omitted_count' => $total - count($retained),
                'reason_code' => 'wordpress_site_plan_not_self_contained',
                'pattern_family' => 'site_plan_document',
                'repair_bucket' => 'restore_compiled_document_identity',
            );
        }

        return $diagnostics;
    }

    /**
     * @param array{source_path:string,reason:string,document_kind:string} $failure
     * @return array<string,mixed>
     */
    private static function documentIdentityDiagnostic(array $failure, int $documentCount): array
    {
        $path = substr($failure['source_path'], 0, 256);
        $kind = 'template_part' === $failure['document_kind'] ? 'template part' : 'page';
        $named = '' === $path ? 'Compiled ' . $kind : 'Compiled ' . $kind . ' "' . $path . '"';
        $message = 'unsafe_identity' === $failure['reason']
            ? $named . ' lacks a safe source path.'
            : $named . ' has empty block markup.';

        return array(
            'code' => 'wordpress_site_plan_not_self_contained',
            'severity' => 'error',
            'message' => substr($message, 0, 256),
            'source_path' => $path,
            'document_kind' => substr($failure['document_kind'], 0, 64),
            'reason' => substr($failure['reason'], 0, 64),
            'document_count' => $documentCount,
            'reason_code' => 'wordpress_site_plan_not_self_contained',
            'pattern_family' => 'site_plan_document',
            'repair_bucket' => 'restore_compiled_document_identity',
        );
    }

    /** @param mixed $documents @param array<int,array<string,string>> $tokens @return array<int,array<string,mixed>> */
    private function documents(mixed $documents, bool $part, array $tokens, AssetReferenceCanonicalizer $references, array $routes): array
    {
        if ( ! is_array($documents) ) {
            throw new InvalidArgumentException('Compiled site documents must be an array.');
        }
        $failures = self::documentIdentityFailures($documents, $part ? 'template_part' : 'page');
        if ( array() !== $failures ) {
            throw new DocumentIdentityException($failures);
        }
        $rows = array();
        foreach ( $documents as $document ) {
            $markup = $references->content($document['block_markup'], $document['source_path']);
            $canonical = $this->routeLinks($markup, $document['source_path'], $routes);
            $target = $part ? 'parts/' . self::value($document, 'slug') . '.html' : self::value($document, 'source_path');
            $area = $part ? self::value($document, 'area', 'uncategorized') : null;
            $row = array('source_path' => $document['source_path'], 'slug' => self::value($document, 'slug'), 'title' => self::value($document, 'title'), 'post_type' => self::value((array) ($document['metadata'] ?? array()), 'post_type', 'page'), 'parent_source_path' => self::value((array) ($document['metadata'] ?? array()), 'parent_source_path'), 'entrypoint' => ! empty($document['entrypoint']), 'area' => $area, 'tag_name' => $part ? self::value($document, 'tag_name', ShellLandmarkPolicy::templatePartAreaTagName((string) $area)) : null, 'placement' => $part && is_array($document['placement'] ?? null) ? $document['placement'] : ($part ? array('kind' => 'unbound') : null), 'canonical_block_markup' => $canonical, '_projected_source_block_markup' => $document['block_markup'], 'metadata' => is_array($document['metadata'] ?? null) ? $document['metadata'] : array(), 'document_metadata' => $this->documentMetadata($document, $references, $routes), 'provenance' => is_array($document['provenance'] ?? null) ? $document['provenance'] : array(), 'reconciliation_identity' => self::identity($part ? 'template-part' : 'page', $document['source_path'], $target), 'content_hash' => self::contentHash($canonical));
            if (!$part && is_array($document['content_decision'] ?? null)) {
                $row['content_decision'] = $document['content_decision'];
                if (is_string($document['publication_timestamp'] ?? null)) $row['publication_timestamp'] = $document['publication_timestamp'];
            }
            if ( ! $part ) $row['shell_candidates'] = $this->shellExtraction->shellCandidates($document, $references, $routes, $canonical);
            $rows[] = $row;
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
            if ( ! is_string($asset['content'] ?? null) && ! is_string($asset['content_base64'] ?? null) && !self::payloadReference($asset['payload_reference'] ?? null) ) continue;
            $compiledTarget = $asset['target_path'] ?? $asset['path'];
            if ( ! self::safePath($compiledTarget) ) throw new InvalidArgumentException('Compiled site asset lacks a safe target identity.');
            $target = 'assets/' . str_replace('\\', '/', $compiledTarget);
            if ( ! self::safePath($target) ) throw new InvalidArgumentException('Compiled site asset lacks a safe target identity.');
            $payload = is_string($asset['content_base64'] ?? null) ? $asset['content_base64'] : (string) ($asset['content'] ?? '');
            $reference = self::payloadReference($asset['payload_reference'] ?? null);
            if (null !== $reference && !self::referenceBackedBinaryAsset($asset)) throw new InvalidArgumentException('WordPress site plan payload references are limited to non-SVG binary assets.');
            $transportHash = is_string($asset['content_base64'] ?? null) ? self::contentHash($asset['content_base64']) : null;
            $rows[] = array_filter(array('source_path' => $asset['path'], 'target_path' => $target, 'token' => 'asset-' . substr(hash('sha256', $target), 0, 16), 'source' => self::value($asset, 'source'), 'source_role' => self::value($asset, 'source_role'), 'pipeline_sanitized' => $asset['pipeline_sanitized'] ?? null, 'kind' => self::value($asset, 'kind'), 'role' => self::value($asset, 'role'), 'stylesheet_placement' => self::value($asset, 'stylesheet_placement'), 'stylesheet_target' => 'css' === ($asset['kind'] ?? '') ? (self::value($asset, 'stylesheet_target') ?? 'both') : null, 'intent' => self::value($asset, 'intent'), 'mime_type' => self::value($asset, 'mime_type'), 'media' => self::value($asset, 'media'), 'placement' => self::value($asset, 'placement'), 'defer' => !empty($asset['defer']) ? true : null, 'async' => !empty($asset['async']) ? true : null, 'selector' => self::value($asset, 'selector'), 'references' => is_array($asset['references'] ?? null) ? $asset['references'] : null, 'bytes' => (int) ($asset['bytes'] ?? 0), 'hash' => self::value($asset, 'hash'), 'content' => $asset['content'] ?? null, 'content_base64' => $asset['content_base64'] ?? null, 'payload_reference' => $reference, 'raw_sha256' => $reference['sha256'] ?? ($asset['raw_sha256'] ?? null), 'transport_sha256' => $transportHash, 'binary' => ! empty($asset['binary']), 'compilation' => is_array($asset['compilation'] ?? null) ? $asset['compilation'] : null, 'reconciliation_identity' => self::identity('asset', $asset['path'], $target), 'content_hash' => $reference['sha256'] ?? self::contentHash($payload)), static fn(mixed $value): bool => null !== $value);
        }
        return $rows;
    }

    /** @param array<int,array<string,mixed>> $assets @param array<int,array<string,mixed>> $pages @return array<int,array<string,mixed>> */
    private function scopeAssets(array $assets, array $pages): array
    {
        $pagesBySource = array_column($pages, null, 'source_path');
        foreach ($assets as &$asset) {
            $compilation = $asset['compilation'] ?? null;
            unset($asset['compilation']);
            if ('css' !== $asset['kind']) continue;
            if ('shared' === ($compilation['scope'] ?? null)) {
                $asset['scopes'] = array(array('kind' => 'global'));
                continue;
            }
            if (null === $compilation) {
                $referencedPages = array();
                foreach (is_array($asset['references'] ?? null) ? $asset['references'] : array() as $reference) {
                    $sourcePath = is_array($reference) && 'link' === ($reference['element'] ?? null) ? ($reference['source_path'] ?? null) : null;
                    if (is_string($sourcePath) && is_array($pagesBySource[$sourcePath] ?? null)) $referencedPages[$sourcePath] = $pagesBySource[$sourcePath];
                }
                if (array() === $referencedPages) {
                    $asset['scopes'] = array(array('kind' => 'global'));
                    continue;
                }
                $asset['scopes'] = array_values(array_map(static fn(array $page): array => array('kind' => 'post' === $page['post_type'] ? 'post' : 'page', 'source_path' => $page['source_path'], 'route_path' => trim($page['route']['path'], '/'), 'reconciliation_identity' => $page['reconciliation_identity'], 'front_page' => '/' === $page['route']['path']), $referencedPages));
                continue;
            }
            if (!is_array($compilation) || 'page' !== ($compilation['scope'] ?? null) || !is_string($compilation['id'] ?? null) || !is_array($pagesBySource[$compilation['id']] ?? null)) {
                throw new InvalidArgumentException('A page-owned compiled asset must resolve to a canonical page.');
            }
            $page = $pagesBySource[$compilation['id']];
            $asset['scopes'] = array(array('kind' => 'post' === $page['post_type'] ? 'post' : 'page', 'source_path' => $page['source_path'], 'route_path' => trim($page['route']['path'], '/'), 'reconciliation_identity' => $page['reconciliation_identity'], 'front_page' => '/' === $page['route']['path']));
        }
        unset($asset);
        return $assets;
    }

    /**
     * Extract only generated rules needed by shared template parts. The source
     * stylesheet can contain both those projected rules and route-owned rules;
     * promoting the whole payload changes the cascade on unrelated pages.
     *
     * @param array<int,array<string,mixed>> $assets
     * @param array<int,array<string,mixed>> $parts
     * @return array<int,array<string,mixed>>
     */
    private static function projectSharedChromeStylesheets(array $assets, array $parts): array
    {
        $classes = array();
        foreach ($parts as $part) {
            if (!in_array($part['placement']['kind'] ?? '', array('shared_shell', 'inline_shared_shell'), true)) continue;
            if (!preg_match_all(self::GENERATED_CLASS_PATTERN, (string) ($part['canonical_block_markup'] ?? ''), $matches)) continue;
            foreach ($matches[0] as $class) $classes[$class] = true;
        }
        if (array() === $classes) return $assets;

        $projected = array();
        foreach ($assets as $asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null) || '' === trim($asset['content'])) {
                $projected[] = $asset;
                continue;
            }
            $matched = false;
            $unparseable = false;
            $shared = (new CssStylesheetTransformer())->transformStyleRules(
                $asset['content'],
                static function (string $prelude, string $body) use ($classes, &$matched, &$unparseable): string {
                    $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
                    if (null === $selectors) {
                        $unparseable = true;
                        return '';
                    }
                    $kept = array_values(array_filter($selectors, static function (string $selector) use ($classes): bool {
                        foreach (array_keys($classes) as $class) if (1 === preg_match('/' . CssIdent::classSelectorRegex($class) . '(?![\\w-])/', $selector)) return true;
                        return false;
                    }));
                    if (array() === $kept) return '';
                    $matched = true;
                    return implode(',', $kept) . '{' . $body . '}';
                }
            );
            if (!$matched || $unparseable) {
                $projected[] = $asset;
                continue;
            }
            $route = (new CssStylesheetTransformer())->transformStyleRules(
                $asset['content'],
                static function (string $prelude, string $body) use ($classes): string {
                    $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
                    if (null === $selectors) return $prelude . '{' . $body . '}';
                    $kept = array_values(array_filter($selectors, static function (string $selector) use ($classes): bool {
                        foreach (array_keys($classes) as $class) if (1 === preg_match('/' . CssIdent::classSelectorRegex($class) . '(?![\\w-])/', $selector)) return false;
                        return true;
                    }));
                    return array() === $kept ? '' : implode(',', $kept) . '{' . $body . '}';
                }
            );
            $sharedAsset = $asset;
            $sharedAsset['path'] = 'assets/css/shared-chrome-' . substr(hash('sha256', $shared), 0, 16) . '.css';
            $sharedAsset['target_path'] = $sharedAsset['path'];
            $sharedAsset['source_path'] = (string) ($asset['source_path'] ?? $asset['path'] ?? '') . '.shared-chrome';
            // Relative url() references still resolve against the stylesheet the
            // rules were projected from, not the synthetic shared-chrome identity.
            $sharedAsset['reference_origin'] = (string) ($asset['source_path'] ?? $asset['path'] ?? '');
            $sharedAsset['content'] = $shared;
            $sharedAsset['bytes'] = strlen($shared);
            $sharedAsset['hash'] = hash('sha256', $shared);
            $sharedAsset['content_hash'] = $sharedAsset['hash'];
            $sharedAsset['scopes'] = array(array('kind' => 'global'));
            $sharedAsset['token'] = 'asset-' . substr(hash('sha256', $sharedAsset['target_path']), 0, 16);
            $sharedAsset['reconciliation_identity'] = self::identity('asset', $sharedAsset['source_path'], $sharedAsset['target_path']);
            unset($sharedAsset['content_base64']);
            $asset['content'] = $route;
            $asset['bytes'] = strlen($route);
            $asset['hash'] = hash('sha256', $route);
            $asset['content_hash'] = $asset['hash'];
            unset($asset['content_base64']);
            $projected[] = $asset;
            $projected[] = $sharedAsset;
        }
        return $projected;
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
    private function documentMetadata(array $document, AssetReferenceCanonicalizer $references, array $routes): array
    {
        $metadata = is_array($document['document_metadata'] ?? null) ? $document['document_metadata'] : array('source_context' => array('source_path' => self::value($document, 'source_path'), 'kind' => 'document'), 'title' => self::value($document, 'title'), 'title_declaration' => array('order' => 0, 'placement' => 'head'), 'meta' => array(), 'links' => array(), 'scripts' => array());
        foreach (array('links', 'scripts') as $kind) {
            if (!is_array($metadata[$kind] ?? null)) $metadata[$kind] = array();
            foreach ($metadata[$kind] as &$row) {
                if (!is_array($row) || !is_string($row['url'] ?? null)) continue;
                $reference = $this->documentAssetReference($row['url'], self::value($document, 'source_path'), $references, $routes);
                if (null !== $reference) {
                    $row['asset_reference'] = $reference;
                    unset($row['url']);
                    continue;
                }
                if ('links' !== $kind) continue;
                $route = $this->routeReference($row['url'], self::value($document, 'source_path'), $routes);
                if (null !== $route) $row['url'] = $route;
                elseif ($this->isOptionalFeedLink($row) || $this->isOptionalResourceHint($row) || $this->isOptionalManifestLink($row)) $row = null;
            }
            unset($row);
            $metadata[$kind] = array_values(array_filter($metadata[$kind], static fn(mixed $row): bool => is_array($row)));
            foreach ($metadata[$kind] as $index => &$row) $row['order'] = $index;
            unset($row);
        }
        return $metadata;
    }

    /** @param array<string,mixed> $link */
    private function isOptionalFeedLink(array $link): bool
    {
        $relations = preg_split('/\s+/', strtolower(trim((string) ($link['rel'] ?? '')))) ?: array();
        return !self::explicitUrl($link['url'] ?? null) && in_array('alternate', $relations, true) && in_array(strtolower(trim((string) ($link['type'] ?? ''))), array('application/atom+xml', 'application/feed+json', 'application/rss+xml'), true);
    }
    /** @param array<string,mixed> $link */
    private function isOptionalResourceHint(array $link): bool
    {
        $relations = preg_split('/\s+/', strtolower(trim((string) ($link['rel'] ?? '')))) ?: array();
        $resourceHints = array('dns-prefetch', 'modulepreload', 'preconnect', 'prefetch', 'preload', 'prerender');
        return !self::explicitUrl($link['url'] ?? null) && array() !== $relations && array() === array_diff($relations, $resourceHints);
    }
    /** @param array<string,mixed> $link */
    private function isOptionalManifestLink(array $link): bool
    {
        $relations = preg_split('/\s+/', strtolower(trim((string) ($link['rel'] ?? '')))) ?: array();
        return !self::explicitUrl($link['url'] ?? null) && array('manifest') === $relations;
    }
    /** @param array<int,array<string,mixed>> $routes */
    private function documentAssetReference(string $url, string $sourcePath, AssetReferenceCanonicalizer $references, array $routes): ?string
    {
        $reference = $references->reference($url, $sourcePath);
        if (null !== $reference) return $reference;
        $entryRoot = self::entryRootFromDocuments($routes);
        if ('' === $entryRoot) return null;
        $rooted = ArtifactPath::resolveRelativePath(ltrim($url, '/'), $entryRoot . '/index.html');
        $reference = '' === $rooted ? null : $references->reference('/' . $rooted, $sourcePath);
        return $reference ?? $references->reference($url, '');
    }
    /** @param mixed $documents @return array<int,array<string,mixed>> */
    private function decideDocuments(mixed $documents): array
    {
        if (!is_array($documents)) throw new InvalidArgumentException('Compiled site documents must be an array.');
        foreach ($documents as &$document) {
            if (!is_array($document) || !self::safePath($document['source_path'] ?? null)) throw new InvalidArgumentException('Compiled site document is invalid.');
            $metadata = is_array($document['metadata'] ?? null) ? $document['metadata'] : array();
            $frontmatter = is_array($metadata['frontmatter'] ?? null) ? $metadata['frontmatter'] : array();
            $explicit = null; $provenance = null;
            foreach (array('post_type', 'type') as $key) if (is_string($frontmatter[$key] ?? null) && in_array(strtolower($frontmatter[$key]), array('page', 'post'), true)) { $explicit = strtolower($frontmatter[$key]); $provenance = 'frontmatter:' . $key; break; }
            if (null === $explicit && 'metadata:post_type' === ($metadata['post_type_declaration'] ?? null) && is_string($metadata['post_type'] ?? null) && in_array(strtolower($metadata['post_type']), array('page', 'post'), true)) { $explicit = strtolower($metadata['post_type']); $provenance = 'metadata:post_type'; }
            $evidence = $this->publicationEvidence($document);
            $postType = $explicit ?? ((!empty($document['entrypoint']) || array() === $evidence) ? 'page' : 'post');
            $surface = $this->templateSurface($metadata['template_surface'] ?? null, (string) ($document['source_path'] ?? ''));
            if (null !== $surface) {
                $document['template_surface'] = $surface;
                $metadata['template_surface'] = $surface;
            }
            $metadata['post_type'] = $postType;
            $document['metadata'] = $metadata;
            $document['content_decision'] = array_filter(array('schema' => 'blocks-engine/content-decision/v1', 'state' => null !== $explicit ? 'declared' : (array() === $evidence ? 'defaulted' : 'inferred'), 'post_type' => $postType, 'provenance' => $provenance, 'evidence' => $evidence), static fn(mixed $value): bool => null !== $value);
            foreach ($evidence as $row) if (is_string($row['publication_timestamp'] ?? null)) { $document['publication_timestamp'] = $row['publication_timestamp']; break; }
        }
        unset($document);
        return $documents;
    }
    /** @return array<string,mixed>|null */
    private function templateSurface(mixed $surface, string $sourcePath): ?array
    {
        if (null === $surface) return null;
        if (!is_array($surface) || !self::validTemplateSurfaceDeclaration($surface, $sourcePath)) throw new ValidationException('A declared template surface must have coherent versioned identity and declaration provenance.', array('source_path' => $sourcePath, 'document_kind' => 'template_surface', 'declaration_kind' => 'template_surface', 'reason' => 'invalid_template_surface'));
        return $surface;
    }
    /** @param array<int,array<string,mixed>> $documents @return array<int,array<string,mixed>> */
    private function templateSurfaces(array $documents): array
    {
        $groups = array();
        foreach ($documents as $document) if (is_array($document['template_surface'] ?? null)) $groups[$document['template_surface']['role'] . "\0" . $document['template_surface']['slug']][] = $document;
        $surfaces = array();
        foreach ($groups as $key => $candidates) {
            $metadataCandidates = array_values(array_filter($candidates, static fn(array $candidate): bool => 'artifact_metadata' === ($candidate['template_surface']['declaration_provenance']['kind'] ?? null)));
            if (array() !== $metadataCandidates) $candidates = $metadataCandidates;
            usort($candidates, static fn(array $a, array $b): int => strcmp((string) $a['source_path'], (string) $b['source_path']));
            $hashes = array_unique(array_map(static fn(array $document): string => hash('sha256', (string) ($document['block_markup'] ?? '')), $candidates));
            if (count($hashes) > 1) throw new ValidationException('Declared template surface variants have different block markup.', array('source_path' => (string) $candidates[0]['source_path'], 'document_kind' => 'template_surface', 'declaration_kind' => 'template_surface', 'reason' => 'template_surface_ambiguous', 'fields' => array('surface' => str_replace("\0", ':', $key), 'candidate_count' => count($candidates))));
            $selected = $candidates[0];
            $provenance = $selected['provenance'] ?? null;
            if (!self::validTemplateSourceProvenance($provenance, (string) $selected['source_path'])) throw new ValidationException('A declared template surface source provenance is detached from its selected source.', array('source_path' => (string) $selected['source_path'], 'document_kind' => 'template_surface', 'declaration_kind' => 'template_surface', 'reason' => 'invalid_template_surface_provenance'));
            $variants = array();
            foreach ($candidates as $candidate) {
                $candidateProvenance = $candidate['provenance'] ?? null;
                if (!self::validTemplateSourceProvenance($candidateProvenance, (string) $candidate['source_path'])) throw new ValidationException('A declared template surface variant provenance is detached from its source.', array('source_path' => (string) $candidate['source_path'], 'document_kind' => 'template_surface', 'declaration_kind' => 'template_surface', 'reason' => 'invalid_template_surface_variant'));
                $variants[] = array('source_path' => $candidate['source_path'], 'source_hash' => $candidateProvenance['hash'], 'source_provenance' => $candidateProvenance);
            }
            $selected['template_surface']['source_variants'] = $variants;
            $selected['template_surface']['selected_source_path'] = $selected['source_path'];
            $selected['template_surface']['selected_source_hash'] = $provenance['hash'];
            $selected['template_surface']['source_provenance'] = $provenance;
            $surfaces[] = $selected;
        }
        return $surfaces;
    }
    /** @param mixed $documents @return array<int,array<string,mixed>> */
    private function sourceDocumentCatalog(mixed $documents): array
    {
        if (!is_array($documents)) throw new InvalidArgumentException('Compiled site documents must be an array.');
        $catalog = array();
        foreach ($documents as $document) {
            $sourcePath = is_array($document) && is_string($document['source_path'] ?? null) ? $document['source_path'] : '';
            $provenance = is_array($document) ? ($document['provenance'] ?? null) : null;
            if (!self::safePath($sourcePath) || !self::validTemplateSourceProvenance($provenance, $sourcePath)) throw new InvalidArgumentException('Compiled site source document catalog entry is invalid.');
            $catalog[] = array('source_path' => $sourcePath, 'source_hash' => $provenance['hash'], 'source_provenance' => $provenance);
        }
        usort($catalog, static fn(array $left, array $right): int => strcmp($left['source_path'], $right['source_path']));
        return $catalog;
    }
    /** @param array<string,mixed> $surface */
    private static function validTemplateSurfaceDeclaration(array $surface, string $sourcePath): bool
    {
        $identifier = static fn(mixed $value): bool => is_string($value) && 0 < strlen($value) && strlen($value) <= 128 && 1 === preg_match('/^[a-z0-9][a-z0-9._:-]*$/', $value);
        $provenance = $surface['declaration_provenance'] ?? null;
        return 'blocks-engine/template-surface/v1' === ($surface['schema'] ?? null) && in_array($surface['role'] ?? null, array('404', 'archive', 'attachment', 'author', 'category', 'date', 'home', 'search', 'single', 'tag', 'taxonomy'), true) && $identifier($surface['slug'] ?? null) && ($surface['logical_surface_id'] ?? null) === $surface['role'] . ':' . $surface['slug'] && $identifier($surface['responsive_variant_id'] ?? null) && is_array($provenance) && 'blocks-engine/template-surface-provenance/v1' === ($provenance['schema'] ?? null) && in_array($provenance['kind'] ?? null, array('artifact_metadata', 'html_attributes'), true) && $sourcePath === ($provenance['source_path'] ?? null);
    }
    /** @param array<string,mixed> $surface */
    private static function validTemplateSurface(array $surface, mixed $sourcePath, array $sourceCatalog): bool
    {
        if (!is_string($sourcePath) || !self::validTemplateSurfaceDeclaration($surface, $sourcePath) || !is_array($surface['source_provenance'] ?? null) || !self::validTemplateSourceProvenance($surface['source_provenance'], $sourcePath) || !is_array($surface['source_variants'] ?? null) || !array_is_list($surface['source_variants']) || array() === $surface['source_variants'] || !is_string($surface['selected_source_path'] ?? null) || $sourcePath !== $surface['selected_source_path'] || !is_string($surface['selected_source_hash'] ?? null) || $surface['selected_source_hash'] !== $surface['source_provenance']['hash']) return false;
        $paths = array(); $selected = null; foreach ($surface['source_variants'] as $variant) { if (!is_array($variant) || !is_string($variant['source_path'] ?? null) || !self::safePath($variant['source_path']) || isset($paths[$variant['source_path']]) || !self::hash($variant['source_hash'] ?? null) || !self::validTemplateSourceProvenance($variant['source_provenance'] ?? null, $variant['source_path']) || $variant['source_hash'] !== $variant['source_provenance']['hash'] || !isset($sourceCatalog[$variant['source_path']]) || RuntimeDeclarations::canonicalJson($sourceCatalog[$variant['source_path']]) !== RuntimeDeclarations::canonicalJson($variant)) return false; $paths[$variant['source_path']] = true; if ($sourcePath === $variant['source_path']) $selected = $variant; }
        $ordered = array_keys($paths); $sorted = $ordered; sort($sorted, SORT_STRING);
        return is_array($selected) && $selected['source_hash'] === $surface['selected_source_hash'] && RuntimeDeclarations::canonicalJson($selected['source_provenance']) === RuntimeDeclarations::canonicalJson($surface['source_provenance']) && $ordered === $sorted;
    }
    /** @param array<string,mixed>|mixed $provenance */
    private static function validTemplateSourceProvenance(mixed $provenance, string $sourcePath): bool
    {
        return is_array($provenance) && $sourcePath === ($provenance['source_path'] ?? null) && is_string($provenance['source'] ?? null) && '' !== $provenance['source'] && self::hash($provenance['hash'] ?? null);
    }
    /** @param array<string,mixed> $source @return array<string,array<string,mixed>> */
    private static function sourceDocumentCatalogFromSource(array $source): array
    {
        if (!isset($source['source_documents'])) return array();
        $catalog = array();
        foreach ($source['source_documents'] as $row) {
            if (!is_array($row) || !is_string($row['source_path'] ?? null) || !self::safePath($row['source_path']) || isset($catalog[$row['source_path']]) || !self::hash($row['source_hash'] ?? null) || !self::validTemplateSourceProvenance($row['source_provenance'] ?? null, $row['source_path']) || $row['source_hash'] !== $row['source_provenance']['hash']) throw new InvalidArgumentException('WordPress site plan source document catalog is invalid.');
            $catalog[$row['source_path']] = $row;
        }
        $paths = array_keys($catalog); $sorted = $paths; sort($sorted, SORT_STRING); if ($paths !== $sorted) throw new InvalidArgumentException('WordPress site plan source document catalog must be sorted.');
        return $catalog;
    }
    /** @param array<string,mixed> $document @return array<int,array<string,string>> */
    private function publicationEvidence(array $document): array
    {
        $html = is_string($document['html'] ?? null) ? $document['html'] : '';
        $evidence = array(); $add = static function (array &$rows, string $source, ?string $value = null): void { if (count($rows) >= 16) return; $row = array('source' => $source); if (null !== $value) $row['publication_timestamp'] = $value; $rows[] = $row; };
        $timestamp = static fn(string $value): ?string => self::normalizePublicationTimestamp($value);
        foreach (($document['document_metadata']['meta'] ?? array()) as $meta) if (is_array($meta) && is_string($meta['content'] ?? null) && in_array(strtolower((string) ($meta['property'] ?? $meta['name'] ?? '')), array('article:published_time', 'article:published', 'pubdate', 'publishdate', 'date', 'dc.date.issued', 'dc.date', 'parsely-pub-date', 'releasedate'), true)) if (null !== ($date = $timestamp($meta['content']))) $add($evidence, 'meta:' . strtolower((string) ($meta['property'] ?? $meta['name'])), $date);
        foreach (self::htmlMarkupNodes($html) as $node) if ('tag' === ($node['kind'] ?? null)) { $attributes = $node['attributes']; if ('time' === ($node['name'] ?? null) && is_string($attributes['datetime'] ?? null) && null !== ($date = $timestamp(html_entity_decode($attributes['datetime'], ENT_QUOTES | ENT_HTML5, 'UTF-8')))) $add($evidence, 'html:time[datetime]', $date); if (preg_match('~\b(?:Article|BlogPosting)\b~', (string) ($attributes['itemtype'] ?? ''))) $add($evidence, 'microdata:itemtype'); if (in_array($attributes['itemprop'] ?? null, array('datePublished', 'dateCreated'), true)) foreach (array('datetime', 'content') as $key) if (is_string($attributes[$key] ?? null) && null !== ($date = $timestamp(html_entity_decode($attributes[$key], ENT_QUOTES | ENT_HTML5, 'UTF-8')))) { $add($evidence, 'microdata:datePublished', $date); break; } }
        foreach (self::htmlMarkupNodes($html) as $node) if ('rawtext' === ($node['kind'] ?? null) && 'script' === ($node['name'] ?? null) && 'application/ld+json' === strtolower(trim((string) ($node['attributes']['type'] ?? '')))) foreach ($this->jsonLdPublicationEvidence(json_decode($node['content'], true), $timestamp) as $row) $add($evidence, $row['source'], $row['publication_timestamp'] ?? null);
        $route = is_string($document['metadata']['route_path'] ?? null) ? $document['metadata']['route_path'] : self::pageRoutePath((string) $document['source_path'], self::entryRootFromDocuments(array($document)));
        if (preg_match('~/(?:[0-9]{4})/(?:0[1-9]|1[0-2])(?:/|$)~', $route)) $add($evidence, 'route:dated');
        $unique = array(); foreach ($evidence as $row) $unique[$row['source'] . "\n" . ($row['publication_timestamp'] ?? '')] = $row; return array_values($unique);
    }
    /** @return array<int,array<string,string>> */
    private function jsonLdPublicationEvidence(mixed $value, callable $timestamp): array
    {
        if (!is_array($value)) return array(); $rows = array();
        if (isset($value['@type'])) { $types = is_array($value['@type']) ? $value['@type'] : array($value['@type']); if (array_intersect(array('Article', 'BlogPosting'), $types)) { $row = array('source' => 'json-ld:' . (in_array('BlogPosting', $types, true) ? 'BlogPosting' : 'Article')); foreach (array('datePublished', 'dateCreated') as $key) if (is_string($value[$key] ?? null) && null !== ($date = $timestamp($value[$key]))) { $row['publication_timestamp'] = $date; break; } $rows[] = $row; } }
        foreach ($value as $child) if (is_array($child)) $rows = array_merge($rows, $this->jsonLdPublicationEvidence($child, $timestamp));
        return $rows;
    }
    /** @param array<string,mixed> $compiled @param array<string,mixed> $data @param array<string,mixed> $coreHtmlFallbackEvidence @return array<string,mixed> */
    private function reporting(array $pages, array $data, array $coreHtmlFallbackEvidence, array $scriptDiagnostics = array(), array $surfaces = array()): array { $documents = array(); foreach ($pages as $page) if (is_array($page)) $documents[] = array('source_path' => $page['source_path'] ?? '', 'kind' => 'page', 'body_format' => 'blocks', 'block_document' => true, 'provenance' => $page['provenance'] ?? array()); foreach ($surfaces as $surface) $documents[] = array('source_path' => $surface['source_path'] ?? '', 'kind' => 'template_surface', 'body_format' => 'blocks', 'block_document' => true, 'template_surface' => $surface['template_surface'] ?? array(), 'provenance' => $surface['provenance'] ?? array()); return array('source_documents' => $documents, 'metrics' => array('source_document_count' => count($documents), 'block_document_count' => count($documents), 'native_block_count' => $data['metrics']['block_count'] ?? 0, 'fallback_count' => $data['metrics']['fallback_count'] ?? 0), 'core_html_fallback_evidence' => $coreHtmlFallbackEvidence, 'diagnostic_codes' => array_values(array_map(static fn(array $diagnostic): string => (string) ($diagnostic['code'] ?? ''), array_merge($data['diagnostics'], $scriptDiagnostics)))); }

    /**
     * The canonical source-path-to-route map, and the only place a route
     * identity is decided.
     *
     * A collision here is information about two pages, not about the plan: a
     * CMS that slugifies author-written titles into filenames routinely emits
     * two paths that derive one slug (a duplicated page whose copy kept a
     * trailing `_`), and aborting costs every other page in the site. So the
     * first occurrence in document order keeps the route and later ones take a
     * deterministic `-2`, `-3` suffix, exactly as WordPress resolves a
     * duplicate `post_name`, with one warning naming both source paths.
     *
     * Two identities are not the plan's to rename, and both are reserved before
     * any derived route is assigned: an authored `metadata.route_path`, where
     * two explicit values naming one route are a contradiction that still fails
     * closed, and the entrypoint, which must keep `/` or the site has no front
     * page.
     *
     * @param mixed $documents @param array<int,array<string,mixed>> $legacyRoutes @return array<int,array<string,mixed>>
     */
    private function canonicalRoutes(mixed $documents, array $legacyRoutes): array
    {
        if (!is_array($documents)) throw new InvalidArgumentException('Compiled site documents must be an array.');
        $legacy = array(); foreach ($legacyRoutes as $route) if (is_array($route) && is_string($route['source_path'] ?? null)) $legacy[$route['source_path']] = $route;
        $entryRoot = self::entryRootFromDocuments($documents);
        $this->routeCollisions = array(); $this->omittedRouteCollisionDiagnostics = 0;
        $derived = array(); $explicit = array();
        foreach ($documents as $order => $document) {
            if (!is_array($document) || !self::safePath($document['source_path'] ?? null)) throw new InvalidArgumentException('Compiled site route source is invalid.');
            $metadata = is_array($document['metadata'] ?? null) ? $document['metadata'] : array();
            $explicit[$order] = is_string($metadata['route_path'] ?? null) && '' !== $metadata['route_path'];
            if ('' !== $entryRoot && ! str_starts_with((string) $document['source_path'], $entryRoot . '/') && !$explicit[$order]) throw new InvalidArgumentException('Compiled site document is outside the entrypoint content root.');
            $derived[$order] = $explicit[$order] ? self::canonicalRoutePath($metadata['route_path']) : self::pageRoutePath($document['source_path'], $entryRoot);
        }
        $taken = array(); $reserved = array();
        foreach ($derived as $order => $path) if ($explicit[$order]) {
            if (isset($taken[$path])) throw new InvalidArgumentException(sprintf('WordPress site plan has colliding page routes: %s and %s both declare %s.', $taken[$path], (string) $documents[$order]['source_path'], $path));
            $taken[$path] = (string) $documents[$order]['source_path']; $reserved[$order] = true;
        }
        foreach ($derived as $order => $path) if (!$explicit[$order] && !empty($documents[$order]['entrypoint']) && !isset($taken[$path])) { $taken[$path] = (string) $documents[$order]['source_path']; $reserved[$order] = true; }
        // Every first claimant keeps its own route before any suffix is handed
        // out, so a renamed duplicate cannot take the route a later page derived
        // for itself: `x_` beside a real `x` and `x-2` becomes `/x-3`, not `/x-2`.
        foreach ($derived as $order => $path) if (!isset($reserved[$order]) && !isset($taken[$path])) { $taken[$path] = (string) $documents[$order]['source_path']; $reserved[$order] = true; }
        $routes = array();
        foreach ($documents as $order => $document) {
            $sourcePath = (string) $document['source_path'];
            $path = $derived[$order];
            if (!isset($reserved[$order])) {
                $kept = $taken[$path];
                $path = self::disambiguatedRoutePath($path, $taken);
                $this->recordRouteCollision($kept, $sourcePath, $derived[$order], $path);
                $taken[$path] = $sourcePath;
            }
            $metadata = is_array($document['metadata'] ?? null) ? $document['metadata'] : array();
            $previous = $legacy[$sourcePath] ?? array();
            $routes[] = array('kind' => 'route', 'source_path' => $document['source_path'], 'target_path' => $path, 'target_slug' => self::value($document, 'slug', self::routeSlug($path)), 'title' => self::value($document, 'title'), 'parent_source_path' => self::value($metadata, 'parent_source_path'), 'source_relation' => !empty($document['entrypoint']) ? 'entrypoint' : ($previous['source_relation'] ?? 'document'), 'order' => $order);
        }
        return $routes;
    }
    /** @param array<string,string> $taken */
    private static function disambiguatedRoutePath(string $path, array $taken): string
    {
        // The front page has no segment to number, so its duplicates take the
        // slug WordPress gives a directory index.
        $base = '/' === $path ? '/index' : $path;
        for ($suffix = 2; ; ++$suffix) if (!isset($taken[$base . '-' . $suffix])) return $base . '-' . $suffix;
    }
    private function recordRouteCollision(string $keptSourcePath, string $sourcePath, string $routePath, string $resolvedPath): void
    {
        if (count($this->routeCollisions) >= self::MAX_ROUTE_COLLISION_DIAGNOSTICS) { ++$this->omittedRouteCollisionDiagnostics; return; }
        $this->routeCollisions[] = array(
            'code' => 'wordpress_site_plan_colliding_page_route',
            'severity' => 'warning',
            'message' => substr(sprintf('%s derives the same route as %s (%s); it materializes at %s instead.', $sourcePath, $keptSourcePath, $routePath, $resolvedPath), 0, 256),
            'source_path' => substr($sourcePath, 0, 256),
            'colliding_source_path' => substr($keptSourcePath, 0, 256),
            'route_path' => substr($routePath, 0, 256),
            'resolved_route_path' => substr($resolvedPath, 0, 256),
            'reason_code' => 'colliding_page_route',
            'pattern_family' => 'site_plan_route',
            'repair_bucket' => 'restore_canonical_route_identity',
        );
    }
    /** @return array<int,array<string,mixed>> */
    private function routeCollisionDiagnostics(): array
    {
        $diagnostics = $this->routeCollisions;
        if ($this->omittedRouteCollisionDiagnostics > 0) $diagnostics[] = array('code' => 'wordpress_site_plan_colliding_page_route', 'severity' => 'warning', 'message' => sprintf('%d more colliding page routes were disambiguated; omitted from this diagnostic list.', $this->omittedRouteCollisionDiagnostics), 'reason' => 'truncated', 'reason_code' => 'colliding_page_route', 'pattern_family' => 'site_plan_route', 'repair_bucket' => 'restore_canonical_route_identity', 'omitted_count' => $this->omittedRouteCollisionDiagnostics);
        return $diagnostics;
    }
    /** @param array<int,array<string,mixed>> $pages @param array<int,array<string,mixed>> $routes @return array<int,array<string,mixed>> */
    private function pageHierarchy(array $pages, array $routes): array
    {
        $byRoute = array(); $sources = array(); foreach ($pages as $page) $sources[$page['source_path']] = true;
        foreach ($pages as $index => &$page) {
            $route = array_values(array_filter($routes, static fn(array $route): bool => $route['source_path'] === $page['source_path']))[0] ?? null; if (!is_array($route)) throw new InvalidArgumentException('WordPress site plan page lacks a canonical route.'); $path = $route['target_path'];
            if (isset($byRoute[$path])) throw new InvalidArgumentException(sprintf('WordPress site plan has colliding page routes: %s and %s both resolve to %s.', (string) ($pages[$byRoute[$path]]['source_path'] ?? ''), (string) $page['source_path'], $path));
            $page['route'] = array('path' => $path, 'parent_path' => self::parentRoutePath($path), 'slug' => self::routeSlug($path));
            if ('/' !== $path) $page['slug'] = $page['route']['slug'];
            $page['reconciliation_identity'] = self::identity('page', $page['source_path'], $path);
            $byRoute[$path] = $index;
        }
        unset($page);
        foreach (array_keys($byRoute) as $path) {
            if ('post' === ($pages[$byRoute[$path]]['post_type'] ?? null)) continue;
            foreach (self::routeAncestors($path) as $ancestor) if (!isset($byRoute[$ancestor])) {
            $source = 'wordpress-site-plan/routes/' . trim($ancestor, '/') . '.html';
            if (isset($sources[$source])) throw new InvalidArgumentException('WordPress site plan synthetic route source collides with a document.');
            $markup = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"></div><!-- /wp:group -->' . "\n";
            $pages[] = array('source_path' => $source, 'slug' => self::routeSlug($ancestor), 'title' => ucwords(str_replace('-', ' ', self::routeSlug($ancestor))), 'post_type' => 'page', 'parent_source_path' => '', 'entrypoint' => false, 'area' => null, 'placement' => null, 'canonical_block_markup' => $markup, 'metadata' => array(), 'document_metadata' => array('source_context' => array('source_path' => $source, 'kind' => 'synthetic_route'), 'title' => self::routeSlug($ancestor), 'title_declaration' => array('order' => 0, 'placement' => 'head'), 'meta' => array(), 'links' => array(), 'scripts' => array()), 'provenance' => array(), 'reconciliation_identity' => self::identity('page', $source, $ancestor), 'content_hash' => hash('sha256', $markup), 'route' => array('path' => $ancestor, 'parent_path' => self::parentRoutePath($ancestor), 'slug' => self::routeSlug($ancestor)), 'synthetic' => true);
            $byRoute[$ancestor] = count($pages) - 1;
            $sources[$source] = true;
            }
        }
        foreach ($pages as &$page) { if ('post' === ($page['post_type'] ?? null)) { $page['parent_source_path'] = ''; continue; } $parent = $page['route']['parent_path']; if ('/' !== $parent && 'page' !== ($pages[$byRoute[$parent]]['post_type'] ?? null)) throw new InvalidArgumentException('WordPress site plan page hierarchy cannot inherit a post route.'); $page['parent_source_path'] = '/' === $parent ? '' : $pages[$byRoute[$parent]]['source_path']; }
        unset($page);
        usort($pages, static fn(array $left, array $right): int => substr_count($left['route']['path'], '/') <=> substr_count($right['route']['path'], '/') ?: strcmp($left['route']['path'], $right['route']['path']));
        return $pages;
    }
    /** @param array<int,array<string,mixed>> $pages @return array<int,array<string,mixed>> */
    private function routesForPages(array $pages): array { $routes = array(); foreach ($pages as $page) $routes[] = array('kind' => 'route', 'source_path' => $page['source_path'], 'target_path' => $page['route']['path'], 'target_slug' => $page['slug'], 'title' => $page['title'], 'parent_source_path' => $page['parent_source_path'], 'source_relation' => !empty($page['synthetic']) ? 'synthetic_parent' : (!empty($page['entrypoint']) ? 'entrypoint' : 'document'), 'order' => count($routes)); return $routes; }

    /** @param array<int,array<string,mixed>> $pages @return array<int,array<string,string>> */
    private function templates(array $pages, array $parts, array $surfaces = array(), array $tokens = array(), ?AssetReferenceCanonicalizer $references = null, array $routes = array()): array
    {
        $bound = array_values(array_filter($parts, static fn(array $part): bool => in_array($part['placement']['kind'] ?? '', array('entry_shell', 'shared_shell'), true)));
        usort($bound, static function (array $left, array $right): int {
            $priority = array('header' => 0, 'footer' => 2);
            return (($priority[$left['area']] ?? 1) <=> ($priority[$right['area']] ?? 1)) ?: strcmp($left['slug'], $right['slug']);
        });
        $markup = static function (string $templateSlug) use ($bound): string {
            $before = ''; $after = '';
            $container = null;
            foreach ($bound as $part) if (in_array($templateSlug, $part['placement']['template_slugs'] ?? array(), true) || (preg_match('/^(?:page|single)-[a-z0-9-]+$/', $templateSlug) && !in_array($templateSlug, $part['placement']['excluded_template_slugs'] ?? array(), true))) {
                if (is_array($part['placement']['container'] ?? null)) $container = $part['placement']['container'];
                $reference = '<!-- wp:template-part {"slug":"' . $part['slug'] . '","area":"' . $part['area'] . '","tagName":"' . $part['tag_name'] . '"} /-->' . "\n";
                if ('footer' === $part['area']) $after .= $reference; else $before .= $reference;
            }
            if (in_array($templateSlug, array('index', 'search'), true)) {
                $query = ('search' === $templateSlug ? '<!-- wp:query-title {"type":"search"} /-->' . "\n" : '')
                    . '<!-- wp:query {"queryId":1,"query":{"perPage":10,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":true},"layout":{"type":"constrained"}} -->' . "\n" . '<div class="wp-block-query"><!-- wp:post-template -->' . "\n" . '<!-- wp:post-title {"isLink":true} /-->' . "\n" . '<!-- wp:post-excerpt /-->' . "\n" . '<!-- wp:post-date {"isLink":true} /-->' . "\n" . '<!-- /wp:post-template -->' . "\n" . '<!-- wp:query-pagination {"paginationArrow":"arrow","layout":{"type":"flex","justifyContent":"space-between"}} -->' . "\n" . '<!-- wp:query-pagination-previous /-->' . "\n" . '<!-- wp:query-pagination-next /-->' . "\n" . '<!-- /wp:query-pagination -->' . "\n" . '<!-- wp:query-no-results -->' . "\n" . '<!-- wp:paragraph -->' . "\n" . '<p>No posts found.</p>' . "\n" . '<!-- /wp:paragraph -->' . "\n" . '<!-- /wp:query-no-results --></div>' . "\n" . '<!-- /wp:query -->';
                $content = '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->' . "\n" . '<main class="wp-block-group">' . "\n" . $query . "\n" . '</main>' . "\n" . '<!-- /wp:group -->';
            } else {
                $content = '<!-- wp:post-content /-->';
            }
            if (is_array($container) && is_string($container['opening'] ?? null) && is_string($container['closing'] ?? null)) return $container['opening'] . $before . $content . "\n" . $container['closing'] . $after;
            return $before . $content . "\n" . $after;
        };
        $make = static function (string $slug, string $target, string $content): array { return array('slug' => $slug, 'target_path' => $target, 'canonical_block_markup' => $content, 'reconciliation_identity' => self::identity('template', 'wordpress-site-plan/' . $target, $target), 'content_hash' => self::contentHash($content)); };
        $templates = array($make('index', 'templates/index.html', $markup('index')));
        $templates[] = $make('search', 'templates/search.html', $markup('search'));
        if ( array() !== $pages ) $templates[] = $make('page', 'templates/page.html', $markup('page'));
        foreach ( $pages as $page ) if ( 'post' === ($page['post_type'] ?? null) ) { $templates[] = $make('single', 'templates/single.html', $markup('single')); break; }
        foreach ( $pages as $page ) if ( ! empty($page['entrypoint']) ) { $templates[] = $make('front-page', 'templates/front-page.html', $markup('front-page')); break; }
        $overrides = array();
        foreach ($bound as $part) foreach ($part['placement']['excluded_template_slugs'] ?? array() as $slug) if (preg_match('/^(?:page|single)-[a-z0-9-]+$/', $slug)) $overrides[$slug] = true;
        foreach (array_keys($overrides) as $slug) $templates[] = $make($slug, 'templates/' . $slug . '.html', $markup($slug));
        foreach ($surfaces as $surface) {
            $declaration = $surface['template_surface']; $slug = $declaration['slug']; $target = 'templates/' . $slug . '.html';
            if (array_filter($templates, static fn(array $template): bool => $template['slug'] === $slug)) throw new InvalidArgumentException('A declared template surface collides with a generated template.');
            $content = is_null($references) ? (string) $surface['block_markup'] : $this->routeLinks($references->content((string) $surface['block_markup'], (string) $surface['source_path']), (string) $surface['source_path'], $routes);
            $templates[] = array('slug' => $slug, 'target_path' => $target, 'canonical_block_markup' => $content, 'source_path' => $surface['source_path'], 'template_surface' => $declaration, 'provenance' => $surface['provenance'] ?? array(), 'reconciliation_identity' => self::identity('template', $surface['source_path'], $target), 'content_hash' => self::contentHash($content));
        }
        return $templates;
    }

    /** @param array<int,array<string,mixed>> $pages @return array<int,array<string,mixed>> */
    private function operations(array $pages): array
    {
        $operations = array();
        foreach ($pages as $page) $operations[] = array('kind' => 'create_page', 'order' => count($operations), 'source_path' => $page['source_path'], 'reconciliation_identity' => $page['reconciliation_identity'], 'post_type' => $page['post_type'], 'slug' => $page['slug'], 'route_path' => $page['route']['path'], 'parent_source_path' => $page['parent_source_path'], 'synthetic' => !empty($page['synthetic']));
        foreach ($pages as $page) if (!empty($page['entrypoint'])) { $operations[] = array('kind' => 'site_reading', 'order' => count($operations), 'show_on_front' => 'page', 'front_page_source_path' => $page['source_path'], 'front_page_reconciliation_identity' => $page['reconciliation_identity']); break; }
        return $operations;
    }

    /** @param array<string,mixed> $runtimeIslandPackage @param array<int,array<string,mixed>> $compiledPages @param array<int,array<string,mixed>> $runtimeDeclarations @return array{documents:array<string,bool>,assets:array<string,bool>} */
    private function runtimeScriptOwnership(array $runtimeIslandPackage, array $compiledPages, array $runtimeDeclarations): array
    {
        $documents = array();
        $assets = array();
        $superseded = array();
        foreach ( $runtimeDeclarations as $declaration ) foreach ( $declaration['payload']['entities'] ?? array() as $entity ) foreach ( $entity['superseded_scripts'] ?? array() as $script ) {
            if ( is_array($script) && is_string($script['source_path'] ?? null) && is_string($script['selector'] ?? null) ) $superseded[$script['source_path'] . "\n" . $script['selector']] = true;
        }
        $package = $runtimeIslandPackage;
        $themeOwnedRequiredScripts = RuntimeIslandPackageBuilder::themeOwnedRequiredScriptOccurrences($package, $compiledPages);
        foreach ( $package['islands'] ?? array() as $island ) {
            if ( !is_array($island) ) continue;
            $sourcePath = is_string($island['source_path'] ?? null) ? $island['source_path'] : '';
            $selector = is_string($island['selector'] ?? null) ? $island['selector'] : '';
            if ( isset($superseded[$sourcePath . "\n" . $selector]) ) continue;
            $drop = 'drop' === ($island['disposition'] ?? null);
            $carried = false;
            foreach ( $island['scripts'] ?? array() as $script ) {
                if ( !is_array($script) ) continue;
                $scriptSelector = is_string($script['selector'] ?? null) ? $script['selector'] : '';
                if ( isset($themeOwnedRequiredScripts[$sourcePath . "\n" . $scriptSelector]) ) continue;
                $scriptDropped = 'telemetry' === ($script['role'] ?? null);
                $scriptCarried = !$scriptDropped && is_string($script['content'] ?? null) && '' !== trim($script['content']);
                $carried = $carried || $scriptCarried;
                if ( ($drop || $scriptDropped || $scriptCarried) && is_string($script['resolved_path'] ?? null) ) $assets[$script['resolved_path']] = true;
                if ( ($drop || $scriptDropped || $scriptCarried) && '' !== $sourcePath && is_string($script['src'] ?? null) ) $documents[$sourcePath . "\n" . $script['src']] = true;
            }
            if ( ($drop || $carried) && '' !== $sourcePath && '' !== $selector ) $documents[$sourcePath . "\n" . $selector] = true;
        }
        return array('documents' => $documents, 'assets' => $assets);
    }

    /** @param array<int,array<string,mixed>> $documents @param array<string,bool> $owned @return array<int,array<string,mixed>> */
    private function withoutOwnedRuntimeScripts(array $documents, array $owned): array
    {
        foreach ( $documents as &$document ) {
            $sourcePath = is_string($document['source_path'] ?? null) ? $document['source_path'] : '';
            if ( !is_array($document['document_metadata']['scripts'] ?? null) ) continue;
            $document['document_metadata']['scripts'] = array_values(array_filter(
                $document['document_metadata']['scripts'],
                static fn(mixed $script): bool => !is_array($script) || (!isset($owned[$sourcePath . "\n" . (string) ($script['selector'] ?? '')]) && !isset($owned[$sourcePath . "\n" . (string) ($script['url'] ?? '')]))
            ));
        }
        unset($document);
        return $documents;
    }

    /** @param array<int,array<string,mixed>> $documents @return array<string,bool> */
    private function documentScriptAssets(array $documents): array
    {
        $assets = array();
        foreach ( $documents as $document ) {
            $sourcePath = is_string($document['source_path'] ?? null) ? $document['source_path'] : '';
            foreach ( $document['document_metadata']['scripts'] ?? array() as $script ) {
                if ( !is_array($script) || !is_string($script['url'] ?? null) ) continue;
                $url = $script['url'];
                $resolved = ArtifactPath::resolveRelativePath($url, $sourcePath);
                if ( '' !== $resolved ) $assets[$resolved] = true;
                if ( str_starts_with($url, '/') && '.' !== dirname($sourcePath) ) $assets[trim(dirname($sourcePath), '/') . '/' . ltrim($url, '/')] = true;
            }
        }
        return $assets;
    }

    /** @param array<int,array<string,mixed>> $assets @param array<int,array<string,string>> $tokens @return array<int,array<string,mixed>> */
    private function assetWrites(array $assets, AssetReferenceCanonicalizer $references): array
    {
        $writes = array();
        $authorStylesheetOrigins = array_values(array_filter(array_map(static fn(array $asset): ?string => 'css' === ($asset['kind'] ?? null) && 'files' === ($asset['source'] ?? null) && is_string($asset['source_path'] ?? null) ? $asset['source_path'] : null, $assets)));
        foreach ( $assets as $asset ) {
            $content = is_string($asset['content'] ?? null) ? $references->content($asset['content'], (string) ($asset['reference_origin'] ?? $asset['source_path'])) : null;
            // Editor-state rules can retain URL-bearing declarations copied from
            // author stylesheets, whose relative origin is not this generated file.
            if ('editor-static-state' === ($asset['source'] ?? null) && is_string($content)) $content = $references->cssFromOrigins($content, $authorStylesheetOrigins);
            if (is_array($asset['payload_reference'] ?? null)) { $writes[] = $this->referenceWrite('theme_asset', $asset['target_path'], $asset['source_path'], $asset['payload_reference']); continue; }
            $base64Transport = is_string($asset['content_base64'] ?? null);
            $text = is_string($content) && empty($asset['binary']) && 1 === preg_match('//u', $content) && (!$base64Transport || 'text/css' === ($asset['mime_type'] ?? null));
            $data = $text ? $content : (is_string($asset['content_base64'] ?? null) ? $asset['content_base64'] : (is_string($content) ? base64_encode($content) : null));
            if ( ! is_string($data) ) throw new InvalidArgumentException(sprintf('Compiled site asset %s lacks a materializable payload.', $asset['source_path']));
            $writes[] = $this->write('theme_asset', $asset['target_path'], $data, $asset['source_path'], $text ? 'utf8' : 'base64');
        }
        return $writes;
    }

    /** @param array<int,array<string,mixed>> $pages @param array<int,array<string,mixed>> $parts @param array<int,array<string,mixed>> $assets @param array<int,array<string,string>> $tokens @param array<int,array<string,mixed>> $operations @return array{scripts:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>} */
    private function scriptLoading(array $pages, array $parts, array $assets, array $tokens, array $operations, array $runtimeDeclarations): array
    {
        $targets = array(); foreach ($tokens as $token) $targets[$token['token']] = $token['target_path'];
        $contents = array(); foreach ($assets as $asset) if (is_string($asset['content'] ?? null)) $contents[$asset['target_path']] = $asset['content'];
        $inlineTargets = array(); foreach ($assets as $asset) if ('inline-script' === ($asset['source'] ?? null) && is_string($asset['content'] ?? null)) $inlineTargets[self::contentHash($asset['content'])] = $asset['target_path'];
        $frontPages = array(); foreach ($operations as $operation) if ('site_reading' === ($operation['kind'] ?? null)) $frontPages[$operation['front_page_reconciliation_identity']] = true;
        $superseded = array();
        foreach ( $runtimeDeclarations as $declaration ) foreach ( $declaration['payload']['entities'] ?? array() as $entity ) foreach ( $entity['superseded_scripts'] ?? array() as $script ) if ( is_array($script) && is_string($script['source_path'] ?? null) && is_string($script['selector'] ?? null) && is_string($script['body_hash'] ?? null) && is_string($script['target_selector'] ?? null) ) $superseded[$script['source_path'] . "\n" . $script['selector'] . "\n" . $script['body_hash'] . "\n" . $script['target_selector']] = true;
        $scripts = array(); $diagnostics = array(); $instances = array();
        foreach (array_merge($pages, $parts) as $document) foreach ($document['document_metadata']['scripts'] ?? array() as $script) {
            $source = $document['source_path'] . '#' . ($script['order'] ?? '');
            $unsupported = static function (string $code, string $message) use (&$diagnostics, $source): void { $diagnostics[] = array('code' => $code, 'severity' => 'warning', 'message' => $message, 'source_path' => $source); };
            if (!is_array($script)) { $unsupported('wordpress_site_plan_script_invalid', 'Document script metadata is invalid.'); continue; }
            if (!self::isExecutableScriptType((string) ($script['type'] ?? ''), true === ($script['module'] ?? false))) continue;
            $supersessionKey = $document['source_path'] . "\n" . ($script['selector'] ?? '') . "\n" . ($script['body_hash'] ?? '') . "\n" . ($script['superseded_by'] ?? '');
            if ( isset($superseded[$supersessionKey]) ) continue;
            // A form-runtime script (marked with a supersession target) that a
            // provider binding did not safely supersede must not be materialized
            // as an ordinary inline asset: its retained behavior (network or
            // global side effects) is exactly what made it ineligible for
            // supersession. Keep the plan not_proven so the materializer treats
            // the residual runtime island as unresolved rather than silently
            // shipping the unsafe handler.
            if ( '' !== (string) ($script['superseded_by'] ?? '') ) { $unsupported('wordpress_site_plan_script_form_runtime_unsuperseded', 'A form-runtime script was not safely superseded by a provider binding and cannot be materialized as a static inline asset.'); continue; }
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
            $scope = isset($document['placement']) ? array('kind' => 'global', 'order' => $script['order']) : array('kind' => 'post' === ($document['post_type'] ?? null) ? 'post' : 'page', 'source_path' => $document['source_path'], 'route_path' => trim($document['route']['path'], '/'), 'reconciliation_identity' => $document['reconciliation_identity'], 'front_page' => isset($frontPages[$document['reconciliation_identity']]), 'order' => $script['order']);
            $scopeKey = ($scope['kind'] ?? '') . ':' . ($scope['source_path'] ?? 'global');
            $signature = hash('sha256', serialize($attributes)); $instance = $instances[$scopeKey][$signature] ?? 0; $instances[$scopeKey][$signature] = $instance + 1;
            $identity = $signature . ':' . $instance;
            if (!isset($scripts[$identity])) $scripts[$identity] = array_merge(array('identity' => $identity, 'scopes' => array()), $attributes);
            $scripts[$identity]['scopes'][] = $scope;
        }
        return array('scripts' => array_values($scripts), 'diagnostics' => $diagnostics);
    }

    private static function isExecutableScriptType(string $type, bool $module): bool
    {
        $type = strtolower(trim($type));
        return $module || '' === $type || in_array($type, array('module', 'text/javascript', 'application/javascript', 'text/ecmascript', 'application/ecmascript'), true);
    }

    private function hasDynamicScriptReferences(string $content): bool { return preg_match('/\bimport\s*\(|\b(?:document\s*\.\s*createElement\s*\(\s*["\']script|appendChild\s*\(|insertBefore\s*\(|\.\s*src\s*=|new\s+URL\s*\()/i', $content) === 1; }
    // Each path segment carries the author's words, so the route grammar folds
    // what it cannot spell instead of deleting it: `RouteSlug` applies
    // WordPress's own `remove_accents()` plus `sanitize_title_with_dashes()`
    // rule, which is what keeps distinct pages on distinct routes and keeps the
    // slug readable. See that class for why deleting was non-injective.
    private static function pageRoutePath(string $sourcePath, string $entryRoot = ''): string { $relative = self::stripEntryRoot($sourcePath, $entryRoot); $segments = explode('/', preg_replace('/\.[A-Za-z0-9]+$/', '', $relative) ?? $relative); $segments = array_values(array_filter(array_map(static fn(string $segment): string => RouteSlug::segment(self::decodedRouteSegment($segment)), $segments), static fn(string $segment): bool => '' !== $segment)); if ('index' === end($segments)) array_pop($segments); return '/' . implode('/', $segments); }
    // A source path segment carries the author's page title, so percent sequences
    // are ordinary punctuation a CMS slugified into a filename: `%3A`, `%2C`, and
    // `%E2%80%99` must reach the slugifier as `:`, `,`, and `’` and become part of
    // the author's words. Decoding is a single pass, and that pass must never give
    // the path structure the raw path did not already have: an encoded separator or
    // dot segment would silently change route identity, so those still fail closed
    // on exactly the rules `safePath()` applies to the undecoded path. Anything a
    // single decode leaves behind (a literal `%`, `%ZZ`, a truncated `%E2%80`) is
    // inert punctuation the slugifier strips.
    private static function decodedRouteSegment(string $segment): string { if (!str_contains($segment, '%')) return $segment; $decoded = rawurldecode($segment); if ('.' === $decoded || '..' === $decoded || preg_match('~[/\\\\\x00]~', $decoded)) throw new InvalidArgumentException('WordPress site plan page routes reject encoded path separators and dot segments.'); return $decoded; }
    // The entrypoint document's directory is the site's web root: a `website/`
    // wrapper around `website/index.html` must not become a `/website` route with
    // every other page nested beneath it. Strip that shared root so `index.html`
    // maps to `/` and its siblings map to top-level routes (`/contact`, `/music`).
    private static function stripEntryRoot(string $sourcePath, string $entryRoot): string { if ('' === $entryRoot) return $sourcePath; $prefix = rtrim($entryRoot, '/') . '/'; return str_starts_with($sourcePath, $prefix) ? substr($sourcePath, strlen($prefix)) : $sourcePath; }
    // Resolve the site root directory from the entrypoint document/page so route
    // derivation and validation agree on the same web root without shared state.
    private static function entryRootFromDocuments(array $documents): string { foreach ($documents as $document) { if (is_array($document) && (!empty($document['entrypoint']) || 'entrypoint' === ($document['source_relation'] ?? null)) && is_string($document['source_path'] ?? null)) { $dir = str_replace('\\', '/', dirname($document['source_path'])); return in_array($dir, array('.', '/', ''), true) ? '' : $dir; } } return ''; }
    private static function canonicalRoutePath(string $path): string { if (!preg_match('~^/(?:[a-z0-9-]+(?:/[a-z0-9-]+)*)?$~', $path)) throw new InvalidArgumentException('WordPress site plan has an unsafe explicit page route.'); return $path; }
    private static function parentRoutePath(string $path): string { $parent = dirname($path); return '.' === $parent || '/' === $parent ? '/' : '/' . trim($parent, '/'); }
    /** @return array<int,string> */
    private static function routeAncestors(string $path): array { $ancestors = array(); for ($parent = self::parentRoutePath($path); '/' !== $parent; $parent = self::parentRoutePath($parent)) $ancestors[] = $parent; return array_reverse($ancestors); }
    private static function routeSlug(string $path): string { return trim((string) basename($path), '/'); }

    /** @param array<int,array<string,mixed>> $assets @param array<int,array<string,string>> $templates @param array<int,array<string,mixed>> $parts @param array<int,array<string,mixed>> $pages @return array<int,array<string,mixed>> */
    private function scaffoldWrites(array $assets, array $templates, array $parts, array $scripts, array $theme, array $tokens, array $pages = array()): array
    {
        $writes = array($this->write('theme_scaffold', 'style.css', "/*\nTheme Name: Blocks Engine Site\nText Domain: blocks-engine-site\n*/\n"), $this->write('theme_scaffold', 'theme.json', json_encode($theme, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"));
        $writes[] = $this->write('theme_bootstrap', 'functions.php', self::bootstrap($assets, $scripts, $parts, $tokens, $templates, $pages));
        foreach ( $templates as $template ) $writes[] = $this->write('theme_template', $template['target_path'], $template['canonical_block_markup']);
        foreach ( $parts as $part ) $writes[] = $this->write('theme_template_part', 'parts/' . $part['slug'] . '.html', $part['canonical_block_markup']);
        return $writes;
    }

    /** @param array<int,array<string,mixed>> $assets */
    private static function bootstrap(array $assets, array $scripts = array(), array $parts = array(), array $tokens = array(), array $templates = array(), array $pages = array()): string
    {
        $lines = array("<?php", self::SOURCE_TEXT_TYPOGRAPHY, "add_action( 'wp_enqueue_scripts', static function (): void {");
        $importLoaded = self::importLoadedStylesheets($assets);
        foreach ($assets as $asset) {
            if ('editor' === ($asset['stylesheet_target'] ?? 'both') || isset($importLoaded[$asset['target_path']])) continue;
            $handle = 'blocks-engine-' . substr(hash('sha256', $asset['target_path']), 0, 12);
            if ('css' === $asset['kind']) foreach ($asset['scopes'] as $scope) {
                $condition = self::bootstrapScopeCondition($scope);
                $media = is_string($asset['media'] ?? null) && '' !== trim($asset['media']) ? ', ' . var_export($asset['media'], true) : '';
                $lines[] = "    if ( {$condition} ) wp_enqueue_style( " . var_export($handle, true) . ", get_theme_file_uri( " . var_export(self::encodedAssetUrlPath($asset['target_path']), true) . " ), array(), null{$media} );";
            }
        }
        $attributes = array();
        foreach ($scripts as $script) {
            $handle = 'blocks-engine-script-' . substr(hash('sha256', $script['identity']), 0, 12);
            $source = null !== $script['local_target'] ? "get_theme_file_uri( " . var_export(self::encodedAssetUrlPath($script['local_target']), true) . " ) . " . var_export($script['suffix'], true) : var_export($script['url'], true);
            $args = array('in_footer' => 'body' === $script['placement']);
            if ($script['async'] && !$script['module']) $args['strategy'] = 'async';
            if ($script['defer'] && !$script['async'] && !$script['module']) $args['strategy'] = 'defer';
            $lines[] = "    wp_register_script( " . var_export($handle, true) . ", {$source}, array(), null, " . var_export($args, true) . " );";
            $attributes[$handle] = array_filter(array('type' => $script['type'], 'nomodule' => $script['nomodule'], 'integrity' => $script['integrity'], 'crossorigin' => $script['crossorigin'], 'referrerpolicy' => $script['referrerpolicy'], 'fetchpriority' => $script['fetchpriority'], 'async' => $script['async'] && $script['module'], 'defer' => $script['defer'] && ($script['async'] || $script['module'])), static fn(mixed $value): bool => false !== $value && null !== $value);
        }
        $lines[] = "}, 1 );";
        $templateAssetTokens = array();
        foreach (array_merge($templates, $parts) as $document) if (str_contains((string) ($document['canonical_block_markup'] ?? ''), self::TOKEN_PREFIX)) foreach ($tokens as $token) if (is_string($token['token'] ?? null) && is_string($token['target_path'] ?? null)) $templateAssetTokens[self::TOKEN_PREFIX . $token['token'] . '}}'] = $token['target_path'];
        if (array() !== $templateAssetTokens) {
            $lines[] = '$blocks_engine_template_asset_tokens = ' . var_export($templateAssetTokens, true) . ';';
            $lines[] = '$blocks_engine_resolve_template_assets = static function ( string $content ) use ( $blocks_engine_template_asset_tokens ): string {';
            // A file-system path and a URL path are different encodings of the
            // same name: a literal `%` (or a `+`, which some serving layers
            // decode to a space) in a captured file name resolves on some hosts
            // and 404s on others once the server URL-decodes the request path.
            // Percent-encode every segment in the emitted URL only, leaving the
            // on-disk path -- and therefore the theme-file lookup -- untouched.
            $lines[] = "    \$references = array(); foreach ( \$blocks_engine_template_asset_tokens as \$token => \$path ) { \$uri = get_theme_file_uri( \$path ); \$references[ \$token ] = str_ends_with( \$uri, \$path ) ? substr( \$uri, 0, -strlen( \$path ) ) . implode( '/', array_map( 'rawurlencode', explode( '/', \$path ) ) ) : \$uri; }";
            $lines[] = '    return strtr( $content, $references );';
            $lines[] = '};';
            $lines[] = "add_filter( 'get_block_file_template', static function ( \$template, string \$id, string \$type ) use ( \$blocks_engine_resolve_template_assets ) { if ( \$template instanceof WP_Block_Template && \$template->has_theme_file && get_stylesheet() === \$template->theme ) \$template->content = \$blocks_engine_resolve_template_assets( \$template->content ); return \$template; }, 10, 3 );";
            $lines[] = "add_filter( 'get_block_templates', static function ( array \$templates ) use ( \$blocks_engine_resolve_template_assets ): array { foreach ( \$templates as \$template ) if ( \$template instanceof WP_Block_Template && 'theme' === \$template->source && get_stylesheet() === \$template->theme ) \$template->content = \$blocks_engine_resolve_template_assets( \$template->content ); return \$templates; }, 10, 1 );";
            $lines[] = "add_filter( 'render_block_core/template-part', static function ( string \$content ) use ( \$blocks_engine_resolve_template_assets ): string { return \$blocks_engine_resolve_template_assets( \$content ); }, 10, 1 );";
        }
        $editorStyles = array();
        $partSlugsBySource = array();
        foreach ($parts as $part) {
            $sourcePaths = is_array($part['placement']['source_paths'] ?? null) ? $part['placement']['source_paths'] : array((string) ($part['placement']['source_path'] ?? preg_replace('/#.*$/', '', (string) ($part['source_path'] ?? ''))));
            foreach ($sourcePaths as $sourcePath) if (is_string($sourcePath) && '' !== $sourcePath && '' !== (string) ($part['slug'] ?? '')) $partSlugsBySource[$sourcePath][] = (string) $part['slug'];
        }
        foreach ($assets as $asset) if ('css' === $asset['kind'] && 'frontend' !== ($asset['stylesheet_target'] ?? 'both') && !isset($importLoaded[$asset['target_path']])) {
            $partSlugs = array();
            foreach ($asset['scopes'] as $scope) foreach ($partSlugsBySource[(string) ($scope['source_path'] ?? '')] ?? array() as $slug) $partSlugs[$slug] = true;
            $editorStyles[] = array_filter(array('target_path' => $asset['target_path'], 'content_hash' => $asset['content_hash'], 'scopes' => $asset['scopes'], 'template_part_slugs' => array_keys($partSlugs), 'media' => $asset['media'] ?? null, 'author_css' => 'engine-support' !== ($asset['source'] ?? ''), 'editor_only' => 'editor' === ($asset['stylesheet_target'] ?? 'both')), static fn(mixed $value): bool => null !== $value);
        }
        if (array() !== $editorStyles) {
            $lines[] = '$blocks_engine_presentation_styles = ' . var_export($editorStyles, true) . ';';
            $lines[] = "\$blocks_engine_presentation_matches = static function ( array \$style, ?WP_Post \$post, bool \$site_editor ): bool {";
            $lines[] = "    \$matches = \$site_editor; if ( ! \$matches && \$post instanceof WP_Post ) foreach ( \$style['scopes'] as \$scope ) {";
            $lines[] = "        if ( 'wp_template_part' === \$post->post_type && in_array( basename( (string) \$post->post_name ), \$style['template_part_slugs'], true ) ) { \$matches = true; break; }";
            $lines[] = "        if ( 'global' === \$scope['kind'] ) { \$matches = true; break; }";
            $lines[] = "        if ( 'post' === \$scope['kind'] && 'post' === \$post->post_type && \$scope['reconciliation_identity'] === get_post_meta( \$post->ID, '_blocks_engine_reconciliation_identity', true ) ) { \$matches = true; break; }";
            $lines[] = "        if ( 'page' === \$scope['kind'] && 'page' === \$post->post_type ) { \$identity = get_post_meta( \$post->ID, '_blocks_engine_reconciliation_identity', true ); if ( '' !== \$identity ? \$scope['reconciliation_identity'] === \$identity : ( ( \$scope['front_page'] && (int) get_option( 'page_on_front' ) === (int) \$post->ID ) || \$scope['route_path'] === trim( get_page_uri( \$post ), '/' ) ) ) { \$matches = true; break; } }";
            $lines[] = "    }";
            $lines[] = "    return \$matches;";
            $lines[] = "};";
            // Core collects the editor canvas iframe's stylesheets by firing
            // enqueue_block_assets with should_load_block_editor_scripts_and_styles
            // forced false (_wp_get_iframed_editor_assets()); the iframe then
            // loads them by URL. Authored and editor-only CSS is enqueued only on
            // that pass, so it never styles the outer admin document and is never
            // inlined into the editor settings payload.
            $lines[] = "add_action( 'enqueue_block_assets', static function () use ( \$blocks_engine_presentation_styles, \$blocks_engine_presentation_matches ): void {";
            $lines[] = "    \$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null; \$site_editor = \$screen instanceof WP_Screen && 'site-editor' === \$screen->base; if ( ! \$site_editor && ( ! \$screen instanceof WP_Screen || ! in_array( \$screen->base, array( 'post', 'post-new' ), true ) ) ) return; \$post = \$GLOBALS['post'] ?? null;";
            $lines[] = "    \$canvas = ! wp_should_load_block_editor_scripts_and_styles();";
            $lines[] = "    foreach ( \$blocks_engine_presentation_styles as \$style ) if ( ( \$canvas || ( empty( \$style['author_css'] ) && empty( \$style['editor_only'] ) ) ) && \$blocks_engine_presentation_matches( \$style, \$post instanceof WP_Post ? \$post : null, \$site_editor ) ) wp_enqueue_style( 'blocks-engine-editor-' . substr( hash( 'sha256', \$style['target_path'] ), 0, 12 ), get_theme_file_uri( \$style['target_path'] ), array(), \$style['content_hash'], \$style['media'] ?? 'all' );";
            $lines[] = "} );";
        }
        $inlineShellSlugs = array_values(array_map(static fn(array $part): string => (string) $part['slug'], array_filter($parts, static fn(array $part): bool => 'inline_shared_shell' === ($part['placement']['kind'] ?? null))));
        if (array() !== $inlineShellSlugs) {
            $lines[] = "add_filter( 'render_block_core/template-part', static function ( string \$content, array \$block ): string {";
            $lines[] = '    $slugs = ' . var_export($inlineShellSlugs, true) . ';';
            $lines[] = "    if ( ! in_array( (string) ( \$block['attrs']['slug'] ?? '' ), \$slugs, true ) || ! preg_match( '/^<([a-z][a-z0-9-]*)\\b[^>]*>(.*)<\\/\\1>$/s', \$content, \$match ) ) return \$content;";
            $lines[] = "    return \$match[2];";
            $lines[] = "}, 10, 2 );";
        }
        $hasNavigationLink = false;
        foreach (array_merge($templates, $parts, $pages) as $document) {
            $markup = (string) ($document['canonical_block_markup'] ?? '');
            // Require the navigation container alongside a link, not merely the
            // link comment text, so the filter is only emitted where core/navigation
            // actually renders a navigation-link item: a bare "wp:navigation-link"
            // substring can appear in unrelated JSON/attribute contexts that never
            // reach render_block_core_navigation_link().
            if (str_contains($markup, 'wp:navigation-link') && str_contains($markup, 'wp:navigation ')) { $hasNavigationLink = true; break; }
        }
        if ($hasNavigationLink) {
            // core/navigation-link is fully dynamic: render_block_core_navigation_link()
            // ignores saved content and only adds aria-current="page" when its own
            // id/kind match the queried object, which never happens for the "custom"
            // kind links this engine emits. When navigation is inlined per page, the
            // current item is already marked with the blocks-engine-current-navigation-item
            // class (a respected className block support). That marker cannot work once
            // navigation is hoisted into a shared template part, because one rendered
            // part serves every route, so also recover aria-current by comparing the
            // link's own canonical route URL against the page currently being served.
            // Either signal recovers the semantic and the source's/engine's own
            // [aria-current] styling hook at render time.
            $lines[] = "add_filter( 'render_block_core/navigation-link', static function ( string \$content, array \$block ): string {";
            $lines[] = "    if ( str_contains( \$content, 'aria-current' ) ) return \$content;";
            $lines[] = "    \$current = str_contains( (string) ( \$block['attrs']['className'] ?? '' ), 'blocks-engine-current-navigation-item' );";
            $lines[] = "    if ( ! \$current ) {";
            $lines[] = "        \$url = is_string( \$block['attrs']['url'] ?? null ) ? trim( (string) \$block['attrs']['url'] ) : '';";
            $lines[] = "        if ( '' !== \$url && '/' === \$url[0] && ( 1 === strlen( \$url ) || '/' !== \$url[1] ) ) {";
            $lines[] = "            \$target = '/' . trim( (string) parse_url( \$url, PHP_URL_PATH ), '/' );";
            $lines[] = "            \$served = is_front_page() ? '/' : ( is_page() ? '/' . trim( (string) get_page_uri( get_queried_object_id() ), '/' ) : null );";
            $lines[] = "            \$current = null !== \$served && \$target === \$served;";
            $lines[] = "        }";
            $lines[] = "    }";
            $lines[] = "    if ( ! \$current ) return \$content;";
            $lines[] = "    return preg_replace( '/(<a\\b[^>]*\\bclass=\"[^\"]*\\bwp-block-navigation-item__content\\b[^\"]*\")/', '\$1 aria-current=\"page\"', \$content, 1 ) ?? \$content;";
            $lines[] = "}, 10, 2 );";
        }
        // Gutenberg pads every saved block with newlines. Imported presentation can
        // preserve white-space (Wix rich text uses break-spaces), so that padding
        // renders as blank lines once a page is saved. Drop serializer newline runs
        // at block edges and between inner blocks; text inside blocks is untouched.
        $lines[] = "add_filter( 'render_block_data', static function ( array \$block ): array {";
        $lines[] = "    \$content = \$block['innerContent'] ?? null; if ( ! is_array( \$content ) ) return \$block;";
        $lines[] = "    if ( null === ( \$block['blockName'] ?? null ) ) { if ( '' === trim( (string) ( \$block['innerHTML'] ?? '' ) ) ) { \$block['innerHTML'] = ''; \$block['innerContent'] = array(); } return \$block; }";
        $lines[] = "    \$last = count( \$content ) - 1;";
        $lines[] = "    foreach ( \$content as \$index => \$chunk ) {";
        $lines[] = "        if ( ! is_string( \$chunk ) ) continue;";
        $lines[] = "        if ( 0 === \$index || null === \$content[ \$index - 1 ] ) \$chunk = preg_replace( '/^\\s*\\n[ \\t\\r\\f]*/', '', \$chunk ) ?? \$chunk;";
        $lines[] = "        if ( \$last === \$index || null === \$content[ \$index + 1 ] ) \$chunk = preg_replace( '/[ \\t\\r\\f]*\\n\\s*\$/', '', \$chunk ) ?? \$chunk;";
        $lines[] = "        \$content[ \$index ] = \$chunk;";
        $lines[] = "    }";
        $lines[] = "    \$block['innerContent'] = \$content; return \$block;";
        $lines[] = "}, 10, 1 );";
        $lines[] = "add_filter( 'block_editor_settings_all', static function ( array \$settings ): array { \$settings['styles'][] = array( 'css' => " . var_export(self::EDITOR_CORE_IMAGE_INTERACTION_CSS . self::EDITOR_POST_TITLE_INTERACTION_CSS . self::EDITOR_LINK_INTERACTION_CSS, true) . ", '__unstableType' => 'theme' ); return \$settings; }, 20 );";
        foreach ($scripts as $script) {
            $handle = 'blocks-engine-script-' . substr(hash('sha256', $script['identity']), 0, 12);
            foreach ($script['scopes'] as $scope) {
                $condition = self::bootstrapScopeCondition($scope);
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
    // A file-system path and a URL path are different encodings of the same
    // name: browsers request exactly what the bootstrap emits and the server
    // URL-decodes the request path before touching the filesystem, so a
    // literal `%24` in an on-disk name must be emitted as `%2524` to round-trip
    // back onto the materialized file. Encode every segment, preserving the
    // `/` separators.
    private static function encodedAssetUrlPath(string $path): string { return implode('/', array_map('rawurlencode', explode('/', $path))); }
    /**
     * A stylesheet that another stylesheet loads through `@import` (a chunked
     * stylesheet's loader, or an authored import) already loads at its
     * importer's cascade position. Enqueueing it as well adds a second copy at
     * an unrelated position, which inverts the source cascade.
     *
     * @param array<int,array<string,mixed>> $assets
     * @return array<string,true> Target paths that load only through an importing stylesheet.
     */
    private static function importLoadedStylesheets(array $assets): array
    {
        $stylesheets = array();
        foreach ($assets as $asset) if ('css' === ($asset['kind'] ?? null)) $stylesheets[(string) $asset['source_path']] = true;
        $importLoaded = array();
        foreach ($assets as $asset) {
            if ('css' !== ($asset['kind'] ?? null)) continue;
            $linked = false;
            $imported = false;
            foreach (is_array($asset['references'] ?? null) ? $asset['references'] : array() as $reference) {
                if (!is_array($reference)) continue;
                if ('link' === ($reference['element'] ?? null)) $linked = true;
                $importer = (string) ($reference['source_path'] ?? '');
                if ('css-import' === ($reference['context'] ?? null) && isset($stylesheets[$importer]) && $importer !== $asset['source_path'] && $asset['source_path'] === ArtifactPath::resolveRelativePath((string) ($reference['url'] ?? ''), $importer)) $imported = true;
            }
            if ($imported && !$linked) $importLoaded[(string) $asset['target_path']] = true;
        }
        return $importLoaded;
    }
    /** @param array<string,mixed> $scope */
    private static function bootstrapScopeCondition(array $scope): string
    {
        if ('global' === ($scope['kind'] ?? null)) return 'true';
        if (!empty($scope['front_page'])) return 'is_front_page()';
        if ('post' === ($scope['kind'] ?? null)) return "is_singular( 'post' ) && " . var_export($scope['reconciliation_identity'], true) . " === get_post_meta( get_queried_object_id(), '_blocks_engine_reconciliation_identity', true )";
        return 'is_page() && ' . var_export($scope['route_path'], true) . " === trim( get_page_uri( get_queried_object_id() ), '/' )";
    }
    private static function assertAssetScopes(mixed $scopes): void
    {
        if (!is_array($scopes) || array() === $scopes) throw new InvalidArgumentException('WordPress site plan asset scopes must be a nonempty array.');
        foreach ($scopes as $scope) {
            if (!is_array($scope) || !in_array($scope['kind'] ?? null, array('global', 'page', 'post'), true)) throw new InvalidArgumentException('WordPress site plan asset scope is invalid.');
            if ('global' === $scope['kind']) {
                if (array('kind') !== array_keys($scope)) throw new InvalidArgumentException('A global asset scope cannot declare page fields.');
                continue;
            }
            if (array('kind', 'source_path', 'route_path', 'reconciliation_identity', 'front_page') !== array_keys($scope) || !self::safePath($scope['source_path']) || !is_string($scope['route_path']) || !self::hash($scope['reconciliation_identity']) || !is_bool($scope['front_page'])) throw new InvalidArgumentException('A page asset scope is structurally invalid.');
        }
    }
    /** @return array<string,mixed> */
    private function write(string $kind, string $target, string $content, ?string $sourcePath = null, string $encoding = 'utf8'): array { $sourcePath ??= 'wordpress-site-plan/' . $target; return array('kind' => $kind, 'source_path' => $sourcePath, 'target_path' => $target, 'reconciliation_identity' => self::identity('write', $sourcePath, $target), 'payload_hash' => self::contentHash($content), 'payload' => array('encoding' => $encoding, 'data' => $content)); }
    /** @param array{schema:string,id:string,bytes:int,sha256:string} $reference */
    private function referenceWrite(string $kind, string $target, string $sourcePath, array $reference): array { return array('kind' => $kind, 'source_path' => $sourcePath, 'target_path' => $target, 'reconciliation_identity' => self::identity('write', $sourcePath, $target), 'payload_hash' => self::contentHash(RuntimeDeclarations::canonicalJson($reference)), 'raw_sha256' => $reference['sha256'], 'payload' => array('encoding' => 'reference', 'reference' => $reference)); }
    /** @param array<string,mixed> $asset */
    private static function referenceBackedBinaryAsset(array $asset): bool { return !empty($asset['binary']) && 'image/svg+xml' !== strtolower((string) ($asset['mime_type'] ?? '')) && !str_ends_with(strtolower((string) ($asset['source_path'] ?? $asset['path'] ?? '')), '.svg'); }
    private static function relativePath(string $origin, string $target): string
    {
        $from = '' === $origin ? array() : explode('/', dirname($origin));
        if (array('.') === $from) $from = array();
        $to = explode('/', $target);
        while (array() !== $from && array() !== $to && $from[0] === $to[0]) { array_shift($from); array_shift($to); }
        return str_repeat('../', count($from)) . implode('/', $to);
    }
    /**
     * Canonicalize every entity binding's search markup through the same asset
     * and route projections used for its source page.
     *
     * @param array<int,array<string,mixed>> $declarations
     * @param array<int,array<string,mixed>> $routes
     * @return array<int,array<string,mixed>>
     */
    private function canonicalEntityBindings(array $declarations, AssetReferenceCanonicalizer $references, array $routes, array $pages): array
    {
        foreach ( $declarations as &$declaration ) {
            if ( ! is_array($declaration) || ! isset($declaration['payload']['entities']) || ! is_array($declaration['payload']['entities']) ) {
                continue;
            }
            foreach ( $declaration['payload']['entities'] as &$entity ) {
                if ( ! is_array($entity) || ! isset($entity['bindings']) || ! is_array($entity['bindings']) ) {
                    continue;
                }
                foreach ( $entity['bindings'] as &$binding ) {
                    if ( is_array($binding) && is_string($binding['search_block_markup'] ?? null) && is_string($binding['source_path'] ?? null) ) {
                        $sourceMarkup = $binding['search_block_markup'];
                        if (!is_array($binding['projected_anchor'] ?? null)) $binding['projected_anchor'] = array_filter(array('schema' => 'blocks-engine/projected-binding-anchor/v1', 'source_block_markup' => $sourceMarkup, 'source_occurrence' => $binding['occurrence'] ?? null, 'source_position' => $binding['position'] ?? null), static fn(mixed $value): bool => null !== $value);
                        $markup = $references->content($sourceMarkup, $binding['source_path']);
                        $binding['search_block_markup'] = $this->routeLinks($markup, $binding['source_path'], $routes);
                    }
                }
                unset($binding);
            }
            unset($entity);
        }
        unset($declaration);

        $markupBySource = array_column($pages, 'canonical_block_markup', 'source_path');
        $sourceMarkupBySource = array_column($pages, '_projected_source_block_markup', 'source_path');
        $groups = array();
        foreach ($declarations as $declarationIndex => $declaration) foreach ($declaration['payload']['entities'] ?? array() as $entityIndex => $entity) foreach (is_array($entity) ? ($entity['bindings'] ?? array()) : array() as $bindingIndex => $binding) {
            $source = $binding['source_path'] ?? null; $search = $binding['search_block_markup'] ?? null; $position = $binding['position'] ?? null;
            $markup = is_string($source) ? ($markupBySource[$source] ?? null) : null;
            if (!is_string($source) || !is_string($search) || '' === $search || !is_int($binding['occurrence'] ?? null) || $binding['occurrence'] < 1 || !is_string($markup)) throw new InvalidArgumentException('A runtime entity binding lacks an exact emitted block anchor.');
            if (is_array($position) && ('blocks-engine/runtime-binding-position/v1' !== ($position['schema'] ?? null) || !is_int($position['block_index'] ?? null) || $position['block_index'] < 0 || !is_int($position['offset'] ?? null) || $position['offset'] < 0 || !is_int($position['length'] ?? null) || $position['length'] < 1)) throw new InvalidArgumentException('A runtime entity binding has an invalid emitted block position.');
            $groups[$source . "\n" . $search][] = array('id' => $declarationIndex . ':' . $entityIndex . ':' . $bindingIndex, 'source' => $source, 'declaration' => $declarationIndex, 'entity' => $entityIndex, 'binding' => $bindingIndex, 'source_offset' => is_array($position) ? $position['offset'] : null, 'source_occurrence' => $binding['occurrence'], 'canonical_position' => false, 'markup' => $markup, 'source_markup' => $sourceMarkupBySource[$source] ?? null, 'search' => $search, 'role' => is_string($binding['role'] ?? null) ? $binding['role'] : null);
        }
        foreach ($groups as $bindings) {
            usort($bindings, static fn(array $left, array $right): int => array($left['source_offset'] ?? PHP_INT_MAX, $left['source_occurrence'], $left['declaration'], $left['entity'], $left['binding']) <=> array($right['source_offset'] ?? PHP_INT_MAX, $right['source_occurrence'], $right['declaration'], $right['entity'], $right['binding']));
            // A `commerce_collection` binding is deliberately shared, byte for
            // byte, by every product entity a detected grid covers (see
            // `CommerceFallbackReporter`): one page region, many entities, one
            // canonical claim -- not N entities racing for the same position.
            // Cluster same-position bindings so that legitimate sharing is one
            // claim consuming one canonical range, while two DIFFERENT claims
            // (any role, or a non-collection role repeating a position) still
            // fail closed exactly as before.
            $clusters = array();
            foreach ($bindings as $identity) {
                $key = is_int($identity['source_offset']) ? 'offset:' . $identity['source_offset'] : 'occurrence:' . $identity['source_occurrence'];
                $clusters[$key][] = $identity;
            }
            $claims = array();
            foreach ($clusters as $members) {
                if (1 < count($members) && array() !== array_filter($members, static fn(array $member): bool => 'commerce_collection' !== $member['role'])) throw new InvalidArgumentException('A runtime entity binding has ambiguous canonical source-page anchors.');
                $claims[] = $members;
            }
            $markup = $bindings[0]['markup']; $search = $bindings[0]['search']; $ranges = array_values(array_filter(self::blockRanges($markup), static fn(array $range): bool => $search === substr($markup, $range['offset'], $range['length'])));
            if (count($ranges) < count($claims)) throw new InvalidArgumentException('A runtime entity binding no longer identifies one exact emitted canonical block.');
            $claimedOffsets = array(); $claimedClaims = array(); $resolved = array();
            foreach ($claims as $claimIndex => $members) {
                $identity = $members[0];
                $position = $declarations[$identity['declaration']]['payload']['entities'][$identity['entity']]['bindings'][$identity['binding']]['position'] ?? null;
                if (isset($declarations[$identity['declaration']]['payload']['entities'][$identity['entity']]['bindings'][$identity['binding']]['projected_anchor']) || true !== $identity['canonical_position'] || !self::bindingPosition($position, $markup, $search)) continue;
                if (isset($claimedOffsets[$position['offset']])) throw new InvalidArgumentException('A runtime entity binding has ambiguous canonical source-page anchors.');
                $claimedOffsets[$position['offset']] = true;
                $claimedClaims[$claimIndex] = true;
                foreach ($members as $member) $resolved[] = array($member, array('offset' => $position['offset'], 'length' => $position['length']));
            }
            $remainingClaims = array_values(array_filter($claims, static fn(array $members, int $claimIndex): bool => !isset($claimedClaims[$claimIndex]), ARRAY_FILTER_USE_BOTH));
            foreach ($remainingClaims as $members) {
                $identity = $members[0];
                $anchor = $declarations[$identity['declaration']]['payload']['entities'][$identity['entity']]['bindings'][$identity['binding']]['projected_anchor'] ?? null;
                if (!is_array($anchor) || 'blocks-engine/projected-binding-anchor/v1' !== ($anchor['schema'] ?? null) || !is_string($anchor['source_block_markup'] ?? null)) throw new InvalidArgumentException('A runtime entity binding no longer identifies one exact emitted canonical block.');
                $sourceMarkup = $sourceMarkupBySource[$identity['source']] ?? null;
                if (!is_string($sourceMarkup)) throw new InvalidArgumentException('A runtime entity binding no longer identifies one exact emitted canonical block.');
                $sourceRanges = self::blockRanges($sourceMarkup); $canonicalRanges = self::blockRanges($markup);
                $sourceMatches = array_values(array_filter($sourceRanges, static fn(array $range): bool => $anchor['source_block_markup'] === substr($sourceMarkup, $range['offset'], $range['length'])));
                if (isset($anchor['source_occurrence_count']) && $anchor['source_occurrence_count'] !== count($sourceMatches)) throw new InvalidArgumentException('A runtime entity binding source anchor is ambiguous after reprojection.');
                $candidates = array(); foreach ($sourceRanges as $index => $sourceRange) { $canonicalRange = $canonicalRanges[$index] ?? null; if ($anchor['source_block_markup'] === substr($sourceMarkup, $sourceRange['offset'], $sourceRange['length']) && $anchor['source_occurrence'] === self::occurrenceAtOffset($sourceMarkup, $anchor['source_block_markup'], $sourceRange['offset']) && is_array($canonicalRange) && $search === substr($markup, $canonicalRange['offset'], $canonicalRange['length']) && !isset($claimedOffsets[$canonicalRange['offset']])) $candidates[] = array('source' => $sourceRange, 'canonical' => $canonicalRange); }
                if (array() === $candidates) {
                    $sourceMatches = array_values(array_filter(self::blockRanges($sourceMarkup), static fn(array $range): bool => $anchor['source_block_markup'] === substr($sourceMarkup, $range['offset'], $range['length'])));
                    $canonicalMatches = array_values(array_filter(self::blockRanges($markup), static fn(array $range): bool => $search === substr($markup, $range['offset'], $range['length'])));
                    $unclaimedCanonical = array_values(array_filter($canonicalMatches, static fn(array $range): bool => !isset($claimedOffsets[$range['offset']])));
                    if (1 === count($sourceMatches) && 1 === count($unclaimedCanonical)) {
                        $candidates[] = array('source' => $sourceMatches[0], 'canonical' => $unclaimedCanonical[0]);
                    } elseif (count($sourceMatches) === count($canonicalMatches) && array() !== $canonicalMatches) {
                        $occurrence = is_int($anchor['source_occurrence'] ?? null) ? $anchor['source_occurrence'] : (is_int($identity['source_occurrence'] ?? null) ? $identity['source_occurrence'] : null);
                        $index = is_int($occurrence) ? $occurrence - 1 : null;
                        if (is_int($index) && isset($sourceMatches[$index], $canonicalMatches[$index]) && !isset($claimedOffsets[$canonicalMatches[$index]['offset']])) {
                            $candidates[] = array('source' => $sourceMatches[$index], 'canonical' => $canonicalMatches[$index]);
                        }
                    }
                }
                if (1 !== count($candidates)) throw new InvalidArgumentException('A runtime entity binding no longer identifies one exact emitted canonical block.');
                $claimedOffsets[$candidates[0]['canonical']['offset']] = true;
                foreach ($members as $member) $resolved[] = array($member, $candidates[0]['canonical']);
            }
            foreach ($resolved as $resolvedEntry) {
                [$identity, $range] = $resolvedEntry;
                if (!is_array($range)) throw new InvalidArgumentException('A runtime entity binding no longer identifies an emitted canonical block.');
                $canonical = substr($markup, $range['offset'], $range['length']);
                $blockIndex = array_search($range, self::blockRanges($markup), true);
                if (!is_string($canonical) || '' === $canonical || !is_int($blockIndex)) throw new InvalidArgumentException('A runtime entity binding resolved to empty canonical block markup.');
                $binding = &$declarations[$identity['declaration']]['payload']['entities'][$identity['entity']]['bindings'][$identity['binding']];
                $binding['search_block_markup'] = $canonical;
                $binding['occurrence'] = self::occurrenceAtOffset($markup, $canonical, $range['offset']);
                $binding['position'] = array('schema' => 'blocks-engine/runtime-binding-position/v1', 'block_index' => $blockIndex, 'offset' => $range['offset'], 'length' => $range['length']);
                unset($binding);
            }
        }

        // Rewriting binding markup changes the payload, so drop the derived
        // hashes and re-normalize to recompute canonical identity and content
        // hashes; the reconciliation identity (source path + kind) is stable.
        foreach ( $declarations as &$declaration ) {
            if ( is_array($declaration) ) {
                foreach ($declaration['payload']['entities'] ?? array() as &$entity) foreach ($entity['bindings'] ?? array() as &$binding) unset($binding['_canonical_position']);
                unset($binding, $entity);
                unset($declaration['payload_hash'], $declaration['content_hash']);
            }
        }
        unset($declaration);

        return RuntimeDeclarations::normalizeList($declarations);
    }

    /** @internal Exposed for ShellExtraction, which canonicalizes shell-candidate links through the same route table as page content. @param array<int,array<string,mixed>> $routes */
    public function routeLinks(string $content, string $origin, array $routes): string
    {
        $replace = fn(array $match): string => $match[1] . ($this->routeReference($match[2], $origin, $routes) ?? $match[2]) . $match[3];
        $content = preg_replace_callback('/(\b(?:href|action|data-[a-z0-9_-]*url)\s*=\s*["\'])([^"\']+)(["\'])/i', $replace, $content) ?? $content;
        // Companion block attributes carry editable HTML as a JSON string, so
        // route-bearing attributes use escaped quotes rather than HTML quotes.
        $content = preg_replace_callback('/(\b(?:href|action|data-[a-z0-9_-]*url)\s*=\s*\\\\")([^"\\\\]*)(\\\\")/i', $replace, $content) ?? $content;
        $content = preg_replace_callback('/(\b(?:href|action|data-[a-z0-9_-]*url)\s*=\s*\\\\u0022)(.*?)(\\\\u0022)/i', $replace, $content) ?? $content;
        $jsonPattern = '/(["\'](?:url|href|action)["\']\s*:\s*["\'])([^"\']+)(["\'])/i';
        $offset = 0;
        while (preg_match($jsonPattern, $content, $match, PREG_OFFSET_CAPTURE, $offset)) {
            if (null !== $this->routeReference($match[2][0], $origin, $routes)) {
                $content = preg_replace_callback($jsonPattern, $replace, $content) ?? $content;
                break;
            }
            $offset = $match[0][1] + strlen($match[0][0]);
        }
        return $this->unresolvedNavigationLinks($content, $origin, $routes);
    }
    /**
     * A document-relative a/area href that names no artifact route cannot stay
     * in the plan: WordPress would resolve it against the imported page URL
     * (issue #636). It names a page on the source site, so point it there when
     * the artifact records its source URL, or neutralize it to a same-page
     * fragment otherwise, and report the outcome instead of rejecting the whole
     * plan. Asset references (src, srcset, CSS url(), link href) stay on the
     * declared-token path and remain rejected by assertNoLocalBrowserReferences.
     *
     * @param array<int,array<string,mixed>> $routes
     */
    private function unresolvedNavigationLinks(string $content, string $origin, array $routes): string
    {
        if (!preg_match('~(?:<|\\\\u003c)(?:a|area)\b~i', $content)) return $content;
        return preg_replace_callback('~(?:<|\\\\u003c)(?:a|area)(?=[\s/>\\\\])(?:[^>\\\\]|\\\\(?!u003e))*~i', function (array $tag) use ($origin, $routes): string {
            $replace = function (array $match) use ($origin, $routes): string {
                $resolved = $this->unresolvedNavigationReference($match[2], $origin, $routes);
                return null === $resolved ? $match[0] : $match[1] . $resolved . $match[3];
            };
            $markup = $tag[0];
            foreach (array('~((?<![\w:-])href\s*=\s*")([^"]*)(")~i', "~((?<![\\w:-])href\\s*=\\s*')([^']*)(')~i", '~((?<![\w:-])href\s*=\s*\\\\")([^"\\\\]*)(\\\\")~i', '~((?<![\w:-])href\s*=\s*\\\\u0022)(.*?)(\\\\u0022)~i') as $pattern) $markup = preg_replace_callback($pattern, $replace, $markup) ?? $markup;
            return $markup;
        }, $content) ?? $content;
    }
    /** @param array<int,array<string,mixed>> $routes */
    private function unresolvedNavigationReference(string $value, string $origin, array $routes): ?string
    {
        $url = str_replace('\\/', '/', trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ('' === $url || str_starts_with($url, self::TOKEN_PREFIX) || preg_match('~^(?:[a-z][a-z0-9+.-]*:|/|#|\?)~i', $url)) return null;
        $path = $url; $suffix = '';
        if (preg_match('/^([^?#]*)(.*)$/s', $url, $parts)) { $path = $parts[1]; $suffix = $parts[2]; }
        if (null !== $this->routeReference($url, $origin, $routes)) return null;
        $absolute = $this->sourceDocumentReference($path, $origin, $routes);
        $key = $origin . "\0" . $url;
        if (!isset($this->unresolvedNavigationDiagnostics[$key])) {
            if (count($this->unresolvedNavigationDiagnostics) >= self::MAX_UNRESOLVED_NAVIGATION_DIAGNOSTICS) $this->omittedUnresolvedNavigationDiagnostics++;
            else $this->unresolvedNavigationDiagnostics[$key] = array_filter(array(
                'code' => 'wordpress_site_plan_unresolved_navigation_link',
                'severity' => 'warning',
                'message' => substr(null === $absolute ? "Neutralized a link to {$url}, which names no artifact page and has no recorded source URL." : "Linked {$url} to the source site because it names no artifact page.", 0, 256),
                'source_path' => substr($origin, 0, 256),
                'value' => substr($url, 0, 256),
                'resolution' => null === $absolute ? 'neutralized' : 'source_url',
                'resolved_url' => null === $absolute ? null : substr($absolute . $suffix, 0, 512),
                'reason_code' => 'unresolved_local_browser_reference',
            ), static fn(mixed $field): bool => null !== $field);
        }
        // Keep the authored query/fragment bytes so their context encoding survives.
        return null === $absolute ? '#' : htmlspecialchars($absolute, ENT_QUOTES | ENT_HTML5, 'UTF-8', false) . (preg_match('/[?#].*$/s', $value, $rawSuffix) ? $rawSuffix[0] : '');
    }
    /**
     * Resolves a document-relative path against the source URL of the page that
     * declared it. The recorded source URL locates the entrypoint; every other
     * document keeps its path relative to the entrypoint directory.
     *
     * @param array<int,array<string,mixed>> $routes
     */
    private function sourceDocumentReference(string $path, string $origin, array $routes): ?string
    {
        $source = '' !== $this->sourceUrl ? parse_url($this->sourceUrl) : false;
        if (!is_array($source) || str_contains($path, '\\')) return null;
        $entry = ''; foreach ($routes as $route) if (is_array($route) && !empty($route['entrypoint']) && is_string($route['source_path'] ?? null)) { $entry = $route['source_path']; break; }
        $entryRoot = self::entryRootFromDocuments($routes);
        if ('' !== $entryRoot && !str_starts_with($origin, $entryRoot . '/')) return null;
        $basePath = (string) ($source['path'] ?? '/');
        // The source URL names the entrypoint; an index entry is served as its directory.
        if (!str_ends_with($basePath, '/')) $basePath = str_starts_with(strtolower(basename($entry)), 'index.') && strtolower(basename($basePath)) !== strtolower(basename($entry)) ? $basePath . '/' : dirname($basePath) . '/';
        $documentDirectory = dirname('' === $entryRoot ? $origin : substr($origin, strlen($entryRoot) + 1));
        $segments = array();
        foreach (explode('/', $basePath . ('.' === $documentDirectory ? '' : $documentDirectory . '/') . $path) as $index => $segment) {
            if ('..' === $segment) { array_pop($segments); continue; }
            if ('.' !== $segment && ('' !== $segment || 0 === $index)) $segments[] = $segment;
        }
        $resolvedPath = '/' . ltrim(implode('/', $segments), '/') . (preg_match('~(?:^|/)\.{0,2}$~', $path) && '' !== $path ? '/' : '');
        if ('//' === $resolvedPath) $resolvedPath = '/';
        return strtolower((string) $source['scheme']) . '://' . $source['host'] . (isset($source['port']) ? ':' . $source['port'] : '') . $resolvedPath;
    }
    /** @return array<int,array<string,mixed>> */
    private function unresolvedNavigationDiagnostics(): array
    {
        $diagnostics = array_values($this->unresolvedNavigationDiagnostics);
        if ($this->omittedUnresolvedNavigationDiagnostics > 0) $diagnostics[] = array('code' => 'wordpress_site_plan_unresolved_navigation_link', 'severity' => 'warning', 'message' => sprintf('%d more unresolved navigation links were resolved or neutralized; omitted from this diagnostic list.', $this->omittedUnresolvedNavigationDiagnostics), 'reason' => 'truncated', 'omitted_count' => $this->omittedUnresolvedNavigationDiagnostics);
        return $diagnostics;
    }
    /** @param array<int,array<string,mixed>> $routes */
    private function routeReference(string $value, string $origin, array $routes): ?string
    {
        $cacheKey = $origin . "\0" . $value;
        if (array_key_exists($cacheKey, $this->routeReferenceCache)) {
            return false === $this->routeReferenceCache[$cacheKey] ? null : $this->routeReferenceCache[$cacheKey];
        }
        $resolved = $this->resolveRouteReference($value, $origin, $routes);
        $this->routeReferenceCache[$cacheKey] = $resolved ?? false;
        return $resolved;
    }
    /** @param array<int,array<string,mixed>> $routes */
    private function resolveRouteReference(string $value, string $origin, array $routes): ?string
    {
        if ('' === $value || preg_match('~^(?://|#|\?)~', $value)) return null;
        $suffix = ''; if (preg_match('/^([^?#]*)(.*)$/', $value, $match)) { $value = $match[1]; $suffix = $match[2]; }
        if (('.' === $value || './' === $value) && '' !== $suffix) return $this->routeSources[$origin] ?? null ? $this->routeSources[$origin] . $suffix : null;
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $value)) {
            if (!$this->isSameOriginSourceUrl($value)) return null;
            $absolute = parse_url($value);
            $value = is_array($absolute) && is_string($absolute['path'] ?? null) && '' !== $absolute['path'] ? $absolute['path'] : '/';
        }
        if (str_contains($value, '\\')) return null;
        if (str_starts_with($value, '/')) {
            $routePath = '/' . trim($value, '/');
            if ('/' === $value) $routePath = '/';
            if (isset($this->routeTargets[$routePath])) return $this->routeTargets[$routePath] . $suffix;
        }
        // A root-relative link (e.g. /contact.html) targets the site web root,
        // which is the entrypoint's packaging directory. Resolve it against that
        // root so it matches the document source path (website/contact.html)
        // rather than a bare top-level path the artifact never contains.
        $entryRoot = self::entryRootFromDocuments($routes);
        $path = str_starts_with($value, '/') ? ('' === $entryRoot ? ltrim($value, '/') : $entryRoot . '/' . ltrim($value, '/')) : self::resolveRouteSource($origin, $value);
        if (null === $path) return null;
        $resolved = $this->routeSourceDocument($path);
        // Capture layers write encoded punctuation (`%E2%80%99`) literally into
        // exported file names, so a percent-carrying reference usually matches an
        // artifact path byte for byte. When it does not, retry the spelling a
        // standards-compliant author encoded: decode each segment once, on the same
        // safety rule as `decodedRouteSegment()` — a decode must never give the path
        // structure the raw reference did not already have, so encoded separators,
        // dot segments, and NUL leave the reference unresolved instead of decoded.
        if (null === $resolved && str_contains($path, '%')) { $decodedPath = self::decodedRouteReferencePath($path); if (null !== $decodedPath && $decodedPath !== $path) $resolved = $this->routeSourceDocument($decodedPath); }
        return null === $resolved ? null : $resolved . $suffix;
    }
    private function routeSourceDocument(string $path): ?string
    {
        if (isset($this->routeSources[$path])) return $this->routeSources[$path];
        // A directory reference (interactive/, ../) names its index document.
        foreach (array('index.html', 'index.htm') as $index) { $indexPath = ('' === $path ? '' : rtrim($path, '/') . '/') . $index; if (isset($this->routeSources[$indexPath])) return $this->routeSources[$indexPath]; }
        return null;
    }
    private static function decodedRouteReferencePath(string $path): ?string { $segments = array(); foreach (explode('/', $path) as $segment) { if (!str_contains($segment, '%')) { $segments[] = $segment; continue; } $decoded = rawurldecode($segment); if ('.' === $decoded || '..' === $decoded || preg_match('~[/\\\\\x00]~', $decoded)) return null; $segments[] = $decoded; } return implode('/', $segments); }
    /** @param array<int,mixed> $provenance */
    private function sourceUrlFromProvenance(array $provenance): string
    {
        foreach ($provenance as $entry) {
            if (!is_array($entry) || !is_string($entry['source_url'] ?? null)) continue;
            $url = trim($entry['source_url']);
            $parts = parse_url($url);
            if (is_array($parts) && in_array(strtolower((string) ($parts['scheme'] ?? '')), array( 'http', 'https' ), true) && '' !== (string) ($parts['host'] ?? '') && !isset($parts['user'], $parts['pass'])) return $url;
        }
        return '';
    }
    private function isSameOriginSourceUrl(string $url): bool
    {
        return '' !== $this->sourceOrigin && $this->sourceOrigin === $this->urlOrigin($url);
    }
    private function urlOrigin(string $url): string
    {
        $parts = '' !== $url ? parse_url($url) : false;
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), array( 'http', 'https' ), true) || '' === (string) ($parts['host'] ?? '') || isset($parts['user'], $parts['pass'])) return '';
        $scheme = strtolower((string) $parts['scheme']);
        return $scheme . '://' . strtolower((string) $parts['host']) . ':' . (string) ($parts['port'] ?? ('https' === $scheme ? 443 : 80));
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
        $scriptLoading = (new self())->scriptLoading($plan['pages'], $plan['template_parts'], $plan['assets'], $plan['reference_tokens'], $plan['operations'], $plan['runtime_declarations']);
        if (!is_array($bootstrap) || 'theme_bootstrap' !== ($bootstrap['kind'] ?? null) || 'wordpress-site-plan/functions.php' !== ($bootstrap['source_path'] ?? null) || self::bootstrap($plan['assets'], $scriptLoading['scripts'], $plan['template_parts'], $plan['reference_tokens'], $plan['templates'], $plan['pages']) !== ($bootstrap['payload']['data'] ?? null)) throw new InvalidArgumentException('WordPress site plan functions.php bootstrap is invalid.');
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
            if ('create_page' === ($operation['kind'] ?? null)) { $page = $pagesBySource[$operation['source_path'] ?? ''] ?? null; if (!is_array($page) || $page['reconciliation_identity'] !== ($operation['reconciliation_identity'] ?? null) || (isset($operation['post_type']) && $page['post_type'] !== $operation['post_type']) || $page['route']['path'] !== ($operation['route_path'] ?? null) || $page['slug'] !== ($operation['slug'] ?? null) || $page['parent_source_path'] !== ($operation['parent_source_path'] ?? null) || !is_bool($operation['synthetic'] ?? null) || ('' !== $page['parent_source_path'] && !isset($created[$page['parent_source_path']]))) throw new InvalidArgumentException('WordPress site plan create_page operation is invalid.'); $created[$page['source_path']] = true; continue; }
            if ('site_reading' !== ($operation['kind'] ?? null) || ++$reading > 1 || 'page' !== ($operation['show_on_front'] ?? null) || !is_string($operation['front_page_source_path'] ?? null) || !is_string($operation['front_page_reconciliation_identity'] ?? null)) throw new InvalidArgumentException('WordPress site plan operation is invalid.');
            $page = $pagesBySource[$operation['front_page_source_path']] ?? null; if (!is_array($page) || empty($page['entrypoint']) || $page['reconciliation_identity'] !== $operation['front_page_reconciliation_identity'] || !isset($created[$page['source_path']])) throw new InvalidArgumentException('WordPress site plan operation references an invalid front page.');
        }
        if (count($created) !== count($pages) || $reading !== (array() === array_filter($pages, static fn(array $page): bool => !empty($page['entrypoint'])) ? 0 : 1)) throw new InvalidArgumentException('WordPress site plan operations are incomplete.');
    }
    /**
     * Validates a text write in the grammar its target declares. A JSON data
     * file is not markup: quotes inside its strings are JSON-escaped, so reading
     * the raw file as HTML turns `href=\"https://…\"` into a bogus local
     * reference. Decode it and validate each markup-bearing string instead.
     */
    private static function assertNoLocalBrowserReferencesInWrite(string $targetPath, string $payload, string $sourcePath): void
    {
        $target = strtolower($targetPath);
        $decoded = str_ends_with($target, '.json') ? json_decode($payload, true) : null;
        if (!is_array($decoded)) {
            self::assertNoLocalBrowserReferences(str_ends_with($target, '.css') ? '<style>' . $payload . '</style>' : $payload, $sourcePath, 'write');
            return;
        }
        array_walk_recursive($decoded, static function (mixed $value) use ($sourcePath): void {
            if (is_string($value) && str_contains($value, '<')) self::assertNoLocalBrowserReferences($value, $sourcePath, 'write:json');
        });
    }
    private static function assertNoLocalBrowserReferences(string $content, string $sourcePath = '', string $context = 'markup'): void
    {
        $assertReference = static function (string $candidate, string $attribute, string $element = '') use ($sourcePath, $context): void { $url = trim(preg_split('/\s+/', trim(html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8')))[0] ?? ''); $route = str_starts_with($url, '/') && (str_starts_with($attribute, 'json:route_') || ('href' === $attribute && in_array($element, array('a', 'area'), true)) || ('action' === $attribute && 'form' === $element)); if ('' !== $url && !str_starts_with($url, self::TOKEN_PREFIX) && !$route && !preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#|\?)~i', $url)) throw new ValidationException(sprintf('WordPress site plan contains unresolved local browser reference %s.', $url), array('source_path' => $sourcePath, 'document_kind' => $context, 'declaration_kind' => 'browser_reference', 'declaration_index' => 0, 'reason' => 'unresolved_local_browser_reference', 'fields' => array('context' => $context, 'attribute' => $attribute, 'value' => $url))); };
        $assertCss = static function (string $css, string $cssContext) use ($assertReference): void { \Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\CssUrlRewriter::rewrite(html_entity_decode($css, ENT_QUOTES | ENT_HTML5, 'UTF-8'), static function (string $url) use ($assertReference, $cssContext): string { $assertReference($url, $cssContext . ':url'); return $url; }); if (preg_match_all('/@import\s+(?:url\(\s*)?(?:"([^"]*)"|\'([^\']*)\'|([^\s\)"\';]+))/i', html_entity_decode($css, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $matches, PREG_SET_ORDER)) foreach ($matches as $match) $assertReference((string) (($match[1] ?? '') ?: ($match[2] ?? '') ?: ($match[3] ?? '')), $cssContext . ':@import'); };
        $assertJsonAttributes = null;
        $assertJsonAttributes = static function (array $attributes, bool $route) use (&$assertJsonAttributes, $assertReference, $sourcePath, $context): void {
            foreach ($attributes as $name => $value) {
                if (is_array($value)) { $assertJsonAttributes($value, $route); continue; }
                // Dynamic companion blocks carry editable markup in a string
                // attribute. Validate its browser references just as strictly as
                // native saved markup instead of treating it as opaque JSON.
                if ('content' === strtolower((string) $name) && is_string($value) && str_contains($value, '<')) {
                    self::assertNoLocalBrowserReferences($value, $sourcePath, $context . ':companion_content');
                    continue;
                }
                if (!is_string($name) || !is_string($value) || !in_array(strtolower($name), array('url', 'src', 'href', 'poster', 'action', 'srcset'), true)) continue;
                $routeField = $route && 'url' === strtolower($name) ? 'route_url' : (in_array(strtolower($name), array('href', 'action'), true) ? 'route_' . strtolower($name) : strtolower($name));
                foreach ('srcset' === strtolower($name) ? self::srcsetCandidates($value) : array($value) as $candidate) $assertReference($candidate, 'json:' . $routeField);
            }
        };
        foreach (self::htmlMarkupNodes($content) as $node) {
            if ('tag' === $node['kind']) foreach ($node['attributes'] as $name => $value) {
                if (!in_array($name, array('xlink:href', 'srcset', 'src', 'href', 'poster', 'action', 'style'), true)) continue;
                if ('action' === $name && 'form' !== $node['name']) continue;
                if ('style' === $name) { $assertCss($value, 'style_attribute'); continue; }
                foreach ('srcset' === $name ? self::srcsetCandidates($value) : array($value) as $candidate) $assertReference($candidate, $name, $node['name']);
            }
            if ('style' === $node['kind']) $assertCss($node['css'], 'style_block');
            if ('comment' === $node['kind'] && preg_match('~^\s*wp:~i', $node['content'])) {
                $attributes = self::blockCommentAttributes($node['content']);
                if (is_array($attributes)) {
                    $assertJsonAttributes($attributes, self::jsonUrlIsRoute($node['content']));
                } elseif (preg_match_all('~(?:"|\\\\u0022)(url|src|href|poster|action|srcset)(?:"|\\\\u0022)\s*:\s*(?:"|\\\\u0022)(.*?)(?:"|\\\\u0022)~is', $node['content'], $fields, PREG_SET_ORDER)) {
                    $route = self::jsonUrlIsRoute($node['content']);
                    foreach ($fields as $field) {
                        $name = strtolower($field[1]);
                        $routeField = $route && 'url' === $name ? 'route_url' : (in_array($name, array('href', 'action'), true) ? 'route_' . $name : $name);
                        foreach ('srcset' === $name ? self::srcsetCandidates($field[2]) : array($field[2]) as $candidate) $assertReference(str_replace('\\/', '/', (string) $candidate), 'json:' . $routeField);
                    }
                }
            }
        }
    }
    /** @return array<string,mixed>|null */
    private static function blockCommentAttributes(string $comment): ?array { if (!preg_match('~^\s*wp:[^\s{]+\s+(\{.*\})\s*/?\s*$~s', $comment, $payload)) return null; $attributes = json_decode($payload[1], true); return is_array($attributes) ? $attributes : null; }
    private static function jsonUrlIsRoute(string $comment): bool { if (!preg_match('~^\s*wp:([^\s{]+)~i', $comment, $block)) return false; return in_array(strtolower($block[1]), self::ROUTE_URL_BLOCKS, true); }
    /** @return array<int,string> */
    private static function srcsetCandidates(string $srcset): array
    {
        return SrcsetParser::urls($srcset);
    }
    /** @return array<int,array<string,mixed>> */
    private static function htmlMarkupNodes(string $content): array
    {
        $nodes = array(); $length = strlen($content); $offset = 0;
        while ($offset < $length) {
            $start = strpos($content, '<', $offset); if (false === $start) break;
            if (str_starts_with(substr($content, $start), '<!--')) { $end = strpos($content, '-->', $start + 4); if (false === $end) break; $nodes[] = array('kind' => 'comment', 'content' => substr($content, $start + 4, $end - $start - 4)); $offset = $end + 3; continue; }
            if ($start + 1 < $length && '!' === $content[$start + 1]) { if (str_starts_with(substr($content, $start), '<![CDATA[')) { $end = strpos($content, ']]>', $start + 9); $offset = false === $end ? $length : $end + 3; continue; } $cursor = $start + 2; $quote = ''; while ($cursor < $length) { if ('' !== $quote) { if ($quote === $content[$cursor]) $quote = ''; ++$cursor; continue; } if ('"' === $content[$cursor] || "'" === $content[$cursor]) { $quote = $content[$cursor++]; continue; } if ('>' === $content[$cursor++]) break; } $offset = $cursor; continue; }
            $cursor = $start + 1; if ($cursor >= $length || !ctype_alpha($content[$cursor])) { $offset = $cursor; continue; }
            $nameStart = $cursor; while ($cursor < $length && preg_match('/[A-Za-z0-9:-]/', $content[$cursor])) ++$cursor;
            $name = strtolower(substr($content, $nameStart, $cursor - $nameStart)); $attributes = array();
            while ($cursor < $length) {
                while ($cursor < $length && ctype_space($content[$cursor])) ++$cursor;
                if ($cursor >= $length) break;
                if ('>' === $content[$cursor] || ('/' === $content[$cursor] && $cursor + 1 < $length && '>' === $content[$cursor + 1])) { $cursor += '>' === $content[$cursor] ? 1 : 2; $nodes[] = array('kind' => 'tag', 'name' => $name, 'attributes' => $attributes); if ('style' === $name) { $closing = self::rawTextEnd($content, $name, $cursor); if (null !== $closing) { $nodes[] = array('kind' => 'style', 'css' => substr($content, $cursor, $closing[0] - $cursor)); $offset = $closing[1]; } else { $nodes[] = array('kind' => 'style', 'css' => substr($content, $cursor)); $offset = $length; } continue 2; } if ('plaintext' === $name) { $offset = $length; continue 2; } if (in_array($name, array('script', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript'), true)) { $closing = self::rawTextEnd($content, $name, $cursor); if ('script' === $name && null !== $closing) $nodes[] = array('kind' => 'rawtext', 'name' => $name, 'attributes' => $attributes, 'content' => substr($content, $cursor, $closing[0] - $cursor)); $offset = null === $closing ? $length : $closing[1]; continue 2; } $offset = $cursor; continue 2; }
                $attributeStart = $cursor; while ($cursor < $length && !ctype_space($content[$cursor]) && !str_contains('=/>', $content[$cursor])) ++$cursor;
                if ($attributeStart === $cursor) { ++$cursor; continue; }
                $attribute = strtolower(substr($content, $attributeStart, $cursor - $attributeStart)); while ($cursor < $length && ctype_space($content[$cursor])) ++$cursor;
                if ($cursor >= $length || '=' !== $content[$cursor]) { if (!array_key_exists($attribute, $attributes)) $attributes[$attribute] = ''; continue; }
                ++$cursor; while ($cursor < $length && ctype_space($content[$cursor])) ++$cursor;
                if ($cursor >= $length) { if (!array_key_exists($attribute, $attributes)) $attributes[$attribute] = ''; break; }
                if ('"' === $content[$cursor] || "'" === $content[$cursor]) { $quote = $content[$cursor++]; $valueStart = $cursor; while ($cursor < $length && $quote !== $content[$cursor]) ++$cursor; if (!array_key_exists($attribute, $attributes)) $attributes[$attribute] = substr($content, $valueStart, $cursor - $valueStart); if ($cursor < $length) ++$cursor; continue; }
                $valueStart = $cursor; while ($cursor < $length && !ctype_space($content[$cursor]) && '>' !== $content[$cursor]) ++$cursor; if (!array_key_exists($attribute, $attributes)) $attributes[$attribute] = substr($content, $valueStart, $cursor - $valueStart);
            }
            $nodes[] = array('kind' => 'tag', 'name' => $name, 'attributes' => $attributes); $offset = $cursor;
        }
        return $nodes;
    }
    /** @return array{0:int,1:int}|null */
    private static function rawTextEnd(string $content, string $name, int $offset): ?array
    {
        if (!preg_match('~</' . preg_quote($name, '~') . '(?=[\s/>])[^>]*>~i', $content, $match, PREG_OFFSET_CAPTURE, $offset)) return null;
        return array($match[0][1], $match[0][1] + strlen($match[0][0]));
    }
    /** @param array<string,bool> $tokens @param array<string,array<string,mixed>> $writes */
    private static function assertResolution(array $plan, array $tokens, array $writes): void
    {
        if (!isset($plan['resolution'])) return;
        $resolution = $plan['resolution'];
        if (!is_array($resolution) || array_keys($resolution) !== array('schema', 'theme_uri', 'runtime_capabilities', 'asset_publication_references', 'unsupported_optional_capabilities') || WordPressSitePlanResolver::RESOLUTION_SCHEMA !== ($resolution['schema'] ?? null) || !is_string($resolution['theme_uri'] ?? null) || !is_array($resolution['runtime_capabilities'] ?? null) || !is_array($resolution['asset_publication_references'] ?? null) || !is_array($resolution['unsupported_optional_capabilities'] ?? null) || WordPressSitePlanResolver::normalizeThemeUri($resolution['theme_uri']) !== $resolution['theme_uri']) throw new InvalidArgumentException('WordPress site plan resolution is malformed or fabricated.');
        $references = WordPressSitePlanResolver::references($plan['reference_tokens'], $resolution['theme_uri']);
        $expectedPublicationReferences = WordPressSitePlanResolver::publicationReferences($plan['runtime_declarations'], $plan['reference_tokens'], $plan['writes'], $resolution['theme_uri']);
        try { $capabilities = WordPressSitePlanResolver::normalizeRuntimeCapabilities($resolution['runtime_capabilities']); $unsupported = WordPressSitePlanResolver::unsupportedOptionalCapabilities($plan['runtime_declarations'], $capabilities); } catch (InvalidArgumentException) { throw new InvalidArgumentException('WordPress site plan publication resolution is malformed or stale.'); }
        if ($resolution['runtime_capabilities'] !== $capabilities || $resolution['asset_publication_references'] !== $expectedPublicationReferences || $resolution['unsupported_optional_capabilities'] !== $unsupported) throw new InvalidArgumentException('WordPress site plan publication resolution is malformed or stale.');
        foreach (array('pages', 'template_parts', 'templates') as $kind) foreach ($plan[$kind] as $document) {
            if (!is_array($document) || !is_string($document['canonical_block_markup'] ?? null) || !is_string($document['resolved_block_markup'] ?? null) || WordPressSitePlanResolver::resolvePayload($document['canonical_block_markup'], $references) !== $document['resolved_block_markup']) throw new InvalidArgumentException("WordPress site plan resolved {$kind} payload is not canonical.");
        }
        foreach ($writes as $write) {
            if ('utf8' !== ($write['payload']['encoding'] ?? null)) { if (isset($write['canonical_payload'], $write['canonical_payload_hash'])) throw new InvalidArgumentException('WordPress site plan binary write cannot carry a resolution projection.'); continue; }
            if (!is_string($write['canonical_payload'] ?? null) || !self::hash($write['canonical_payload_hash'] ?? null) || $write['canonical_payload_hash'] !== self::contentHash($write['canonical_payload']) || WordPressSitePlanResolver::resolveWritePayload($write['canonical_payload'], $plan['reference_tokens'], $resolution['theme_uri'], $write['target_path']) !== $write['payload']['data']) throw new InvalidArgumentException('WordPress site plan resolved write payload is not canonical.');
            self::assertNoLocalBrowserReferencesInWrite($write['target_path'], $write['canonical_payload'], $write['source_path']);
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
    /**
     * A page keeps its derived route, or the deterministic `-2`, `-3` variant a
     * route collision gave it (see {@see canonicalRoutes()}). Validation cannot
     * recompute which page won the collision without the whole document order,
     * so it pins the weaker but checkable property — the route is the derived
     * one or a numbered variant of it — while route uniqueness is asserted
     * across the page set.
     */
    private static function isDerivedRoute(string $path, string $expected): bool
    {
        if ($path === $expected) return true;
        $base = '/' === $expected ? '/index' : $expected;
        return 1 === preg_match('~^' . preg_quote($base, '~') . '-([0-9]+)$~', $path, $suffix) && (int) $suffix[1] >= 2;
    }
    private static function assertRoute(array $page, string $entryRoot = ''): void { $route = $page['route'] ?? null; $expected = is_string($page['metadata']['route_path'] ?? null) && '' !== $page['metadata']['route_path'] ? self::canonicalRoutePath($page['metadata']['route_path']) : self::pageRoutePath($page['source_path'], $entryRoot); if (!is_array($route) || !is_string($route['path'] ?? null) || !preg_match('~^/(?:[a-z0-9-]+(?:/[a-z0-9-]+)*)?$~', $route['path']) || !is_string($route['parent_path'] ?? null) || !is_string($route['slug'] ?? null) || self::parentRoutePath($route['path']) !== $route['parent_path'] || self::routeSlug($route['path']) !== $route['slug'] || (!isset($page['synthetic']) && !self::isDerivedRoute($route['path'], $expected)) || (isset($page['synthetic']) && (true !== $page['synthetic'] || !str_starts_with((string) ($page['source_path'] ?? ''), 'wordpress-site-plan/routes/')))) throw new InvalidArgumentException('WordPress site plan page route is invalid.'); }
    /** @param array<string,string> $tokens */
    private static function assertDocument(mixed $document, string $kind, bool $part, array $tokens): void { if(!is_array($document)||!self::safePath($document['source_path']??null)||!is_string($document['slug']??null)||!is_string($document['title']??null)||!is_string($document['post_type']??null)||!is_string($document['parent_source_path']??null)||!is_bool($document['entrypoint']??null)||!is_string($document['canonical_block_markup']??null)||''===trim($document['canonical_block_markup'])||!is_array($document['metadata']??null)||!is_array($document['document_metadata']??null)||!is_array($document['provenance']??null)||!self::hash($document['reconciliation_identity']??null)||!self::hash($document['content_hash']??null)||($part&&(!is_string($document['area']??null)||''===$document['area']||!is_array($document['placement']??null)))||(!$part&&(null!==($document['area']??null)||null!==($document['placement']??null))))throw new InvalidArgumentException("WordPress site plan {$kind} is structurally invalid.");if($part&&$document['reconciliation_identity']!==self::identity('template-part',$document['source_path'],'parts/'.$document['slug'].'.html'))throw new InvalidArgumentException('WordPress site plan template part identity is invalid.');if($part&&in_array($document['placement']['kind']??null,array('entry_shell','shared_shell'),true)&&(!is_string($document['placement']['source_path']??null)||!is_array($document['placement']['template_slugs']??null)||array()=== $document['placement']['template_slugs']))throw new InvalidArgumentException('WordPress site plan template part placement is invalid.');if(!$part)self::assertContentDecision($document);self::assertDocumentMetadata($document['document_metadata'],$tokens,$document['source_path'],$kind);self::assertTokens($document['canonical_block_markup'],$tokens);self::assertNoLocalBrowserReferences($document['canonical_block_markup'],$document['source_path'],$kind); }
    /** @param array<string,mixed> $document */
    private static function assertContentDecision(array $document): void
    {
        $decision = $document['content_decision'] ?? null;
        if (null === $decision && !array_key_exists('publication_timestamp', $document)) return;
        if (!is_array($decision) || 'blocks-engine/content-decision/v1' !== ($decision['schema'] ?? null) || !in_array($decision['state'] ?? null, array('declared', 'inferred', 'defaulted'), true) || !is_string($decision['post_type'] ?? null) || $decision['post_type'] !== $document['post_type'] || !is_array($decision['evidence'] ?? null) || count($decision['evidence']) > 16) throw new InvalidArgumentException('WordPress site plan content decision is invalid.');
        $provenance = $decision['provenance'] ?? null;
        if (('declared' === $decision['state'] && (!is_string($provenance) || !preg_match('/^(?:frontmatter|metadata):[a-z_]+$/', $provenance))) || ('declared' !== $decision['state'] && null !== $provenance) || ('defaulted' === $decision['state'] && array() !== $decision['evidence']) || ('inferred' === $decision['state'] && array() === $decision['evidence'])) throw new InvalidArgumentException('WordPress site plan content decision provenance is invalid.');
        $timestamps = array(); foreach ($decision['evidence'] as $evidence) { if (!is_array($evidence) || array_diff(array_keys($evidence), array('source', 'publication_timestamp')) || !is_string($evidence['source'] ?? null) || '' === $evidence['source'] || strlen($evidence['source']) > 128) throw new InvalidArgumentException('WordPress site plan content decision evidence is invalid.'); if (isset($evidence['publication_timestamp'])) { if (!self::utcTimestamp($evidence['publication_timestamp'])) throw new InvalidArgumentException('WordPress site plan content decision timestamp is invalid.'); $timestamps[] = $evidence['publication_timestamp']; } }
        if (isset($document['publication_timestamp']) && (!self::utcTimestamp($document['publication_timestamp']) || !in_array($document['publication_timestamp'], $timestamps, true))) throw new InvalidArgumentException('WordPress site plan publication timestamp is invalid.');
    }
    private static function utcTimestamp(mixed $value): bool { if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value)) return false; try { return (new \DateTimeImmutable($value))->format('Y-m-d\\TH:i:s\\Z') === $value; } catch (\Exception) { return false; } }
    private static function normalizePublicationTimestamp(string $value): ?string
    {
        $format = null; $input = $value;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) $format = '!Y-m-d';
        elseif (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value, $match)) { $input = $match[1] . ('Z' === $match[2] ? '+00:00' : $match[2]); $format = '!Y-m-d\\TH:i:sP'; }
        if (null === $format) return null;
        $date = \DateTimeImmutable::createFromFormat($format, $input); $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && (0 !== $errors['warning_count'] || 0 !== $errors['error_count']))) return null;
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
    }
    /** @param array<string,mixed> $metadata @param array<string,bool> $tokens */
    private static function assertDocumentMetadata(array $metadata, array $tokens, string $sourcePath, string $documentKind): void
    {
        if (!is_array($metadata['source_context'] ?? null) || !self::safePath($metadata['source_context']['source_path'] ?? null) || !is_string($metadata['source_context']['kind'] ?? null) || !is_string($metadata['title'] ?? null) || !is_array($metadata['title_declaration'] ?? null) || 0 !== ($metadata['title_declaration']['order'] ?? null) || 'head' !== ($metadata['title_declaration']['placement'] ?? null) || !is_array($metadata['meta'] ?? null) || !is_array($metadata['links'] ?? null) || !is_array($metadata['scripts'] ?? null)) throw new InvalidArgumentException('WordPress site plan document metadata is structurally invalid.');
        foreach ($metadata['meta'] as $index => $row) {
            if (!is_array($row)) self::invalidDeclaration('meta declaration', 'meta', $index, $sourcePath, $documentKind, 'invalid_structure', $row);
            if ($index !== ($row['order'] ?? null)) self::invalidDeclaration('meta declaration', 'meta', $index, $sourcePath, $documentKind, 'invalid_order', $row);
            if (!in_array($row['placement'] ?? null, array('head', 'body'), true)) self::invalidDeclaration('meta declaration', 'meta', $index, $sourcePath, $documentKind, 'invalid_placement', $row);
            if (array_diff(array_keys($row), array('order', 'placement', 'charset', 'name', 'property', 'http_equiv', 'content'))) self::invalidDeclaration('meta declaration', 'meta', $index, $sourcePath, $documentKind, 'unsupported_field', $row);
        }
        foreach ($metadata['links'] as $index => $row) {
            if (!is_array($row)) self::invalidDeclaration('link declaration', 'link', $index, $sourcePath, $documentKind, 'invalid_structure', $row);
            if ($index !== ($row['order'] ?? null)) self::invalidDeclaration('link declaration', 'link', $index, $sourcePath, $documentKind, 'invalid_order', $row);
            if (!in_array($row['placement'] ?? null, array('head', 'body'), true)) self::invalidDeclaration('link declaration', 'link', $index, $sourcePath, $documentKind, 'invalid_placement', $row);
            if (!is_string($row['asset_reference'] ?? null) && !self::explicitUrl($row['url'] ?? null)) self::invalidDeclaration('link declaration', 'link', $index, $sourcePath, $documentKind, 'unresolved_local_url', $row);
            if (array_diff(array_keys($row), array('order', 'placement', 'rel', 'type', 'media', 'integrity', 'crossorigin', 'referrerpolicy', 'as', 'fetchpriority', 'sizes', 'asset_reference', 'url', 'resolved_url'))) self::invalidDeclaration('link declaration', 'link', $index, $sourcePath, $documentKind, 'unsupported_field', $row);
            if (is_string($row['asset_reference'] ?? null)) self::assertTokens($row['asset_reference'], $tokens);
        }
        foreach ($metadata['scripts'] as $index => $row) {
            if (!is_array($row)) self::invalidDeclaration('script declaration', 'script', $index, $sourcePath, $documentKind, 'invalid_structure', $row);
            if ($index !== ($row['order'] ?? null)) self::invalidDeclaration('script declaration', 'script', $index, $sourcePath, $documentKind, 'invalid_order', $row);
            if (!in_array($row['placement'] ?? null, array('head', 'body'), true)) self::invalidDeclaration('script declaration', 'script', $index, $sourcePath, $documentKind, 'invalid_placement', $row);
            if (!is_string($row['asset_reference'] ?? null) && !self::explicitUrl($row['url'] ?? null) && 'inline' !== ($row['source_kind'] ?? null)) self::invalidDeclaration('script declaration', 'script', $index, $sourcePath, $documentKind, 'unresolved_local_url', $row);
            if (array_diff(array_keys($row), array('order', 'placement', 'async', 'defer', 'module', 'nomodule', 'effective_loading', 'type', 'integrity', 'crossorigin', 'referrerpolicy', 'fetchpriority', 'asset_reference', 'url', 'resolved_url', 'source_kind', 'body_hash', 'selector', 'superseded_by'))) self::invalidDeclaration('script declaration', 'script', $index, $sourcePath, $documentKind, 'unsupported_field', $row);
            if (!is_bool($row['defer'] ?? null) || !is_bool($row['async'] ?? null) || !is_bool($row['module'] ?? null) || !is_bool($row['nomodule'] ?? null) || !in_array($row['effective_loading'] ?? null, array('blocking', 'defer', 'async'), true) || ($row['async'] && 'async' !== $row['effective_loading']) || (!$row['async'] && ($row['defer'] || $row['module']) && 'defer' !== $row['effective_loading']) || (!$row['async'] && !$row['defer'] && !$row['module'] && 'blocking' !== $row['effective_loading'])) self::invalidDeclaration('script declaration', 'script', $index, $sourcePath, $documentKind, 'invalid_loading_semantics', $row);
            if (isset($row['superseded_by']) && (!is_string($row['selector'] ?? null) || !preg_match('/^script:nth-of-type\([1-9][0-9]*\)$/', $row['selector']) || !is_string($row['superseded_by']) || !preg_match('/^#[A-Za-z][A-Za-z0-9_-]*$/', $row['superseded_by']) || !self::hash($row['body_hash'] ?? null))) self::invalidDeclaration('script declaration', 'script', $index, $sourcePath, $documentKind, 'invalid_supersession_metadata', $row);
            if (is_string($row['asset_reference'] ?? null) && preg_match_all('/\{\{wordpress-site-plan:asset:([^}]+)\}\}/', $row['asset_reference'], $matches)) foreach ($matches[1] as $token) if (!isset($tokens[$token])) self::invalidDeclaration('script declaration', 'script', $index, $sourcePath, $documentKind, 'undeclared_asset_token', $row);
        }
    }
    /** @param mixed $row */
    private static function invalidDeclaration(string $label, string $declarationKind, int|string $index, string $sourcePath, string $documentKind, string $reason, mixed $row): never
    {
        $fields = array();
        $truncated = 0;
        if (is_array($row)) {
            ksort($row);
            foreach ($row as $key => $value) {
                if (!is_string($key) || (!is_scalar($value) && null !== $value) || 20 === count($fields)) { ++$truncated; continue; }
                $key = substr($key, 0, 64);
                if (isset($fields[$key])) { ++$truncated; continue; }
                $fields[$key] = is_string($value) ? substr($value, 0, 256) : $value;
            }
        }
        $context = array('source_path' => $sourcePath, 'document_kind' => $documentKind, 'declaration_kind' => $declarationKind, 'declaration_index' => $index, 'reason' => $reason, 'fields' => $fields);
        if (0 < $truncated) $context['fields_truncated'] = $truncated;
        throw new ValidationException("WordPress site plan {$label} is invalid: {$reason}.", $context);
    }
    /** @param array<string,mixed> $reporting @param array<string,bool> $pagePaths @param array<string,bool> $tokens */
    private static function assertReporting(array $reporting, array $sourcePaths, array $tokens, array $diagnostics): void { if(!is_array($reporting['source_documents']??null)||!is_array($reporting['metrics']??null)||!is_array($reporting['core_html_fallback_evidence']??null)||!is_array($reporting['diagnostic_codes']??null))throw new InvalidArgumentException('WordPress site plan reporting summary is invalid.');$sources=array();foreach($reporting['source_documents'] as $document){if(!is_array($document)||!self::safePath($document['source_path']??null)||!is_string($document['kind']??null)||!is_string($document['body_format']??null)||!is_bool($document['block_document']??null)||!is_array($document['provenance']??null))throw new InvalidArgumentException('WordPress site plan source document summary is invalid.');self::unique($sources,$document['source_path'],'source document');}if(count($sources)!==count($sourcePaths)||array_keys($sources)!==array_keys($sourcePaths))throw new InvalidArgumentException('WordPress site plan source document summaries do not match materialized documents.');foreach(array('source_document_count','block_document_count','native_block_count','fallback_count') as $key)if(!is_int($reporting['metrics'][$key]??null))throw new InvalidArgumentException('WordPress site plan reporting metric is invalid.');$linked=array_fill_keys($reporting['diagnostic_codes'],true);foreach($reporting['diagnostic_codes'] as $code)if(!is_string($code)||''===$code)throw new InvalidArgumentException('WordPress site plan diagnostic linkage is invalid.');foreach($diagnostics as $diagnostic)if(is_array($diagnostic)&&is_string($diagnostic['code']??null)&&!isset($linked[$diagnostic['code']]))throw new InvalidArgumentException('WordPress site plan diagnostics are not linked to reporting.');}
    /** @param array<string,string> $tokens */
    private static function assertWrite(mixed $write, array $tokens, bool $browserReferences): void { if (!is_array($write) || !is_string($write['kind'] ?? null) || !self::safePath($write['source_path'] ?? null) || !self::safePath($write['target_path'] ?? null) || !self::hash($write['reconciliation_identity'] ?? null) || !self::hash($write['payload_hash'] ?? null) || !is_array($write['payload'] ?? null) || !in_array($write['payload']['encoding'] ?? null, array('utf8','base64','reference'), true) || $write['reconciliation_identity'] !== self::identity('write', $write['source_path'], $write['target_path'])) throw new InvalidArgumentException('WordPress site plan write has a stale payload hash or invalid structure.'); if ('reference' === $write['payload']['encoding']) { $reference = self::payloadReference($write['payload']['reference'] ?? null); if (!is_array($reference) || ($write['raw_sha256'] ?? null) !== $reference['sha256'] || $write['payload_hash'] !== self::contentHash(RuntimeDeclarations::canonicalJson($reference))) throw new InvalidArgumentException('WordPress site plan write has an invalid payload reference.'); return; } if (!is_string($write['payload']['data'] ?? null) || $write['payload_hash'] !== self::contentHash($write['payload']['data'])) throw new InvalidArgumentException('WordPress site plan write has a stale payload hash or invalid structure.'); if ('base64' === $write['payload']['encoding'] && false === base64_decode($write['payload']['data'], true)) throw new InvalidArgumentException('WordPress site plan write has invalid base64 payload.'); if ('utf8' === $write['payload']['encoding']) { self::assertTokens($write['payload']['data'], $tokens); if ($browserReferences) self::assertNoLocalBrowserReferencesInWrite($write['target_path'], $write['payload']['data'], $write['source_path']); } }
    /** @return array{schema:string,id:string,bytes:int,sha256:string}|null */
    private static function payloadReference(mixed $reference): ?array { if (!is_array($reference) || 'blocks-engine/payload-reference/v1' !== ($reference['schema'] ?? null) || !is_string($reference['id'] ?? null) || '' === $reference['id'] || !is_int($reference['bytes'] ?? null) || $reference['bytes'] < 0 || !self::hash($reference['sha256'] ?? null)) return null; return array('schema' => $reference['schema'], 'id' => $reference['id'], 'bytes' => $reference['bytes'], 'sha256' => $reference['sha256']); }
    /** @param array<string,string> $tokens */
    private static function assertTokens(string $content, array $tokens): void { if (preg_match_all('/\{\{wordpress-site-plan:asset:([^}]+)\}\}/', $content, $matches)) foreach ($matches[1] as $token) if (!isset($tokens[$token])) throw new InvalidArgumentException('WordPress site plan contains an undeclared reference token.'); }
    /** @param array<string,bool> $values */
    private static function unique(array &$values, string $value, string $kind): void { $key = strtolower($value); if (isset($values[$key])) throw new InvalidArgumentException("WordPress site plan has colliding {$kind}s."); $values[$key] = true; }
    public static function identity(string $kind, string $source, string $target): string { return hash('sha256', "wordpress-site-plan/{$kind}/v2\n{$source}\n{$target}"); }
    public static function contentHash(string $content): string { return hash('sha256', $content); }
    private static function hash(mixed $value): bool { return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value); }
    /** @return array<int,array{offset:int,length:int}> */
    public static function blockRanges(string $markup): array
    {
        $ranges = array(); $stack = array();
        if (!preg_match_all('/<!--\s*(\/?)wp:.*?-->/s', $markup, $matches, PREG_OFFSET_CAPTURE)) return $ranges;
        foreach ($matches[0] as $match) {
            $token = $match[0]; $offset = $match[1];
            if (str_starts_with($token, '<!-- /wp:')) { $open = array_pop($stack); if (is_array($open)) $ranges[$open['index']]['length'] = $offset + strlen($token) - $open['offset']; }
            elseif (str_ends_with(rtrim($token), '/-->')) $ranges[] = array('offset' => $offset, 'length' => strlen($token));
            else { $index = count($ranges); $ranges[] = array('offset' => $offset, 'length' => 0); $stack[] = array('index' => $index, 'offset' => $offset); }
        }
        return array_values(array_filter($ranges, static fn(array $range): bool => 0 < $range['length']));
    }
    /** @param array<string,mixed>|mixed $position */
    public static function bindingPosition(mixed $position, string $markup, string $search): bool
    {
        if (!is_array($position) || 'blocks-engine/runtime-binding-position/v1' !== ($position['schema'] ?? null) || !is_int($position['block_index'] ?? null) || $position['block_index'] < 0 || !is_int($position['offset'] ?? null) || $position['offset'] < 0 || !is_int($position['length'] ?? null) || $position['length'] < 1) return false;
        foreach (self::blockRanges($markup) as $index => $range) if ($index === $position['block_index'] && $range['offset'] === $position['offset'] && $range['length'] === $position['length'] && $search === substr($markup, $range['offset'], $range['length'])) return true;
        return false;
    }
    private static function occurrenceAtOffset(string $markup, string $search, int $offset): int
    {
        $occurrence = 0; $cursor = 0;
        while (false !== ($found = strpos($markup, $search, $cursor))) { ++$occurrence; if ($found === $offset) return $occurrence; $cursor = $found + strlen($search); }
        return 0;
    }
    private static function occurrenceOffset(string $markup, string $search, int $occurrence): ?int
    {
        if ('' === $search || $occurrence < 1) return null;
        $cursor = 0;
        for ($index = 0; $index < $occurrence; ++$index) { $cursor = strpos($markup, $search, $cursor); if (false === $cursor) return null; if ($index + 1 < $occurrence) $cursor += strlen($search); }
        return $cursor;
    }
    /** @param array<string,mixed> $source */
    private static function assertSource(array $source): void { if ('blocks-engine/php-transformer/compiled-site/v1' !== ($source['schema'] ?? null) || !is_string($source['source_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/', $source['source_hash']) || !is_string($source['entry_path'] ?? null) || !is_array($source['provenance'] ?? null) || (isset($source['source_documents']) && (!is_array($source['source_documents']) || !array_is_list($source['source_documents']) || count($source['source_documents']) > 5000))) throw new InvalidArgumentException('WordPress site plan source identity is invalid.'); }
    /** @param array<int,mixed> $rows @param array<int,string> $fields @param array<int,string> $optional */
    private static function assertRows(array $rows, string $kind, array $fields, array $optional = array()): void { foreach ($rows as $row) { if (!is_array($row)) throw new InvalidArgumentException("WordPress site plan {$kind} must be an array."); foreach ($fields as $field) if (!array_key_exists($field, $row) || (!is_string($row[$field]) && !is_int($row[$field]))) throw new InvalidArgumentException("WordPress site plan {$kind} lacks {$field}."); foreach ($optional as $field) if (array_key_exists($field, $row) && !is_string($row[$field])) throw new InvalidArgumentException("WordPress site plan {$kind} has invalid {$field}."); } }
    /** @param array<string,mixed> $data */
    public static function value(array $data, string $key, string $default = ''): string { return is_string($data[$key] ?? null) ? $data[$key] : $default; }
    private static function explicitUrl(mixed $url): bool { return is_string($url) && (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $url) === 1 || self::routeUrl($url)); }
    private static function routeUrl(mixed $url): bool { return is_string($url) && preg_match('~^/(?:[a-z0-9-]+(?:/[a-z0-9-]+)*)?(?:[?#].*)?$~', $url) === 1; }
    private static function safePath(mixed $path): bool { if (!is_string($path) || '' === $path || str_contains($path, "\0") || str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:/', $path)) return false; foreach (explode('/', str_replace('\\', '/', $path)) as $segment) if ('' === $segment || '.' === $segment || '..' === $segment) return false; return true; }
}
