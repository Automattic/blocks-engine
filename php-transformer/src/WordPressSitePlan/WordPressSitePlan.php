<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\Support\StepTimer;
use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityPolicy;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeEntityManifest;
use Automattic\BlocksEngine\PhpTransformer\Path\RouteSlug;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Support\NativeListItemFallbackReconciler;
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
    /** Marks the post-content block of a template that holds a page's hoisted layout frame. */
    public const FRAME_CONTENT_CLASS = 'blocks-engine-frame-content';
    public const LISTING_QUERY_CLASS = 'blocks-engine-listing-query';
    private string $sourceOrigin = '';
    public const MAX_UNRESOLVED_NAVIGATION_DIAGNOSTICS = 50;
    /** @var array<string,array<string,mixed>> Dropped or rewritten units, keyed by their identity fields. */
    private array $unitFindings = array();
    private readonly ShellExtraction $shellExtraction;
    private ListingQueryLoops $listings;
    private PostChrome $postChrome;
    private RouteLinks $links;
    private DocumentHeads $heads;
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
        $this->unitFindings = array();
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
        $compiled['pages'] = array_values(array_filter($compiled['pages'] ?? array(), static fn(mixed $document): bool => !is_array($document) || !DocumentIdentity::isEmptyRedirect($document)));
        $identityFailures = DocumentIdentity::compiledSiteFailures($compiled);
        if ( array() !== $identityFailures ) {
            throw new DocumentIdentityException($identityFailures);
        }

        $runtimeEmoji = (bool) array_filter($compiled['pages'], static fn(array $page): bool => 'wordpress' === ($page['document_metadata']['root_attributes'][CaptureEvidence::RUNTIME_EMOJI] ?? null));
        $runtimeDeclarations = $compiled['runtime_declarations'] ?? array();
        $runtimeRecords = RuntimeDeclarations::normalizeRecords($compiled['runtime_records'] ?? array());
        $runtimeDeclarations = RuntimeDeclarations::materialize($runtimeDeclarations, $runtimeRecords);
        $this->timer?->start('compose.site_plan.documents');
        $runtimeScriptOwnership = DocumentScripts::runtimeOwnership($input->runtimeIslandPackage, $compiled['pages'] ?? array(), $runtimeDeclarations);
        $documents = DocumentScripts::withoutOwned(ContentDecisions::documents($compiled['pages'] ?? null), $runtimeScriptOwnership['documents']);
        $documentScriptAssets = DocumentScripts::assets($documents);
        $assets = array_values(array_filter(
            CompiledAssets::rows($compiled['assets'] ?? null, $this->recordUnitFinding(...)),
            static fn(array $asset): bool => !isset($runtimeScriptOwnership['assets'][(string) ($asset['source_path'] ?? '')]) || isset($documentScriptAssets[(string) ($asset['source_path'] ?? '')])
        ));
        $retainedSources = array_fill_keys(array_column($documents, 'source_path'), true);
        $assets = array_values(array_filter($assets, static fn(array $asset): bool => 'page' !== ($asset['compilation']['scope'] ?? null) || isset($retainedSources[$asset['compilation']['id'] ?? ''])));
        $assets = AssetPublications::apply($assets, $runtimeDeclarations);
        // The design system comes from what every document renders, before
        // any document is projected: RouteLinks::markup() binds literal block values
        // to its tokens wherever block markup enters the plan.
        // Button element styles are decided on the markup before binding,
        // which is what tells a button that renders through them apart from
        // one that never had the value.
        $this->timer?->start('compose.site_plan.design_system');
        $this->designSystem = DesignSystem::fromUsage(DesignSystem::usageBySource($documents))?->withButtonElementsOwnedBy(array_values(array_filter(array_column($documents, 'block_markup'), 'is_string')));
        $this->presetBinding = null === $this->designSystem ? null : new PresetBinding($this->designSystem, $assets, DesignSystem::usageBySource($documents));
        $this->timer?->start('compose.site_plan.routes');
        $tokens = $this->tokens($assets);
        $surfaces = TemplateSurfaces::select($documents);
        $documents = array_values(array_filter($documents, static fn(array $document): bool => !isset($document['template_surface'])));
        $routeTable = new RouteTable();
        $routeMap = $routeTable->canonical($documents, $input->routes);
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
        $this->heads = new DocumentHeads($this->links, $this->recordUnitFinding(...));
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
        $assets = PageStylesheets::instances($assets, $pages);
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
        $bindingTemplates = ThemeTemplates::build($this->links, array(), array(), $surfaces, $tokens, $references, $routeMap);
        $surfaceMarkup = array_column($surfaces, 'block_markup', 'source_path');
        foreach ($bindingTemplates as &$template) if (isset($surfaceMarkup[$template['source_path'] ?? ''])) $template['_projected_source_block_markup'] = $surfaceMarkup[$template['source_path']];
        unset($template);
        $runtimeDeclarations = $entityBindings->canonical($runtimeDeclarations, $runtimeEntityRecords, $references, $routeMap, $pages, $bindingTemplates, $paginationOwners);
        // A form provider rebuilds a form from its fallback's readable blocks.
        foreach ($data['fallbacks'] as &$fallback) {
            $source = is_array($fallback) ? ($fallback['source_path'] ?? $fallback['source'] ?? null) : null;
            if (is_string($source) && is_array($fallback['readable_blocks'] ?? null)) $fallback = EntityMarkup::map($fallback, fn(string $markup): string => $this->links->markup($references->content($markup, $source), $source, $routeMap));
        }
        unset($fallback);
        $pages = $routeTable->hierarchy($pages, $routeMap);
        $templateOwners = array();
        foreach ($pages as $page) if (empty($page['entrypoint'])) $templateOwners[TemplateSlugs::forPage($page)][] = $page['source_path'];
        // Colliding terminal slugs get a readable route-qualified template
        // (`page-north-about`), as an editor would name it; a short route hash
        // disambiguates only when the readable key is itself taken.
        $claimedTemplates = $templateOwners;
        foreach ($pages as &$page) {
            $key = TemplateSlugs::forPage($page);
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
        $assets = StylesheetScopes::assign($assets, StylesheetScopes::documentsBySource($assetPages, $rootDocuments));
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
        $routes = RouteTable::forPages($pages);
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
        $liveElementMarkers = preg_match_all(DeadElementMarkers::PATTERN, (string) json_encode(array($emitted($pages), $emitted($parts), $frameParts, $shellFrames['frames'] ?? array(), $shells['runtime_declarations'] ?? array(), $runtimeEntityRecords)), $liveMatches) ? array_fill_keys($liveMatches[0], true) : array();
        $assets = SharedChromeStylesheets::project($assets, array_merge($parts, $frameParts), $pages, $references, $assetTargetCollisions, $liveElementMarkers);
        $assets = DeadElementMarkers::withoutDeadSelectors($assets, $liveElementMarkers);
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
        $assets = PageStylesheets::place($assets, $pages, array_merge($pages, $parts, $frameParts));
        $assets = EditorStaticStateAssets::share($assets, $references);
        $assets = StylesheetScopes::foldSupport($assets, StylesheetScopes::documentsBySource($pages, $rootDocuments), StylesheetScopes::canonicalDocuments(StylesheetScopes::documentsBySource($assetPages, $rootDocuments)), implode("\n", array_column(array_merge($parts, $frameParts), 'canonical_block_markup')));
        $tokens = $this->tokens($assets);
        if (array() !== $parts) $themeProjection['theme']['templateParts'] = array_values(array_map(static fn(array $part): array => array('name' => $part['slug'], 'title' => $part['title'], 'area' => $part['area']), $parts));
        $runtimeDeclarations = $shells['runtime_declarations'];
        $this->timer?->start('compose.site_plan.templates');
        $runtimeDeclarations = $entityBindings->canonical($runtimeDeclarations, $runtimeEntityRecords, $references, $routeMap, $pages, array_merge($parts, $bindingTemplates), $paginationOwners);
         BindingAnchors::assertAnchored($runtimeDeclarations, $pages, array_merge($parts, $bindingTemplates), $assets);
         $navigation = NavigationEntityProjection::project($pages, $parts, $input->menus, $runtimeDeclarations, $runtimeEntityRecords);
         $pages = $navigation['pages'];
         $parts = $navigation['parts'];
         $menus = $navigation['menus'];
        $pages = $this->postChrome->extractCommentForms($pages);
        $blockThemeChrome = $this->postChrome->extractBlockThemeChrome($pages, $runtimeDeclarations, $runtimeEntityRecords);
        $pages = $blockThemeChrome['pages'];
        $singleContent = array() !== $blockThemeChrome['templates'] ? reset($blockThemeChrome['templates']) : null;
          $pages = $this->listings->materialize($pages, $runtimeDeclarations, $taxonomyProjection['entities'], true);
          $pages = ListingFieldProjection::resolveFeaturedImageTargets($pages, $tokens);
          $assets = ListingQueryPresentation::project($assets, $this->listings->queryContainers());
         // Content every panel of one tabs block repeats is declared once.
         $syncedPatterns = SyncedPatternProjection::project($pages, $assets);
         $pages = $syncedPatterns['pages'];
         $syncedPatterns = $syncedPatterns['patterns'];
         // Query Loop projection can shorten page markup after shell extraction,
         // and synced patterns take shared panel content (and its bindings) out
         // of the page. Rebase retained runtime anchors on the final documents.
         $runtimeDeclarations = $entityBindings->canonical($runtimeDeclarations, $runtimeEntityRecords, $references, $routeMap, $pages, array_merge($parts, $bindingTemplates), $paginationOwners);
         $pages = WholePageCandidates::attach($pages, $runtimeDeclarations, $runtimeEntityRecords);
         foreach ($pages as &$page) unset($page['_projected_source_block_markup'], $page['_synced_pattern_source']); unset($page);
         BindingAnchors::assertAnchored($runtimeDeclarations, $pages, array_merge($parts, $syncedPatterns, $bindingTemplates), $assets);
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
        // Declared terms without a captured archive keep only surviving posts.
        $postSources = array_fill_keys(array_column(array_filter($pages, static fn(array $page): bool => 'post' === ($page['post_type'] ?? null)), 'source_path'), true);
        foreach ($taxonomyProjection['declared_entities'] ?? array() as $entity) {
            $entity['membership_source_paths'] = array_values(array_filter($entity['membership_source_paths'], static fn(string $source): bool => isset($postSources[$source])));
            if (array() !== $entity['membership_source_paths']) $taxonomyProjection['entities'][] = $entity;
        }
        $templates = ThemeTemplates::build($this->links, $pages, $parts, $surfaces, $tokens, $references, $routeMap, $singleContent, $taxonomyProjection['entities'], $shellFrames['frames'], $documentChrome['chrome'], $blockThemeChrome['templates']);
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
        $sharedTemplateTitles = ThemeTemplates::shareOverrides($pages, $parts, $templates);
        $articleChrome = $this->postChrome->extractArticleChrome($pages, $parts, $runtimeDeclarations, $runtimeEntityRecords, $templates);
        $pages = $articleChrome['pages'];
        $templates = $articleChrome['templates'];
        $parts = $articleChrome['parts'];
        // Template-group lifting settles post bodies and can add featured images.
        $pages = $this->listings->materialize($pages, $runtimeDeclarations, $taxonomyProjection['entities'], true);
        $pages = ListingFieldProjection::resolveFeaturedImageTargets($pages, $tokens);
        $registeredTemplates = array();
        foreach ($pages as $page) if (isset($page['route_template']) && !isset($registeredTemplates[$page['route_template']])) {
            $registeredTemplates[$page['route_template']] = true;
            $themeProjection['theme']['customTemplates'][] = array('name' => $page['route_template'], 'title' => $sharedTemplateTitles[$page['route_template']] ?? $page['title'], 'postTypes' => array($page['post_type']));
        }
        foreach ($taxonomyProjection['entities'] as &$taxonomyEntity) foreach ($templates as $template) if (isset($taxonomyEntity['archive']) && TemplateSlugs::forArchive($taxonomyEntity) === ($template['slug'] ?? null)) {
            $taxonomyEntity['archive']['presentation_markup'] = $template['canonical_block_markup'];
            continue 2;
        }
        unset($taxonomyEntity);
        $this->timer?->start('compose.site_plan.asset_writes');
        $routes = RouteTable::forPages($pages);
        $emittedMarkup = implode("\n", self::markups(array_merge($pages, $parts, $templates, $syncedPatterns)));
        $menus = array_values(array_filter($menus, static fn(array $menu): bool => !isset($menu['token']) || str_contains($emittedMarkup, self::NAVIGATION_TOKEN_PREFIX . $menu['token'] . '}}')));
        $operations = PageOperations::plan($pages);
        $assets = CompiledAssets::withVariantScopedIdSelectors($assets);
        // Stylesheet values equal to a token follow it too: links inside
        // text, navigation items and states have no block attribute to bind.
        if (null !== $this->presetBinding) foreach ($assets as &$asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null) || isset($asset['payload_reference'])) continue;
            $content = $this->presetBinding->bindStylesheet($asset['content']);
            if ($content === $asset['content']) continue;
            $asset = CompiledAssets::withRewrittenTextContent($asset, $content);
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
            if ($compacted !== $asset['content']) $asset = CompiledAssets::withRewrittenTextContent($asset, $compacted);
        }
        unset($asset);
        $themeAssetWrites = new ThemeAssetWrites();
        $assetWrites = $themeAssetWrites->writes($assets, $references);
        foreach ($assets as &$asset) unset($asset['reference_origin']); unset($asset);
        $placeholderAssets = CompiledAssets::rows($this->missingMedia->assets(), $this->recordUnitFinding(...));
        if (array() !== $placeholderAssets) {
            $assets = array_merge($assets, $placeholderAssets);
            $tokens = array_merge($tokens, $this->tokens($placeholderAssets));
            $assetWrites = array_merge($assetWrites, $themeAssetWrites->writes($placeholderAssets, $references));
        }
        // Asset bytes are final now. Integrity copied from the source must
        // describe them before script loading and the head bootstrap emit it.
        $integrity = new SubresourceIntegrity($assetWrites, $tokens);
        $pages = $integrity->documents($pages);
        $parts = $integrity->documents($parts);
        $scriptLoading = DocumentScripts::loading($pages, $parts, $assets, $tokens, $operations, $runtimeDeclarations);
        $writes = array_merge(ThemeScaffold::writes($assets, $templates, $parts, $scriptLoading['scripts'], $themeProjection['theme'], $tokens, $pages, $menus, $runtimeEmoji, $rootDocuments), $assetWrites);
        $unitFindings = array_merge($this->unitFindingDiagnostics(), NavigationInstanceDiagnostics::collect($assets, $emittedMarkup . "\n" . implode("\n", self::markups($menus))));
        $recoveryDiagnostics = array_merge($routeTable->diagnostics(), $this->links->diagnostics(), $themeAssetWrites->diagnostics(), $this->heads->diagnostics(), $unitFindings, $this->missingMedia->diagnostics(), $missingFonts->diagnostics(), $missingImports->diagnostics(), $eventDiagnostics, $paginationDiagnostics);
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
            $runtimeEntityRecords = RuntimeEntityManifest::referencedRecords(RuntimeDeclarations::materialize($runtimeDeclarations, $runtimeRecords), $runtimeEntityRecords);
            $pages = WholePageCandidates::attach(array_map(static function (array $page): array { unset($page['whole_page_candidates']); return $page; }, $pages), RuntimeDeclarations::materialize($runtimeDeclarations, $runtimeRecords), $runtimeEntityRecords);
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
            'source' => array('schema' => $compiled['schema'] ?? null, 'source_hash' => $compiled['source_hash'] ?? null, 'entry_path' => $compiled['entry_path'] ?? null, 'provenance' => $data['provenance'], 'source_documents' => TemplateSurfaces::sourceCatalog($compiled['pages'] ?? array())),
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
        $unreferenced = array_merge(AssetConsumers::unreferencedImages($plan) ?? array(), DocumentScripts::unreferencedInline($plan));
        $fontEmbeddingSvgs = AssetConsumers::fontEmbeddingSvgs($plan);
        $unwritten = array();
        foreach ($plan['assets'] as &$asset) {
            $asset['consumers'] = $consumers[$asset['token']] ?? array();
            $asset['destination'] = AssetConsumers::destination($asset['consumers'], $asset, $unreferenced, $fontEmbeddingSvgs);
            $asset['theme_write_reasons'] = AssetConsumers::themeWriteReasons($asset['consumers'], $asset['destination']);
            if ('none' === $asset['destination']) $unwritten[$asset['target_path']] = true;
        }
        unset($asset);
        // An image nothing references is not materialized; its row and token
        // remain so the plan still reports what the source carried.
        if (array() !== $unwritten) $plan['writes'] = array_values(array_filter($plan['writes'], static fn(array $write): bool => !isset($unwritten[$write['target_path']])));
        $plan = ContentConservation::check($plan, $compiled['pages']);
        $plan['plan_identity'] = self::planIdentity($plan);
        self::assertValid($plan);
        return $plan;
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
        $sourceCatalog = TemplateSurfaces::planSourceCatalog($plan['source']);
        RuntimeDeclarations::assertNormalized($plan['runtime_declarations'], !isset($plan['resolution']));
        $records = RuntimeEntityManifest::normalizeRecords($plan['runtime_entity_records']);
        if ($records !== $plan['runtime_entity_records']) throw new InvalidArgumentException('WordPress site plan runtime entity records are not canonically normalized.');
        foreach ($plan['runtime_declarations'] as $declaration) if (RuntimeEntityManifest::SCHEMA === ($declaration['payload']['schema'] ?? null)) RuntimeEntityManifest::resolve($declaration['payload'], $records);
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
        $entryRoot = self::entryRootFromDocuments($plan['pages']);
        foreach ( $plan['pages'] as $page ) {
            PlanDocuments::assertValid($page, 'page', false, $tokens);
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
        $pageTemplateKeys = array_fill_keys(array_map(TemplateSlugs::forPage(...), $plan['pages']), true);
        foreach ($plan['pages'] as $page) if (!empty($page['synthetic']) && !isset($pageParents[$page['source_path']])) throw new InvalidArgumentException('WordPress site plan synthetic parent must have a materialized child.');
        $archiveDocuments = array();
        foreach (array_merge($plan['taxonomy_entities'], $plan['author_entities']) as $entity) {
            if (!array_key_exists('archive', $entity)) continue;
            $source = $entity['archive']['source_path'] ?? '';
            $route = $entity['archive']['source_route'] ?? '';
            if (!isset($sourceCatalog[$source]) || isset($pagesBySource[$source])) throw new InvalidArgumentException('WordPress site plan native archive must name a captured source without a materialized page.');
            $archiveDocuments[$source] = array('source_path' => $source, 'post_type' => 'page', 'route' => array('path' => $route), 'reconciliation_identity' => self::identity('page', $source, $route));
        }
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
        self::assertReporting($plan['reporting'], array_merge($pagePaths, array_change_key_case(array_fill_keys(array_filter(array_column($plan['templates'], 'source_path'), 'is_string'), true), CASE_LOWER)), $tokens, $plan['diagnostics']);
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
        foreach (array_merge($plan['taxonomy_entities'], $plan['author_entities']) as $entity) {
            if (!isset($entity['archive'])) continue;
            $templateSlug = TemplateSlugs::forArchive($entity);
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

    /** @param mixed $documents @param array<int,array<string,string>> $tokens @return array<int,array<string,mixed>> */
    private function documents(mixed $documents, bool $part, array $tokens, AssetReferenceCanonicalizer $references, array $routes): array
    {
        if ( ! is_array($documents) ) {
            throw new InvalidArgumentException('Compiled site documents must be an array.');
        }
        $failures = DocumentIdentity::failures($documents, $part ? 'template_part' : 'page');
        if ( array() !== $failures ) {
            throw new DocumentIdentityException($failures);
        }
        $rows = array();
        foreach ( $documents as $document ) {
            $markup = $references->content($document['block_markup'], $document['source_path']);
            $canonical = $this->links->markup($markup, $document['source_path'], $routes);
            $target = $part ? 'parts/' . self::value($document, 'slug') . '.html' : self::value($document, 'source_path');
            $area = $part ? self::value($document, 'area', 'uncategorized') : null;
            $row = array('source_path' => $document['source_path'], 'slug' => self::value($document, 'slug'), 'title' => self::value($document, 'title'), 'post_type' => self::value((array) ($document['metadata'] ?? array()), 'post_type', 'page'), 'parent_source_path' => self::value((array) ($document['metadata'] ?? array()), 'parent_source_path'), 'entrypoint' => ! empty($document['entrypoint']), 'area' => $area, 'tag_name' => $part ? self::value($document, 'tag_name', ShellLandmarkPolicy::templatePartAreaTagName((string) $area)) : null, 'placement' => $part && is_array($document['placement'] ?? null) ? $document['placement'] : ($part ? array('kind' => 'unbound') : null), 'canonical_block_markup' => $canonical, '_projected_source_block_markup' => $document['block_markup'], 'metadata' => ArrayField::array($document, 'metadata'), 'document_metadata' => $this->heads->metadata($document, $references, $routes), 'provenance' => ArrayField::array($document, 'provenance'), 'reconciliation_identity' => self::identity($part ? 'template-part' : 'page', $document['source_path'], $target), 'content_hash' => self::contentHash($canonical));
            if (!$part && is_array($document['content_decision'] ?? null)) {
                $row['content_decision'] = $document['content_decision'];
                if (is_string($document['publication_timestamp'] ?? null)) $row['publication_timestamp'] = $document['publication_timestamp'];
                if (is_string($document['publication_local_time'] ?? null)) $row['publication_local_time'] = $document['publication_local_time'];
                if (is_string($document['modified_timestamp'] ?? null)) $row['modified_timestamp'] = $document['modified_timestamp'];
                if (is_string($document['author_name'] ?? null)) $row['author_name'] = $document['author_name'];
            }
            if ( ! $part ) $row['shell_candidates'] = $this->shellExtraction->shellCandidates($document, $references, $routes, $canonical);
            $rows[] = $row;
        }
        return $rows;
    }

    /** @param array<int,array<string,mixed>> $assets @return array<int,array<string,string>> */
    private function tokens(array $assets): array { return array_map(static fn(array $asset): array => array('token' => $asset['token'], 'source_path' => $asset['source_path'], 'target_path' => $asset['target_path']), $assets); }

    /**
     * Clips one diagnostic field to its budget without splitting a UTF-8
     * sequence. A byte-wise cut can leave an invalid string, and planIdentity()
     * json_encode()s every diagnostic under JSON_THROW_ON_ERROR, so a split
     * sequence would discard the whole plan -- the failure this omission exists
     * to avoid, one layer up.
     */
    public static function clipDiagnosticField(string $value): string { return mb_strcut($value, 0, 256, 'UTF-8'); }
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

    /** @param array<string,mixed> $compiled @param array<string,mixed> $data @param array<string,mixed> $coreHtmlFallbackEvidence @return array<string,mixed> */
    private function reporting(array $pages, array $data, array $coreHtmlFallbackEvidence, array $scriptDiagnostics = array(), array $surfaces = array()): array { $documents = array(); foreach ($pages as $page) if (is_array($page)) $documents[] = array('source_path' => $page['source_path'] ?? '', 'kind' => 'page', 'body_format' => 'blocks', 'block_document' => true, 'provenance' => $page['provenance'] ?? array()); foreach ($surfaces as $surface) $documents[] = array('source_path' => $surface['source_path'] ?? '', 'kind' => 'template_surface', 'body_format' => 'blocks', 'block_document' => true, 'template_surface' => $surface['template_surface'] ?? array(), 'provenance' => $surface['provenance'] ?? array()); return array('source_documents' => $documents, 'metrics' => array('source_document_count' => count($documents), 'block_document_count' => count($documents), 'native_block_count' => $data['metrics']['block_count'] ?? 0, 'fallback_count' => $data['metrics']['fallback_count'] ?? 0), 'core_html_fallback_evidence' => $coreHtmlFallbackEvidence, 'diagnostic_codes' => array_values(array_map(static fn(array $diagnostic): string => (string) ($diagnostic['code'] ?? ''), array_merge($data['diagnostics'], $scriptDiagnostics)))); }

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
    /** @internal Shared artifact-root semantics for document-head asset resolution. */
    public static function entryRootFromDocuments(array $documents): string { foreach ($documents as $document) { if (is_array($document) && (!empty($document['entrypoint']) || 'entrypoint' === ($document['source_relation'] ?? null)) && is_string($document['source_path'] ?? null)) { $dir = str_replace('\\', '/', dirname($document['source_path'])); return in_array($dir, array('.', '/', ''), true) ? '' : $dir; } } return ''; }


    /** @return array<string,mixed> */
    public static function write(string $kind, string $target, string $content, ?string $sourcePath = null, string $encoding = 'utf8'): array { $sourcePath ??= 'wordpress-site-plan/' . $target; return array('kind' => $kind, 'source_path' => $sourcePath, 'target_path' => $target, 'reconciliation_identity' => self::identity('write', $sourcePath, $target), 'payload_hash' => self::contentHash($content), 'payload' => array('encoding' => $encoding, 'data' => $content)); }
    /** @param array{schema:string,id:string,bytes:int,sha256:string} $reference */
    public static function referenceWrite(string $kind, string $target, string $sourcePath, array $reference): array { return array('kind' => $kind, 'source_path' => $sourcePath, 'target_path' => $target, 'reconciliation_identity' => self::identity('write', $sourcePath, $target), 'payload_hash' => self::contentHash(RuntimeDeclarations::canonicalJson($reference)), 'raw_sha256' => $reference['sha256'], 'payload' => array('encoding' => 'reference', 'reference' => $reference)); }

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

    private static function assertRoute(array $page, string $entryRoot = ''): void { $route = $page['route'] ?? null; $expected = is_string($page['metadata']['route_path'] ?? null) && '' !== $page['metadata']['route_path'] ? PageRoute::canonical($page['metadata']['route_path']) : PageRoute::fromSource($page['source_path'], $entryRoot); if (!is_array($route) || !is_string($route['path'] ?? null) || !preg_match('~^/(?:[a-z0-9_-]+(?:/[a-z0-9_-]+)*)?$~', $route['path']) || !is_string($route['parent_path'] ?? null) || !is_string($route['slug'] ?? null) || PageRoute::parent($route['path']) !== $route['parent_path'] || PageRoute::slug($route['path']) !== $route['slug'] || (!isset($page['synthetic']) && !PageRoute::isDerived($route['path'], $expected)) || (isset($page['synthetic']) && (true !== $page['synthetic'] || !str_starts_with((string) ($page['source_path'] ?? ''), 'wordpress-site-plan/routes/')))) throw new InvalidArgumentException('WordPress site plan page route is invalid.'); }
    /** @param array<string,mixed> $reporting @param array<string,bool> $pagePaths @param array<string,bool> $tokens */
    private static function assertReporting(array $reporting, array $sourcePaths, array $tokens, array $diagnostics): void { if(!is_array($reporting['source_documents']??null)||!is_array($reporting['metrics']??null)||!is_array($reporting['core_html_fallback_evidence']??null)||!is_array($reporting['diagnostic_codes']??null))throw new InvalidArgumentException('WordPress site plan reporting summary is invalid.');$sources=array();foreach($reporting['source_documents'] as $document){if(!is_array($document)||!self::safePath($document['source_path']??null)||!is_string($document['kind']??null)||!is_string($document['body_format']??null)||!is_bool($document['block_document']??null)||!is_array($document['provenance']??null))throw new InvalidArgumentException('WordPress site plan source document summary is invalid.');self::unique($sources,$document['source_path'],'source document');}if(count($sources)!==count($sourcePaths)||array_keys($sources)!==array_keys($sourcePaths))throw new InvalidArgumentException('WordPress site plan source document summaries do not match materialized documents.');foreach(array('source_document_count','block_document_count','native_block_count','fallback_count') as $key)if(!is_int($reporting['metrics'][$key]??null))throw new InvalidArgumentException('WordPress site plan reporting metric is invalid.');$linked=array_fill_keys($reporting['diagnostic_codes'],true);foreach($reporting['diagnostic_codes'] as $code)if(!is_string($code)||''===$code)throw new InvalidArgumentException('WordPress site plan diagnostic linkage is invalid.');foreach($diagnostics as $diagnostic)if(is_array($diagnostic)&&is_string($diagnostic['code']??null)&&!isset($linked[$diagnostic['code']]))throw new InvalidArgumentException('WordPress site plan diagnostics are not linked to reporting.');}
    /** @param array<string,string> $tokens */
    private static function assertWrite(mixed $write, array $tokens, bool $browserReferences): void { if (!is_array($write) || !is_string($write['kind'] ?? null) || !self::safePath($write['source_path'] ?? null) || !self::safePath($write['target_path'] ?? null) || !self::hash($write['reconciliation_identity'] ?? null) || !self::hash($write['payload_hash'] ?? null) || !is_array($write['payload'] ?? null) || !in_array($write['payload']['encoding'] ?? null, array('utf8','base64','reference'), true) || $write['reconciliation_identity'] !== self::identity('write', $write['source_path'], $write['target_path'])) throw new InvalidArgumentException('WordPress site plan write has a stale payload hash or invalid structure.'); if ('reference' === $write['payload']['encoding']) { $reference = CompiledAssets::payloadReference($write['payload']['reference'] ?? null); if (!is_array($reference) || ($write['raw_sha256'] ?? null) !== $reference['sha256'] || $write['payload_hash'] !== self::contentHash(RuntimeDeclarations::canonicalJson($reference))) throw new InvalidArgumentException('WordPress site plan write has an invalid payload reference.'); return; } if (!is_string($write['payload']['data'] ?? null) || $write['payload_hash'] !== self::contentHash($write['payload']['data'])) throw new InvalidArgumentException('WordPress site plan write has a stale payload hash or invalid structure.'); if ('base64' === $write['payload']['encoding'] && false === base64_decode($write['payload']['data'], true)) throw new InvalidArgumentException('WordPress site plan write has invalid base64 payload.'); if ('utf8' === $write['payload']['encoding']) { self::assertTokens($write['payload']['data'], $tokens); if ($browserReferences && !LocalBrowserReferences::isVerbatimWrite($write['kind'], $write['target_path'])) LocalBrowserReferences::assertNoneInWrite($write['target_path'], $write['payload']['data'], $write['source_path']); } }
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

    /**
     * @param array<int,array<string,mixed>> $documents
     * @return list<string>
     */
    private static function markups(array $documents): array
    {
        return array_values(array_filter(array_column($documents, 'canonical_block_markup'), 'is_string'));
    }
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
