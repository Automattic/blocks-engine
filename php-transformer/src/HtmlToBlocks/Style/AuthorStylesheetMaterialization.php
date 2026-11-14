<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Css\AdminBarAccommodation;
use Automattic\BlocksEngine\PhpTransformer\Css\AuthorCascadeLayerOrder;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\EditorCanvasBodyBackground;
use Automattic\BlocksEngine\PhpTransformer\Css\TemplatePartStickyContainment;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;

/**
 * One compilation's author-stylesheet stage. It is built once blocks are
 * serialized, projects each author stylesheet asset against that markup,
 * then materializes the before-author, author and after-author stylesheets.
 * It owns the projected-selector bindings and content-box restatements it
 * produces along the way.
 */
final class AuthorStylesheetMaterialization
{
    private const ENGINE_RESET_LAYER = 'blocks-engine-reset';

    /** Content-box restatements collected while projecting stylesheet assets. */
    private string $contentBoxCss = '';

    /** @var list<array{authored: string, projected: string, stylesheet: string, scope: string}> */
    private array $projectedSelectorBindings = array();

    /**
     * @param array<string, true> $sharedStylesheetPaths Stylesheet paths shared across pages; their author class selectors are kept.
     */
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly StyleResolver $styleResolver,
        private readonly AuthorStylesheetProjector $projector,
        private readonly NavigationStyleProjector $navigationStyleProjector,
        private readonly StylesheetAssetStage $stylesheetAssetStage,
        private readonly string $emittedStyleMarkup,
        private readonly array $sharedStylesheetPaths,
        private readonly bool $projectsOnlyMatchedAuthorRules,
        private readonly bool $nativeDocumentContext,
        private readonly string $sourcePath
    ) {
    }

    public function contentBoxCss(): string
    {
        return $this->contentBoxCss;
    }

    /** @return list<array{authored: string, projected: string, stylesheet: string, scope: string}> */
    public function projectedSelectorBindings(): array
    {
        return $this->projectedSelectorBindings;
    }

    /** @return list<array{path: string, content: string, bytes: int, hash: string, source_hash: string}> */
    public function projections(): array
    {
        $projections = array();
        $contentBoxRestatements = new ContentBoxRestatements();
        foreach ( $this->session->authorStyleAnalysis()->stylesheetAssets() as $asset ) {
            $content = $this->rewrite(
                $asset['content'],
                isset($this->sharedStylesheetPaths[$asset['path']])
                    || isset($this->sharedStylesheetPaths[$asset['source_path'] ?? '']),
                (string) $asset['path'],
                true,
                (string) ($asset['media'] ?? ''),
                $nativeSelectors,
                $contentBoxRestatements
            );
            $hash = hash('sha256', $content);
            $projections[] = array(
                'path'        => $asset['path'],
                'content'     => $content,
                'bytes'       => strlen($content),
                'hash'        => $hash,
                'source_hash' => $asset['source_hash'],
                'source_path' => $asset['source_path'],
                'media'       => $asset['media'],
                'type'        => $asset['type'] ?? '',
                'attribute_state_markers' => $this->session->authorSelectorProjectionState()->attributeNegationMarkers(),
                'native_selectors' => $nativeSelectors,
            );
        }
        $this->contentBoxCss = $contentBoxRestatements->css();
        return $projections;
    }


    /**
     * @param array<int, array<string, mixed>> $sourceProvenance
     * @param list<array{path: string, content: string, bytes: int, hash: string, source_hash: string}> $authorStylesheetProjections
     */
    public function materialize(string $html, string $staticCss, bool $includeAuthorStyles = true, string $serializedBlocks = '', array $sourceProvenance = array(), array $authorStylesheetProjections = array(), ?string $sourceLayerOrder = null): void
    {
        $beforeAuthorCssParts = array();
        $authorCssParts = array();
        $afterAuthorCss = new LayeredCssCollector();
        $authorCss = '';
        // Content-box restatements are support CSS: they reach the
        // after-author stylesheet even when the host keeps its own author CSS
        // (or, as the artifact compiler does, installs the projected
        // stylesheet assets itself).
        $contentBoxSupportCss = $this->contentBoxCss;
        if ( $includeAuthorStyles && '' !== $this->session->authorStyleAnalysis()->combinedCss() ) {
            $contentBoxRestatements = new ContentBoxRestatements();
            $authorCss = $this->rewrite(
                $this->session->authorStyleAnalysis()->combinedCss(),
                false,
                '',
                array() === $this->session->authorStyleAnalysis()->stylesheetAssets(),
                '',
                $combinedNativeSelectors,
                $contentBoxRestatements
            );
            if ( array() === $this->session->authorStyleAnalysis()->stylesheetAssets() ) {
                $contentBoxSupportCss = $contentBoxRestatements->css();
            }
            $split = ( new CssStylesheetTransformer() )->splitLeadingAtRulePreamble($authorCss);
            if ( array() === $this->session->authorStyleAnalysis()->stylesheetAssets() ) {
                if ( '' !== trim($split['preamble']) ) {
                    $authorCssParts[] = $split['preamble'];
                }
                $authorCssParts[] = $split['stylesheet'];
            }
        }
        $afterAuthorCss->add(CascadeLayer::SOURCE_BOX_MODEL_RESTATEMENT, $contentBoxSupportCss);
        $geometryCss = $this->styleResolver->generatedGeometryCss($serializedBlocks);
        if ( '' !== $geometryCss ) {
            // Important carrier rules precede author CSS: they retain inline
            // precedence over normal selectors while authored !important rules
            // remain able to override them.
            $beforeAuthorCssParts[] = $geometryCss;
        }
        $markerReset = $this->richTextMarkerResetCss();
        if ( '' !== $markerReset ) {
            $beforeAuthorCssParts[] = $markerReset;
        }
        $engineSupportCss = new EngineSupportCss();
        $engineResetParts = $engineSupportCss->beforeAuthorCss($serializedBlocks);
        $engineResetStart = count($beforeAuthorCssParts);
        array_push($beforeAuthorCssParts, ...$engineResetParts);
        $coreDefaultResetParts = $engineSupportCss->coreDefaultResetCss($serializedBlocks);
        $coreDefaultResetStart = count($beforeAuthorCssParts);
        array_push($beforeAuthorCssParts, ...$coreDefaultResetParts);
        array_push($beforeAuthorCssParts, ...$engineSupportCss->coreOverrideCss($serializedBlocks));
        // Precedence for everything below is declared on each contribution's
        // CascadeLayer (see CascadeLayer), not by the order these calls
        // appear in. $afterAuthorCss orders the collected stylesheet by that
        // declared layer once every contribution below has been collected.
        $afterAuthorCss->absorb($engineSupportCss->generatedMarkupRepairCss($serializedBlocks));
        $afterAuthorCss->absorb($this->navigationStyleProjector->navigationLinkTextColorRules($serializedBlocks));
        $afterAuthorCss->absorb($this->navigationStyleProjector->navigationLinkBoxRules($serializedBlocks));
        $afterAuthorCss->absorb($this->navigationStyleProjector->navigationAnchorLineHeightRules($serializedBlocks));
        $afterAuthorCss->addAll(CascadeLayer::SOURCE_STYLE_PROJECTION, $this->session->sourceTargetProjectionState()->rules());
        $afterAuthorCss->absorb($this->navigationStyleProjector->navigationLinkIconRules($serializedBlocks));
        $afterAuthorCss->absorb($this->navigationStyleProjector->navigationLinkLeadingIconRules($serializedBlocks));
        $afterAuthorCss->absorb($engineSupportCss->socialLinkCss($serializedBlocks));
        $afterAuthorCss->absorb($engineSupportCss->deviceVisibilityCss($serializedBlocks));
        $afterAuthorCss->absorb($this->session->generatedSupportStylesheetState()->conditionalAfterAuthorCss($serializedBlocks));
        $nativeSearchTriggerCss = $this->session->generatedSupportStylesheetState()->beforeAuthorCss($serializedBlocks);
        if ( '' !== $nativeSearchTriggerCss ) {
            $beforeAuthorCssParts[] = $nativeSearchTriggerCss;
        }
        if ( '' !== trim($authorCss) ) {
            $authorCssParts[] = $authorCss;
            $afterAuthorCss->add(CascadeLayer::VIEWPORT_CHROME_COMPAT, (new AdminBarAccommodation())->supportCss($authorCss));
            $afterAuthorCss->add(CascadeLayer::VIEWPORT_CHROME_COMPAT, (new TemplatePartStickyContainment())->supportCss($authorCss));
        }
        if ( str_contains($serializedBlocks, 'blocks-engine-list-navigation') ) {
            $mobileOverlayBackground = $this->navigationStyleProjector->sourceMobileNavigationOverlayBackground();
            $afterAuthorCss->absorb($engineSupportCss->listNavigationHostRepairCss($serializedBlocks, $mobileOverlayBackground));
            $afterAuthorCss->absorb($this->navigationStyleProjector->listNavigationInlineMarginRules($serializedBlocks));
            $afterAuthorCss->absorb($this->navigationStyleProjector->listNavigationPaddingRules($serializedBlocks));
            $afterAuthorCss->absorb($this->navigationStyleProjector->listNavigationItemAnchorRules($serializedBlocks, $sourceProvenance));
            $afterAuthorCss->absorb($engineSupportCss->listNavigationOverlayRepairCss($serializedBlocks, $mobileOverlayBackground));
        }
        $afterAuthorCss->absorb($engineSupportCss->secondaryBlockRenderRepairCss($serializedBlocks));
        $afterAuthorCss->absorb($this->navigationStyleProjector->navigationItemStateAnchorRules($serializedBlocks, $sourceProvenance));
        $afterAuthorCss->absorb($this->navigationStyleProjector->directNavigationSupportCss($serializedBlocks));
        $afterAuthorCss->absorb($this->session->generatedSupportStylesheetState()->buttonAfterAuthorCss());
        $afterAuthorCss->addAll(CascadeLayer::CLOSED_STATE_REPAIR, $this->styleResolver->closedStateRepairCssRules());
        // A captured reveal whose driver did not survive import must still
        // settle at the appearance it was travelling towards, not at the hidden
        // keyframe it starts from (#239). Read the projected author CSS the
        // theme actually ships: when the artifact pipeline materializes the
        // author stylesheets itself, that is the per-asset projection rather
        // than the copy inlined here, and only the projected form carries the
        // state markers the repair has to reproduce in its selectors.
        $settleableAuthorCss = '' !== trim($authorCss)
            ? $authorCss
            : implode("\n\n", array_column($authorStylesheetProjections, 'content'));
        // Read replay bindings from the input markup: leaf attributes may have
        // moved into render metadata by the time blocks are serialized.
        $afterAuthorCss->addAll(CascadeLayer::REVEAL_SETTLE, ( new RevealAnimationSettler() )->settleRules($settleableAuthorCss, '', $html));
        // DOMDocument synthesizes a body for fragments; its margin belongs to
        // the host document, not to the imported content.
        $bodyDeclarations = preg_match('/<(?:html|body)\b/i', $html)
            ? $this->styleResolver->authorStructuralDeclarations($this->session->authorStyleAnalysis()->sourceBody())
            : null;
        $afterAuthorCss->add(CascadeLayer::VIEWPORT_CHROME_COMPAT, ( new EditorCanvasBodyBackground() )->supportCss($settleableAuthorCss, $bodyDeclarations, null !== $bodyDeclarations && $this->htmlOwnsCanvas($this->session->authorStyleAnalysis()->sourceBody())));
        // Engine support CSS may join an author cascade layer, and naming a
        // layer registers it. The editor injects author CSS after the enqueued
        // editor styles, so that registration would otherwise arrive first and
        // invert the author's layer order — letting an author reset layer beat
        // the author utilities that centre and pad every section. Stating the
        // author's own order ahead of any engine CSS pins it in both contexts.
        // Artifact compilation has the canonical import graph. Its authoritative
        // empty statement means anonymous source positions cannot be predeclared.
        $authorLayerOrder = $sourceLayerOrder ?? ( new AuthorCascadeLayerOrder() )->statement($settleableAuthorCss);
        // Layered author rules lose to unlayered ones whatever their specificity,
        // so once the author uses layers the engine resets join a layer registered
        // ahead of every author layer. `!important` parts stay unlayered: there
        // the first layer would win and override authored !important rules. When
        // the statement is '' (anonymous author layers) nothing is wrapped, since
        // naming an engine layer could shift anonymous layer positions. Layers in
        // the capture vocabulary are not author layering: a capture-only layer
        // leaves the author's rules unlayered, and those must keep losing to the
        // engine resets by specificity.
        if ( $this->namesAuthorOwnedLayer($authorLayerOrder) ) {
            foreach ( $engineResetParts as $offset => $part ) {
                if ( ! str_contains($part, '!important') ) {
                    $beforeAuthorCssParts[$engineResetStart + $offset] = '@layer ' . self::ENGINE_RESET_LAYER . '{' . $part . '}';
                }
            }
            foreach ( $coreDefaultResetParts as $offset => $part ) {
                // Beat Core's unlayered default, but roll back to the author
                // layers when they declare this margin. The engine layer is
                // the fallback only when no author layer owns the property.
                $bridge = preg_replace('/(?<=[{:;])0(?=[;}])/', 'revert-layer', $part);
                $beforeAuthorCssParts[$coreDefaultResetStart + $offset] = '@layer ' . self::ENGINE_RESET_LAYER . '{' . $part . '}' . $bridge;
            }
            $authorLayerOrder = '@layer ' . self::ENGINE_RESET_LAYER . ',' . substr($authorLayerOrder, strlen('@layer '));
        }
        if ( '' !== $authorLayerOrder ) {
            array_unshift($beforeAuthorCssParts, $authorLayerOrder);
        }
        $this->stylesheetAssetStage->materialize(
            $beforeAuthorCssParts,
            $authorCssParts,
            $afterAuthorCss->orderedCss(),
            $authorStylesheetProjections,
            $includeAuthorStyles,
            array() !== $this->session->authorStyleAnalysis()->stylesheetAssets()
        );
    }


    private function namesAuthorOwnedLayer(string $layerOrderStatement): bool
    {
        $names = explode(',', rtrim(substr($layerOrderStatement, strlen('@layer ')), ';'));
        foreach ( $names as $name ) {
            $name = trim($name);
            if ( '' !== $name && ! str_starts_with($name, CaptureEvidence::ATTRIBUTE_PREFIX) ) {
                return true;
            }
        }
        return false;
    }


    /** Whether the source paints its canvas on `html` and never on `body`, under any condition. */
    private function htmlOwnsCanvas(\DOMElement $body): bool
    {
        $html = $this->styleResolver->documentRoot($body);
        if ( null === $html ) {
            return false;
        }
        $properties = array( 'background', 'background-color', 'background-image' );
        $paints = function ( \DOMElement $element ) use ( $properties ): bool {
            $declarations = array_change_key_case($this->styleResolver->resolvedPresentationDeclarations($element));
            foreach ( $properties as $property ) {
                $value = strtolower(trim(CssValueInspector::withoutImportant((string) ($declarations[$property] ?? ''))));
                if ( '' !== $value && ! in_array($value, array( 'none', 'transparent', 'initial', 'unset', 'inherit', 'revert', 'revert-layer' ), true) ) {
                    return true;
                }
            }
            return false;
        };
        return $paints($html) && ! $paints($body)
            && array() === $this->styleResolver->conditionalAuthorDeclaredPropertyValues($body, $properties);
    }

    private function richTextMarkerResetCss(): string
    {
        if ( ! $this->session->authorSelectorProjectionState()->hasRichTextMarkers() ) {
            return '';
        }

        // Specificity (0,0,2): above an author rule that selects `mark` by tag
        // alone (Bootstrap's reboot, older normalize.css), which never matched
        // the source span a marker replaces, and below any author class rule
        // wherever its stylesheet lands. A captured document can keep author
        // styles in its own <head>, ahead of this before-author asset.
        return 'html mark:where([style*="--blocks-engine-richtext-marker:"]){background-color:transparent;color:inherit}';
    }


    /**
     * `$media` is the stylesheet's link/style media attribute. Source analysis
     * reads the asset as `@media <media>{...}`, so projection is told the same
     * outer condition for rules that compare against analyzed declarations.
     */
    private function rewrite(string $stylesheet, bool $keepAuthorClassSelectors = false, string $stylesheetPath = '', bool $recordBindings = true, string $media = '', ?array &$nativeSelectors = null, ?ContentBoxRestatements $contentBoxRestatements = null): string
    {
        $this->session->authorSelectorProjectionState()->reconcileRetainedControls($this->emittedStyleMarkup);
        $bindings = new ProjectedSelectorBindings();
        $media = trim($media);
        $projected = $this->projector->project(
            $stylesheet,
            new AuthorStylesheetProjectionContext(
                $this->session->authorStyleAnalysis(),
                $this->session->sourceStyleResolutionState(),
                $this->session->authorSelectorProjectionState(),
                $this->session->transformationEvidenceState(),
                $keepAuthorClassSelectors,
                $bindings,
                $this->emittedStyleMarkup,
                $contentBoxRestatements ?? new ContentBoxRestatements(),
                $this->projectsOnlyMatchedAuthorRules,
                $this->nativeDocumentContext
            ),
            '' === $media ? array() : array( '@media ' . $media )
        );
        $nativeSelectors = $bindings->nativeSelectors();
        if ( $recordBindings ) {
            foreach ( $bindings->all() as $binding ) {
                $this->projectedSelectorBindings[] = array(
                    'authored'    => $binding['authored'],
                    'projected'   => $binding['projected'],
                    'stylesheet'  => $stylesheetPath,
                    'scope'       => $this->sourcePath,
                );
            }
        }

        return $projected;
    }

}
