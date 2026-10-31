<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes;
use Automattic\BlocksEngine\PhpTransformer\Support\DocumentVariantIds;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlMarkupParser;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlTagScanner;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;
use Automattic\BlocksEngine\PhpTransformer\Support\StylesheetActivation;
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
use Automattic\BlocksEngine\PhpTransformer\Css\CssIdent;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
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
    /** Generated, document-namespaced class names the projected author CSS selects on. */
    /** Marks the post-content block of a template that holds a page's hoisted layout frame. */
    public const FRAME_CONTENT_CLASS = 'blocks-engine-frame-content';
    private const GENERATED_CLASS_PATTERN = '/blocks-engine-[a-z-]+-[0-9a-f]{12}-\d+/';
    public const EDITOR_CORE_IMAGE_INTERACTION_CSS = ':root .block-editor-block-list__block.wp-block-image img{pointer-events:auto!important}';
    public const EDITOR_POST_TITLE_INTERACTION_CSS = ':root .editor-post-title{position:relative;z-index:100000;pointer-events:auto!important}';
    public const EDITOR_LINK_INTERACTION_CSS = ':root .editor-styles-wrapper a[href]{pointer-events:none!important}';
    public const LISTING_QUERY_CLASS = 'blocks-engine-listing-query';
    /** The source-route pagination rewrite capture admits bounded positive page numbers only (1..999999). */
    public const TAXONOMY_ARCHIVE_PAGED_CAPTURE = '([1-9][0-9]{0,5})';
    public const LISTING_QUERY_CSS = '.wp-block-query.blocks-engine-listing-query,.wp-block-query.blocks-engine-listing-query .wp-block-post-template,.wp-block-query.blocks-engine-listing-query .wp-block-post,.wp-block-query.blocks-engine-listing-query .wp-block-post-content{display:contents;list-style:none;margin:0;padding:0}.blocks-engine-listing-query .blocks-engine-authored-excerpt>p{margin:0}.blocks-engine-listing-overlay{width:auto}';
    /**
     * A generated theme reproduces captured text, so WordPress typographic
     * rewriting stays off while it is active. Static block content survives
     * texturization only because its punctuation is entity encoded; text that a
     * dynamic block decodes and prints is rewritten, so the guarantee belongs to
     * the theme rather than to any one block.
     */
    public const SOURCE_TEXT_TYPOGRAPHY = <<<'PHP'
add_filter( 'run_wptexturize', '__return_false' );
PHP;
    /** Keep native emoji unless capture proves the source used core's runtime. */
    public const SOURCE_NATIVE_EMOJI = <<<'PHP'
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'embed_head', 'print_emoji_detection_script' );
add_action( 'admin_init', static function (): void { remove_action( 'admin_print_scripts', 'print_emoji_detection_script' ); } );
add_filter( 'tiny_mce_plugins', static function ( array $plugins ): array { return array_values( array_diff( $plugins, array( 'wpemoji' ) ) ); } );
PHP;
    /**
     * core/accordion-heading saves a fixed `+` icon slot with no artwork field.
     * A source-proved SVG rides in the heading's block metadata and is rendered
     * into that slot here, so saved markup stays core's exact shape while the
     * page paints the actual vector. Metadata is author-editable post content,
     * so the markup is sanitized to passive SVG on every render.
     */
    public const ACCORDION_ICON_RENDERER = <<<'PHP'
add_filter( 'render_block_core/accordion-heading', static function ( string $content, array $block ): string {
    $svg = $block['attrs']['metadata']['blocksEngineIcon'] ?? '';
    if ( ! is_string( $svg ) || '' === $svg ) return $content;
    $paint = array( 'fill' => true, 'fill-rule' => true, 'clip-rule' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'stroke-miterlimit' => true, 'stroke-dasharray' => true, 'stroke-dashoffset' => true, 'opacity' => true, 'fill-opacity' => true, 'stroke-opacity' => true, 'transform' => true, 'class' => true, 'style' => true );
    $shape = static fn ( array $geometry ): array => array_merge( $paint, array_fill_keys( $geometry, true ) );
    $svg = trim( wp_kses( $svg, array(
        'svg' => array_merge( $paint, array( 'xmlns' => true, 'width' => true, 'height' => true, 'viewbox' => true, 'preserveaspectratio' => true, 'aria-hidden' => true, 'focusable' => true, 'role' => true ) ),
        'g' => $paint,
        'path' => $shape( array( 'd' ) ),
        'circle' => $shape( array( 'cx', 'cy', 'r' ) ),
        'ellipse' => $shape( array( 'cx', 'cy', 'rx', 'ry' ) ),
        'line' => $shape( array( 'x1', 'y1', 'x2', 'y2' ) ),
        'polyline' => $shape( array( 'points' ) ),
        'polygon' => $shape( array( 'points' ) ),
        'rect' => $shape( array( 'x', 'y', 'width', 'height', 'rx', 'ry' ) ),
    ) ) );
    if ( ! str_starts_with( $svg, '<svg' ) ) return $content;
    return (string) preg_replace_callback( '/(<span\b[^>]*\bwp-block-accordion-heading__toggle-icon\b[^>]*>)[^<]*(<\/span>)/', static fn ( array $match ): string => $match[1] . $svg . $match[2], $content, 1 );
}, 10, 2 );
PHP;
    /**
     * A lowered srcset family keeps its authored candidates, slot sizes and
     * loading in core/image metadata. They describe the source `src`; once
     * the image is replaced, the new attachment's own candidates apply.
     */
    public const IMAGE_SELECTION_RENDERER = <<<'PHP'
add_filter( 'render_block_core/image', static function ( string $content, array $block ): string {
    $selection = $block['attrs']['metadata']['blocksEngineImageSelection'] ?? null;
    if ( ! is_array( $selection ) || ! is_string( $selection['src'] ?? null ) || ! is_string( $selection['srcset'] ?? null ) || '' === $selection['srcset'] ) return $content;
    $tag = new WP_HTML_Tag_Processor( $content );
    if ( ! $tag->next_tag( 'IMG' ) || $tag->get_attribute( 'src' ) !== $selection['src'] ) return $content;
    $tag->set_attribute( 'srcset', $selection['srcset'] );
    if ( is_string( $selection['sizes'] ?? null ) && '' !== $selection['sizes'] ) $tag->set_attribute( 'sizes', $selection['sizes'] );
    else $tag->remove_attribute( 'sizes' );
    if ( in_array( $selection['loading'] ?? null, array( 'lazy', 'eager' ), true ) ) $tag->set_attribute( 'loading', $selection['loading'] );
    return $tag->get_updated_html();
}, 10, 2 );
PHP;
    private string $sourceOrigin = '';
    private string $sourceUrl = '';
    private const MAX_UNRESOLVED_NAVIGATION_DIAGNOSTICS = 50;
    private const MAX_ROUTE_COLLISION_DIAGNOSTICS = 50;
    private const MAX_OMITTED_LINK_DECLARATION_DIAGNOSTICS = 50;
    private const OMITTED_LINK_DECLARATION_DIAGNOSTIC_CODE = 'wordpress_site_plan_omitted_link_declaration';
    /** @var array<string,array<string,mixed>> */
    private array $unresolvedNavigationDiagnostics = array();
    private int $omittedUnresolvedNavigationDiagnostics = 0;
    /** @var array<string,array<string,mixed>> */
    private array $unresolvedScriptReferenceDiagnostics = array();
    private int $omittedUnresolvedScriptReferenceDiagnostics = 0;
    /** @var array<string,array<string,mixed>> */
    private array $omittedLinkDeclarations = array();
    /** @var array<string,array<string,bool>> */
    private array $omittedLinkDeclarationPages = array();
    /** @var array<string,int> Earlier viewport metas dropped per source path. */
    private array $duplicateViewports = array();
    /** @var array<string,array<string,mixed>> Dropped or rewritten units, keyed by their identity fields. */
    private array $unitFindings = array();
    /** @var array<string,bool> */
    private array $omittedLinkDeclarationOverflow = array();
    /** @var array<int,array<string,mixed>> */
    private array $routeCollisions = array();
    private int $omittedRouteCollisionDiagnostics = 0;
    /** @var array<string,string> */
    private array $routeSources = array();
    /** @var array<string,string> */
    private array $routeTargets = array();
    /** @var array<string,string|false> */
    private array $routeReferenceCache = array();
    /** @var array<string,array<string,true>> */
    private array $routeLinkedFragments = array();
    /** @var list<string> */
    private array $listingQueryContainers = array();
    private readonly ShellExtraction $shellExtraction;
    private ?DesignSystem $designSystem = null;
    private ?PresetBinding $presetBinding = null;
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
        $this->sourceUrl = $this->sourceUrlFromProvenance($data['provenance'] ?? array());
        $this->listingQueryContainers = array();
        $this->sourceOrigin = $this->urlOrigin($this->sourceUrl);
        $this->unresolvedNavigationDiagnostics = array();
        $this->omittedUnresolvedNavigationDiagnostics = 0;
        $this->unresolvedScriptReferenceDiagnostics = array();
        $this->omittedUnresolvedScriptReferenceDiagnostics = 0;
        $this->omittedLinkDeclarations = array();
        $this->omittedLinkDeclarationPages = array();
        $this->duplicateViewports = array();
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
        $runtimeScriptOwnership = $this->runtimeScriptOwnership($input->runtimeIslandPackage, $compiled['pages'] ?? array(), $runtimeDeclarations);
        $documents = $this->withoutOwnedRuntimeScripts($this->decideDocuments($compiled['pages'] ?? null), $runtimeScriptOwnership['documents']);
        $documentScriptAssets = $this->documentScriptAssets($documents);
        $assets = array_values(array_filter(
            $this->assets($compiled['assets'] ?? null),
            static fn(array $asset): bool => !isset($runtimeScriptOwnership['assets'][(string) ($asset['source_path'] ?? '')]) || isset($documentScriptAssets[(string) ($asset['source_path'] ?? '')])
        ));
        $retainedSources = array_fill_keys(array_column($documents, 'source_path'), true);
        $assets = array_values(array_filter($assets, static fn(array $asset): bool => 'page' !== ($asset['compilation']['scope'] ?? null) || isset($retainedSources[$asset['compilation']['id'] ?? ''])));
        $assets = $this->applyDeclaredAssetTransformations($assets, $runtimeDeclarations);
        // The design system comes from what every document renders, before
        // any document is projected: routeLinks() binds literal block values
        // to its tokens wherever block markup enters the plan.
        // Button element styles are decided on the markup before binding,
        // which is what tells a button that renders through them apart from
        // one that never had the value.
        $this->designSystem = DesignSystem::fromUsage(self::designUsage($documents))?->withButtonElementsOwnedBy(array_values(array_filter(array_column($documents, 'block_markup'), 'is_string')));
        $this->presetBinding = null === $this->designSystem ? null : new PresetBinding($this->designSystem, $assets, self::designUsage($documents));
        $tokens = $this->tokens($assets);
        $surfaces = $this->templateSurfaces($documents);
        $documents = array_values(array_filter($documents, static fn(array $document): bool => !isset($document['template_surface'])));
        $routeMap = $this->canonicalRoutes($documents, $input->routes);
        // Both native archive kinds use the same listing, template and resource pipeline.
        $taxonomyProjection = TaxonomyProjection::project($documents, $routeMap, $this->sourceOrigin);
        $authorProjection = AuthorProjection::project($documents, $routeMap, $this->sourceOrigin);
        foreach (array('entities', 'diagnostics') as $key) $taxonomyProjection[$key] = array_merge($taxonomyProjection[$key], $authorProjection[$key]);
        $taxonomyProjection['pagination_source_paths_by_archive'] += $authorProjection['pagination_source_paths_by_archive'];
        $this->routeSources = array();
        $this->routeTargets = array();
        $this->routeReferenceCache = array();
        foreach ($routeMap as $route) {
            $sourcePath = (string) ($route['source_path'] ?? '');
            $targetPath = (string) ($route['target_path'] ?? '');
            if ('' !== $sourcePath && '' !== $targetPath) $this->routeSources[$sourcePath] = $targetPath;
            if ('' !== $targetPath) $this->routeTargets['/' === $targetPath ? '/' : '/' . trim($targetPath, '/')] = $targetPath;
        }
        $this->routeLinkedFragments = $this->routeLinkedFragments($documents, $routeMap);
        $this->missingMedia = new MissingMediaRecovery($this->strictMissingMedia, array_column($assets, 'target_path'));
        $missingFonts = new MissingFontSourceRecovery($this->strictMissingMedia);
        $references = new AssetReferenceCanonicalizer($tokens, self::entryRootFromDocuments($documents), $this->missingMedia, $missingFonts);
        $pages = $this->documents($documents, false, $tokens, $references, $routeMap);
        // Probe presentation with the final plan's canonical listing projection.
        // Entity ownership is already proven independently and must survive a
        // declined presentation, including ownership of numbered captures.
        $routeBySource = array_column($routeMap, 'target_path', 'source_path');
        foreach ($pages as &$page) {
            $path = $routeBySource[$page['source_path']] ?? null;
            if (is_string($path)) $page['route'] = array('path' => $path, 'parent_path' => self::parentRoutePath($path), 'slug' => self::routeSlug($path));
        }
        unset($page);
        // Taxonomy projection runs over compiled documents to prove source
        // membership, while page metadata is where declared head resources are
        // resolved to canonical asset tokens. Attach that canonical occurrence
        // only after resource resolution; copying the compiled URL here would
        // detach the archive proof from its declared write and scope.
        $canonicalPagesBySource = array_column($pages, null, 'source_path');
        foreach ($taxonomyProjection['entities'] as &$taxonomyEntity) {
            $archiveSource = $taxonomyEntity['archive']['source_path'] ?? null;
            $archiveHead = is_string($archiveSource) ? ($canonicalPagesBySource[$archiveSource]['document_metadata']['head'] ?? null) : null;
            if (is_array($archiveHead)) $taxonomyEntity['archive']['document_head'] = $archiveHead;
        }
        unset($taxonomyEntity);
        $preflightPages = $pages;
        $this->listingQueryContainers = array();
        $preflightPages = $this->materializeListingQueryLoops($preflightPages, $runtimeDeclarations, $taxonomyProjection['entities']);
        $this->listingQueryContainers = array();
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
            $parent = self::paginationParentRoute($route);
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
            $owner = $sourceByRoute[rtrim((string) self::paginationParentRoute($routeBySource[$source] ?? ''), '/')] ?? null;
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
                $restored = $this->shellExtraction->replaceTopLevelShell($page['canonical_block_markup'], (string) ($candidate['area'] ?? ''), (string) ($candidate['markup'] ?? ''));
                if (null !== $restored) $page['canonical_block_markup'] = $restored;
            }
            $page['content_hash'] = self::contentHash($page['canonical_block_markup']);
        }
        unset($page);
        // Bindings anchor on the final canonical page markup before any shell
        // extraction. Asset and route projection can make source anchors equal,
        // so assign occurrences only after that shared projection is complete.
        $bindingTemplates = $this->templates(array(), array(), $surfaces, $tokens, $references, $routeMap);
        $surfaceMarkup = array_column($surfaces, 'block_markup', 'source_path');
        foreach ($bindingTemplates as &$template) if (isset($surfaceMarkup[$template['source_path'] ?? ''])) $template['_projected_source_block_markup'] = $surfaceMarkup[$template['source_path']];
        unset($template);
        $runtimeDeclarations = $this->canonicalEntityBindings($runtimeDeclarations, $runtimeEntityRecords, $references, $routeMap, $pages, $bindingTemplates, $paginationOwners);
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
        $assets = $this->scopeAssets($assets, $pages);
        $projector = new ThemeJsonProjection();
        $themeProjection = $projector->project($assets, array_column($pages, 'source_path'), $this->designSystem);
        $assets = $themeProjection['assets'];
        $routes = $this->routesForPages($pages);
        // Entry shells remain in compiled-site/v1 for existing consumers; the
        // canonical plan rebuilds them from full page shell candidates.
        $compiledParts = is_array($compiled['template_parts'] ?? null) ? array_values(array_filter($compiled['template_parts'], static fn(mixed $part): bool => !is_array($part) || 'entry_shell' !== ($part['placement']['kind'] ?? null))) : null;
        $existingParts = $this->documents($compiledParts, true, $tokens, $references, $routeMap);
        $reservedPartSlugs = array_fill_keys(array_column($existingParts, 'slug'), true);
        $canonicalInlineParts = $this->documents(is_array($compiled['inline_shell_artifacts'] ?? null) ? $compiled['inline_shell_artifacts'] : array(), true, $tokens, $references, $routeMap);
        $pages = $this->shellExtraction->withSourceSharedShellIdentity($pages, $canonicalInlineParts, $runtimeDeclarations, $runtimeEntityRecords);
        $inlineShells = $this->shellExtraction->inlineSharedShells($pages, $reservedPartSlugs, $runtimeDeclarations, $canonicalInlineParts, $runtimeEntityRecords);
        $reservedPartSlugs += array_fill_keys(array_column($inlineShells['parts'], 'slug'), true);
        $edgeShells = $this->shellExtraction->edgeShells($inlineShells['pages'], $reservedPartSlugs, $inlineShells['runtime_declarations']);
        $inlineShells['pages'] = $edgeShells['pages'];
        $inlineShells['parts'] = array_merge($inlineShells['parts'], $edgeShells['parts']);
        $inlineShells['diagnostics'] = array_merge($inlineShells['diagnostics'], $edgeShells['diagnostics']);
        // Variant parts settle their area: no single part is bound in templates.
        $reservedPartSlugs += array_fill_keys(array_column($edgeShells['parts'], 'slug'), true) + array_fill_keys(array_column($edgeShells['parts'], 'area'), true);
        $shells = $this->shellExtraction->sharedShells($inlineShells['pages'], $reservedPartSlugs, $inlineShells['runtime_declarations'], $runtimeEntityRecords);
        $inlineAreas = array_fill_keys(array_column($inlineShells['parts'], 'area'), true);
        $shells['diagnostics'] = array_values(array_filter($shells['diagnostics'], static fn(array $diagnostic): bool => !isset($inlineAreas[$diagnostic['area'] ?? '']) || 'wordpress_site_plan_shell_retained_incomplete' !== ($diagnostic['code'] ?? null)));
        $footerContent = $this->shellExtraction->factorSharedFooterContent($shells['pages'], $shells['parts'], $shells['runtime_declarations'], $runtimeEntityRecords);
        $shells['pages'] = $footerContent['pages'];
        $shells['parts'] = $footerContent['parts'];
        $shells['diagnostics'] = array_merge($shells['diagnostics'], $footerContent['diagnostics']);
        $parts = array_merge($existingParts, $inlineShells['parts'], $shells['parts']);
        $documentChrome = $this->shellExtraction->separateCaptureDocumentChrome($shells['pages'], $shells['runtime_declarations'], $runtimeEntityRecords);
        $shells['diagnostics'] = array_merge($shells['diagnostics'], $documentChrome['diagnostics']);
        $shellFrames = $this->shellExtraction->hoistInlineShellFrames($documentChrome['pages'], $parts, $shells['runtime_declarations']);
        $pages = $shellFrames['pages'];
        // The generic page template is shared by routes that did not author it,
        // so the rules aimed at its markers are site-wide chrome rules.
        $frameParts = isset($shellFrames['frames']['page']) ? array(array('slug' => 'page-frame', 'placement' => array('kind' => 'shared_shell'), 'canonical_block_markup' => $shellFrames['frames']['page']['opening'] . $shellFrames['frames']['page']['closing'])) : array();
        foreach ($documentChrome['chrome'] as $chrome) $frameParts[] = array('slug' => 'document-chrome', 'placement' => array('kind' => 'shared_shell', 'template_slugs' => array('single')), 'canonical_block_markup' => $chrome);
        $assetTargetCollisions = array();
        // Rich-text markers some emitted markup still carries. A chrome rule
        // naming any other marker names an element extraction replaced (one
        // route's copy of the chrome), which matches nothing anywhere.
        // Underscore keys are working state the plan does not emit, such as a
        // route's projected source markup from before its chrome was extracted.
        $emitted = static fn (array $rows): array => array_map(static fn (mixed $row): mixed => is_array($row) ? array_filter($row, static fn (int|string $key): bool => !is_string($key) || !str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY) : $row, $rows);
        $liveRichTextMarkers = preg_match_all('/blocks-engine-richtext-[a-f0-9]+-\d+/', (string) json_encode(array($emitted($pages), $emitted($parts), $frameParts, $shellFrames['frames'] ?? array(), $shells['runtime_declarations'] ?? array(), $runtimeEntityRecords)), $liveMatches) ? array_fill_keys($liveMatches[0], true) : array();
        $assets = self::projectSharedChromeStylesheets($assets, array_merge($parts, $frameParts), $pages, $references, $assetTargetCollisions, $liveRichTextMarkers);
        $assets = self::withoutDeadRichTextMarkerSelectors($assets, $liveRichTextMarkers);
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
        $assets = self::projectDetachedChromePaintOrder($assets, $parts, $pages);
        $assets = self::orderPageStylesheetProjections($assets);
        $assets = self::placeSharedStylesheetProjections($assets, $pages);
        $assets = self::mergeIdenticalPageStylesheets($assets, array_merge($pages, $parts, $frameParts));
        $tokens = $this->tokens($assets);
        if (array() !== $parts) $themeProjection['theme']['templateParts'] = array_values(array_map(static fn(array $part): array => array('name' => $part['slug'], 'title' => $part['title'], 'area' => $part['area']), $parts));
        $runtimeDeclarations = $shells['runtime_declarations'];
        $runtimeDeclarations = $this->canonicalEntityBindings($runtimeDeclarations, $runtimeEntityRecords, $references, $routeMap, $pages, array_merge($parts, $bindingTemplates), $paginationOwners);
         self::assertEntityBindingsAnchored($runtimeDeclarations, $pages, array_merge($parts, $bindingTemplates), $assets);
         $navigation = NavigationEntityProjection::project($pages, $parts, $input->menus, $runtimeDeclarations, $runtimeEntityRecords);
         $pages = $navigation['pages'];
         $parts = $navigation['parts'];
         $menus = $navigation['menus'];
        $pages = $this->extractPostCommentForms($pages);
        $blockThemeChrome = $this->extractBlockThemePostChrome($pages, $runtimeDeclarations, $runtimeEntityRecords);
        $pages = $blockThemeChrome['pages'];
        $articleChrome = $this->extractPostArticleChrome($pages, $parts, $runtimeDeclarations, $runtimeEntityRecords);
        if (array() !== $blockThemeChrome['templates']) $articleChrome['single'] = reset($blockThemeChrome['templates']);
         $pages = $articleChrome['pages'];
          $pages = $this->materializeListingQueryLoops($pages, $runtimeDeclarations, $taxonomyProjection['entities'], true);
          $pages = self::resolveFeaturedImageTargets($pages, $tokens);
          $assets = ListingQueryPresentation::project($assets, $this->listingQueryContainers);
         // Content every panel of one tabs block repeats is declared once.
         $syncedPatterns = SyncedPatternProjection::project($pages, $assets);
         $pages = $syncedPatterns['pages'];
         $syncedPatterns = $syncedPatterns['patterns'];
         // Query Loop projection can shorten page markup after shell extraction,
         // and synced patterns take shared panel content (and its bindings) out
         // of the page. Rebase retained runtime anchors on the final documents.
         $runtimeDeclarations = $this->canonicalEntityBindings($runtimeDeclarations, $runtimeEntityRecords, $references, $routeMap, $pages, array_merge($parts, $bindingTemplates), $paginationOwners);
         $pages = self::attachWholePageCandidates($pages, $runtimeDeclarations, $runtimeEntityRecords);
         foreach ($pages as &$page) unset($page['_projected_source_block_markup'], $page['_synced_pattern_source']); unset($page);
         self::assertEntityBindingsAnchored($runtimeDeclarations, $pages, array_merge($parts, $syncedPatterns, $bindingTemplates), $assets);
         $projectedTaxonomyEntities = array();
         foreach ($taxonomyProjection['entities'] as $taxonomyEntity) {
             foreach ($pages as $page) if (($page['source_path'] ?? null) === ($taxonomyEntity['archive']['source_path'] ?? null)) {
                 if (!str_contains((string) ($page['canonical_block_markup'] ?? ''), '<!-- wp:query ')) {
                     $taxonomyProjection['diagnostics'][] = array('code' => 'wordpress_site_plan_' . ('author' === $taxonomyEntity['kind'] ? 'author' : 'taxonomy') . '_archive_presentation_static', 'severity' => 'warning', 'message' => 'The archive renders its captured presentation and static cards rather than a live query because the listing could not be converted to an inherited native query loop.', 'source_path' => $taxonomyEntity['archive']['source_path'], 'source_route' => $taxonomyEntity['archive']['source_route']);
                 }
                 $taxonomyEntity['archive']['presentation_markup'] = $page['canonical_block_markup'];
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
        $templates = $this->templates($pages, $parts, $surfaces, $tokens, $references, $routeMap, $articleChrome['single'], $taxonomyProjection['entities'], $shellFrames['frames'], $documentChrome['chrome'], $blockThemeChrome['templates']);
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
        $registeredTemplates = array();
        foreach ($pages as $page) if (isset($page['route_template']) && !isset($registeredTemplates[$page['route_template']])) {
            $registeredTemplates[$page['route_template']] = true;
            $themeProjection['theme']['customTemplates'][] = array('name' => $page['route_template'], 'title' => $sharedTemplateTitles[$page['route_template']] ?? $page['title'], 'postTypes' => array($page['post_type']));
        }
        foreach ($taxonomyProjection['entities'] as &$taxonomyEntity) foreach ($templates as $template) if (self::archiveTemplateSlug($taxonomyEntity) === ($template['slug'] ?? null)) {
            $taxonomyEntity['archive']['presentation_markup'] = $template['canonical_block_markup'];
            continue 2;
        }
        unset($taxonomyEntity);
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
        foreach ($taxonomyProjection['entities'] as &$taxonomyEntity) if (is_array($taxonomyEntity['archive']['document_head'] ?? null)) $taxonomyEntity['archive']['document_head'] = $integrity->head($taxonomyEntity['archive']['document_head']);
        unset($taxonomyEntity);
        $scriptLoading = $this->scriptLoading($pages, $parts, $assets, $tokens, $operations, $runtimeDeclarations);
        $writes = array_merge($this->scaffoldWrites($assets, $templates, $parts, $scriptLoading['scripts'], $themeProjection['theme'], $tokens, $pages, $menus, $taxonomyProjection['entities'], $runtimeEmoji), $assetWrites);
        $unitFindings = $this->unitFindingDiagnostics();
        $recoveryDiagnostics = array_merge($this->routeCollisionDiagnostics(), $this->unresolvedNavigationDiagnostics(), $this->unresolvedScriptReferenceDiagnostics(), $this->omittedLinkDeclarationDiagnostics(), $this->duplicateViewportDiagnostics(), $unitFindings, $this->missingMedia->diagnostics(), $missingFonts->diagnostics(), $eventDiagnostics, $paginationDiagnostics);
        // All shell, navigation, listing and script binding projection is now
        // complete. Only the public plan needs the bounded record form.
        // Declarations still over a byte limit are dropped here with their
        // bindings, so their blocks stay static and each drop is a finding.
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
            'theme' => array_merge($runtimeEmoji ? array('runtime_emoji' => 'wordpress') : array(), array('stylesheet' => 'style.css', 'theme_json' => 'theme.json', 'bootstrap' => 'functions.php', 'design_token_provenance' => $themeProjection['provenance']), null !== ($themeProjection['responsive_breakpoints'] ?? null) ? array('responsive_breakpoints' => $themeProjection['responsive_breakpoints']) : array(), array() === $input->fontMaterialization ? array() : array('font_materialization' => $input->fontMaterialization), null === $this->designSystem ? array() : array('design_system' => $this->designSystem->report() + array('metrics' => $this->designSystem->metrics(self::markups(array_merge($pages, $templates, $parts)), $assets, array_merge(self::markups(array_merge($pages, $templates)), ...array_fill(0, max(1, count($pages)), self::markups($parts))), $this->presetBinding?->refusals(self::markups(array_merge($pages, $templates, $parts)), array_values(array_map(static fn(array $menu): string => (string) ($menu['block_markup'] ?? ''), $menus))) ?? array(), (int) ($compiled['source_css_bytes'] ?? 0))))),
            'visual_repair' => $compiled['visual_repair'] ?? array(),
            'runtime_declarations' => $runtimeDeclarations,
            'runtime_records' => $runtimeRecords,
            'runtime_entity_records' => $runtimeEntityRecords,
            'diagnostics' => array_merge($data['diagnostics'], $inlineShells['diagnostics'], $shells['diagnostics'], $scriptLoading['diagnostics'], $taxonomyProjection['diagnostics'], $recoveryDiagnostics),
            // A dropped or rewritten unit is a warning on the plan, the same as withFindings().
            'quality' => array('status' => self::statusWithFindings($data['status'], $unitFindings), 'pass' => 'failed' !== $data['status'], 'metrics' => array_diff_key($data['metrics'], array('transform_duration_ms' => true)), 'fallbacks' => $data['fallbacks'], 'core_html_fallback_evidence' => $input->coreHtmlFallbackEvidence, 'editability_policy' => $editabilityPolicy),
            'reporting' => $this->reporting($pages, $data, $input->coreHtmlFallbackEvidence, array_merge($inlineShells['diagnostics'], $shells['diagnostics'], $scriptLoading['diagnostics'], $taxonomyProjection['diagnostics'], $recoveryDiagnostics), array_merge($surfaces, array_values(array_column(array_filter($templates, static fn(array $template): bool => isset($archiveSources[$template['source_path'] ?? ''])), null, 'source_path')))),
        );
        $plan['reference_semantics']['navigation_entities'] = NavigationEntityProjection::REFERENCE_CONTRACT;
        if (array() !== $syncedPatterns) {
            $plan['synced_patterns'] = $syncedPatterns;
            $plan['reference_semantics']['synced_patterns'] = SyncedPatternProjection::REFERENCE_CONTRACT;
        }
        $consumers = AssetConsumers::inventory($plan);
        foreach ($plan['assets'] as &$asset) {
            $asset['consumers'] = $consumers[$asset['token']] ?? array();
            $asset['destination'] = AssetConsumers::destination($asset['consumers']);
            $asset['theme_write_reasons'] = self::themeWriteReasons($asset['consumers'], $asset['destination']);
        }
        unset($asset);
        $plan['plan_identity'] = self::planIdentity($plan);
        self::assertValid($plan);
        return $plan;
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
                    if ( !is_string($source) || !is_string($search) || '' === $search || !is_int($occurrence) || $occurrence < 1 || !is_string($ownedMarkup) || null === $offset || (null !== $position && (!self::bindingPosition($position, $ownedMarkup, $search) || $position['offset'] !== $offset)) ) throw new InvalidArgumentException('A runtime entity binding no longer has its declared source document block anchor after shell extraction: ' . (is_string($source) ? $source : 'unknown') . ' (' . (is_string($binding['role'] ?? null) ? $binding['role'] : 'unknown') . ').');
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
        RuntimeDeclarations::assertNormalized($plan['runtime_declarations']);
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
            $source = $entity['archive']['source_path'] ?? '';
            $route = $entity['archive']['source_route'] ?? '';
            if (!isset($sourceCatalog[$source]) || isset($pagesBySource[$source])) throw new InvalidArgumentException('WordPress site plan native archive must name a captured source without a materialized page.');
            $archiveDocuments[$source] = array('source_path' => $source, 'post_type' => 'page', 'route' => array('path' => $route), 'reconciliation_identity' => self::identity('page', $source, $route));
        }
        self::assertTaxonomyEntities($plan['taxonomy_entities'], $pagesBySource);
        self::assertAuthorEntities($plan['author_entities'], $pagesBySource);
        $expectedCandidatePages = self::attachWholePageCandidates(array_map(static function (array $page): array { unset($page['whole_page_candidates']); return $page; }, $plan['pages']), RuntimeDeclarations::materialize($plan['runtime_declarations'], $runtimeRecords), $records);
        foreach ($plan['pages'] as $index => $page) if (($expectedCandidatePages[$index]['whole_page_candidates'] ?? null) !== ($page['whole_page_candidates'] ?? null)) throw new InvalidArgumentException('Whole-page candidates are stale, duplicated, or detached from their canonical source pages.');
        foreach ($plan['assets'] as $asset) foreach ($asset['scopes'] ?? array() as $scope) if ('global' !== $scope['kind']) {
            $page = $pagesBySource[$scope['source_path']] ?? $archiveDocuments[$scope['source_path']] ?? null;
            if (!is_array($page) || $scope['kind'] !== ('post' === $page['post_type'] ? 'post' : 'page') || $scope['route_path'] !== trim($page['route']['path'], '/') || $scope['reconciliation_identity'] !== $page['reconciliation_identity'] || $scope['front_page'] !== ('/' === $page['route']['path'])) throw new InvalidArgumentException('A page asset scope does not match its canonical page.');
        }
        // A document position holds exactly one stylesheet instance, across
        // every resource, so each route replays one unambiguous sequence.
        $identities = array();
        foreach ($plan['assets'] as $asset) {
            if (isset($asset['stylesheet_instances']) && ('css' !== $asset['kind'] || !is_array($asset['stylesheet_instances']))) throw new InvalidArgumentException('Only stylesheet resources may declare ordered instances.');
            foreach ($asset['stylesheet_instances'] ?? array() as $instance) {
                if (!is_array($instance) || array('source_path', 'order', 'media', 'type') !== array_keys(array_diff_key($instance, array_flip(array(CaptureEvidence::DEVICE_STYLE, CaptureEvidence::SOURCE_MEDIA)))) || (!isset($pagesBySource[$instance['source_path'] ?? '']) && !isset($archiveDocuments[$instance['source_path'] ?? ''])) || !is_int($instance['order'] ?? null) || $instance['order'] < 0 || !is_string($instance['media'] ?? null) || !is_string($instance['type'] ?? null) || array_filter(self::stylesheetDeviceAttributes($instance), static fn(mixed $value): bool => !is_string($value))) throw new InvalidArgumentException('Stylesheet instance must identify a canonical document, order and conditions.');
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
        self::assertReporting($plan['reporting'], array_merge($pagePaths, array_fill_keys(array_filter(array_column($plan['templates'], 'source_path'), 'is_string'), true)), $tokens, $plan['diagnostics']);
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
                $boundTemplates[] = self::archiveTemplateSlug($entity);
                if ('author' === $entity['kind']) $boundTemplates[] = 'author';
            }
            if ('inline_shared_shell' === ($part['placement']['kind'] ?? null)) foreach ($plan['templates'] as $template) if (in_array($part['slug'], $template['shell_frame'] ?? array(), true)) $boundTemplates[] = $template['slug'];
            if ('inline_shared_shell' === ($part['placement']['kind'] ?? null)) foreach (array_merge($plan['taxonomy_entities'], $plan['author_entities']) as $entity) if (in_array($entity['archive']['source_path'], $part['placement']['source_paths'] ?? array(), true)) foreach ($plan['templates'] as $template) if ((self::archiveTemplateSlug($entity) === ($template['slug'] ?? null) || ('author' === $entity['kind'] && 'author' === ($template['slug'] ?? null) && $template['source_path'] === $entity['archive']['source_path'])) && str_contains($template['canonical_block_markup'], '"slug":"' . $part['slug'] . '"')) $boundTemplates[] = $template['slug'];
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
        foreach ( $plan['assets'] as $asset ) {
            $expectedConsumers = $asset['consumers'] ?? array();
            if (($asset['consumers'] ?? null) !== $expectedConsumers || ($asset['destination'] ?? null) !== AssetConsumers::destination($expectedConsumers) || ($asset['theme_write_reasons'] ?? null) !== self::themeWriteReasons($expectedConsumers, $asset['destination'])) throw new InvalidArgumentException('Asset consumers or destination are stale.');
            $target = $asset['target_path'];
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
            if ('page' === $documentKind && self::emptyRedirectDocument($document)) continue;
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
            $canonical = $this->routeLinks($markup, $document['source_path'], $routes);
            $target = $part ? 'parts/' . self::value($document, 'slug') . '.html' : self::value($document, 'source_path');
            $area = $part ? self::value($document, 'area', 'uncategorized') : null;
            $row = array('source_path' => $document['source_path'], 'slug' => self::value($document, 'slug'), 'title' => self::value($document, 'title'), 'post_type' => self::value((array) ($document['metadata'] ?? array()), 'post_type', 'page'), 'parent_source_path' => self::value((array) ($document['metadata'] ?? array()), 'parent_source_path'), 'entrypoint' => ! empty($document['entrypoint']), 'area' => $area, 'tag_name' => $part ? self::value($document, 'tag_name', ShellLandmarkPolicy::templatePartAreaTagName((string) $area)) : null, 'placement' => $part && is_array($document['placement'] ?? null) ? $document['placement'] : ($part ? array('kind' => 'unbound') : null), 'canonical_block_markup' => $canonical, '_projected_source_block_markup' => $document['block_markup'], 'metadata' => is_array($document['metadata'] ?? null) ? $document['metadata'] : array(), 'document_metadata' => $this->documentMetadata($document, $references, $routes), 'provenance' => is_array($document['provenance'] ?? null) ? $document['provenance'] : array(), 'reconciliation_identity' => self::identity($part ? 'template-part' : 'page', $document['source_path'], $target), 'content_hash' => self::contentHash($canonical));
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
            $rows[] = array_filter(array('source_path' => $asset['path'], 'target_path' => $target, 'token' => 'asset-' . substr(hash('sha256', $target), 0, 16), 'source' => self::value($asset, 'source'), 'source_role' => self::value($asset, 'source_role'), 'pipeline_sanitized' => $asset['pipeline_sanitized'] ?? null, 'kind' => self::value($asset, 'kind'), 'role' => self::value($asset, 'role'), 'stylesheet_placement' => self::value($asset, 'stylesheet_placement'), 'stylesheet_target' => 'css' === ($asset['kind'] ?? '') ? (self::value($asset, 'stylesheet_target') ?? 'both') : null, 'intent' => self::value($asset, 'intent'), 'mime_type' => self::value($asset, 'mime_type'), 'media' => self::value($asset, 'media'), 'placement' => self::value($asset, 'placement'), 'defer' => !empty($asset['defer']) ? true : null, 'async' => !empty($asset['async']) ? true : null, 'selector' => self::value($asset, 'selector'), 'references' => is_array($asset['references'] ?? null) || array() !== $priorReferences ? self::mergedReferences($priorReferences, is_array($asset['references'] ?? null) ? $asset['references'] : array()) : null, 'bytes' => (int) ($asset['bytes'] ?? 0), 'hash' => self::value($asset, 'hash'), 'content' => $assetContent, 'content_base64' => $asset['content_base64'] ?? null, 'payload_reference' => $reference, 'raw_sha256' => $reference['sha256'] ?? ($asset['raw_sha256'] ?? null), 'transport_sha256' => $transportHash, 'binary' => ! empty($asset['binary']), 'compilation' => is_array($asset['compilation'] ?? null) ? $asset['compilation'] : null, 'stylesheet_link_position' => is_int($asset['stylesheet_link_position'] ?? null) ? $asset['stylesheet_link_position'] : null, 'reconciliation_identity' => self::identity('asset', $asset['path'], $target), 'content_hash' => $contentHash), static fn(mixed $value): bool => null !== $value);
            $rowByTarget[strtolower($target)] = array_key_last($rows);
            if (isset($asset['inline_references'])) $rows[array_key_last($rows)]['inline_references'] = $asset['inline_references'];
            if (isset($asset['source_media']) && is_string($asset['source_media'])) $rows[array_key_last($rows)]['source_media'] = $asset['source_media'];
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
            return array($target, is_array($prior['references'] ?? null) ? $prior['references'] : array());
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

    /** @param array<int,array<string,mixed>> $assets @param array<int,array<string,mixed>> $pages @return array<int,array<string,mixed>> */
    private function scopeAssets(array $assets, array $pages): array
    {
        $pagesBySource = array_column($pages, null, 'source_path');
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
            if (isset($asset['inline_references'])) {
                $referencedPages = array();
                foreach ($asset['inline_references'] as $reference) if (isset($pagesBySource[$reference['source_path']])) $referencedPages[$reference['source_path']] = $pagesBySource[$reference['source_path']];
                $asset['scopes'] = array_values(array_map(static fn(array $page): array => array('kind' => 'post' === $page['post_type'] ? 'post' : 'page', 'source_path' => $page['source_path'], 'route_path' => trim($page['route']['path'], '/'), 'reconciliation_identity' => $page['reconciliation_identity'], 'front_page' => '/' === $page['route']['path']), $referencedPages));
                if (array() === $asset['scopes']) $asset['scopes'] = array(array('kind' => 'global'));
                continue;
            }
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
                // A stylesheet every canonical source document links is
                // site-wide author CSS: WordPress-native views (404, search,
                // archives) render the shared chrome it dresses, so they load
                // it through the route-flag fallback in source order, ahead of
                // the shared-chrome projections lifted from it. Route
                // documents keep their per-occurrence instance enqueues, and a
                // stylesheet only some documents link stays route-only.
                // Generated synthetic route pages capture nothing, so they do
                // not hold a site-wide stylesheet back.
                if (count($referencedPages) === count(array_filter($pagesBySource, static fn(array $page): bool => empty($page['synthetic'])))) {
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
                $sequence[] = array(2 * (int) ($reference['stylesheet_link_position'] ?? 0), $index, (string) ($reference['source_media'] ?? $reference['media'] ?? ''), (string) ($asset['type'] ?? ''), (int) ($reference['stylesheet_index'] ?? 0));
            }
            foreach ($inlineIndexesByPage[(string) ($page['source_path'] ?? '')] ?? array() as $index) {
                $sequence[] = array(2 * (int) $assets[$index]['stylesheet_link_position'], $index, (string) ($assets[$index]['media'] ?? ''), '', (int) ($assets[$index]['stylesheet_inline_order'] ?? $index));
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
    private static function positiveSelector(string $selector): string
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

    /**
     * Authored classes and ids in shared chrome markup, read from rendered HTML
     * and from block attributes (a dynamic block such as a navigation link keeps
     * its classes only in its comment). Engine and WordPress utility classes are
     * excluded: they are not the author's hooks and are shared with unrelated
     * content. An editor-anchor class stands for the source id it re-expresses.
     *
     * @return array{class:array<string,true>,id:array<string,true>}
     */
    private static function authoredChromeHooks(string $markup): array
    {
        $hooks = array('class' => array(), 'id' => array());
        foreach (self::authoredChromeElementHooks($markup) as $element) {
            $hooks['class'] += $element['class'];
            $hooks['id'] += $element['id'];
        }
        return $hooks;
    }

    /**
     * authoredChromeHooks() grouped by the element (HTML start tag or block
     * comment) that carries them.
     *
     * @return list<array{class:array<string,true>,id:array<string,true>}>
     */
    private static function authoredChromeElementHooks(string $markup): array
    {
        $elements = array();
        preg_match_all('/<[a-zA-Z][^\s>\/]*((?:[^>"]|"[^"]*")*)>/', $markup, $tags);
        foreach ($tags[1] as $attributes) {
            $classes = preg_match('/\sclass="([^"]*)"/', $attributes, $class) ? preg_split('/\s+/', html_entity_decode($class[1], ENT_QUOTES | ENT_HTML5)) : array();
            $ids = preg_match('/\sid="([^"]+)"/', $attributes, $id) ? array(html_entity_decode($id[1], ENT_QUOTES | ENT_HTML5)) : array();
            if (array() !== $classes || array() !== $ids) $elements[] = self::selectorHooks($classes ?: array(), $ids);
        }
        preg_match_all('/<!--\s*wp:[a-z0-9\/-]+\s+(\{.*?\})\s*\/?-->/s', $markup, $comments);
        foreach ($comments[1] as $json) {
            $attrs = json_decode($json, true);
            $classes = is_string($attrs['className'] ?? null) ? (preg_split('/\s+/', $attrs['className']) ?: array()) : array();
            $ids = is_string($attrs['anchor'] ?? null) ? array($attrs['anchor']) : array();
            if (array() !== $classes || array() !== $ids) $elements[] = self::selectorHooks($classes, $ids);
        }
        return $elements;
    }

    /**
     * @param array<int,mixed> $classes
     * @param array<int,mixed> $ids
     * @return array{class:array<string,true>,id:array<string,true>}
     */
    private static function selectorHooks(array $classes, array $ids): array
    {
        $hooks = array('class' => array(), 'id' => array());
        foreach ($ids as $id) if (is_string($id) && '' !== $id) $hooks['id'][$id] = true;
        foreach ($classes as $class) {
            if (!is_string($class) || '' === $class) continue;
            $hook = self::selectorHook('.', $class);
            if (null !== $hook) $hooks[$hook[0]][$hook[1]] = true;
        }
        return $hooks;
    }

    /** Page markup with its `<header>` and `<footer>` landmarks removed: the route's own content. */
    private static function withoutLandmarks(string $markup): string
    {
        $content = '';
        $depth = 0;
        $offset = 0;
        preg_match_all('/<(\/?)(header|footer)\b[^>]*>/i', $markup, $tags, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($tags as $tag) {
            $closing = '/' === $tag[1][0];
            if (!$closing && 0 === $depth) $content .= substr($markup, $offset, $tag[0][1] - $offset);
            $depth = max(0, $depth + ($closing ? -1 : 1));
            if ($closing && 0 === $depth) $offset = $tag[0][1] + strlen($tag[0][0]);
        }
        return 0 === $depth ? $content . substr($markup, $offset) : $content;
    }

    /**
     * A shared shell part is rendered by the template, outside the ancestors it
     * sat under in the source. A selector that reaches chrome through a leading
     * run of those ancestors (`#page-root .menu .item`) also accepts the
     * template part wrapper in their place. The wrapper arm sits in `:where()`,
     * so the rule keeps the source specificity, and the original ancestors
     * still match where they remain.
     *
     * @param array{class:array<string,true>,id:array<string,true>} $context
     * @param array<int,string> $roots
     */
    private static function reanchoredDetachedContextSelector(string $selector, array $context, array $roots): string
    {
        if (array() === $roots) return $selector;
        $compounds = array();
        $current = '';
        $depth = 0;
        $length = strlen($selector);
        for ($i = 0; $i < $length; ++$i) {
            $char = $selector[$i];
            if ('(' === $char || '[' === $char) ++$depth;
            elseif (')' === $char || ']' === $char) --$depth;
            if (0 === $depth && (ctype_space($char) || '>' === $char || '+' === $char || '~' === $char)) {
                if ('' !== $current) $compounds[] = $current;
                if (!ctype_space($char)) $compounds[] = $char;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        if ('' !== $current) $compounds[] = $current;
        // A capture scopes a responsive variant's rules by that variant's root
        // (`:where(.data-blocks-engine-capture-mobile-document) #page-root #footer`). The
        // variant root is not an ancestor the chrome was hoisted out of: the
        // part carries its own variant group. Keep the scope in front of the
        // run being re-anchored, and require it on the wrapper arm, so the
        // rule keeps reaching only that variant's copy of the chrome.
        $scope = array();
        foreach ($compounds as $index => $compound) {
            if (!isset($compounds[$index + 1]) || in_array($compounds[$index + 1], array('>', '+', '~'), true)) break;
            $variantClasses = self::responsiveVariantScopeClasses($compound);
            if (null === $variantClasses) break;
            $scope[] = $variantClasses;
        }
        $first = count($scope);
        $run = $first;
        foreach ($compounds as $index => $compound) {
            if ($index < $first) continue;
            if (!isset($compounds[$index + 1]) || in_array($compounds[$index + 1], array('>', '+', '~'), true)) break;
            // The compound's positive hooks, including those the engine moved
            // into `:where()`/`:is()` when it rewrote an id for editor parity.
            $hooks = str_replace(array(':where(', ':is(', ')'), '', self::positiveSelector($compound));
            if (!preg_match('/^(?:[#.][_a-zA-Z][\w-]*)+$/', $hooks)) break;
            preg_match_all('/([#.])([\w-]+)/', $hooks, $tokens, PREG_SET_ORDER);
            $named = false;
            foreach ($tokens as $token) {
                $hook = self::selectorHook($token[1], $token[2]);
                if (null === $hook) continue;
                if (!isset($context[$hook[0]][$hook[1]])) break 2;
                $named = true;
            }
            if (!$named) break;
            $run = $index + 1;
        }
        if ($first === $run) return $selector;
        $wrappers = array_map(static fn (string $root): string => ':has(> #' . CssIdent::escape($root) . ')', $roots);
        if (array() !== $scope) {
            // The variant group is the part's own child wrapper (`.data-blocks-engine-capture-mobile-document > #footer`),
            // or an ancestor of the wrapper that holds the chrome.
            $scopeClass = implode('', array_map(static fn (string $class): string => '.' . CssIdent::escape($class), array_merge(...$scope)));
            $wrappers = array_merge(
                array_map(static fn (string $wrapper): string => $scopeClass . $wrapper, $wrappers),
                array_map(static fn (string $wrapper): string => $scopeClass . ' ' . $wrapper, $wrappers)
            );
        }
        return ':is(' . implode(' ', array_slice($compounds, 0, $run)) . ',:where(' . implode(',', $wrappers) . ')) ' . implode(' ', array_slice($compounds, $run));
    }

    /**
     * The responsive variant root classes a compound consists of, such as
     * `:where(.data-blocks-engine-capture-mobile-document)`; null for any other compound.
     *
     * @return list<string>|null
     */
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

    private static function responsiveVariantScopeClasses(string $compound): ?array
    {
        $hooks = str_replace(array(':where(', ':is(', ')'), '', self::positiveSelector($compound));
        if (!preg_match('/^(?:\.[_a-zA-Z][\w-]*)+$/', $hooks)) return null;
        $classes = explode('.', ltrim($hooks, '.'));
        foreach ($classes as $class) if (!ShellExtraction::isResponsiveVariantClass($class)) return null;
        return $classes;
    }

    /**
     * Whether a selector is written for shared chrome: it names at least one of
     * the chrome's own authored classes or ids, and every class or id it names
     * belongs to that chrome or to the ancestors it was hoisted out of. A
     * selector that also names route-owned hooks stays with the route.
     *
     * @param array{class:array<string,true>,id:array<string,true>} $authored
     * @param array{class:array<string,true>,id:array<string,true>} $context
     */
    private static function selectorTargetsAuthoredChrome(string $selector, array $authored, array $context): bool
    {
        $positive = self::positiveSelector($selector);
        if (str_contains($positive, '\\')) return false;
        if (!preg_match_all('/([.#])(-?[_a-zA-Z][\w-]*)/', $positive, $matches, PREG_SET_ORDER)) return false;
        $owned = false;
        foreach ($matches as $match) {
            $hook = self::selectorHook($match[1], $match[2]);
            if (null === $hook) continue;
            if (isset($authored[$hook[0]][$hook[1]])) {
                $owned = true;
                continue;
            }
            if (!isset($context[$hook[0]][$hook[1]])) return false;
        }
        return $owned;
    }

    /**
     * The hook a selector token names, keyed like selectorHooks(): an
     * editor-anchor class is its source id, and utility classes are neutral.
     *
     * @return array{0:'class'|'id',1:string}|null
     */
    private static function selectorHook(string $sigil, string $name): ?array
    {
        if ('#' === $sigil) return array('id', $name);
        // A responsive variant root scopes rules to one variant; it is neither
        // chrome nor an ancestor the chrome was hoisted out of.
        if (ShellExtraction::isResponsiveVariantClass($name)) return null;
        $anchorId = EngineMarker::editorAnchorId($name);
        if (null !== $anchorId) return array('id', $anchorId);
        return 1 === preg_match('/^(?:wp-|blocks-engine-|has-|is-|be-)/', $name) ? null : array('class', $name);
    }

    /**
     * Whether a selector targets a shared-chrome generated class or rich-text
     * marker. A class named only inside `:not(...)` is an exclusion: the rule
     * still applies to the page's own elements (for example
     * `.text-sm:not(:where(.control))`), so it stays in the page stylesheet in
     * its source cascade position.
     *
     * Document-namespaced rich-text markers are rewritten onto attribute
     * selectors (`mark[style*="--blocks-engine-richtext-marker:…"]`), not class
     * selectors. Identity comparison strips those markers so pages still
     * cluster; the matching rules must follow the canonical marker onto the
     * shared part instead of remaining page-scoped.
     *
     * @param array{patterns:list<string>,richtext:list<string>} $matcher from generatedClassMatcher()
     */
    private static function selectorTargetsGeneratedClass(string $selector, array $matcher): bool
    {
        $positive = self::positiveSelector($selector);
        foreach ($matcher['patterns'] as $pattern) {
            if (1 === preg_match($pattern, $positive)) return true;
        }
        foreach ($matcher['richtext'] as $class) {
            if (str_contains($positive, $class)) return true;
        }
        return false;
    }

    /** Projected native menu state survives even when no captured item is current. */
    private static function selectorTargetsRuntimeNavigationState(string $selector): bool
    {
        $positive = self::positiveSelector($selector);
        return 1 === preg_match('/\\.wp-block-navigation(?![\\w-])/', $positive)
            && 1 === preg_match('/\\[\\s*aria-current\\b|\\.current-menu-item(?![\\w-])/', $positive);
    }

    /**
     * Compile the generated-class test once per projection. Every selector of
     * the site stylesheet is checked against every shared-part class, so one
     * regex per class per selector grows with rules x classes; a few
     * alternation patterns keep it linear in the selector count.
     *
     * @param array<string,true> $classes
     * @return array{patterns:list<string>,richtext:list<string>}
     */
    private static function generatedClassMatcher(array $classes): array
    {
        $alternatives = array();
        $richtext = array();
        foreach (array_keys($classes) as $class) {
            $class = (string) $class;
            $alternatives[] = CssIdent::classSelectorRegex($class);
            if (1 === preg_match('/^blocks-engine-richtext-[a-f0-9]+-\d+$/', $class)) $richtext[] = $class;
        }
        $patterns = array();
        foreach (array_chunk($alternatives, 200) as $chunk) {
            $patterns[] = '/(?:' . implode('|', $chunk) . ')(?![\\w-])/';
        }

        return array('patterns' => $patterns, 'richtext' => $richtext);
    }

    /**
     * A header part renders before post-content, yet page content that preceded
     * it in the source (a fixed page background) now follows it and paints over
     * it wherever both are positioned without z-index. Restore the source paint
     * order at zero specificity, so a z-index the author declared still wins and
     * a static root is unaffected. Author rules that reached the part through
     * its detached ancestors are re-anchored by projectSharedChromeStylesheets.
     *
     * @param array<int,array<string,mixed>> $assets
     * @param array<int,array<string,mixed>> $parts
     * @return array<int,array<string,mixed>>
     */
    private static function projectDetachedChromePaintOrder(array $assets, array $parts, array $pages = array()): array
    {
        $rules = '';
        foreach ($parts as $part) {
            if ('shared_shell' !== ($part['placement']['kind'] ?? null) || 'header' !== ($part['area'] ?? null) || empty($part['ancestor_context']['preceded'])) continue;
            foreach (self::partRootAnchors((string) ($part['canonical_block_markup'] ?? '')) as $anchor) $rules .= ':where(#' . CssIdent::escape($anchor) . '){z-index:1}';
        }
        $rules .= self::detachedChromeBackdropRules($parts, $assets, $pages);
        $template = null;
        foreach ($assets as $asset) {
            if ('css' === ($asset['kind'] ?? null) && is_string($asset['content'] ?? null) && '' !== trim($asset['content'])) {
                $template = $asset;
                break;
            }
        }
        if (null === $template || '' === $rules) return $assets;
        $context = $template;
        $context['path'] = 'assets/css/shared-chrome-context-' . substr(hash('sha256', $rules), 0, 16) . '.css';
        $context['target_path'] = $context['path'];
        $context['reference_origin'] = (string) ($template['source_path'] ?? $template['path'] ?? '');
        $context['source_path'] = (string) ($template['source_path'] ?? $template['path'] ?? '') . '.shared-chrome-context';
        $context = self::withRewrittenTextContent($context, $rules);
        $context['scopes'] = array(array('kind' => 'global'));
        // The paint-order context is route-independent and paints after every
        // authored stylesheet; it is not an occurrence of its template's link.
        $context['stylesheet_placement'] = 'after-author';
        unset($context['stylesheet_instances'], $context['stylesheet_parent'], $context['stylesheet_inline_order']);
        $context['token'] = 'asset-' . substr(hash('sha256', $context['target_path']), 0, 16);
        $context['reconciliation_identity'] = self::identity('asset', $context['source_path'], $context['target_path']);
        $assets[] = $context;
        return $assets;
    }

    /**
     * Page content that preceded the chrome inside its layout ancestors (a
     * page background layer) was sized, in the source, to an ancestor box that
     * also held the chrome. That ancestor stays in post-content while the
     * chrome renders from the template, so the backdrop now starts below the
     * header and the band behind the chrome shows the bare canvas.
     *
     * In WordPress the box that spans the chrome and the content is the
     * template root. It takes over the containing-block role of the ancestors
     * that held the backdrop (only on the front end, where the chrome is
     * outside them), and each chrome root stays positioned so it still paints
     * above that backdrop, as it did inside the source ancestors. Only the
     * ancestors every detached part left behind are moved: their source box
     * spanned all of the chrome, as the template root does. When a part root
     * has no anchor it cannot be kept above the backdrop, so nothing moves.
     * Only a positioned ancestor is a containing block to hand over, and one
     * whose own box differs from the template root's (a width cap, an inset,
     * clipping overflow or a stacking z-index, under any media condition)
     * keeps its role: the template root would stand in for a different box.
     *
     * @param array<int,array<string,mixed>> $parts
     * @param array<int,array<string,mixed>> $assets
     * @param array<int,array<string,mixed>> $pages
     */
    private static function detachedChromeBackdropRules(array $parts, array $assets = array(), array $pages = array()): string
    {
        $detached = array_values(array_filter($parts, static fn(array $part): bool => 'shared_shell' === ($part['placement']['kind'] ?? null) && is_array($part['ancestor_context'] ?? null) && in_array($part['area'] ?? null, array('header', 'footer'), true)));
        if (array() === $detached) return '';
        // The backdrop is what precedes the first chrome. Before a footer, the
        // route's own content also precedes it, so a detached header decides.
        $first = array_values(array_filter($detached, static fn(array $part): bool => 'header' === $part['area'])) ?: $detached;
        $backdrop = array();
        foreach ($first as $part) foreach ((array) ($part['ancestor_context']['backdrop_ids'] ?? array()) as $id) if (is_string($id) && '' !== $id) $backdrop[$id] = true;
        if (array() === $backdrop) return '';
        $roots = array();
        foreach ($detached as $part) {
            $markup = (string) ($part['canonical_block_markup'] ?? '');
            $anchors = self::partRootAnchors($markup);
            if (array() === $anchors) return '';
            array_push($roots, ...$anchors);
            // An ancestor the part never sat under did not span it in the source.
            $enclosing = self::selectorHooks((array) ($part['ancestor_context']['classes'] ?? array()), (array) ($part['ancestor_context']['ids'] ?? array()))['id'];
            foreach ((array) ($part['ancestor_context']['backdrop_ids'] ?? array()) as $id) if (is_string($id)) $enclosing[$id] = true;
            foreach (array_keys($backdrop) as $id) if (!isset($enclosing[(string) $id])) unset($backdrop[$id]);
        }
        foreach (array_keys($backdrop) as $id) if (!self::sharesTemplateRootBox((string) $id, $assets, $pages)) unset($backdrop[$id]);
        if (array() === $backdrop) return '';
        $escape = static fn(string $id): string => '#' . CssIdent::escape($id);
        return ':where(.wp-site-blocks){position:relative}'
            . implode(',', array_map(static fn(int|string $id): string => '.wp-site-blocks ' . $escape((string) $id), array_keys($backdrop))) . '{position:static}'
            . ':where(' . implode(',', array_map($escape, array_values(array_unique($roots)))) . '){position:relative}';
    }

    private const BOX_PROPERTIES = '/(?:^|[;{\s])(?:(?:max-)?width|z-index|overflow(?:-[xy])?|inset|top|right|bottom|left)\s*:/i';

    /**
     * Whether the element with `$id`, as rendered in a page, is positioned by
     * an author rule or its inline style, and no rule (under any media
     * condition) gives it a box the template root does not share: a width or
     * max-width other than full, a non-zero inset, a z-index, or overflow
     * other than visible. A rule the matcher cannot model counts against it,
     * and so does an element that cannot be found.
     *
     * @param array<int,array<string,mixed>> $assets
     * @param array<int,array<string,mixed>> $pages
     */
    private static function sharesTemplateRootBox(string $id, array $assets, array $pages): bool
    {
        $element = null;
        foreach ($pages as $page) {
            $markup = (string) ($page['canonical_block_markup'] ?? '');
            if (!str_contains($markup, 'id="' . htmlspecialchars($id, ENT_QUOTES) . '"')) continue;
            $document = new \DOMDocument();
            $document->loadHTML('<?xml encoding="UTF-8"><html><body><div class="wp-site-blocks"><div class="entry-content wp-block-post-content">' . $markup . '</div></div></body></html>', LIBXML_NOERROR | LIBXML_NOWARNING);
            foreach ((new \DOMXPath($document))->query('//*[@id]') ?: array() as $node) {
                if ($node instanceof \DOMElement && $id === $node->getAttribute('id')) {
                    $element = $node;
                    break 2;
                }
            }
        }
        if (!$element instanceof \DOMElement) return false;
        $inline = $element->getAttribute('style');
        if (self::declaresDistinctBox($inline)) return false;
        $positioned = self::declaresPosition($inline);
        // Rules can reach the element through its id or any of its classes
        // (an editor anchor, an inline-geometry class, an authored class).
        $hooks = array($id);
        foreach (preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array() as $class) if ('' !== $class && 1 !== preg_match('/^(?:wp-block-group|is-layout-|wp-container-|wp-block-group-is-layout-)/', $class)) $hooks[] = $class;
        $distinct = false;
        $transformer = new CssStylesheetTransformer();
        foreach ($assets as $asset) {
            if ($distinct) break;
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null)) continue;
            $transformer->visitStyleRules($asset['content'], static function (string $prelude, string $body) use (&$distinct, &$positioned, $element, $hooks): void {
                if ($distinct) return;
                $boxed = 1 === preg_match(self::BOX_PROPERTIES, $body) && self::declaresDistinctBox($body);
                $places = !$positioned && self::declaresPosition($body);
                if (!$boxed && !$places) return;
                $named = false;
                foreach ($hooks as $hook) if (str_contains($prelude, $hook)) $named = true;
                if (!$named) return;
                foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array($prelude) as $selector) {
                    $match = CssSelectorMatcher::matches($element, CssSelectorMatcher::parse(trim($selector)), true);
                    // A selector the matcher cannot model may still apply when
                    // its subject names the element.
                    if ($boxed && ($match['matches'] || (!$match['supported'] && self::subjectNamesAny(trim($selector), $hooks)))) {
                        $distinct = true;
                        return;
                    }
                    if ($places && $match['matches']) $positioned = true;
                }
            });
        }
        return $positioned && !$distinct;
    }

    /**
     * Whether a selector's rightmost compound names one of `$hooks`.
     *
     * @param list<string> $hooks
     */
    private static function subjectNamesAny(string $selector, array $hooks): bool
    {
        $depth = 0;
        $start = 0;
        $length = strlen($selector);
        for ($i = 0; $i < $length; ++$i) {
            $char = $selector[$i];
            if ('(' === $char || '[' === $char) ++$depth;
            elseif (')' === $char || ']' === $char) --$depth;
            elseif (0 === $depth && (ctype_space($char) || '>' === $char || '+' === $char || '~' === $char)) $start = $i + 1;
        }
        $subject = substr($selector, $start);
        foreach ($hooks as $hook) if (1 === preg_match('/[#.]' . preg_quote($hook, '/') . '(?![\w-])/', $subject)) return true;
        return false;
    }

    private static function declaresPosition(string $body): bool
    {
        foreach (CssRuleAnalyzer::declarations($body, array('position')) as $declaration) {
            if (1 !== preg_match('/^(?:static|initial|unset|inherit|revert|revert-layer)(?:\s*!important)?$/i', trim($declaration['value']))) return true;
        }
        return false;
    }

    private static function declaresDistinctBox(string $body): bool
    {
        if ('' === trim($body) || 1 !== preg_match(self::BOX_PROPERTIES, ';' . $body)) return false;
        $neutral = '(?:initial|unset|inherit|revert|revert-layer)';
        foreach (CssRuleAnalyzer::declarations($body, array('width', 'max-width', 'z-index', 'overflow', 'overflow-x', 'overflow-y', 'inset', 'top', 'right', 'bottom', 'left')) as $declaration) {
            $value = strtolower(trim(preg_replace('/\s*!important\s*$/i', '', $declaration['value']) ?? ''));
            $allowed = match ($declaration['name']) {
                'width' => '/^(?:auto|100%|' . $neutral . ')$/',
                'max-width' => '/^(?:none|100%|' . $neutral . ')$/',
                'z-index' => '/^(?:auto|' . $neutral . ')$/',
                'overflow', 'overflow-x', 'overflow-y' => '/^(?:visible|' . $neutral . ')(?:\s+visible)?$/',
                default => '/^(?:(?:0(?:\.0+)?[a-z%]*|auto)(?:\s+|$))+$|^' . $neutral . '$/',
            };
            if (1 !== preg_match($allowed, $value)) return true;
        }
        return false;
    }

    /**
     * The anchors of a shared part's root elements, never of a descendant.
     *
     * The root is the part's single top-level block. A viewport-partitioned
     * part instead holds one visibility group per variant, each wrapping that
     * variant's own root. A root without an anchor contributes nothing: an
     * anchored descendant (a Wix header's positioned background layer, say)
     * is a different element, and lifting it reorders the part's own layers.
     *
     * @return list<string>
     */
    private static function partRootAnchors(string $markup): array
    {
        $roots = self::topLevelBlocks($markup, 0, strlen($markup));
        $partitioned = array() !== $roots && array() === array_filter($roots, static fn(array $block): bool => !self::isVariantVisibilityGroup($block['attrs']));
        if (!$partitioned) {
            if (1 !== count($roots)) return array();
        } else {
            $inner = array();
            foreach ($roots as $group) {
                $children = self::topLevelBlocks($markup, $group['inner_offset'], $group['inner_end']);
                if (1 !== count($children)) return array();
                $inner[] = $children[0];
            }
            $roots = $inner;
        }
        $anchors = array();
        foreach ($roots as $root) {
            $anchor = $root['attrs']['anchor'] ?? null;
            if (is_string($anchor) && '' !== $anchor) $anchors[$anchor] = true;
        }
        return array_keys($anchors);
    }

    /** @param array<string,mixed> $attrs */
    private static function isVariantVisibilityGroup(array $attrs): bool
    {
        return 1 === count($attrs) && is_string($attrs['className'] ?? null) && ShellExtraction::isResponsiveVariantClass($attrs['className']);
    }

    /**
     * Blocks whose delimiters sit directly in `$markup[$start, $end)`.
     *
     * @return list<array{attrs:array<string,mixed>,inner_offset:int,inner_end:int}>
     */
    private static function topLevelBlocks(string $markup, int $start, int $end): array
    {
        $segment = substr($markup, $start, $end - $start);
        if (!preg_match_all('/<!--\s*(\/?)wp:[a-z0-9\/_-]+(?:\s+(\{.*?\}))?\s*(\/?)-->/s', $segment, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return array();
        $blocks = array();
        $depth = 0;
        $open = null;
        foreach ($matches as $match) {
            $closing = '/' === $match[1][0];
            $selfClosing = '/' === ($match[3][0] ?? '');
            if ($closing) {
                --$depth;
                if (0 === $depth && null !== $open) {
                    $blocks[] = $open + array('inner_end' => $start + $match[0][1]);
                    $open = null;
                }
                if ($depth < 0) return array();
                continue;
            }
            if (0 === $depth) {
                $attrs = isset($match[2][0]) && '' !== $match[2][0] && -1 !== $match[2][1] ? json_decode($match[2][0], true) : array();
                $attrs = is_array($attrs) ? $attrs : array();
                if ($selfClosing) {
                    $blocks[] = array('attrs' => $attrs, 'inner_offset' => $start + $match[0][1] + strlen($match[0][0]), 'inner_end' => $start + $match[0][1] + strlen($match[0][0]));
                    continue;
                }
                $open = array('attrs' => $attrs, 'inner_offset' => $start + $match[0][1] + strlen($match[0][0]));
            }
            if (!$selfClosing) ++$depth;
        }
        return 0 === $depth ? $blocks : array();
    }

    /**
     * Extract only generated rules needed by shared template parts. The source
     * stylesheet can contain both those projected rules and route-owned rules;
     * promoting the whole payload changes the cascade on unrelated pages.
     *
     * Cascade rule: the shared-chrome asset is enqueued after the route
     * stylesheet, so an element's competing rules must not be split across the
     * two. A chrome element that carries any hook whose rules stay on the route
     * (one route content also uses, or one no lifted selector can name) keeps
     * all of its authored hooks on the route, so its rules stay in one
     * stylesheet in source order on every route.
     *
     * @param array<int,array<string,mixed>> $assets
     * @param array<int,array<string,mixed>> $parts
     * @param list<array<string,string>> $collisions Receives one row per projection given a suffixed target.
     * @return array<int,array<string,mixed>>
     */
    private static function projectSharedChromeStylesheets(array $assets, array $parts, array $pages = array(), ?AssetReferenceCanonicalizer $references = null, array &$collisions = array(), ?array $liveRichTextMarkers = null): array
    {
        $classes = array();
        $controlRoutes = array();
        $routeIdentities = array();
        foreach ($pages as $page) {
            $source = (string) $page['source_path'];
            $routeIdentities[$source] = self::identity('page', $source, $source);
        }
        $routeClasses = DocumentRootContext::routeClasses(array_values($routeIdentities));
        $authored = array('class' => array(), 'id' => array());
        $context = array('class' => array(), 'id' => array());
        $detachedRoots = array();
        $chromeElements = array();
        $hasSharedNavigation = false;
        foreach ($parts as $part) {
            if (!in_array($part['placement']['kind'] ?? '', array('shared_shell', 'inline_shared_shell'), true)) continue;
            // A one-page inline part still lives under its authored page layout.
            // Its ancestor-scoped rules belong to that page, not global chrome.
            if ('inline_shared_shell' === ($part['placement']['kind'] ?? '') && 1 === count($part['placement']['source_paths'] ?? array())) continue;
            $markup = (string) ($part['canonical_block_markup'] ?? '');
            // A deduplicated control keeps the chosen capture's marker, but
            // renders on every route adopting the part, not just that capture.
            $sources = $part['placement']['source_paths'] ?? array_keys($part['provenance']['sources'] ?? array());
            preg_match_all('/\bblocks-engine-control-[a-f0-9]{12}-[0-9]+\b/', $markup, $controls);
            foreach ($controls[0] as $control) {
                foreach ($sources as $source) {
                    if (isset($routeIdentities[$source])) $controlRoutes[$control][$routeClasses[$routeIdentities[$source]]] = true;
                }
            }
            $hasSharedNavigation = $hasSharedNavigation || 1 === preg_match('/<!--\s*wp:navigation(?:\s|\/|-->)/', $markup);
            // A source-tag marker names every element of its tag on every page
            // under any author CSS, not this chrome: a rule reaching it only
            // through such a marker (`p{…}` from one page's own stylesheet)
            // must stay where its stylesheet applies rather than become global.
            // Each page's own type rules already reach the chrome's elements.
            if (preg_match_all(self::GENERATED_CLASS_PATTERN, $markup, $matches)) foreach ($matches[0] as $class) if (!str_starts_with((string) EngineMarker::markerKind($class), 'source-')) $classes[$class] = true;
            // Geometry carriers are content-addressed instance CSS, not document
            // markers. Structural shell dedupe can choose a different carrier
            // from the adopting page's capture; its rules must follow the part
            // just like generated attribute and semantic marker rules do.
            if (preg_match_all('/\bbe-inline-geometry-[a-f0-9]{16}(?:-[a-f0-9]{16})?\b/', $markup, $matches)) foreach ($matches[0] as $class) $classes[$class] = true;
            // The chrome's own authored hooks: rules written for them styled it
            // on every page, so they follow it into the shared part.
            foreach (self::authoredChromeElementHooks($markup) as $element) {
                $authored['class'] += $element['class'];
                $authored['id'] += $element['id'];
                $chromeElements[] = $element;
            }
            $ancestors = self::selectorHooks((array) ($part['ancestor_context']['classes'] ?? array()), (array) ($part['ancestor_context']['ids'] ?? array()));
            $context['class'] += $ancestors['class'];
            $context['id'] += $ancestors['id'];
            if ('shared_shell' === ($part['placement']['kind'] ?? null) && is_array($part['ancestor_context'] ?? null)) {
                foreach (self::partRootAnchors($markup) as $root) $detachedRoots[$root] = true;
            }
        }
        // Hooks that route content also uses belong to that content too; rules
        // for them stay with the route instead of becoming global chrome rules.
        $routeHooks = array('class' => array(), 'id' => array());
        foreach ($pages as $page) {
            foreach (self::authoredChromeHooks(self::withoutLandmarks((string) ($page['canonical_block_markup'] ?? ''))) as $kind => $names) {
                $routeHooks[$kind] += $names;
            }
        }
        // Rules for a hook no lifted selector can name (an escaped class) stay
        // with the route as well.
        foreach ($authored as $kind => $names) {
            foreach (array_keys($names) as $name) {
                $only = array('class' => array(), 'id' => array());
                $only[$kind][$name] = true;
                if (!self::selectorTargetsAuthoredChrome(('id' === $kind ? '#' : '.') . CssIdent::escape((string) $name), $only, array('class' => array(), 'id' => array()))) $routeHooks[$kind][$name] = true;
            }
        }
        // Keep each chrome element's rules in one stylesheet: an element with any
        // route-kept hook keeps all of its hooks on the route (to a fixed point).
        do {
            $grown = false;
            foreach ($chromeElements as $element) {
                if (array() === array_intersect_key($element['class'], $routeHooks['class']) && array() === array_intersect_key($element['id'], $routeHooks['id'])) continue;
                foreach (array('class', 'id') as $kind) {
                    if (array() === array_diff_key($element[$kind], $routeHooks[$kind])) continue;
                    $routeHooks[$kind] += $element[$kind];
                    $grown = true;
                }
            }
        } while ($grown);
        foreach ($routeHooks as $kind => $names) $authored[$kind] = array_diff_key($authored[$kind], $names);
        if (!$hasSharedNavigation && array() === $classes && array() === $authored['class'] && array() === $authored['id']) return $assets;
        $classMatcher = self::generatedClassMatcher($classes);
        // Current state is assigned by core on the served route, not saved in
        // the shared part. Projected selectors using only native navigation
        // hooks therefore have no authored/generated class to claim them.
        // Lift them with the menu rather than the capture's matching leaf.
        $targetsChrome = static fn (string $selector): bool => self::selectorTargetsGeneratedClass($selector, $classMatcher)
            || self::selectorTargetsAuthoredChrome($selector, $authored, $context)
            || ($hasSharedNavigation && self::selectorTargetsRuntimeNavigationState($selector));
        $reanchor = static function (string $selector) use ($context, $detachedRoots, $controlRoutes): string {
            $routes = array();
            foreach ($controlRoutes as $control => $classes) {
                if (str_contains($selector, '.' . $control . ')')) $routes += $classes;
            }
            if ($routes) {
                // Rewrite only compiler-owned route gates. Authored root state
                // and all specificity shims remain exactly as projected.
                $scope = ':where(:root:is(.' . implode(',.', array_keys($routes)) . '))';
                $selector = preg_replace('/:where\(:root(?::(?:is|not)\((?:\.be-r-[a-f0-9]+,?)+\)|\.be-r(?::not\((?:\.be-r-[a-f0-9]+,?)+\))?)\)/', $scope, $selector) ?? $selector;
            }
            return self::reanchoredDetachedContextSelector(trim($selector), $context, array_keys($detachedRoots));
        };

        // Lift chrome once per occurrence media contract, while retaining one
        // authored payload resource. Route instances still own their conditions.
        $chromeAssets = array();
        foreach ($assets as $asset) {
            $media = array();
            foreach ($asset['inline_references'] ?? array() as $reference) $media[(string) ($reference['source_media'] ?? $reference['media'] ?? '')] = true;
            if (count($media) > 1) {
                foreach (array_keys($media) as $condition) {
                    $copy = $asset;
                    $copy['media'] = $condition;
                    $copy['_inline_media_projection'] = array_key_exists('media', $asset) ? array($asset['media']) : array();
                    $chromeAssets[] = $copy;
                }
            } else {
                if (1 === count($media)) $asset['media'] = (string) array_key_first($media);
                $chromeAssets[] = $asset;
            }
        }
        $projected = array();
        $emittedShared = array();
        // A per-media copy exists only to lift chrome under its condition; the
        // authored payload it came from is still one asset with one target.
        $keptInline = array();
        $keep = static function (array $asset) use (&$projected, &$keptInline): void {
            if (isset($asset['_inline_media_projection'])) {
                if (isset($keptInline[$asset['source_path']])) return;
                $keptInline[$asset['source_path']] = true;
                $media = $asset['_inline_media_projection'];
                unset($asset['_inline_media_projection'], $asset['media']);
                if (array() !== $media) $asset['media'] = $media[0];
            }
            $projected[] = $asset;
        };
        foreach ($chromeAssets as $asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null) || '' === trim($asset['content']) || !StylesheetActivation::active($asset)) {
                $keep($asset);
                continue;
            }
            $matched = false;
            $unparseable = false;
            $shared = (new CssStylesheetTransformer())->transformStyleRules(
                $asset['content'],
                static function (string $prelude, string $body) use ($targetsChrome, $reanchor, $liveRichTextMarkers, &$matched, &$unparseable): string {
                    $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
                    if (null === $selectors) {
                        $unparseable = true;
                        return '';
                    }
                    $kept = array_values(array_filter($selectors, $targetsChrome));
                    if (array() === $kept) return '';
                    if (null !== $liveRichTextMarkers) {
                        $kept = array_values(array_filter(array_map(static fn (string $selector): ?string => self::withoutDeadRichTextMarkers($selector, $liveRichTextMarkers), $kept), 'is_string'));
                        // Every selector named only replaced elements: the rule matches nothing.
                        if (array() === $kept) return '';
                    }
                    $matched = true;
                    return implode(',', array_map($reanchor, $kept)) . '{' . $body . '}';
                }
            );
            if (!$matched || $unparseable) {
                $keep($asset);
                continue;
            }
            $route = (new CssStylesheetTransformer())->transformStyleRules(
                $asset['content'],
                static function (string $prelude, string $body) use ($targetsChrome): string {
                    $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
                    if (null === $selectors) return $prelude . '{' . $body . '}';
                    $kept = array_values(array_filter($selectors, static fn (string $selector): bool => ! $targetsChrome($selector)));
                    return array() === $kept ? '' : implode(',', $kept) . '{' . $body . '}';
                }
            );
            $sharedAsset = $asset;
            // Address the target by what is enqueued, not only the text: the same
            // rules under another media condition or target are another asset.
            $sharedAsset['path'] = 'assets/css/shared-chrome-' . substr(hash('sha256', self::sharedChromeContract($asset) . "\0" . $shared), 0, 16) . '.css';
            $sharedAsset['target_path'] = $sharedAsset['path'];
            $sharedAsset['source_path'] = (string) ($asset['source_path'] ?? $asset['path'] ?? '') . '.shared-chrome';
            // Relative url() references still resolve against the stylesheet the
            // rules were projected from, not the synthetic shared-chrome identity.
            $sharedAsset['reference_origin'] = (string) ($asset['source_path'] ?? $asset['path'] ?? '');
            $sharedAsset = self::withRewrittenTextContent($sharedAsset, $shared);
            $sharedAsset['scopes'] = array(array('kind' => 'global'));
            $sharedAsset['token'] = 'asset-' . substr(hash('sha256', $sharedAsset['target_path']), 0, 16);
            $sharedAsset['reconciliation_identity'] = self::identity('asset', $sharedAsset['source_path'], $sharedAsset['target_path']);
            // Global chrome is not an occurrence of the stylesheet it was lifted
            // from; placeSharedStylesheetProjections() gives it a slot on every
            // route, next to whichever contributing stylesheet that route applies.
            unset($sharedAsset['inline_references'], $sharedAsset['stylesheet_instances'], $sharedAsset['stylesheet_parent'], $sharedAsset['stylesheet_inline_order']);
            $sharedAsset['_stylesheet_projection_origins'] = array(self::stylesheetProjectionOrigin($asset));
            $asset = self::withRewrittenTextContent($asset, $route);
            $keep($asset);
            unset($sharedAsset['_inline_media_projection']);
            // Media/target contracts and relative URL resolution must agree:
            // otherwise the slices cannot share a resource or reference origin.
            // Consolidate compatible chrome slices at the planning boundary,
            // before tokens and enqueue instances are consumed. Keep their
            // existing cascade order; only earlier identical rules are redundant.
            foreach ($emittedShared as $emissions) {
                foreach ($emissions as $emitted) {
                    $index = $emitted['index'];
                    $existing = $projected[$index];
                    if ($emitted['contract'] !== self::sharedChromeContract($sharedAsset)
                        || !self::sharedChromeOriginsAgree($shared, $emitted['origin'], (string) $sharedAsset['reference_origin'], $references)) continue;
                    $combined = (new CssStylesheetTransformer())->concatenateWithoutRedundantRules(array($existing['content'], $shared));
                    $existing = self::withRewrittenTextContent($existing, $combined);
                    $existing['path'] = $existing['target_path'] = 'assets/css/shared-chrome-' . substr(hash('sha256', self::sharedChromeContract($existing) . "\0" . $combined), 0, 16) . '.css';
                    $existing['token'] = 'asset-' . substr(hash('sha256', $existing['target_path']), 0, 16);
                    $existing['reconciliation_identity'] = self::identity('asset', $existing['source_path'], $existing['target_path']);
                    $existing['_stylesheet_projection_origins'][] = self::stylesheetProjectionOrigin($asset);
                    $projected[$index] = $existing;
                    continue 3;
                }
            }
            $sharedKey = strtolower((string) $sharedAsset['path']);
            if (isset($emittedShared[$sharedKey])) {
                $collidingTarget = (string) $sharedAsset['target_path'];
                $sharedAsset['path'] = $sharedAsset['target_path'] = substr($collidingTarget, 0, -strlen('.css')) . '-' . (count($emittedShared[$sharedKey]) + 1) . '.css';
                $sharedAsset['token'] = 'asset-' . substr(hash('sha256', $sharedAsset['target_path']), 0, 16);
                $sharedAsset['reconciliation_identity'] = self::identity('asset', $sharedAsset['source_path'], $sharedAsset['target_path']);
                $collisions[] = array('source_path' => (string) $sharedAsset['source_path'], 'colliding_source_path' => (string) $projected[$emittedShared[$sharedKey][0]['index']]['source_path'], 'target_path' => $collidingTarget, 'resolved_target_path' => (string) $sharedAsset['target_path']);
            }
            $emittedShared[$sharedKey][] = array('origin' => (string) $sharedAsset['reference_origin'], 'contract' => self::sharedChromeContract($sharedAsset), 'index' => count($projected));
            $projected[] = $sharedAsset;
        }
        return $projected;
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
     * The resource whose instances position a projection lifted out of $asset:
     * the asset itself, or the stylesheet a projected page slice joins. The
     * slice's scopes still bound which documents apply it.
     *
     * @param array<string,mixed> $asset
     * @return array{source_path:string,scopes:array<int,array<string,mixed>>}
     */
    private static function stylesheetProjectionOrigin(array $asset): array
    {
        return array('source_path' => (string) ($asset['stylesheet_parent'] ?? $asset['source_path'] ?? ''), 'scopes' => is_array($asset['scopes'] ?? null) ? $asset['scopes'] : array(array('kind' => 'global')));
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
                if (array() === $origins) $origins[] = array('position' => $preceding ?? -1, 'media' => (string) ($assets[$projection]['media'] ?? ''), 'type' => '');
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
    private const PAGE_STYLESHEET_IDENTITY_FIELDS = array('source_path', 'target_path', 'token', 'reconciliation_identity', 'scopes', 'stylesheet_instances', 'references');

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
        foreach (array_merge(array((string) json_encode($documents, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)), array_map(static fn (array $asset): string => is_string($asset['content'] ?? null) ? $asset['content'] : '', $assets)) as $text) {
            if (preg_match_all('/\{\{wordpress-site-plan:asset:([^}]+)\}\}/', $text, $matches)) $named += array_fill_keys($matches[1], true);
            if (preg_match_all('/(?:url\(\s*|@import\s+(?!url\()|(?:href|src)=)\\\\?["\']?([^"\'()\s\\\\]+)/i', $text, $matches)) {
                foreach ($matches[1] as $reference) $named[strtolower(basename((string) parse_url($reference, PHP_URL_PATH)))] = true;
            }
        }
        $parents = array();
        $instancesBySource = array();
        foreach ($assets as $asset) {
            if (is_string($asset['stylesheet_parent'] ?? null)) $parents[$asset['stylesheet_parent']] = true;
            if (is_string($asset['source_path'] ?? null) && !isset($instancesBySource[$asset['source_path']])) $instancesBySource[$asset['source_path']] = is_array($asset['stylesheet_instances'] ?? null) ? $asset['stylesheet_instances'] : array();
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
        // How stylesheetEnqueues() places a resource on one page: document
        // instances sort by their source position (then resource order); a
        // resource without any sits in the flat before/after run in resource
        // order. Returns null when the page does not apply the resource.
        $placement = static function (array $asset, string $page) use ($pagesOf, $instancesBySource): ?array {
            if ('css' !== ($asset['kind'] ?? null)) return null;
            $own = $pagesOf($asset);
            $applies = null === $own || isset($own[$page]);
            $occurrences = is_array($asset['stylesheet_instances'] ?? null) ? $asset['stylesheet_instances'] : array();
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
            $pages = 'css' === ($asset['kind'] ?? null) && is_string($asset['content'] ?? null) && !isset($asset['payload_reference']) && !isset($asset['content_base64']) && empty($asset['frame_fallback']) && !isset($parents[$source]) && !isset($named[(string) ($asset['token'] ?? '')]) && !isset($named[strtolower(basename((string) ($asset['target_path'] ?? '')))]) && !isset($named[strtolower(basename($source))]) ? $pagesOf($asset) : null;
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
                if (isset($asset['stylesheet_instances']) || isset($head['stylesheet_instances'])) $head['stylesheet_instances'] = array_merge($head['stylesheet_instances'] ?? array(), $asset['stylesheet_instances'] ?? array());
                if (isset($asset['references']) || isset($head['references'])) $head['references'] = self::mergedReferences(is_array($head['references'] ?? null) ? $head['references'] : array(), is_array($asset['references'] ?? null) ? $asset['references'] : array());
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

    /** @param array<string,mixed> $asset */
    /**
     * A selector without the `:where()` arguments that name a rich-text
     * marker no emitted markup carries, or null when the selector can only
     * match such an element.
     *
     * A dead marker matches nothing, so dropping an argument that requires
     * it leaves what the `:where()` matches unchanged, and `:where()` adds no
     * specificity. A `:not(:where(...))` left with no arguments excluded
     * nothing and is removed. Each route's copy of extracted chrome carries
     * its own marker, so without this the shared chrome rules differ per
     * route only by dead markers and every route ships its own copy.
     *
     * @param array<string,true> $live
     */
    /**
     * A rich-text marker no emitted markup, runtime declaration or entity
     * record carries names an element the plan replaced or dropped, so every
     * stylesheet selector naming it matches nothing: drop those arms from
     * page stylesheets too, as the shared-chrome projection already does.
     *
     * @param list<array<string,mixed>> $assets
     * @param array<string,true> $live
     * @return list<array<string,mixed>>
     */
    private static function withoutDeadRichTextMarkerSelectors(array $assets, array $live): array
    {
        foreach ($assets as &$asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null) || isset($asset['payload_reference']) || !str_contains($asset['content'], 'blocks-engine-richtext-')) continue;
            $content = (new CssStylesheetTransformer())->transformStyleRules(
                $asset['content'],
                static function (string $prelude, string $body) use ($live): string {
                    if (!str_contains($prelude, 'blocks-engine-richtext-')) return $prelude . '{' . $body . '}';
                    $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
                    if (null === $selectors) return $prelude . '{' . $body . '}';
                    $kept = array();
                    $changed = false;
                    foreach ($selectors as $selector) {
                        $pruned = self::withoutDeadRichTextMarkers(trim($selector), $live);
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

    private static function withoutDeadRichTextMarkers(string $selector, array $live): ?string
    {
        $dead = static function (string $text) use ($live): bool {
            if (!preg_match_all('/blocks-engine-richtext-[a-f0-9]+-\d+/', $text, $markers)) return false;
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
                $argument = self::withoutDeadRichTextMarkers(trim($argument), $live);
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

    private static function sharedChromeContract(array $asset): string
    {
        return (string) ($asset['media'] ?? '') . "\0" . (string) ($asset['stylesheet_target'] ?? 'both') . "\0" . ('engine-support' === ($asset['source'] ?? '') ? 'engine' : 'author');
    }

    private static function sharedChromeOriginsAgree(string $css, string $origin, string $other, ?AssetReferenceCanonicalizer $references): bool
    {
        if ($origin === $other || self::stylesheetDirectory($origin) === self::stylesheetDirectory($other)) return true;
        foreach (self::originSensitiveReferences($css) as $reference) {
            $left = $references?->reference($reference, $origin);
            $right = $references?->reference($reference, $other);
            if ($left !== $right) return false;
            if (null === $left && ArtifactPath::resolveRelativePath($reference, $origin) !== ArtifactPath::resolveRelativePath($reference, $other)) return false;
        }
        return true;
    }

    private static function stylesheetDirectory(string $path): string
    {
        $directory = str_replace('\\', '/', dirname($path));
        return in_array($directory, array('.', '/', ''), true) ? '' : $directory;
    }

    /** @return array<int,string> */
    private static function originSensitiveReferences(string $css): array
    {
        $references = array();
        $collect = static function (string $reference) use (&$references): void {
            $reference = trim($reference);
            if ('' === $reference || 1 === preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#|\?|/)~i', $reference)) return;
            $references[] = $reference;
        };
        CssUrlRewriter::rewrite($css, static function (string $reference) use ($collect): string {
            $collect($reference);
            return $reference;
        });
        if (preg_match_all('/@import\s+(?:url\(\s*)?(?:"([^"]*)"|\'([^\']*)\'|([^\s\)"\';]+))/i', $css, $matches, PREG_SET_ORDER)) foreach ($matches as $match) $collect((string) (($match[1] ?? '') ?: ($match[2] ?? '') ?: ($match[3] ?? '')));
        return $references;
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
        if (isset($metadata['head'])) {
            if (is_array($metadata['head'])) {
                [$metadata['head'], $droppedHead] = DocumentHeadContext::withoutUnrepresentable($metadata['head']);
                foreach ($droppedHead as $drop) $this->recordDroppedHeadItem(self::value($document, 'source_path'), $drop);
            }
            DocumentHeadContext::assertValid($metadata['head'], false, false);
            [$metadata['head'], $droppedViewports] = DocumentHeadContext::keepLastViewport($metadata['head']);
            if (0 < $droppedViewports) $this->recordDuplicateViewport(self::value($document, 'source_path'), $droppedViewports);
            DocumentHeadContext::assertValid($metadata['head'], false);
            foreach ($metadata['head']['elements'] as &$row) {
                if (!isset($row['url'])) continue;
                $reference = $this->documentAssetReference($row['url'], self::value($document, 'source_path'), $references, $routes);
                if (null !== $reference) { $row['asset_reference'] = $reference; unset($row['url']); }
                elseif ('link' === $row['tag'] && DocumentHeadContext::isRouteLink($row['attributes']) && null !== ($route = $this->routeReference($row['url'], self::value($document, 'source_path'), $routes))) $row['url'] = $route;
                elseif ('link' === $row['tag'] && !self::explicitUrl($row['url'])) {
                    $this->recordOmittedLinkDeclaration(self::value($document, 'source_path'), array('rel' => $row['attributes']['rel'] ?? '', 'url' => $row['url']));
                    $row = null;
                }
            }
            unset($row);
            $metadata['head']['elements'] = array_values(array_filter($metadata['head']['elements'], static fn(mixed $row): bool => is_array($row)));
        }
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
    /** @param array{code:string,reason_code:string,tag:string,name:string} $drop */
    private function recordDroppedHeadItem(string $sourcePath, array $drop): void
    {
        $attribute = 'document_head_attribute_dropped' === $drop['code'];
        $this->recordUnitFinding(array_filter(array(
            'code' => $drop['code'],
            'unit' => 'item',
            'message' => $attribute ? sprintf('Document head <%s> attribute %s cannot be carried (%s); it was dropped.', $drop['tag'], $drop['name'], $drop['reason_code']) : sprintf('Document head <%s> element cannot be carried (%s); it was dropped.', $drop['tag'], $drop['reason_code']),
            'source_path' => $sourcePath,
            'reason_code' => $drop['reason_code'],
            'tag' => $drop['tag'],
            'attribute' => $attribute ? $drop['name'] : '',
        ), static fn(string $value): bool => '' !== $value));
    }
    private function recordDuplicateViewport(string $sourcePath, int $dropped): void
    {
        $this->duplicateViewports[$sourcePath] = ($this->duplicateViewports[$sourcePath] ?? 0) + $dropped;
    }
    /** @return array<int,array<string,mixed>> */
    private function duplicateViewportDiagnostics(): array
    {
        $diagnostics = array();
        foreach ($this->duplicateViewports as $sourcePath => $dropped) {
            $diagnostics[] = array(
                'code' => 'document_head_duplicate_viewport',
                'severity' => 'warning',
                'message' => self::clipDiagnosticField(sprintf('Document head declared more than one viewport; kept the last and dropped %d earlier.', $dropped)),
                'source_path' => self::clipDiagnosticField((string) $sourcePath),
                'omitted_count' => $dropped,
                'reason_code' => 'duplicate_viewport',
            );
        }
        return $diagnostics;
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
    /**
     * Article markers alone (no publication date) are weak: page builders stamp
     * Article JSON-LD on ordinary service pages. They only count as publication
     * evidence when the page also looks like it belongs to a blog.
     *
     * @param array<int,array<string,string>> $evidence
     * @param array<string,mixed> $document
     * @param array<string,mixed>|null $parent
     * @return array<int,array<string,string>>
     */
    private static function withoutUnsupportedArticleMarkers(array $evidence, array $document, string $route, ?array $parent): array
    {
        foreach ($evidence as $row) {
            if (isset($row['publication_timestamp']) || !in_array($row['source'] ?? null, array('json-ld:Article', 'json-ld:BlogPosting', 'microdata:itemtype'), true)) {
                return $evidence;
            }
        }
        return self::hasBlogContext($document, $route, $parent) ? $evidence : array();
    }
    /** @param array<string,mixed> $document @param array<string,mixed>|null $parent */
    private static function hasBlogContext(array $document, string $route, ?array $parent): bool
    {
        if (1 === preg_match('~/(?:blog|news|posts?|articles?|journal|stories|insights)(?:/|$)~i', $route)) return true;
        foreach (array_filter(array($document, $parent)) as $candidate) {
            $html = is_string($candidate['html'] ?? null) ? $candidate['html'] : (is_string($candidate['block_markup'] ?? null) ? $candidate['block_markup'] : '');
            foreach (($candidate['document_metadata']['meta'] ?? array()) as $meta) {
                if (is_array($meta) && 'og:type' === strtolower((string) ($meta['property'] ?? $meta['name'] ?? '')) && 'article' === strtolower(trim((string) ($meta['content'] ?? '')))) return true;
            }
            foreach (($candidate['document_metadata']['links'] ?? array()) as $link) {
                $rel = is_array($link) ? preg_split('/\s+/', strtolower((string) ($link['rel'] ?? ''))) : array();
                $type = is_array($link) ? strtolower((string) ($link['type'] ?? '')) : '';
                if (in_array('alternate', $rel, true) && (str_contains($type, 'rss') || str_contains($type, 'atom'))) return true;
                if (in_array('prev', $rel, true) || in_array('next', $rel, true)) return true;
            }
            if (1 === preg_match('~<link\b[^>]*\brel=["\']?(?:prev|next)\b|<link\b[^>]*type=["\']?application/(?:rss|atom)\+xml~i', $html)) return true;
        }
        $html = is_array($parent) ? (is_string($parent['html'] ?? null) ? $parent['html'] : (string) ($parent['block_markup'] ?? '')) : '';
        return preg_match_all('~<article\b~i', $html) >= 2 || 1 === preg_match('~read\s+more|continue\s+reading~i', strip_tags($html));
    }
    /**
     * Route paths a document's links point at, resolved against its own route
     * the way a browser resolves them on the served page.
     *
     * @param array<string,mixed> $document
     * @return list<string>
     */
    private static function documentLinkedRoutes(array $document, string $route): array
    {
        $html = is_string($document['html'] ?? null) ? $document['html'] : (string) ($document['block_markup'] ?? '');
        if (!preg_match_all('~<a\b[^>]*?\bhref\s*=\s*["\']([^"\']*)["\']~i', $html, $matches)) return array();
        $base = '/' === $route ? '/' : $route . '/';
        $linked = array();
        foreach ($matches[1] as $href) {
            $href = html_entity_decode((string) preg_replace('/[?#].*$/s', '', trim($href)), ENT_QUOTES | ENT_HTML5);
            if (1 === preg_match('~^[a-z][a-z0-9+.-]*:~i', $href) || str_starts_with($href, '//')) {
                $path = parse_url(str_starts_with($href, '//') ? 'http:' . $href : $href, PHP_URL_PATH);
                if (!is_string($path)) continue;
                $href = '' === $path ? '/' : $path;
            }
            if ('' === $href) continue;
            $path = str_starts_with($href, '/') ? $href : $base . $href;
            $segments = array();
            foreach (explode('/', $path) as $segment) {
                if ('' === $segment || '.' === $segment) continue;
                if ('..' === $segment) { array_pop($segments); continue; }
                $segments[] = $segment;
            }
            if (array() !== $segments && 1 === preg_match('/^index\.html?$/i', (string) end($segments))) array_pop($segments);
            elseif (array() !== $segments) $segments[count($segments) - 1] = (string) preg_replace('/\.html?$/i', '', (string) end($segments));
            $linked['/' . implode('/', $segments)] = true;
        }
        return array_keys($linked);
    }
    /** @param array<int,array<string,string>> $evidence */
    private static function hasOnlyPublishTimeMeta(array $evidence): bool
    {
        if (array() === $evidence) return false;
        foreach ($evidence as $row) {
            $source = $row['source'] ?? '';
            if (!str_starts_with($source, 'meta:') || 'meta:og:url-dated' === $source || !isset($row['publication_timestamp'])) return false;
        }
        return true;
    }
    /** Return the owning route for a positive numbered pagination view. */
    private static function paginationParentRoute(string $route): ?string
    {
        if (!preg_match('~/(?:page/[1-9][0-9]*|comment-page-[1-9][0-9]*)/?$~', $route, $match, PREG_OFFSET_CAPTURE)) return null;
        $parent = substr($route, 0, $match[0][1]);
        return '' === $parent ? '/' : $parent;
    }

    /** @param mixed $documents @return array<int,array<string,mixed>> */
    private function decideDocuments(mixed $documents): array
    {
        if (!is_array($documents)) throw new InvalidArgumentException('Compiled site documents must be an array.');
        $entryRoot = self::entryRootFromDocuments($documents);
        $routes = array();
        $evidenceByIndex = array();
        $localTimesByIndex = array();
        $structuredTypes = array();
        $pageEvidenceByIndex = array();
        foreach ($documents as $index => $document) {
            if (!is_array($document) || !self::safePath($document['source_path'] ?? null)) throw new InvalidArgumentException('Compiled site document is invalid.');
            $metadata = is_array($document['metadata'] ?? null) ? $document['metadata'] : array();
            $path = is_string($metadata['route_path'] ?? null) && '' !== $metadata['route_path'] ? $metadata['route_path'] : self::pageRoutePath((string) $document['source_path'], $entryRoot);
            $routes[$index] = '/' === $path ? '/' : '/' . trim($path, '/');
            $structuredTypes[$index] = self::structuredPageTypes($document);
            $localTimesByIndex[$index] = array();
            // Repeated article metadata on pagination, and the listed entries'
            // dates on a category or tag archive, are not an independent
            // publication and must not vote in listing or dated-site inference.
            $evidenceByIndex[$index] = null === self::paginationParentRoute($routes[$index]) && !TaxonomyProjection::isTermRoute($routes[$index]) ? $this->publicationEvidence($document, $localTimesByIndex[$index]) : array();
            $pageTypes = array_intersect(array('WebPage', 'AboutPage', 'ContactPage', 'CollectionPage'), $structuredTypes[$index]);
            if (!array_intersect(array('Article', 'BlogPosting', 'NewsArticle'), $structuredTypes[$index]) && $pageTypes) {
                $evidenceByIndex[$index] = array();
                $pageEvidenceByIndex[$index] = array_values(array_map(static fn(string $type): array => array('source' => 'json-ld:' . $type), $pageTypes));
            }
        }
        $indexByRoute = array_flip($routes);
        foreach ($evidenceByIndex as $index => $evidence) {
            $parentIndex = $indexByRoute[self::parentRoutePath($routes[$index])] ?? null;
            $evidenceByIndex[$index] = array_intersect(array('BlogPosting', 'NewsArticle'), $structuredTypes[$index]) ? $evidence : self::withoutUnsupportedArticleMarkers($evidence, $documents[$index], $routes[$index], null === $parentIndex ? null : $documents[$parentIndex]);
        }
        $datedByParent = array();
        foreach ($evidenceByIndex as $index => $evidence) {
            if (array() === $evidence || !empty($documents[$index]['entrypoint'])) continue;
            $datedByParent[self::parentRoutePath($routes[$index])][] = $index;
        }
        $listings = array();
        foreach ($datedByParent as $parent => $indexes) {
            if ('/' !== $parent && count($indexes) >= 2) {
                $listings[$parent] = true;
            }
        }
        $metaOnlyPosts = array();
        $inferredPostCount = 0;
        $datedPostCount = 0;
        foreach ($documents as $index => &$document) {
            $metadata = is_array($document['metadata'] ?? null) ? $document['metadata'] : array();
            // CollectionPage is page evidence, not proof that a native archive
            // exists. Taxonomy/author projection below owns archive promotion.
            $frontmatter = is_array($metadata['frontmatter'] ?? null) ? $metadata['frontmatter'] : array();
            $explicit = null;
            $provenance = null;
            foreach (array('post_type', 'type') as $key) {
                if (is_string($frontmatter[$key] ?? null) && in_array(strtolower($frontmatter[$key]), array('page', 'post'), true)) {
                    $explicit = strtolower($frontmatter[$key]);
                    $provenance = 'frontmatter:' . $key;
                    break;
                }
            }
            if (null === $explicit && 'metadata:post_type' === ($metadata['post_type_declaration'] ?? null) && is_string($metadata['post_type'] ?? null) && in_array(strtolower($metadata['post_type']), array('page', 'post'), true)) {
                $explicit = strtolower($metadata['post_type']);
                $provenance = 'metadata:post_type';
            }
            $evidence = $evidenceByIndex[$index];
            $route = $routes[$index];
            $parent = self::parentRoutePath($route);
            if (isset($listings[$route])) {
                $evidence = array();
            } elseif (!isset($listings[$parent])) {
                $evidence = array_values(array_filter($evidence, static fn(array $row): bool => 'html:visible-date' !== ($row['source'] ?? null)));
            } elseif (array() !== $evidence) {
                // A listing that links to its entries says which routes it
                // contains. An undated route it leaves out is not one of its
                // posts: as a post it would take the import time as its date
                // and lead the reconstructed Query Loop. A listing that links
                // to none of its children (an uncaptured or stub index) says
                // nothing, so blog context still decides those routes.
                $listingIndex = $indexByRoute[$parent] ?? null;
                if (null !== $listingIndex && !array_filter($evidence, static fn(array $row): bool => isset($row['publication_timestamp']))) {
                    $listed = array_flip(self::documentLinkedRoutes($documents[$listingIndex], $parent));
                    $listsChildren = array() !== array_filter($routes, static fn(string $child): bool => isset($listed[$child]) && $parent === self::parentRoutePath($child));
                    if ($listsChildren && !isset($listed[$route])) {
                        $evidence = array();
                    }
                }
            }
            if (!isset($listings[$route]) && isset($listings[$parent]) && array() !== $evidence) {
                $hasListing = false;
                foreach ($evidence as $row) {
                    if ('listing:parent' === ($row['source'] ?? null)) {
                        $hasListing = true;
                    }
                }
                if (!$hasListing && count($evidence) < 16) {
                    $evidence[] = array('source' => 'listing:parent');
                }
            }
            // Page evidence must not participate in publication/listing inference.
            // Attach it after that inference so consumers can honour the page decision.
            $pageEvidence = $pageEvidenceByIndex[$index] ?? array();
            if (array() !== $pageEvidence) $evidence = $pageEvidence;
            $postType = $explicit ?? ((!empty($document['entrypoint']) || array() !== $pageEvidence || array() === $evidence) ? 'page' : 'post');
            if (null === $explicit && 'post' === $postType && null === self::paginationParentRoute($route)) {
                // Meta-only documents are the ambiguous candidates, not votes
                // against a dated permalink pattern. Stronger undated posts
                // do vote against it, even when listing inference drops a date.
                if (self::hasOnlyPublishTimeMeta($evidenceByIndex[$index])) {
                    $metaOnlyPosts[] = $index;
                } else {
                    ++$inferredPostCount;
                    if (in_array('route:dated', array_column($evidenceByIndex[$index], 'source'), true)) ++$datedPostCount;
                }
            }
            $surface = $this->templateSurface($metadata['template_surface'] ?? null, (string) ($document['source_path'] ?? ''));
            if (null !== $surface) {
                $document['template_surface'] = $surface;
                $metadata['template_surface'] = $surface;
            }
            $metadata['post_type'] = $postType;
            $document['metadata'] = $metadata;
            $document['content_decision'] = array_filter(array('schema' => 'blocks-engine/content-decision/v1', 'state' => null !== $explicit ? 'declared' : (array() === $evidence ? 'defaulted' : 'inferred'), 'post_type' => $postType, 'provenance' => $provenance, 'evidence' => $evidence), static fn(mixed $value): bool => null !== $value);
            $timestamp = self::preferredPublicationTimestamp($evidence);
            if (null !== $timestamp) {
                $document['publication_timestamp'] = $timestamp;
                if (isset($localTimesByIndex[$index][$timestamp])) {
                    $document['publication_local_time'] = $localTimesByIndex[$index][$timestamp];
                }
            }
        }
        unset($document);
        // Decide from the complete site's inferred posts, independently of
        // document order. Declarations and stronger publication evidence are
        // never overridden by the site's permalink pattern. The dated pattern
        // must also be the site's dominant post shape: dated posts outnumber
        // the meta-only candidates, so one dated URL on a `/slug/` blog never
        // turns its undated posts into pages.
        if ($datedPostCount > 0 && $datedPostCount === $inferredPostCount && $datedPostCount > count($metaOnlyPosts)) {
            foreach ($metaOnlyPosts as $index) {
                $documents[$index]['metadata']['post_type'] = 'page';
                $documents[$index]['content_decision']['post_type'] = 'page';
                $documents[$index]['content_decision']['evidence'][] = array('source' => 'site:dated-post-routes');
            }
        }
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
    /** @param array<string,mixed> $document @return list<string> */
    private static function structuredPageTypes(array $document): array
    {
        $types = array();
        $collect = static function (mixed $value) use (&$collect, &$types): void {
            if (!is_array($value)) return;
            foreach ((array) ($value['@type'] ?? array()) as $type) {
                if (is_string($type)) $types[] = (string) preg_replace('~^https?://schema\\.org/~', '', $type);
            }
            foreach ($value as $child) if (is_array($child)) $collect($child);
        };
        foreach (HtmlMarkupParser::htmlMarkupNodes((string) ($document['html'] ?? '')) as $node) {
            if ('rawtext' === ($node['kind'] ?? null) && 'script' === ($node['name'] ?? null) && 'application/ld+json' === strtolower(trim((string) ($node['attributes']['type'] ?? '')))) $collect(json_decode($node['content'], true));
        }
        return array_values(array_unique($types));
    }
    /** @param array<string,mixed> $document @param array<string,string> $localTimes Non-zero-offset representations keyed by UTC instant. @return array<int,array<string,string>> */
    private function publicationEvidence(array $document, array &$localTimes): array
    {
        $html = is_string($document['html'] ?? null) ? $document['html'] : '';
        if ('' === trim($html) && is_string($document['block_markup'] ?? null)) $html = $document['block_markup'];
        $evidence = array(); $add = static function (array &$rows, string $source, ?string $value = null): void { if (count($rows) >= 16) return; $row = array('source' => $source); if (null !== $value) $row['publication_timestamp'] = $value; $rows[] = $row; };
        // Keep offset candidates separate from evidence: a plain <time> must
        // neither select the publication instant nor affect post inference.
        $timestamp = static function (string $value) use (&$localTimes): ?string {
            $date = self::normalizePublicationTimestamp($value, $localTime);
            if (null !== $date && null !== $localTime) $localTimes[$date] ??= $localTime;
            return $date;
        };
        foreach (($document['document_metadata']['meta'] ?? array()) as $meta) if (is_array($meta) && is_string($meta['content'] ?? null) && in_array(strtolower((string) ($meta['property'] ?? $meta['name'] ?? '')), array('article:published_time', 'article:published', 'pubdate', 'publishdate', 'date', 'dc.date.issued', 'dc.date', 'parsely-pub-date', 'releasedate'), true)) if (null !== ($date = $timestamp($meta['content']))) $add($evidence, 'meta:' . strtolower((string) ($meta['property'] ?? $meta['name'])), $date);
        foreach (HtmlMarkupParser::htmlMarkupNodes($html) as $node) if ('tag' === ($node['kind'] ?? null)) { $attributes = $node['attributes']; if ('time' === ($node['name'] ?? null) && is_string($attributes['datetime'] ?? null)) $timestamp(html_entity_decode($attributes['datetime'], ENT_QUOTES | ENT_HTML5, 'UTF-8')); if (preg_match('~\b(?:Article|BlogPosting)\b~', (string) ($attributes['itemtype'] ?? ''))) $add($evidence, 'microdata:itemtype'); if ('datePublished' === ($attributes['itemprop'] ?? null)) foreach (array('datetime', 'content') as $key) if (is_string($attributes[$key] ?? null) && null !== ($date = $timestamp(html_entity_decode($attributes[$key], ENT_QUOTES | ENT_HTML5, 'UTF-8')))) { $add($evidence, 'microdata:datePublished', $date); break; } }
        foreach (HtmlMarkupParser::htmlMarkupNodes($html) as $node) if ('rawtext' === ($node['kind'] ?? null) && 'script' === ($node['name'] ?? null) && 'application/ld+json' === strtolower(trim((string) ($node['attributes']['type'] ?? '')))) foreach ($this->jsonLdPublicationEvidence(json_decode($node['content'], true), $timestamp) as $row) $add($evidence, $row['source'], $row['publication_timestamp'] ?? null);
        $route = is_string($document['metadata']['route_path'] ?? null) ? $document['metadata']['route_path'] : self::pageRoutePath((string) $document['source_path'], self::entryRootFromDocuments(array($document)));
        if (preg_match('~/(?:[0-9]{4})/(?:0[1-9]|1[0-2])(?:/|$)~', $route)) $add($evidence, 'route:dated');
        foreach ($this->visiblePublicationEvidence($html, $timestamp) as $row) {
            $add($evidence, $row['source'], $row['publication_timestamp'] ?? null);
        }
        foreach (($document['document_metadata']['meta'] ?? array()) as $meta) {
            if (!is_array($meta) || !is_string($meta['content'] ?? null) || !in_array(strtolower((string) ($meta['property'] ?? $meta['name'] ?? '')), array('og:url', 'twitter:url'), true)) {
                continue;
            }
            $path = parse_url($meta['content'], PHP_URL_PATH);
            if (!is_string($path) || !preg_match('~/(20\d{2})/(0[1-9]|1[0-2])(?:/|$)~', $path, $match)) {
                continue;
            }
            $add($evidence, 'meta:og:url-dated', $timestamp($match[1] . '-' . $match[2] . '-01'));
        }
        $unique = array();
        foreach ($evidence as $row) {
            $unique[$row['source'] . "\n" . ($row['publication_timestamp'] ?? '')] = $row;
        }
        return array_values($unique);
    }
    /** @return array<int,array<string,string>> */
    private function visiblePublicationEvidence(string $html, callable $timestamp): array
    {
        $rows = array();
        foreach ($this->publicationHtmlFragments($html) as $fragment) {
            foreach (HtmlMarkupParser::htmlMarkupNodes($fragment) as $node) {
                if ('tag' !== ($node['kind'] ?? null) || !is_int($node['inner_offset'] ?? null)) {
                    continue;
                }
                $attributes = is_array($node['attributes'] ?? null) ? $node['attributes'] : array();
                if (!self::isVisibleDateElement((string) ($node['name'] ?? ''), $attributes)) {
                    continue;
                }
                $text = self::elementInnerText($fragment, (string) $node['name'], $node['inner_offset']);
                $timestamp(trim(preg_replace('/\s+/', ' ', $text) ?? $text));
                $parsed = self::parseVisiblePublicationTimestamp($text);
                if (null !== $parsed) {
                    $rows[] = array('source' => 'html:visible-date', 'publication_timestamp' => $parsed);
                }
            }
        }
        return $rows;
    }
    /** @return array<int,string> */
    private function publicationHtmlFragments(string $html): array
    {
        $fragments = array($html);
        foreach (HtmlMarkupParser::htmlMarkupNodes($html) as $node) {
            if ('comment' !== ($node['kind'] ?? null)) {
                continue;
            }
            $attributes = self::blockCommentAttributes((string) ($node['content'] ?? ''));
            if (!is_array($attributes) || !is_string($attributes['content'] ?? null) || !str_contains($attributes['content'], '<')) {
                continue;
            }
            $fragments[] = $attributes['content'];
        }
        return $fragments;
    }
    /** @param array<string,mixed> $attributes */
    private static function isVisibleDateElement(string $name, array $attributes): bool
    {
        if ('time' === $name) {
            return 'datePublished' === ($attributes['itemprop'] ?? null);
        }
        if (!in_array($name, array('p', 'span', 'div', 'li', 'td', 'mark'), true)) {
            return false;
        }
        $tokens = strtolower(trim(html_entity_decode((string) ($attributes['class'] ?? '') . ' ' . (string) ($attributes['id'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        return 1 === preg_match('/\b(?:date|published|pubdate)\b/', $tokens);
    }
    private static function elementInnerText(string $html, string $name, int $innerOffset): string
    {
        $closing = HtmlMarkupParser::rawTextEnd($html, $name, $innerOffset);
        if (null === $closing) {
            return '';
        }
        $inner = substr($html, $innerOffset, $closing[0] - $innerOffset);
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }
    /** @param array<int,array<string,string>> $evidence */
    private static function preferredPublicationTimestamp(array $evidence): ?string
    {
        $fallback = null;
        foreach ($evidence as $row) {
            if (!is_string($row['publication_timestamp'] ?? null)) {
                continue;
            }
            if ('meta:og:url-dated' === ($row['source'] ?? null)) {
                $fallback ??= $row['publication_timestamp'];
                continue;
            }
            return $row['publication_timestamp'];
        }
        return $fallback;
    }
    /** @return array<int,array<string,string>> */
    private function jsonLdPublicationEvidence(mixed $value, callable $timestamp): array
    {
        if (!is_array($value)) return array(); $rows = array();
        if (isset($value['@type'])) { $types = array_map(static fn(mixed $type): mixed => is_string($type) ? preg_replace('~^https?://schema\\.org/~', '', $type) : $type, is_array($value['@type']) ? $value['@type'] : array($value['@type'])); if (array_intersect(array('Article', 'BlogPosting', 'NewsArticle'), $types)) { $row = array('source' => 'json-ld:' . (in_array('BlogPosting', $types, true) ? 'BlogPosting' : (in_array('NewsArticle', $types, true) ? 'NewsArticle' : 'Article'))); if (is_string($value['datePublished'] ?? null) && null !== ($date = $timestamp($value['datePublished']))) $row['publication_timestamp'] = $date; $rows[] = $row; } }
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
            $metadata = is_array($document['metadata'] ?? null) ? $document['metadata'] : array();
            $explicit[$order] = is_string($metadata['route_path'] ?? null) && '' !== $metadata['route_path'];
            if ('' !== $entryRoot && ! str_starts_with((string) $document['source_path'], $entryRoot . '/') && !$explicit[$order]) throw new InvalidArgumentException('Compiled site document is outside the entrypoint content root.');
            $derived[$order] = $explicit[$order] ? self::canonicalRoutePath($metadata['route_path']) : self::pageRoutePath($document['source_path'], $entryRoot);
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
                $path = self::disambiguatedRoutePath($path, $taken);
                $this->recordRouteCollision($kept, $sourcePath, $derived[$order], $path);
                $taken[$path] = $sourcePath;
            }
            $metadata = is_array($document['metadata'] ?? null) ? $document['metadata'] : array();
            $previous = $legacy[$sourcePath] ?? array();
            $routes[] = array('kind' => 'route', 'source_path' => $document['source_path'], 'target_path' => $path, 'target_slug' => self::value($document, 'slug', self::routeSlug($path)), 'title' => self::value($document, 'title'), 'parent_source_path' => self::value($metadata, 'parent_source_path'), 'source_relation' => !empty($document['entrypoint']) ? 'entrypoint' : ($previous['source_relation'] ?? 'document'), 'order' => $order);
            if (preg_match('~^/author/[^/]+$~', $derived[$order])) $routes[array_key_last($routes)]['source_claim_path'] = $derived[$order];
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
            $page['route'] = array('path' => $path, 'parent_path' => self::parentRoutePath($path), 'slug' => self::routeSlug($path));
            if ('/' !== $path) $page['slug'] = $page['route']['slug'];
            $page['reconciliation_identity'] = self::identity('page', $page['source_path'], $path);
            $byRoute[$path] = $index;
        }
        unset($page);
        $inboundLabels = null;
        foreach (array_keys($byRoute) as $path) {
            if ('post' === ($pages[$byRoute[$path]]['post_type'] ?? null)) continue;
            // Any existing route, including a post, blocks a synthetic ancestor.
            foreach (self::routeAncestors($path) as $ancestor) if (!isset($byRoute[$ancestor])) {
            $source = 'wordpress-site-plan/routes/' . trim($ancestor, '/') . '.html';
            if (isset($sources[$source])) throw new InvalidArgumentException('WordPress site plan synthetic route source collides with a document.');
            $markup = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"></div><!-- /wp:group -->' . "\n";
            $inboundLabels ??= self::inboundRouteLinkLabels($pages);
            $title = self::mostCommonInboundLabel($inboundLabels, $ancestor) ?? ucwords(str_replace('-', ' ', self::routeSlug($ancestor)));
            $pages[] = array('source_path' => $source, 'slug' => self::routeSlug($ancestor), 'title' => $title, 'post_type' => 'page', 'parent_source_path' => '', 'entrypoint' => false, 'area' => null, 'placement' => null, 'canonical_block_markup' => $markup, 'metadata' => array(), 'document_metadata' => array('source_context' => array('source_path' => $source, 'kind' => 'synthetic_route'), 'title' => $title, 'title_declaration' => array('order' => 0, 'placement' => 'head'), 'meta' => array(), 'links' => array(), 'scripts' => array()), 'provenance' => array(), 'reconciliation_identity' => self::identity('page', $source, $ancestor), 'content_hash' => hash('sha256', $markup), 'route' => array('path' => $ancestor, 'parent_path' => self::parentRoutePath($ancestor), 'slug' => self::routeSlug($ancestor)), 'synthetic' => true);
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
            if (isset($byRoute[$fallbackPath])) $root['slug'] = self::routeSlug(self::disambiguatedRoutePath($fallbackPath, $byRoute));
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
                do { $parent = self::parentRoutePath($parent); } while ('/' !== $parent && 'page' !== ($pages[$byRoute[$parent] ?? -1]['post_type'] ?? null));
                $path = rtrim($parent, '/') . '/' . self::routeSlug($originalPath);
                if (isset($byRoute[$path])) $path = self::disambiguatedRoutePath($path, $byRoute);
                $this->recordRouteCollision($parentSource, $page['source_path'], $originalPath, $path, true);
                unset($byRoute[$originalPath]);
                $byRoute[$path] = $index;
                $page['route'] = array('path' => $path, 'parent_path' => self::parentRoutePath($path), 'slug' => self::routeSlug($path));
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
        $renames = array(); $titles = array(); $shared = array();
        foreach ($groups as $indexes) {
            if (count($indexes) < 2) continue;
            $page = $pages[$indexes[0]];
            $key = self::routeTemplateKey($page);
            $prefix = 'page' === $page['post_type'] ? 'page-' : 'single-' . $page['post_type'] . '-';
            $slug = $prefix . 'shared-' . substr(hash('sha256', $page['post_type'] . "\0" . $bySlug[$key]['canonical_block_markup']), 0, 12);
            while (isset($bySlug[$slug]) || isset($titles[$slug])) $slug .= '-shared';
            $labels = array();
            foreach ($indexes as $index) {
                $renames[self::routeTemplateKey($pages[$index])] = $slug;
                $labels[] = $pages[$index]['title'];
                $pages[$index]['route_template'] = $slug;
                $pages[$index]['metadata']['post_meta']['_wp_page_template'] = $slug;
            }
            $titles[$slug] = implode(' / ', array_unique($labels));
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
        $type = $part['placement']['post_type'] ?? null;
        return null === $type || str_starts_with($slug, 'page' === $type ? 'page-' : 'single-' . $type . '-');
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
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $markup . '</body></html>');
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
                  $query = ('search' === $templateSlug ? '<!-- wp:query-title {"type":"search"} /-->' . "\n" : ('archive' === $templateSlug ? '<!-- wp:query-title {"type":"archive"} /-->' . "\n" : ''))
                      . self::queryLoopMarkup(true);
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
            $template['canonical_block_markup'] .= "\n" . $documentChrome[$template['slug']];
            $template['content_hash'] = self::contentHash($template['canonical_block_markup']);
        }
        unset($template);
        foreach ($templates as &$template) if (isset($frames[$template['slug']])) $template['shell_frame'] = $frames[$template['slug']]['shells']; unset($template);
         $explicitSlugs = array_fill_keys(array_map(static fn(array $surface): string => $surface['template_surface']['slug'], $surfaces), true);
         $templates = array_values(array_filter($templates, static fn(array $template): bool => !isset($explicitSlugs[$template['slug']])));
         foreach ($surfaces as $surface) {
            $declaration = $surface['template_surface']; $slug = $declaration['slug']; $target = 'templates/' . $slug . '.html';
            if (array_filter($templates, static fn(array $template): bool => $template['slug'] === $slug)) throw new InvalidArgumentException('A declared template surface collides with a generated template.');
            $content = is_null($references) ? (string) $surface['block_markup'] : $this->routeLinks($references->content((string) $surface['block_markup'], (string) $surface['source_path']), (string) $surface['source_path'], $routes);
             $templates[] = array('slug' => $slug, 'target_path' => $target, 'canonical_block_markup' => $content, 'source_path' => $surface['source_path'], 'template_surface' => $declaration, 'provenance' => $surface['provenance'] ?? array(), 'reconciliation_identity' => self::identity('template', $surface['source_path'], $target), 'content_hash' => self::contentHash($content));
         }
         foreach ($taxonomyEntities as $entity) {
             if (!array_key_exists('presentation_markup', $entity['archive'])) continue;
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
     * @param array<int,array<string,mixed>> $pages
     * @return array<int,array<string,mixed>>
     */
    private function extractPostCommentForms(array $pages): array
    {
        // Only factor a shared post primitive when every captured post has it
        // and nothing renderable follows any occurrence in its original context.
        $pattern = '~<!-- wp:comments -->\\s*<div class="wp-block-comments">\\s*<!-- wp:post-comments-form /-->\\s*</div>\\s*<!-- /wp:comments -->~';
        $posts = array_filter($pages, static fn(array $page): bool => 'post' === ($page['post_type'] ?? null) && empty($page['synthetic']));
        if (array() === $posts) return $pages;
        $closingOnly = '~\A(?:\s|<!--\s*/wp:[a-zA-Z0-9_/-]+\s*-->|</[a-zA-Z][a-zA-Z0-9:-]*\s*>)*\z~';
        foreach ($posts as $post) {
            $markup = $post['canonical_block_markup'];
            if (!preg_match_all($pattern, $markup, $matches, PREG_OFFSET_CAPTURE)) return $pages;
            foreach ($matches[0] as [$form, $offset]) {
                if (1 !== preg_match($closingOnly, substr($markup, $offset + strlen($form)))) return $pages;
            }
        }
        foreach ($posts as $index => $post) {
            $pages[$index]['canonical_block_markup'] = preg_replace($pattern, '', $post['canonical_block_markup']);
            $pages[$index]['metadata']['native_comment_form'] = true;
            $pages[$index]['content_hash'] = self::contentHash($pages[$index]['canonical_block_markup']);
        }
        return $pages;
    }

    /**
     * A captured WordPress entry-content boundary is stronger evidence than
     * cross-post similarity: everything outside it belongs to the template.
     * WordPress post primitives belong to the shared single lifecycle. Responsive
     * document copies keep their visibility wrappers around article bodies only.
     */
    private function extractBlockThemePostChrome(array $pages, array $declarations, array $records): array
    {
        $templates = array();
        $shells = array();
        $capturedComments = null;
        foreach ($pages as $page) {
            if ('post' !== ($page['post_type'] ?? null)) continue;
            $source = $page['canonical_block_markup'];
            foreach (self::blockRanges($source) as $range) {
                $slice = substr($source, $range['offset'], $range['length']);
                if (str_starts_with($slice, '<!-- wp:comments') && str_contains($slice, '<!-- wp:comment-template')) {
                    $capturedComments = $slice;
                    break 2;
                }
            }
        }
        // Prefer a unified document with both navigation directions as the
        // chrome owner, not an endpoint whose absent neighbor rendered empty.
        $ordered = $pages;
        uasort($ordered, static fn(array $a, array $b): int => (substr_count($a['canonical_block_markup'], 'wp-block-post-content') <=> substr_count($b['canonical_block_markup'], 'wp-block-post-content')) ?: (substr_count($b['canonical_block_markup'], '<!-- wp:post-navigation-link ') <=> substr_count($a['canonical_block_markup'], '<!-- wp:post-navigation-link ')));
        foreach ($ordered as $index => $post) {
            if ('post' !== ($post['post_type'] ?? null)) continue;
            $markup = $post['canonical_block_markup'];
            $contents = array();
            foreach (self::blockRanges($markup) as $range) {
                $slice = substr($markup, $range['offset'], $range['length']);
                $attrs = self::listingBlockAttributes($slice);
                if (in_array('wp-block-post-content', preg_split('/\s+/', (string) ($attrs['className'] ?? '')) ?: array(), true)) $contents[] = $range;
            }
            if (array() === $contents) continue;
            if (count($contents) > 1) {
                // A shared template renders post-content once. Retain each
                // captured document's visibility owner, but only its article
                // children, so desktop/mobile never duplicate the site shell.
                if (!isset($templates['single'])) continue;
                $bodies = array();
                $articles = array();
                $retained = array();
                foreach ($contents as $content) {
                    $children = self::childBlockRanges($markup, $content);
                    if (array() === $children) continue;
                    $start = $children[0]['offset'];
                    $last = $children[count($children) - 1];
                    $length = $last['offset'] + $last['length'] - $start;
                    $owner = null;
                    foreach (self::blockRanges($markup) as $candidate) {
                        if ($candidate['offset'] >= $content['offset'] || $candidate['offset'] + $candidate['length'] <= $content['offset']) continue;
                        $slice = substr($markup, $candidate['offset'], $candidate['length']);
                        // The generated-block namespace is consumer-owned.
                        if (1 === preg_match('~^<!-- wp:[a-z0-9-]+/layout-shell ~', $slice) && array_key_exists(CaptureEvidence::DOCUMENT_SCOPE, self::listingBlockAttributes($slice)['wrappers'][0]['attributes'] ?? array())) {
                            $owner = $candidate;
                            break;
                        }
                    }
                    if (null === $owner) continue;
                    $siblings = self::childBlockRanges($markup, $owner);
                    if (array() === $siblings) continue;
                    $first = $siblings[0]['offset'];
                    $lastSibling = $siblings[count($siblings) - 1];
                    $end = $lastSibling['offset'] + $lastSibling['length'];
                    $articles[] = substr($markup, $start, $length);
                    $bodies[] = substr($markup, $owner['offset'], $first - $owner['offset']) . $articles[count($articles) - 1] . substr($markup, $end, $owner['offset'] + $owner['length'] - $end);
                    $retained[] = array('offset' => $start, 'length' => $length);
                }
                if (count($bodies) !== count($contents) || $this->postChromeFactoringStrandsBindings($post, $retained, $declarations, $records)) continue;
                $pages[$index]['route_template'] = 'single';
                // Compare the same non-semantic per-document capture identity
                // as shell extraction, but retain the desktop payload verbatim
                // so its authored blocks and generated geometry keep their CSS.
                $identities = array_map(array(ShellExtraction::class, 'documentChromeIdentity'), $articles);
                $pages[$index]['canonical_block_markup'] = 1 === count(array_unique($identities)) ? $articles[0] : implode("\n", $bodies);
                $pages[$index]['content_hash'] = self::contentHash($pages[$index]['canonical_block_markup']);
                continue;
            }
            $content = $contents[0];
            $children = self::childBlockRanges($markup, $content);
            if (array() === $children) continue;
            $start = $children[0]['offset'];
            $last = $children[count($children) - 1];
            $length = $last['offset'] + $last['length'] - $start;
            $retained = array(array('offset' => $start, 'length' => $length));
            if ($this->postChromeFactoringStrandsBindings($post, $retained, $declarations, $records)) continue;
            $attrs = self::listingBlockAttributes(substr($markup, $content['offset'], $content['length']));
            $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array();
            $attrs['className'] = implode(' ', array_diff($classes, array('wp-block-post-content')));
            if (array_intersect($classes, array('has-global-padding', 'is-layout-constrained'))) $attrs['layout'] = array('type' => 'constrained');
            unset($attrs['tagName']);
            $template = substr_replace($markup, '<!-- wp:post-content ' . json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ' /-->', $content['offset'], $content['length']);
            // Reuse the normal title/date conversion, but never infer article
            // body from arbitrary chrome siblings once WordPress named it.
            $replacements = array();
            foreach (self::blockRanges($template) as $range) {
                $slice = substr($template, $range['offset'], $range['length']);
                if (array() !== self::childBlockRanges($template, $range)) continue;
                $kind = $this->classifyPostChromeBlock($slice, $post, (string) ($post['route']['path'] ?? ''));
                if (preg_match('/<time\b[^>]*datetime=/i', $slice)) $kind = 'date';
                if ('title' === $kind || 'date' === $kind) $replacements[] = $range + array('markup' => 'title' === $kind ? self::postTitleMarkup($slice) : self::postDateMarkup($slice));
            }
            usort($replacements, static fn(array $a, array $b): int => $b['offset'] <=> $a['offset']);
            foreach ($replacements as $replacement) $template = substr_replace($template, $replacement['markup'], $replacement['offset'], $replacement['length']);
            $slug = 'single';
            $pages[$index]['route_template'] = $slug;
            $pages[$index]['canonical_block_markup'] = substr($markup, $start, $length);
            $pages[$index]['content_hash'] = self::contentHash($pages[$index]['canonical_block_markup']);
            // The first complete block-theme document supplies the shared
            // lifecycle, rather than freezing each post's captured date,
            // navigation availability and responsive header variant in a
            // route-specific template.
            if (isset($templates[$slug])) continue;
            $templates[$slug] = $template;
            preg_match_all('/<!-- wp:template-part (\{.*?\}) \/-->/', $template, $matches);
            $shells[$slug] = array_map(static fn(string $json): string => (string) (json_decode($json, true)['slug'] ?? ''), $matches[1]);
        }
        if (null !== $capturedComments && isset($templates['single'])) {
            $template = $templates['single'];
            $replacements = array();
            foreach (self::blockRanges($template) as $range) {
                $slice = substr($template, $range['offset'], $range['length']);
                if (preg_match('/^<!-- wp:comments(?:\s|-->)/', $slice)) $replacements[] = $range;
            }
            if (array() === $replacements) $template .= $capturedComments;
            else foreach (array_reverse($replacements) as $range) $template = substr_replace($template, $capturedComments, $range['offset'], $range['length']);
            $templates['single'] = $template;
        }
        return array('pages' => $pages, 'templates' => $templates, 'shells' => $shells);
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array<string,mixed>> $parts
     * @param array<int,array<string,mixed>> $runtimeDeclarations
     * @param array<int,array<string,mixed>> $runtimeEntityRecords
     * @return array{pages:array<int,array<string,mixed>>,single:?string}
     */
    private function extractPostArticleChrome(array $pages, array $parts, array $runtimeDeclarations = array(), array $runtimeEntityRecords = array()): array
    {
        $posts = array();
        $indexes = array();
        foreach ($pages as $index => $page) {
            if ('post' !== ($page['post_type'] ?? null) || !empty($page['synthetic']) || !is_string($page['canonical_block_markup'] ?? null) || '' === trim($page['canonical_block_markup'])) {
                continue;
            }
            $posts[] = $page;
            $indexes[] = $index;
        }
        if (count($posts) < 2) {
            return array('pages' => $pages, 'single' => null);
        }
        // Article factoring targets one global single template. Inline shell
        // references belong to the source documents, including their wrappers.
        // Keep the entire article in post-content rather than hoisting those
        // references (or splitting their authored ancestor context).
        foreach ($parts as $part) {
            if ('inline_shared_shell' !== ($part['placement']['kind'] ?? null)) continue;
            foreach ($posts as $post) {
                if (str_contains($post['canonical_block_markup'], '"slug":"' . $part['slug'] . '"')) {
                    return array('pages' => $pages, 'single' => null);
                }
            }
        }
        $shared = $this->sharedPostChromeIdentities($posts);
        $single = null;
        $singleMenus = array();
        $bodies = array();
        foreach ($posts as $postIndex => $post) {
            $route = is_string($post['route']['path'] ?? null) ? $post['route']['path'] : '';
            $transformed = $this->transformPostChromeDocument($post['canonical_block_markup'], $post, $route, $shared);
            if (!$transformed['title'] || (!$transformed['date'] && !$transformed['shared']) || '' === trim($transformed['body']) || !str_contains($transformed['template'], '<!-- wp:post-content')) {
                return array('pages' => $pages, 'single' => null);
            }
            // A runtime entity binding anchors one exact block of the document
            // its declaration still owns. Factoring keeps only post-content
            // there, so when an anchored region would move into the single
            // template the complete articles stay page-owned rather than
            // strand the binding on a source path that no longer renders it.
            if ($this->postChromeFactoringStrandsBindings($post, $transformed['retained'], $runtimeDeclarations, $runtimeEntityRecords)) {
                return array('pages' => $pages, 'single' => null);
            }
            if (null === $single) {
                $single = $this->replaceListingArchives($transformed['template'], $posts);
                $singleMenus = NavigationEntityProjection::renderedMenus($single);
            }
            // After factoring, a post renders only the single template and its
            // body. Every menu the post rendered before must still be rendered
            // by one of them; otherwise it would be lost (and its entity left
            // with no reference), so the articles stay page-owned.
            $keptMenus = $singleMenus + NavigationEntityProjection::renderedMenus($transformed['body']);
            if (array() !== array_diff_key(NavigationEntityProjection::renderedMenus($post['canonical_block_markup']), $keptMenus)) {
                return array('pages' => $pages, 'single' => null);
            }
            $bodies[$indexes[$postIndex]] = $transformed['body'];
        }
        foreach ($bodies as $index => $body) {
            $pages[$index]['canonical_block_markup'] = $body;
            $pages[$index]['content_hash'] = self::contentHash($body);
        }
        return array('pages' => $pages, 'single' => $single);
    }
    /**
     * Whether factoring this post's article chrome would move a runtime entity
     * binding's anchored region out of the document its declaration owns. The
     * body keeps only the retained post-content ranges, so a bound region that
     * survives in none of them would strand.
     *
     * @param array<string,mixed> $post
     * @param array<int,array{offset:int,length:int}> $retained
     * @param array<int,array<string,mixed>> $declarations
     * @param array<int,array<string,mixed>> $records
     */
    private function postChromeFactoringStrandsBindings(array $post, array $retained, array $declarations, array $records): bool
    {
        $ranges = $this->postBoundBlockRanges($declarations, $records, $post);
        if (array() === $ranges) return false;
        foreach ($ranges as $range) {
            foreach ($retained as $kept) {
                if ($kept['offset'] <= $range['offset'] && $range['offset'] + $range['length'] <= $kept['offset'] + $kept['length']) continue 2;
            }
            return true;
        }
        return false;
    }
    /**
     * Byte ranges of a post's blocks that runtime entity bindings anchor on.
     *
     * @param array<int,array<string,mixed>> $declarations
     * @param array<int,array<string,mixed>> $records
     * @param array<string,mixed> $post
     * @return list<array{offset:int,length:int}>
     */
    private function postBoundBlockRanges(array $declarations, array $records, array $post): array
    {
        $markup = (string) ($post['canonical_block_markup'] ?? '');
        $ranges = array();
        foreach ($declarations as $declaration) {
            $payload = $declaration['payload'] ?? array();
            $entities = RuntimeEntityManifest::SCHEMA === ($payload['schema'] ?? null)
                ? RuntimeEntityManifest::resolve($payload, $records)
                : (is_array($payload['entities'] ?? null) ? $payload['entities'] : array());
            foreach ($entities as $entity) foreach (is_array($entity) ? ($entity['bindings'] ?? array()) : array() as $binding) {
                if (($binding['source_path'] ?? null) !== ($post['source_path'] ?? null)) continue;
                $search = $binding['search_block_markup'] ?? null;
                if (!is_string($search) || '' === $search) continue;
                $position = $binding['position'] ?? null;
                if (self::bindingPosition($position, $markup, $search)) { $ranges[] = array('offset' => $position['offset'], 'length' => $position['length']); continue; }
                // A manifest record keeps the position it was compiled with, which
                // page canonicalization can shift; protect every block it may name.
                for ($offset = strpos($markup, $search); false !== $offset; $offset = strpos($markup, $search, $offset + strlen($search))) $ranges[] = array('offset' => $offset, 'length' => strlen($search));
            }
        }
        return $ranges;
    }
    /**
     * @param array<int,array<string,mixed>> $posts
     * @return array<string,true>
     */
    private function sharedPostChromeIdentities(array $posts): array
    {
        $shared = null;
        foreach ($posts as $post) {
            $identities = array();
            foreach (self::blockRanges($post['canonical_block_markup']) as $range) {
                $identity = ShellExtraction::identityMarkup(substr($post['canonical_block_markup'], $range['offset'], $range['length']));
                if ('' !== $identity) {
                    $identities[$identity] = true;
                }
            }
            $shared = null === $shared ? $identities : array_intersect_key($shared, $identities);
        }
        return $shared ?? array();
    }
    /**
     * @param array<string,mixed> $post
     * @param array<string,true> $shared
     * @return array{template:string,body:string,title:bool,date:bool,shared:bool,content:bool,retained:array<int,array{offset:int,length:int}>}
     */
    private function transformPostChromeDocument(string $markup, array $post, string $route, array $shared): array
    {
        $top = array();
        foreach (self::blockRanges($markup) as $range) {
            if (null === self::parentBlockRange($markup, $range)) {
                $top[] = $range;
            }
        }
        usort($top, static fn(array $left, array $right): int => $left['offset'] <=> $right['offset']);
        if (array() === $top) {
            return array('template' => '', 'body' => '', 'title' => false, 'date' => false, 'shared' => false, 'content' => false, 'retained' => array());
        }
        $template = '';
        $body = '';
        $title = false;
        $date = false;
        $sharedChrome = false;
        $content = false;
        $retained = array();
        $cursor = 0;
        foreach ($top as $range) {
            if ($range['offset'] > $cursor) {
                $template .= substr($markup, $cursor, $range['offset'] - $cursor);
            }
            $transformed = $this->transformPostChromeRange($markup, $range, $post, $route, $shared);
            $template .= $transformed['template'];
            $body .= $transformed['body'];
            $title = $title || $transformed['title'];
            $date = $date || $transformed['date'];
            $sharedChrome = $sharedChrome || $transformed['shared'];
            $content = $content || $transformed['content'];
            $retained = array_merge($retained, $transformed['retained']);
            $cursor = $range['offset'] + $range['length'];
        }
        if ($cursor < strlen($markup)) {
            $template .= substr($markup, $cursor);
        }
        return array('template' => $template, 'body' => $body, 'title' => $title, 'date' => $date, 'shared' => $sharedChrome, 'content' => $content, 'retained' => $retained);
    }
    /**
     * @param array{offset:int,length:int} $range
     * @param array<string,mixed> $post
     * @param array<string,true> $shared
     * @return array{template:string,body:string,title:bool,date:bool,shared:bool,content:bool,retained:array<int,array{offset:int,length:int}>}
     */
    private function transformPostChromeRange(string $markup, array $range, array $post, string $route, array $shared): array
    {
        $slice = substr($markup, $range['offset'], $range['length']);
        $empty = array('template' => $slice, 'body' => '', 'title' => false, 'date' => false, 'shared' => false, 'content' => false, 'retained' => array());
        $attrs = self::listingBlockAttributes($slice);
        $className = (string) ($attrs['className'] ?? '');
        $anchor = (string) ($attrs['anchor'] ?? '');
        if (preg_match('/\b(?:social|share-button|twitter-share)\b/', $className) || preg_match('/\bcomments?\b/i', $className) || preg_match('/comment/i', $anchor)) {
            return $empty;
        }
        $identity = ShellExtraction::identityMarkup($slice);
        if ('' !== $identity && isset($shared[$identity])) {
            return array('template' => $slice, 'body' => '', 'title' => false, 'date' => false, 'shared' => true, 'content' => false, 'retained' => array());
        }
        // A closed dialog opens on demand, so it can neither host the post
        // title nor place post-content: both would stay hidden with it. When
        // it varies by post it travels with the body, unplaced.
        if (self::isClosedDialogBlock($slice)) {
            return array('template' => '', 'body' => $slice, 'title' => false, 'date' => false, 'shared' => false, 'content' => false, 'retained' => array(array('offset' => $range['offset'], 'length' => $range['length'])));
        }
        $children = self::childBlockRanges($markup, $range);
        if (array() === $children) {
            $kind = $this->classifyPostChromeBlock($slice, $post, $route);
            if ('title' === $kind) {
                return array('template' => self::postTitleMarkup($slice), 'body' => '', 'title' => true, 'date' => false, 'shared' => false, 'content' => false, 'retained' => array());
            }
            if ('date' === $kind) {
                return array('template' => self::postDateMarkup($slice), 'body' => '', 'title' => false, 'date' => true, 'shared' => false, 'content' => false, 'retained' => array());
            }
            if ('comments' === $kind || 'chrome' === $kind || 'share' === $kind || 'skip' === $kind) {
                return $empty;
            }
            return array('template' => '<!-- wp:post-content /-->', 'body' => $slice, 'title' => false, 'date' => false, 'shared' => false, 'content' => true, 'retained' => array(array('offset' => $range['offset'], 'length' => $range['length'])));
        }
        $foundTitle = false;
        $foundDate = false;
        $foundShared = false;
        $emittedContent = false;
        $body = '';
        $retained = array();
        $replacements = array();
        foreach ($children as $child) {
            $transformed = $this->transformPostChromeRange($markup, $child, $post, $route, $shared);
            $foundShared = $foundShared || $transformed['shared'];
            $body .= $transformed['body'];
            $retained = array_merge($retained, $transformed['retained']);
            if ($transformed['content'] && $emittedContent) {
                // The subtree leaves the template, and with it any title or
                // date it placed: neither is found.
                $replacements[] = array('offset' => $child['offset'], 'length' => $child['length'], 'markup' => '');
                continue;
            }
            $foundTitle = $foundTitle || $transformed['title'];
            $foundDate = $foundDate || $transformed['date'];
            if ($transformed['content']) {
                $emittedContent = true;
            }
            $replacements[] = array('offset' => $child['offset'], 'length' => $child['length'], 'markup' => $transformed['template']);
        }
        usort($replacements, static fn(array $left, array $right): int => $right['offset'] <=> $left['offset']);
        foreach ($replacements as $replacement) {
            $relative = $replacement['offset'] - $range['offset'];
            $slice = substr($slice, 0, $relative) . $replacement['markup'] . substr($slice, $relative + $replacement['length']);
        }
        return array('template' => $slice, 'body' => $body, 'title' => $foundTitle, 'date' => $foundDate, 'shared' => $foundShared, 'content' => $emittedContent, 'retained' => $retained);
    }
    /** @param array<string,mixed> $post */
    private function classifyPostChromeBlock(string $slice, array $post, string $route): string
    {
        if ($this->headingMatchesPostTitle($slice, $post)) {
            return 'title';
        }
        if (self::isCommentFormChrome($slice)) {
            return 'comments';
        }
        $kind = $this->classifyListingCardBlock($slice, $post, $route);
        return 'skip' === $kind ? 'share' : $kind;
    }
    /** @param array<string,mixed> $post */
    private function headingMatchesPostTitle(string $slice, array $post): bool
    {
        $title = trim((string) ($post['title'] ?? ''));
        if ('' === $title || !in_array(self::listingBlockName($slice), array('heading', 'post-title'), true)) {
            return false;
        }
        $normalize = static fn(string $value): string => trim(preg_replace('/\s+/', ' ', str_replace("\xc2\xa0", ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '');
        return $normalize(strip_tags($slice)) === $normalize($title);
    }
    /** Whether a block renders a dialog that is hidden until opened. */
    private static function isClosedDialogBlock(string $slice): bool
    {
        if (!preg_match('/^\s*<!--\s*wp:.*?-->\s*(<(?!!)[^>]*>)/s', $slice, $match)) {
            return false;
        }
        $attributes = HtmlTagScanner::attributes($match[1]);
        if (preg_match('/^<dialog\b/i', $match[1])) {
            return !array_key_exists('open', $attributes);
        }
        if (!array_key_exists('hidden', $attributes) && 'true' !== strtolower($attributes['aria-hidden'] ?? '')) {
            return false;
        }
        return 'dialog' === strtolower($attributes['role'] ?? '')
            || 'true' === strtolower($attributes['aria-modal'] ?? '')
            || array_key_exists(CaptureEvidence::DIALOG_PANEL, $attributes);
    }
    private static function isCommentFormChrome(string $slice): bool
    {
        return str_contains($slice, 'Leave a Reply') || 1 === preg_match('/<iframe\b[^>]*(?:comment|Comment)/', $slice);
    }
    /**
     * @param array<int,array<string,mixed>> $pages
     * @param bool $postChromeSettled Whether shared shells and article chrome
     *        have been factored out, so post content is final.
     * @return array<int,array<string,mixed>>
     */
    private function materializeListingQueryLoops(array $pages, array $runtimeDeclarations = array(), array $taxonomyEntities = array(), bool $postChromeSettled = false): array
    {
        $postsByParent = array();
        $postsByArchive = array();
        $excerpts = array();
        $featuredImages = array();
        $postMeta = array();
        $bindingsBySource = array();
        foreach ($runtimeDeclarations as $declaration) foreach ($declaration['payload']['entities'] ?? array() as $entity) foreach ($entity['bindings'] ?? array() as $binding) {
            $source = $binding['source_path'] ?? null;
            $search = $binding['search_block_markup'] ?? null;
            if (is_string($source) && is_string($search) && '' !== $search) $bindingsBySource[$source][] = $search;
        }
        foreach ($pages as $page) {
            if ('post' !== ($page['post_type'] ?? null) || !empty($page['synthetic']) || !is_string($page['route']['path'] ?? null)) {
                continue;
            }
            $postsByParent[self::parentRoutePath($page['route']['path'])][] = $page;
        }
        $pagesBySource = array_column($pages, null, 'source_path');
        foreach ($taxonomyEntities as $entity) foreach ($entity['membership_source_paths'] as $sourcePath) if (isset($pagesBySource[$sourcePath])) $postsByArchive[$entity['archive']['source_path']][] = $pagesBySource[$sourcePath];
        foreach ($pages as &$page) {
            if ('page' !== ($page['post_type'] ?? null) || !empty($page['synthetic']) || !is_string($page['route']['path'] ?? null)) {
                continue;
            }
            $posts = $postsByArchive[$page['source_path']] ?? ($postsByParent[$page['route']['path']] ?? array());
            if (count($posts) < (isset($postsByArchive[$page['source_path']]) ? 1 : 2) || !is_string($page['canonical_block_markup'] ?? null) || str_contains($page['canonical_block_markup'], '<!-- wp:query')) {
                continue;
            }
            $sourceListingMarkup = $page['canonical_block_markup'];
            $fields = array();
            $replaced = $this->replaceListingMarkup($sourceListingMarkup, $posts, $fields, isset($postsByArchive[$page['source_path']]));
            if (null === $replaced || $replaced === $page['canonical_block_markup']) {
                continue;
            }
            // A card that renders post content repeats whatever chrome the posts
            // still own. When a post still carries a block the listing page
            // already renders around its cards (a banner, sidebar or comment
            // form that could not move to the single template), every listing
            // item would repeat it, so the listing stays as captured.
            if ($postChromeSettled && substr_count($replaced, '<!-- wp:post-content') > substr_count($sourceListingMarkup, '<!-- wp:post-content')
                && $this->postsRepeatListingChrome($sourceListingMarkup, $posts)
            ) {
                continue;
            }
            // A template cannot replace a source region that owns a live entity.
            foreach ($bindingsBySource[$page['source_path']] ?? array() as $anchor) {
                if (substr_count($replaced, $anchor) !== substr_count($page['canonical_block_markup'], $anchor)) continue 2;
            }
            // The query loop repeats the first card. A card menu the loop does
            // not render would be lost, so the listing stays as captured.
            if (array() !== array_diff_key(NavigationEntityProjection::renderedMenus($page['canonical_block_markup']), NavigationEntityProjection::renderedMenus($replaced))) continue;
            $page['canonical_block_markup'] = $replaced;
            $page['content_hash'] = self::contentHash($replaced);
            $firstCard = ($this->listingCardRanges($sourceListingMarkup, $posts, isset($postsByArchive[$page['source_path']]) ? 1 : 2) ?? array())[0] ?? null;
            $parent = is_array($firstCard) ? self::parentBlockRange($sourceListingMarkup, $firstCard) : null;
            if (is_array($parent)) $this->listingQueryContainers[] = substr($sourceListingMarkup, $parent['offset'], $parent['length']);
            foreach ($fields as $source => $values) $postMeta[$source] = ($postMeta[$source] ?? array()) + $values;
            foreach ($this->listingCardRanges($sourceListingMarkup, $posts, isset($postsByArchive[$page['source_path']]) ? 1 : 2) ?? array() as $card) {
                if (str_contains($replaced, '<!-- wp:post-featured-image')) {
                    $cardMarkup = substr($sourceListingMarkup, $card['offset'], $card['length']);
                    foreach (self::blockRanges($cardMarkup) as $leaf) {
                        $slice = substr($cardMarkup, $leaf['offset'], $leaf['length']);
                        if ('featured-image' !== $this->classifyListingCardBlock($slice, $card['post'], $card['route']) && !('image' === self::listingBlockName($slice) && str_contains($cardMarkup, '"wrappers"'))) continue;
                        $image = self::listingCardImage($slice);
                        if (null !== $image) $featuredImages[$card['post']['source_path']] = $image;
                        break;
                    }
                }
                $description = ListingExcerptProjection::description($card['post']);
                if (null === $description) continue;
                $cardMarkup = substr($sourceListingMarkup, $card['offset'], $card['length']);
                foreach (self::blockRanges($cardMarkup) as $leaf) {
                    $slice = substr($cardMarkup, $leaf['offset'], $leaf['length']);
                    if ('paragraph' === self::listingBlockName($slice) && ListingExcerptProjection::matches($slice, $card['post'])) { $excerpts[$card['post']['source_path']] = $description; break; }
                }
            }
        }
        unset($page);
        foreach ($pages as &$page) if (isset($excerpts[$page['source_path']])) $page['metadata']['excerpt'] = $excerpts[$page['source_path']];
        unset($page);
        foreach ($pages as &$page) if (isset($featuredImages[$page['source_path']]) && !isset($page['metadata']['featured_image'])) $page['metadata']['featured_image'] = $featuredImages[$page['source_path']];
        unset($page);
        foreach ($pages as &$page) if (isset($postMeta[$page['source_path']])) $page['metadata']['post_meta'] = ($page['metadata']['post_meta'] ?? array()) + $postMeta[$page['source_path']];
        unset($page);
        return $pages;
    }
    /**
     * Whether any post still owns a container block that the listing page
     * renders outside its cards.
     *
     * @param array<int,array<string,mixed>> $posts
     */
    private function postsRepeatListingChrome(string $markup, array $posts): bool
    {
        $cards = $this->listingCardRanges($markup, $posts) ?? array();
        $chrome = array();
        foreach (self::blockRanges($markup) as $range) {
            $end = $range['offset'] + $range['length'];
            foreach ($cards as $card) {
                if ($range['offset'] < $card['offset'] + $card['length'] && $card['offset'] < $end) continue 2;
            }
            if (array() === self::childBlockRanges($markup, $range)) continue;
            $identity = ShellExtraction::identityMarkup(substr($markup, $range['offset'], $range['length']));
            if ('' !== $identity) $chrome[$identity] = true;
        }
        if (array() === $chrome) return false;
        foreach ($posts as $post) {
            $postMarkup = (string) ($post['canonical_block_markup'] ?? '');
            foreach (self::blockRanges($postMarkup) as $range) {
                if (array() === self::childBlockRanges($postMarkup, $range)) continue;
                if (isset($chrome[ShellExtraction::identityMarkup(substr($postMarkup, $range['offset'], $range['length']))])) return true;
            }
        }
        return false;
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
    /** @param array<int,array<string,mixed>> $posts */
    private function replaceListingMarkup(string $markup, array $posts, array &$fields = array(), bool $inheritQuery = false): ?string
    {
        $cards = $this->listingCardRanges($markup, $posts, $inheritQuery ? 1 : 2);
        if (null === $cards) {
            return $this->replaceListingArchives($markup, $posts);
        }
        $template = $this->listingCardTemplate($markup, $cards, $fields);
        if (null === $template) {
            return $this->replaceListingArchives($markup, $posts);
        }
        $start = $cards[0]['offset'];
        $end = $cards[count($cards) - 1]['offset'] + $cards[count($cards) - 1]['length'];
        $query = self::listingQueryMarkup(count($cards), $template, $inheritQuery);
        $parent = self::parentBlockRange($markup, $cards[0]);
        if (is_array($parent) && 'columns' === self::listingBlockName(substr($markup, $parent['offset'], $parent['length']))) {
            $query = '<!-- wp:column --><div class="wp-block-column">' . $query . '</div><!-- /wp:column -->';
        }
        $replaced = substr($markup, 0, $start) . $query . substr($markup, $end);
        return $this->replaceListingArchives($replaced, $posts);
    }
    /**
     * @param array<int,array<string,mixed>> $posts
     * @return array<int,array{offset:int,length:int,route:string,post:array<string,mixed>}>|null
     */
    private function listingCardRanges(string $markup, array $posts, int $minimumCards = 2): ?array
    {
        $routes = array();
        foreach ($posts as $post) {
            if (is_string($post['route']['path'] ?? null)) {
                $routes[$post['route']['path']] = $post;
            }
        }
        $ranges = self::blockRanges($markup);
        $cards = array();
        foreach ($routes as $route => $post) {
            $exclusive = null;
            foreach ($ranges as $range) {
                $slice = substr($markup, $range['offset'], $range['length']);
                // A single reciprocal card must not swallow the archive heading.
                if (1 === count($routes) && preg_match('/<h1\b/i', $slice)) continue;
                $anchors = $this->listingAnchors($slice);
                $hits = false;
                $others = false;
                foreach ($anchors as $anchor) {
                    if (self::hrefMatchesListingRoute($anchor['href'], $route)) {
                        $hits = true;
                    } elseif ($this->hrefMatchesAnyListingRoute($anchor['href'], array_keys($routes))) {
                        $others = true;
                    }
                }
                if (!$hits || $others) {
                    continue;
                }
                if (null === $exclusive || $range['length'] > $exclusive['length']) {
                    $exclusive = array('offset' => $range['offset'], 'length' => $range['length'], 'route' => $route, 'post' => $post);
                }
            }
            if (null !== $exclusive) {
                $cards[] = $exclusive;
            }
        }
        if (count($cards) < $minimumCards) {
            return null;
        }
        usort($cards, static fn(array $left, array $right): int => $left['offset'] <=> $right['offset']);
        $parent = self::parentBlockRange($markup, $cards[0]);
        for ($index = 0; $index < count($cards); ++$index) {
            if ($index > 0 && $cards[$index]['offset'] < $cards[$index - 1]['offset'] + $cards[$index - 1]['length']) {
                return null;
            }
            $cardParent = self::parentBlockRange($markup, $cards[$index]);
            if ((null === $parent) !== (null === $cardParent) || (is_array($parent) && is_array($cardParent) && ($parent['offset'] !== $cardParent['offset'] || $parent['length'] !== $cardParent['length']))) {
                return null;
            }
        }
        return $cards;
    }
    /**
     * @param array<int,array{offset:int,length:int,route:string,post:array<string,mixed>}> $cards
     */
    private function listingCardTemplate(string $markup, array $cards, array &$fields): ?string
    {
        $first = $cards[0];
        $groups = $this->listingLinkedMetadataGroups($markup, $first);
        $boundGroups = array();
        foreach ($groups as $index => $group) {
            $key = 'blocks_engine_listing_labels_' . substr(hash('sha256', $group['markup']), 0, 12);
            $content = ListingFieldProjection::content($group['paragraphs']);
            $paragraph = ListingFieldProjection::paragraph($group['paragraphs'][0], self::listingBlockAttributes($group['paragraphs'][0]), $key, $content);
            $bound = $group['markup'];
            foreach (array_reverse($group['ranges'], true) as $childIndex => $child) {
                $relative = $child['offset'] - $group['offset'];
                $bound = substr($bound, 0, $relative) . (0 === $childIndex ? $paragraph : '') . substr($bound, $relative + $child['length']);
            }
            $boundGroups[$group['offset']] = $bound;
            foreach ($cards as $card) {
                $row = $this->listingLinkedMetadataGroups($markup, $card)[$index] ?? null;
                $fields[$card['post']['source_path']][$key] = null === $row ? '' : ListingFieldProjection::content($row['paragraphs']);
            }
        }
        $compact = !array_filter($cards, fn(array $card): bool => !$this->isExcerptListingCard($markup, $card));
        $sharedText = null;
        foreach ($cards as $card) {
            $texts = array();
            $cardMarkup = substr($markup, $card['offset'], $card['length']);
            foreach (self::blockRanges($cardMarkup) as $leaf) {
                if (array() !== self::childBlockRanges($cardMarkup, $leaf)) continue;
                $texts[self::listingLeafIdentity(substr($cardMarkup, $leaf['offset'], $leaf['length']))] = true;
            }
            $sharedText = null === $sharedText ? $texts : array_intersect_key($sharedText, $texts);
        }
        $transformed = $this->listingTransformRange($markup, $first, $first['post'], $first['route'], $compact ? ListingExcerptProjection::length($cards) : 0, $boundGroups, false, $sharedText ?? array());
        if (null === $transformed || !$transformed['title'] || '' === $transformed['markup']) {
            return null;
        }
        $template = $transformed['markup'];
        $gaps = array();
        for ($index = 1; $index < count($cards); ++$index) {
            $gaps[] = substr($markup, $cards[$index - 1]['offset'] + $cards[$index - 1]['length'], $cards[$index]['offset'] - ($cards[$index - 1]['offset'] + $cards[$index - 1]['length']));
        }
        $uniqueGaps = array_unique($gaps);
        if (1 === count($uniqueGaps) && '' !== trim($uniqueGaps[0]) && !str_contains($uniqueGaps[0], '<!-- wp:')) {
            $template .= $uniqueGaps[0];
        } elseif (1 === count($uniqueGaps) && 1 === preg_match('/^<!-- wp:(?:separator|spacer)\b/', trim($uniqueGaps[0]))) {
            $template .= $uniqueGaps[0];
        }
        return $template;
    }

    /** @param array<string,mixed> $card @return list<array{offset:int,markup:string,paragraphs:list<string>}> */
    private function listingLinkedMetadataGroups(string $markup, array $card): array
    {
        $groups = array();
        foreach (self::blockRanges($markup) as $range) {
            if ($range['offset'] <= $card['offset'] || $range['offset'] + $range['length'] >= $card['offset'] + $card['length']) continue;
            $slice = substr($markup, $range['offset'], $range['length']);
            if ('group' !== self::listingBlockName($slice)) continue;
            $children = self::childBlockRanges($markup, $range);
            if (array() === $children) continue;
            $paragraphs = array();
            foreach ($children as $child) {
                $paragraph = substr($markup, $child['offset'], $child['length']);
                $anchors = $this->listingAnchors($paragraph);
                if ('paragraph' !== self::listingBlockName($paragraph) || !ListingFieldProjection::isLinkedMetadata($paragraph) || array() === $anchors || ListingExcerptProjection::matches($paragraph, $card['post']) || array_filter($anchors, static fn(array $anchor): bool => self::hrefMatchesListingRoute($anchor['href'], $card['route']))) continue 2;
                $paragraphs[] = $paragraph;
            }
            $groups[] = array('offset' => $range['offset'], 'markup' => $slice, 'paragraphs' => $paragraphs, 'ranges' => $children);
        }
        return $groups;
    }

    /** @param array<string,mixed> $card */
    private function isExcerptListingCard(string $markup, array $card): bool
    {
        $description = false;
        $slice = substr($markup, $card['offset'], $card['length']);
        foreach (self::blockRanges($slice) as $range) {
            $paragraph = substr($slice, $range['offset'], $range['length']);
            if ('paragraph' !== self::listingBlockName($paragraph)) continue;
            if (ListingExcerptProjection::matches($paragraph, $card['post'])) { $description = true; continue; }
            if ('body' === $this->classifyListingCardBlock($paragraph, $card['post'], $card['route']) && !ListingFieldProjection::isLinkedMetadata($paragraph)) return false;
        }
        return $description;
    }
    /**
     * @param array{offset:int,length:int} $range
     * @param array<string,mixed> $post
     * @param array<string,true> $sharedText
     * @return array{markup:string,title:bool,content:bool}|null
     */
    private function listingTransformRange(string $markup, array $range, array $post, string $route, int $excerptLength = 0, array $boundGroups = array(), bool $linkedContainer = false, array $sharedText = array()): ?array
    {
        $slice = substr($markup, $range['offset'], $range['length']);
        if (isset($boundGroups[$range['offset']])) return array('markup' => $boundGroups[$range['offset']], 'title' => false, 'content' => false);
        $className = (string) (self::listingBlockAttributes($slice)['className'] ?? '');
        if (preg_match('/\b(?:social|share-button|twitter-share)\b/', $className)) {
            return array('markup' => '', 'title' => false, 'content' => false);
        }
        if (preg_match('/\bseparator\b/', $className)) {
            return array('markup' => $slice, 'title' => false, 'content' => false);
        }
        $children = self::childBlockRanges($markup, $range);
        if (isset(self::listingBlockAttributes($slice)['wrappers'])) {
            foreach (self::listingBlockAttributes($slice)['wrappers'] ?? array() as $wrapper) {
                if ('a' === ($wrapper['tagName'] ?? '') && self::hrefMatchesListingRoute((string) ($wrapper['attributes']['href'] ?? ''), $route)) $linkedContainer = true;
            }
        }
        if (array() === $children) {
            if ('paragraph' === self::listingBlockName($slice) && '' === ListingExcerptProjection::text($slice)) {
                foreach (HtmlMarkupParser::htmlMarkupNodes($slice) as $node) {
                    $identity = $node['attributes'] ?? array();
                    if ('a' === ($node['name'] ?? '') && self::hrefMatchesListingRoute((string) ($identity['href'] ?? ''), $route) && 'true' === ($identity['aria-hidden'] ?? '') && '-1' === ($identity['tabindex'] ?? '')) {
                        $attrs = array('className' => trim((string) ($identity['class'] ?? '') . ' blocks-engine-listing-overlay'), 'content' => '<span aria-hidden="true"></span>');
                        return array('markup' => '<!-- wp:read-more ' . BlockAttributes::encode($attrs) . ' /-->', 'title' => false, 'content' => false);
                    }
                }
            }
            if ($excerptLength > 0 && 'paragraph' === self::listingBlockName($slice) && ListingExcerptProjection::matches($slice, $post)) {
                $attrs = self::listingBlockAttributes($slice);
                unset($attrs['metadata']);
                $attrs['excerptLength'] = $excerptLength;
                $attrs['className'] = trim((string) ($attrs['className'] ?? '') . ' blocks-engine-authored-excerpt');
                return array('markup' => '<!-- wp:post-excerpt ' . BlockAttributes::encode($attrs) . ' /-->', 'title' => false, 'content' => false);
            }
            $kind = $this->classifyListingCardBlock($slice, $post, $route);
            if ($linkedContainer && 'heading' === self::listingBlockName($slice)) {
                $kind = 'title';
            } elseif ($linkedContainer && 'image' === self::listingBlockName($slice) && null !== self::listingCardImage($slice)) {
                $kind = 'featured-image';
            }
            if ('title' === $kind) {
                return array('markup' => self::postTitleMarkup($slice), 'title' => true, 'content' => false);
            }
            if ('date' === $kind) {
                return array('markup' => self::postDateMarkup($slice), 'title' => false, 'content' => false);
            }
            if ('featured-image' === $kind) {
                return array('markup' => self::postFeaturedImageMarkup($slice), 'title' => false, 'content' => false);
            }
            if ('unbound' === $kind) {
                // Repeating an unbound per-post literal is no more truthful than
                // rendering the full article in its place. Decline the listing.
                if (!isset($sharedText[self::listingLeafIdentity($slice)])) return null;
                $kind = 'chrome';
            }
            // Media and other textless leaves carry no visible text to compare,
            // so they are shared only when every card renders the same markup.
            if ('chrome' === $kind && !isset($sharedText[self::listingLeafIdentity($slice)])) return null;
            if ('comments' === $kind || 'chrome' === $kind) {
                return array('markup' => $slice, 'title' => false, 'content' => false);
            }
            if ('skip' === $kind) {
                return array('markup' => '', 'title' => false, 'content' => false);
            }
            return array('markup' => '<!-- wp:post-content /-->', 'title' => false, 'content' => true);
        }
        $foundTitle = false;
        $emittedContent = false;
        $replacements = array();
        foreach ($children as $child) {
            $transformed = $this->listingTransformRange($markup, $child, $post, $route, $excerptLength, $boundGroups, $linkedContainer, $sharedText);
            if (null === $transformed) return null;
            $foundTitle = $foundTitle || $transformed['title'];
            if ($transformed['content'] && $emittedContent) {
                $replacements[] = array('offset' => $child['offset'], 'length' => $child['length'], 'markup' => '');
                continue;
            }
            if ($transformed['content']) {
                $emittedContent = true;
            }
            $replacements[] = array('offset' => $child['offset'], 'length' => $child['length'], 'markup' => $transformed['markup']);
        }
        usort($replacements, static fn(array $left, array $right): int => $right['offset'] <=> $left['offset']);
        foreach ($replacements as $replacement) {
            $relative = $replacement['offset'] - $range['offset'];
            $slice = substr($slice, 0, $relative) . $replacement['markup'] . substr($slice, $relative + $replacement['length']);
        }
        // Native query slots own their per-post links. A retained source card
        // shell must not wrap them in the first post's static destination.
        $attrs = self::listingBlockAttributes($slice);
        if (isset($attrs['wrappers'])) {
            $changed = false;
            foreach ($attrs['wrappers'] as &$wrapper) {
                if ('a' === ($wrapper['tagName'] ?? '') && self::hrefMatchesListingRoute((string) ($wrapper['attributes']['href'] ?? ''), $route)) {
                    $wrapper['tagName'] = 'div';
                    unset($wrapper['attributes']['href'], $wrapper['attributes']['target'], $wrapper['attributes']['rel']);
                    $changed = true;
                }
            }
            unset($wrapper);
            if ($changed) {
                $slice = preg_replace_callback('/^(<!--\\s*wp:[^\\s]+)\\s+\\{.*?\\}\\s*-->/', static fn(array $match): string => $match[1] . ' ' . BlockAttributes::encode($attrs) . ' -->', $slice, 1) ?? $slice;
                $slice = preg_replace('/(<a)\\b[^>]*>/', '<div' . self::listingWrapperAttributes($attrs) . '>', $slice, 1) ?? $slice;
                $position = strrpos($slice, '</a>');
                if (false !== $position) $slice = substr_replace($slice, '</div>', $position, 4);
            }
        }
        return array('markup' => $slice, 'title' => $foundTitle, 'content' => $emittedContent && !$foundTitle);
    }
    /** Visible text identifies a card leaf; a textless leaf is identified by its markup. */
    private static function listingLeafIdentity(string $leaf): string
    {
        $text = ListingExcerptProjection::text($leaf);
        return '' !== $text ? $text : "\0" . $leaf;
    }

    /** @param array<string,mixed> $attrs */
    private static function listingWrapperAttributes(array $attrs): string
    {
        $html = '';
        foreach ($attrs['wrappers'][0]['attributes'] ?? array() as $name => $value) {
            $html .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        return $html;
    }

    /** @param array<string,mixed> $post */
    private function classifyListingCardBlock(string $slice, array $post, string $route): string
    {
        $anchors = $this->listingAnchors($slice);
        foreach ($anchors as $anchor) {
            if (str_contains(strtolower($anchor['href']), '#comment') || preg_match('/\bcomments?\b/i', $anchor['text'])) {
                return 'comments';
            }
        }
        $hasRouteLink = false;
        foreach ($anchors as $anchor) {
            if (self::hrefMatchesListingRoute($anchor['href'], $route)) {
                $hasRouteLink = true;
                break;
            }
        }
        $name = self::listingBlockName($slice);
        if ($hasRouteLink && in_array($name, array('heading', 'post-title'), true)) {
            return 'title';
        }
        $timestamp = is_string($post['publication_timestamp'] ?? null) ? $post['publication_timestamp'] : null;
        foreach (HtmlMarkupParser::htmlMarkupNodes($slice) as $node) {
            if ('tag' !== ($node['kind'] ?? null) || !is_int($node['inner_offset'] ?? null)) {
                continue;
            }
            $attributes = is_array($node['attributes'] ?? null) ? $node['attributes'] : array();
            $datedTime = null !== $timestamp && 'time' === ($node['name'] ?? '') && is_string($attributes['datetime'] ?? null);
            // A machine-readable datetime proves the slot even when the visible
            // text only carries the day of a timestamp that has a time of day.
            if ($datedTime && self::normalizePublicationTimestamp((string) $attributes['datetime']) === $timestamp) {
                return 'date';
            }
            if (!self::isVisibleDateElement((string) ($node['name'] ?? ''), $attributes) && !$datedTime) {
                continue;
            }
            $parsed = self::parseVisiblePublicationTimestamp(self::elementInnerText($slice, (string) $node['name'], $node['inner_offset']));
            if (null !== $parsed && (null === $timestamp || $parsed === $timestamp)) {
                return 'date';
            }
        }
        if (preg_match('/<(?:iframe|fb:like)\b/i', $slice) || preg_match('/\b(?:blog-social|share-button|twitter-share)\b/', $slice)) {
            return 'skip';
        }
        if ('image' === $name && $hasRouteLink && null !== self::listingCardImage($slice)) {
            return 'featured-image';
        }
        if (in_array($name, array('image', 'gallery', 'video', 'audio', 'embed', 'media-text', 'cover'), true)) {
            return 'body';
        }
        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($slice), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '', " \t\n\r\0\x0B\xC2\xA0");
        if ('' === $text || in_array($name, array('separator', 'spacer'), true) || preg_match('/\bseparator\b/', (string) (self::listingBlockAttributes($slice)['className'] ?? ''))) {
            return 'chrome';
        }
        // Recognize actual article leaves before considering unbound card text.
        $postMarkup = (string) ($post['canonical_block_markup'] ?? '');
        foreach (self::blockRanges($postMarkup) as $range) {
            if (array() !== self::childBlockRanges($postMarkup, $range)) continue;
            if (ListingExcerptProjection::text(substr($postMarkup, $range['offset'], $range['length'])) === ListingExcerptProjection::text($slice)) return 'body';
        }
        // Keep the legacy content-listing contract for paragraphs without an
        // authored summary. Summary cards must never infer body ownership from
        // an otherwise unrecognized text leaf.
        return ListingExcerptProjection::matches($slice, $post) || ('paragraph' === $name && null === ListingExcerptProjection::description($post)) ? 'body' : 'unbound';
    }
    /**
     * The single plan-owned image of a card slot, which the post owns as its
     * featured image. Remote or ambiguous images stay out of the handoff.
     *
     * @return array{asset_token:string,alt:string}|null
     */
    private static function listingCardImage(string $slice): ?array
    {
        $images = array_values(array_filter(HtmlMarkupParser::htmlMarkupNodes($slice), static fn(array $node): bool => 'tag' === ($node['kind'] ?? null) && 'img' === strtolower((string) ($node['name'] ?? ''))));
        if (1 !== count($images)) return null;
        $attributes = is_array($images[0]['attributes'] ?? null) ? $images[0]['attributes'] : array();
        if (1 !== preg_match('/^' . preg_quote(self::TOKEN_PREFIX, '/') . '(asset-[0-9a-f]{16})\}\}$/', (string) ($attributes['src'] ?? ''), $match)) return null;
        return array('asset_token' => $match[1], 'alt' => html_entity_decode((string) ($attributes['alt'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    private static function postFeaturedImageMarkup(string $slice): string
    {
        $source = self::listingBlockAttributes($slice);
        $attrs = array('isLink' => true);
        foreach (array('width', 'height', 'scale', 'aspectRatio', 'style') as $key) {
            if (isset($source[$key]) && (is_string($source[$key]) || is_array($source[$key]))) $attrs[$key] = $source[$key];
        }
        $classes = array_values(array_filter(preg_split('/\s+/', (string) ($source['className'] ?? '')) ?: array(), static fn(string $class): bool => '' !== $class && 'is-resized' !== $class));
        if (array() !== $classes) $attrs['className'] = implode(' ', $classes);
        return '<!-- wp:post-featured-image ' . BlockAttributes::encode($attrs) . ' /-->';
    }
    private static function listingQueryMarkup(int $perPage, string $template, bool $inherit = false): string
    {
        $attrs = array(
            'queryId' => 1,
            'query' => array(
                'perPage' => $perPage,
                'pages' => 0,
                'offset' => 0,
                'postType' => 'post',
                'order' => 'desc',
                'orderBy' => 'date',
                'author' => '',
                'search' => '',
                'exclude' => array(),
                'sticky' => '',
                'inherit' => $inherit,
            ),
            'className' => self::LISTING_QUERY_CLASS,
        );
        $encoded = BlockAttributes::encode($attrs);
        $pagination = $inherit
            ? '<!-- wp:query-pagination {"paginationArrow":"arrow","layout":{"type":"flex","justifyContent":"space-between"}} --><!-- wp:query-pagination-previous /--><!-- wp:query-pagination-next /--><!-- /wp:query-pagination --><!-- wp:query-no-results --><!-- wp:paragraph --><p>No posts found.</p><!-- /wp:paragraph --><!-- /wp:query-no-results -->'
            : '';
        return '<!-- wp:query ' . $encoded . ' -->' . '<div class="wp-block-query ' . self::LISTING_QUERY_CLASS . '"><!-- wp:post-template -->' . $template . '<!-- /wp:post-template -->' . $pagination . '</div>' . '<!-- /wp:query -->';
    }
    private static function postTitleMarkup(string $slice): string
    {
        $attrs = array('isLink' => true);
        $blockAttrs = self::listingBlockAttributes($slice);
        if (is_array($blockAttrs['style'] ?? null)) $attrs['style'] = $blockAttrs['style'];
        $level = is_int($blockAttrs['level'] ?? null) ? $blockAttrs['level'] : self::listingHeadingLevel($slice);
        if (2 !== $level) {
            $attrs['level'] = $level;
        }
        $className = self::listingPreservedClassName($slice, array('wp-block-heading'));
        if (null !== $className) {
            $attrs['className'] = $className;
        }
        foreach (HtmlMarkupParser::htmlMarkupNodes($slice) as $node) if ('a' === ($node['name'] ?? '') && is_string($node['attributes']['class'] ?? null)) $attrs['metadata']['blocksEngineLinkClass'] = $node['attributes']['class'];
        return '<!-- wp:post-title ' . BlockAttributes::encode($attrs) . ' /-->';
    }
    private static function postDateMarkup(string $slice): string
    {
        $attrs = array();
        $blockAttrs = self::listingBlockAttributes($slice);
        if (is_array($blockAttrs['style'] ?? null)) $attrs['style'] = $blockAttrs['style'];
        $format = self::visibleDateFormat(trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($slice), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? ''));
        if (null !== $format) {
            $attrs['format'] = $format;
        }
        $className = self::listingPreservedClassName($slice, array('wp-block-paragraph'));
        if (null !== $className) {
            $attrs['className'] = $className;
        }
        $encoded = array() === $attrs ? '' : ' ' . BlockAttributes::encode($attrs);
        return '<!-- wp:post-date' . $encoded . ' /-->';
    }
    private static function listingHeadingLevel(string $slice): int
    {
        foreach (HtmlMarkupParser::htmlMarkupNodes($slice) as $node) {
            if ('tag' === ($node['kind'] ?? null) && preg_match('/^h([1-6])$/', (string) ($node['name'] ?? ''), $match)) {
                return (int) $match[1];
            }
        }
        return 2;
    }
    /** @param array<int,string> $omit */
    private static function listingPreservedClassName(string $slice, array $omit): ?string
    {
        $blockAttrs = self::listingBlockAttributes($slice);
        $classes = array();
        if (is_string($blockAttrs['className'] ?? null) && '' !== trim($blockAttrs['className'])) {
            foreach (preg_split('/\s+/', trim($blockAttrs['className'])) ?: array() as $token) {
                $classes[$token] = true;
            }
        }
        foreach (HtmlMarkupParser::htmlMarkupNodes($slice) as $node) {
            if ('tag' !== ($node['kind'] ?? null) || !is_string($node['attributes']['class'] ?? null)) {
                continue;
            }
            $contributed = false;
            foreach (preg_split('/\s+/', trim($node['attributes']['class'])) ?: array() as $token) {
                if ('' === $token || str_starts_with($token, 'wp-block-') || in_array($token, $omit, true) || str_starts_with($token, 'blocks-engine-')) {
                    continue;
                }
                $classes[$token] = true;
                $contributed = true;
            }
            if ($contributed) {
                break;
            }
        }
        if (array() === $classes) {
            return null;
        }
        return implode(' ', array_keys($classes));
    }
    /** @return array<string,mixed> */
    private static function listingBlockAttributes(string $slice): array
    {
        foreach (HtmlMarkupParser::htmlMarkupNodes($slice) as $node) {
            if ('comment' !== ($node['kind'] ?? null)) {
                continue;
            }
            $attributes = self::blockCommentAttributes((string) ($node['content'] ?? ''));
            return is_array($attributes) ? $attributes : array();
        }
        return array();
    }
    private static function listingBlockName(string $slice): string
    {
        return preg_match('/^<!--\s*wp:([a-z0-9-]+)/', ltrim($slice), $match) ? $match[1] : '';
    }
    /** @param array<int,array<string,mixed>> $posts */
    private function replaceListingArchives(string $markup, array $posts): string
    {
        $months = $this->listingPostMonths($posts);
        if (array() === $months) {
            return $markup;
        }
        $best = null;
        foreach (self::blockRanges($markup) as $range) {
            $slice = substr($markup, $range['offset'], $range['length']);
            if (str_contains($slice, '<!-- wp:query') || str_contains($slice, '<!-- wp:post-template') || str_contains($slice, '<!-- wp:archives')) {
                continue;
            }
            $labels = array();
            $other = false;
            foreach ($this->listingAnchors($slice) as $anchor) {
                if (1 === preg_match('/^[A-Z][a-z]+ \d{4}$/', $anchor['text'])) {
                    $labels[$anchor['text']] = true;
                    continue;
                }
                $other = true;
            }
            ksort($labels);
            if ($other || $labels !== $months) {
                continue;
            }
            if (null === $best || $range['length'] < $best['length']) {
                $best = $range;
            }
        }
        if (null === $best) {
            return $markup;
        }
        return substr($markup, 0, $best['offset']) . '<!-- wp:archives /-->' . substr($markup, $best['offset'] + $best['length']);
    }
    /** @param array<int,array<string,mixed>> $posts @return array<string,true> */
    private function listingPostMonths(array $posts): array
    {
        $months = array();
        foreach ($posts as $post) {
            if (!is_string($post['publication_timestamp'] ?? null)) {
                continue;
            }
            try {
                $date = new \DateTimeImmutable($post['publication_timestamp']);
            } catch (\Exception) {
                continue;
            }
            $months[$date->setTimezone(new \DateTimeZone('UTC'))->format('F Y')] = true;
        }
        ksort($months);
        return $months;
    }
    /** @return array<int,array{href:string,text:string}> */
    private function listingAnchors(string $html): array
    {
        $anchors = array();
        foreach (HtmlMarkupParser::htmlMarkupNodes($html) as $node) {
            if ('tag' !== ($node['kind'] ?? null) || 'a' !== ($node['name'] ?? null) || !is_string($node['attributes']['href'] ?? null)) {
                continue;
            }
            $href = html_entity_decode($node['attributes']['href'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = is_int($node['inner_offset'] ?? null) ? self::elementInnerText($html, 'a', $node['inner_offset']) : '';
            $anchors[] = array('href' => $href, 'text' => $text);
        }
        return $anchors;
    }
    /** @param array<int,string> $routes */
    private function hrefMatchesAnyListingRoute(string $href, array $routes): bool
    {
        foreach ($routes as $route) {
            if (self::hrefMatchesListingRoute($href, $route)) {
                return true;
            }
        }
        return false;
    }
    private static function hrefMatchesListingRoute(string $href, string $route): bool
    {
        $path = parse_url($href, PHP_URL_PATH);
        if (!is_string($path) || '' === $path) {
            $path = $href;
        }
        $path = '/' . trim($path, '/');
        if ($path === $route) {
            return true;
        }
        if (str_ends_with($path, '/index.html') && substr($path, 0, -11) === $route) {
            return true;
        }
        return false;
    }
    private static function visibleDateFormat(string $value): ?string
    {
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $match)) {
            return ((int) $match[1] > 12 && (int) $match[2] <= 12) ? 'j/n/Y' : 'n/j/Y';
        }
        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $value, $match)) {
            return ((int) $match[1] > 12 && (int) $match[2] <= 12) ? 'j-n-Y' : 'n-j-Y';
        }
        foreach (array('M j, Y' => '/^[A-Z][a-z]{2} \d{1,2}, \d{4}$/', 'F j, Y' => '/^[A-Z][a-z]+ \d{1,2}, \d{4}$/', 'j M Y' => '/^\d{1,2} [A-Z][a-z]{2} \d{4}$/', 'j F Y' => '/^\d{1,2} [A-Z][a-z]+ \d{4}$/') as $format => $pattern) {
            if (1 === preg_match($pattern, $value)) {
                return $format;
            }
        }
        return null;
    }
    /** @param array{offset:int,length:int} $parent @return array<int,array{offset:int,length:int}> */
    private static function childBlockRanges(string $markup, array $parent): array
    {
        $parentStart = $parent['offset'];
        $parentEnd = $parent['offset'] + $parent['length'];
        $inner = array();
        foreach (self::blockRanges($markup) as $range) {
            $start = $range['offset'];
            $end = $start + $range['length'];
            if ($start <= $parentStart || $end >= $parentEnd) {
                continue;
            }
            $inner[] = $range;
        }
        $children = array();
        foreach ($inner as $range) {
            $nested = false;
            foreach ($inner as $other) {
                if ($other['offset'] === $range['offset'] && $other['length'] === $range['length']) {
                    continue;
                }
                if ($other['offset'] < $range['offset'] && ($other['offset'] + $other['length']) > ($range['offset'] + $range['length'])) {
                    $nested = true;
                    break;
                }
            }
            if (!$nested) {
                $children[] = $range;
            }
        }
        usort($children, static fn(array $left, array $right): int => $left['offset'] <=> $right['offset']);
        return $children;
    }
    /** @param array{offset:int,length:int} $child @return array{offset:int,length:int}|null */
    private static function parentBlockRange(string $markup, array $child): ?array
    {
        $best = null;
        foreach (self::blockRanges($markup) as $range) {
            if ($range['offset'] < $child['offset'] && ($range['offset'] + $range['length']) > ($child['offset'] + $child['length'])) {
                if (null === $best || $range['length'] < $best['length']) {
                    $best = $range;
                }
            }
        }
        return $best;
    }
    private static function queryLoopMarkup(bool $inherit): string
    {
        return '<!-- wp:query {"queryId":1,"query":{"perPage":10,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":' . ($inherit ? 'true' : 'false') . '},"layout":{"type":"constrained"}} -->' . "\n" . '<div class="wp-block-query"><!-- wp:post-template -->' . "\n" . '<!-- wp:post-title {"isLink":true} /-->' . "\n" . '<!-- wp:post-excerpt /-->' . "\n" . '<!-- wp:post-date {"isLink":true} /-->' . "\n" . '<!-- /wp:post-template -->' . "\n" . '<!-- wp:query-pagination {"paginationArrow":"arrow","layout":{"type":"flex","justifyContent":"space-between"}} -->' . "\n" . '<!-- wp:query-pagination-previous /-->' . "\n" . '<!-- wp:query-pagination-next /-->' . "\n" . '<!-- /wp:query-pagination -->' . "\n" . '<!-- wp:query-no-results -->' . "\n" . '<!-- wp:paragraph -->' . "\n" . '<p>No posts found.</p>' . "\n" . '<!-- /wp:paragraph -->' . "\n" . '<!-- /wp:query-no-results --></div>' . "\n" . '<!-- /wp:query -->';
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
            $content = is_string($asset['content'] ?? null) ? $asset['content'] : null;
            // Resolve copied author URLs before recovery judges them against the
            // generated stylesheet's directory (which is not their origin).
            if ('editor-static-state' === ($asset['source'] ?? null) && is_string($content)) $content = $references->cssFromOrigins($content, $authorStylesheetOrigins);
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
    /** @internal Shared artifact-root semantics for document-head asset resolution. */
    public static function entryRootFromDocuments(array $documents): string { foreach ($documents as $document) { if (is_array($document) && (!empty($document['entrypoint']) || 'entrypoint' === ($document['source_relation'] ?? null)) && is_string($document['source_path'] ?? null)) { $dir = str_replace('\\', '/', dirname($document['source_path'])); return in_array($dir, array('.', '/', ''), true) ? '' : $dir; } } return ''; }
    private static function canonicalRoutePath(string $path): string { if (!preg_match('~^/(?:[a-z0-9_-]+(?:/[a-z0-9_-]+)*)?$~', $path)) throw new InvalidArgumentException('WordPress site plan has an unsafe explicit page route.'); return $path; }
    private static function parentRoutePath(string $path): string { $parent = dirname($path); return '.' === $parent || '/' === $parent ? '/' : '/' . trim($parent, '/'); }
    /** @return array<int,string> */
    private static function routeAncestors(string $path): array { $ancestors = array(); for ($parent = self::parentRoutePath($path); '/' !== $parent; $parent = self::parentRoutePath($parent)) $ancestors[] = $parent; return array_reverse($ancestors); }
    private static function routeSlug(string $path): string { return trim((string) basename($path), '/'); }

    /** @param array<int,array<string,mixed>> $assets @param array<int,array<string,string>> $templates @param array<int,array<string,mixed>> $parts @param array<int,array<string,mixed>> $pages @param array<int,array<string,mixed>> $menus @return array<int,array<string,mixed>> */
    private function scaffoldWrites(array $assets, array $templates, array $parts, array $scripts, array $theme, array $tokens, array $pages = array(), array $menus = array(), array $taxonomyEntities = array(), bool $runtimeEmoji = false): array
    {
        $writes = array($this->write('theme_scaffold', 'style.css', "/*\nTheme Name: Blocks Engine Site\nText Domain: blocks-engine-site\n*/\n"), $this->write('theme_scaffold', 'theme.json', json_encode($theme, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"));
        $writes[] = $this->write('theme_bootstrap', 'functions.php', self::bootstrap($assets, $scripts, $parts, $tokens, $templates, $pages, $menus, $taxonomyEntities, $runtimeEmoji));
        if (DocumentRootContext::needsCanvas($pages)) $writes[] = $this->write('theme_scaffold', 'document-canvas.php', DocumentRootContext::canvas());
        foreach ( $templates as $template ) $writes[] = $this->write('theme_template', $template['target_path'], $template['canonical_block_markup']);
        foreach ( $parts as $part ) $writes[] = $this->write('theme_template_part', 'parts/' . $part['slug'] . '.html', $part['canonical_block_markup']);
        return $writes;
    }

    /** @param array<int,array<string,mixed>> $assets */
    private static function bootstrap(array $assets, array $scripts = array(), array $parts = array(), array $tokens = array(), array $templates = array(), array $pages = array(), array $menus = array(), array $taxonomyEntities = array(), bool $runtimeEmoji = false): string
    {
        $lines = array("<?php", self::SOURCE_TEXT_TYPOGRAPHY);
        if (!$runtimeEmoji) $lines[] = self::SOURCE_NATIVE_EMOJI;
        // Core only retains theme.json variations registered for the block.
        $lines[] = <<<'PHP'
add_action( 'init', static function (): void {
    $theme = wp_json_file_decode( get_theme_file_path( 'theme.json' ), array( 'associative' => true ) );
    foreach ( $theme['styles']['blocks']['core/button']['variations'] ?? array() as $slug => $look ) {
        register_block_style( 'core/button', array( 'name' => $slug, 'label' => ucwords( str_replace( '-', ' ', $slug ) ) ) );
    }
} );
PHP;
        $rootContext = DocumentRootContext::bootstrap($pages);
        if ('' !== $rootContext) $lines[] = $rootContext;
        $headContext = DocumentHeadContext::bootstrap($pages, $assets, $tokens, $taxonomyEntities);
        if ('' !== $headContext) $lines[] = $headContext;
        $fields = ListingFieldProjection::bootstrap($pages);
        if ('' !== $fields) $lines[] = $fields;
        if (array_filter($taxonomyEntities, static fn(array $entity): bool => 'taxonomy_term' === $entity['kind'])) {
            $termRoutes = array();
            foreach ($taxonomyEntities as $entity) {
                if ('author' === $entity['kind']) continue; // Core owns author rewrites.
                $taxonomy = (string) $entity['taxonomy'];
                $slug = (string) $entity['slug'];
                $sourceRoute = (string) $entity['archive']['source_route'];
                $termRoutes[$taxonomy][$slug] = $sourceRoute;
                $routePrefix = preg_quote(trim($sourceRoute, '/'), '~');
                $queryVar = 'category' === $taxonomy ? 'category_name' : 'tag';
                // The captured term link names the base route only, so the native
                // term query owns pagination; the rewrite carries the term context
                // through `paged` instead of mutating global category/tag bases.
                // Zero, negative, and overlong page numbers stay outside the
                // capture and keep WordPress's own 404 semantics.
                $lines[] = 'add_action( \'init\', static function (): void { add_rewrite_rule( ' . var_export('^' . $routePrefix . '/?$', true) . ', ' . var_export('index.php?' . $queryVar . '=' . rawurlencode($slug), true) . ', \'top\' ); add_rewrite_rule( ' . var_export('^' . $routePrefix . '/page/' . self::TAXONOMY_ARCHIVE_PAGED_CAPTURE . '/?$', true) . ', ' . var_export('index.php?' . $queryVar . '=' . rawurlencode($slug) . '&paged=$matches[1]', true) . ', \'top\' ); }, 1 );';
            }
            $lines[] = '$blocks_engine_taxonomy_archive_routes = ' . var_export($termRoutes, true) . ';';
            $lines[] = "add_filter( 'term_link', static function ( string \$url, WP_Term \$term, string \$taxonomy ) use ( \$blocks_engine_taxonomy_archive_routes ): string { \$route = \$blocks_engine_taxonomy_archive_routes[ \$taxonomy ][ \$term->slug ] ?? null; return is_string( \$route ) ? home_url( \$route ) : \$url; }, 10, 3 );";
        }
        if (array_filter(array_merge($pages, $parts, $templates), static fn(array $document): bool => str_contains($document['canonical_block_markup'], 'blocksEngineSocialAnchor'))) $lines[] = SocialLinkSourceAttribution::bootstrap();
        if (array_filter(array_merge($pages, $parts, $templates), static fn(array $document): bool => str_contains($document['canonical_block_markup'], 'blocksEngineLinkClass'))) {
            $lines[] = "add_filter( 'render_block_core/post-title', static function ( string \$content, array \$block ): string { \$classes = \$block['attrs']['metadata']['blocksEngineLinkClass'] ?? ''; if ( ! is_string( \$classes ) || '' === \$classes ) return \$content; \$tag = new WP_HTML_Tag_Processor( \$content ); if ( \$tag->next_tag( 'A' ) ) foreach ( preg_split( '/\\s+/', \$classes ) ?: array() as \$class ) \$tag->add_class( \$class ); return \$tag->get_updated_html(); }, 10, 2 );";
        }
        if (array_filter(array_merge($pages, $parts, $templates), static fn(array $document): bool => str_contains($document['canonical_block_markup'], 'blocksEngineIcon'))) {
            $lines[] = self::ACCORDION_ICON_RENDERER;
        }
        if (array_filter(array_merge($pages, $parts, $templates), static fn(array $document): bool => str_contains($document['canonical_block_markup'], 'blocksEngineImageSelection'))) {
            $lines[] = self::IMAGE_SELECTION_RENDERER;
        }
        if (array_filter($pages, static fn(array $page): bool => str_contains($page['canonical_block_markup'], 'blocks-engine-listing-overlay'))) {
            $lines[] = "add_filter( 'render_block_core/read-more', static function ( string \$content, array \$block ): string { if ( ! in_array( 'blocks-engine-listing-overlay', preg_split( '/\\s+/', (string) ( \$block['attrs']['className'] ?? '' ) ) ?: array(), true ) ) return \$content; \$tag = new WP_HTML_Tag_Processor( \$content ); if ( \$tag->next_tag( 'A' ) ) { \$tag->set_attribute( 'aria-hidden', 'true' ); \$tag->set_attribute( 'tabindex', '-1' ); } return \$tag->get_updated_html(); }, 10, 2 );";
        }
        $lines[] = "add_action( 'wp_enqueue_scripts', static function (): void {";
        $importLoaded = self::importLoadedStylesheets($assets);
        $stylesheetAttributes = array();
        $stylesheetEnqueues = self::stylesheetEnqueues($assets, $pages);
        // Each source document's ordered stylesheet instances form one route
        // block; a repeated resource gets one handle per instance, same URI.
        if (array_filter($stylesheetEnqueues, static fn(array $enqueue): bool => isset($enqueue['route']))) $lines[] = '    $blocks_engine_route_styles = false;';
        $route = null;
        foreach ($stylesheetEnqueues as $enqueue) {
            $asset = $enqueue['asset'];
            if ('editor' === ($asset['stylesheet_target'] ?? 'both') || isset($importLoaded[$asset['target_path']])) continue;
            // Stylesheet-set membership travels on every instance's link.
            if (isset($asset['stylesheet_activation'])) {
                $state = $asset['stylesheet_activation'];
                $stylesheetAttributes[$enqueue['handle']] = array('rel' => $state['rel']);
                if ('' !== $state['title']) $stylesheetAttributes[$enqueue['handle']]['title'] = $state['title'];
                if ($state['disabled']) $stylesheetAttributes[$enqueue['handle']]['disabled'] = true;
            }
            $deviceAttributes = self::stylesheetDeviceAttributes($enqueue);
            if (isset($deviceAttributes[CaptureEvidence::DEVICE_STYLE])) $stylesheetAttributes[$enqueue['handle']] = ($stylesheetAttributes[$enqueue['handle']] ?? array()) + $deviceAttributes;
            $media = '' !== $enqueue['media'] ? ', ' . var_export($enqueue['media'], true) : '';
            $call = "wp_enqueue_style( " . var_export($enqueue['handle'], true) . ", get_theme_file_uri( " . var_export(self::encodedAssetUrlPath($asset['target_path']), true) . " ), array(), null{$media} );";
            if (null !== $route && ($enqueue['route'] ?? null) !== $route) { $lines[] = '    }'; $route = null; }
            if (isset($enqueue['route'])) {
                if (null === $route) { $route = $enqueue['route']; $lines[] = "    if ( {$enqueue['condition']} ) {"; $lines[] = '        $blocks_engine_route_styles = true;'; }
                $lines[] = "        {$call}";
                continue;
            }
            $condition = empty($enqueue['fallback']) ? $enqueue['condition'] : '! $blocks_engine_route_styles';
            $lines[] = "    if ( {$condition} ) {$call}";
        }
        if (null !== $route) $lines[] = '    }';
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
        if (array() !== $stylesheetAttributes) {
            $lines[] = '$blocks_engine_stylesheet_attributes = ' . var_export($stylesheetAttributes, true) . ';';
            $lines[] = "add_filter( 'style_loader_tag', static function ( string \$html, string \$handle ) use ( \$blocks_engine_stylesheet_attributes ): string {";
            $lines[] = "    \$state = \$blocks_engine_stylesheet_attributes[ \$handle ] ?? null; if ( null === \$state ) return \$html; \$tag = new WP_HTML_Tag_Processor( \$html ); if ( \$tag->next_tag( 'LINK' ) ) { foreach ( \$state as \$attribute => \$value ) \$tag->set_attribute( \$attribute, \$value ); } return \$tag->get_updated_html();";
            $lines[] = "}, 10, 2 );";
        }
        $templateAssetTokens = array();
        foreach (array_merge($templates, $parts) as $document) if (str_contains((string) ($document['canonical_block_markup'] ?? ''), self::TOKEN_PREFIX)) foreach ($tokens as $token) if (is_string($token['token'] ?? null) && is_string($token['target_path'] ?? null)) $templateAssetTokens[self::TOKEN_PREFIX . $token['token'] . '}}'] = $token['target_path'];
        if (array() !== $templateAssetTokens) {
            $lines[] = '$blocks_engine_template_asset_tokens = ' . var_export($templateAssetTokens, true) . ';';
            $lines[] = '$blocks_engine_template_attachment_ids = array();';
            $lines[] = '$blocks_engine_resolve_template_assets = static function ( string $content ) use ( $blocks_engine_template_asset_tokens, $blocks_engine_template_attachment_ids ): string {';
            // A file-system path and a URL path are different encodings of the
            // same name: a literal `%` (or a `+`, which some serving layers
            // decode to a space) in a captured file name resolves on some hosts
            // and 404s on others once the server URL-decodes the request path.
            // Percent-encode every segment in the emitted URL only, leaving the
            // on-disk path -- and therefore the theme-file lookup -- untouched.
            $lines[] = "    \$references = array(); foreach ( \$blocks_engine_template_asset_tokens as \$token => \$path ) { if ( isset( \$blocks_engine_template_attachment_ids[ \$token ] ) ) { \$references[ \$token ] = wp_get_attachment_url( \$blocks_engine_template_attachment_ids[ \$token ] ) ?: ''; continue; } \$uri = get_theme_file_uri( \$path ); \$references[ \$token ] = str_ends_with( \$uri, \$path ) ? substr( \$uri, 0, -strlen( \$path ) ) . implode( '/', array_map( 'rawurlencode', explode( '/', \$path ) ) ) : \$uri; }";
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
        foreach ($stylesheetEnqueues as $enqueue) {
            $asset = $enqueue['asset'];
            if (!StylesheetActivation::active($asset) || 'frontend' === ($asset['stylesheet_target'] ?? 'both') || isset($importLoaded[$asset['target_path']])) continue;
            $partSlugs = array();
            foreach ($partSlugsBySource[(string) ($enqueue['scope']['source_path'] ?? '')] ?? array() as $slug) $partSlugs[$slug] = true;
            $editorStyles[] = array_filter(array('target_path' => $asset['target_path'], 'handle' => $enqueue['handle'], 'content_hash' => $asset['content_hash'], 'scopes' => array($enqueue['scope']), 'template_part_slugs' => array_keys($partSlugs), 'media' => $asset['source_media'] ?? $enqueue['media'], 'author_css' => 'engine-support' !== ($asset['source'] ?? ''), 'editor_only' => 'editor' === ($asset['stylesheet_target'] ?? 'both'), 'fallback' => empty($enqueue['fallback']) ? null : true), static fn(mixed $value): bool => null !== $value);
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
            // One document's canvas replays its ordered instances. The site
            // editor and shared template parts span routes, so they load each
            // resource/condition once instead of once per source document. Like
            // the front end, global resources outside a route's sequence (a
            // post no source document owns) load from their fallback entry.
            $lines[] = "    \$route_canvas = ! \$site_editor && \$post instanceof WP_Post && 'wp_template_part' !== \$post->post_type; \$loaded = array(); \$routed = false;";
            $lines[] = "    if ( \$route_canvas ) foreach ( \$blocks_engine_presentation_styles as \$style ) if ( empty( \$style['fallback'] ) && 'global' !== \$style['scopes'][0]['kind'] && \$blocks_engine_presentation_matches( \$style, \$post, false ) ) { \$routed = true; break; }";
            $lines[] = "    foreach ( \$blocks_engine_presentation_styles as \$style ) if ( ( empty( \$style['fallback'] ) || ( \$route_canvas && ! \$routed ) ) && ( \$canvas || ( empty( \$style['author_css'] ) && empty( \$style['editor_only'] ) ) ) && \$blocks_engine_presentation_matches( \$style, \$post instanceof WP_Post ? \$post : null, \$site_editor ) ) {";
            $lines[] = "        \$resource = \$style['target_path'] . \"\\0\" . ( \$style['media'] ?? 'all' ); if ( ! \$route_canvas && isset( \$loaded[ \$resource ] ) ) continue; \$loaded[ \$resource ] = true;";
            $lines[] = "        wp_enqueue_style( 'blocks-engine-editor-' . \$style['handle'], get_theme_file_uri( \$style['target_path'] ), array(), \$style['content_hash'], \$style['media'] ?? 'all' );";
            $lines[] = "    }";
            $lines[] = "} );";
        }
        // Keep the source landmark intact. The marked Core transport contributes
        // no layout box on the frontend; the editor retains its selection box.
        if (array() !== $parts) {
            $transportCss = ':where(body:not(.editor-styles-wrapper):not(.wp-admin)) :where(.blocks-engine-shell-transport){display:contents}';
            $lines[] = "add_action( 'wp_enqueue_scripts', static function (): void { wp_register_style( 'blocks-engine-shell-transport', false, array(), null ); wp_enqueue_style( 'blocks-engine-shell-transport' ); wp_add_inline_style( 'blocks-engine-shell-transport', " . var_export($transportCss, true) . " ); } );";
        }
        // A page's layout frame sits in its template around post-content. Core's
        // post-content element would be an extra box in that layout, so the
        // marked block renders only its content.
        foreach ($templates as $template) {
            if (!str_contains((string) ($template['canonical_block_markup'] ?? ''), self::FRAME_CONTENT_CLASS)) continue;
            $lines[] = "add_filter( 'render_block_core/post-content', static function ( string \$content, array \$block ): string {";
            $lines[] = "    if ( ! str_contains( (string) ( \$block['attrs']['className'] ?? '' ), '" . self::FRAME_CONTENT_CLASS . "' ) || ! preg_match( '/^<([a-z][a-z0-9-]*)\\b[^>]*>(.*)<\\/\\1>\\s*\$/s', \$content, \$match ) ) return \$content;";
            $lines[] = "    return \$match[2];";
            $lines[] = "}, 10, 2 );";
            break;
        }
        foreach (array_merge($templates, $parts, $pages) as $document) {
            if (str_contains((string) ($document['canonical_block_markup'] ?? ''), 'data-blocks-engine-route-state')) {
                $lines[] = NavigationAnchorRuntime::retainedLabelSource();
                break;
            }
        }
        $hasNavigationLink = false;
        foreach (array_merge($templates, $parts, $pages) as $document) {
            if (str_contains((string) ($document['canonical_block_markup'] ?? $document['block_markup'] ?? ''), 'blocksEngineNavigationOpener')) {
                $lines[] = NavigationOpenerRuntime::source();
                break;
            }
        }
        foreach (array_merge($templates, $parts, $pages, $menus) as $document) {
            $markup = (string) ($document['canonical_block_markup'] ?? $document['block_markup'] ?? '');
            // Require the navigation container alongside a link, not merely the
            // link comment text, so the filter is only emitted where core/navigation
            // actually renders a native navigation item: bare block-name
            // substrings can appear in unrelated JSON/attribute contexts that never
            // reach the core navigation renderers. Entity inner markup is
            // the link list for a referencing navigation block.
            if ((str_contains($markup, 'wp:navigation-link') || str_contains($markup, 'wp:navigation-submenu')) && (str_contains($markup, 'wp:navigation ') || isset($document['token']))) { $hasNavigationLink = true; break; }
        }
        if ($hasNavigationLink) {
            // Core's navigation-link and navigation-submenu renderers only add
            // aria-current="page" when their own
            // id/kind match the queried object, which never happens for the "custom"
            // kind links this engine emits. When navigation is inlined per page, the
            // current item is already marked with the blocks-engine-current-navigation-item
            // class (a respected className block support). That marker cannot work once
            // navigation is hoisted into a shared template part, because one rendered
            // part serves every route, so also recover aria-current by comparing the
            // link's own canonical route URL against the page currently being served.
            // Either signal recovers the semantic and the source's/engine's own
            // [aria-current] styling hook at render time.
            // Both native item blocks use the same URL resolution. A submenu's
            // descendants can already be current; inspect only its own first anchor.
            foreach (array('navigation-link', 'navigation-submenu') as $navigationBlock) {
            $lines[] = "add_filter( 'render_block_core/{$navigationBlock}', static function ( string \$content, array \$block ): string {";
            $lines[] = "    if ( ! preg_match( '/<a\\b[^>]*>/', \$content, \$anchor ) || str_contains( \$anchor[0], 'aria-current' ) ) return \$content;";
            $lines[] = "    \$current = str_contains( (string) ( \$block['attrs']['className'] ?? '' ), 'blocks-engine-current-navigation-item' );";
            $lines[] = "    if ( ! \$current ) {";
            $lines[] = "        \$url = is_string( \$block['attrs']['url'] ?? null ) ? trim( (string) \$block['attrs']['url'] ) : '';";
            $lines[] = "        if ( '' !== \$url && '/' === \$url[0] && ( 1 === strlen( \$url ) || '/' !== \$url[1] ) ) {";
            $lines[] = "            \$target = '/' . trim( (string) parse_url( \$url, PHP_URL_PATH ), '/' );";
            $lines[] = "            \$document = " . self::DOCUMENT_ID_EXPRESSION . ";";
            $lines[] = "            \$served = is_front_page() ? '/' : ( 0 !== \$document && 'page' === get_post_type( \$document ) ? '/' . trim( (string) get_page_uri( \$document ), '/' ) : null );";
            $lines[] = "            \$current = null !== \$served && \$target === \$served;";
            $lines[] = "        }";
            $lines[] = "    }";
            $lines[] = "    if ( ! \$current ) return \$content;";
            if ('navigation-submenu' === $navigationBlock) {
                // Only the outer item is current, never a descendant or ancestor.
                $lines[] = "    \$content = preg_replace_callback( '/^<li\\b[^>]*\\bclass=\"([^\"]*)\"/', static function ( array \$match ): string { return in_array( 'current-menu-item', preg_split( '/\\s+/', \$match[1] ), true ) ? \$match[0] : substr( \$match[0], 0, -1 ) . ' current-menu-item\"'; }, \$content, 1 ) ?? \$content;";
            }
            $lines[] = "    return preg_replace( '/(<a\\b[^>]*\\bclass=\"[^\"]*\\bwp-block-navigation-item__content\\b[^\"]*\")/', '\$1 aria-current=\"page\"', \$content, 1 ) ?? \$content;";
            $lines[] = "}, 10, 2 );";
            }
        }
        foreach (array_merge($templates, $parts, $pages, $menus) as $document) {
            if (str_contains((string) ($document['canonical_block_markup'] ?? $document['block_markup'] ?? ''), 'blocksEngineRuntimeAttributes')) {
                $lines[] = RuntimeAttributesRuntime::source();
                break;
            }
        }
        foreach (array_merge($templates, $parts, $pages, $menus) as $document) {
            if (str_contains((string) ($document['canonical_block_markup'] ?? $document['block_markup'] ?? ''), 'blocksEngineNavigationAnchor')) {
                $lines[] = NavigationAnchorRuntime::source();
                break;
            }
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
        $hasListingQuery = false;
        foreach ($pages as $page) {
            if (str_contains((string) ($page['canonical_block_markup'] ?? ''), self::LISTING_QUERY_CLASS)) {
                $hasListingQuery = true;
                break;
            }
        }
        $editorCss = self::EDITOR_CORE_IMAGE_INTERACTION_CSS . self::EDITOR_POST_TITLE_INTERACTION_CSS . self::EDITOR_LINK_INTERACTION_CSS;
        foreach ($pages as $page) if (str_contains((string) ($page['canonical_block_markup'] ?? ''), self::SYNCED_PATTERN_TOKEN_PREFIX)) {
            $editorCss .= SyncedPatternProjection::EDITOR_CONTAINER_CSS;
            break;
        }
        if ($hasListingQuery) {
            // Gutenberg's query-template editor can expose a core/post-meta key
            // before its post context resolves; the frontend binding remains live.
            $editorCss .= self::LISTING_QUERY_CSS
                . ':root .editor-styles-wrapper .blocks-engine-listing-overlay{display:none}'
                . ':root .editor-styles-wrapper .blocks-engine-listing-bound-meta{display:none}';
            $lines[] = "add_action( 'wp_enqueue_scripts', static function (): void { wp_register_style( 'blocks-engine-listing-query', false, array(), null ); wp_enqueue_style( 'blocks-engine-listing-query' ); wp_add_inline_style( 'blocks-engine-listing-query', " . var_export(self::LISTING_QUERY_CSS, true) . " ); } );";
        }
        $lines[] = "add_filter( 'block_editor_settings_all', static function ( array \$settings ): array { \$settings['styles'][] = array( 'css' => " . var_export($editorCss, true) . ", '__unstableType' => 'theme' ); return \$settings; }, 20 );";
        $headOwned = DocumentHeadContext::ownedAssets($pages);
        $headReferencesByTarget = array();
        foreach ($tokens as $token) $headReferencesByTarget[$token['target_path']] = self::TOKEN_PREFIX . $token['token'] . '}}';
        foreach ($scripts as $script) {
            $handle = 'blocks-engine-script-' . substr(hash('sha256', $script['identity']), 0, 12);
            foreach ($script['scopes'] as $scope) {
                $headReference = isset($headReferencesByTarget[$script['local_target'] ?? '']) ? $headReferencesByTarget[$script['local_target']] . $script['suffix'] : null;
                if ('head' === $script['placement'] && isset($headOwned[$scope['source_path'] ?? ''][$headReference ?? $script['url'] ?? ''])) continue;
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
        $postRoutePaths = array();
        $postRouteParents = array();
        foreach ($pages as $page) {
            if ('post' !== ($page['post_type'] ?? null) || !empty($page['synthetic'])) {
                continue;
            }
            $path = trim((string) ($page['route']['path'] ?? ''), '/');
            $identity = (string) ($page['reconciliation_identity'] ?? '');
            if ('' === $path || '' === $identity || !str_contains($path, '/')) {
                continue;
            }
            $postRoutePaths[$identity] = $path;
            $parent = trim((string) ($page['route']['parent_path'] ?? ''), '/');
            if ('' !== $parent) {
                $postRouteParents[$parent][] = basename($path);
            }
        }
        ksort($postRoutePaths);
        ksort($postRouteParents);
        if (array() !== $postRoutePaths) {
            $lines[] = '$blocks_engine_post_routes = ' . var_export($postRoutePaths, true) . ';';
            // WP::parse_request() reads public vars through query_vars; the
            // rewrite tag below also registers it on \$wp for url_to_postid().
            $lines[] = "add_filter( 'query_vars', static function ( array \$vars ): array { \$vars[] = 'blocks_engine_post_route'; return array_values( array_unique( \$vars ) ); } );";
            $lines[] = "\$blocks_engine_resolve_post_route = static function ( array \$vars ) use ( \$blocks_engine_post_routes ): array {";
            $lines[] = "    if ( ! isset( \$vars['blocks_engine_post_route'] ) ) return \$vars;";
            $lines[] = "    \$identity = array_search( \$vars['blocks_engine_post_route'], \$blocks_engine_post_routes, true );";
            // A route outside this inventory is a 404 unless a later resolver
            // owns it, so the route var stays for that resolver to claim.
            $lines[] = "    if ( false === \$identity ) return array( 'error' => '404', 'blocks_engine_post_route' => \$vars['blocks_engine_post_route'] );";
            $lines[] = "    \$ids = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_blocks_engine_reconciliation_identity', 'meta_value' => \$identity ) );";
            $lines[] = "    return empty( \$ids ) ? array( 'error' => '404' ) : array( 'p' => (int) \$ids[0] );";
            $lines[] = "};";
            $lines[] = "add_filter( 'request', \$blocks_engine_resolve_post_route );";
            // url_to_postid() constructs WP_Query directly, bypassing request.
            $lines[] = "add_action( 'parse_query', static function ( WP_Query \$query ) use ( \$blocks_engine_resolve_post_route ): void {";
            // Re-parse only when resolution changes the request, so a route left
            // as a claimable 404 does not re-enter parse_query indefinitely.
            $lines[] = "    if ( ! isset( \$query->query['blocks_engine_post_route'] ) ) return;";
            $lines[] = "    \$resolved = \$blocks_engine_resolve_post_route( \$query->query );";
            $lines[] = "    if ( \$resolved !== \$query->query ) \$query->parse_query( \$resolved );";
            $lines[] = "} );";
            $lines[] = "add_action( 'init', static function () use ( \$blocks_engine_post_routes ): void {";
            // url_to_postid() matches only \$wp->public_query_vars.
            $lines[] = "    add_rewrite_tag( '%blocks_engine_post_route%', '(.+)' );";
            foreach ($postRouteParents as $parent => $slugs) {
                // Only shipped posts may shadow core's hierarchical page rules.
                // A numeric parent (`/2019/<slug>/`) is an int key.
                sort($slugs, SORT_STRING);
                $quotedSlugs = array_map(static fn(string $slug): string => preg_quote($slug, '/'), array_unique($slugs));
                $pattern = '^(' . preg_quote((string) $parent, '/') . '/(?:' . implode('|', $quotedSlugs) . '))/?$';
                $lines[] = "    add_rewrite_rule( " . var_export($pattern, true) . ", 'index.php?blocks_engine_post_route=\$matches[1]', 'top' );";
            }
            $lines[] = "    \$signature = hash( 'sha256', 'identity-routes-v2:' . wp_json_encode( \$blocks_engine_post_routes ) );";
            $lines[] = "    if ( \$signature !== get_option( 'blocks_engine_post_route_signature' ) ) {";
            $lines[] = "        update_option( 'blocks_engine_post_route_signature', \$signature );";
            $lines[] = "        flush_rewrite_rules( false );";
            $lines[] = "    }";
            $lines[] = "} );";
            $lines[] = "add_filter( 'post_link', static function ( string \$permalink, WP_Post \$post ) use ( \$blocks_engine_post_routes ): string {";
            $lines[] = "    if ( 'post' !== \$post->post_type ) {";
            $lines[] = "        return \$permalink;";
            $lines[] = "    }";
            $lines[] = "    \$identity = (string) get_post_meta( \$post->ID, '_blocks_engine_reconciliation_identity', true );";
            $lines[] = "    if ( isset( \$blocks_engine_post_routes[ \$identity ] ) ) {";
            $lines[] = "        return home_url( user_trailingslashit( \$blocks_engine_post_routes[ \$identity ] ) );";
            $lines[] = "    }";
            $lines[] = "    return \$permalink;";
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

    /** @return array<string,mixed> */
    private static function stylesheetDeviceAttributes(array $occurrence): array
    {
        return array_intersect_key($occurrence, array_flip(array(CaptureEvidence::DEVICE_STYLE, CaptureEvidence::SOURCE_MEDIA)));
    }

    /**
     * Resource identity owns bytes; a document occurrence owns an enqueue handle.
     * Keep generated support on its existing side of the authored sequence and
     * join projected slices at their source occurrence, including repeated links.
     *
     * @param array<int,array<string,mixed>> $assets
     * @param array<int,array<string,mixed>> $pages
     * @return array<int,array<string,mixed>>
     */
    private static function stylesheetEnqueues(array $assets, array $pages): array
    {
        $pagesBySource = array_column($pages, null, 'source_path');
        $assetsBySource = array_column($assets, null, 'source_path');
        $before = $after = $instances = array();
        $firstManaged = count($assets);
        foreach ($assets as $index => $asset) if (!empty($asset['stylesheet_instances'])) $firstManaged = min($firstManaged, $index);
        foreach ($assets as $index => $asset) {
            if ('css' !== ($asset['kind'] ?? null)) continue;
            $occurrences = $asset['stylesheet_instances'] ?? array();
            $parent = $asset['stylesheet_parent'] ?? null;
            if (array() === $occurrences && is_string($parent)) $occurrences = $assetsBySource[$parent]['stylesheet_instances'] ?? array();
            foreach ($occurrences as $occurrence) {
                $source = $occurrence['source_path'];
                if (!isset($pagesBySource[$source])) continue;
                if (!array_filter($asset['scopes'], static fn(array $scope): bool => 'global' === $scope['kind'] || ($scope['source_path'] ?? null) === $source)) continue;
                $scope = self::stylesheetPageScope($pagesBySource[$source]);
                $instances[$source][] = array('asset' => $asset, 'scope' => $scope, 'route' => $source, 'condition' => self::bootstrapScopeCondition($scope), 'media' => $occurrence['media'], 'handle' => 'blocks-engine-instance-' . substr(hash('sha256', $asset['target_path'] . "\0" . $source . "\0" . $occurrence['order']), 0, 16), 'order' => $occurrence['order'], 'resource_order' => $index) + self::stylesheetDeviceAttributes($occurrence);
            }
            if (!empty($asset['frame_fallback'])) {
                $row = array('asset' => $asset, 'scope' => array('kind' => 'global'), 'condition' => 'true', 'media' => (string) ($asset['media'] ?? ''), 'handle' => 'blocks-engine-frame-fallback-' . substr(hash('sha256', $asset['target_path']), 0, 12), 'fallback' => true);
                if ('before-author' === ($asset['stylesheet_placement'] ?? '')) $before[] = $row; else $after[] = $row;
            }
            foreach ($asset['scopes'] as $scope) {
                if (array() !== $occurrences && 'global' !== $scope['kind']) continue;
                // A global resource with document instances still styles
                // WordPress-native routes (archives, search) that no source
                // document owns; the bootstrap guards it with the route flag.
                $fallback = array() !== $occurrences;
                $row = array('asset' => $asset, 'scope' => $scope, 'condition' => self::bootstrapScopeCondition($scope), 'media' => (string) ($asset['media'] ?? ''), 'handle' => 'blocks-engine-' . substr(hash('sha256', $asset['target_path']), 0, 12), 'fallback' => $fallback);
                if (!$fallback && ('before-author' === ($asset['stylesheet_placement'] ?? '') || ($index < $firstManaged && 'after-author' !== ($asset['stylesheet_placement'] ?? '') && 'wordpress-compat' !== ($asset['source'] ?? '')))) $before[] = $row;
                else $after[] = $row;
            }
        }
        $ordered = $before;
        foreach ($instances as $rows) {
            usort($rows, static fn(array $left, array $right): int => $left['order'] <=> $right['order'] ?: $left['resource_order'] <=> $right['resource_order']);
            array_push($ordered, ...$rows);
        }
        return array_merge($ordered, $after);
    }

    /**
     * The ordered stylesheet enqueues the generated bootstrap publishes for one
     * canonical document's route: before-author globals, the document's
     * instances (one handle per occurrence, shared resource URI), then
     * after-author globals. A host that publishes plan assets without that
     * bootstrap (a companion plugin inside an existing theme) replays this
     * sequence instead of re-deriving it from asset fields.
     *
     * @param array<string,mixed> $plan
     * @return list<array{target_path:string,handle:string,media:string,stylesheet_target:string}>
     */
    public static function documentStylesheets(array $plan, string $sourcePath): array
    {
        $assets = is_array($plan['assets'] ?? null) ? $plan['assets'] : array();
        $importLoaded = self::importLoadedStylesheets($assets);
        $sequence = array();
        foreach (self::stylesheetEnqueues($assets, is_array($plan['pages'] ?? null) ? $plan['pages'] : array()) as $enqueue) {
            $asset = $enqueue['asset'];
            if (!empty($enqueue['fallback']) || isset($importLoaded[$asset['target_path']])) continue;
            $owned = isset($enqueue['route']) ? $sourcePath === $enqueue['route'] : ('global' === $enqueue['scope']['kind'] || $sourcePath === ($enqueue['scope']['source_path'] ?? null));
            if ($owned) $sequence[] = array('target_path' => (string) $asset['target_path'], 'handle' => (string) $enqueue['handle'], 'media' => (string) $enqueue['media'], 'stylesheet_target' => (string) ($asset['stylesheet_target'] ?? 'both'));
        }
        return $sequence;
    }

    /** @param array<string,mixed> $page @return array<string,mixed> */
    private static function stylesheetPageScope(array $page): array
    {
        return array('kind' => 'post' === $page['post_type'] ? 'post' : 'page', 'source_path' => $page['source_path'], 'route_path' => trim($page['route']['path'], '/'), 'reconciliation_identity' => $page['reconciliation_identity'], 'front_page' => '/' === $page['route']['path']);
    }
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
    /**
     * The post the current request renders as a document: the queried post on
     * a singular view. A provider that renders an imported page's design on
     * another view (an archive of its own content type, for instance) names
     * that page through the `blocks_engine_document_id` filter, and the page's
     * route styles, document attributes, head, and current-navigation state
     * follow it there.
     */
    public const DOCUMENT_ID_EXPRESSION = "(int) apply_filters( 'blocks_engine_document_id', is_singular() ? get_queried_object_id() : 0 )";

    /** @param array<string,mixed> $scope */
    private static function bootstrapScopeCondition(array $scope): string
    {
        if ('global' === ($scope['kind'] ?? null)) return 'true';
        if (!empty($scope['front_page'])) return 'is_front_page()';
        if ('post' === ($scope['kind'] ?? null)) return "is_singular( 'post' ) && " . var_export($scope['reconciliation_identity'], true) . " === get_post_meta( get_queried_object_id(), '_blocks_engine_reconciliation_identity', true )";
        return "0 !== ( \$blocks_engine_page_id = " . self::DOCUMENT_ID_EXPRESSION . " ) && 'page' === get_post_type( \$blocks_engine_page_id ) && ( '' !== ( \$blocks_engine_page_identity = get_post_meta( \$blocks_engine_page_id, '_blocks_engine_reconciliation_identity', true ) ) ? " . var_export($scope['reconciliation_identity'], true) . " === \$blocks_engine_page_identity : " . var_export($scope['route_path'], true) . " === trim( get_page_uri( \$blocks_engine_page_id ), '/' ) )";
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
     * @param array<int,array<string,mixed>> $records
     * @param array<int,array<string,mixed>> $routes
     * @return array<int,array<string,mixed>>
     */
    private function canonicalEntityBindings(array $declarations, array &$records, AssetReferenceCanonicalizer $references, array $routes, array $pages, array $parts = array(), array $paginationOwners = array()): array
    {
        $projection = RuntimeEntityManifest::project($declarations, $records, fn(array $inline): array => $this->canonicalInlineEntityBindings($inline, $references, $routes, $pages, $parts, $paginationOwners));
        $records = $projection['records'];
        return $projection['declarations'];
    }

    /**
     * The source anchor a plan keeps beside a binding's canonical anchor, so the
     * binding can be found again after its page markup is reprojected.
     *
     * @param array<string,mixed> $binding
     * @return array<string,mixed>
     */
    public static function projectedBindingAnchor(array $binding): array
    {
        // The anchor identifies the binding's source block by digest and length:
        // the markup itself already travels as the binding's
        // `search_block_markup`, and reprojection only tests exact equality.
        $sourceMarkup = is_string($binding['search_block_markup'] ?? null) ? $binding['search_block_markup'] : null;
        return array_filter(array('schema' => 'blocks-engine/projected-binding-anchor/v2', 'source_block_hash' => null === $sourceMarkup ? null : hash('sha256', $sourceMarkup), 'source_block_length' => null === $sourceMarkup ? null : strlen($sourceMarkup), 'source_occurrence' => $binding['occurrence'] ?? null, 'source_position' => $binding['position'] ?? null), static fn(mixed $value): bool => null !== $value);
    }

    private function canonicalInlineEntityBindings(array $declarations, AssetReferenceCanonicalizer $references, array $routes, array $pages, array $parts = array(), array $paginationOwners = array()): array
    {
        // A binding owned by a shared part was re-anchored on the part's final
        // markup when the chrome moved; it needs no source projection, and the
        // part markup is its own projected source.
        $variantSources = array(); $templateTargets = array(); $sourceBindings = array();
        foreach ($parts as $part) if (isset($part['template_surface'])) {
            $templateTargets[$part['source_path']] = $part['target_path'];
            foreach ($part['template_surface']['source_variants'] as $variant) if ($variant['source_path'] !== $part['source_path']) $variantSources[$variant['source_path']] = $part['source_path'];
        }
        // Numbered captures of a listing are absorbed by the listing's own document.
        foreach ($paginationOwners as $source => $owner) $variantSources[$source] ??= $owner;
        // Compare declared semantics before projections can erase differences.
        foreach ($declarations as $di => $declaration) foreach ($declaration['payload']['entities'] ?? array() as $ei => $entity) {
            $type = (string) ($declaration['type'] ?? '');
            if ('forms' === $type) {
                $source = $entity['source_path'] ?? null;
                $action = $entity['form']['action'] ?? null;
                if (is_string($source) && is_string($action) && $this->isSelfPostAction($action, $source, $routes)) $entity['form']['action'] = 'blocks-engine:self-post';
                foreach ($entity['controls'] ?? array() as $ci => $control) unset($entity['controls'][$ci]['selector']);
                // Layout graph nodes locate the same controls positionally.
                foreach ($entity['layout_graph']['nodes'] ?? array() as $ni => $node) if (is_array($node['source'] ?? null)) unset($entity['layout_graph']['nodes'][$ni]['source']['selector']);
            }
            $key = EntityEquivalence::key($entity, $type);
            foreach ($entity['bindings'] ?? array() as $bi => $binding) $sourceBindings[$di][$ei][$bi] = null === $key ? null : array($type, $key, $binding['selector'] ?? null);
        }
        $partSources = array();
        foreach ($parts as $part) if (is_string($part['source_path'] ?? null) && is_string($part['canonical_block_markup'] ?? null)) {
            if (!isset($part['template_surface'])) $partSources[$part['source_path']] = true;
            $pages[] = array('source_path' => $part['source_path'], 'canonical_block_markup' => $part['canonical_block_markup'], '_projected_source_block_markup' => $part['_projected_source_block_markup'] ?? $part['canonical_block_markup']);
        }
        foreach ( $declarations as &$declaration ) {
            if ( ! is_array($declaration) || ! isset($declaration['payload']['entities']) || ! is_array($declaration['payload']['entities']) ) {
                continue;
            }
            foreach ( $declaration['payload']['entities'] as &$entity ) {
                if ( ! is_array($entity) || ! isset($entity['bindings']) || ! is_array($entity['bindings']) ) {
                    continue;
                }
                if (is_string($entity['source_path'] ?? null)) {
                    $entity = self::mapItemTemplateMarkup($entity, fn(string $markup): string => $this->routeLinks($references->content($markup, $entity['source_path']), $entity['source_path'], $routes));
                }
                foreach ( $entity['bindings'] as &$binding ) {
                    if ( is_array($binding) && is_string($binding['search_block_markup'] ?? null) && is_string($binding['source_path'] ?? null) ) {
                        $sourceMarkup = $binding['search_block_markup'];
                        if (!is_array($binding['projected_anchor'] ?? null)) $binding['projected_anchor'] = self::projectedBindingAnchor($binding);
                        if (isset($templateTargets[$binding['source_path']])) $binding['target_path'] = $templateTargets[$binding['source_path']];
                        if (isset($partSources[$binding['source_path']])) continue;
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
        // A page that synced patterns took content from is matched on its markup
        // before that projection; each resolved block is then rebased below.
        $patternSources = array_column($pages, '_synced_pattern_source', 'source_path');
        $projectedMarkupBySource = $markupBySource;
        foreach ($patternSources as $source => $patternSource) $markupBySource[$source] = $patternSource['markup'];
        $groups = array();
        // A binding whose block is not emitted has nothing to bind: the block
        // stays static. These are dropped below and reported, not thrown.
        $unanchored = array(); $anchored = array();
        foreach ($declarations as $declarationIndex => $declaration) foreach ($declaration['payload']['entities'] ?? array() as $entityIndex => $entity) foreach (is_array($entity) ? ($entity['bindings'] ?? array()) : array() as $bindingIndex => $binding) {
            $source = $binding['source_path'] ?? null; $search = $binding['search_block_markup'] ?? null; $position = $binding['position'] ?? null;
            $markup = is_string($source) ? ($markupBySource[$source] ?? null) : null;
            if (!is_string($source) || !is_string($search) || '' === $search || !is_int($binding['occurrence'] ?? null) || $binding['occurrence'] < 1) throw new InvalidArgumentException('A runtime entity binding lacks an exact emitted block anchor.');
            if (!is_string($markup)) { $unanchored[] = array('declaration' => $declarationIndex, 'entity' => $entityIndex, 'binding' => $bindingIndex, 'reason_code' => 'document_not_emitted'); continue; }
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
            // fail closed exactly as before. A `commerce_product_grid` binding
            // names that same whole-grid region for a provider product loop,
            // so it joins the grid's shared claim.
            $clusters = array();
            foreach ($bindings as $identity) {
                $key = is_int($identity['source_offset']) ? 'offset:' . $identity['source_offset'] : 'occurrence:' . $identity['source_occurrence'];
                $clusters[$key][] = $identity;
            }
            $claims = array();
            foreach ($clusters as $members) {
                if (1 < count($members) && array() !== array_filter($members, static fn(array $member): bool => ! in_array($member['role'], array('commerce_collection', 'commerce_product_grid'), true))) throw new InvalidArgumentException('A runtime entity binding has ambiguous canonical source-page anchors.');
                $claims[] = $members;
            }
            $markup = $bindings[0]['markup']; $search = $bindings[0]['search'];
            // Claims beyond the emitted matching blocks find no candidate below and are dropped.
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
                if (!is_array($anchor) || 'blocks-engine/projected-binding-anchor/v2' !== ($anchor['schema'] ?? null) || !is_string($anchor['source_block_hash'] ?? null) || !is_int($anchor['source_block_length'] ?? null)) throw new InvalidArgumentException('A runtime entity binding no longer identifies one exact emitted canonical block.');
                $isAnchorSource = static fn(string $markup, array $range): bool => $anchor['source_block_length'] === $range['length'] && hash_equals($anchor['source_block_hash'], hash('sha256', substr($markup, $range['offset'], $range['length'])));
                $sourceMarkup = $sourceMarkupBySource[$identity['source']] ?? null;
                if (!is_string($sourceMarkup)) throw new InvalidArgumentException('A runtime entity binding no longer identifies one exact emitted canonical block.');
                $sourceRanges = self::blockRanges($sourceMarkup); $canonicalRanges = self::blockRanges($markup);
                $sourceMatches = array_values(array_filter($sourceRanges, static fn(array $range): bool => $isAnchorSource($sourceMarkup, $range)));
                if (isset($anchor['source_occurrence_count']) && $anchor['source_occurrence_count'] !== count($sourceMatches)) throw new InvalidArgumentException('A runtime entity binding source anchor is ambiguous after reprojection.');
                $canonicalMatches = array_values(array_filter($canonicalRanges, static fn(array $range): bool => $search === substr($markup, $range['offset'], $range['length'])));
                $candidates = array();
                // Shell extraction changes raw block indexes, not the occurrence
                // order of equal blocks. Prefer equal-sized match sets by ordinal.
                if (count($sourceMatches) === count($canonicalMatches) && array() !== $canonicalMatches) {
                    $occurrence = is_int($anchor['source_occurrence'] ?? null) ? $anchor['source_occurrence'] : (is_int($identity['source_occurrence'] ?? null) ? $identity['source_occurrence'] : null);
                    $index = is_int($occurrence) ? $occurrence - 1 : null;
                    if (is_int($index) && isset($sourceMatches[$index], $canonicalMatches[$index]) && !isset($claimedOffsets[$canonicalMatches[$index]['offset']])) {
                        $candidates[] = array('source' => $sourceMatches[$index], 'canonical' => $canonicalMatches[$index]);
                    }
                } else {
                    // Distinct source blocks can canonicalize to the same markup
                    // (e.g. route aliases). Preserve their positional disambiguation.
                    foreach ($sourceRanges as $index => $sourceRange) {
                        $canonicalRange = $canonicalRanges[$index] ?? null;
                        if ($isAnchorSource($sourceMarkup, $sourceRange) && $anchor['source_occurrence'] === self::occurrenceAtOffset($sourceMarkup, substr($sourceMarkup, $sourceRange['offset'], $sourceRange['length']), $sourceRange['offset']) && is_array($canonicalRange) && $search === substr($markup, $canonicalRange['offset'], $canonicalRange['length']) && !isset($claimedOffsets[$canonicalRange['offset']])) $candidates[] = array('source' => $sourceRange, 'canonical' => $canonicalRange);
                    }
                    $unclaimedCanonical = array_values(array_filter($canonicalMatches, static fn(array $range): bool => !isset($claimedOffsets[$range['offset']])));
                    if (array() === $candidates && 1 === count($sourceMatches) && 1 === count($unclaimedCanonical)) $candidates[] = array('source' => $sourceMatches[0], 'canonical' => $unclaimedCanonical[0]);
                }
                // No unclaimed emitted block carries this markup at all: the anchor
                // is gone, so the binding is dropped. An unclaimed match that cannot
                // be told apart stays ambiguous and fails closed below.
                if (array() === $candidates && array() === array_filter($canonicalMatches, static fn(array $range): bool => !isset($claimedOffsets[$range['offset']]))) {
                    foreach ($members as $member) $unanchored[] = array('declaration' => $member['declaration'], 'entity' => $member['entity'], 'binding' => $member['binding'], 'reason_code' => 'anchor_not_emitted');
                    continue;
                }
                if (1 !== count($candidates)) throw new InvalidArgumentException('A runtime entity binding no longer identifies one exact emitted canonical block.');
                $claimedOffsets[$candidates[0]['canonical']['offset']] = true;
                foreach ($members as $member) $resolved[] = array($member, $candidates[0]['canonical']);
            }
            foreach ($resolved as $resolvedEntry) {
                [$identity, $range] = $resolvedEntry;
                if (!is_array($range)) throw new InvalidArgumentException('A runtime entity binding no longer identifies an emitted canonical block.');
                $binding = &$declarations[$identity['declaration']]['payload']['entities'][$identity['entity']]['bindings'][$identity['binding']];
                $documentMarkup = $markup;
                $landing = isset($patternSources[$identity['source']]) ? SyncedPatternProjection::rebase($patternSources[$identity['source']], $projectedMarkupBySource[$identity['source']], $range) : null;
                if (null !== $landing) {
                    // A copy of pattern content: the pattern document's own binding replaces it.
                    if ($landing['duplicate']) { $binding['_synced_pattern_duplicate'] = $landing['claim']; unset($binding); continue; }
                    $documentMarkup = $landing['markup'];
                    $range = $landing['range'];
                }
                $canonical = substr($documentMarkup, $range['offset'], $range['length']);
                $blockIndex = array_search($range, self::blockRanges($documentMarkup), true);
                if (!is_string($canonical) || '' === $canonical || !is_int($blockIndex)) throw new InvalidArgumentException('A runtime entity binding resolved to empty canonical block markup.');
                $anchored[$identity['id']] = true;
                $binding['search_block_markup'] = $canonical;
                $binding['occurrence'] = self::occurrenceAtOffset($documentMarkup, $canonical, $range['offset']);
                $binding['position'] = array('schema' => 'blocks-engine/runtime-binding-position/v1', 'block_index' => $blockIndex, 'offset' => $range['offset'], 'length' => $range['length']);
                if (is_string($landing['source_path'] ?? null)) {
                    $binding['source_path'] = $landing['source_path'];
                    $binding['projected_anchor'] = self::projectedBindingAnchor($binding);
                    $binding['_synced_pattern_claim'] = $landing['claim'];
                }
                unset($binding);
            }
        }

        // Absorb semantically equivalent variants only when the selected binding anchored.
        // Missing or different forms retain the existing unanchored diagnostic.
        foreach ($unanchored as $index => $drop) {
            if ('document_not_emitted' !== $drop['reason_code']) continue;
            $variantEntity = $declarations[$drop['declaration']]['payload']['entities'][$drop['entity']];
            $variantBinding = $variantEntity['bindings'][$drop['binding']];
            $selected = $variantSources[$variantBinding['source_path']] ?? null;
            $fallback = $variantEntity['fallback_identity'] ?? null;
            if (null === $selected || !is_string($fallback) || '' === $fallback) continue;
            $matches = array();
            foreach ($declarations as $di => $declaration) foreach ($declaration['payload']['entities'] ?? array() as $ei => $entity) foreach ($entity['bindings'] ?? array() as $bi => $binding) {
                if (!isset($anchored[$di . ':' . $ei . ':' . $bi]) || $selected !== ($binding['source_path'] ?? null) || null === $sourceBindings[$di][$ei][$bi] || $sourceBindings[$di][$ei][$bi] !== $sourceBindings[$drop['declaration']][$drop['entity']][$drop['binding']] || !self::bindingPosition($binding['position'] ?? null, $markupBySource[$selected], $binding['search_block_markup'])) continue;
                $matches[] = array($di, $ei);
            }
            if (1 !== count($matches)) continue;
            [$di, $ei] = $matches[0];
            $canonical = &$declarations[$di]['payload']['entities'][$ei];
            $identities = array_values(array_unique(array_merge($canonical['replaced_fallback_identities'] ?? array(), array_filter(array($canonical['fallback_identity'] ?? null, $fallback)), $variantEntity['replaced_fallback_identities'] ?? array())));
            sort($identities, SORT_STRING);
            $canonical['replaced_fallback_identities'] = $identities;
            unset($canonical);
            unset($declarations[$drop['declaration']]['payload']['entities'][$drop['entity']]['bindings'][$drop['binding']], $unanchored[$index]);
        }
        $declarations = SyncedPatternProjection::collapseDuplicateBindings($this->withoutUnanchoredBindings($declarations, $unanchored));
        foreach ($declarations as &$declaration) {
            foreach ($declaration['payload']['entities'] ?? array() as $ei => $entity) if (isset($entity['bindings'])) $declaration['payload']['entities'][$ei]['bindings'] = array_values($entity['bindings']);
        }
        unset($declaration);

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

        // Composition can grow a previously representable entity's bindings.
        // Screen the final projected form before factoring or rebuilding manifests.
        foreach ($declarations as &$declaration) {
            if ('entity_collection' !== ($declaration['kind'] ?? null) || !is_array($declaration['payload']['entities'] ?? null)) continue;
            $screened = RuntimeEntityManifest::screenEntities($declaration['payload']['entities'], $declaration['payload']['schema'], $declaration['source_path'] ?? '');
            $declaration['payload']['entities'] = $screened['entities'];
            foreach ($screened['diagnostics'] as $finding) $this->recordUnitFinding($finding);
        }
        unset($declaration);
        return RuntimeDeclarations::normalizeForComposition($declarations);
    }

    /** Resolve against source documents, including surfaces absent from page routes. */
    private function isSelfPostAction(string $action, string $source, array $routes): bool
    {
        if ('' === $action) return true;
        if (preg_match('~^(?://|#|\?)~', $action) || strpbrk($action, '?#') !== false) return false;
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $action)) {
            if (!$this->isSameOriginSourceUrl($action)) return false;
            $action = (string) (parse_url($action, PHP_URL_PATH) ?: '/');
        }
        $root = self::entryRootFromDocuments($routes);
        $path = str_starts_with($action, '/') ? ('' === $root ? ltrim($action, '/') : $root . '/' . ltrim($action, '/')) : self::resolveRouteSource($source, $action);
        if (null === $path) return false;
        $document = static fn(string $path): string => preg_replace('~(?:^|/)index\.html?$~', '', rtrim($path, '/'));
        return rtrim($document($source), '/') === rtrim($document($path), '/');
    }

    /**
     * Drops entity bindings whose block is not emitted and reports each one as
     * a `runtime_entity_binding_unanchored` finding. The entity keeps its other
     * bindings; the unbound block stays static.
     *
     * @param array<int,array<string,mixed>> $declarations
     * @param list<array{declaration:int|string,entity:int|string,binding:int|string,reason_code:string}> $unanchored
     * @return array<int,array<string,mixed>>
     */
    private function withoutUnanchoredBindings(array $declarations, array $unanchored): array
    {
        if (array() === $unanchored) return $declarations;
        foreach ($unanchored as $drop) {
            $declaration = $declarations[$drop['declaration']];
            $entity = $declaration['payload']['entities'][$drop['entity']];
            $binding = $entity['bindings'][$drop['binding']];
            $this->recordUnitFinding(array_filter(array(
                'code' => 'runtime_entity_binding_unanchored',
                'unit' => 'binding',
                'message' => self::unanchoredBindingMessage($drop['reason_code']),
                'source_path' => (string) $binding['source_path'],
                'reason_code' => $drop['reason_code'],
                'declaration_source_path' => is_string($declaration['source_path'] ?? null) ? $declaration['source_path'] : '',
                'entity_id' => is_string($entity['id'] ?? null) ? $entity['id'] : '',
                'role' => is_string($binding['role'] ?? null) ? $binding['role'] : '',
            ), static fn(string $value): bool => '' !== $value));
            unset($declarations[$drop['declaration']]['payload']['entities'][$drop['entity']]['bindings'][$drop['binding']]);
        }
        foreach ($unanchored as $drop) {
            $bindings = &$declarations[$drop['declaration']]['payload']['entities'][$drop['entity']]['bindings'];
            $bindings = array_values($bindings);
            unset($bindings);
        }
        return $declarations;
    }
    private static function unanchoredBindingMessage(string $reason): string
    {
        return 'document_not_emitted' === $reason
            ? 'A runtime entity binding names a document the plan does not emit; the binding was dropped.'
            : 'A runtime entity binding names a block the page does not emit; the binding was dropped and the block stays static.';
    }

    /**
     * @internal Exposed for ShellExtraction. Shell markup rendered beyond its
     * source route points same-page fragment links at that route, unless the
     * target is inside the shell itself.
     */
    public function qualifyShellFragments(string $content, string $origin): string
    {
        $route = $this->routeSources[$origin] ?? null;
        if (null === $route) return $content;
        $ids = array();
        if (preg_match_all('/\bid\s*=\s*\\\\?["\']([^"\'\\\\]+)|"anchor"\s*:\s*"([^"\\\\]+)"/i', $content, $matches)) $ids = array_flip(array_filter(array_merge($matches[1], $matches[2])));
        $qualify = static fn(array $match): string => isset($ids[$match[2]]) ? $match[0] : $match[1] . $route . '#' . $match[2] . $match[3];
        // HTML attributes, escaped attributes inside JSON block comments, and JSON url attributes.
        return preg_replace_callback('/(\b(?:href|data-[a-z0-9_-]*url)\s*=\s*\\\\?["\']|"url"\s*:\s*")#([A-Za-z][\w:.-]*)(\\\\?["\'])/i', $qualify, $content) ?? $content;
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
    public static function mapItemTemplateMarkup(array $entity, callable $map): array
    {
        if (is_string($entity['item_template']['block_markup'] ?? null)) $entity['item_template']['block_markup'] = $map($entity['item_template']['block_markup']);
        foreach (is_array($entity['items'] ?? null) ? $entity['items'] : array() as $itemIndex => $item) {
            foreach (is_array($item['slots'] ?? null) ? $item['slots'] : array() as $slotId => $slot) {
                if (is_string($slot['markup'] ?? null)) $entity['items'][$itemIndex]['slots'][$slotId]['markup'] = $map($slot['markup']);
            }
        }
        return $entity;
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
        // Route tokens and relative links retain their existing mapping. Only
        // absolute item destinations need URL serialization before shell/menu
        // identity is computed; emission uses these same attributes.
        $content = preg_replace_callback('/<!--\s*wp:navigation-(?:link|submenu)\s+(\{.*?\})\s*(\/)?-->/s', static function (array $match): string {
            $attrs = json_decode($match[1], true);
            if (!is_array($attrs) || !is_string($attrs['url'] ?? null)) return $match[0];
            $url = \Automattic\BlocksEngine\PhpTransformer\Support\NavigationUrl::serialize($attrs['url']);
            if ($url === $attrs['url']) return $match[0];
            $attrs['url'] = $url;
            return str_replace($match[1], BlockAttributes::encode($attrs, JSON_THROW_ON_ERROR), $match[0]);
        }, $content) ?? $content;
        $content = $this->unresolvedNavigationLinks($content, $origin, $routes);
        return null === $this->presetBinding ? $content : $this->presetBinding->bind($content);
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
     * A document-relative a/area href or form action that names no artifact route cannot stay
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
        if (!preg_match('~(?:<|\\\\u003c)(?:a|area|form)\b~i', $content)) return $content;
        return preg_replace_callback('~(?:<|\\\\u003c)(?:a|area|form)(?=[\s/>\\\\])(?:[^>\\\\]|\\\\(?!u003e))*~i', function (array $tag) use ($origin, $routes): string {
            $replace = function (array $match) use ($origin, $routes): string {
                $resolved = $this->unresolvedNavigationReference($match[2], $origin, $routes);
                return null === $resolved ? $match[0] : $match[1] . $resolved . $match[3];
            };
            $markup = $tag[0];
            // A form navigates through `action`; links navigate through `href`.
            $attribute = 1 === preg_match('~^(?:<|\\\\u003c)form~i', $markup) ? 'action' : 'href';
            foreach (array('~((?<![\w:-])' . $attribute . '\s*=\s*")([^"]*)(")~i', "~((?<![\\w:-])" . $attribute . "\\s*=\\s*')([^']*)(')~i", '~((?<![\w:-])' . $attribute . '\s*=\s*\\\\")([^"\\\\]*)(\\\\")~i', '~((?<![\w:-])' . $attribute . '\s*=\s*\\\\u0022)(.*?)(\\\\u0022)~i') as $pattern) $markup = preg_replace_callback($pattern, $replace, $markup) ?? $markup;
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
                'message' => mb_strcut(null === $absolute ? "Neutralized a link to {$url}, which names no artifact page and has no recorded source URL." : "Linked {$url} to the source site because it names no artifact page.", 0, 256, 'UTF-8'),
                'source_path' => mb_strcut($origin, 0, 256, 'UTF-8'),
                'value' => mb_strcut($url, 0, 256, 'UTF-8'),
                'resolution' => null === $absolute ? 'neutralized' : 'source_url',
                'resolved_url' => null === $absolute ? null : mb_strcut($absolute . $suffix, 0, 512, 'UTF-8'),
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
    /**
     * A section other routes reach as `index.html#donate` is the same target
     * its own route reaches as `#donate`. Spell that same-document link the
     * way the other routes do, so chrome repeated on every route carries one
     * link. A fragment no other route names stays bare: `#top` on every route
     * means each route's own top.
     *
     * @param array<int,array<string,mixed>> $documents
     * @param array<int,array<string,mixed>> $routes
     * @return array<string,array<string,true>>
     */
    private function routeLinkedFragments(array $documents, array $routes): array
    {
        $targets = array_flip($this->routeSources);
        $linked = array();
        foreach ($documents as $document) {
            $origin = (string) ($document['source_path'] ?? '');
            if (!preg_match_all('/(?:\bhref\s*=\s*(?:\\\\)?["\']|"url"\s*:\s*")([^"\'#\\\\]+)#([^"\'\\\\]+)/i', (string) ($document['block_markup'] ?? ''), $hrefs, PREG_SET_ORDER)) continue;
            foreach ($hrefs as $href) {
                $target = $targets[(string) $this->routeReference($href[1], $origin, $routes)] ?? null;
                if (is_string($target) && $target !== $origin) $linked[$target][$href[2]] = true;
            }
        }
        return $linked;
    }

    /** @param array<int,array<string,mixed>> $routes */
    private function resolveRouteReference(string $value, string $origin, array $routes): ?string
    {
        if (preg_match('~^#(.+)$~s', $value, $fragment) && isset($this->routeLinkedFragments[$origin][$fragment[1]], $this->routeSources[$origin])) return $this->routeSources[$origin] . $value;
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
        if (!is_array($bootstrap) || 'theme_bootstrap' !== ($bootstrap['kind'] ?? null) || 'wordpress-site-plan/functions.php' !== ($bootstrap['source_path'] ?? null) || self::bootstrap($plan['assets'], $scriptLoading['scripts'], $plan['template_parts'], $plan['reference_tokens'], $plan['templates'], $plan['pages'], $plan['menus'], array_merge($plan['taxonomy_entities'], $plan['author_entities']), 'wordpress' === ($plan['theme']['runtime_emoji'] ?? null)) !== ($bootstrap['canonical_payload'] ?? $bootstrap['payload']['data'] ?? null)) throw new InvalidArgumentException('WordPress site plan functions.php bootstrap is invalid.');
        if (DocumentRootContext::needsCanvas($plan['pages'])) {
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
        $assertReference = static function (string $candidate, string $attribute, string $element = '', bool $documentLink = false) use ($sourcePath, $context, $report): void { $url = trim(preg_split('/\s+/', trim(html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8')))[0] ?? ''); $route = str_starts_with($url, '/') && (str_starts_with($attribute, 'json:route_') || ('href' === $attribute && (in_array($element, array('a', 'area'), true) || $documentLink)) || ('action' === $attribute && 'form' === $element)); if ('' !== $url && !str_starts_with($url, self::TOKEN_PREFIX) && !$route && !preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#|\?)~i', $url)) { if (null !== $report) { $report($url, $attribute); return; } throw new ValidationException(sprintf('WordPress site plan contains unresolved local browser reference %s.', $url), array('source_path' => $sourcePath, 'document_kind' => $context, 'declaration_kind' => 'browser_reference', 'declaration_index' => 0, 'reason' => 'unresolved_local_browser_reference', 'fields' => array('context' => $context, 'attribute' => $attribute, 'value' => $url))); } };
        $assertCss = static function (string $css, string $cssContext) use ($assertReference): void { \Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\CssUrlRewriter::rewrite(html_entity_decode($css, ENT_QUOTES | ENT_HTML5, 'UTF-8'), static function (string $url) use ($assertReference, $cssContext): string { $assertReference($url, $cssContext . ':url'); return $url; }); if (preg_match_all('/@import\s+(?:url\(\s*)?(?:"([^"]*)"|\'([^\']*)\'|([^\s\)"\';]+))/i', html_entity_decode($css, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $matches, PREG_SET_ORDER)) foreach ($matches as $match) $assertReference((string) (($match[1] ?? '') ?: ($match[2] ?? '') ?: ($match[3] ?? '')), $cssContext . ':@import'); };
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
                foreach ('srcset' === strtolower($name) ? self::srcsetCandidates($value) : array($value) as $candidate) $assertReference($candidate, 'json:' . $routeField);
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
                foreach ('srcset' === $name ? self::srcsetCandidates($value) : array($value) as $candidate) $assertReference($candidate, $name, $node['name'], 'link' === $node['name'] && DocumentHeadContext::isRouteLink($node['attributes']));
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
    private static function assertRoute(array $page, string $entryRoot = ''): void { $route = $page['route'] ?? null; $expected = is_string($page['metadata']['route_path'] ?? null) && '' !== $page['metadata']['route_path'] ? self::canonicalRoutePath($page['metadata']['route_path']) : self::pageRoutePath($page['source_path'], $entryRoot); if (!is_array($route) || !is_string($route['path'] ?? null) || !preg_match('~^/(?:[a-z0-9_-]+(?:/[a-z0-9_-]+)*)?$~', $route['path']) || !is_string($route['parent_path'] ?? null) || !is_string($route['slug'] ?? null) || self::parentRoutePath($route['path']) !== $route['parent_path'] || self::routeSlug($route['path']) !== $route['slug'] || (!isset($page['synthetic']) && !self::isDerivedRoute($route['path'], $expected)) || (isset($page['synthetic']) && (true !== $page['synthetic'] || !str_starts_with((string) ($page['source_path'] ?? ''), 'wordpress-site-plan/routes/')))) throw new InvalidArgumentException('WordPress site plan page route is invalid.'); }
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
        $timestamps = array(); foreach ($decision['evidence'] as $evidence) { if (!is_array($evidence) || array_diff(array_keys($evidence), array('source', 'publication_timestamp')) || !is_string($evidence['source'] ?? null) || '' === $evidence['source'] || strlen($evidence['source']) > 128) throw new InvalidArgumentException('WordPress site plan content decision evidence is invalid.'); if (isset($evidence['publication_timestamp'])) { if (!self::utcTimestamp($evidence['publication_timestamp'])) throw new InvalidArgumentException('WordPress site plan content decision timestamp is invalid.'); $timestamps[] = $evidence['publication_timestamp']; } }
        if (isset($document['publication_timestamp']) && (!self::utcTimestamp($document['publication_timestamp']) || !in_array($document['publication_timestamp'], $timestamps, true))) throw new InvalidArgumentException('WordPress site plan publication timestamp is invalid.');
    }
    private static function utcTimestamp(mixed $value): bool { if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value)) return false; try { return (new \DateTimeImmutable($value))->format('Y-m-d\\TH:i:s\\Z') === $value; } catch (\Exception) { return false; } }
    private static function normalizePublicationTimestamp(string $value, ?string &$localTime = null): ?string
    {
        $localTime = null;
        $format = null; $input = $value;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) $format = '!Y-m-d';
        elseif (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value, $match)) { $input = $match[1] . ('Z' === $match[2] ? '+00:00' : $match[2]); $format = '!Y-m-d\\TH:i:sP'; }
        if (null === $format) return null;
        $timestamp = self::utcFromFormat($format, $input);
        // The same validated, seconds-precision input used for UTC conversion
        // retains its explicit offset; date-only and zero-offset values do not.
        if (null !== $timestamp && '!Y-m-d' !== $format && !in_array(substr($input, -6), array('+00:00', '-00:00'), true)) $localTime = $input;
        return $timestamp;
    }
    private static function parseVisiblePublicationTimestamp(string $value): ?string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        if ('' === $value) return null;
        $iso = self::normalizePublicationTimestamp($value);
        if (null !== $iso) return $iso;
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $value, $match)) {
            $first = (int) $match[1];
            $second = (int) $match[2];
            if ($first > 31 || $second > 31 || $first < 1 || $second < 1) return null;
            $format = $first > 12 && $second <= 12 ? '!j/n/Y' : '!n/j/Y';
            return self::utcFromFormat($format, $first . '/' . $second . '/' . $match[3]);
        }
        foreach (array('!F j, Y', '!M j, Y', '!j F Y', '!j M Y') as $format) {
            $parsed = self::utcFromFormat($format, $value);
            if (null !== $parsed) return $parsed;
        }
        return null;
    }
    private static function utcFromFormat(string $format, string $input): ?string
    {
        $date = \DateTimeImmutable::createFromFormat($format, $input);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && (0 !== $errors['warning_count'] || 0 !== $errors['error_count']))) return null;
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
    }
    /** @param array<string,mixed> $metadata @param array<string,bool> $tokens */
    private static function assertDocumentMetadata(array $metadata, array $tokens, string $sourcePath, string $documentKind): void
    {
        if (array_key_exists('root_attributes', $metadata)) DocumentRootContext::assertValid($metadata['root_attributes']);
        if (array_key_exists('head', $metadata)) {
            DocumentHeadContext::assertValid($metadata['head']);
            foreach ($metadata['head']['elements'] as $row) if (isset($row['asset_reference'])) self::assertTokens($row['asset_reference'], $tokens);
            foreach ($metadata['head']['elements'] as $index => $element) {
                if ('script' !== $element['tag']) continue;
                $reference = $element['asset_reference'] ?? $element['url'] ?? '';
                $matches = array_filter($metadata['scripts'] ?? array(), static fn(array $script): bool => 'head' === ($script['placement'] ?? null)
                    && (isset($element['content']) ? (($element['body_hash'] ?? null) === ($script['body_hash'] ?? null) && 'inline' === ($script['source_kind'] ?? null)) : $reference === ($script['asset_reference'] ?? $script['url'] ?? ''))
                    && strtolower($element['attributes']['type'] ?? '') === strtolower($script['type'] ?? '')
                    && isset($element['attributes']['async']) === ($script['async'] ?? false)
                    && isset($element['attributes']['defer']) === ($script['defer'] ?? false)
                    && isset($element['attributes']['nomodule']) === ($script['nomodule'] ?? false));
                if (array() === $matches) self::invalidDeclaration('head script declaration', 'head', $index, $sourcePath, $documentKind, 'unbound_script_loading_contract', $element);
            }
        }
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
            if (isset($archive['document_head'])) DocumentHeadContext::assertValid($archive['document_head']);
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
            if (isset($archive['document_head'])) DocumentHeadContext::assertValid($archive['document_head']);
            $members = $entity['membership_source_paths'] ?? null;
            if (!is_array($members) || !array_is_list($members) || array() === $members || count($members) > 10000) throw new InvalidArgumentException('WordPress site plan taxonomy membership list is structurally invalid.');
            $memberSet = array();
            foreach ($members as $sourcePath) {
                if (!is_string($sourcePath) || !isset($pagesBySource[$sourcePath]) || 'post' !== ($pagesBySource[$sourcePath]['post_type'] ?? null)) throw new InvalidArgumentException('WordPress site plan taxonomy membership does not name a canonical post.');
                self::unique($memberSet, $sourcePath, 'taxonomy membership');
            }
            if (($entity['evidence'] ?? null) !== array('membership' => true, 'name' => true, 'archive' => true)) throw new InvalidArgumentException('WordPress site plan taxonomy evidence is incomplete.');
        }
    }
    private static function assertRows(array $rows, string $kind, array $fields, array $optional = array()): void { foreach ($rows as $row) { if (!is_array($row)) throw new InvalidArgumentException("WordPress site plan {$kind} must be an array."); foreach ($fields as $field) if (!array_key_exists($field, $row) || (!is_string($row[$field]) && !is_int($row[$field]))) throw new InvalidArgumentException("WordPress site plan {$kind} lacks {$field}."); foreach ($optional as $field) if (array_key_exists($field, $row) && !is_string($row[$field])) throw new InvalidArgumentException("WordPress site plan {$kind} has invalid {$field}."); } }
    /** @param array<string,mixed> $data */
    public static function value(array $data, string $key, string $default = ''): string { return is_string($data[$key] ?? null) ? $data[$key] : $default; }
    private static function explicitUrl(mixed $url): bool { return is_string($url) && (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $url) === 1 || self::routeUrl($url)); }
    private static function routeUrl(mixed $url): bool { return is_string($url) && preg_match('~^/(?:[a-z0-9_-]+(?:/[a-z0-9_-]+)*)?(?:[?#].*)?$~', $url) === 1; }
    private static function safePath(mixed $path): bool { if (!is_string($path) || '' === $path || str_contains($path, "\0") || str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:/', $path)) return false; foreach (explode('/', str_replace('\\', '/', $path)) as $segment) if ('' === $segment || '.' === $segment || '..' === $segment) return false; return true; }
}
