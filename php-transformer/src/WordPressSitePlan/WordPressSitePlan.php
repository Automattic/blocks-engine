<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes;
use Automattic\BlocksEngine\PhpTransformer\Support\DocumentVariantIds;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlMarkupParser;
use Automattic\BlocksEngine\PhpTransformer\Support\StepTimer;
use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityPolicy;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeEntityManifest;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeIslandPackageBuilder;
use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\CssUrlRewriter;
use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\SrcsetParser;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use Automattic\BlocksEngine\PhpTransformer\Path\RouteSlug;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\StaticSite\FontMaterialization\FontMaterializationPlanBuilder;
use Automattic\BlocksEngine\PhpTransformer\Support\NativeListItemFallbackReconciler;
use Automattic\BlocksEngine\PhpTransformer\Support\StyleTagScanner;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
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
    public const MAX_DOCUMENT_IDENTITY_DIAGNOSTICS = 50;
    /** Marks the post-content block of a template that holds a page's hoisted layout frame. */
    public const FRAME_CONTENT_CLASS = 'blocks-engine-frame-content';
    /** Markers that name one carrier element: rich text, control and semantic. */
    private const ELEMENT_MARKER_PATTERN = '/blocks-engine-(?:richtext|control|semantic)-[a-f0-9]+-\d+/';
    public const LISTING_QUERY_CLASS = 'blocks-engine-listing-query';
    private string $sourceOrigin = '';
    public const MAX_UNRESOLVED_NAVIGATION_DIAGNOSTICS = 50;
    private const MAX_ROUTE_COLLISION_DIAGNOSTICS = 50;
    private const MAX_OMITTED_LINK_DECLARATION_DIAGNOSTICS = 50;
    private const OMITTED_LINK_DECLARATION_DIAGNOSTIC_CODE = 'wordpress_site_plan_omitted_link_declaration';
    /** @var array<string,array<string,mixed>> */
    private array $unresolvedScriptReferenceDiagnostics = array();
    private int $omittedUnresolvedScriptReferenceDiagnostics = 0;
    /** @var array<string,array<string,mixed>> */
    private array $omittedLinkDeclarations = array();
    /** @var array<string,array<string,bool>> */
    private array $omittedLinkDeclarationPages = array();
    /** @var array<string,array<string,mixed>> Dropped or rewritten units, keyed by their identity fields. */
    private array $unitFindings = array();
    /** @var array<string,bool> */
    private array $omittedLinkDeclarationOverflow = array();
    /** @var array<int,array<string,mixed>> */
    private array $routeCollisions = array();
    private int $omittedRouteCollisionDiagnostics = 0;
    private readonly ShellExtraction $shellExtraction;
    private ListingQueryLoops $listings;
    private PostChrome $postChrome;
    private RouteLinks $links;
    private ?DesignSystem $designSystem = null;
    private ?PresetBinding $presetBinding = null;
    private MissingMediaRecovery $missingMedia;

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
        $this->shellExtraction = new ShellExtraction($this);
        $this->listings = new ListingQueryLoops('', $this->chromeIdentityKey(...));
        $this->postChrome = new PostChrome($this->listings, $this->chromeIdentityKey(...), $this->recordUnitFinding(...));
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

    /**
     * Project opt-in entity evidence onto its canonical page. This is a hint to
     * consumers, never an instruction to omit create_page or claim a route.
     * Works for both inline entities and content-addressed entity manifests.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array<string,mixed>> $declarations
     * @param array<int,array<string,mixed>> $records
     * @return array<int,array<string,mixed>>
     */
    private static function attachWholePageCandidates(array $pages, array $declarations, array $records): array
    {
        $bySource = array_column($pages, null, 'source_path');
        $candidates = array();
        foreach ($declarations as $declaration) {
            $payload = $declaration['payload'] ?? array();
            $entities = RuntimeEntityManifest::SCHEMA === ($payload['schema'] ?? null)
                ? RuntimeEntityManifest::resolve($payload, $records) : ($payload['entities'] ?? array());
            if (!is_array($entities)) continue;
            foreach ($entities as $entity) {
                if (!is_array($entity) || !array_key_exists('whole_page_candidate', $entity)) continue;
                $source = $entity['source_path'] ?? null;
                $page = is_string($source) ? ($bySource[$source] ?? null) : null;
                $id = $entity['id'] ?? null;
                $route = $entity['source_route'] ?? null;
                if ('blocks-engine/whole-page-candidate/v1' !== $entity['whole_page_candidate']
                    || 'entity_collection' !== ($declaration['kind'] ?? null)
                    || !is_string($id) || '' === $id || strlen($id) > 256
                    || !is_array($page) || !empty($page['synthetic']) || 'page' !== ($page['post_type'] ?? null)
                    || !is_string($route) || $route !== $page['route']['path']) {
                    throw new InvalidArgumentException('Whole-page candidate must name one canonical source page and producer entity row.');
                }
                // One fixed-size candidate per canonical page: the page set bounds the candidate set.
                if (isset($candidates[$source])) throw new InvalidArgumentException('Whole-page candidates must be unique by source page.');
                $candidates[$source][] = array(
                    'schema' => 'blocks-engine/whole-page-candidate/v1',
                    'source_path' => $source,
                    'source_route' => $page['route']['path'],
                    'page_reconciliation_identity' => $page['reconciliation_identity'],
                    'declaration_reconciliation_identity' => $declaration['reconciliation_identity'],
                    'entity_id' => $id,
                );
            }
        }
        foreach ($pages as &$page) {
            if (!isset($candidates[$page['source_path']])) continue;
            $page['whole_page_candidates'] = $candidates[$page['source_path']];
        }
        unset($page);
        return $pages;
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
        $sourceUrl = $this->sourceUrlFromProvenance($data['provenance'] ?? array());
        $this->chromeIdentityKeys = array();
        $this->sourceOrigin = RouteLinks::origin($sourceUrl);
        $this->listings = new ListingQueryLoops($this->sourceOrigin, $this->chromeIdentityKey(...));
        $this->postChrome = new PostChrome($this->listings, $this->chromeIdentityKey(...), $this->recordUnitFinding(...));
        $this->unresolvedScriptReferenceDiagnostics = array();
        $this->omittedUnresolvedScriptReferenceDiagnostics = 0;
        $this->omittedLinkDeclarations = array();
        $this->omittedLinkDeclarationPages = array();
        $this->unitFindings = array();
        $this->omittedLinkDeclarationOverflow = array();
        $editabilityPolicy = $input->editabilityPolicy;
        if (!is_array($editabilityPolicy) || EditabilityPolicy::SCHEMA !== ($editabilityPolicy['schema'] ?? null) || 'required' !== ($editabilityPolicy['enforcement'] ?? null) || !in_array($editabilityPolicy['status'] ?? null, array('passed', 'failed'), true)) {
            throw new InvalidArgumentException('WordPress site plan requires a versioned editability policy.');
        }
        $compiled = $input->compiledSite;
        if ( array() === $compiled ) {
            throw new InvalidArgumentException('WordPress site plan requires a compiled-site report.');
        }
        // Redirect captures have no content identity to materialize. Remove
        // only proven empty redirects before validating compiled block bodies.
        $compiled['pages'] = array_values(array_filter($compiled['pages'] ?? array(), static fn(mixed $document): bool => !is_array($document) || !self::emptyRedirectDocument($document)));
        $identityFailures = self::compiledSiteIdentityFailures($compiled);
        if ( array() !== $identityFailures ) {
            throw new DocumentIdentityException($identityFailures);
        }

        $runtimeEmoji = (bool) array_filter($compiled['pages'], static fn(array $page): bool => 'wordpress' === ($page['document_metadata']['root_attributes'][CaptureEvidence::RUNTIME_EMOJI] ?? null));
        $runtimeDeclarations = $compiled['runtime_declarations'] ?? array();
        $runtimeRecords = RuntimeDeclarations::normalizeRecords($compiled['runtime_records'] ?? array());
        $runtimeDeclarations = RuntimeDeclarations::materialize($runtimeDeclarations, $runtimeRecords);
        $this->timer?->start('compose.site_plan.documents');
        $runtimeScriptOwnership = $this->runtimeScriptOwnership($input->runtimeIslandPackage, $compiled['pages'] ?? array(), $runtimeDeclarations);
        $documents = $this->withoutOwnedRuntimeScripts(ContentDecisions::documents($compiled['pages'] ?? null), $runtimeScriptOwnership['documents']);
        $documentScriptAssets = $this->documentScriptAssets($documents);
        $assets = array_values(array_filter(
            $this->assets($compiled['assets'] ?? null),
            static fn(array $asset): bool => !isset($runtimeScriptOwnership['assets'][(string) ($asset['source_path'] ?? '')]) || isset($documentScriptAssets[(string) ($asset['source_path'] ?? '')])
        ));
        $retainedSources = array_fill_keys(array_column($documents, 'source_path'), true);
        $assets = array_values(array_filter($assets, static fn(array $asset): bool => 'page' !== ($asset['compilation']['scope'] ?? null) || isset($retainedSources[$asset['compilation']['id'] ?? ''])));
        $assets = $this->applyDeclaredAssetTransformations($assets, $runtimeDeclarations);
        // The design system comes from what every document renders, before
        // any document is projected: RouteLinks::markup() binds literal block values
        // to its tokens wherever block markup enters the plan.
        // Button element styles are decided on the markup before binding,
        // which is what tells a button that renders through them apart from
        // one that never had the value.
        $this->timer?->start('compose.site_plan.design_system');
        $this->designSystem = DesignSystem::fromUsage(self::designUsage($documents))?->withButtonElementsOwnedBy(array_values(array_filter(array_column($documents, 'block_markup'), 'is_string')));
        $this->presetBinding = null === $this->designSystem ? null : new PresetBinding($this->designSystem, $assets, self::designUsage($documents));
        $this->timer?->start('compose.site_plan.routes');
        $tokens = $this->tokens($assets);
        $surfaces = $this->templateSurfaces($documents);
        $documents = array_values(array_filter($documents, static fn(array $document): bool => !isset($document['template_surface'])));
        $routeMap = $this->canonicalRoutes($documents, $input->routes);
        // Both native archive kinds use the same listing, template and resource pipeline.
        $taxonomyProjection = TaxonomyProjection::project($documents, $routeMap, $this->sourceOrigin);
        $authorProjection = AuthorProjection::project($documents, $routeMap, $this->sourceOrigin);
        foreach (array('entities', 'diagnostics') as $key) $taxonomyProjection[$key] = array_merge($taxonomyProjection[$key], $authorProjection[$key]);
        $taxonomyProjection['pagination_source_paths_by_archive'] += $authorProjection['pagination_source_paths_by_archive'];
        $routeSources = array();
        $routeTargets = array();
        foreach ($routeMap as $route) {
            $sourcePath = (string) ($route['source_path'] ?? '');
            $targetPath = (string) ($route['target_path'] ?? '');
            if ('' !== $sourcePath && '' !== $targetPath) $routeSources[$sourcePath] = $targetPath;
            if ('' !== $targetPath) $routeTargets['/' === $targetPath ? '/' : '/' . trim($targetPath, '/')] = $targetPath;
        }
        // Native date archives are document roots, not materialized pages, but
        // their captured file paths still participate in link resolution.
        $entryRoot = self::entryRootFromDocuments($compiled['pages']);
        foreach ($compiled['pages'] as $document) {
            if (!ContentDecisions::isDateArchive($document)) continue;
            $path = $document['metadata']['route_path'] ?? PageRoute::fromSource($document['source_path'], $entryRoot);
            $target = '/' . trim($path, '/') . '/';
            $routeSources[$document['source_path']] = $target;
            $routeTargets['/' . trim($path, '/')] = $target;
        }
        $this->timer?->start('compose.site_plan.pages');
        $this->links = new RouteLinks($sourceUrl, $this->sourceOrigin, $routeSources, $routeTargets, $this->presetBinding, $documents, $routeMap);
        $entityBindings = new EntityBindings($this->links, $this->recordUnitFinding(...));
        $this->missingMedia = new MissingMediaRecovery($this->strictMissingMedia, array_column($assets, 'target_path'));
        $missingFonts = new MissingFontSourceRecovery($this->strictMissingMedia);
        $missingImports = new MissingStylesheetImportRecovery($this->strictMissingMedia);
        $references = new AssetReferenceCanonicalizer($tokens, self::entryRootFromDocuments($documents), $this->missingMedia, $missingFonts, $missingImports);
        $pages = $this->documents($documents, false, $tokens, $references, $routeMap);
        // Probe presentation with the final plan's canonical listing projection.
        // Entity ownership is already proven independently and must survive a
        // declined presentation, including ownership of numbered captures.
        $routeBySource = array_column($routeMap, 'target_path', 'source_path');
        foreach ($pages as &$page) {
            $path = $routeBySource[$page['source_path']] ?? null;
            if (is_string($path)) $page['route'] = array('path' => $path, 'parent_path' => PageRoute::parent($path), 'slug' => PageRoute::slug($path));
            // Shared chrome later moves the breadcrumb into one template, so
            // each document's own source trail is recorded while it still has it.
            $trail = BreadcrumbLabelRuntime::trail($page);
            if (array() !== $trail) $page['metadata']['breadcrumb_trail'] = $trail;
        }
        unset($page);
        $preflightPages = $pages;
        $preflightPages = (new ListingQueryLoops($this->sourceOrigin, $this->chromeIdentityKey(...)))->materialize($preflightPages, $runtimeDeclarations, $taxonomyProjection['entities']);
        $preflightBySource = array_column($preflightPages, null, 'source_path');
        $projectedTaxonomyEntities = array();
        $paginationOwnerBySource = array();
        foreach ($taxonomyProjection['entities'] as $taxonomyEntity) {
            $archiveSource = $taxonomyEntity['archive']['source_path'];
            if (!str_contains((string) ($preflightBySource[$archiveSource]['canonical_block_markup'] ?? ''), '<!-- wp:query ')) {
                unset($taxonomyEntity['archive']['presentation_markup']);
            }
            $projectedTaxonomyEntities[] = $taxonomyEntity;
            foreach ($taxonomyProjection['pagination_source_paths_by_archive'][$archiveSource] ?? array() as $paginationSource) $paginationOwnerBySource[$paginationSource] = $archiveSource;
        }
        $taxonomyProjection['entities'] = TaxonomyProjection::retainProvenParents($projectedTaxonomyEntities, $taxonomyProjection['diagnostics']);
        if (array() !== $paginationOwnerBySource) {
            foreach ($assets as &$asset) {
                $owner = $asset['compilation'] ?? null;
                if ('page' === ($owner['scope'] ?? null) && isset($paginationOwnerBySource[$owner['id'] ?? ''])) $asset['compilation']['id'] = $paginationOwnerBySource[$owner['id']];
            }
            unset($asset);
        }
        // Taxonomy membership must see numbered captures before they are
        // suppressed. They are views of a listing, never standalone content.
        $paginationSources = array();
        $paginationDiagnostics = array();
        foreach ($pages as $page) {
            $route = $routeBySource[$page['source_path']] ?? '';
            $parent = PageRoute::paginationParent($route);
            if (!empty($page['entrypoint']) || null === $parent) continue;
            $paginationSources[$page['source_path']] = true;
            $paginationDiagnostics[] = array(
                'code' => 'wordpress_site_plan_pagination_suppressed',
                'severity' => 'info',
                'message' => 'A pagination capture is a view of its parent, not a standalone document.',
                'source_path' => $page['source_path'],
                'source_route' => $route,
                'parent_route' => $parent,
                'reason_code' => str_contains($route, '/comment-page-') ? 'comment_pagination' : 'listing_pagination',
            );
        }
        // A suppressed numbered capture repeats its listing's chrome. Record the
        // listing that owns it so the runtime entities it shares with that
        // listing (a sidebar form, for instance) fold into the listing's binding.
        $paginationOwners = $paginationOwnerBySource;
        $sourceByRoute = array_flip(array_map(static fn(string $route): string => rtrim($route, '/'), array_filter($routeBySource, 'is_string')));
        // A listing that became a template surface has no page route; its
        // numbered capture still sits beside it in the source tree.
        $surfaceSources = array_fill_keys(array_column($surfaces, 'source_path'), true);
        foreach (array_keys($paginationSources) as $source) {
            $owner = $sourceByRoute[rtrim((string) PageRoute::paginationParent($routeBySource[$source] ?? ''), '/')] ?? null;
            $sibling = preg_replace('~/(?:page/[1-9][0-9]*|comment-page-[1-9][0-9]*)(/index\.html?)$~i', '$1', $source);
            if (!is_string($owner) && is_string($sibling) && isset($surfaceSources[$sibling])) $owner = $sibling;
            if (is_string($owner) && $owner !== $source && !isset($paginationSources[$owner])) $paginationOwners[$source] = $owner;
        }
        $pages = array_values(array_filter($pages, static fn(array $page): bool => !isset($paginationSources[$page['source_path']])));
        $documents = array_values(array_filter($documents, static fn(array $document): bool => !isset($paginationSources[$document['source_path']])));
        $assets = array_values(array_filter($assets, static fn(array $asset): bool => 'page' !== ($asset['compilation']['scope'] ?? null) || !isset($paginationSources[$asset['compilation']['id'] ?? ''])));
        $runtimeEntityRecords = $compiled['runtime_entity_records'] ?? array();
        $eventDiagnostics = array();
        $runtimeDeclarations = EventDeclarations::add($documents, $routeMap, $runtimeDeclarations, $runtimeEntityRecords, $eventDiagnostics);
        $assets = $this->attachDocumentStylesheetInstances($assets, $pages);
        NativeListItemFallbackReconciler::reconcileBlockDocuments(
            $data['fallbacks'],
            array_values(array_filter(array_column($pages, 'canonical_block_markup'), 'is_string'))
        );
        $data['metrics']['fallback_count'] = count(array_filter(
            $data['fallbacks'],
            static fn (mixed $fallback): bool => is_array($fallback) && 'native_conversion' !== ($fallback['conversion_classification'] ?? '')
        ));
        // Restore the semantic shell candidates before deriving binding positions.
        // Extracted parts intentionally contain only their inner markup; the page
        // representation owns the landmark wrapper until extraction is accepted.
        foreach ($pages as &$page) {
            foreach ($page['shell_candidates'] ?? array() as $candidate) {
                // A leading-chrome candidate still owns its original landmark
                // until extraction accepts it; restoring the pruned part loses content.
                $restored = $this->shellExtraction->replaceTopLevelShell($page['canonical_block_markup'], (string) ($candidate['area'] ?? ''), (string) ($candidate['shared_leading']['markup'] ?? $candidate['markup'] ?? ''));
                if (null !== $restored) $page['canonical_block_markup'] = $restored;
            }
            $page['content_hash'] = self::contentHash($page['canonical_block_markup']);
        }
        unset($page);
        // Bindings anchor on the final canonical page markup before any shell
        // extraction. Asset and route projection can make source anchors equal,
        // so assign occurrences only after that shared projection is complete.
        $this->timer?->start('compose.site_plan.entity_bindings');
        $bindingTemplates = $this->templates(array(), array(), $surfaces, $tokens, $references, $routeMap);
        $surfaceMarkup = array_column($surfaces, 'block_markup', 'source_path');
        foreach ($bindingTemplates as &$template) if (isset($surfaceMarkup[$template['source_path'] ?? ''])) $template['_projected_source_block_markup'] = $surfaceMarkup[$template['source_path']];
        unset($template);
        $runtimeDeclarations = $entityBindings->canonical($runtimeDeclarations, $runtimeEntityRecords, $references, $routeMap, $pages, $bindingTemplates, $paginationOwners);
        // A form provider rebuilds a form from its fallback's readable blocks.
        foreach ($data['fallbacks'] as &$fallback) {
            $source = is_array($fallback) ? ($fallback['source_path'] ?? $fallback['source'] ?? null) : null;
            if (is_string($source) && is_array($fallback['readable_blocks'] ?? null)) $fallback = self::mapEntityMarkup($fallback, fn(string $markup): string => $this->links->markup($references->content($markup, $source), $source, $routeMap));
        }
        unset($fallback);
        $pages = $this->pageHierarchy($pages, $routeMap);
        $templateOwners = array();
        foreach ($pages as $page) if (empty($page['entrypoint'])) $templateOwners[self::routeTemplateKey($page)][] = $page['source_path'];
        // Colliding terminal slugs get a readable route-qualified template
        // (`page-north-about`), as an editor would name it; a short route hash
        // disambiguates only when the readable key is itself taken.
        $claimedTemplates = $templateOwners;
        foreach ($pages as &$page) {
            $key = self::routeTemplateKey($page);
            if (empty($page['entrypoint']) && 1 < count($templateOwners[$key] ?? array())) {
                $prefix = 'page' === ($page['post_type'] ?? null) ? 'page-' : 'single-' . $page['post_type'] . '-';
                $routeSlug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(trim((string) $page['route']['path'], '/'))), '-');
                $template = $prefix . ('' === $routeSlug ? $page['slug'] : $routeSlug);
                if ($template === $key || isset($claimedTemplates[$template])) $template .= '-' . substr(hash('sha256', (string) $page['route']['path']), 0, 8);
                $claimedTemplates[$template] = true;
                $page['route_template'] = $template;
                $page['metadata']['post_meta']['_wp_page_template'] = $template;
            }
        }
        unset($page);
        $surfaceSources = array_fill_keys(array_column($surfaces, 'source_path'), true);
        foreach ($assets as &$asset) {
            if ('page' === ($asset['compilation']['scope'] ?? null) && isset($surfaceSources[$asset['compilation']['id'] ?? ''])) $asset['compilation'] = array('scope' => 'shared');
        }
        unset($asset);
        // Root selector context belongs to captured documents, not only to
        // entities that survive as pages (terms, authors and dates do not).
        $rootDocuments = array();
        $nativeArchiveSources = array_fill_keys(array_column(array_column($taxonomyProjection['entities'], 'archive'), 'source_path'), true);
        $assetPages = array_values(array_filter($pages, static fn(array $page): bool => !isset($nativeArchiveSources[$page['source_path']])));
        $pageSources = array_fill_keys(array_column($assetPages, 'source_path'), true);
        $entryRoot = self::entryRootFromDocuments($compiled['pages']);
        foreach ($compiled['pages'] as $document) {
            if (isset($pageSources[$document['source_path']]) || isset($paginationOwners[$document['source_path']]) || isset($paginationSources[$document['source_path']])) continue;
            $path = $routeBySource[$document['source_path']] ?? $document['metadata']['route_path'] ?? PageRoute::fromSource($document['source_path'], $entryRoot);
            $metadata = $document['document_metadata'] ?? array();
            $rootDocuments[] = array('source_path' => $document['source_path'], 'reconciliation_identity' => self::identity('page', $document['source_path'], $document['source_path']), 'route' => array('path' => $path), 'document_metadata' => array_intersect_key($metadata, array('root_attributes' => true, 'body_attributes' => true)));
        }
        $this->timer?->start('compose.site_plan.theme_json');
        $assets = $this->scopeAssets($assets, self::assetOwnerDocuments($assetPages, $rootDocuments));
        $projector = new ThemeJsonProjection();
        $themeProjection = $projector->project($assets, array_column($pages, 'source_path'), $this->designSystem);
        $assets = $themeProjection['assets'];
        $this->presetBinding?->useThemeDefaults($themeProjection['theme']);
        // Font binding waited for the scoped defaults; do not pin a block to
        // a preset when the written Global Styles already owns its family.
        // Shell candidates hold the page's bytes and block offsets, so a page
        // whose markup binding changes has them read again from the bound page.
        $documentsBySource = array_column($documents, null, 'source_path');
        foreach ($pages as &$page) {
            $bound = $this->presetBinding?->bind($page['canonical_block_markup']);
            if (null === $bound || $bound === $page['canonical_block_markup']) continue;
            $page['canonical_block_markup'] = $bound;
            $page['content_hash'] = self::contentHash($bound);
            $document = $documentsBySource[$page['source_path']] ?? null;
            if (!is_array($document) || !isset($page['shell_candidates'])) continue;
            foreach ($document['shell_artifacts'] ?? array() as $key => $artifact) {
                if (is_array($artifact) && is_string($artifact['block_markup'] ?? null)) $document['shell_artifacts'][$key]['block_markup'] = $this->presetBinding->bind($artifact['block_markup']);
            }
            $page['shell_candidates'] = $this->shellExtraction->shellCandidates($document, $references, $routeMap, $bound);
        }
        unset($page);
        $routes = $this->routesForPages($pages);
        // Entry shells remain in compiled-site/v1 for existing consumers; the
        // canonical plan rebuilds them from full page shell candidates.
        $compiledParts = is_array($compiled['template_parts'] ?? null) ? array_values(array_filter($compiled['template_parts'], static fn(mixed $part): bool => !is_array($part) || 'entry_shell' !== ($part['placement']['kind'] ?? null))) : null;
        $this->timer?->start('compose.site_plan.shell_extraction');
        $existingParts = $this->documents($compiledParts, true, $tokens, $references, $routeMap);
        $explicitBindings = $this->shellExtraction->bindExplicitParts($pages, $existingParts, $runtimeDeclarations, $runtimeEntityRecords);
        $pages = $explicitBindings['pages'];
        $existingParts = $explicitBindings['parts'];
        $reservedPartSlugs = array_fill_keys(array_column($existingParts, 'slug'), true);
        $canonicalInlineParts = $this->documents(ArrayField::array($compiled, 'inline_shell_artifacts'), true, $tokens, $references, $routeMap);
        $pages = $this->shellExtraction->withSourceSharedShellIdentity($pages, $canonicalInlineParts, $runtimeDeclarations, $runtimeEntityRecords);
        $inlineShells = $this->shellExtraction->inlineSharedShells($pages, $reservedPartSlugs, $runtimeDeclarations, $canonicalInlineParts, $runtimeEntityRecords, $assets);
        $reservedPartSlugs += array_fill_keys(array_column($inlineShells['parts'], 'slug'), true);
        $edgeShells = $this->shellExtraction->edgeShells($inlineShells['pages'], $reservedPartSlugs, $inlineShells['runtime_declarations']);
        $inlineShells['pages'] = $edgeShells['pages'];
        $inlineShells['parts'] = array_merge($inlineShells['parts'], $edgeShells['parts']);
        $inlineShells['diagnostics'] = array_merge($inlineShells['diagnostics'], $edgeShells['diagnostics']);
        // Variant parts settle their area: no single part is bound in templates.
        $reservedPartSlugs += array_fill_keys(array_column($edgeShells['parts'], 'slug'), true) + array_fill_keys(array_column($edgeShells['parts'], 'area'), true);
        $shells = $this->shellExtraction->sharedShells($inlineShells['pages'], $reservedPartSlugs, $inlineShells['runtime_declarations'], $runtimeEntityRecords, true, true, $assets);
        $inlineAreas = array_fill_keys(array_column($inlineShells['parts'], 'area'), true);
        $shells['diagnostics'] = array_values(array_filter($shells['diagnostics'], static fn(array $diagnostic): bool => !isset($inlineAreas[$diagnostic['area'] ?? '']) || 'wordpress_site_plan_shell_retained_incomplete' !== ($diagnostic['code'] ?? null)));
        $trailingChrome = $this->shellExtraction->withSharedFooterTrailingChrome($pages, $shells['pages'], $shells['parts'], $shells['runtime_declarations'], $runtimeEntityRecords, $assets);
        $shells['runtime_declarations'] = $trailingChrome['runtime_declarations'];
        $runtimeEntityRecords = $trailingChrome['runtime_entity_records'];
        $shells['pages'] = $trailingChrome['pages'];
        $shells['parts'] = $trailingChrome['parts'];
        $footerContent = $this->shellExtraction->factorSharedFooterContent($shells['pages'], $shells['parts'], $shells['runtime_declarations'], $runtimeEntityRecords);
        $shells['pages'] = $footerContent['pages'];
        $shells['parts'] = $footerContent['parts'];
        $shells['diagnostics'] = array_merge($shells['diagnostics'], $footerContent['diagnostics']);
        $parts = array_merge($existingParts, $inlineShells['parts'], $shells['parts']);
        $documentChrome = $this->shellExtraction->separateCaptureDocumentChrome($shells['pages'], $shells['runtime_declarations'], $runtimeEntityRecords);
        $shells['diagnostics'] = array_merge($shells['diagnostics'], $documentChrome['diagnostics']);
        $shellFrames = $this->shellExtraction->hoistInlineShellFrames($documentChrome['pages'], $parts, $shells['runtime_declarations']);
        $pages = $shellFrames['pages'];
        $folded = $this->shellExtraction->foldFrameTrailingChrome($shellFrames['frames'], $parts, $pages);
        $shellFrames['frames'] = $folded['frames'];
        $parts = $folded['parts'];
        // The generic page template is shared by routes that did not author it,
        // so the rules aimed at its markers are site-wide chrome rules.
        $frameParts = isset($shellFrames['frames']['page']) ? array(array('slug' => 'page-frame', 'placement' => array('kind' => 'shared_shell'), 'canonical_block_markup' => $shellFrames['frames']['page']['opening'] . $shellFrames['frames']['page']['closing'])) : array();
        foreach ($documentChrome['chrome'] as $chrome) $frameParts[] = array('slug' => 'document-chrome', 'placement' => array('kind' => 'shared_shell', 'template_slugs' => array('single')), 'canonical_block_markup' => $chrome);
        $assetTargetCollisions = array();
        // Element markers some emitted markup still carries. A rule naming
        // any other marker names an element extraction replaced (one route's
        // copy of the chrome), which matches nothing anywhere.
        // Underscore keys are working state the plan does not emit, such as a
        // route's projected source markup from before its chrome was extracted.
        $emitted = static fn (array $rows): array => array_map(static fn (mixed $row): mixed => is_array($row) ? array_filter($row, static fn (int|string $key): bool => !is_string($key) || !str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY) : $row, $rows);
        $this->timer?->start('compose.site_plan.stylesheets');
        $liveElementMarkers = preg_match_all(self::ELEMENT_MARKER_PATTERN, (string) json_encode(array($emitted($pages), $emitted($parts), $frameParts, $shellFrames['frames'] ?? array(), $shells['runtime_declarations'] ?? array(), $runtimeEntityRecords)), $liveMatches) ? array_fill_keys($liveMatches[0], true) : array();
        $assets = SharedChromeStylesheets::project($assets, array_merge($parts, $frameParts), $pages, $references, $assetTargetCollisions, $liveElementMarkers);
        $assets = self::withoutDeadElementMarkerSelectors($assets, $liveElementMarkers);
        // Removing dead selectors can consume a generated sheet's last rule.
        $assets = array_values(array_filter($assets, static fn(array $asset): bool => !in_array($asset['source'] ?? '', array('engine-support', 'editor-static-state', 'wordpress-compat'), true) || !is_string($asset['content'] ?? null) || '' !== trim($asset['content'])));
        foreach ($assetTargetCollisions as $collision) $this->recordUnitFinding(array(
            'code' => 'wordpress_site_plan_colliding_asset_target',
            'unit' => 'asset',
            'message' => 'Two assets resolved to one target with different content; the later one was given a suffixed target.',
            'source_path' => $collision['source_path'],
            'reason_code' => 'colliding_asset_target',
            'colliding_source_path' => $collision['colliding_source_path'],
            'target_path' => $collision['target_path'],
            'resolved_target_path' => $collision['resolved_target_path'],
        ));
        // A page made later in WordPress renders in the generic page template but
        // owns no stylesheet. The page that authored the frame lends its own as
        // the fallback that routes without styles of their own already use.
        // An entry shell renders on every native template, so its page lends
        // the stylesheets that dress that chrome in the same way.
        $fallbackSources = array();
        if (isset($shellFrames['frames']['page']) && is_string($shellFrames['generic_source'])) $fallbackSources[$shellFrames['generic_source']] = true;
        foreach ($parts as $part) if ('entry_shell' === ($part['placement']['kind'] ?? null)) $fallbackSources[substr((string) $part['source_path'], 0, (int) strrpos((string) $part['source_path'], '#'))] = true;
        if (array() !== $fallbackSources) foreach ($assets as &$asset) if ('css' === ($asset['kind'] ?? null) && array() !== array_filter($asset['scopes'] ?? array(), static fn(array $scope): bool => 'page' === ($scope['kind'] ?? null) && isset($fallbackSources[$scope['source_path'] ?? '']))) $asset['frame_fallback'] = true; unset($asset);
        $assets = SharedChromeStylesheets::projectDetachedPaintOrder($assets, $parts, $pages);
        $assets = self::orderPageStylesheetProjections($assets);
        $assets = self::placeSharedStylesheetProjections($assets, $pages);
        $assets = self::mergeIdenticalPageStylesheets($assets, array_merge($pages, $parts, $frameParts));
        $assets = EditorStaticStateAssets::share($assets, $references);
        $assets = self::foldDocumentSupport($assets, self::assetOwnerDocuments($pages, $rootDocuments), self::canonicalSupportDocuments(self::assetOwnerDocuments($assetPages, $rootDocuments)), implode("\n", array_column(array_merge($parts, $frameParts), 'canonical_block_markup')));
        $tokens = $this->tokens($assets);
        if (array() !== $parts) $themeProjection['theme']['templateParts'] = array_values(array_map(static fn(array $part): array => array('name' => $part['slug'], 'title' => $part['title'], 'area' => $part['area']), $parts));
        $runtimeDeclarations = $shells['runtime_declarations'];
        $this->timer?->start('compose.site_plan.templates');
        $runtimeDeclarations = $entityBindings->canonical($runtimeDeclarations, $runtimeEntityRecords, $references, $routeMap, $pages, array_merge($parts, $bindingTemplates), $paginationOwners);
         self::assertEntityBindingsAnchored($runtimeDeclarations, $pages, array_merge($parts, $bindingTemplates), $assets);
         $navigation = NavigationEntityProjection::project($pages, $parts, $input->menus, $runtimeDeclarations, $runtimeEntityRecords);
         $pages = $navigation['pages'];
         $parts = $navigation['parts'];
         $menus = $navigation['menus'];
        $pages = $this->postChrome->extractCommentForms($pages);
        $blockThemeChrome = $this->postChrome->extractBlockThemeChrome($pages, $runtimeDeclarations, $runtimeEntityRecords);
        $pages = $blockThemeChrome['pages'];
        $singleContent = array() !== $blockThemeChrome['templates'] ? reset($blockThemeChrome['templates']) : null;
          $pages = $this->listings->materialize($pages, $runtimeDeclarations, $taxonomyProjection['entities'], true);
          $pages = self::resolveFeaturedImageTargets($pages, $tokens);
          $assets = ListingQueryPresentation::project($assets, $this->listings->queryContainers());
         // Content every panel of one tabs block repeats is declared once.
         $syncedPatterns = SyncedPatternProjection::project($pages, $assets);
         $pages = $syncedPatterns['pages'];
         $syncedPatterns = $syncedPatterns['patterns'];
         // Query Loop projection can shorten page markup after shell extraction,
         // and synced patterns take shared panel content (and its bindings) out
         // of the page. Rebase retained runtime anchors on the final documents.
         $runtimeDeclarations = $entityBindings->canonical($runtimeDeclarations, $runtimeEntityRecords, $references, $routeMap, $pages, array_merge($parts, $bindingTemplates), $paginationOwners);
         $pages = self::attachWholePageCandidates($pages, $runtimeDeclarations, $runtimeEntityRecords);
         foreach ($pages as &$page) unset($page['_projected_source_block_markup'], $page['_synced_pattern_source']); unset($page);
         self::assertEntityBindingsAnchored($runtimeDeclarations, $pages, array_merge($parts, $syncedPatterns, $bindingTemplates), $assets);
         $projectedTaxonomyEntities = array();
         foreach ($taxonomyProjection['entities'] as $taxonomyEntity) {
             foreach ($pages as $page) if (($page['source_path'] ?? null) === ($taxonomyEntity['archive']['source_path'] ?? null)) {
                 if (!str_contains((string) ($page['canonical_block_markup'] ?? ''), '<!-- wp:query ')) {
                     $taxonomyProjection['diagnostics'][] = array('code' => 'wordpress_site_plan_' . ('author' === $taxonomyEntity['kind'] ? 'author' : 'taxonomy') . '_archive_presentation_static', 'severity' => 'warning', 'message' => 'The archive renders its captured presentation and static cards rather than a live query because the listing could not be converted to an inherited native query loop.', 'source_path' => $taxonomyEntity['archive']['source_path'], 'source_route' => $taxonomyEntity['archive']['source_route']);
                 }
                 if ('' !== trim($page['canonical_block_markup'])) $taxonomyEntity['archive']['presentation_markup'] = $page['canonical_block_markup'];
                 else unset($taxonomyEntity['archive']['presentation_markup']);
                 $projectedTaxonomyEntities[] = $taxonomyEntity;
                 continue 2;
             }
         }
         $taxonomyProjection['entities'] = TaxonomyProjection::retainProvenParents($projectedTaxonomyEntities, $taxonomyProjection['diagnostics']);
         unset($taxonomyEntity);
        // Archive documents remain working inputs until their presentation is
        // settled, but native archive routes must never materialize pages.
        $archiveSources = array_fill_keys(array_column(array_column($taxonomyProjection['entities'], 'archive'), 'source_path'), true);
        $workingPages = array_column($pages, null, 'source_path');
        $pages = array_values(array_filter($pages, static fn(array $page): bool => !isset($archiveSources[$page['source_path']])));
        foreach ($pages as &$page) {
            while (isset($archiveSources[$page['parent_source_path']])) {
                $page['parent_source_path'] = $workingPages[$page['parent_source_path']]['parent_source_path'];
            }
        }
        unset($page);
        // Prune from the leaves: removing an unused synthetic parent can make
        // its own synthetic ancestor unused as well. Real documents stay put.
        do {
            $count = count($pages);
            $parents = array_fill_keys(array_column($pages, 'parent_source_path'), true);
            $pages = array_values(array_filter($pages, static fn(array $page): bool => empty($page['synthetic']) || isset($parents[$page['source_path']])));
        } while (count($pages) !== $count);
        // Asset projections already saw the working hierarchy. Drop every
        // occurrence owned by a removed synthetic document at this boundary;
        // routes, operations, head metadata and reporting are derived below
        // from the surviving pages. Native archive resources remain owned by
        // their archive documents and must not be removed with working pages.
        $prunedSources = array_diff_key(array_filter($workingPages, static fn(array $page): bool => !empty($page['synthetic'])), array_column($pages, null, 'source_path'));
        foreach ($assets as &$asset) {
            foreach (array('stylesheet_instances', 'scopes', 'references') as $key) {
                if (!isset($asset[$key])) continue;
                $asset[$key] = array_values(array_filter($asset[$key], static fn(array $row): bool => !isset($prunedSources[$row['source_path'] ?? ''])));
            }
        }
        unset($asset);
        // Shell extraction needed the archive documents, but route overrides
        // belong only to documents that actually materialize in WordPress.
        foreach (TaxonomyProjection::sections($pages, $taxonomyProjection['entities'], $this->sourceOrigin) as $entity) if (!isset($entity['archive'])) $taxonomyProjection['entities'][] = $entity;
        $templates = $this->templates($pages, $parts, $surfaces, $tokens, $references, $routeMap, $singleContent, $taxonomyProjection['entities'], $shellFrames['frames'], $documentChrome['chrome'], $blockThemeChrome['templates']);
        foreach ($templates as &$template) {
            $chrome = $blockThemeChrome['templates'][$template['slug']] ?? null;
            if (!is_string($chrome)) continue;
            $template['shell_frame'] = array_values(array_unique(array_merge($template['shell_frame'] ?? array(), $blockThemeChrome['shells'][$template['slug']] ?? array())));
        }
        unset($template);
        if (array() !== $blockThemeChrome['templates']) foreach ($templates as &$template) {
            if ('single' === $template['slug']) $template['shell_frame'] = reset($blockThemeChrome['shells']);
        }
        unset($template);
        $sharedTemplateTitles = self::shareOverrideTemplates($pages, $parts, $templates);
        $articleChrome = $this->postChrome->extractArticleChrome($pages, $parts, $runtimeDeclarations, $runtimeEntityRecords, $templates);
        $pages = $articleChrome['pages'];
        $templates = $articleChrome['templates'];
        $parts = $articleChrome['parts'];
        // Template-group lifting settles post bodies and can add featured images.
        $pages = $this->listings->materialize($pages, $runtimeDeclarations, $taxonomyProjection['entities'], true);
        $pages = self::resolveFeaturedImageTargets($pages, $tokens);
        $registeredTemplates = array();
        foreach ($pages as $page) if (isset($page['route_template']) && !isset($registeredTemplates[$page['route_template']])) {
            $registeredTemplates[$page['route_template']] = true;
            $themeProjection['theme']['customTemplates'][] = array('name' => $page['route_template'], 'title' => $sharedTemplateTitles[$page['route_template']] ?? $page['title'], 'postTypes' => array($page['post_type']));
        }
        foreach ($taxonomyProjection['entities'] as &$taxonomyEntity) foreach ($templates as $template) if (isset($taxonomyEntity['archive']) && self::archiveTemplateSlug($taxonomyEntity) === ($template['slug'] ?? null)) {
            $taxonomyEntity['archive']['presentation_markup'] = $template['canonical_block_markup'];
            continue 2;
        }
        unset($taxonomyEntity);
        $this->timer?->start('compose.site_plan.asset_writes');
        $routes = $this->routesForPages($pages);
        $emittedMarkup = implode("\n", self::markups(array_merge($pages, $parts, $templates, $syncedPatterns)));
        $menus = array_values(array_filter($menus, static fn(array $menu): bool => !isset($menu['token']) || str_contains($emittedMarkup, self::NAVIGATION_TOKEN_PREFIX . $menu['token'] . '}}')));
        $operations = $this->operations($pages);
        $assets = self::withVariantScopedIdSelectors($assets);
        // Stylesheet values equal to a token follow it too: links inside
        // text, navigation items and states have no block attribute to bind.
        if (null !== $this->presetBinding) foreach ($assets as &$asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null) || isset($asset['payload_reference'])) continue;
            $content = $this->presetBinding->bindStylesheet($asset['content']);
            if ($content === $asset['content']) continue;
            $asset = self::withRewrittenTextContent($asset, $content);
        }
        unset($asset);
        // Asset payloads are the last canonicalization pass, so the placeholder
        // backing recovered media is declared once every reference is known.
        // Every emitted stylesheet is serialized once, at its smallest
        // cascade-equivalent size.
        $compactor = new CssStylesheetTransformer();
        foreach ($assets as &$asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null)) continue;
            $compacted = $compactor->compactStylesheet($asset['content']);
            if ($compacted !== $asset['content']) $asset = self::withRewrittenTextContent($asset, $compacted);
        }
        unset($asset);
        $assetWrites = $this->assetWrites($assets, $references);
        foreach ($assets as &$asset) unset($asset['reference_origin']); unset($asset);
        $placeholderAssets = $this->assets($this->missingMedia->assets());
        if (array() !== $placeholderAssets) {
            $assets = array_merge($assets, $placeholderAssets);
            $tokens = array_merge($tokens, $this->tokens($placeholderAssets));
            $assetWrites = array_merge($assetWrites, $this->assetWrites($placeholderAssets, $references));
        }
        // Asset bytes are final now. Integrity copied from the source must
        // describe them before script loading and the head bootstrap emit it.
        $integrity = new SubresourceIntegrity($assetWrites, $tokens);
        $pages = $integrity->documents($pages);
        $parts = $integrity->documents($parts);
        $scriptLoading = $this->scriptLoading($pages, $parts, $assets, $tokens, $operations, $runtimeDeclarations);
        $writes = array_merge($this->scaffoldWrites($assets, $templates, $parts, $scriptLoading['scripts'], $themeProjection['theme'], $tokens, $pages, $menus, $runtimeEmoji, $rootDocuments), $assetWrites);
        $unitFindings = array_merge($this->unitFindingDiagnostics(), NavigationInstanceDiagnostics::collect($assets, $emittedMarkup . "\n" . implode("\n", self::markups($menus))));
        $recoveryDiagnostics = array_merge($this->routeCollisionDiagnostics(), $this->links->diagnostics(), $this->unresolvedScriptReferenceDiagnostics(), $this->omittedLinkDeclarationDiagnostics(), $unitFindings, $this->missingMedia->diagnostics(), $missingFonts->diagnostics(), $missingImports->diagnostics(), $eventDiagnostics, $paginationDiagnostics);
        // All shell, navigation, listing and script binding projection is now
        // complete. Only the public plan needs the bounded record form.
        // Declarations still over a byte limit are dropped here with their
        // bindings, so their blocks stay static and each drop is a finding.
        $this->timer?->start('compose.site_plan.plan_assembly');
        $factoredRuntimeDeclarations = RuntimeDeclarations::factor($runtimeDeclarations);
        $runtimeDeclarations = $factoredRuntimeDeclarations['declarations'];
        $runtimeRecords = $factoredRuntimeDeclarations['records'];
        $droppedDeclarations = $factoredRuntimeDeclarations['diagnostics'];
        // Entity lists factoring moved out of their declarations join the
        // compiled entity manifests' content-addressed store.
        if (array() !== $factoredRuntimeDeclarations['entity_records']) $runtimeEntityRecords = RuntimeEntityManifest::normalizeRecords(array_values(array_column(array_merge($runtimeEntityRecords, $factoredRuntimeDeclarations['entity_records']), null, 'content_hash')));
        if (array() !== $droppedDeclarations) {
            $runtimeEntityRecords = self::referencedEntityRecords(RuntimeDeclarations::materialize($runtimeDeclarations, $runtimeRecords), $runtimeEntityRecords);
            $pages = self::attachWholePageCandidates(array_map(static function (array $page): array { unset($page['whole_page_candidates']); return $page; }, $pages), RuntimeDeclarations::materialize($runtimeDeclarations, $runtimeRecords), $runtimeEntityRecords);
            $unitFindings = array_merge($unitFindings, $droppedDeclarations);
            $recoveryDiagnostics = array_merge($recoveryDiagnostics, $droppedDeclarations);
        }
        // Shell extraction already knows the final part markup. Locate the
        // emitted identity there, not via source-document structural paths.
        foreach ($data['diagnostics'] as &$diagnostic) {
            if ('author_layout_topology_changed' !== ($diagnostic['code'] ?? '') || empty($diagnostic['block_class_names'])) continue;
            $slugs = array();
            foreach ($parts as $part) {
                preg_match_all('/\bclass="([^"]*)"/', (string) ($part['canonical_block_markup'] ?? ''), $matches);
                foreach ($matches[1] as $classes) {
                    $classTokens = preg_split('/\s+/', html_entity_decode($classes, ENT_QUOTES | ENT_HTML5)) ?: array();
                    if (array() === array_diff($diagnostic['block_class_names'], $classTokens)) {
                        $slugs[] = $part['slug'];
                        break;
                    }
                }
            }
            $slugs = array_values(array_unique($slugs));
            if (1 === count($slugs)) $diagnostic['template_part'] = $slugs[0];
        }
        unset($diagnostic);
        $commentEntities = array();
        foreach ($pages as &$page) {
            array_push($commentEntities, ...($page['document_metadata']['comment_entities'] ?? array()));
            unset($page['document_metadata']['comment_entities']);
        }
        unset($page);
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
            'taxonomy_entities' => array_values(array_filter($taxonomyProjection['entities'], static fn(array $entity): bool => 'taxonomy_term' === $entity['kind'])),
            'comment_entities' => $commentEntities,
            'author_entities' => array_values(array_filter($taxonomyProjection['entities'], static fn(array $entity): bool => 'author' === $entity['kind'])),
            'navigation_links' => $input->navigationLinks,
            'menus' => $menus,
            'theme' => array_merge($runtimeEmoji ? array('runtime_emoji' => 'wordpress') : array(), array('stylesheet' => 'style.css', 'theme_json' => 'theme.json', 'bootstrap' => 'functions.php', 'design_token_provenance' => $themeProjection['provenance']), $rootDocuments ? array('document_roots' => $rootDocuments) : array(), null !== ($themeProjection['responsive_breakpoints'] ?? null) ? array('responsive_breakpoints' => $themeProjection['responsive_breakpoints']) : array(), array() === $input->fontMaterialization ? array() : array('font_materialization' => $input->fontMaterialization), null === $this->designSystem ? array() : array('design_system' => $this->designSystem->report() + array('metrics' => $this->designSystem->metrics(self::markups(array_merge($pages, $templates, $parts)), $assets, array_merge(self::markups(array_merge($pages, $templates)), ...array_fill(0, max(1, count($pages)), self::markups($parts))), $this->presetBinding?->refusals(self::markups(array_merge($pages, $templates, $parts)), array_values(array_map(static fn(array $menu): string => (string) ($menu['block_markup'] ?? ''), $menus))) ?? array(), (int) ($compiled['source_css_bytes'] ?? 0))))),
            'visual_repair' => $compiled['visual_repair'] ?? array(),
            'runtime_declarations' => $runtimeDeclarations,
            'runtime_records' => $runtimeRecords,
            'runtime_entity_records' => $runtimeEntityRecords,
            'diagnostics' => array_merge($data['diagnostics'], $inlineShells['diagnostics'], $shells['diagnostics'], $scriptLoading['diagnostics'], $taxonomyProjection['diagnostics'], $recoveryDiagnostics),
            // A dropped or rewritten unit is a warning on the plan, the same as withFindings().
            'quality' => array('status' => self::statusWithFindings($data['status'], $unitFindings), 'pass' => 'failed' !== $data['status'], 'metrics' => array_diff_key($data['metrics'], array('transform_duration_ms' => true, 'step_timings' => true)), 'fallbacks' => $data['fallbacks'], 'core_html_fallback_evidence' => $input->coreHtmlFallbackEvidence, 'editability_policy' => $editabilityPolicy),
            'reporting' => $this->reporting($pages, $data, $input->coreHtmlFallbackEvidence, array_merge($inlineShells['diagnostics'], $shells['diagnostics'], $scriptLoading['diagnostics'], $taxonomyProjection['diagnostics'], $recoveryDiagnostics), array_merge($surfaces, array_values(array_column(array_filter($templates, static fn(array $template): bool => isset($archiveSources[$template['source_path'] ?? ''])), null, 'source_path')))),
        );
        $plan['reference_semantics']['navigation_entities'] = NavigationEntityProjection::REFERENCE_CONTRACT;
        if (array() !== $syncedPatterns) {
            $plan['synced_patterns'] = $syncedPatterns;
            $plan['reference_semantics']['synced_patterns'] = SyncedPatternProjection::REFERENCE_CONTRACT;
        }
        $this->timer?->start('compose.site_plan.asset_consumers');
        $consumers = AssetConsumers::inventory($plan);
        $unreferenced = array_merge(AssetConsumers::unreferencedImages($plan) ?? array(), self::unreferencedInlineScripts($plan));
        $fontEmbeddingSvgs = AssetConsumers::fontEmbeddingSvgs($plan);
        $unwritten = array();
        foreach ($plan['assets'] as &$asset) {
            $asset['consumers'] = $consumers[$asset['token']] ?? array();
            $asset['destination'] = AssetConsumers::destination($asset['consumers'], $asset, $unreferenced, $fontEmbeddingSvgs);
            $asset['theme_write_reasons'] = self::themeWriteReasons($asset['consumers'], $asset['destination']);
            if ('none' === $asset['destination']) $unwritten[$asset['target_path']] = true;
        }
        unset($asset);
        // An image nothing references is not materialized; its row and token
        // remain so the plan still reports what the source carried.
        if (array() !== $unwritten) $plan['writes'] = array_values(array_filter($plan['writes'], static fn(array $write): bool => !isset($unwritten[$write['target_path']])));
        $plan = self::withContentConservation($plan, $compiled['pages']);
        $plan['plan_identity'] = self::planIdentity($plan);
        self::assertValid($plan);
        return $plan;
    }

    /**
     * Every text run a compiled document carries must survive into the plan:
     * its own page, or a shared surface (template part, template, pattern,
     * menu or runtime record). Text that reaches neither was dropped, so the
     * plan counts it as content loss instead of reporting a clean conversion.
     *
     * @param array<string,mixed> $plan @param array<int,mixed> $documents Compiled pages.
     * @return array<string,mixed>
     */
    public static function withContentConservation(array $plan, array $documents): array
    {
        // Ordinary comments contribute no text or spacing, just as on the
        // source side. Keep block delimiters searchable for attribute text.
        $text = static fn(string $markup): string => preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace_callback('/<!--(.*?)-->/s', static fn(array $match): string => preg_match('/^\s*\/?wp:[a-z0-9-]+(?:\/[a-z0-9-]+)?(?:\s|$)/', $match[1]) ? ' ' . $match[1] . ' ' : '', $markup) ?? ''), ENT_QUOTES | ENT_HTML5)) ?? '';
        $leaves = static function (mixed $value) use (&$leaves): string {
            if (is_string($value)) return $value;
            return is_array($value) ? implode("\n", array_map($leaves, $value)) : '';
        };
        $shared = $text($leaves(array_intersect_key($plan, array_flip(array('template_parts', 'templates', 'synced_patterns', 'menus', 'runtime_records', 'runtime_entity_records')))));
        $pages = array();
        $byType = array();
        foreach ($plan['pages'] as $page) {
            $pages[$page['source_path']] = $text($leaves($page));
            $byType[(string) ($page['post_type'] ?? '')][] = $pages[$page['source_path']];
        }
        // A query loop renders the titles, excerpts and bound meta of the
        // entries it queries, so their text is carried by the listing page.
        foreach ($plan['pages'] as $page) {
            if (!preg_match_all('/<!-- wp:query (\{.*?\}) \/?-->/s', (string) ($page['canonical_block_markup'] ?? ''), $queries)) continue;
            foreach ($queries[1] as $json) {
                $type = json_decode($json, true)['query']['postType'] ?? null;
                if (is_string($type)) $pages[$page['source_path']] .= "\n" . implode("\n", $byType[$type] ?? array());
            }
        }
        // Queries in shared templates render the same post-scoped data as
        // queries in page bodies (including related-post titles).
        foreach ($plan['templates'] ?? array() as $template) {
            if (!preg_match_all('/<!-- wp:query (\{.*?\}) \/?-->/s', (string) ($template['canonical_block_markup'] ?? ''), $queries)) continue;
            foreach ($queries[1] as $json) {
                $type = json_decode($json, true)['query']['postType'] ?? null;
                if (is_string($type)) $shared .= "\n" . implode("\n", $byType[$type] ?? array());
            }
        }
        // A post-date block in a shared template renders each post's own
        // publication date in the authored format.
        foreach ($plan['templates'] ?? array() as $template) {
            if (!preg_match_all('/<!-- wp:post-date (\{.*?\}) \/?-->/s', (string) ($template['canonical_block_markup'] ?? ''), $dates)) continue;
            foreach ($dates[1] as $json) {
                $format = json_decode($json, true)['format'] ?? null;
                if (!is_string($format)) continue;
                foreach ($plan['pages'] as $page) {
                    $published = $page['publication_local_time'] ?? $page['publication_timestamp'] ?? null;
                    if (is_string($published) && false !== ($date = date_create_immutable($published))) $pages[$page['source_path']] .= "\n" . $date->format($format);
                }
            }
        }
        $findings = array();
        foreach ($documents as $document) {
            if (!is_array($document) || !is_string($document['block_markup'] ?? null) || !is_string($document['source_path'] ?? null)) continue;
            $source = preg_replace(array('/<!--.*?-->/s', '#<(script|style|template)\b[^>]*>.*?</\1\s*>#is'), '', $document['block_markup']) ?? '';
            $lost = array();
            foreach (preg_split('/<[^>]*>/', $source) ?: array() as $run) {
                $run = trim(preg_replace('/\s+/u', ' ', html_entity_decode($run, ENT_QUOTES | ENT_HTML5)) ?? '');
                if (!preg_match('/[\p{L}\p{N}].*[\p{L}\p{N}]/u', $run) || isset($lost[$run])) continue;
                if (!str_contains($pages[$document['source_path']] ?? '', $run) && !str_contains($shared, $run)) $lost[$run] = true;
            }
            if (array() === $lost) continue;
            $findings[] = array('code' => 'wordpress_site_plan_content_lost', 'severity' => 'error', 'loss_class' => 'content_loss', 'source_path' => $document['source_path'], 'lost_text_count' => count($lost), 'lost_text' => array_slice(array_keys($lost), 0, 5), 'message' => 'Compiled document text is missing from its page and from every shared surface in the plan.');
        }
        $plan['quality']['metrics']['content_loss_count'] = count($findings);
        return array() === $findings ? $plan : self::withFindings($plan, $findings);
    }

    /**
     * Entity records still referenced once factoring dropped declarations.
     * Records only a dropped manifest referenced leave the plan with it.
     *
     * @param array<int,array<string,mixed>> $declarations @param array<int,array<string,mixed>> $records
     * @return array<int,array<string,mixed>>
     */
    private static function referencedEntityRecords(array $declarations, array $records): array
    {
        $referenced = array();
        foreach ($declarations as $declaration) if (RuntimeEntityManifest::SCHEMA === ($declaration['payload']['schema'] ?? null)) foreach ($declaration['payload']['entities'] ?? array() as $reference) if (is_string($reference['content_hash'] ?? null)) $referenced[$reference['content_hash']] = true;
        return array_values(array_filter($records, static fn (mixed $record): bool => is_array($record) && isset($referenced[$record['content_hash'] ?? ''])));
    }

    /**
     * Declarations with every entity whose bindings the plan's documents must
     * anchor. Record references are materialized. A manifest's entities are its
     * resolved records; once the plan is resolved, their anchors are the ones
     * resolution projected onto the resolved documents.
     *
     * @param array<string,mixed> $plan @param array<int,array<string,mixed>> $runtimeRecords @param array<int,array<string,mixed>> $entityRecords
     * @return array<int,array<string,mixed>>
     */
    private static function anchoredEntityDeclarations(array $plan, array $runtimeRecords, array $entityRecords): array
    {
        $resolved = isset($plan['resolution']) && is_array($plan['runtime_entity_resolution'] ?? null) ? array_column($plan['runtime_entity_resolution'], 'entities', 'reconciliation_identity') : array();
        $declarations = RuntimeDeclarations::materialize($plan['runtime_declarations'], $runtimeRecords);
        foreach ($declarations as &$declaration) {
            if (RuntimeEntityManifest::SCHEMA !== ($declaration['payload']['schema'] ?? null)) continue;
            $entities = isset($plan['resolution']) ? ($resolved[$declaration['reconciliation_identity']] ?? null) : RuntimeEntityManifest::resolve($declaration['payload'], $entityRecords);
            if (!is_array($entities)) throw new InvalidArgumentException('A resolved WordPress site plan does not resolve every runtime entity manifest.');
            $declaration['payload'] = array('schema' => $declaration['payload']['entity_schema'], 'entities' => $entities);
        }
        unset($declaration);
        return $declarations;
    }

    /**
     * Every entity binding names the document that renders its block: a page,
     * or a shared template part that chrome containing the entity moved into.
     *
     * @param array<int,array<string,mixed>> $declarations @param array<int,array<string,mixed>> $pages @param array<int,array<string,mixed>> $parts @param array<int,array<string,mixed>> $assets
     */
    private static function assertEntityBindingsAnchored(array $declarations, array $pages, array $parts, array $assets): void
    {
        $markupBySource = array();
        foreach (array_merge($pages, $parts) as $page) if (is_string($page['source_path'] ?? null) && is_string($page['canonical_block_markup'] ?? null)) $markupBySource[$page['source_path']] = is_string($page['resolved_block_markup'] ?? null) ? $page['resolved_block_markup'] : $page['canonical_block_markup'];
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
                    if ( !is_string($source) || !is_string($search) || '' === $search || !is_int($occurrence) || $occurrence < 1 || !is_string($ownedMarkup) || null === $offset || (null !== $position && (!self::bindingPosition($position, new \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($ownedMarkup), $search) || $position['offset'] !== $offset)) ) throw new InvalidArgumentException('A runtime entity binding no longer has its declared source document block anchor after shell extraction: ' . (is_string($source) ? $source : 'unknown') . ' (' . (is_string($binding['role'] ?? null) ? $binding['role'] : 'unknown') . ').');
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
        $plan['quality']['status'] = self::statusWithFindings($plan['quality']['status'], $diagnostics);
        $plan['plan_identity'] = self::planIdentity($plan);
        self::assertValid($plan);
        return $plan;
    }

    /** A plan that carries warning findings is at best `success_with_warnings`. @param array<int,array<string,mixed>> $findings */
    private static function statusWithFindings(string $status, array $findings): string
    {
        return 'success' === $status && array() !== $findings ? 'success_with_warnings' : $status;
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
        $sourceCatalog = self::sourceDocumentCatalogFromSource($plan['source']);
        RuntimeDeclarations::assertNormalized($plan['runtime_declarations'], !isset($plan['resolution']));
        $records = RuntimeEntityManifest::normalizeRecords($plan['runtime_entity_records']);
        if ($records !== $plan['runtime_entity_records']) throw new InvalidArgumentException('WordPress site plan runtime entity records are not canonically normalized.');
        foreach ($plan['runtime_declarations'] as $declaration) if (RuntimeEntityManifest::SCHEMA === ($declaration['payload']['schema'] ?? null)) RuntimeEntityManifest::resolve($declaration['payload'], $records);
        $runtimeRecords = RuntimeDeclarations::normalizeRecords($plan['runtime_records'] ?? array());
        if ($runtimeRecords !== ($plan['runtime_records'] ?? array())) throw new InvalidArgumentException('WordPress site plan runtime records are not canonically normalized.');
        self::assertEntityBindingsAnchored(self::anchoredEntityDeclarations($plan, $runtimeRecords, $records), $plan['pages'], array_merge($plan['template_parts'], $plan['synced_patterns'] ?? array(), $plan['templates']), $plan['assets']);
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
            if ( ! is_array($asset) || ! self::safePath($asset['source_path'] ?? null) || ! self::safePath($asset['target_path'] ?? null) || !is_string($asset['source'] ?? null) || !is_string($asset['role'] ?? null) || !is_string($asset['mime_type'] ?? null) || !is_int($asset['bytes'] ?? null) || $asset['bytes'] < 0 || !is_string($asset['token'] ?? null) || !self::hash($asset['reconciliation_identity'] ?? null) || !self::hash($asset['content_hash'] ?? null) || (!is_string($assetContent) && !self::payloadReference($reference)) || $asset['reconciliation_identity'] !== self::identity('asset', $asset['source_path'], $asset['target_path']) || (is_string($assetContent) && $asset['content_hash'] !== self::contentHash($assetContent)) || (is_string($asset['content_base64'] ?? null) && ($asset['transport_sha256'] ?? null) !== $asset['content_hash']) || (is_array($reference) && (!self::referenceBackedBinaryAsset($asset) || isset($asset['content'], $asset['content_base64'], $asset['transport_sha256']) || $asset['content_hash'] !== $reference['sha256'] || ($asset['raw_sha256'] ?? null) !== $reference['sha256']) ) ) {
                throw new InvalidArgumentException('WordPress site plan asset is structurally invalid.');
            }
            if ('css' === $asset['kind']) {
                self::assertAssetScopes($asset['scopes'] ?? null);
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
            self::assertNoLocalBrowserReferences($menu['block_markup']);
        }
        foreach ($plan['synced_patterns'] ?? array() as $pattern) {
            self::assertTokens($pattern['canonical_block_markup'], $tokens);
            self::assertNoLocalBrowserReferences($pattern['canonical_block_markup'], $pattern['source_path']);
        }
        $partSlugs = array();
        $overrideTemplateSlugs = self::overrideTemplateSlugs($plan['template_parts'], array_column(array_filter($plan['templates'], static fn(array $template): bool => array_key_exists('shell_frame', $template)), 'slug'));
        foreach ($plan['pages'] as $page) if (isset($page['route_template'])) $overrideTemplateSlugs[$page['route_template']] = true;
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
        $pageParents = array_fill_keys(array_column($plan['pages'], 'parent_source_path'), true);
        $pageTemplateKeys = array_fill_keys(array_map(self::routeTemplateKey(...), $plan['pages']), true);
        foreach ($plan['pages'] as $page) if (!empty($page['synthetic']) && !isset($pageParents[$page['source_path']])) throw new InvalidArgumentException('WordPress site plan synthetic parent must have a materialized child.');
        $archiveDocuments = array();
        foreach (array_merge($plan['taxonomy_entities'], $plan['author_entities']) as $entity) {
            if (!array_key_exists('archive', $entity)) continue;
            $source = $entity['archive']['source_path'] ?? '';
            $route = $entity['archive']['source_route'] ?? '';
            if (!isset($sourceCatalog[$source]) || isset($pagesBySource[$source])) throw new InvalidArgumentException('WordPress site plan native archive must name a captured source without a materialized page.');
            $archiveDocuments[$source] = array('source_path' => $source, 'post_type' => 'page', 'route' => array('path' => $route), 'reconciliation_identity' => self::identity('page', $source, $route));
        }
        self::assertTaxonomyEntities($plan['taxonomy_entities'], $pagesBySource);
        self::assertAuthorEntities($plan['author_entities'], $pagesBySource);
        $expectedCandidatePages = self::attachWholePageCandidates(array_map(static function (array $page): array { unset($page['whole_page_candidates']); return $page; }, $plan['pages']), RuntimeDeclarations::materialize($plan['runtime_declarations'], $runtimeRecords), $records);
        foreach ($plan['pages'] as $index => $page) if (($expectedCandidatePages[$index]['whole_page_candidates'] ?? null) !== ($page['whole_page_candidates'] ?? null)) throw new InvalidArgumentException('Whole-page candidates are stale, duplicated, or detached from their canonical source pages.');
        $documentsBySource = self::assetOwnerDocuments($plan['pages'], $plan['theme']['document_roots'] ?? array());
        foreach ($plan['assets'] as $asset) foreach ($asset['scopes'] ?? array() as $scope) if ('global' !== $scope['kind']) {
            $document = $documentsBySource[$scope['source_path']] ?? null;
            if (!is_array($document) || $scope != self::stylesheetDocumentScope($document)) throw new InvalidArgumentException('An asset scope does not match its canonical document.');
        }
        // A document position holds exactly one stylesheet instance, across
        // every resource, so each route replays one unambiguous sequence.
        $identities = array();
        foreach ($plan['assets'] as $asset) {
            if (isset($asset['stylesheet_instances']) && ('css' !== $asset['kind'] || !is_array($asset['stylesheet_instances']))) throw new InvalidArgumentException('Only stylesheet resources may declare ordered instances.');
            foreach ($asset['stylesheet_instances'] ?? array() as $instance) {
                if (!is_array($instance) || array('source_path', 'order', 'media', 'type') !== array_keys(array_diff_key($instance, array_flip(array(CaptureEvidence::DEVICE_STYLE, CaptureEvidence::SOURCE_MEDIA)))) || !isset($documentsBySource[$instance['source_path'] ?? '']) || !is_int($instance['order'] ?? null) || $instance['order'] < 0 || !is_string($instance['media'] ?? null) || !is_string($instance['type'] ?? null) || array_filter(self::stylesheetDeviceAttributes($instance), static fn(mixed $value): bool => !is_string($value))) throw new InvalidArgumentException('Stylesheet instance must identify a canonical document, order and conditions.');
                // A frontend-only and an editor-only copy share their position:
                // each context still replays exactly one resource there.
                foreach (array('frontend', 'editor') as $context) {
                    if (!in_array($asset['stylesheet_target'] ?? 'both', array('both', $context), true)) continue;
                    $identity = $instance['source_path'] . "\0" . $instance['order'] . "\0" . $context;
                    if (isset($identities[$identity])) throw new InvalidArgumentException('Two stylesheet instances claim one document position.');
                    $identities[$identity] = true;
                }
            }
        }
        $routeSources = array(); foreach ($plan['routes'] as $route) { self::unique($routeSources, $route['source_path'], 'route source'); $page = $pagesBySource[$route['source_path']] ?? null; if (!is_array($page) || $route['target_path'] !== $page['route']['path'] || $route['target_slug'] !== $page['slug']) throw new InvalidArgumentException('WordPress site plan routes do not match canonical page routes.'); }
        if (count($routeSources) !== count($pagePaths)) throw new InvalidArgumentException('WordPress site plan must export every canonical page route.');
        // Source identities compare case-insensitively, as unique() keys them.
        self::assertReporting($plan['reporting'], array_merge($pagePaths, array_change_key_case(array_fill_keys(array_filter(array_column($plan['templates'], 'source_path'), 'is_string'), true), CASE_LOWER)), $tokens, $plan['diagnostics']);
        self::assertOperations($plan['operations'], $plan['pages']);
        $templateTargets = array();
        foreach ( $plan['templates'] as $template ) {
            $sourcePath = is_array($template) && is_string($template['source_path'] ?? null) ? $template['source_path'] : 'wordpress-site-plan/' . ($template['target_path'] ?? '');
            if ( ! is_array($template) || ! is_string($template['slug'] ?? null) || ! self::safePath($template['target_path'] ?? null) || ! is_string($template['canonical_block_markup'] ?? null) || '' === trim($template['canonical_block_markup']) || !self::hash($template['reconciliation_identity'] ?? null) || !self::hash($template['content_hash'] ?? null) || $template['reconciliation_identity'] !== self::identity('template', $sourcePath, $template['target_path']) || $template['content_hash'] !== self::contentHash($template['canonical_block_markup']) ) {
                throw new InvalidArgumentException('WordPress site plan template is structurally invalid.');
            }
            if (isset($template['template_surface']) && (!is_array($template['template_surface']) || !self::validTemplateSurface($template['template_surface'], $template['source_path'] ?? null, $sourceCatalog) || $template['slug'] !== $template['template_surface']['slug'] || !is_string($template['source_path'] ?? null) || !is_array($template['provenance'] ?? null))) throw new InvalidArgumentException('WordPress site plan declared template surface is structurally invalid.');
            if (str_starts_with($template['slug'], 'page-') && !isset($pageTemplateKeys[$template['slug']])) throw new InvalidArgumentException('WordPress site plan page override must resolve to a materialized page.');
            self::unique($templateTargets, $template['target_path'], 'template target');
            self::assertTokens($template['canonical_block_markup'], $tokens);
            self::assertNoLocalBrowserReferences($template['canonical_block_markup']);
        }
        foreach (array_merge($plan['taxonomy_entities'], $plan['author_entities']) as $entity) {
            if (!isset($entity['archive'])) continue;
            $templateSlug = self::archiveTemplateSlug($entity);
            $archiveTemplates = array_values(array_filter($plan['templates'], static fn(array $template): bool => $templateSlug === ($template['slug'] ?? null)));
            if (!array_key_exists('presentation_markup', $entity['archive'])) {
                if (array() !== $archiveTemplates) throw new InvalidArgumentException('WordPress site plan fallback archive must not have a per-entity template.');
                continue;
            }
            if (1 !== count($archiveTemplates) || ($archiveTemplates[0]['source_path'] ?? null) !== $entity['archive']['source_path'] || ($archiveTemplates[0]['canonical_block_markup'] ?? null) !== $entity['archive']['presentation_markup']) throw new InvalidArgumentException('WordPress site plan native archive entity lacks its source-owned archive template.');
        }
        $writeTargets = array();
        $writesByTarget = array();
        foreach ( $plan['writes'] as $write ) {
            $mimeType = is_array($write) ? ($assetMimeTypes[$write['target_path'] ?? ''] ?? null) : null;
            self::assertWrite($write, $tokens, !isset($plan['resolution']) && (null === $mimeType || in_array($mimeType, array('text/css', 'text/html', 'image/svg+xml'), true)));
            self::unique($writeTargets, $write['target_path'], 'write target', (string) ($write['source_path'] ?? ''));
            $writesByTarget[$write['target_path']] = $write;
        }
        self::assertResolution($plan, $tokens, $writesByTarget);
        self::assertScaffold($plan, $writesByTarget);
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
            $boundTemplates = in_array($part['placement']['kind'] ?? null, array('entry_shell', 'shared_shell'), true) ? $part['placement']['template_slugs'] : array();
            if (in_array($part['placement']['kind'] ?? null, array('entry_shell', 'shared_shell'), true)) foreach (array_keys($overrideTemplateSlugs) as $slug) if (self::shellPartAppliesToOverride($part, $slug)) $boundTemplates[] = $slug;
            if (in_array('archive', $part['placement']['template_slugs'] ?? array(), true)) foreach (array_merge($plan['taxonomy_entities'], $plan['author_entities']) as $entity) {
                if (!isset($entity['archive'])) continue;
                $boundTemplates[] = self::archiveTemplateSlug($entity);
                if ('author' === $entity['kind']) $boundTemplates[] = 'author';
            }
            if ('inline_shared_shell' === ($part['placement']['kind'] ?? null)) foreach ($plan['templates'] as $template) if (in_array($part['slug'], $template['shell_frame'] ?? array(), true)) $boundTemplates[] = $template['slug'];
            if ('inline_shared_shell' === ($part['placement']['kind'] ?? null)) foreach (array_merge($plan['taxonomy_entities'], $plan['author_entities']) as $entity) if (isset($entity['archive']) && in_array($entity['archive']['source_path'], $part['placement']['source_paths'] ?? array(), true)) foreach ($plan['templates'] as $template) if ((self::archiveTemplateSlug($entity) === ($template['slug'] ?? null) || ('author' === $entity['kind'] && 'author' === ($template['slug'] ?? null) && $template['source_path'] === $entity['archive']['source_path'])) && str_contains($template['canonical_block_markup'], '"slug":"' . $part['slug'] . '"')) $boundTemplates[] = $template['slug'];
            foreach ( $plan['templates'] as $template ) {
                $references = substr_count($template['canonical_block_markup'], '"slug":"' . $part['slug'] . '"');
                // An inline shell is bound where it sits, so a template that
                // keeps every captured document copy binds it once per copy.
                $allowed = 'inline_shared_shell' === ($part['placement']['kind'] ?? null) ? self::documentCopyCount($template['canonical_block_markup']) : 1;
                $contentOwnsChrome = self::contentCarriesChrome($template['canonical_block_markup'], $part['area']);
                foreach ($plan['pages'] as $page) if (self::routeTemplateKey($page) === $template['slug'] && self::contentCarriesChrome($page['canonical_block_markup'], $part['area'])) $contentOwnsChrome = true;
                if (0 === $references && $contentOwnsChrome) continue;
                if (in_array($template['slug'], $boundTemplates, true) && ($references < 1 || $references > $allowed)) throw new InvalidArgumentException('WordPress site plan template part binding is invalid.');
                if (!in_array($template['slug'], $boundTemplates, true) && 0 !== $references) throw new InvalidArgumentException('WordPress site plan has an unproven template part binding.');
            }
        }
        // Resolution rewrites tokens to URLs, so a resolved plan keeps the
        // unresolved plan's proof for unwritten assets.
        $unreferenced = isset($plan['resolution']) ? array_fill_keys(array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'none' === ($asset['destination'] ?? null)), 'token'), true) : array_merge(AssetConsumers::unreferencedImages($plan) ?? array(), self::unreferencedInlineScripts($plan));
        $fontEmbeddingSvgs = AssetConsumers::fontEmbeddingSvgs($plan);
        foreach ( $plan['assets'] as $asset ) {
            $expectedConsumers = $asset['consumers'] ?? array();
            if (($asset['consumers'] ?? null) !== $expectedConsumers || ($asset['destination'] ?? null) !== AssetConsumers::destination($expectedConsumers, $asset, $unreferenced, $fontEmbeddingSvgs) || ($asset['theme_write_reasons'] ?? null) !== self::themeWriteReasons($expectedConsumers, $asset['destination'])) throw new InvalidArgumentException('Asset consumers or destination are stale.');
            $target = $asset['target_path'];
            if ('none' === $asset['destination']) {
                if (isset($writesByTarget[$target])) throw new InvalidArgumentException('WordPress site plan writes an unreferenced asset.');
                continue;
            }
            if ('media_library' === $asset['destination'] && !isset($writesByTarget[$target])) continue;
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
        // Bound retention for long-lived processes without an eviction index.
        if (count($validated) >= 4096) $validated = array();
        $validated[$contentHash] = true;
    }

    /** Inline scripts are published only when the script-loading plan enqueues them. */
    private static function unreferencedInlineScripts(array $plan): array
    {
        $loading = (new self())->scriptLoading($plan['pages'], $plan['template_parts'], $plan['assets'], $plan['reference_tokens'], $plan['operations'], $plan['runtime_declarations']);
        $targets = array_fill_keys(array_filter(array_column($loading['scripts'], 'local_target'), 'is_string'), true);
        $unreferenced = array();
        foreach ($plan['assets'] as $asset) {
            if ('inline-script' === ($asset['source'] ?? null) && !isset($targets[$asset['target_path']])) $unreferenced[$asset['token']] = true;
        }
        return $unreferenced;
    }

    /** Explicit counted fallback: uploads have no portable relative CSS URL. */
    private static function themeWriteReasons(array $consumers, string $destination): array
    {
        if ('media_library' !== $destination) return array();
        $reasons = array();
        foreach ($consumers as $consumer) if ('stylesheet' === $consumer['kind']) $reasons[] = array('reason' => 'attachment_url_required', 'target_path' => $consumer['target_path'], 'count' => $consumer['count']);
        return $reasons;
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
        $pages = ArrayField::array($compiledSite, 'pages');
        $parts = ArrayField::array($compiledSite, 'template_parts');
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
            if ('page' === $documentKind && self::emptyRedirectDocument($document)) continue;
            // Native term captures may contain no presentation at all. Defer
            // their content validation until taxonomy ownership is resolved;
            // any unproven capture still faces the final page contract.
            if ('page' === $documentKind) {
                $classes = DocumentRootContext::bodyClasses($document);
                if (in_array('archive', $classes, true) && (in_array('tag', $classes, true) || in_array('category', $classes, true))) continue;
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
        $path = mb_strcut($failure['source_path'], 0, 256, 'UTF-8');
        $kind = 'template_part' === $failure['document_kind'] ? 'template part' : 'page';
        $named = '' === $path ? 'Compiled ' . $kind : 'Compiled ' . $kind . ' "' . $path . '"';
        $message = 'unsafe_identity' === $failure['reason']
            ? $named . ' lacks a safe source path.'
            : $named . ' has empty block markup.';

        return array(
            'code' => 'wordpress_site_plan_not_self_contained',
            'severity' => 'error',
            'message' => mb_strcut($message, 0, 256, 'UTF-8'),
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
            $canonical = $this->links->markup($markup, $document['source_path'], $routes);
            $target = $part ? 'parts/' . self::value($document, 'slug') . '.html' : self::value($document, 'source_path');
            $area = $part ? self::value($document, 'area', 'uncategorized') : null;
            $row = array('source_path' => $document['source_path'], 'slug' => self::value($document, 'slug'), 'title' => self::value($document, 'title'), 'post_type' => self::value((array) ($document['metadata'] ?? array()), 'post_type', 'page'), 'parent_source_path' => self::value((array) ($document['metadata'] ?? array()), 'parent_source_path'), 'entrypoint' => ! empty($document['entrypoint']), 'area' => $area, 'tag_name' => $part ? self::value($document, 'tag_name', ShellLandmarkPolicy::templatePartAreaTagName((string) $area)) : null, 'placement' => $part && is_array($document['placement'] ?? null) ? $document['placement'] : ($part ? array('kind' => 'unbound') : null), 'canonical_block_markup' => $canonical, '_projected_source_block_markup' => $document['block_markup'], 'metadata' => ArrayField::array($document, 'metadata'), 'document_metadata' => $this->documentMetadata($document, $references, $routes), 'provenance' => ArrayField::array($document, 'provenance'), 'reconciliation_identity' => self::identity($part ? 'template-part' : 'page', $document['source_path'], $target), 'content_hash' => self::contentHash($canonical));
            if (!$part && is_array($document['content_decision'] ?? null)) {
                $row['content_decision'] = $document['content_decision'];
                if (is_string($document['publication_timestamp'] ?? null)) $row['publication_timestamp'] = $document['publication_timestamp'];
                if (is_string($document['publication_local_time'] ?? null)) $row['publication_local_time'] = $document['publication_local_time'];
            }
            if ( ! $part ) $row['shell_candidates'] = $this->shellExtraction->shellCandidates($document, $references, $routes, $canonical);
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Keep variant-scoped author rules on the ids the page actually renders.
     *
     * When a captured desktop/mobile pair shares an id, the mobile copy is
     * rendered as `<id>--data-blocks-engine-capture-mobile` so the page keeps unique ids. The delivered
     * stylesheets still address the source id (`:where(.data-blocks-engine-capture-mobile-document)
     * #<id>`), so without this pass every mobile id rule misses its element.
     * It runs after shared-chrome extraction, which reasons about the source
     * ids, and only changes selectors scoped to a non-default document variant.
     *
     * @param array<int,array<string,mixed>> $assets
     * @return array<int,array<string,mixed>>
     */
    private static function withVariantScopedIdSelectors(array $assets): array
    {
        foreach ($assets as &$asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null) || isset($asset['payload_reference'])) continue;
            $content = DocumentVariantIds::scopeIdSelectors($asset['content']);
            if ($content === $asset['content']) continue;
            $asset = self::withRewrittenTextContent($asset, $content);
        }
        unset($asset);
        return $assets;
    }

    /**
     * An asset whose text was rewritten in place. Its transport twin
     * (`content_base64` and `transport_sha256`) described the bytes as
     * delivered, so it is dropped rather than left to contradict the content.
     *
     * @param array<string,mixed> $asset
     * @return array<string,mixed>
     */
    public static function withRewrittenTextContent(array $asset, string $content): array
    {
        $asset['content'] = $content;
        $asset['bytes'] = strlen($content);
        $asset['content_hash'] = self::contentHash($content);
        if (array_key_exists('hash', $asset)) $asset['hash'] = self::contentHash($content);
        unset($asset['content_base64'], $asset['transport_sha256']);
        return $asset;
    }

    /** @param mixed $assets @return array<int,array<string,mixed>> */
    private function assets(mixed $assets): array
    {
        if ( ! is_array($assets) ) throw new InvalidArgumentException('Compiled site assets must be an array.');
        $rows = array();
        $rowByTarget = array();
        foreach ( $assets as $asset ) {
            if ( ! is_array($asset) || ! self::safePath($asset['path'] ?? null) ) throw new InvalidArgumentException('Compiled site asset lacks a safe source identity.');
            // The compiler retains rejected source assets for diagnostics. They have no
            // payload and therefore are not materializable theme artifacts.
            if ( ! is_string($asset['content'] ?? null) && ! is_string($asset['content_base64'] ?? null) && !self::payloadReference($asset['payload_reference'] ?? null) ) continue;
            $compiledTarget = $asset['target_path'] ?? $asset['path'];
            if ( ! self::safePath($compiledTarget) ) throw new InvalidArgumentException('Compiled site asset lacks a safe target identity.');
            $target = 'assets/' . str_replace('\\', '/', $compiledTarget);
            if ( ! self::safePath($target) ) throw new InvalidArgumentException('Compiled site asset lacks a safe target identity.');
            $assetContent = is_string($asset['content'] ?? null) ? $asset['content'] : null;
            $payload = is_string($asset['content_base64'] ?? null) ? $asset['content_base64'] : (string) ($assetContent ?? '');
            $reference = self::payloadReference($asset['payload_reference'] ?? null);
            if (null !== $reference && !self::referenceBackedBinaryAsset($asset)) throw new InvalidArgumentException('WordPress site plan payload references are limited to non-SVG binary assets.');
            $transportHash = is_string($asset['content_base64'] ?? null) ? self::contentHash($asset['content_base64']) : null;
            $contentHash = $reference['sha256'] ?? self::contentHash($payload);
            $priorReferences = array();
            if (isset($rowByTarget[strtolower($target)])) [$target, $priorReferences] = $this->collidingAssetTarget($rows, $rowByTarget, $target, (string) $asset['path'], $contentHash);
            $rows[] = array_filter(array('source_path' => $asset['path'], 'target_path' => $target, 'token' => 'asset-' . substr(hash('sha256', $target), 0, 16), 'source' => self::value($asset, 'source'), 'source_role' => self::value($asset, 'source_role'), 'pipeline_sanitized' => $asset['pipeline_sanitized'] ?? null, 'kind' => self::value($asset, 'kind'), 'role' => self::value($asset, 'role'), 'stylesheet_placement' => self::value($asset, 'stylesheet_placement'), 'stylesheet_target' => 'css' === ($asset['kind'] ?? '') ? (self::value($asset, 'stylesheet_target') ?? 'both') : null, 'intent' => self::value($asset, 'intent'), 'mime_type' => self::value($asset, 'mime_type'), 'media' => self::value($asset, 'media'), 'placement' => self::value($asset, 'placement'), 'defer' => !empty($asset['defer']) ? true : null, 'async' => !empty($asset['async']) ? true : null, 'selector' => self::value($asset, 'selector'), 'references' => is_array($asset['references'] ?? null) || array() !== $priorReferences ? self::mergedReferences($priorReferences, ArrayField::array($asset, 'references')) : null, 'bytes' => (int) ($asset['bytes'] ?? 0), 'hash' => self::value($asset, 'hash'), 'content' => $assetContent, 'content_base64' => $asset['content_base64'] ?? null, 'payload_reference' => $reference, 'raw_sha256' => $reference['sha256'] ?? ($asset['raw_sha256'] ?? null), 'transport_sha256' => $transportHash, 'binary' => ! empty($asset['binary']), 'compilation' => is_array($asset['compilation'] ?? null) ? $asset['compilation'] : null, 'stylesheet_link_position' => is_int($asset['stylesheet_link_position'] ?? null) ? $asset['stylesheet_link_position'] : null, 'reconciliation_identity' => self::identity('asset', $asset['path'], $target), 'content_hash' => $contentHash), static fn(mixed $value): bool => null !== $value);
            $rowByTarget[strtolower($target)] = array_key_last($rows);
            if (isset($asset['inline_references'])) $rows[array_key_last($rows)]['inline_references'] = $asset['inline_references'];
            if (isset($asset['source_media']) && is_string($asset['source_media'])) $rows[array_key_last($rows)]['source_media'] = $asset['source_media'];
            if (isset($asset[CaptureEvidence::DEVICE_STYLE])) $rows[array_key_last($rows)][CaptureEvidence::DEVICE_STYLE] = $asset[CaptureEvidence::DEVICE_STYLE];
            if (isset($asset['stylesheet_activation'])) {
                $rows[array_key_last($rows)]['stylesheet_activation'] = $asset['stylesheet_activation'];
                $rows[array_key_last($rows)]['stylesheet_source_path'] = $asset['stylesheet_source_path'] ?? $asset['path'];
            }
        }
        $rows = array_values($rows);
        $assetsByPath = array_column($assets, null, 'path');
        foreach ($rows as &$row) {
            $source = $assetsByPath[$row['source_path']];
            if (is_string($source['stylesheet_parent'] ?? null)) $row['stylesheet_parent'] = $source['stylesheet_parent'];
            if (is_int($source['stylesheet_inline_order'] ?? null)) $row['stylesheet_inline_order'] = $source['stylesheet_inline_order'];
        }
        unset($row);
        return $rows;
    }

    /**
     * Two compiled assets that resolve to one theme target cannot both be
     * written. When their bytes are identical they are one asset: the earlier
     * row is removed and the later one, whose position wins the cascade for
     * the same rules, keeps every reference. Otherwise the later asset gets a
     * deterministic numbered target (`name-2.css`) and a token derived from
     * it. Either way the plan records a `wordpress_site_plan_colliding_asset_target`
     * finding; the strict validator keeps rejecting a plan that still collides.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,int> $rowByTarget
     * @return array{0:string,1:array<int,mixed>} The target for the incoming asset and references carried over from a removed duplicate.
     */
    private function collidingAssetTarget(array &$rows, array $rowByTarget, string $target, string $sourcePath, string $contentHash): array
    {
        $index = $rowByTarget[strtolower($target)];
        $prior = $rows[$index];
        $finding = array('code' => 'wordpress_site_plan_colliding_asset_target', 'unit' => 'asset', 'source_path' => $sourcePath, 'colliding_source_path' => (string) $prior['source_path'], 'target_path' => $target);
        if ($contentHash === ($prior['content_hash'] ?? null)) {
            unset($rows[$index]);
            $this->recordUnitFinding($finding + array('message' => 'Two assets resolved to one target with identical content; they were written once.', 'reason_code' => 'identical_content', 'resolved_target_path' => $target));
            return array($target, ArrayField::array($prior, 'references'));
        }
        $dot = strrpos($target, '.');
        $slash = strrpos($target, '/');
        $stem = false !== $dot && (false === $slash || $dot > $slash) ? substr($target, 0, $dot) : $target;
        $extension = $stem === $target ? '' : substr($target, strlen($stem));
        for ($suffix = 2; isset($rowByTarget[strtolower($candidate = $stem . '-' . $suffix . $extension)]); ++$suffix);
        $this->recordUnitFinding($finding + array('message' => 'Two assets resolved to one target with different content; the later one was given a suffixed target.', 'reason_code' => 'colliding_asset_target', 'resolved_target_path' => $candidate));
        return array($candidate, array());
    }
    /** @param array<int,mixed> $prior @param array<int,mixed> $references @return array<int,mixed> */
    private static function mergedReferences(array $prior, array $references): array
    {
        foreach ($references as $reference) if (!in_array($reference, $prior, true)) $prior[] = $reference;
        return $prior;
    }

    /** @param array<int,array<string,mixed>> $assets @param array<string,array<string,mixed>> $documentsBySource @return array<int,array<string,mixed>> */
    private function scopeAssets(array $assets, array $documentsBySource): array
    {
        $stylesheetSets = array();
        foreach ($assets as $asset) {
            $state = $asset['stylesheet_activation'] ?? null;
            if (is_array($state) && ('' !== $state['title'] || !$state['active'])) $stylesheetSets[$asset['stylesheet_source_path']] = true;
        }
        foreach ($assets as &$asset) {
            $compilation = $asset['compilation'] ?? null;
            $stylesheetSource = $asset['stylesheet_source_path'] ?? null;
            unset($asset['compilation'], $asset['stylesheet_link_position'], $asset['stylesheet_source_path']);
            if ('css' !== $asset['kind']) continue;
            // A source payload can be preferred here and active or disabled on
            // another route. Its link occurrences, rather than shared chrome
            // ownership, determine where each activation variant is delivered.
            if (isset($asset['stylesheet_activation'], $stylesheetSets[$stylesheetSource ?? '']) && 'page' !== ($compilation['scope'] ?? null)) $compilation = null;
            // Explicit page ownership wins over inherited occurrence records.
            if (isset($asset['inline_references']) && 'page' !== ($compilation['scope'] ?? null)) {
                $referencedPages = array();
                foreach ($asset['inline_references'] as $reference) if (isset($documentsBySource[$reference['source_path']])) $referencedPages[$reference['source_path']] = $documentsBySource[$reference['source_path']];
                $asset['scopes'] = array_values(array_map(static fn(array $page): array => self::stylesheetDocumentScope($page), $referencedPages));
                if (array() === $asset['scopes']) $asset['scopes'] = array(array('kind' => 'global'));
                continue;
            }
            if ('shared' === ($compilation['scope'] ?? null)) {
                $sources = $compilation['source_paths'] ?? null;
                $consumers = is_array($sources) ? array_intersect_key($documentsBySource, array_fill_keys($sources, true)) : array();
                // A deduplicated document payload is shared only by its
                // consumers, not by every route. Truly site-wide support and
                // isolated shared-shell compilations keep their global scope.
                $canonicalPages = array_filter($documentsBySource, static fn(array $page): bool => empty($page['synthetic']));
                $asset['scopes'] = null === $sources || count($consumers) === count($canonicalPages)
                    ? array(array('kind' => 'global'))
                    : array_values(array_map(self::stylesheetDocumentScope(...), $consumers));
                if (array() === $asset['scopes']) $asset['scopes'] = array(array('kind' => 'global'));
                continue;
            }
            if (null === $compilation) {
                $referencedPages = array();
                foreach (ArrayField::array($asset, 'references') as $reference) {
                    $sourcePath = is_array($reference) && 'link' === ($reference['element'] ?? null) ? ($reference['source_path'] ?? null) : null;
                    if (is_string($sourcePath) && is_array($documentsBySource[$sourcePath] ?? null)) $referencedPages[$sourcePath] = $documentsBySource[$sourcePath];
                }
                if (array() === $referencedPages) {
                    $asset['scopes'] = array(array('kind' => 'global'));
                    continue;
                }
                // A stylesheet every canonical source document links is
                // site-wide author CSS: WordPress-native views (404, search,
                // archives) render the shared chrome it dresses, so they load
                // it through the route-flag fallback in source order, ahead of
                // the shared-chrome projections lifted from it. Route
                // documents keep their per-occurrence instance enqueues, and a
                // stylesheet only some documents link stays route-only.
                // Generated synthetic route pages capture nothing, so they do
                // not hold a site-wide stylesheet back.
                if (count($referencedPages) === count(array_filter($documentsBySource, static fn(array $page): bool => empty($page['synthetic'])))) {
                    $asset['scopes'] = array(array('kind' => 'global'));
                    continue;
                }
                $asset['scopes'] = array_values(array_map(static fn(array $page): array => self::stylesheetDocumentScope($page), $referencedPages));
                continue;
            }
            if (!is_array($compilation) || 'page' !== ($compilation['scope'] ?? null) || !is_string($compilation['id'] ?? null) || !is_array($documentsBySource[$compilation['id']] ?? null)) {
                throw new InvalidArgumentException('A document-owned compiled asset must resolve to a canonical document.');
            }
            $page = $documentsBySource[$compilation['id']];
            $asset['scopes'] = array(self::stylesheetDocumentScope($page));
        }
        unset($asset);
        return self::allocateDocumentSupport($assets, self::canonicalSupportDocuments($documentsBySource));
    }

    /**
     * @param array<string,array<string,mixed>> $documentsBySource
     * @return list<string>
     */
    private static function canonicalSupportDocuments(array $documentsBySource): array
    {
        return array_keys(array_filter($documentsBySource, static fn(array $page): bool => empty($page['synthetic'])));
    }

    /**
     * Fold each run of adjacent allocated support sheets with one delivery
     * into a single resource, in their existing rule order. A rule reaches
     * routes outside its owners only where it provably matches nothing in
     * their documents; otherwise a zero-weight route gate keeps it on its
     * owners. Rules that can be neither proven nor gated keep their sheet.
     *
     * Each document is proven with every template part's markup, since any
     * of them may render on it.
     *
     * @param array<int,array<string,mixed>> $assets
     * @param array<string,array<string,mixed>> $documentsBySource
     * @param list<string> $canonicalSources
     * @return array<int,array<string,mixed>>
     */
    private static function foldDocumentSupport(array $assets, array $documentsBySource, array $canonicalSources, string $sharedMarkup = ''): array
    {
        $routeClasses = array();
        foreach ($canonicalSources as $source) {
            $class = (string) ($documentsBySource[$source]['document_metadata']['root_attributes']['class'] ?? '');
            if (preg_match('/(?:^|\s)(be-r-[0-9a-f]+)(?:\s|$)/', $class, $match)) $routeClasses[$source] = $match[1];
        }
        $index = null;
        $transformer = new \Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer();
        // The rules a member contributes to a fold, or null when one of them
        // can be neither proven inert outside its owners nor gated to them.
        $foldable = static function (array $asset) use (&$index, $documentsBySource, $canonicalSources, $routeClasses, $transformer, $sharedMarkup): ?string {
            $owners = array_column($asset['scopes'], 'source_path');
            if (in_array('global', array_column($asset['scopes'], 'kind'), true)) return (string) $asset['content'];
            $outside = array_fill_keys(array_diff($canonicalSources, $owners), true);
            $gate = array();
            foreach ($owners as $owner) {
                if (!isset($routeClasses[$owner])) return null;
                $gate[] = '.' . $routeClasses[$owner];
            }
            sort($gate);
            $scope = ':where(:root:is(' . implode(',', $gate) . '))';
            if (null === $index) {
                $documents = array();
                foreach ($canonicalSources as $source) {
                    // A document without emitted markup (a WordPress-rendered
                    // archive) is never evidence that a rule is inert there.
                    if (!is_string($documentsBySource[$source]['canonical_block_markup'] ?? null) || '' === trim($documentsBySource[$source]['canonical_block_markup'])) continue;
                    $dom = HtmlSerialization::parseDocument('<html><body>' . $documentsBySource[$source]['canonical_block_markup'] . "\n" . $sharedMarkup . '</body></html>');
                    foreach (array('html' => 'root_attributes', 'body' => 'body_attributes') as $tag => $key) {
                        $element = $dom->getElementsByTagName($tag)->item(0);
                        foreach ($documentsBySource[$source]['document_metadata'][$key] ?? array() as $name => $value) $element?->setAttribute((string) $name, (string) $value);
                    }
                    $documents[$source] = $dom;
                }
                $index = new \Automattic\BlocksEngine\PhpTransformer\Css\RouteSelectorMatchIndex($documents);
            }
            $failed = false;
            $content = $transformer->transformStyleRules((string) $asset['content'], static function (string $prelude, string $body) use ($index, $outside, $scope, &$failed): string {
                $arms = \Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer::splitSelectorList($prelude);
                if (null === $arms) { $failed = true; return ''; }
                $arms = array_map('trim', $arms);
                if (array() === array_filter($arms, static fn(string $arm): bool => !$index->inertOutside($arm, $outside))) return $prelude . '{' . $body . '}';
                $scoped = \Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer::routeScopedArms($arms, $scope);
                if (null === $scoped) { $failed = true; return ''; }
                return implode(',', $scoped) . '{' . $body . '}';
            });
            return $failed ? null : $content;
        };
        // A member is still its own support sheet (sheets projected from
        // support, such as shared chrome, carry their own target) and has no
        // url() or statement whose meaning its file position decides.
        $delivery = static fn(array $asset): ?string => 'engine-support' === ($asset['source'] ?? '') && 'css' === ($asset['kind'] ?? null) && str_starts_with(basename((string) ($asset['target_path'] ?? '')), 'engine-support-')
            && is_string($asset['content'] ?? null) && 1 !== preg_match('/url\(|@import|@charset|@namespace/i', $asset['content'])
            ? serialize(array($asset['stylesheet_placement'] ?? '', $asset['stylesheet_target'] ?? 'both', $asset['media'] ?? '')) : null;
        // A fold takes the position of its first member, so other assets keep
        // their order.
        $result = $run = array();
        $slot = 0;
        $flush = static function () use (&$run, &$result, &$slot, $canonicalSources): void {
            if (count($run) < 2) {
                if (array() !== $run) $result[$slot] = $run[0]['asset'];
                $run = array();
                return;
            }
            $asset = $run[0]['asset'];
            $scopes = array();
            foreach ($run as $member) foreach ($member['asset']['scopes'] as $scope) $scopes['global' === $scope['kind'] ? '' : $scope['source_path']] = $scope;
            $sources = array_diff(array_keys($scopes), array(''));
            $asset = self::withRewrittenTextContent($asset, implode("\n", array_column($run, 'content')));
            $path = 'assets/css/engine-support-' . ($asset['stylesheet_placement'] ?? 'after-author') . '-' . substr(hash('sha256', serialize(array_keys($scopes)) . $asset['content']), 0, 16) . '.css';
            $asset['source_path'] = $path;
            $asset['target_path'] = 'assets/' . $path;
            $asset['token'] = 'asset-' . substr(hash('sha256', $asset['target_path']), 0, 16);
            $asset['reconciliation_identity'] = self::identity('asset', $path, $asset['target_path']);
            ksort($scopes);
            $asset['scopes'] = isset($scopes['']) || (count($sources) === count($canonicalSources) && array() === array_diff($canonicalSources, $sources))
                ? array(array('kind' => 'global')) : array_values($scopes);
            $result[$slot] = $asset;
            $run = array();
        };
        foreach ($assets as $asset) {
            $key = $delivery($asset);
            if ('css' !== ($asset['kind'] ?? null)) { $result[] = $asset; continue; }
            $content = null === $key ? null : $foldable($asset);
            if (null === $content || (array() !== $run && $run[0]['key'] !== $key)) $flush();
            if (null === $content) { $result[] = $asset; continue; }
            if (array() === $run) $result[$slot = count($result)] = null;
            $run[] = array('key' => $key, 'asset' => $asset, 'content' => $content);
        }
        $flush();
        return array_values($result);
    }

    /**
     * Partition generated rules by their exact consumers and delivery side.
     * Opaque statements and sheets with location-dependent URLs retain their
     * original resource. Self-contained data URLs can move with their rules.
     *
     * @param array<int,array<string,mixed>> $assets
     * @param list<string> $canonicalSources
     * @return array<int,array<string,mixed>>
     */
    private static function allocateDocumentSupport(array $assets, array $canonicalSources): array
    {
        $rules = $eligible = array();
        foreach ($assets as $index => $asset) {
            if ('engine-support' !== ($asset['source'] ?? '') || 'css' !== ($asset['kind'] ?? '') || in_array('global', array_column($asset['scopes'], 'kind'), true)) continue;
            $content = (string) ($asset['content'] ?? '');
            // Data URLs do not depend on the resource's location. Keep the
            // conservative URL guard for every other reference.
            $withoutDataUrls = preg_replace('/url\(\s*(?:"data:[^"]*"|\'data:[^\']*\'|data:[^\s)"\']*)\s*\)/i', '', $content) ?? $content;
            if (false !== stripos($withoutDataUrls, 'url(')) continue;
            $tree = new \Automattic\BlocksEngine\PhpTransformer\Css\CssRuleTree($content);
            if (!$tree->wellFormed) continue;
            $parts = array();
            foreach ($tree->rules() as $rule) {
                $text = trim(substr($content, $rule['start'], $rule['end'] - $rule['start'] + 1));
                if (';' === $content[$rule['boundary']]) { $parts = array(); break; }
                $parts[] = $text;
            }
            if (array() === $parts) continue;
            $eligible[$index] = true;
            $delivery = serialize(array($asset['stylesheet_placement'] ?? '', $asset['stylesheet_target'] ?? 'both', $asset['media'] ?? ''));
            foreach ($parts as $text) {
                $key = $delivery . "\0" . $text;
                if (!isset($rules[$key])) $rules[$key] = array('text' => $text, 'asset' => $asset, 'index' => $index, 'scopes' => array());
                foreach ($asset['scopes'] as $scope) $rules[$key]['scopes'][$scope['source_path']] = $scope;
            }
        }
        $groups = array();
        foreach ($rules as $rule) {
            ksort($rule['scopes']);
            $asset = $rule['asset'];
            $key = serialize(array($asset['stylesheet_placement'] ?? '', $asset['stylesheet_target'] ?? 'both', $asset['media'] ?? '', $rule['scopes']));
            if (!isset($groups[$key])) $groups[$key] = array('asset' => $asset, 'index' => $rule['index'], 'scopes' => array_values($rule['scopes']), 'rules' => array());
            $groups[$key]['rules'][] = $rule['text'];
        }
        // Each allocated sheet takes the slot of the first sheet it draws
        // rules from, so the inventory keeps its cascade order.
        $allocated = array();
        foreach ($groups as $key => $group) {
            $asset = self::withRewrittenTextContent($group['asset'], implode("\n", $group['rules']));
            $path = 'assets/css/engine-support-' . ($asset['stylesheet_placement'] ?? 'after-author') . '-' . substr(hash('sha256', $key . $asset['content']), 0, 16) . '.css';
            $asset['source_path'] = $path;
            $asset['target_path'] = 'assets/' . $path;
            $asset['token'] = 'asset-' . substr(hash('sha256', $asset['target_path']), 0, 16);
            $asset['reconciliation_identity'] = self::identity('asset', $path, $asset['target_path']);
            $sources = array_column($group['scopes'], 'source_path');
            $asset['scopes'] = count($sources) === count($canonicalSources) && array() === array_diff($canonicalSources, $sources)
                ? array(array('kind' => 'global')) : $group['scopes'];
            $allocated[$group['index']][] = $asset;
        }
        $result = array();
        foreach ($assets as $index => $asset) {
            if (!isset($eligible[$index])) $result[] = $asset;
            foreach ($allocated[$index] ?? array() as $sheet) $result[] = $sheet;
        }
        return $result;
    }

    /**
     * A stylesheet resource owns bytes; each document link or <style> that
     * applies it is an ordered instance with its own media/type. Record every
     * instance (A,B,A is three) on its resource so the bootstrap can replay
     * each document's cascade, then order the resource inventory by first
     * instance for display only. Unlinked/generated assets keep their slots.
     *
     * @param array<int,array<string,mixed>> $assets
     * @param array<int,array<string,mixed>> $pages
     * @return array<int,array<string,mixed>>
     */
    private function attachDocumentStylesheetInstances(array $assets, array $pages): array
    {
        $indexesByToken = array();
        foreach ($assets as $index => $asset) {
            if ('css' === ($asset['kind'] ?? null) && is_string($asset['token'] ?? null)) {
                $indexesByToken[$asset['token']] = $index;
            }
        }

        // Page-owned <style> assets record how many stylesheet links precede
        // them, so they join that page's sequence between the same links.
        $inlineIndexesByPage = array();
        foreach ($assets as $index => $asset) {
            $owner = $asset['compilation'] ?? null;
            if ('css' === ($asset['kind'] ?? null) && is_int($asset['stylesheet_link_position'] ?? null) && is_array($owner) && 'page' === ($owner['scope'] ?? null) && is_string($owner['id'] ?? null)) {
                $inlineIndexesByPage[$owner['id']][] = $index;
            }
        }

        $linkedIndexes = array();
        $firstOccurrences = array();
        foreach ($pages as $page) {
            // The Nth stylesheet link sorts at 2N+1 and a <style> preceded by
            // N stylesheet links sorts at 2N, placing it before that link.
            $sequence = array();
            $links = is_array($page['document_metadata']['links'] ?? null) ? $page['document_metadata']['links'] : array();
            usort($links, static fn(array $left, array $right): int => (int) ($left['order'] ?? PHP_INT_MAX) <=> (int) ($right['order'] ?? PHP_INT_MAX));
            $stylesheetLinkPosition = 0;
            foreach ($links as $link) {
                if (!is_array($link) || !StyleTagScanner::isStylesheetRel((string) ($link['rel'] ?? '')) || !StyleTagScanner::isCssType((string) ($link['type'] ?? ''))) continue;
                $position = $stylesheetLinkPosition++;
                $reference = (string) ($link['asset_reference'] ?? '');
                if (!preg_match('/^' . preg_quote(self::TOKEN_PREFIX, '/') . '(asset-[a-f0-9]{16})}}$/', $reference, $match)) continue;
                $index = $indexesByToken[$match[1]] ?? null;
                if (!is_int($index)) continue;
                $sequence[] = array(2 * $position + 1, $index, (string) ($link['media'] ?? $assets[$index]['media'] ?? ''), (string) ($link['type'] ?? ''), 0, self::stylesheetDeviceAttributes($link));
            }
            foreach ($assets as $index => $asset) foreach ($asset['inline_references'] ?? array() as $reference) {
                if ('css' !== ($asset['kind'] ?? null) || $page['source_path'] !== ($reference['source_path'] ?? null)) continue;
                $sequence[] = array(2 * (int) ($reference['stylesheet_link_position'] ?? 0), $index, (string) (isset($reference[CaptureEvidence::DEVICE_STYLE]) ? ($reference['media'] ?? '') : ($reference['source_media'] ?? $reference['media'] ?? '')), (string) ($asset['type'] ?? ''), (int) ($reference['stylesheet_index'] ?? 0), self::inlineStylesheetDeviceAttributes($reference));
            }
            foreach ($inlineIndexesByPage[(string) ($page['source_path'] ?? '')] ?? array() as $index) {
                $sequence[] = array(2 * (int) $assets[$index]['stylesheet_link_position'], $index, (string) ($assets[$index]['media'] ?? ''), '', (int) ($assets[$index]['stylesheet_inline_order'] ?? $index), self::inlineStylesheetDeviceAttributes($assets[$index]));
            }
            usort($sequence, static fn(array $left, array $right): int => $left[0] <=> $right[0] ?: $left[4] <=> $right[4] ?: $left[1] <=> $right[1]);
            foreach ($sequence as $order => $entry) {
                $linkedIndexes[$entry[1]] = true;
                if (!in_array($entry[1], $firstOccurrences, true)) $firstOccurrences[] = $entry[1];
                $assets[$entry[1]]['stylesheet_instances'][] = array('source_path' => $page['source_path'], 'order' => $order, 'media' => $entry[2], 'type' => $entry[3]) + ($entry[5] ?? array());
            }
        }
        // This is only the resource inventory's display order. Runtime order is
        // per-document instances; no global graph can represent A,B,A or A/B
        // on one route and B/A on another.
        $slots = array_keys($linkedIndexes);
        sort($slots, SORT_NUMERIC);
        $orderedAssets = array_map(static fn(int $index): array => $assets[$index], $firstOccurrences);
        foreach ($slots as $position => $slot) $assets[$slot] = $orderedAssets[$position];

        return array_values($assets);
    }

    /** The selector with every `:not(...)` removed: what it targets, not what it excludes. */
    public static function positiveSelector(string $selector): string
    {
        $positive = '';
        $depth = 0;
        $length = strlen($selector);
        for ($i = 0; $i < $length; ++$i) {
            if (0 === $depth && 0 === strncasecmp(substr($selector, $i, 5), ':not(', 5)) {
                $depth = 1;
                $i += 4;
                continue;
            }
            if ($depth > 0) {
                if ('(' === $selector[$i]) ++$depth;
                elseif (')' === $selector[$i]) --$depth;
                continue;
            }
            $positive .= $selector[$i];
        }
        return $positive;
    }

    /** Rendered elements rooting a captured document copy; at least one. */
    private static function documentCopyCount(string $markup): int
    {
        $html = (string) preg_replace('/<!--.*?-->/s', '', $markup);
        $copies = 0;
        if (preg_match_all('/<[a-z][a-z0-9-]*\\s[^>]*?\\bclass="([^"]*)"/i', $html, $matches)) {
            foreach ($matches[1] as $classes) {
                if (array_filter(preg_split('/\\s+/', $classes) ?: array(), ShellExtraction::isResponsiveVariantClass(...))) ++$copies;
            }
        }

        return max(1, $copies);
    }

    /**
     * A page-specific projection must follow its source stylesheet in the
     * WordPress enqueue list, even when another document's linked CSS forces
     * the original stylesheet into a later slot. Keep each detached shared
     * chrome slice with its page projection at that same source boundary.
     *
     * @param array<int,array<string,mixed>> $assets
     * @return array<int,array<string,mixed>>
     */
    private static function orderPageStylesheetProjections(array $assets): array
    {
        $sources = array_fill_keys(array_column($assets, 'source_path'), true);
        $children = array();
        foreach ($assets as $asset) {
            $source = (string) ($asset['source_path'] ?? '');
            if ('css' !== ($asset['kind'] ?? null)
                || !preg_match('/^(.*)\.page-[a-f0-9]{12}\.css(\.shared-chrome)?$/D', $source, $match)
                || !isset($sources[$match[1] . '.css'])
            ) {
                continue;
            }
            $children[$match[1] . '.css'][] = $asset;
        }
        if (array() === $children) return $assets;

        $ordered = array();
        foreach ($assets as $asset) {
            $source = (string) ($asset['source_path'] ?? '');
            if (preg_match('/^(.*)\.page-[a-f0-9]{12}\.css(?:\.shared-chrome)?$/D', $source, $match)
                && isset($children[$match[1] . '.css'])
            ) {
                continue;
            }
            $ordered[] = $asset;
            $parent = str_ends_with($source, '.shared-chrome') ? substr($source, 0, -strlen('.shared-chrome')) : $source;
            if (isset($children[$parent]) && ($source === $parent . '.shared-chrome' || !isset($sources[$parent . '.shared-chrome']))) {
                array_push($ordered, ...$children[$parent]);
            }
        }
        return $ordered;
    }

    /**
     * Shared chrome rules lifted out of authored stylesheets style a template
     * part on every route, so they keep a route-independent instance on every
     * canonical document. On a document that applies a contributing stylesheet
     * the projection follows that stylesheet's last instance there (its rules'
     * winning source position); elsewhere it follows the last instance whose
     * resource precedes it in the inventory, as the flat enqueue order did.
     * Each document's sequence is renumbered so (source_path, order) stays
     * unique across resources. A projection none of whose contributing
     * stylesheets has instances keeps its inventory slot instead.
     *
     * @param array<int,array<string,mixed>> $assets
     * @param array<int,array<string,mixed>> $pages
     * @return array<int,array<string,mixed>>
     */
    private static function placeSharedStylesheetProjections(array $assets, array $pages): array
    {
        $sequences = array();
        $owners = array();
        foreach ($assets as $index => $asset) {
            foreach ($asset['stylesheet_instances'] ?? array() as $key => $instance) {
                $sequences[$instance['source_path']][] = array('index' => $index, 'key' => $key, 'order' => $instance['order']);
                $owners[(string) ($asset['source_path'] ?? '')] = true;
            }
        }
        $projections = array();
        foreach ($assets as $index => $asset) {
            if (!isset($asset['_stylesheet_projection_origins'])) continue;
            if (array_filter($asset['_stylesheet_projection_origins'], static fn(array $origin): bool => isset($owners[$origin['source_path']]))) $projections[] = $index;
            else unset($assets[$index]['_stylesheet_projection_origins']);
        }
        if (array() === $projections) return $assets;
        // A route without the source stylesheet still loads the slice for its
        // shared chrome; a device-selected source keeps that selection there.
        $deviceConditions = array();
        foreach ($projections as $projection) {
            $sources = array_column($assets[$projection]['_stylesheet_projection_origins'], 'source_path', 'source_path');
            foreach ($assets as $asset) {
                if (!isset($sources[(string) ($asset['source_path'] ?? '')])) continue;
                foreach ($asset['stylesheet_instances'] ?? array() as $instance) {
                    $device = self::stylesheetDeviceAttributes($instance);
                    if (!isset($device[CaptureEvidence::DEVICE_STYLE])) continue;
                    $conditions = array('media' => $instance['media'], 'type' => '') + $device;
                    $deviceConditions[$projection][serialize($conditions)] = $conditions;
                }
            }
        }
        $applies = static fn(array $scopes, string $document): bool => array() !== array_filter($scopes, static fn(array $scope): bool => 'global' === ($scope['kind'] ?? null) || ($scope['source_path'] ?? null) === $document);
        foreach ($pages as $page) {
            $document = (string) $page['source_path'];
            $sequence = $sequences[$document] ?? array();
            usort($sequence, static fn(array $left, array $right): int => $left['order'] <=> $right['order']);
            foreach ($projections as $projection) {
                $origins = array();
                $preceding = null;
                foreach ($sequence as $position => $entry) {
                    if ($entry['index'] < $projection) $preceding = $position;
                    if (isset($entry['projection'])) continue;
                    foreach ($assets[$projection]['_stylesheet_projection_origins'] as $source) {
                        if ($source['source_path'] !== ($assets[$entry['index']]['source_path'] ?? null) || !$applies($source['scopes'], $document)) continue;
                        $instance = $assets[$entry['index']]['stylesheet_instances'][$entry['key']];
                        // Coalesced sources share one winning slot per condition,
                        // not one copy per link or only the last condition.
                        // An omitted type is text/css: both are one condition.
                        $conditions = array('media' => $instance['media'], 'type' => $instance['type']) + self::stylesheetDeviceAttributes($instance);
                        $key = array('type' => 'text/css' === strtolower(trim((string) $instance['type'])) ? '' : $instance['type']) + $conditions;
                        $origins[serialize($key)] = array('position' => $position) + $conditions;
                    }
                }
                if (array() === $origins) {
                    foreach ($deviceConditions[$projection] ?? array(array('media' => (string) ($assets[$projection]['media'] ?? ''), 'type' => '')) as $conditions) $origins[] = array('position' => $preceding ?? -1) + $conditions;
                }
                // Insert backwards so earlier positions do not shift.
                usort($origins, static fn(array $left, array $right): int => $right['position'] <=> $left['position']);
                foreach ($origins as $origin) {
                    array_splice($sequence, $origin['position'] + 1, 0, array(array('index' => $projection, 'projection' => true, 'media' => $origin['media'], 'type' => $origin['type']) + self::stylesheetDeviceAttributes($origin)));
                }
            }
            foreach ($sequence as $order => $entry) {
                if (isset($entry['projection'])) $assets[$entry['index']]['stylesheet_instances'][] = array('source_path' => $document, 'order' => $order, 'media' => $entry['media'], 'type' => $entry['type']) + self::stylesheetDeviceAttributes($entry);
                else $assets[$entry['index']]['stylesheet_instances'][$entry['key']]['order'] = $order;
            }
        }
        foreach ($projections as $projection) unset($assets[$projection]['_stylesheet_projection_origins']);
        return $assets;
    }

    /**
     * Fields that only identify a stylesheet resource or say which documents
     * apply it. Everything else (bytes, media, target, placement, parent,
     * activation, ...) is the delivery contract two page stylesheets must
     * share before one file can serve both.
     */
    private const PAGE_STYLESHEET_IDENTITY_FIELDS = array('source_path', 'target_path', 'token', 'reconciliation_identity', 'scopes', 'stylesheet_instances', 'references', 'frame_fallback');

    /**
     * Page-scoped stylesheets with identical bytes and delivery contract
     * become one resource that every one of their pages applies.
     *
     * Each page used to publish its own copy of what is often the same
     * projection: the slice of a shared stylesheet a page needs beyond the
     * site-wide copy, its compatibility replay, its editor state. A merged
     * resource keeps the first copy's identity and gains the other copies'
     * scopes and document instances, so the bootstrap enqueues it for each of
     * those pages exactly where it enqueued that page's copy.
     *
     * The enqueue order on a page is resource order (within one source
     * position for projected slices). The later copy is folded into the
     * earlier one only when no resource between them applies to any of the
     * later copy's pages and both sit on the same side of the first
     * document-ordered resource, so no page's cascade order changes. Copies
     * in different directories stay apart: their relative url() references
     * resolve differently, and a copy a document or stylesheet names by its
     * token (an inline `<style>` replayed in the head, an `@import`) keeps it.
     *
     * @param array<int,array<string,mixed>> $assets
     * @param array<int,array<string,mixed>> $documents
     * @return array<int,array<string,mixed>>
     */
    private static function mergeIdenticalPageStylesheets(array $assets, array $documents): array
    {
        $assets = array_values($assets);
        // Tokens and file names anything still links, imports or url()s, and
        // the stylesheets page slices hang off: those keep their identity.
        $named = array();
        foreach (array_merge(array((string) json_encode($documents, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)), array_map(static fn (array $asset): string => ArrayField::string($asset, 'content'), $assets)) as $text) {
            if (preg_match_all('/\{\{wordpress-site-plan:asset:([^}]+)\}\}/', $text, $matches)) $named += array_fill_keys($matches[1], true);
            if (preg_match_all('/(?:url\(\s*|@import\s+(?!url\()|(?:href|src)=)\\\\?["\']?([^"\'()\s\\\\]+)/i', $text, $matches)) {
                foreach ($matches[1] as $reference) $named[strtolower(basename((string) parse_url($reference, PHP_URL_PATH)))] = true;
            }
        }
        $parents = array();
        $instancesBySource = array();
        foreach ($assets as $asset) {
            if (is_string($asset['stylesheet_parent'] ?? null)) $parents[$asset['stylesheet_parent']] = true;
            if (is_string($asset['source_path'] ?? null) && !isset($instancesBySource[$asset['source_path']])) $instancesBySource[$asset['source_path']] = ArrayField::array($asset, 'stylesheet_instances');
        }
        $firstManaged = count($assets);
        foreach ($assets as $index => $asset) if (!empty($asset['stylesheet_instances'])) $firstManaged = min($firstManaged, $index);
        $pagesOf = static function (array $asset): ?array {
            $pages = array();
            foreach (is_array($asset['scopes'] ?? null) ? $asset['scopes'] : array(array('kind' => 'global')) as $scope) {
                if ('global' === ($scope['kind'] ?? null) || !is_string($scope['source_path'] ?? null)) return null;
                $pages[$scope['source_path']] = true;
            }
            return $pages;
        };
        // How ThemeBootstrap::stylesheetEnqueues() places a resource on one page: document
        // instances sort by their source position (then resource order); a
        // resource without any sits in the flat before/after run in resource
        // order. Returns null when the page does not apply the resource.
        $placement = static function (array $asset, string $page) use ($pagesOf, $instancesBySource): ?array {
            if ('css' !== ($asset['kind'] ?? null)) return null;
            $own = $pagesOf($asset);
            $applies = null === $own || isset($own[$page]);
            $occurrences = ArrayField::array($asset, 'stylesheet_instances');
            if (array() === $occurrences && is_string($asset['stylesheet_parent'] ?? null)) $occurrences = $instancesBySource[$asset['stylesheet_parent']] ?? array();
            if (array() === $occurrences) return $applies || !empty($asset['frame_fallback']) ? array('flat' => true) : null;
            $orders = array();
            foreach ($occurrences as $occurrence) if ($applies && $page === ($occurrence['source_path'] ?? null)) $orders[(int) $occurrence['order']] = true;
            if (!empty($asset['frame_fallback'])) return array('flat' => true, 'orders' => $orders);
            return array() === $orders ? null : array('flat' => false, 'orders' => $orders);
        };
        $merged = array();
        $heads = array();
        foreach ($assets as $index => $asset) {
            $source = (string) ($asset['source_path'] ?? '');
            // Projection can empty distinct generated resources. Empty bytes
            // are not evidence that those resource identities are equivalent.
            $pages = 'css' === ($asset['kind'] ?? null) && is_string($asset['content'] ?? null) && '' !== trim($asset['content']) && !isset($asset['payload_reference']) && !isset($asset['content_base64']) && !isset($parents[$source]) && !isset($named[(string) ($asset['token'] ?? '')]) && !isset($named[strtolower(basename((string) ($asset['target_path'] ?? '')))]) && !isset($named[strtolower(basename($source))]) ? $pagesOf($asset) : null;
            if (null === $pages || array() === $pages) {
                $merged[] = $asset;
                continue;
            }
            $contract = array_diff_key($asset, array_flip(self::PAGE_STYLESHEET_IDENTITY_FIELDS));
            ksort($contract);
            $key = hash('sha256', serialize(array($contract, dirname($source), dirname((string) $asset['target_path']), $index < $firstManaged, !empty($asset['stylesheet_instances']))));
            $folded = false;
            foreach ($heads[$key] ?? array() as $position) {
                $head = $merged[$position];
                if (array() !== array_intersect_key($pagesOf($head) ?? array(), $pages)) continue;
                // Moving this copy up to the head must not pass any resource
                // the same page enqueues in the same run or source position.
                // A frame fallback copy also styles native routes no document
                // owns; there it must not pass a resource those routes load.
                if (!empty($asset['frame_fallback'])) {
                    for ($between = $position + 1, $count = count($merged); $between < $count; ++$between) {
                        if ('css' === ($merged[$between]['kind'] ?? null) && (null === $pagesOf($merged[$between]) || !empty($merged[$between]['frame_fallback']))) continue 2;
                    }
                }
                foreach (array_keys($pages) as $page) {
                    $own = $placement($asset, (string) $page);
                    if (null === $own) continue;
                    for ($between = $position + 1, $count = count($merged); $between < $count; ++$between) {
                        $other = $placement($merged[$between], (string) $page);
                        if (null === $other) continue;
                        if ($own['flat'] ? $other['flat'] : array() !== array_intersect_key($own['orders'], $other['orders'] ?? array())) continue 3;
                    }
                }
                $head['scopes'] = array_merge($head['scopes'], $asset['scopes']);
                if (!empty($asset['frame_fallback'])) $head['frame_fallback'] = true;
                if (isset($asset['stylesheet_instances']) || isset($head['stylesheet_instances'])) $head['stylesheet_instances'] = array_merge($head['stylesheet_instances'] ?? array(), $asset['stylesheet_instances'] ?? array());
                if (isset($asset['references']) || isset($head['references'])) $head['references'] = self::mergedReferences(ArrayField::array($head, 'references'), ArrayField::array($asset, 'references'));
                $merged[$position] = $head;
                $folded = true;
                break;
            }
            if ($folded) continue;
            $heads[$key][] = count($merged);
            $merged[] = $asset;
        }
        return $merged;
    }

    /**
     * An element marker no emitted markup, runtime declaration or entity
     * record carries names an element the plan replaced or dropped, so every
     * stylesheet selector naming it matches nothing: drop those arms from
     * page stylesheets too, as the shared-chrome projection already does.
     *
     * @param list<array<string,mixed>> $assets
     * @param array<string,true> $live
     * @return list<array<string,mixed>>
     */
    private static function withoutDeadElementMarkerSelectors(array $assets, array $live): array
    {
        foreach ($assets as &$asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null) || isset($asset['payload_reference']) || 1 !== preg_match(self::ELEMENT_MARKER_PATTERN, $asset['content'])) continue;
            $content = (new CssStylesheetTransformer())->transformStyleRules(
                $asset['content'],
                static function (string $prelude, string $body) use ($live): string {
                    if (1 !== preg_match(self::ELEMENT_MARKER_PATTERN, $prelude)) return $prelude . '{' . $body . '}';
                    $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
                    if (null === $selectors) return $prelude . '{' . $body . '}';
                    $kept = array();
                    $changed = false;
                    foreach ($selectors as $selector) {
                        $pruned = self::withoutDeadElementMarkers(trim($selector), $live);
                        if ($pruned !== trim($selector)) $changed = true;
                        if (null !== $pruned) $kept[] = $pruned;
                    }
                    if (!$changed) return $prelude . '{' . $body . '}';
                    return array() === $kept ? '' : implode(',', $kept) . '{' . $body . '}';
                }
            );
            if ($content === $asset['content']) continue;
            $asset = self::withRewrittenTextContent($asset, $content);
        }
        unset($asset);
        return $assets;
    }

    public static function withoutDeadElementMarkers(string $selector, array $live): ?string
    {
        $dead = static function (string $text) use ($live): bool {
            if (!preg_match_all(self::ELEMENT_MARKER_PATTERN, $text, $markers)) return false;
            foreach ($markers[0] as $marker) if (!isset($live[$marker])) return true;
            return false;
        };
        if (!$dead($selector)) return $selector;
        $offset = 0;
        while (false !== ($start = stripos($selector, ':where(', $offset))) {
            $open = $start + strlen(':where(');
            $depth = 1;
            for ($end = $open; $end < strlen($selector) && $depth > 0; ++$end) {
                if ('(' === $selector[$end]) ++$depth;
                elseif (')' === $selector[$end]) --$depth;
            }
            if (0 !== $depth) return $selector;
            $inner = substr($selector, $open, $end - 1 - $open);
            $arguments = CssStylesheetTransformer::splitSelectorList($inner);
            if (null === $arguments || !$dead($inner)) {
                $offset = $open;
                continue;
            }
            $alive = array();
            foreach ($arguments as $argument) {
                $argument = self::withoutDeadElementMarkers(trim($argument), $live);
                if (null !== $argument) $alive[] = $argument;
            }
            if (array() !== $alive) {
                $replacement = ':where(' . implode(',', $alive) . ')';
                $selector = substr($selector, 0, $start) . $replacement . substr($selector, $end);
                $offset = $start + strlen($replacement);
                continue;
            }
            // `:not(:where(dead))` excluded nothing; any other position required a dead element.
            if ($start >= 5 && 0 === strcasecmp(substr($selector, $start - 5, 5), ':not(') && ')' === ($selector[$end] ?? '')) {
                // A compound left with no other simple selector keeps `*`,
                // which, like `:not(:where())`, adds no specificity.
                $before = $start - 5;
                $after = $selector[$end + 1] ?? '';
                $alone = (0 === $before || str_contains(" \t\n>+~(,", $selector[$before - 1])) && ('' === $after || str_contains(" \t\n>+~),", $after));
                $replacement = $alone ? '*' : '';
                $selector = substr($selector, 0, $before) . $replacement . substr($selector, $end + 1);
                $offset = $before + strlen($replacement);
                continue;
            }
            return null;
        }
        // A dead marker outside `:where()` (a class or attribute the subject
        // must carry) leaves nothing to match; inside another `:not()` it is
        // left as authored.
        $positive = self::positiveSelector($selector);
        if ($dead($positive)) return null;
        $selector = trim($selector);
        return '' === $selector ? null : $selector;
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
                $route = $this->links->reference($row['url'], self::value($document, 'source_path'), $routes);
                if (null !== $route) { $row['url'] = $route; continue; }
                // A local link that names neither a captured asset nor a route has nothing to bind.
                // Omit it and say so; the strict link validator stays the integrity backstop.
                if (self::explicitUrl($row['url'])) continue;
                $this->recordOmittedLinkDeclaration(self::value($document, 'source_path'), $row);
                $row = null;
            }
            unset($row);
            $metadata[$kind] = array_values(array_filter($metadata[$kind], static fn(mixed $row): bool => is_array($row)));
            foreach ($metadata[$kind] as $index => &$row) $row['order'] = $index;
            unset($row);
        }
        if ('html' === ($metadata['source_context']['kind'] ?? null)) foreach (self::unrenderedHeadItems($metadata) as $drop) $this->recordDroppedHeadItem(self::value($document, 'source_path'), $drop);
        return $metadata;
    }

    /**
     * Records one omitted link declaration. An omission is a reportable quality
     * defect, not a silent edit: the plan keeps a bounded, deduplicated warning
     * per declaration so a consumer can show what the head lost.
     *
     * These land on the plan's own `diagnostics`, beside
     * `wordpress_site_plan_unresolved_navigation_link`. They are not envelope
     * diagnostics, so they do not reach `source_reports.wordpress_site_plan_diagnostics`
     * or `WordPressSitePlanView::diagnostics()`, which carry the failure
     * channel; a consumer wanting these reads the plan.
     *
     * @param array<string,mixed> $link
     */
    private function recordOmittedLinkDeclaration(string $sourcePath, array $link): void
    {
        $url = (string) ($link['url'] ?? '');
        $relation = trim((string) preg_replace('/\s+/', ' ', (string) ($link['rel'] ?? '')));
        // Keyed on the declaration rather than the page. WordPress prints the
        // same discovery links in every head, so keying on the page spends the
        // whole budget restating one site's boilerplate and names no other
        // defect: a 60-page capture reported 5 facts about 10 pages and gave
        // up. One row per distinct declaration, with the page it was first seen
        // on and how many pages carried it.
        //
        // Pages, not tags: fullDocumentMetadata() keeps duplicate links, so a
        // head that prints one pingback twice would otherwise report two
        // affected pages when it has one.
        $key = $relation . "\0" . $url;
        if (isset($this->omittedLinkDeclarations[$key])) { $this->omittedLinkDeclarationPages[$key][$sourcePath] = true; return; }
        // Counted as distinct declarations, the same unit as the rows above it,
        // so the listed rows and the remainder add up.
        if (count($this->omittedLinkDeclarations) >= self::MAX_OMITTED_LINK_DECLARATION_DIAGNOSTICS) { $this->omittedLinkDeclarationOverflow[$key] = true; return; }
        $label = $relation . ' link';
        $this->omittedLinkDeclarationPages[$key][$sourcePath] = true;
        $this->omittedLinkDeclarations[$key] = array_filter(array(
            'code' => self::OMITTED_LINK_DECLARATION_DIAGNOSTIC_CODE,
            'severity' => 'warning',
            'message' => self::clipDiagnosticField("Omitted {$url} ({$label} declaration), which names neither a captured asset nor an artifact route."),
            'source_path' => self::clipDiagnosticField($sourcePath),
            'rel' => self::clipDiagnosticField($relation),
            'value' => self::clipDiagnosticField($url),
            'reason_code' => 'unresolved_local_url',
        ), static fn(mixed $field): bool => '' !== $field);
    }
    /**
     * Clips one diagnostic field to its budget without splitting a UTF-8
     * sequence. A byte-wise cut can leave an invalid string, and planIdentity()
     * json_encode()s every diagnostic under JSON_THROW_ON_ERROR, so a split
     * sequence would discard the whole plan -- the failure this omission exists
     * to avoid, one layer up.
     */
    private static function clipDiagnosticField(string $value): string { return mb_strcut($value, 0, 256, 'UTF-8'); }
    /**
     * Records one unit the plan dropped or rewrote instead of failing. Findings
     * with the same fields are one row whose `omitted_count` counts them.
     *
     * @param array<string,mixed> $finding code, unit, source_path, reason_code, message, metrics and unit identity fields.
     */
    private function recordUnitFinding(array $finding): void
    {
        $finding = array_map(static fn(mixed $value): mixed => is_string($value) ? self::clipDiagnosticField($value) : $value, $finding);
        $key = json_encode($finding, JSON_THROW_ON_ERROR);
        if (isset($this->unitFindings[$key])) { ++$this->unitFindings[$key]['omitted_count']; return; }
        $this->unitFindings[$key] = array('code' => $finding['code'], 'severity' => 'warning') + $finding + array('omitted_count' => 1);
    }
    /** @return array<int,array<string,mixed>> */
    private function unitFindingDiagnostics(): array { return array_values($this->unitFindings); }
    /**
     * WordPress renders the document head: core owns charset and viewport,
     * platform fields own route SEO and the site icon, and stylesheets and
     * executable scripts are enqueued. Any other source head item is a loss.
     * @param array<string,mixed> $metadata
     * @return list<array{code:string,reason_code:string,tag:string,name:string}>
     */
    private static function unrenderedHeadItems(array $metadata): array
    {
        $drops = array();
        $drop = static function (string $tag, string $name) use (&$drops): void { $drops[] = array('code' => 'document_head_element_dropped', 'reason_code' => 'unrendered', 'tag' => $tag, 'name' => $name); };
        foreach ($metadata['meta'] ?? array() as $row) {
            if ('head' !== ($row['placement'] ?? null) || isset($row['charset'])) continue;
            $equiv = strtolower(trim((string) ($row['http_equiv'] ?? '')));
            $name = strtolower(trim((string) ($row['name'] ?? $row['property'] ?? '')));
            if ('' !== $equiv) { if ('content-type' !== $equiv) $drop('meta', 'http-equiv=' . $equiv); continue; }
            if (in_array($name, array('viewport', 'description', 'robots', 'googlebot', 'bingbot', 'generator'), true) || str_starts_with($name, 'og:') || str_starts_with($name, 'twitter:')) continue;
            $drop('meta', $name);
        }
        foreach ($metadata['links'] ?? array() as $row) {
            if ('head' !== ($row['placement'] ?? null)) continue;
            $relations = preg_split('/\s+/', strtolower(trim((string) ($row['rel'] ?? '')))) ?: array();
            if (array() === array_diff($relations, array('stylesheet', 'icon', 'shortcut', 'apple-touch-icon', 'canonical', 'alternate', 'next', 'prev'))) continue;
            $drop('link', implode(' ', $relations));
        }
        foreach ($metadata['scripts'] ?? array() as $row) {
            if ('head' === ($row['placement'] ?? null) && 'inline' === ($row['source_kind'] ?? null) && !self::isExecutableScriptType((string) ($row['type'] ?? ''), false)) $drop('script', strtolower((string) ($row['type'] ?? '')));
        }
        return $drops;
    }
    /** @param array{code:string,reason_code:string,tag:string,name:string} $drop */
    private function recordDroppedHeadItem(string $sourcePath, array $drop): void
    {
        $this->recordUnitFinding(array_filter(array(
            'code' => $drop['code'],
            'unit' => 'item',
            'message' => sprintf('Document head <%s> %s is not rendered by WordPress (%s); it was dropped.', $drop['tag'], $drop['name'], $drop['reason_code']),
            'source_path' => $sourcePath,
            'reason_code' => $drop['reason_code'],
            'tag' => $drop['tag'],
            'name' => $drop['name'],
        ), static fn(string $value): bool => '' !== $value));
    }
    /** @return array<int,array<string,mixed>> */
    private function omittedLinkDeclarationDiagnostics(): array
    {
        $diagnostics = array();
        foreach ($this->omittedLinkDeclarations as $key => $diagnostic) {
            $diagnostic['occurrences'] = count($this->omittedLinkDeclarationPages[$key] ?? array());
            $diagnostics[] = $diagnostic;
        }
        $overflow = count($this->omittedLinkDeclarationOverflow);
        if ($overflow > 0) $diagnostics[] = array('code' => self::OMITTED_LINK_DECLARATION_DIAGNOSTIC_CODE, 'severity' => 'warning', 'message' => sprintf('%d more distinct unresolved link declarations were omitted; omitted from this diagnostic list.', $overflow), 'reason' => 'truncated', 'omitted_count' => $overflow);
        return $diagnostics;
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
    public static function validTemplateSurfaceDeclaration(array $surface, string $sourcePath): bool
    {
        $identifier = static fn(mixed $value): bool => is_string($value) && 0 < strlen($value) && strlen($value) <= 128 && 1 === preg_match('/^[a-z0-9][a-z0-9._:-]*$/', $value);
        $provenance = $surface['declaration_provenance'] ?? null;
        return 'blocks-engine/template-surface/v1' === ($surface['schema'] ?? null) && in_array($surface['role'] ?? null, array('404', 'archive', 'attachment', 'author', 'category', 'date', 'home', 'search', 'single', 'tag', 'taxonomy'), true) && $identifier($surface['slug'] ?? null) && ($surface['logical_surface_id'] ?? null) === $surface['role'] . ':' . $surface['slug'] && $identifier($surface['responsive_variant_id'] ?? null) && is_array($provenance) && 'blocks-engine/template-surface-provenance/v1' === ($provenance['schema'] ?? null) && in_array($provenance['kind'] ?? null, array('artifact_metadata', 'html_attributes', 'schema_org'), true) && $sourcePath === ($provenance['source_path'] ?? null);
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
    /** @param array<string,mixed> $document */
    private static function emptyRedirectDocument(array $document): bool
    {
        if (!empty($document['entrypoint']) || !is_string($document['html'] ?? null)) return false;
        $html = $document['html'];
        $redirect = false;
        foreach (HtmlMarkupParser::htmlMarkupNodes($html) as $node) {
            if ('meta' === ($node['name'] ?? null) && 'refresh' === strtolower((string) ($node['attributes']['http-equiv'] ?? ''))) $redirect = true;
        }
        $body = preg_replace('~<(head|script|style)\\b[^>]*>.*?</\\1>~is', '', $html) ?? $html;
        return $redirect && '' === trim(strip_tags($body)) && !preg_match('~<(?:img|video|audio|iframe|svg|canvas|form)\\b~i', $body);
    }
    /** @param array<string,mixed> $compiled @param array<string,mixed> $data @param array<string,mixed> $coreHtmlFallbackEvidence @return array<string,mixed> */
    private function reporting(array $pages, array $data, array $coreHtmlFallbackEvidence, array $scriptDiagnostics = array(), array $surfaces = array()): array { $documents = array(); foreach ($pages as $page) if (is_array($page)) $documents[] = array('source_path' => $page['source_path'] ?? '', 'kind' => 'page', 'body_format' => 'blocks', 'block_document' => true, 'provenance' => $page['provenance'] ?? array()); foreach ($surfaces as $surface) $documents[] = array('source_path' => $surface['source_path'] ?? '', 'kind' => 'template_surface', 'body_format' => 'blocks', 'block_document' => true, 'template_surface' => $surface['template_surface'] ?? array(), 'provenance' => $surface['provenance'] ?? array()); return array('source_documents' => $documents, 'metrics' => array('source_document_count' => count($documents), 'block_document_count' => count($documents), 'native_block_count' => $data['metrics']['block_count'] ?? 0, 'fallback_count' => $data['metrics']['fallback_count'] ?? 0), 'core_html_fallback_evidence' => $coreHtmlFallbackEvidence, 'diagnostic_codes' => array_values(array_map(static fn(array $diagnostic): string => (string) ($diagnostic['code'] ?? ''), array_merge($data['diagnostics'], $scriptDiagnostics)))); }

    /**
     * The canonical source-path-to-route map, and the only place a route
     * identity is decided.
     *
     * A collision here is information about two pages, not about the plan: a
     * CMS that slugifies author-written titles into filenames routinely emits
     * two paths that derive one slug (one name captured with two extensions,
     * `about.html` beside `about.htm`), and aborting costs every other page in
     * the site. So the
     * first occurrence in document order keeps the route and later ones take a
     * deterministic `-2`, `-3` suffix, exactly as WordPress resolves a
     * duplicate `post_name`, with one warning naming both source paths.
     *
     * Two identities are not the plan's to rename, and both are reserved before
     * any derived route is assigned: an authored `metadata.route_path`, where
     * two explicit values naming one route are a contradiction that still fails
     * closed (except author claims, which are diagnosed and withheld), and the
     * entrypoint, which must keep `/` or the site has no front page.
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
            $metadata = ArrayField::array($document, 'metadata');
            $explicit[$order] = is_string($metadata['route_path'] ?? null) && '' !== $metadata['route_path'];
            if ('' !== $entryRoot && ! str_starts_with((string) $document['source_path'], $entryRoot . '/') && !$explicit[$order]) throw new InvalidArgumentException('Compiled site document is outside the entrypoint content root.');
            $derived[$order] = $explicit[$order] ? PageRoute::canonical($metadata['route_path']) : PageRoute::fromSource($document['source_path'], $entryRoot);
        }
        $taken = array(); $reserved = array();
        foreach ($derived as $order => $path) if ($explicit[$order]) {
            if (isset($taken[$path])) {
                // Duplicate author claims are evidence ambiguity, not permission
                // to rename an author. Keep both captures as fallback documents;
                // AuthorProjection sees their original claim and withholds both.
                if (preg_match('~^/author/[^/]+$~', $path)) continue;
                throw new InvalidArgumentException(sprintf('WordPress site plan has colliding page routes: %s and %s both declare %s.', $taken[$path], (string) $documents[$order]['source_path'], $path));
            }
            $taken[$path] = (string) $documents[$order]['source_path']; $reserved[$order] = true;
        }
        foreach ($derived as $order => $path) if (!$explicit[$order] && !empty($documents[$order]['entrypoint']) && !isset($taken[$path])) { $taken[$path] = (string) $documents[$order]['source_path']; $reserved[$order] = true; }
        // Every first claimant keeps its own route before any suffix is handed
        // out, so a renamed duplicate cannot take the route a later page derived
        // for itself: `about.htm` beside a real `about.html` and a page that
        // derived `/about-2` becomes `/about-3`, not `/about-2`.
        foreach ($derived as $order => $path) if (!isset($reserved[$order]) && !isset($taken[$path])) { $taken[$path] = (string) $documents[$order]['source_path']; $reserved[$order] = true; }
        $routes = array();
        foreach ($documents as $order => $document) {
            $sourcePath = (string) $document['source_path'];
            $path = $derived[$order];
            if (!isset($reserved[$order])) {
                $kept = $taken[$path];
                $path = PageRoute::disambiguated($path, $taken);
                $this->recordRouteCollision($kept, $sourcePath, $derived[$order], $path);
                $taken[$path] = $sourcePath;
            }
            $metadata = ArrayField::array($document, 'metadata');
            $previous = $legacy[$sourcePath] ?? array();
            $routes[] = array('kind' => 'route', 'source_path' => $document['source_path'], 'target_path' => $path, 'target_slug' => self::value($document, 'slug', PageRoute::slug($path)), 'title' => self::value($document, 'title'), 'parent_source_path' => self::value($metadata, 'parent_source_path'), 'source_relation' => !empty($document['entrypoint']) ? 'entrypoint' : ($previous['source_relation'] ?? 'document'), 'order' => $order);
            if (preg_match('~^/author/[^/]+$~', $derived[$order])) $routes[array_key_last($routes)]['source_claim_path'] = $derived[$order];
        }
        return $routes;
    }
    private function recordRouteCollision(string $keptSourcePath, string $sourcePath, string $routePath, string $resolvedPath, bool $flattened = false): void
    {
        if (count($this->routeCollisions) >= self::MAX_ROUTE_COLLISION_DIAGNOSTICS) { ++$this->omittedRouteCollisionDiagnostics; return; }
        $this->routeCollisions[] = array(
            'code' => $flattened ? 'wordpress_site_plan_page_hierarchy_flattened' : 'wordpress_site_plan_colliding_page_route',
            'severity' => 'warning',
            'message' => substr($flattened ? sprintf('%s cannot inherit the post route of %s (%s); it materializes at %s instead.', $sourcePath, $keptSourcePath, $routePath, $resolvedPath) : sprintf('%s derives the same route as %s (%s); it materializes at %s instead.', $sourcePath, $keptSourcePath, $routePath, $resolvedPath), 0, 256),
            'source_path' => mb_strcut($sourcePath, 0, 256, 'UTF-8'),
            'colliding_source_path' => mb_strcut($keptSourcePath, 0, 256, 'UTF-8'),
            'route_path' => mb_strcut($routePath, 0, 256, 'UTF-8'),
            'resolved_route_path' => mb_strcut($resolvedPath, 0, 256, 'UTF-8'),
            'reason_code' => $flattened ? 'page_hierarchy_flattened' : 'colliding_page_route',
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
            $page['route'] = array('path' => $path, 'parent_path' => PageRoute::parent($path), 'slug' => PageRoute::slug($path));
            if ('/' !== $path) $page['slug'] = $page['route']['slug'];
            $page['reconciliation_identity'] = self::identity('page', $page['source_path'], $path);
            $byRoute[$path] = $index;
        }
        unset($page);
        $inboundLabels = null;
        foreach (array_keys($byRoute) as $path) {
            if ('post' === ($pages[$byRoute[$path]]['post_type'] ?? null)) continue;
            // Any existing route, including a post, blocks a synthetic ancestor.
            foreach (PageRoute::ancestors($path) as $ancestor) if (!isset($byRoute[$ancestor])) {
            $source = 'wordpress-site-plan/routes/' . trim($ancestor, '/') . '.html';
            if (isset($sources[$source])) throw new InvalidArgumentException('WordPress site plan synthetic route source collides with a document.');
            $markup = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"></div><!-- /wp:group -->' . "\n";
            $inboundLabels ??= self::inboundRouteLinkLabels($pages);
            $title = self::mostCommonInboundLabel($inboundLabels, $ancestor) ?? ucwords(str_replace('-', ' ', PageRoute::slug($ancestor)));
            $pages[] = array('source_path' => $source, 'slug' => PageRoute::slug($ancestor), 'title' => $title, 'post_type' => 'page', 'parent_source_path' => '', 'entrypoint' => false, 'area' => null, 'placement' => null, 'canonical_block_markup' => $markup, 'metadata' => array(), 'document_metadata' => array('source_context' => array('source_path' => $source, 'kind' => 'synthetic_route'), 'title' => $title, 'title_declaration' => array('order' => 0, 'placement' => 'head'), 'meta' => array(), 'links' => array(), 'scripts' => array()), 'provenance' => array(), 'reconciliation_identity' => self::identity('page', $source, $ancestor), 'content_hash' => hash('sha256', $markup), 'route' => array('path' => $ancestor, 'parent_path' => PageRoute::parent($ancestor), 'slug' => PageRoute::slug($ancestor)), 'synthetic' => true);
            $byRoute[$ancestor] = count($pages) - 1;
            $sources[$source] = true;
            }
        }
        // The front page has no route segment, but WordPress still needs a
        // physical slug. Reserve captured routes and their generated ancestors
        // before choosing that fallback, so /home keeps its own native page.
        if (isset($byRoute['/'])) {
            $root = &$pages[$byRoute['/']];
            $fallbackPath = '/' . $root['slug'];
            if (isset($byRoute[$fallbackPath])) $root['slug'] = PageRoute::slug(PageRoute::disambiguated($fallbackPath, $byRoute));
            unset($root);
        }
        // Repair ancestors before descendants, including synthetic pages. Existing
        // post routes stay reserved and must never be replaced by synthetic pages.
        $hierarchyOrder = array_keys($pages);
        usort($hierarchyOrder, static fn(int $left, int $right): int => substr_count($pages[$left]['route']['path'], '/') <=> substr_count($pages[$right]['route']['path'], '/'));
        foreach ($hierarchyOrder as $index) {
            $page = &$pages[$index];
            if ('post' === ($page['post_type'] ?? null)) { $page['parent_source_path'] = ''; continue; }
            $parent = $page['route']['parent_path'];
            if ('/' !== $parent && 'page' !== ($pages[$byRoute[$parent] ?? -1]['post_type'] ?? null)) {
                $originalPath = $page['route']['path'];
                $parentSource = $pages[$byRoute[$parent] ?? -1]['source_path'] ?? '';
                do { $parent = PageRoute::parent($parent); } while ('/' !== $parent && 'page' !== ($pages[$byRoute[$parent] ?? -1]['post_type'] ?? null));
                $path = rtrim($parent, '/') . '/' . PageRoute::slug($originalPath);
                if (isset($byRoute[$path])) $path = PageRoute::disambiguated($path, $byRoute);
                $this->recordRouteCollision($parentSource, $page['source_path'], $originalPath, $path, true);
                unset($byRoute[$originalPath]);
                $byRoute[$path] = $index;
                $page['route'] = array('path' => $path, 'parent_path' => PageRoute::parent($path), 'slug' => PageRoute::slug($path));
                $page['slug'] = $page['route']['slug'];
                $page['metadata']['route_path'] = $path;
                $page['reconciliation_identity'] = self::identity('page', $page['source_path'], $path);
            }
            $page['parent_source_path'] = '/' === $parent ? '' : $pages[$byRoute[$parent]]['source_path'];
        }
        unset($page);
        usort($pages, static fn(array $left, array $right): int => substr_count($left['route']['path'], '/') <=> substr_count($right['route']['path'], '/') ?: strcmp($left['route']['path'], $right['route']['path']));
        return $pages;
    }
    /**
     * Inbound internal link labels per canonical route path, counted across the
     * canonical page markup. A synthesized route parent has no source document
     * of its own, so the label other pages use to reach its route is the best
     * available human title.
     *
     * @param array<int,array<string,mixed>> $pages
     * @return array<string,array<string,int>>
     */
    private static function inboundRouteLinkLabels(array $pages): array
    {
        $labels = array();
        $collect = static function (string $href, string $label) use (&$labels): void {
            $href = html_entity_decode((string) preg_replace('/[?#].*$/s', '', trim($href)), ENT_QUOTES | ENT_HTML5);
            if ('' === $href || !str_starts_with($href, '/') || str_starts_with($href, '//')) return;
            $label = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($label), ENT_QUOTES | ENT_HTML5)));
            if ('' === $label) return;
            $path = '/' . trim($href, '/');
            $labels[$path][$label] = ($labels[$path][$label] ?? 0) + 1;
        };
        foreach ($pages as $page) {
            $markup = (string) ($page['canonical_block_markup'] ?? '');
            if (preg_match_all('/<!--\s*wp:navigation-(?:link|submenu)\s+(\{.*?\})\s*\/?-->/s', $markup, $matches)) foreach ($matches[1] as $json) {
                $attributes = json_decode($json, true);
                if (is_array($attributes) && is_string($attributes['url'] ?? null) && is_string($attributes['label'] ?? null)) $collect($attributes['url'], $attributes['label']);
            }
            if (preg_match_all('~<a\b[^>]*?\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>~is', $markup, $matches, PREG_SET_ORDER)) foreach ($matches as $anchor) $collect($anchor[2], $anchor[3]);
        }
        return $labels;
    }
    /** @param array<string,array<string,int>> $labels */
    private static function mostCommonInboundLabel(array $labels, string $path): ?string
    {
        $best = null; $bestCount = 0;
        foreach ($labels[$path] ?? array() as $label => $count) if ($count > $bestCount) { $best = (string) $label; $bestCount = $count; }
        return $best;
    }
    /** @param array<int,array<string,mixed>> $pages @return array<int,array<string,mixed>> */
    private function routesForPages(array $pages): array { $routes = array(); foreach ($pages as $page) $routes[] = array('kind' => 'route', 'source_path' => $page['source_path'], 'target_path' => $page['route']['path'], 'target_slug' => $page['slug'], 'title' => $page['title'], 'parent_source_path' => $page['parent_source_path'], 'source_relation' => !empty($page['synthetic']) ? 'synthetic_parent' : (!empty($page['entrypoint']) ? 'entrypoint' : 'document'), 'order' => count($routes)); return $routes; }

    /**
     * The generated templates without those that repeat the template
     * WordPress falls back to.
     *
     * Without archive.html or search.html WordPress renders those lifecycles
     * through index.html; the entry route is the static front page
     * (show_on_front=page), so without front-page.html it renders through
     * page.html. A byte-identical copy only repeats its fallback. A page
     * template the entry route selects itself would outrank page.html, so
     * front-page.html stays then.
     *
     * @param array<int,array<string,mixed>> $templates
     * @param array<int,array<string,mixed>> $pages
     * @return array<int,array<string,mixed>>
     */
    private static function withoutRedundantTemplates(array $templates, array $pages): array
    {
        $fallbacks = array('archive' => 'index', 'search' => 'index');
        $entry = array_values(array_filter($pages, static fn(array $page): bool => !empty($page['entrypoint'])))[0] ?? null;
        if (null !== $entry && 'page' === ($entry['post_type'] ?? 'page') && !isset($entry['route_template']) && in_array($entry['metadata']['post_meta']['_wp_page_template'] ?? 'default', array('', 'default'), true)) $fallbacks['front-page'] = 'page';
        $markup = array_column($templates, 'canonical_block_markup', 'slug');
        return array_values(array_filter($templates, static fn(array $template): bool => !isset($fallbacks[$template['slug']], $markup[$fallbacks[$template['slug']]]) || $markup[$template['slug']] !== $markup[$fallbacks[$template['slug']]]));
    }

    /**
     * The markup WordPress renders for each lifecycle a plan's templates
     * serve, keyed by template slug and resolved through the template
     * hierarchy. The entry route is the static front page, so front-page
     * falls back to page.
     *
     * @param array<string,mixed> $plan
     * @return array<string,string>
     */
    public static function renderedTemplates(array $plan): array
    {
        $templates = array_column(ArrayField::array($plan, 'templates'), 'canonical_block_markup', 'slug');
        $archives = array('author', 'category', 'date', 'tag', 'taxonomy');
        $rendered = array();
        foreach (array_unique(array_merge(self::NATIVE_TEMPLATE_SLUGS, $archives, array('home'), array_map('strval', array_keys($templates)))) as $slug) {
            $base = explode('-', $slug)[0];
            $chain = array($slug, $base);
            if ('front' === $base || 'page' === $base) $chain[] = 'page';
            elseif ('single' === $base) $chain[] = 'single';
            elseif (in_array($base, $archives, true)) $chain[] = 'archive';
            $chain[] = 'index';
            foreach ($chain as $candidate) if (isset($templates[$candidate])) { $rendered[$slug] = (string) $templates[$candidate]; break; }
        }
        return $rendered;
    }

    /** One key for placement, exclusions, template registration and assignment. */
    public static function routeTemplateKey(array $page): string
    {
        return $page['route_template'] ?? (!empty($page['entrypoint']) ? 'front-page' : ('page' === ($page['post_type'] ?? null) ? 'page-' . $page['slug'] : 'single-' . $page['post_type'] . '-' . $page['slug']));
    }

    /**
     * Share generated route overrides only after their final markup is known.
     * Keep placement keys aligned with routeTemplateKey for binding validation.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array<string,mixed>> $parts
     * @param array<int,array<string,mixed>> $templates
     * @return array<string,string> Shared template titles.
     */
    private static function shareOverrideTemplates(array &$pages, array &$parts, array &$templates): array
    {
        $bySlug = array_column($templates, null, 'slug');
        $groups = array();
        foreach ($pages as $index => $page) {
            $key = self::routeTemplateKey($page);
            $template = $bySlug[$key] ?? null;
            if ('single' === $key || !empty($page['entrypoint']) || !is_array($template) || isset($template['source_path'])) continue;
            $groups[$page['post_type'] . "\0" . $template['canonical_block_markup']][] = $index;
        }
        $renames = array(); $titles = array(); $shared = array(); $layouts = array();
        foreach ($groups as $indexes) {
            if (count($indexes) < 2) continue;
            $page = $pages[$indexes[0]];
            $key = self::routeTemplateKey($page);
            $prefix = 'page' === $page['post_type'] ? 'page-' : 'single-' . $page['post_type'] . '-';
            $slug = $prefix . 'shared-' . substr(hash('sha256', $page['post_type'] . "\0" . $bySlug[$key]['canonical_block_markup']), 0, 12);
            while (isset($bySlug[$slug]) || isset($titles[$slug])) $slug .= '-shared';
            foreach ($indexes as $index) {
                $renames[self::routeTemplateKey($pages[$index])] = $slug;
                $pages[$index]['route_template'] = $slug;
                $pages[$index]['metadata']['post_meta']['_wp_page_template'] = $slug;
            }
            // Name the group, not its members: the Site Editor lists this title.
            $ordinal = $layouts[$page['post_type']] = ($layouts[$page['post_type']] ?? 0) + 1;
            $titles[$slug] = ucfirst(str_replace('_', ' ', $page['post_type'])) . ' (shared layout' . (1 < $ordinal ? ' ' . $ordinal : '') . ')';
        }
        foreach ($templates as &$template) {
            $slug = $renames[$template['slug']] ?? null;
            if (null === $slug || isset($shared[$slug])) continue;
            $shared[$slug] = true;
            $template['slug'] = $slug;
            $template['target_path'] = 'templates/' . $slug . '.html';
            $template['reconciliation_identity'] = self::identity('template', 'wordpress-site-plan/' . $template['target_path'], $template['target_path']);
        }
        unset($template);
        $templates = array_values(array_filter($templates, static fn(array $template): bool => !isset($renames[$template['slug']])));
        foreach ($parts as &$part) {
            foreach (array('template_slugs', 'excluded_template_slugs') as $field) if (isset($part['placement'][$field])) {
                $part['placement'][$field] = array_values(array_unique(array_map(static fn(string $slug): string => $renames[$slug] ?? $slug, $part['placement'][$field])));
            }
            if (isset($part['placement']['template_wrappers'])) {
                $wrappers = array();
                foreach ($part['placement']['template_wrappers'] as $slug => $wrapper) $wrappers[$renames[$slug] ?? $slug] = $wrapper;
                $part['placement']['template_wrappers'] = $wrappers;
            }
        }
        unset($part);
        return $titles;
    }

    /** @param array<string,mixed> $part */
    private static function shellPartAppliesToOverride(array $part, string $slug): bool
    {
        if (!preg_match('/^(?:page|single)-[a-z0-9_-]+$/', $slug) || in_array($slug, $part['placement']['excluded_template_slugs'] ?? array(), true)) return false;
        $types = $part['placement']['post_types'] ?? (isset($part['placement']['post_type']) ? array($part['placement']['post_type']) : null);
        if (null === $types) return true;
        foreach ($types as $type) {
            if (str_starts_with($slug, 'page' === $type ? 'page-' : 'single-' . $type . '-')) return true;
        }
        return false;
    }

    /**
     * Per-route override templates: slugs a part is excluded from, wrapper
     * templates, and frame templates. Excluded slugs are kept as given; the
     * other sources count only page-* and single-* slugs.
     *
     * @param array<int,array<string,mixed>> $parts @param array<int,mixed> $frameSlugs @return array<string,bool>
     */
    private static function overrideTemplateSlugs(array $parts, array $frameSlugs): array
    {
        $slugs = array();
        foreach ($parts as $part) {
            foreach ($part['placement']['excluded_template_slugs'] ?? array() as $slug) if (is_string($slug)) $slugs[$slug] = true;
            foreach (array_keys($part['placement']['template_wrappers'] ?? array()) as $slug) if (preg_match('/^(?:page|single)-[a-z0-9_-]+$/', $slug)) $slugs[$slug] = true;
        }
        foreach ($frameSlugs as $slug) if (is_string($slug) && preg_match('/^(?:page|single)-[a-z0-9_-]+$/', $slug)) $slugs[$slug] = true;
        return $slugs;
    }

    /** Captured landmarks remain content-owned until shell extraction removes them. */
    private static function contentCarriesChrome(string $markup, string $area): bool
    {
        if (!in_array($area, array('header', 'footer'), true)) return false;
        if (1 === preg_match('~"area"\\s*:\\s*"' . $area . '"~i', $markup)) return true;
        $dom = HtmlSerialization::parseDocument('<html><body>' . $markup . '</body></html>');
        $xpath = new \DOMXPath($dom);
        return $xpath->query('//' . $area . '[not(ancestor::article) and not(ancestor::main) and not(ancestor::section)]')->length > 0;
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array<string,mixed>> $parts
     * @param array<int,array<string,mixed>> $surfaces
     * @param array<int,array<string,mixed>> $tokens
     * @param array<int,array<string,mixed>> $routes
     * @param array<int,array<string,mixed>> $taxonomyEntities
     * @param array<string,array<string,mixed>> $frames
     * @param array<string,string> $documentChrome Shared document chrome by template slug.
     * @return array<int,array<string,mixed>>
     */
    private function templates(array $pages, array $parts, array $surfaces = array(), array $tokens = array(), ?AssetReferenceCanonicalizer $references = null, array $routes = array(), ?string $singleContent = null, array $taxonomyEntities = array(), array $frames = array(), array $documentChrome = array(), array $postTemplates = array()): array
     {
         $bound = array_values(array_filter($parts, static fn(array $part): bool => in_array($part['placement']['kind'] ?? '', array('entry_shell', 'shared_shell'), true)));
         usort($bound, static function (array $left, array $right): int {
             $priority = array('header' => 0, 'footer' => 2);
             return (($priority[$left['area']] ?? 1) <=> ($priority[$right['area']] ?? 1)) ?: strcmp($left['slug'], $right['slug']);
         });
         $hasCapturedPosts = (bool) array_filter($pages, static fn(array $page): bool => 'post' === ($page['post_type'] ?? null));
         $hasCommentForm = (bool) array_filter($pages, static fn(array $page): bool => !empty($page['metadata']['native_comment_form']));
         $markup = static function (string $templateSlug) use ($bound, $singleContent, $hasCapturedPosts, $frames, $hasCommentForm, $postTemplates, $pages): string {
             $before = ''; $after = '';
             $container = null;
             foreach ($bound as $part) if (in_array($templateSlug, $part['placement']['template_slugs'] ?? array(), true) || self::shellPartAppliesToOverride($part, $templateSlug)
                 || ('archive' === $templateSlug && 'header' === $part['area'] && in_array('index', $part['placement']['template_slugs'] ?? array(), true) && !in_array($part['slug'], $frames['archive']['shells'] ?? array(), true))) {
                 // A lifecycle may bind chrome only after its captured content
                 // relinquishes that area, including responsive document copies.
                 $retainsChrome = false;
                 foreach ($pages as $page) if (self::routeTemplateKey($page) === $templateSlug && self::contentCarriesChrome($page['canonical_block_markup'], $part['area'])) $retainsChrome = true;
                 if ($retainsChrome) continue;
                 if (is_array($part['placement']['container'] ?? null)) $container = $part['placement']['container'];
                 $reference = '<!-- wp:template-part {"slug":"' . $part['slug'] . '","area":"' . $part['area'] . '","tagName":"' . $part['tag_name'] . '","className":"blocks-engine-shell-transport"} /-->' . "\n";
                 $wrapper = $part['placement']['template_wrappers'][$templateSlug] ?? null;
                 if (is_array($wrapper) && is_string($wrapper['opening'] ?? null) && is_string($wrapper['closing'] ?? null)) $reference = $wrapper['opening'] . "\n" . $reference . $wrapper['closing'] . "\n";
                 if ('footer' === $part['area']) $after .= $reference; else $before .= $reference;
             }
              if (in_array($templateSlug, array('index', 'search', 'archive'), true)) {
                  // Each title renders only in its own context, so one listing
                  // serves the home, archive and search lifecycles alike.
                  $query = '<!-- wp:query-title {"type":"archive"} /-->' . "\n" . '<!-- wp:query-title {"type":"search"} /-->' . "\n"
                      . PostBlocks::defaultQueryLoop(true);
                  $content = '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->' . "\n" . '<main class="wp-block-group">' . "\n" . $query . "\n" . '</main>' . "\n" . '<!-- /wp:group -->';
              } elseif ('404' === $templateSlug) {
                  $content = '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->' . "\n" . '<main class="wp-block-group">' . "\n" . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Page not found</h1><!-- /wp:heading -->' . "\n" . '<!-- wp:search {"label":"Search","showLabel":false,"buttonText":"Search"} /-->' . "\n" . '</main>' . "\n" . '<!-- /wp:group -->';
             } else {
                 $content = $postTemplates[$templateSlug] ?? (('single' === $templateSlug && is_string($singleContent) && '' !== $singleContent) ? $singleContent : '<!-- wp:post-content /-->');
                 if ('single' === $templateSlug && !$hasCapturedPosts) {
                     $content = '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} --><main class="wp-block-group"><!-- wp:post-title {"level":1} /--><!-- wp:post-content /--></main><!-- /wp:group -->';
                 }
             }
            if ($hasCommentForm && !str_contains($content, '<!-- wp:post-comments-form') && ('single' === $templateSlug || str_starts_with($templateSlug, 'single-'))) {
                $content .= "\n" . '<!-- wp:comments --><div class="wp-block-comments"><!-- wp:post-comments-form /--></div><!-- /wp:comments -->';
            }
            // Keep each lifecycle's native content inside the shared app frame.
            if (isset($frames[$templateSlug]) && array() !== $frames[$templateSlug]['shells']) {
                if ('<!-- wp:post-content /-->' === $content) $content = '<!-- wp:post-content {"className":"' . self::FRAME_CONTENT_CLASS . '"} /-->';
                $content = $frames[$templateSlug]['opening'] . $content . $frames[$templateSlug]['closing'];
            }
            if (is_array($container) && is_string($container['opening'] ?? null) && is_string($container['closing'] ?? null)) return $container['opening'] . $before . $content . "\n" . $container['closing'] . $after;
            return $before . $content . "\n" . $after;
        };
         $make = static function (string $slug, string $target, string $content): array { return array('slug' => $slug, 'target_path' => $target, 'canonical_block_markup' => $content, 'source_relation' => 'generated_native_lifecycle', 'reconciliation_identity' => self::identity('template', 'wordpress-site-plan/' . $target, $target), 'content_hash' => self::contentHash($content)); };
         $templates = array($make('index', 'templates/index.html', $markup('index')));
         $templates[] = $make('search', 'templates/search.html', $markup('search'));
         $templates[] = $make('single', 'templates/single.html', $markup('single'));
         $templates[] = $make('archive', 'templates/archive.html', $markup('archive'));
         $templates[] = $make('404', 'templates/404.html', $markup('404'));
        if ( array() !== $pages ) $templates[] = $make('page', 'templates/page.html', $markup('page'));
        foreach ( $pages as $page ) if ( ! empty($page['entrypoint']) ) { $templates[] = $make('front-page', 'templates/front-page.html', $markup('front-page')); break; }
        $overrides = array_filter(self::overrideTemplateSlugs($bound, array_keys($frames)), static fn(int|string $slug): bool => 1 === preg_match('/^(?:page|single)-[a-z0-9_-]+$/', (string) $slug), ARRAY_FILTER_USE_KEY);
        foreach ($pages as $page) if (isset($page['route_template']) && 'single' !== $page['route_template']) $overrides[$page['route_template']] = true;
        $liveOverrides = array_fill_keys(array_map(self::routeTemplateKey(...), $pages), true);
        $overrides = array_intersect_key($overrides, $liveOverrides);
        foreach (array_keys($overrides) as $slug) $templates[] = $make($slug, 'templates/' . $slug . '.html', $markup($slug));
        foreach ($templates as &$template) {
            if (!isset($documentChrome[$template['slug']])) continue;
            // Document chrome frames the post content (leading and trailing side
            // regions around the slot), so it replaces the template's own slot and
            // keeps that slot's attributes. A template without a slot keeps the
            // chrome after its markup.
            $chrome = $documentChrome[$template['slug']];
            $framed = preg_replace_callback('/<!-- wp:post-content(?:\s+\{.*?\})?\s*\/-->/', static fn(array $slot): string => str_replace('<!-- wp:post-content /-->', $slot[0], $chrome), $template['canonical_block_markup'], 1, $count);
            $template['canonical_block_markup'] = 1 === $count ? (string) $framed : $template['canonical_block_markup'] . "\n" . $chrome;
            $template['content_hash'] = self::contentHash($template['canonical_block_markup']);
        }
        unset($template);
        $templates = self::withoutRedundantTemplates($templates, $pages);
        foreach ($templates as &$template) if (isset($frames[$template['slug']])) $template['shell_frame'] = $frames[$template['slug']]['shells']; unset($template);
         $explicitSlugs = array_fill_keys(array_map(static fn(array $surface): string => $surface['template_surface']['slug'], $surfaces), true);
         $templates = array_values(array_filter($templates, static fn(array $template): bool => !isset($explicitSlugs[$template['slug']])));
         foreach ($surfaces as $surface) {
            $declaration = $surface['template_surface']; $slug = $declaration['slug']; $target = 'templates/' . $slug . '.html';
            if (array_filter($templates, static fn(array $template): bool => $template['slug'] === $slug)) throw new InvalidArgumentException('A declared template surface collides with a generated template.');
            $content = is_null($references) ? (string) $surface['block_markup'] : $this->links->markup($references->content((string) $surface['block_markup'], (string) $surface['source_path']), (string) $surface['source_path'], $routes);
             $templates[] = array('slug' => $slug, 'target_path' => $target, 'canonical_block_markup' => $content, 'source_path' => $surface['source_path'], 'template_surface' => $declaration, 'provenance' => $surface['provenance'] ?? array(), 'reconciliation_identity' => self::identity('template', $surface['source_path'], $target), 'content_hash' => self::contentHash($content));
         }
         foreach ($taxonomyEntities as $entity) {
             if (!isset($entity['archive']) || !array_key_exists('presentation_markup', $entity['archive'])) continue;
             $slug = self::archiveTemplateSlug($entity);
             $target = 'templates/' . $slug . '.html';
             if (array_filter($templates, static fn(array $template): bool => $template['slug'] === $slug)) throw new InvalidArgumentException('A native archive template collides with a declared template.');
             $content = (string) $entity['archive']['presentation_markup'];
             $before = ''; $after = '';
              foreach ($bound as $part) {
                  if (!in_array('archive', $part['placement']['template_slugs'] ?? array(), true) || str_contains($content, '"slug":"' . $part['slug'] . '"') || self::contentCarriesChrome($content, $part['area'])) continue;
                 $reference = '<!-- wp:template-part {"slug":"' . $part['slug'] . '","area":"' . $part['area'] . '","tagName":"' . $part['tag_name'] . '","className":"blocks-engine-shell-transport"} /-->' . "\n";
                 $wrapper = $part['placement']['template_wrappers']['archive'] ?? null;
                 if (is_array($wrapper) && is_string($wrapper['opening'] ?? null) && is_string($wrapper['closing'] ?? null)) $reference = $wrapper['opening'] . "\n" . $reference . $wrapper['closing'] . "\n";
                  if ('footer' === $part['area']) $after .= $reference; else $before .= $reference;
              }
              $nestedShellMarkup = '';
              foreach ($bound as $part) if (in_array('archive', $part['placement']['template_slugs'] ?? array(), true)) $nestedShellMarkup .= (string) $part['canonical_block_markup'];
              foreach ($parts as $part) {
                  if ('inline_shared_shell' !== ($part['placement']['kind'] ?? null) || !in_array($entity['archive']['source_path'], $part['placement']['source_paths'] ?? array(), true) || str_contains($content . $nestedShellMarkup, '"slug":"' . $part['slug'] . '"') || self::contentCarriesChrome($content, $part['area'])) continue;
                  $reference = '<!-- wp:template-part {"slug":"' . $part['slug'] . '","area":"' . $part['area'] . '","tagName":"' . $part['tag_name'] . '","className":"blocks-engine-shell-transport"} /-->' . "\n";
                  if ('footer' === $part['area']) $after .= $reference; else $before .= $reference;
              }
             $content = $before . $content . "\n" . $after;
             $template = $make($slug, $target, $content);
             $template['source_path'] = $entity['archive']['source_path'];
             if ('author' === $entity['kind']) $template['author_archive'] = array('slug' => $entity['slug'], 'source_route' => $entity['archive']['source_route']);
             else $template['taxonomy_archive'] = array('taxonomy' => $entity['taxonomy'], 'slug' => $entity['slug'], 'source_route' => $entity['archive']['source_route']);
             $template['reconciliation_identity'] = self::identity('template', $template['source_path'], $target);
             $template['content_hash'] = self::contentHash($content);
             $templates[] = $template;
         }
         // The first proven author supplies the native author fallback as well
         // as its source-specific template. Other authors retain their own
         // captured presentations through WordPress's author-nicename hierarchy.
         if (!array_filter($templates, static fn(array $template): bool => 'author' === $template['slug'])) {
             foreach ($templates as $template) if (isset($template['author_archive'])) {
                 $template['slug'] = 'author';
                 $template['target_path'] = 'templates/author.html';
                 $template['reconciliation_identity'] = self::identity('template', $template['source_path'], $template['target_path']);
                 $templates[] = $template;
                 break;
             }
         }
         return $templates;
    }

    /**
     * Post-chrome matching normalizes every nested range of every post, and
     * the same ranges are matched again while factoring and placing slots.
     * Keyed by content hash, each distinct range is normalized once per plan.
     * Only membership is ever compared, so the identity is kept as a hash.
     *
     * @var array<string,string>
     */
    private array $chromeIdentityKeys = array();

    private function chromeIdentityKey(string $markup): string
    {
        return $this->chromeIdentityKeys[hash('xxh128', $markup)] ??= ('' === ($identity = ChromeIdentity::markup($markup)) ? '' : hash('xxh128', $identity));
    }

    /**
     * Name each featured-image handoff by its declared theme write, so the
     * consumer attaches the same file the plan materializes.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array{token:string,source_path:string,target_path:string}> $tokens
     * @return array<int,array<string,mixed>>
     */
    private static function resolveFeaturedImageTargets(array $pages, array $tokens): array
    {
        $targets = array_column($tokens, 'target_path', 'token');
        foreach ($pages as &$page) {
            $image = $page['metadata']['featured_image'] ?? null;
            if (!is_array($image)) continue;
            $target = $targets[$image['asset_token'] ?? ''] ?? null;
            if (is_string($target)) $page['metadata']['featured_image']['target_path'] = $target;
            else unset($page['metadata']['featured_image']);
        }
        unset($page);
        return $pages;
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
            $sourcePath = ArrayField::string($island, 'source_path');
            $selector = ArrayField::string($island, 'selector');
            if ( isset($superseded[$sourcePath . "\n" . $selector]) ) continue;
            $drop = 'drop' === ($island['disposition'] ?? null);
            $carried = false;
            foreach ( $island['scripts'] ?? array() as $script ) {
                if ( !is_array($script) ) continue;
                $scriptSelector = ArrayField::string($script, 'selector');
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
            $sourcePath = ArrayField::string($document, 'source_path');
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
            $sourcePath = ArrayField::string($document, 'source_path');
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
            $content = is_string($asset['content'] ?? null) ? $asset['content'] : null;
            // Resolve copied author URLs before recovery judges them against the
            // generated stylesheet's directory (which is not their origin).
            if ('editor-static-state' === ($asset['source'] ?? null) && is_string($content)) $content = $references->cssFromOrigins($content, $authorStylesheetOrigins);
            // An inline stylesheet's urls are relative to the documents that held
            // the <style>, not to its generated inline/ path.
            if ('inline-style' === ($asset['source'] ?? null) && is_string($content)) $content = $references->cssFromOrigins($content, array_column($asset['inline_references'] ?? array(), 'source_path'));
            if (is_string($content)) $content = $references->content($content, (string) ($asset['reference_origin'] ?? $asset['source_path']));
            if (is_array($asset['payload_reference'] ?? null)) { $writes[] = $this->referenceWrite('theme_asset', $asset['target_path'], $asset['source_path'], $asset['payload_reference']); continue; }
            $base64Transport = is_string($asset['content_base64'] ?? null);
            $text = is_string($content) && empty($asset['binary']) && 1 === preg_match('//u', $content) && (!$base64Transport || 'text/css' === ($asset['mime_type'] ?? null));
            $data = $text ? $content : (is_string($asset['content_base64'] ?? null) ? $asset['content_base64'] : (is_string($content) ? base64_encode($content) : null));
            if ( ! is_string($data) ) throw new InvalidArgumentException(sprintf('Compiled site asset %s lacks a materializable payload.', $asset['source_path']));
            $writes[] = $this->write('theme_asset', $asset['target_path'], $data, $asset['source_path'], $text ? 'utf8' : 'base64');
            if ($text && self::isVerbatimRuntimeWrite('theme_asset', $asset['target_path'])) $this->recordUnresolvedScriptReferences($asset['target_path'], $data, $asset['source_path']);
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
            if (null !== $localTarget && !CaptureEvidence::isRecognizedRuntime($contents[$localTarget] ?? '') && $this->hasDynamicScriptReferences($contents[$localTarget] ?? '')) { $unsupported('wordpress_site_plan_script_dynamic_references', 'A local script contains dynamic imports, script injection, or runtime URL construction that cannot be proven from the canonical write.'); continue; }
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

    /** @internal Shared with document-head materialization. */
    public static function isExecutableScriptType(string $type, bool $module): bool
    {
        $type = strtolower(trim($type));
        return $module || '' === $type || in_array($type, array('module', 'text/javascript', 'application/javascript', 'text/ecmascript', 'application/ecmascript'), true);
    }

    // A dynamic reference is a URL the script builds or assigns: an import, an
    // injected script, a `src` it sets, a URL it constructs. Inserting a node
    // (`appendChild`, `insertBefore`) names no URL: a copy button's scratch
    // textarea or a rendered list item references nothing the write must hold.
    private function hasDynamicScriptReferences(string $content): bool { return preg_match('/\bimport\s*\(|\bdocument\s*\.\s*createElement\s*\(\s*["\']script|\.\s*src\s*=|\bsetAttribute\s*\(\s*["\']src(?:set)?["\']|\bnew\s+URL\s*\(/i', $content) === 1; }
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
    /** @internal Shared artifact-root semantics for document-head asset resolution. */
    public static function entryRootFromDocuments(array $documents): string { foreach ($documents as $document) { if (is_array($document) && (!empty($document['entrypoint']) || 'entrypoint' === ($document['source_relation'] ?? null)) && is_string($document['source_path'] ?? null)) { $dir = str_replace('\\', '/', dirname($document['source_path'])); return in_array($dir, array('.', '/', ''), true) ? '' : $dir; } } return ''; }

    /** @param array<int,array<string,mixed>> $assets @param array<int,array<string,string>> $templates @param array<int,array<string,mixed>> $parts @param array<int,array<string,mixed>> $pages @param array<int,array<string,mixed>> $menus @return array<int,array<string,mixed>> */
    private function scaffoldWrites(array $assets, array $templates, array $parts, array $scripts, array $theme, array $tokens, array $pages = array(), array $menus = array(), bool $runtimeEmoji = false, array $rootDocuments = array()): array
    {
        $writes = array($this->write('theme_scaffold', 'style.css', "/*\nTheme Name: Blocks Engine Site\nText Domain: blocks-engine-site\n*/\n"), $this->write('theme_scaffold', 'theme.json', json_encode($theme, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"));
        $writes[] = $this->write('theme_bootstrap', 'functions.php', ThemeBootstrap::functionsPhp($assets, $scripts, $parts, $tokens, $templates, $pages, $menus, $runtimeEmoji, $rootDocuments));
        if (DocumentRootContext::needsCanvas(array_merge($pages, $rootDocuments))) $writes[] = $this->write('theme_scaffold', 'document-canvas.php', DocumentRootContext::canvas());
        foreach ( $templates as $template ) $writes[] = $this->write('theme_template', $template['target_path'], $template['canonical_block_markup']);
        foreach ( $parts as $part ) $writes[] = $this->write('theme_template_part', 'parts/' . $part['slug'] . '.html', $part['canonical_block_markup']);
        return $writes;
    }

    /** Document navigation relationships are routes; subresource links are assets. */
    private static function isRouteLink(array $attributes): bool
    {
        $relations = preg_split('/\s+/', strtolower(trim($attributes['rel'] ?? ''))) ?: array();
        return array() !== $relations && array() === array_diff($relations, array('canonical', 'alternate', 'next', 'prev', 'author', 'license', 'help')) && !isset($attributes['as']) && !in_array(strtolower($attributes['type'] ?? ''), array('text/css', 'text/javascript'), true);
    }
    // A file-system path and a URL path are different encodings of the same
    // name: browsers request exactly what the bootstrap emits and the server
    // URL-decodes the request path before touching the filesystem, so a
    // literal `%24` in an on-disk name must be emitted as `%2524` to round-trip
    // back onto the materialized file. Encode every segment, preserving the
    // `/` separators.

    /** Inline occurrences store the author's media separately from captured media. @return array<string,mixed> */
    private static function inlineStylesheetDeviceAttributes(array $occurrence): array
    {
        if (!isset($occurrence[CaptureEvidence::DEVICE_STYLE])) return array();
        return self::stylesheetDeviceAttributes($occurrence) + array(CaptureEvidence::SOURCE_MEDIA => (string) ($occurrence['source_media'] ?? $occurrence['media'] ?? ''));
    }

    /** @return array<string,mixed> */
    public static function stylesheetDeviceAttributes(array $occurrence): array
    {
        return array_intersect_key($occurrence, array_flip(array(CaptureEvidence::DEVICE_STYLE, CaptureEvidence::SOURCE_MEDIA)));
    }

    /** @param array<int,array<string,mixed>> $pages @param array<int,array<string,mixed>> $rootDocuments @return array<string,array<string,mixed>> */
    public static function assetOwnerDocuments(array $pages, array $rootDocuments): array
    {
        return array_column($pages, null, 'source_path') + array_column($rootDocuments, null, 'source_path');
    }

    /** @param array<string,mixed> $page @return array<string,mixed> */
    public static function stylesheetDocumentScope(array $page): array
    {
        if (!isset($page['post_type'])) return array('kind' => 'archive', 'source_path' => $page['source_path'], 'route_path' => trim($page['route']['path'], '/'));
        return array('kind' => 'post' === $page['post_type'] ? 'post' : 'page', 'source_path' => $page['source_path'], 'route_path' => trim($page['route']['path'], '/'), 'reconciliation_identity' => $page['reconciliation_identity'], 'front_page' => '/' === $page['route']['path']);
    }

    private static function assertAssetScopes(mixed $scopes): void
    {
        if (!is_array($scopes) || array() === $scopes) throw new InvalidArgumentException('WordPress site plan asset scopes must be a nonempty array.');
        foreach ($scopes as $scope) {
            if (!is_array($scope) || !in_array($scope['kind'] ?? null, array('global', 'page', 'post', 'archive'), true)) throw new InvalidArgumentException('WordPress site plan asset scope is invalid.');
            if ('global' === $scope['kind']) {
                if (array('kind') !== array_keys($scope)) throw new InvalidArgumentException('A global asset scope cannot declare page fields.');
                continue;
            }
            if ('archive' === $scope['kind']) {
                if (array('kind', 'source_path', 'route_path') !== array_keys($scope) || !self::safePath($scope['source_path']) || !is_string($scope['route_path'])) throw new InvalidArgumentException('An archive asset scope is structurally invalid.');
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
     * Apply `$map` to every block markup a collection item template carries:
     * the template card and each item's slot values. A provider renders this
     * markup in place of page content, so it takes the same reference
     * projection as the page it came from.
     *
     * @param array<string,mixed> $entity
     * @param callable(string):string $map
     * @return array<string,mixed>
     */
    public static function mapEntityMarkup(array $entity, callable $map): array
    {
        if (is_string($entity['item_template']['block_markup'] ?? null)) $entity['item_template']['block_markup'] = $map($entity['item_template']['block_markup']);
        foreach (ArrayField::array($entity, 'items') as $itemIndex => $item) {
            foreach (ArrayField::array($item, 'slots') as $slotId => $slot) {
                if (is_string($slot['markup'] ?? null)) $entity['items'][$itemIndex]['slots'][$slotId]['markup'] = $map($slot['markup']);
            }
        }
        // A provider rebuilds a form from its readable blocks, so their media
        // must reference published assets exactly as the page does.
        if (is_array($entity['readable_blocks'] ?? null)) $entity['readable_blocks'] = self::mapBlockTreeMarkup($entity['readable_blocks'], $map);
        return $entity;
    }

    /**
     * Map every markup string of a parsed block tree. Attributes travel as
     * their serialized block comment, the form markup mappers already read.
     *
     * @param array<int|string,mixed> $blocks
     * @return array<int|string,mixed>
     */
    private static function mapBlockTreeMarkup(array $blocks, callable $map): array
    {
        foreach ($blocks as $index => $block) {
            if (!is_array($block) || !is_string($block['blockName'] ?? null)) continue;
            if (is_array($block['attrs'] ?? null) && array() !== $block['attrs']) {
                $comment = $map('<!-- wp:' . $block['blockName'] . ' ' . BlockAttributes::encode($block['attrs'], JSON_INVALID_UTF8_SUBSTITUTE) . ' /-->');
                if (preg_match('~^<!-- wp:\S+ (\{.*\}) /-->$~s', $comment, $match) && is_array($attrs = json_decode($match[1], true))) $block['attrs'] = $attrs;
            }
            if (is_string($block['innerHTML'] ?? null)) $block['innerHTML'] = $map($block['innerHTML']);
            foreach (ArrayField::array($block, 'innerContent') as $contentIndex => $content) {
                if (is_string($content)) $block['innerContent'][$contentIndex] = $map($content);
            }
            if (is_array($block['innerBlocks'] ?? null)) $block['innerBlocks'] = self::mapBlockTreeMarkup($block['innerBlocks'], $map);
            $blocks[$index] = $block;
        }
        return $blocks;
    }

    /**
     * Rendered design usage of every compiled document, keyed by source path.
     *
     * @param array<int,array<string,mixed>> $documents
     * @return array<string,array<string,mixed>>
     */
    private static function designUsage(array $documents): array
    {
        $usage = array();
        foreach ($documents as $document) {
            if (is_array($document['design_usage'] ?? null) && is_string($document['source_path'] ?? null)) $usage[$document['source_path']] = $document['design_usage'];
        }
        return $usage;
    }

    /**
     * A script or data file is published verbatim, so a local route or asset
     * path written in its strings (`'<a href="' + slug + '.html">'`) cannot be
     * rewritten to its WordPress address. The page still imports: each distinct
     * string is reported once per file as a warning naming where it was written.
     */
    private function recordUnresolvedScriptReferences(string $targetPath, string $payload, string $sourcePath): void
    {
        self::assertNoLocalBrowserReferencesInWrite($targetPath, $payload, $sourcePath, function (string $url, string $attribute) use ($sourcePath): void {
            $key = $sourcePath . "\0" . $url;
            if (isset($this->unresolvedScriptReferenceDiagnostics[$key])) return;
            if (count($this->unresolvedScriptReferenceDiagnostics) >= self::MAX_UNRESOLVED_NAVIGATION_DIAGNOSTICS) { $this->omittedUnresolvedScriptReferenceDiagnostics++; return; }
            $this->unresolvedScriptReferenceDiagnostics[$key] = array(
                'code' => 'wordpress_site_plan_unresolved_script_reference',
                'severity' => 'warning',
                'message' => mb_strcut("{$sourcePath} writes {$url} in its strings; the file is published verbatim, so that address is not rewritten for WordPress.", 0, 256, 'UTF-8'),
                'source_path' => mb_strcut($sourcePath, 0, 256, 'UTF-8'),
                'value' => mb_strcut($url, 0, 256, 'UTF-8'),
                'attribute' => $attribute,
                'reason_code' => 'unresolved_local_browser_reference',
            );
        });
    }
    /** @return array<int,array<string,mixed>> */
    private function unresolvedScriptReferenceDiagnostics(): array
    {
        $diagnostics = array_values($this->unresolvedScriptReferenceDiagnostics);
        if ($this->omittedUnresolvedScriptReferenceDiagnostics > 0) $diagnostics[] = array('code' => 'wordpress_site_plan_unresolved_script_reference', 'severity' => 'warning', 'message' => sprintf('%d more unresolved script references were published verbatim; omitted from this diagnostic list.', $this->omittedUnresolvedScriptReferenceDiagnostics));
        return $diagnostics;
    }

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
    /** @internal Exposed for ShellExtraction, which resolves shell links through the run's route table. */
    public function links(): RouteLinks
    {
        return $this->links;
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
        $scriptLoading = (new self())->scriptLoading($plan['pages'], $plan['template_parts'], $plan['assets'], $plan['reference_tokens'], $plan['operations'], $plan['runtime_declarations']);
        if (!is_array($bootstrap) || 'theme_bootstrap' !== ($bootstrap['kind'] ?? null) || 'wordpress-site-plan/functions.php' !== ($bootstrap['source_path'] ?? null) || ThemeBootstrap::functionsPhp($plan['assets'], $scriptLoading['scripts'], $plan['template_parts'], $plan['reference_tokens'], $plan['templates'], $plan['pages'], $plan['menus'], 'wordpress' === ($plan['theme']['runtime_emoji'] ?? null), $plan['theme']['document_roots'] ?? array()) !== ($bootstrap['canonical_payload'] ?? $bootstrap['payload']['data'] ?? null)) throw new InvalidArgumentException('WordPress site plan functions.php bootstrap is invalid.');
        if (DocumentRootContext::needsCanvas(array_merge($plan['pages'], $plan['theme']['document_roots'] ?? array()))) {
            $canvas = $writes['document-canvas.php'] ?? null;
            if (!is_array($canvas) || 'theme_scaffold' !== ($canvas['kind'] ?? null) || 'wordpress-site-plan/document-canvas.php' !== ($canvas['source_path'] ?? null) || DocumentRootContext::canvas() !== ($canvas['payload']['data'] ?? null)) throw new InvalidArgumentException('WordPress site plan document canvas is invalid.');
        }
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
     * A generated PHP write (functions.php) carries markup only inside
     * var_export() string literals, which escape `\` and `'`. Read raw, a
     * head style's `url('…')` is `url(\'…\')` and its token reads as a
     * quoted value. Undo those two escapes so the markup is read as written.
     * (Tokenizing would be exact but costs ~10x the file in memory.)
     */
    private static function assertNoLocalBrowserReferencesInWrite(string $targetPath, string $payload, string $sourcePath, ?callable $report = null): void
    {
        $target = strtolower($targetPath);
        if (str_ends_with($target, '.php')) {
            self::assertNoLocalBrowserReferences(strtr($payload, array('\\\\' => '\\', "\\'" => "'")), $sourcePath, 'write:php', $report);
            return;
        }
        $decoded = str_ends_with($target, '.json') ? json_decode($payload, true) : null;
        if (!is_array($decoded)) {
            self::assertNoLocalBrowserReferences(str_ends_with($target, '.css') ? '<style>' . $payload . '</style>' : $payload, $sourcePath, 'write', $report);
            return;
        }
        array_walk_recursive($decoded, static function (mixed $value) use ($sourcePath, $report): void {
            if (is_string($value) && str_contains($value, '<')) self::assertNoLocalBrowserReferences($value, $sourcePath, 'write:json', $report);
        });
    }
    /**
     * A captured script or JSON data file is published byte for byte apart from
     * declared asset tokens. Markup it builds at runtime is not page markup the
     * plan can rewrite, so its local strings are findings, not plan failures.
     */
    private static function isVerbatimRuntimeWrite(string $kind, string $targetPath): bool
    {
        return 'theme_asset' === $kind && 1 === preg_match('/\.(?:m?js|cjs|json)$/i', $targetPath);
    }
    private static function assertNoLocalBrowserReferences(string $content, string $sourcePath = '', string $context = 'markup', ?callable $report = null): void
    {
        $assertReference = static function (string $candidate, string $attribute, string $element = '', bool $documentLink = false) use ($sourcePath, $context, $report): void { $url = trim(preg_split('/\s+/', trim(html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8')))[0] ?? ''); $route = str_starts_with($url, '/') && (str_starts_with($attribute, 'json:route_') || RouteLinks::isNavigationAttribute($element, $attribute) || ('href' === $attribute && $documentLink)); if ('' !== $url && !str_starts_with($url, self::TOKEN_PREFIX) && !$route && !preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#|\?)~i', $url)) { if (null !== $report) { $report($url, $attribute); return; } throw new ValidationException(sprintf('WordPress site plan contains unresolved local browser reference %s.', $url), array('source_path' => $sourcePath, 'document_kind' => $context, 'declaration_kind' => 'browser_reference', 'declaration_index' => 0, 'reason' => 'unresolved_local_browser_reference', 'fields' => array('context' => $context, 'attribute' => $attribute, 'value' => $url))); } };
        $assertCss = static function (string $css, string $cssContext) use ($assertReference): void { \Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\CssUrlRewriter::rewrite(html_entity_decode($css, ENT_QUOTES | ENT_HTML5, 'UTF-8'), static function (string $url) use ($assertReference, $cssContext): string { $assertReference($url, $cssContext . ':url'); return $url; }); if (preg_match_all('/@import\s+(?:url\(\s*)?(?:"([^"]*)"|\'([^\']*)\'|([^\s\)"\';]+))/i', \Automattic\BlocksEngine\PhpTransformer\Css\CssSyntaxScanner::withoutComments(html_entity_decode($css, ENT_QUOTES | ENT_HTML5, 'UTF-8')), $matches, PREG_SET_ORDER)) foreach ($matches as $match) $assertReference((string) (($match[1] ?? '') ?: ($match[2] ?? '') ?: ($match[3] ?? '')), $cssContext . ':@import'); };
        $assertJsonAttributes = null;
        $assertJsonAttributes = static function (array $attributes, bool $route) use (&$assertJsonAttributes, $assertReference, $sourcePath, $context, $report): void {
            foreach ($attributes as $name => $value) {
                if (is_array($value)) { $assertJsonAttributes($value, $route); continue; }
                // Dynamic companion blocks carry editable markup in a string
                // attribute. Validate its browser references just as strictly as
                // native saved markup instead of treating it as opaque JSON.
                if ('content' === strtolower((string) $name) && is_string($value) && str_contains($value, '<')) {
                    self::assertNoLocalBrowserReferences($value, $sourcePath, $context . ':companion_content', $report);
                    continue;
                }
                if (CaptureEvidence::GALLERY_SEQUENCE === strtolower((string) $name) && is_string($value)) {
                    AssetReferenceCanonicalizer::gallerySequence($value, static function (string $reference) use ($assertReference): string {
                        $assertReference($reference, CaptureEvidence::GALLERY_SEQUENCE);
                        return $reference;
                    });
                    continue;
                }
                if (!is_string($name) || !is_string($value) || !in_array(strtolower($name), array('url', 'src', 'href', 'poster', 'action', 'srcset'), true)) continue;
                $routeField = $route && 'url' === strtolower($name) ? 'route_url' : (in_array(strtolower($name), array('href', 'action'), true) ? 'route_' . strtolower($name) : strtolower($name));
                foreach ('srcset' === strtolower($name) ? SrcsetParser::urls($value) : array($value) as $candidate) $assertReference($candidate, 'json:' . $routeField);
            }
        };
        foreach (HtmlMarkupParser::htmlMarkupNodes($content) as $node) {
            if ('tag' === $node['kind']) foreach ($node['attributes'] as $name => $value) {
                if (CaptureEvidence::GALLERY_SEQUENCE === $name) {
                    AssetReferenceCanonicalizer::gallerySequence($value, static function (string $reference) use ($assertReference, $name, $node): string {
                        $assertReference($reference, $name, $node['name']);
                        return $reference;
                    });
                    continue;
                }
                if (!in_array($name, array('xlink:href', 'srcset', 'src', 'href', 'poster', 'action', 'style'), true)) continue;
                if ('action' === $name && 'form' !== $node['name']) continue;
                if ('style' === $name) { $assertCss($value, 'style_attribute'); continue; }
                foreach ('srcset' === $name ? SrcsetParser::urls($value) : array($value) as $candidate) $assertReference($candidate, $name, $node['name'], 'link' === $node['name'] && self::isRouteLink($node['attributes']));
            }
            if ('style' === $node['kind']) $assertCss($node['css'], 'style_block');
            if ('comment' === $node['kind'] && preg_match('~^\s*wp:~i', $node['content'])) {
                $attributes = BlockAttributes::decodeComment($node['content']);
                if (is_array($attributes)) {
                    $assertJsonAttributes($attributes, self::jsonUrlIsRoute($node['content']));
                } elseif (preg_match_all('~(?:"|\\\\u0022)(url|src|href|poster|action|srcset)(?:"|\\\\u0022)\s*:\s*(?:"|\\\\u0022)(.*?)(?:"|\\\\u0022)~is', $node['content'], $fields, PREG_SET_ORDER)) {
                    $route = self::jsonUrlIsRoute($node['content']);
                    foreach ($fields as $field) {
                        $name = strtolower($field[1]);
                        $routeField = $route && 'url' === $name ? 'route_url' : (in_array($name, array('href', 'action'), true) ? 'route_' . $name : $name);
                        foreach ('srcset' === $name ? SrcsetParser::urls($field[2]) : array($field[2]) as $candidate) $assertReference(str_replace('\\/', '/', (string) $candidate), 'json:' . $routeField);
                    }
                }
            }
        }
    }
    private static function jsonUrlIsRoute(string $comment): bool { if (!preg_match('~^\s*wp:([^\s{]+)~i', $comment, $block)) return false; return in_array(strtolower($block[1]), self::ROUTE_URL_BLOCKS, true); }
    /** @param array<string,bool> $tokens @param array<string,array<string,mixed>> $writes */
    private static function assertResolution(array $plan, array $tokens, array $writes): void
    {
        if (!isset($plan['resolution'])) return;
        $resolution = $plan['resolution'];
        if (!is_array($resolution) || array_keys(array_diff_key($resolution, array('attachment_resolutions' => true))) !== array('schema', 'theme_uri', 'runtime_capabilities', 'asset_publication_references', 'unsupported_optional_capabilities') || WordPressSitePlanResolver::RESOLUTION_SCHEMA !== ($resolution['schema'] ?? null) || !is_string($resolution['theme_uri'] ?? null) || !is_array($resolution['runtime_capabilities'] ?? null) || !is_array($resolution['asset_publication_references'] ?? null) || !is_array($resolution['unsupported_optional_capabilities'] ?? null) || WordPressSitePlanResolver::normalizeThemeUri($resolution['theme_uri']) !== $resolution['theme_uri']) throw new InvalidArgumentException('WordPress site plan resolution is malformed or fabricated.');
        $attachments = WordPressSitePlanResolver::normalizeAttachments($resolution['attachment_resolutions'] ?? array(), $plan['assets']);
        $references = WordPressSitePlanResolver::references($plan['reference_tokens'], $resolution['theme_uri'], $attachments);
        $expectedPublicationReferences = WordPressSitePlanResolver::publicationReferences($plan['runtime_declarations'], $plan['reference_tokens'], $plan['writes'], $resolution['theme_uri'], $attachments);
        try { $capabilities = WordPressSitePlanResolver::normalizeRuntimeCapabilities($resolution['runtime_capabilities']); $unsupported = WordPressSitePlanResolver::unsupportedOptionalCapabilities($plan['runtime_declarations'], $capabilities); } catch (InvalidArgumentException) { throw new InvalidArgumentException('WordPress site plan publication resolution is malformed or stale.'); }
        if ($resolution['runtime_capabilities'] !== $capabilities || $resolution['asset_publication_references'] !== $expectedPublicationReferences || $resolution['unsupported_optional_capabilities'] !== $unsupported) throw new InvalidArgumentException('WordPress site plan publication resolution is malformed or stale.');
        foreach (array('pages', 'template_parts', 'templates') as $kind) foreach ($plan[$kind] as $document) {
            if (!is_array($document) || !is_string($document['canonical_block_markup'] ?? null) || !is_string($document['resolved_block_markup'] ?? null) || WordPressSitePlanResolver::resolveMarkup($document['canonical_block_markup'], $references, $attachments) !== $document['resolved_block_markup']) throw new InvalidArgumentException("WordPress site plan resolved {$kind} payload is not canonical.");
        }
        foreach ($plan['menus'] as $menu) if (is_string($menu['block_markup'] ?? null)) {
            if (!is_string($menu['resolved_block_markup'] ?? null) || WordPressSitePlanResolver::resolvePayload($menu['block_markup'], $references) !== $menu['resolved_block_markup']) throw new InvalidArgumentException('WordPress site plan resolved navigation content is not canonical.');
        }
        foreach ($writes as $write) {
            if ('utf8' !== ($write['payload']['encoding'] ?? null)) { if (isset($write['canonical_payload'], $write['canonical_payload_hash'])) throw new InvalidArgumentException('WordPress site plan binary write cannot carry a resolution projection.'); continue; }
            if (!is_string($write['canonical_payload'] ?? null) || !self::hash($write['canonical_payload_hash'] ?? null) || $write['canonical_payload_hash'] !== self::contentHash($write['canonical_payload']) || WordPressSitePlanResolver::resolveWritePayload($write['canonical_payload'], $plan['reference_tokens'], $resolution['theme_uri'], $write['target_path'], $attachments) !== $write['payload']['data']) throw new InvalidArgumentException('WordPress site plan resolved write payload is not canonical.');
            if (!self::isVerbatimRuntimeWrite($write['kind'], $write['target_path'])) self::assertNoLocalBrowserReferencesInWrite($write['target_path'], $write['canonical_payload'], $write['source_path']);
        }
        self::assertResolvedMetadata($plan, $references);
    }
    /** @param array<string,string> $references */
    private static function assertResolvedMetadata(array $plan, array $references): void
    {
        foreach (array('pages', 'template_parts') as $kind) foreach ($plan[$kind] as $document) foreach (array_merge($document['document_metadata']['links'] ?? array(), $document['document_metadata']['scripts'] ?? array(), $document['document_metadata']['head']['elements'] ?? array()) as $declaration) {
            if (!is_array($declaration)) throw new InvalidArgumentException('WordPress site plan resolved metadata declaration is invalid.');
            if (is_string($declaration['asset_reference'] ?? null)) {
                if (!is_string($declaration['resolved_url'] ?? null) || WordPressSitePlanResolver::resolvePayload($declaration['asset_reference'], $references) !== $declaration['resolved_url']) throw new InvalidArgumentException('WordPress site plan resolved metadata URL is missing, stale, or tampered.');
                continue;
            }
            if (array_key_exists('resolved_url', $declaration)) throw new InvalidArgumentException('WordPress site plan external metadata URL must not carry a resolved alias.');
        }
    }
    private static function assertRoute(array $page, string $entryRoot = ''): void { $route = $page['route'] ?? null; $expected = is_string($page['metadata']['route_path'] ?? null) && '' !== $page['metadata']['route_path'] ? PageRoute::canonical($page['metadata']['route_path']) : PageRoute::fromSource($page['source_path'], $entryRoot); if (!is_array($route) || !is_string($route['path'] ?? null) || !preg_match('~^/(?:[a-z0-9_-]+(?:/[a-z0-9_-]+)*)?$~', $route['path']) || !is_string($route['parent_path'] ?? null) || !is_string($route['slug'] ?? null) || PageRoute::parent($route['path']) !== $route['parent_path'] || PageRoute::slug($route['path']) !== $route['slug'] || (!isset($page['synthetic']) && !PageRoute::isDerived($route['path'], $expected)) || (isset($page['synthetic']) && (true !== $page['synthetic'] || !str_starts_with((string) ($page['source_path'] ?? ''), 'wordpress-site-plan/routes/')))) throw new InvalidArgumentException('WordPress site plan page route is invalid.'); }
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
        // Inferred decisions may describe pages as well as posts. Structured
        // page types are classification evidence, not publication timestamps.
        foreach ($decision['evidence'] as $evidence) {
            if (is_array($evidence) && in_array($evidence['source'] ?? null, array('json-ld:WebPage', 'json-ld:AboutPage', 'json-ld:ContactPage', 'json-ld:CollectionPage'), true) && ('inferred' === $decision['state'] && 'page' !== $decision['post_type'] || array_key_exists('publication_timestamp', $evidence))) throw new InvalidArgumentException('WordPress site plan structured page evidence is invalid.');
        }
        $timestamps = array(); foreach ($decision['evidence'] as $evidence) { if (!is_array($evidence) || array_diff(array_keys($evidence), array('source', 'publication_timestamp')) || !is_string($evidence['source'] ?? null) || '' === $evidence['source'] || strlen($evidence['source']) > 128) throw new InvalidArgumentException('WordPress site plan content decision evidence is invalid.'); if (isset($evidence['publication_timestamp'])) { if (!PublicationDate::isUtc($evidence['publication_timestamp'])) throw new InvalidArgumentException('WordPress site plan content decision timestamp is invalid.'); $timestamps[] = $evidence['publication_timestamp']; } }
        if (isset($document['publication_timestamp']) && (!PublicationDate::isUtc($document['publication_timestamp']) || !in_array($document['publication_timestamp'], $timestamps, true))) throw new InvalidArgumentException('WordPress site plan publication timestamp is invalid.');
    }
    /** @param array<string,mixed> $metadata @param array<string,bool> $tokens */
    private static function assertDocumentMetadata(array $metadata, array $tokens, string $sourcePath, string $documentKind): void
    {
        if (array_key_exists('root_attributes', $metadata)) DocumentRootContext::assertValid($metadata['root_attributes']);
        if (array_key_exists('body_attributes', $metadata)) DocumentRootContext::assertValid($metadata['body_attributes']);
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
            if (array_diff(array_keys($row), array('order', 'placement', 'rel', 'type', 'media', 'integrity', 'crossorigin', 'referrerpolicy', 'as', 'fetchpriority', 'sizes', CaptureEvidence::DEVICE_STYLE, CaptureEvidence::SOURCE_MEDIA, 'asset_reference', 'url', 'resolved_url'))) self::invalidDeclaration('link declaration', 'link', $index, $sourcePath, $documentKind, 'unsupported_field', $row);
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
                $fields[$key] = is_string($value) ? mb_strcut($value, 0, 256, 'UTF-8') : $value;
            }
        }
        $context = array('source_path' => $sourcePath, 'document_kind' => $documentKind, 'declaration_kind' => $declarationKind, 'declaration_index' => $index, 'reason' => $reason, 'fields' => $fields);
        if (0 < $truncated) $context['fields_truncated'] = $truncated;
        throw new ValidationException("WordPress site plan {$label} is invalid: {$reason}.", $context);
    }
    /** @param array<string,mixed> $reporting @param array<string,bool> $pagePaths @param array<string,bool> $tokens */
    private static function assertReporting(array $reporting, array $sourcePaths, array $tokens, array $diagnostics): void { if(!is_array($reporting['source_documents']??null)||!is_array($reporting['metrics']??null)||!is_array($reporting['core_html_fallback_evidence']??null)||!is_array($reporting['diagnostic_codes']??null))throw new InvalidArgumentException('WordPress site plan reporting summary is invalid.');$sources=array();foreach($reporting['source_documents'] as $document){if(!is_array($document)||!self::safePath($document['source_path']??null)||!is_string($document['kind']??null)||!is_string($document['body_format']??null)||!is_bool($document['block_document']??null)||!is_array($document['provenance']??null))throw new InvalidArgumentException('WordPress site plan source document summary is invalid.');self::unique($sources,$document['source_path'],'source document');}if(count($sources)!==count($sourcePaths)||array_keys($sources)!==array_keys($sourcePaths))throw new InvalidArgumentException('WordPress site plan source document summaries do not match materialized documents.');foreach(array('source_document_count','block_document_count','native_block_count','fallback_count') as $key)if(!is_int($reporting['metrics'][$key]??null))throw new InvalidArgumentException('WordPress site plan reporting metric is invalid.');$linked=array_fill_keys($reporting['diagnostic_codes'],true);foreach($reporting['diagnostic_codes'] as $code)if(!is_string($code)||''===$code)throw new InvalidArgumentException('WordPress site plan diagnostic linkage is invalid.');foreach($diagnostics as $diagnostic)if(is_array($diagnostic)&&is_string($diagnostic['code']??null)&&!isset($linked[$diagnostic['code']]))throw new InvalidArgumentException('WordPress site plan diagnostics are not linked to reporting.');}
    /** @param array<string,string> $tokens */
    private static function assertWrite(mixed $write, array $tokens, bool $browserReferences): void { if (!is_array($write) || !is_string($write['kind'] ?? null) || !self::safePath($write['source_path'] ?? null) || !self::safePath($write['target_path'] ?? null) || !self::hash($write['reconciliation_identity'] ?? null) || !self::hash($write['payload_hash'] ?? null) || !is_array($write['payload'] ?? null) || !in_array($write['payload']['encoding'] ?? null, array('utf8','base64','reference'), true) || $write['reconciliation_identity'] !== self::identity('write', $write['source_path'], $write['target_path'])) throw new InvalidArgumentException('WordPress site plan write has a stale payload hash or invalid structure.'); if ('reference' === $write['payload']['encoding']) { $reference = self::payloadReference($write['payload']['reference'] ?? null); if (!is_array($reference) || ($write['raw_sha256'] ?? null) !== $reference['sha256'] || $write['payload_hash'] !== self::contentHash(RuntimeDeclarations::canonicalJson($reference))) throw new InvalidArgumentException('WordPress site plan write has an invalid payload reference.'); return; } if (!is_string($write['payload']['data'] ?? null) || $write['payload_hash'] !== self::contentHash($write['payload']['data'])) throw new InvalidArgumentException('WordPress site plan write has a stale payload hash or invalid structure.'); if ('base64' === $write['payload']['encoding'] && false === base64_decode($write['payload']['data'], true)) throw new InvalidArgumentException('WordPress site plan write has invalid base64 payload.'); if ('utf8' === $write['payload']['encoding']) { self::assertTokens($write['payload']['data'], $tokens); if ($browserReferences && !self::isVerbatimRuntimeWrite($write['kind'], $write['target_path'])) self::assertNoLocalBrowserReferencesInWrite($write['target_path'], $write['payload']['data'], $write['source_path']); } }
    /** @return array{schema:string,id:string,bytes:int,sha256:string}|null */
    private static function payloadReference(mixed $reference): ?array { if (!is_array($reference) || 'blocks-engine/payload-reference/v1' !== ($reference['schema'] ?? null) || !is_string($reference['id'] ?? null) || '' === $reference['id'] || !is_int($reference['bytes'] ?? null) || $reference['bytes'] < 0 || !self::hash($reference['sha256'] ?? null)) return null; return array('schema' => $reference['schema'], 'id' => $reference['id'], 'bytes' => $reference['bytes'], 'sha256' => $reference['sha256']); }
    /** @param array<string,string> $tokens */
    private static function assertTokens(string $content, array $tokens): void { if (preg_match_all('/\{\{wordpress-site-plan:asset:([^}]+)\}\}/', $content, $matches)) foreach ($matches[1] as $token) if (!isset($tokens[$token])) throw new InvalidArgumentException('WordPress site plan contains an undeclared reference token.'); }
    /** @param array<string,string> $values */
    private static function unique(array &$values, string $value, string $kind, string $source = ''): void
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

    /**
     * @param array<int,array<string,mixed>> $documents
     * @return list<string>
     */
    private static function markups(array $documents): array
    {
        return array_values(array_filter(array_column($documents, 'canonical_block_markup'), 'is_string'));
    }
    private static function hash(mixed $value): bool { return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value); }
    /** @param array<string,mixed>|mixed $position */
    public static function bindingPosition(mixed $position, \Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex $documentIndex, string $search): bool
    {
        $markup = $documentIndex->markup;
        if (!is_array($position) || 'blocks-engine/runtime-binding-position/v1' !== ($position['schema'] ?? null) || !is_int($position['block_index'] ?? null) || $position['block_index'] < 0 || !is_int($position['offset'] ?? null) || $position['offset'] < 0 || !is_int($position['length'] ?? null) || $position['length'] < 1) return false;
        $range = $documentIndex->ranges()[$position['block_index']] ?? null;
        return null !== $range && $range['offset'] === $position['offset'] && $range['length'] === $position['length'] && $search === substr($markup, $range['offset'], $range['length']);
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
    /** Source-owned native templates share the taxonomy presentation path. */
    private static function archiveTemplateSlug(array $entity): string
    {
        return ('author' === $entity['kind'] ? 'author-' : ('post_tag' === ($entity['taxonomy'] ?? null) ? 'tag-' : 'category-')) . $entity['slug'];
    }

    private static function assertAuthorEntities(array $entities, array $pagesBySource): void
    {
        if (!array_is_list($entities) || count($entities) > 256) throw new InvalidArgumentException('WordPress site plan author entity count or list is invalid.');
        $slugs = array();
        $claimed = array();
        foreach ($entities as $entity) {
            if (!is_array($entity) || 'author' !== ($entity['kind'] ?? null) || !is_string($entity['slug'] ?? null) || !preg_match('/^[a-z0-9][a-z0-9-]{0,49}$/', $entity['slug']) || !is_string($entity['name'] ?? null) || '' === trim($entity['name'])) throw new InvalidArgumentException('WordPress site plan author entity is structurally invalid.');
            self::unique($slugs, $entity['slug'], 'author nicename');
            $archive = $entity['archive'] ?? null;
            if (!is_array($archive) || !self::safePath($archive['source_path'] ?? null) || ($archive['source_route'] ?? null) !== '/author/' . $entity['slug'] || ($archive['query'] ?? null) !== array('post_type' => 'post', 'author' => $entity['slug']) || !self::validArchivePresentation($archive)) throw new InvalidArgumentException('WordPress site plan author archive is structurally invalid.');
            $members = $entity['membership_source_paths'] ?? null;
            if (!is_array($members) || !array_is_list($members) || array() === $members || count($members) > 10000) throw new InvalidArgumentException('WordPress site plan author membership list is invalid.');
            $sorted = $members;
            sort($sorted, SORT_STRING);
            if ($members !== $sorted) throw new InvalidArgumentException('WordPress site plan author membership must be sorted.');
            foreach ($members as $source) {
                if (!is_string($source) || 'post' !== ($pagesBySource[$source]['post_type'] ?? null)) throw new InvalidArgumentException('WordPress site plan author membership must name a canonical post.');
                self::unique($claimed, $source, 'post author membership');
            }
            if (($entity['evidence'] ?? null) !== array('membership' => true, 'name' => true, 'archive' => true)) throw new InvalidArgumentException('WordPress site plan author evidence is invalid.');
        }
    }

    /** Presentation may retain captured static blocks or an inherited archive query. */
    private static function validArchivePresentation(array $archive): bool
    {
        if (!array_key_exists('presentation_markup', $archive)) return true;
        $markup = $archive['presentation_markup'];
        return is_string($markup) && '' !== trim($markup) && strlen($markup) <= 1000000
            && (!str_contains($markup, '<!-- wp:query ') || (
                str_contains($markup, '"inherit":true')
                && str_contains($markup, '<!-- wp:query-pagination ')
                && str_contains($markup, '<!-- wp:query-no-results -->')
            ));
    }

    private static function assertTaxonomyEntities(array $entities, array $pagesBySource): void
    {
        $seen = array();
        $byTerm = array();
        foreach ($entities as $entity) if (is_array($entity) && is_string($entity['taxonomy'] ?? null) && is_string($entity['slug'] ?? null)) $byTerm[$entity['taxonomy'] . ':' . $entity['slug']] = $entity;
        if (count($entities) > 256) throw new InvalidArgumentException('WordPress site plan taxonomy entity count exceeds its bound.');
        foreach ($entities as $entity) {
            if (!is_array($entity) || 'taxonomy_term' !== ($entity['kind'] ?? null) || !in_array($entity['taxonomy'] ?? null, array('category', 'post_tag'), true) || !is_string($entity['slug'] ?? null) || !preg_match('/^[a-z0-9][a-z0-9-]{0,199}$/', $entity['slug']) || !is_string($entity['name'] ?? null) || '' === trim($entity['name'])) throw new InvalidArgumentException('WordPress site plan taxonomy entity is structurally invalid.');
            self::unique($seen, $entity['taxonomy'] . ':' . $entity['slug'], 'taxonomy term');
            $archive = $entity['archive'] ?? null;
            if (array_key_exists('archive', $entity)) {
            if (!is_array($archive) || !self::safePath($archive['source_path'] ?? null) || !is_string($archive['source_route'] ?? null) || !preg_match('~^/[a-z0-9-]+(?:/[a-z0-9-]+)*$~', $archive['source_route']) || !self::validArchivePresentation($archive) || ($archive['query'] ?? null) !== array('post_type' => 'post', 'taxonomy' => $entity['taxonomy'], 'term' => $entity['slug'])) throw new InvalidArgumentException('WordPress site plan taxonomy archive is structurally invalid.');
            if (basename($archive['source_route']) !== $entity['slug']) throw new InvalidArgumentException('WordPress site plan taxonomy slug does not match its archive route.');
            if (array_key_exists('parent', $entity)) {
                $parent = $entity['parent'];
                if ('category' !== $entity['taxonomy'] || !is_string($parent) || !preg_match('/^[a-z0-9][a-z0-9-]{0,199}$/', $parent) || $parent === $entity['slug']) throw new InvalidArgumentException('WordPress site plan taxonomy parent is structurally invalid.');
                $parentEntity = $byTerm[$entity['taxonomy'] . ':' . $parent] ?? null;
                if (!is_array($parentEntity) || ($parentEntity['archive']['source_route'] ?? null) !== dirname($archive['source_route'])) throw new InvalidArgumentException('WordPress site plan taxonomy parent is not a proven immediate ancestor.');
            } elseif ('category' === $entity['taxonomy'] && preg_match('~(?:^|/)category/[^/]+/[^/]+~', $archive['source_route'])) {
                throw new InvalidArgumentException('WordPress site plan hierarchical category lacks its parent.');
            }
            } elseif ('category' !== $entity['taxonomy'] || isset($entity['parent'])) {
                throw new InvalidArgumentException('WordPress site plan section must be an unparented category.');
            }
            $members = $entity['membership_source_paths'] ?? null;
            if (!is_array($members) || !array_is_list($members) || count($members) > 10000) throw new InvalidArgumentException('WordPress site plan taxonomy membership list is structurally invalid.');
            $memberSet = array();
            foreach ($members as $sourcePath) {
                if (!is_string($sourcePath) || !isset($pagesBySource[$sourcePath]) || 'post' !== ($pagesBySource[$sourcePath]['post_type'] ?? null)) throw new InvalidArgumentException('WordPress site plan taxonomy membership does not name a canonical post.');
                self::unique($memberSet, $sourcePath, 'taxonomy membership');
            }
            $evidence = array('membership' => true, 'name' => true);
            if (array_key_exists('archive', $entity)) $evidence['archive'] = true;
            if (($entity['evidence'] ?? null) !== $evidence) throw new InvalidArgumentException('WordPress site plan taxonomy evidence is incomplete.');
        }
    }
    private static function assertRows(array $rows, string $kind, array $fields, array $optional = array()): void { foreach ($rows as $row) { if (!is_array($row)) throw new InvalidArgumentException("WordPress site plan {$kind} must be an array."); foreach ($fields as $field) if (!array_key_exists($field, $row) || (!is_string($row[$field]) && !is_int($row[$field]))) throw new InvalidArgumentException("WordPress site plan {$kind} lacks {$field}."); foreach ($optional as $field) if (array_key_exists($field, $row) && !is_string($row[$field])) throw new InvalidArgumentException("WordPress site plan {$kind} has invalid {$field}."); } }
    /** @param array<string,mixed> $data */
    public static function value(array $data, string $key, string $default = ''): string { return is_string($data[$key] ?? null) ? $data[$key] : $default; }
    private static function explicitUrl(mixed $url): bool { return is_string($url) && (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $url) === 1 || self::routeUrl($url)); }
    private static function routeUrl(mixed $url): bool { return is_string($url) && preg_match('~^/(?:[a-z0-9_-]+(?:/[a-z0-9_-]+)*)?(?:[?#].*)?$~', $url) === 1; }
    public static function safePath(mixed $path): bool { if (!is_string($path) || '' === $path || str_contains($path, "\0") || str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:/', $path)) return false; foreach (explode('/', str_replace('\\', '/', $path)) as $segment) if ('' === $segment || '.' === $segment || '..' === $segment) return false; return true; }
}
