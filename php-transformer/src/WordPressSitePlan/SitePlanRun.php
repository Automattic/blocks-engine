<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\Support\StepTimer;
use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;
use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityPolicy;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeEntityManifest;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Support\NativeListItemFallbackReconciler;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use InvalidArgumentException;

/**
 * One site-plan composition. WordPressSitePlan builds a run per call, so the
 * collaborators it composes with (route links, heads, findings, design system,
 * media recovery, shell extraction) belong to that one run.
 */
final class SitePlanRun
{
    private readonly string $sourceOrigin;
    private readonly UnitFindings $unitFindings;
    private readonly ShellExtraction $shellExtraction;
    private readonly ListingQueryLoops $listings;
    private readonly PostChrome $postChrome;
    private readonly RouteLinks $links;
    private readonly DocumentHeads $heads;
    private readonly ?DesignSystem $designSystem;
    private readonly ?PresetBinding $presetBinding;
    private readonly MissingMediaRecovery $missingMedia;
    private readonly array $compiled;
    private readonly array $editabilityPolicy;
    private readonly bool $runtimeEmoji;
    private readonly array $surfaces;
    private readonly RouteTable $routeTable;
    private readonly array $routeMap;
    private readonly string $entryRoot;
    private readonly EntityBindings $entityBindings;
    private readonly MissingFontSourceRecovery $missingFonts;
    private readonly MissingStylesheetImportRecovery $missingImports;
    private readonly AssetReferenceCanonicalizer $references;
    /** Values the setup produced, which later stages carry forward and rewrite. */
    private readonly array $initialDocuments;
    private readonly array $initialAssets;
    private readonly array $initialTokens;
    private readonly array $initialRuntimeDeclarations;
    private readonly array $initialRuntimeRecords;
    private readonly array $initialTaxonomyProjection;

    /**
     * Post-chrome matching normalizes every nested range of every post, and
     * the same ranges are matched again while factoring and placing slots.
     * Keyed by content hash, each distinct range is normalized once per plan.
     * Only membership is ever compared, so the identity is kept as a hash.
     *
     * @var array<string,string>
     */
    private array $chromeIdentityKeys = array();

    /**
     * Admits the compiled site and builds the context every later stage reads:
     * the route map and links, the design system, the reference canonicalizer
     * and the recoveries behind it. Stages read this context and never change it.
     *
     * @param array<string,mixed> $data
     */
    public function __construct(private readonly array $data, private readonly WordPressSitePlanInput $input, private readonly bool $strictMissingMedia, private readonly ?StepTimer $timer)
    {
        $sourceUrl = RouteLinks::sourceUrl($data['provenance'] ?? array());
        $this->sourceOrigin = RouteLinks::origin($sourceUrl);
        $this->listings = new ListingQueryLoops($this->sourceOrigin, $this->chromeIdentityKey(...));
        $this->unitFindings = new UnitFindings();
        $this->postChrome = new PostChrome($this->listings, $this->chromeIdentityKey(...), $this->unitFindings->record(...));
        $this->editabilityPolicy = $input->editabilityPolicy;
        if (!is_array($this->editabilityPolicy) || EditabilityPolicy::SCHEMA !== ($this->editabilityPolicy['schema'] ?? null) || 'required' !== ($this->editabilityPolicy['enforcement'] ?? null) || !in_array($this->editabilityPolicy['status'] ?? null, array('passed', 'failed'), true)) {
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

        $this->runtimeEmoji = (bool) array_filter($compiled['pages'], static fn(array $page): bool => 'wordpress' === ($page['document_metadata']['root_attributes'][CaptureEvidence::RUNTIME_EMOJI] ?? null));
        $runtimeDeclarations = $compiled['runtime_declarations'] ?? array();
        $runtimeRecords = RuntimeDeclarations::normalizeRecords($compiled['runtime_records'] ?? array());
        $runtimeDeclarations = RuntimeDeclarations::materialize($runtimeDeclarations, $runtimeRecords);
        $this->timer?->start('compose.site_plan.documents');
        $runtimeScriptOwnership = DocumentScripts::runtimeOwnership($input->runtimeIslandPackage, $compiled['pages'] ?? array(), $runtimeDeclarations);
        $documents = DocumentScripts::withoutOwned(ContentDecisions::documents($compiled['pages'] ?? null), $runtimeScriptOwnership['documents']);
        $documentScriptAssets = DocumentScripts::assets($documents);
        $assets = array_values(array_filter(
            CompiledAssets::rows($compiled['assets'] ?? null, $this->unitFindings->record(...)),
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
        $this->surfaces = TemplateSurfaces::select($documents);
        $documents = array_values(array_filter($documents, static fn(array $document): bool => !isset($document['template_surface'])));
        $this->routeTable = new RouteTable();
        $this->routeMap = $this->routeTable->canonical($documents, $input->routes);
        // Both native archive kinds use the same listing, template and resource pipeline.
        $taxonomyProjection = TaxonomyProjection::project($documents, $this->routeMap, $this->sourceOrigin);
        $authorProjection = AuthorProjection::project($documents, $this->routeMap, $this->sourceOrigin);
        foreach (array('entities', 'diagnostics') as $key) $taxonomyProjection[$key] = array_merge($taxonomyProjection[$key], $authorProjection[$key]);
        $taxonomyProjection['pagination_source_paths_by_archive'] += $authorProjection['pagination_source_paths_by_archive'];
        $routeSources = array();
        $routeTargets = array();
        foreach ($this->routeMap as $route) {
            $sourcePath = (string) ($route['source_path'] ?? '');
            $targetPath = (string) ($route['target_path'] ?? '');
            if ('' !== $sourcePath && '' !== $targetPath) $routeSources[$sourcePath] = $targetPath;
            if ('' !== $targetPath) $routeTargets['/' === $targetPath ? '/' : '/' . trim($targetPath, '/')] = $targetPath;
        }
        // Native date archives are document roots, not materialized pages, but
        // their captured file paths still participate in link resolution.
        $this->entryRoot = PageRoute::entryRoot($compiled['pages']);
        foreach ($compiled['pages'] as $document) {
            if (!ContentDecisions::isDateArchive($document)) continue;
            $path = $document['metadata']['route_path'] ?? PageRoute::fromSource($document['source_path'], $this->entryRoot);
            $target = '/' . trim($path, '/') . '/';
            $routeSources[$document['source_path']] = $target;
            $routeTargets['/' . trim($path, '/')] = $target;
        }
        $this->timer?->start('compose.site_plan.pages');
        $this->links = new RouteLinks($sourceUrl, $this->sourceOrigin, $routeSources, $routeTargets, $this->presetBinding, $documents, $this->routeMap);
        $this->shellExtraction = new ShellExtraction($this->links);
        $this->heads = new DocumentHeads($this->links, $this->unitFindings->record(...));
        $this->entityBindings = new EntityBindings($this->links, $this->unitFindings->record(...));
        $this->missingMedia = new MissingMediaRecovery($this->strictMissingMedia, array_column($assets, 'target_path'));
        $this->missingFonts = new MissingFontSourceRecovery($this->strictMissingMedia);
        $this->missingImports = new MissingStylesheetImportRecovery($this->strictMissingMedia);
        $this->references = new AssetReferenceCanonicalizer($tokens, PageRoute::entryRoot($documents), $this->missingMedia, $this->missingFonts, $this->missingImports);
        $this->compiled = $compiled;
        $this->initialDocuments = $documents;
        $this->initialAssets = $assets;
        $this->initialTokens = $tokens;
        $this->initialRuntimeDeclarations = $runtimeDeclarations;
        $this->initialRuntimeRecords = $runtimeRecords;
        $this->initialTaxonomyProjection = $taxonomyProjection;
    }

    /**
     * Runs the plan stages in order. Each stage is named by its timer:
     *
     * - pages (begun by the constructor): routes the pages, selects
     *   pagination captures, adds event declarations and instances page
     *   stylesheets.
     * - entity_bindings: anchors runtime declarations on the final page
     *   markup before shell extraction, settles the route hierarchy, and
     *   pairs root documents with the asset pages.
     * - theme_json: scopes stylesheets, projects theme.json and binds
     *   presets on the pages.
     * - shell_extraction: extractedShells() moves shared chrome out of the
     *   pages into template parts and frames.
     * - stylesheets: projects shared chrome stylesheets, drops selectors
     *   no emitted markup matches, then places and folds them.
     * - templates: projects navigation, post chrome, listings and synced
     *   patterns, then builds the theme templates.
     * - asset_writes: plans page operations, the final stylesheets, asset
     *   writes, document integrity and script loading.
     * - plan_assembly: factors runtime declarations and assembles the plan.
     * - asset_consumers: assigns asset consumers and checks content
     *   conservation.
     *
     * A step belongs in the stage whose values it reads and rewrites.
     * EntityBindings::canonical() runs again after each pass that rewrites
     * page markup, so runtime anchors follow the final documents.
     *
     * @return array<string,mixed>
     */
    public function plan(): array
    {
        $data = $this->data;
        $documents = $this->initialDocuments;
        $assets = $this->initialAssets;
        $tokens = $this->initialTokens;
        $runtimeDeclarations = $this->initialRuntimeDeclarations;
        $runtimeRecords = $this->initialRuntimeRecords;
        $taxonomyProjection = $this->initialTaxonomyProjection;
        $pages = $this->documents($documents, false, $tokens, $this->references, $this->routeMap);
        // Probe presentation with the final plan's canonical listing projection.
        // Entity ownership is already proven independently and must survive a
        // declined presentation, including ownership of numbered captures.
        $routeBySource = array_column($this->routeMap, 'target_path', 'source_path');
        $pages = self::routedPages($pages, $routeBySource);
        $preflightPages = (new ListingQueryLoops($this->sourceOrigin, $this->chromeIdentityKey(...)))->materialize($pages, $runtimeDeclarations, $taxonomyProjection['entities']);
        $paginationOwnerBySource = TaxonomyProjection::paginationOwners($taxonomyProjection);
        $taxonomyProjection = TaxonomyProjection::withPreflightPresentation($taxonomyProjection, $preflightPages);
        $assets = PaginationCaptures::withOwnedAssets($assets, $paginationOwnerBySource);
        // Taxonomy membership must see numbered captures before they are
        // suppressed. They are views of a listing, never standalone content.
        $pagination = PaginationCaptures::select($pages, $routeBySource, $this->surfaces, $paginationOwnerBySource);
        $paginationSources = $pagination['sources'];
        $paginationDiagnostics = $pagination['diagnostics'];
        $paginationOwners = $pagination['owners'];
        $pages = array_values(array_filter($pages, static fn(array $page): bool => !isset($paginationSources[$page['source_path']])));
        $documents = array_values(array_filter($documents, static fn(array $document): bool => !isset($paginationSources[$document['source_path']])));
        $assets = array_values(array_filter($assets, static fn(array $asset): bool => 'page' !== ($asset['compilation']['scope'] ?? null) || !isset($paginationSources[$asset['compilation']['id'] ?? ''])));
        $runtimeEntityRecords = $this->compiled['runtime_entity_records'] ?? array();
        $eventDiagnostics = array();
        $runtimeDeclarations = EventDeclarations::add($documents, $this->routeMap, $runtimeDeclarations, $runtimeEntityRecords, $eventDiagnostics);
        $assets = PageStylesheets::instances($assets, $pages);
        NativeListItemFallbackReconciler::reconcileBlockDocuments(
            $data['fallbacks'],
            array_values(array_filter(array_column($pages, 'canonical_block_markup'), 'is_string'))
        );
        $data['metrics']['fallback_count'] = count(array_filter(
            $data['fallbacks'],
            static fn (mixed $fallback): bool => is_array($fallback) && 'native_conversion' !== ($fallback['conversion_classification'] ?? '')
        ));
        $pages = $this->shellExtraction->withRestoredCandidates($pages);
        // Bindings anchor on the final canonical page markup before any shell
        // extraction. Asset and route projection can make source anchors equal,
        // so assign occurrences only after that shared projection is complete.
        $this->timer?->start('compose.site_plan.entity_bindings');
        $bindingTemplates = $this->bindingTemplates($tokens);
        $runtimeDeclarations = $this->entityBindings->canonical($runtimeDeclarations, $runtimeEntityRecords, $this->references, $this->routeMap, $pages, $bindingTemplates, $paginationOwners);
        // A form provider rebuilds a form from its fallback's readable blocks.
        foreach ($data['fallbacks'] as &$fallback) {
            $source = is_array($fallback) ? ($fallback['source_path'] ?? $fallback['source'] ?? null) : null;
            if (is_string($source) && is_array($fallback['readable_blocks'] ?? null)) $fallback = EntityMarkup::map($fallback, fn(string $markup): string => $this->links->markup($this->references->content($markup, $source), $source, $this->routeMap));
        }
        unset($fallback);
        $pages = $this->routeTable->hierarchy($pages, $this->routeMap);
        $pages = TemplateSlugs::qualifyColliding($pages);
        $assets = StylesheetScopes::withSharedSurfaces($assets, $this->surfaces);
        ['asset_pages' => $assetPages, 'root_documents' => $rootDocuments] = $this->rootDocuments($pages, $taxonomyProjection, $routeBySource, $paginationOwners, $paginationSources);
        $this->timer?->start('compose.site_plan.theme_json');
        $assets = StylesheetScopes::assign($assets, StylesheetScopes::documentsBySource($assetPages, $rootDocuments));
        $projector = new ThemeJsonProjection();
        $themeProjection = $projector->project($assets, array_column($pages, 'source_path'), $this->designSystem);
        $assets = $themeProjection['assets'];
        $this->presetBinding?->useThemeDefaults($themeProjection['theme']);
        $pages = $this->presetBoundPages($pages, $documents);
        $routes = RouteTable::forPages($pages);
        // Entry shells remain in compiled-site/v1 for existing consumers; the
        // canonical plan rebuilds them from full page shell candidates.
        $compiledParts = is_array($this->compiled['template_parts'] ?? null) ? array_values(array_filter($this->compiled['template_parts'], static fn(mixed $part): bool => !is_array($part) || 'entry_shell' !== ($part['placement']['kind'] ?? null))) : null;
        $this->timer?->start('compose.site_plan.shell_extraction');
        ['pages' => $pages, 'parts' => $parts, 'frame_parts' => $frameParts, 'shell_frames' => $shellFrames, 'document_chrome' => $documentChrome, 'runtime_declarations' => $shellDeclarations, 'runtime_entity_records' => $runtimeEntityRecords, 'diagnostics' => $shellDiagnostics] = $this->extractedShells($pages, $compiledParts, $tokens, $runtimeDeclarations, $runtimeEntityRecords, $assets);
        $assetTargetCollisions = array();
        // Element markers some emitted markup still carries. A rule naming
        // any other marker names an element extraction replaced (one route's
        // copy of the chrome), which matches nothing anywhere.
        // Underscore keys are working state the plan does not emit, such as a
        // route's projected source markup from before its chrome was extracted.
        $emitted = static fn (array $rows): array => array_map(static fn (mixed $row): mixed => is_array($row) ? array_filter($row, static fn (int|string $key): bool => !is_string($key) || !str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY) : $row, $rows);
        $this->timer?->start('compose.site_plan.stylesheets');
        $liveElementMarkers = preg_match_all(DeadElementMarkers::PATTERN, (string) json_encode(array($emitted($pages), $emitted($parts), $frameParts, $shellFrames['frames'] ?? array(), $shellDeclarations ?? array(), $runtimeEntityRecords)), $liveMatches) ? array_fill_keys($liveMatches[0], true) : array();
        $assets = SharedChromeStylesheets::project($assets, array_merge($parts, $frameParts), $pages, $this->references, $assetTargetCollisions, $liveElementMarkers);
        $assets = DeadElementMarkers::withoutDeadSelectors($assets, $liveElementMarkers);
        // Removing dead selectors can consume a generated sheet's last rule.
        $assets = array_values(array_filter($assets, static fn(array $asset): bool => !in_array($asset['source'] ?? '', array('engine-support', 'editor-static-state', 'wordpress-compat'), true) || !is_string($asset['content'] ?? null) || '' !== trim($asset['content'])));
        foreach ($assetTargetCollisions as $collision) $this->unitFindings->record(array(
            'code' => 'wordpress_site_plan_colliding_asset_target',
            'unit' => 'asset',
            'message' => 'Two assets resolved to one target with different content; the later one was given a suffixed target.',
            'source_path' => $collision['source_path'],
            'reason_code' => 'colliding_asset_target',
            'colliding_source_path' => $collision['colliding_source_path'],
            'target_path' => $collision['target_path'],
            'resolved_target_path' => $collision['resolved_target_path'],
        ));
        $assets = PageStylesheets::withFrameFallbacks($assets, $shellFrames, $parts);
        $assets = SharedChromeStylesheets::projectDetachedPaintOrder($assets, $parts, $pages);
        $assets = PageStylesheets::place($assets, $pages, array_merge($pages, $parts, $frameParts));
        $assets = EditorStaticStateAssets::share($assets, $this->references);
        $assets = StylesheetScopes::foldSupport($assets, StylesheetScopes::documentsBySource($pages, $rootDocuments), StylesheetScopes::canonicalDocuments(StylesheetScopes::documentsBySource($assetPages, $rootDocuments)), implode("\n", array_column(array_merge($parts, $frameParts), 'canonical_block_markup')));
        $tokens = $this->tokens($assets);
        if (array() !== $parts) $themeProjection['theme']['templateParts'] = array_values(array_map(static fn(array $part): array => array('name' => $part['slug'], 'title' => $part['title'], 'area' => $part['area']), $parts));
        $runtimeDeclarations = $shellDeclarations;
        $this->timer?->start('compose.site_plan.templates');
        $runtimeDeclarations = $this->entityBindings->canonical($runtimeDeclarations, $runtimeEntityRecords, $this->references, $this->routeMap, $pages, array_merge($parts, $bindingTemplates), $paginationOwners);
         BindingAnchors::assertAnchored($runtimeDeclarations, $pages, array_merge($parts, $bindingTemplates), $assets);
         $navigation = NavigationEntityProjection::project($pages, $parts, $this->input->menus, $runtimeDeclarations, $runtimeEntityRecords);
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
         $runtimeDeclarations = $this->entityBindings->canonical($runtimeDeclarations, $runtimeEntityRecords, $this->references, $this->routeMap, $pages, array_merge($parts, $bindingTemplates), $paginationOwners);
         $pages = WholePageCandidates::attach($pages, $runtimeDeclarations, $runtimeEntityRecords);
         foreach ($pages as &$page) unset($page['_projected_source_block_markup'], $page['_synced_pattern_source']); unset($page);
         BindingAnchors::assertAnchored($runtimeDeclarations, $pages, array_merge($parts, $syncedPatterns, $bindingTemplates), $assets);
        $taxonomyProjection = TaxonomyProjection::withSettledPresentation($taxonomyProjection, $pages);
        ['pages' => $pages, 'assets' => $assets, 'taxonomy' => $taxonomyProjection, 'archive_sources' => $archiveSources] = $this->materializedPages($pages, $assets, $taxonomyProjection);
        $templates = ThemeTemplates::build($this->links, $pages, $parts, $this->surfaces, $tokens, $this->references, $this->routeMap, $singleContent, $taxonomyProjection['entities'], $shellFrames['frames'], $documentChrome, $blockThemeChrome['templates']);
        $templates = ThemeTemplates::withBlockThemeShellFrames($templates, $blockThemeChrome);
        $sharedTemplateTitles = ThemeTemplates::shareOverrides($pages, $parts, $templates);
        $articleChrome = $this->postChrome->extractArticleChrome($pages, $parts, $runtimeDeclarations, $runtimeEntityRecords, $templates);
        $pages = $articleChrome['pages'];
        $templates = $articleChrome['templates'];
        $parts = $articleChrome['parts'];
        // Template-group lifting settles post bodies and can add featured images.
        $pages = $this->listings->materialize($pages, $runtimeDeclarations, $taxonomyProjection['entities'], true);
        $pages = ListingFieldProjection::resolveFeaturedImageTargets($pages, $tokens);
        $themeProjection = ThemeTemplates::withCustomTemplates($themeProjection, $pages, $sharedTemplateTitles);
        $taxonomyProjection = TaxonomyProjection::withArchivePresentation($taxonomyProjection, $templates);
        $this->timer?->start('compose.site_plan.asset_writes');
        $routes = RouteTable::forPages($pages);
        $emittedMarkup = implode("\n", self::markups(array_merge($pages, $parts, $templates, $syncedPatterns)));
        $menus = array_values(array_filter($menus, static fn(array $menu): bool => !isset($menu['token']) || str_contains($emittedMarkup, WordPressSitePlan::NAVIGATION_TOKEN_PREFIX . $menu['token'] . '}}')));
        $operations = PageOperations::plan($pages);
        $navigationMarkup = $emittedMarkup . "\n" . implode("\n", self::markups($menus));
        $assets = $this->finalStylesheets($assets, $navigationMarkup);
        $themeAssetWrites = new ThemeAssetWrites();
        $assetWrites = $themeAssetWrites->writes($assets, $this->references);
        foreach ($assets as &$asset) unset($asset['reference_origin']); unset($asset);
        $placeholderAssets = CompiledAssets::rows($this->missingMedia->assets(), $this->unitFindings->record(...));
        if (array() !== $placeholderAssets) {
            $assets = array_merge($assets, $placeholderAssets);
            $tokens = array_merge($tokens, $this->tokens($placeholderAssets));
            $assetWrites = array_merge($assetWrites, $themeAssetWrites->writes($placeholderAssets, $this->references));
        }
        // Asset bytes are final now. Integrity copied from the source must
        // describe them before script loading and the head bootstrap emit it.
        $integrity = new SubresourceIntegrity($assetWrites, $tokens);
        $pages = $integrity->documents($pages);
        $parts = $integrity->documents($parts);
        $scriptLoading = DocumentScripts::loading($pages, $parts, $assets, $tokens, $operations, $runtimeDeclarations);
        $writes = array_merge(ThemeScaffold::writes($assets, $templates, $parts, $scriptLoading['scripts'], $themeProjection['theme'], $tokens, $pages, $menus, $this->runtimeEmoji, $rootDocuments), $assetWrites);
        $unitFindings = array_merge($this->unitFindings->diagnostics(), NavigationInstanceDiagnostics::collect($assets, $navigationMarkup));
        $recoveryDiagnostics = array_merge($this->routeTable->diagnostics(), $this->links->diagnostics(), $themeAssetWrites->diagnostics(), $this->heads->diagnostics(), $unitFindings, $this->missingMedia->diagnostics(), $this->missingFonts->diagnostics(), $this->missingImports->diagnostics(), $eventDiagnostics, $paginationDiagnostics);
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
        $data['diagnostics'] = self::withTemplatePartAttribution($data['diagnostics'], $parts);
        $commentEntities = array();
        foreach ($pages as &$page) {
            array_push($commentEntities, ...($page['document_metadata']['comment_entities'] ?? array()));
            unset($page['document_metadata']['comment_entities']);
        }
        unset($page);
        $plan = array(
            'schema' => WordPressSitePlan::SCHEMA,
            'source' => array('schema' => $this->compiled['schema'] ?? null, 'source_hash' => $this->compiled['source_hash'] ?? null, 'entry_path' => $this->compiled['entry_path'] ?? null, 'provenance' => $data['provenance'], 'source_documents' => TemplateSurfaces::sourceCatalog($this->compiled['pages'] ?? array())),
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
            'navigation_links' => $this->input->navigationLinks,
            'menus' => $menus,
            'theme' => array_merge($this->runtimeEmoji ? array('runtime_emoji' => 'wordpress') : array(), array('stylesheet' => 'style.css', 'theme_json' => 'theme.json', 'bootstrap' => 'functions.php', 'design_token_provenance' => $themeProjection['provenance']), $rootDocuments ? array('document_roots' => $rootDocuments) : array(), null !== ($themeProjection['responsive_breakpoints'] ?? null) ? array('responsive_breakpoints' => $themeProjection['responsive_breakpoints']) : array(), array() === $this->input->fontMaterialization ? array() : array('font_materialization' => $this->input->fontMaterialization), null === $this->designSystem ? array() : array('design_system' => $this->designSystem->report() + array('metrics' => $this->designSystem->metrics(self::markups(array_merge($pages, $templates, $parts)), $assets, array_merge(self::markups(array_merge($pages, $templates)), ...array_fill(0, max(1, count($pages)), self::markups($parts))), $this->presetBinding?->refusals(self::markups(array_merge($pages, $templates, $parts)), array_values(array_map(static fn(array $menu): string => (string) ($menu['block_markup'] ?? ''), $menus))) ?? array(), (int) ($this->compiled['source_css_bytes'] ?? 0))))),
            'visual_repair' => $this->compiled['visual_repair'] ?? array(),
            'runtime_declarations' => $runtimeDeclarations,
            'runtime_records' => $runtimeRecords,
            'runtime_entity_records' => $runtimeEntityRecords,
            'diagnostics' => array_merge($data['diagnostics'], $shellDiagnostics, $scriptLoading['diagnostics'], $taxonomyProjection['diagnostics'], $recoveryDiagnostics),
            // A dropped or rewritten unit is a warning on the plan, the same as withFindings().
            'quality' => array('status' => UnitFindings::status($data['status'], $unitFindings), 'pass' => 'failed' !== $data['status'], 'metrics' => array_diff_key($data['metrics'], array('transform_duration_ms' => true, 'step_timings' => true)), 'fallbacks' => $data['fallbacks'], 'core_html_fallback_evidence' => $this->input->coreHtmlFallbackEvidence, 'editability_policy' => $this->editabilityPolicy),
            'reporting' => PlanReporting::summary($pages, $data, $this->input->coreHtmlFallbackEvidence, array_merge($shellDiagnostics, $scriptLoading['diagnostics'], $taxonomyProjection['diagnostics'], $recoveryDiagnostics), array_merge($this->surfaces, array_values(array_column(array_filter($templates, static fn(array $template): bool => isset($archiveSources[$template['source_path'] ?? ''])), null, 'source_path')))),
        );
        $plan['reference_semantics']['navigation_entities'] = NavigationEntityProjection::REFERENCE_CONTRACT;
        if (array() !== $syncedPatterns) {
            $plan['synced_patterns'] = $syncedPatterns;
            $plan['reference_semantics']['synced_patterns'] = SyncedPatternProjection::REFERENCE_CONTRACT;
        }
        $this->timer?->start('compose.site_plan.asset_consumers');
        $plan = AssetConsumers::assign($plan);
        $plan = ContentConservation::check($plan, $this->compiled['pages']);
        $plan['plan_identity'] = WordPressSitePlan::planIdentity($plan);
        WordPressSitePlan::assertValid($plan);
        return $plan;
    }
    /**
     * Archive documents and unused synthetic parents were working inputs to
     * shell extraction and presentation. Only documents that materialize in
     * WordPress remain pages; the assets they owned and the taxonomy
     * membership follow.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array<string,mixed>> $assets
     * @param array<string,mixed> $taxonomyProjection
     * @return array{pages:array<int,array<string,mixed>>,assets:array<int,array<string,mixed>>,taxonomy:array<string,mixed>,archive_sources:array<string,true>}
     */
    private function materializedPages(array $pages, array $assets, array $taxonomyProjection): array
    {
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
        return array('pages' => $pages, 'assets' => $assets, 'taxonomy' => $taxonomyProjection, 'archive_sources' => $archiveSources);
    }
    /**
     * Stylesheets take their final form: variant-scoped id selectors, no
     * rules for navigation instances the emitted markup never renders, preset
     * bindings for values equal to a token, and one compaction each.
     *
     * @param array<int,array<string,mixed>> $assets
     * @return array<int,array<string,mixed>>
     */
    private function finalStylesheets(array $assets, string $navigationMarkup): array
    {
        $assets = CompiledAssets::withVariantScopedIdSelectors($assets);
        $assets = NavigationInstanceDiagnostics::withoutOrphanedRules($assets, $navigationMarkup);
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
        return $assets;
    }
    /**
     * Shell extraction already knows the final part markup, so a layout
     * topology change is attributed to the one part that emits its classes,
     * located in that markup rather than through source-document paths.
     *
     * @param array<int,mixed> $diagnostics
     * @param array<int,array<string,mixed>> $parts
     * @return array<int,mixed>
     */
    private static function withTemplatePartAttribution(array $diagnostics, array $parts): array
    {
        foreach ($diagnostics as &$diagnostic) {
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
        return $diagnostics;
    }
    /**
     * Binds each page's markup to the design-system presets. Font binding
     * waited for the scoped defaults, so a block is not pinned to a preset
     * when the written Global Styles already owns its family. Shell
     * candidates hold the page's bytes and block offsets, so a page whose
     * markup binding changes has them read again from the bound page.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array<string,mixed>> $documents
     * @return array<int,array<string,mixed>>
     */
    private function presetBoundPages(array $pages, array $documents): array
    {
        $documentsBySource = array_column($documents, null, 'source_path');
        foreach ($pages as &$page) {
            $bound = $this->presetBinding?->bind($page['canonical_block_markup']);
            if (null === $bound || $bound === $page['canonical_block_markup']) continue;
            $page['canonical_block_markup'] = $bound;
            $page['content_hash'] = WordPressSitePlan::contentHash($bound);
            $document = $documentsBySource[$page['source_path']] ?? null;
            if (!is_array($document) || !isset($page['shell_candidates'])) continue;
            foreach ($document['shell_artifacts'] ?? array() as $key => $artifact) {
                if (is_array($artifact) && is_string($artifact['block_markup'] ?? null)) $document['shell_artifacts'][$key]['block_markup'] = $this->presetBinding->bind($artifact['block_markup']);
            }
            $page['shell_candidates'] = $this->shellExtraction->shellCandidates($document, $this->references, $this->routeMap, $bound);
        }
        unset($page);
        return $pages;
    }
    /**
     * Gives each page its route, and records each document's own breadcrumb
     * trail while it still has it: shared chrome later moves the breadcrumb
     * into one template.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param array<string,mixed> $routeBySource
     * @return array<int,array<string,mixed>>
     */
    private static function routedPages(array $pages, array $routeBySource): array
    {
        foreach ($pages as &$page) {
            $path = $routeBySource[$page['source_path']] ?? null;
            if (is_string($path)) $page['route'] = array('path' => $path, 'parent_path' => PageRoute::parent($path), 'slug' => PageRoute::slug($path));
            $trail = BreadcrumbLabelRuntime::trail($page);
            if (array() !== $trail) $page['metadata']['breadcrumb_trail'] = $trail;
        }
        unset($page);
        return $pages;
    }
    /**
     * Extracts the shared shells: explicit parts first, then inline shared
     * shells, edge shells, shared shells with their trailing footer chrome
     * and footer content, capture document chrome, and the hoisted shell
     * frames. Returns the pages and parts that carry them.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param mixed $compiledParts
     * @param array<int,array<string,string>> $tokens
     * @param array<int,array<string,mixed>> $runtimeDeclarations
     * @param array<int,array<string,mixed>> $runtimeEntityRecords
     * @param array<int,array<string,mixed>> $assets
     * @return array{pages:array<int,array<string,mixed>>,parts:array<int,array<string,mixed>>,frame_parts:list<array<string,mixed>>,shell_frames:array<string,mixed>,document_chrome:array<int,string>,runtime_declarations:mixed,runtime_entity_records:array<int,array<string,mixed>>,diagnostics:array<int,mixed>}
     */
    private function extractedShells(array $pages, mixed $compiledParts, array $tokens, array $runtimeDeclarations, array $runtimeEntityRecords, array $assets): array
    {
        $existingParts = $this->documents($compiledParts, true, $tokens, $this->references, $this->routeMap);
        $explicitBindings = $this->shellExtraction->bindExplicitParts($pages, $existingParts, $runtimeDeclarations, $runtimeEntityRecords);
        $pages = $explicitBindings['pages'];
        $existingParts = $explicitBindings['parts'];
        $reservedPartSlugs = array_fill_keys(array_column($existingParts, 'slug'), true);
        $canonicalInlineParts = $this->documents(ArrayField::array($this->compiled, 'inline_shell_artifacts'), true, $tokens, $this->references, $this->routeMap);
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
        return array(
            'pages' => $pages,
            'parts' => $parts,
            'frame_parts' => $frameParts,
            'shell_frames' => $shellFrames,
            'document_chrome' => $documentChrome['chrome'],
            'runtime_declarations' => $shells['runtime_declarations'],
            'runtime_entity_records' => $runtimeEntityRecords,
            'diagnostics' => array_merge($inlineShells['diagnostics'], $shells['diagnostics']),
        );
    }
    /**
     * Theme templates for the captured surfaces alone, before any page or
     * part exists, for anchoring entity bindings. Each carries its surface's
     * projected source markup.
     *
     * @param array<int,array<string,string>> $tokens
     * @return array<int,array<string,mixed>>
     */
    private function bindingTemplates(array $tokens): array
    {
        $bindingTemplates = ThemeTemplates::build($this->links, array(), array(), $this->surfaces, $tokens, $this->references, $this->routeMap);
        $surfaceMarkup = array_column($this->surfaces, 'block_markup', 'source_path');
        foreach ($bindingTemplates as &$template) if (isset($surfaceMarkup[$template['source_path'] ?? ''])) $template['_projected_source_block_markup'] = $surfaceMarkup[$template['source_path']];
        unset($template);
        return $bindingTemplates;
    }

    /**
     * Root selector context belongs to captured documents, not only to
     * entities that survive as pages (terms, authors and dates do not).
     * Returns the pages whose stylesheets are scoped (native archives are
     * excluded) and the captured documents no such page carries.
     *
     * @param array<int,array<string,mixed>> $pages
     * @param array<string,mixed> $taxonomyProjection
     * @param array<string,string> $routeBySource
     * @param array<string,mixed> $paginationOwners
     * @param array<string,mixed> $paginationSources
     * @return array{asset_pages:list<array<string,mixed>>,root_documents:list<array<string,mixed>>}
     */
    private function rootDocuments(array $pages, array $taxonomyProjection, array $routeBySource, array $paginationOwners, array $paginationSources): array
    {
        $rootDocuments = array();
        $nativeArchiveSources = array_fill_keys(array_column(array_column($taxonomyProjection['entities'], 'archive'), 'source_path'), true);
        $assetPages = array_values(array_filter($pages, static fn(array $page): bool => !isset($nativeArchiveSources[$page['source_path']])));
        $pageSources = array_fill_keys(array_column($assetPages, 'source_path'), true);
        foreach ($this->compiled['pages'] as $document) {
            if (isset($pageSources[$document['source_path']]) || isset($paginationOwners[$document['source_path']]) || isset($paginationSources[$document['source_path']])) continue;
            $path = $routeBySource[$document['source_path']] ?? $document['metadata']['route_path'] ?? PageRoute::fromSource($document['source_path'], $this->entryRoot);
            $metadata = $document['document_metadata'] ?? array();
            $rootDocuments[] = array('source_path' => $document['source_path'], 'reconciliation_identity' => WordPressSitePlan::identity('page', $document['source_path'], $document['source_path']), 'route' => array('path' => $path), 'document_metadata' => array_intersect_key($metadata, array('root_attributes' => true, 'body_attributes' => true)));
        }
        return array('asset_pages' => $assetPages, 'root_documents' => $rootDocuments);
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
            $target = $part ? 'parts/' . WordPressSitePlan::value($document, 'slug') . '.html' : WordPressSitePlan::value($document, 'source_path');
            $area = $part ? WordPressSitePlan::value($document, 'area', 'uncategorized') : null;
            $row = array('source_path' => $document['source_path'], 'slug' => WordPressSitePlan::value($document, 'slug'), 'title' => WordPressSitePlan::value($document, 'title'), 'post_type' => WordPressSitePlan::value((array) ($document['metadata'] ?? array()), 'post_type', 'page'), 'parent_source_path' => WordPressSitePlan::value((array) ($document['metadata'] ?? array()), 'parent_source_path'), 'entrypoint' => ! empty($document['entrypoint']), 'area' => $area, 'tag_name' => $part ? WordPressSitePlan::value($document, 'tag_name', ShellLandmarkPolicy::templatePartAreaTagName((string) $area)) : null, 'placement' => $part && is_array($document['placement'] ?? null) ? $document['placement'] : ($part ? array('kind' => 'unbound') : null), 'canonical_block_markup' => $canonical, '_projected_source_block_markup' => $document['block_markup'], 'metadata' => ArrayField::array($document, 'metadata'), 'document_metadata' => $this->heads->metadata($document, $references, $routes), 'provenance' => ArrayField::array($document, 'provenance'), 'reconciliation_identity' => WordPressSitePlan::identity($part ? 'template-part' : 'page', $document['source_path'], $target), 'content_hash' => WordPressSitePlan::contentHash($canonical));
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
    private function chromeIdentityKey(string $markup): string
    {
        return $this->chromeIdentityKeys[hash('xxh128', $markup)] ??= ('' === ($identity = ChromeIdentity::markup($markup)) ? '' : hash('xxh128', $identity));
    }
    /**
     * @param array<int,array<string,mixed>> $documents
     * @return list<string>
     */
    private static function markups(array $documents): array
    {
        return array_values(array_filter(array_column($documents, 'canonical_block_markup'), 'is_string'));
    }
}
