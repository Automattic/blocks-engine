<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Typed state and render services shared by one emitter execution context.
 */
final class StaticHtmlEmissionSession
{
    private readonly StaticHtmlPageState $pageState;
    private readonly StaticHtmlLinkState $linkState;
    private readonly StaticHtmlAssetRegistry $assetRegistry;
    private readonly StaticHtmlTypographyState $typographyState;
    private readonly StaticHtmlDecisionTraceState $decisionTraceState;
    private readonly TextStyleDeclarationResolver $textStyleDeclarationResolver;
    private readonly PaintStackResolver $paintStackResolver;
    private readonly StyleDeclarationBuilder $styleDeclarationBuilder;
    private readonly ChildLayerCompositionResolver $childLayerCompositionResolver;
    private readonly StaticHtmlVectorEvidence $vectorEvidence;
    private readonly VectorSvgRenderer $vectorSvgRenderer;
    private readonly StaticHtmlVisualStyleResolver $visualStyleResolver;

    public function __construct(
        StaticHtmlNodeInspector $nodeInspector,
        StaticHtmlValueFormatter $formatter,
        TypographyModel $typographyModel,
    ) {
        $this->pageState = new StaticHtmlPageState();
        $this->linkState = new StaticHtmlLinkState();
        $this->assetRegistry = new StaticHtmlAssetRegistry($formatter);
        $this->typographyState = new StaticHtmlTypographyState();
        $this->decisionTraceState = new StaticHtmlDecisionTraceState();
        $this->textStyleDeclarationResolver = new TextStyleDeclarationResolver($typographyModel, $formatter, $this->typographyState);
        $this->paintStackResolver = new PaintStackResolver($this->assetRegistry, $formatter);
        $this->styleDeclarationBuilder = new StyleDeclarationBuilder($formatter, $this->paintStackResolver);
        $this->childLayerCompositionResolver = new ChildLayerCompositionResolver($this->assetRegistry, $formatter);
        $this->vectorEvidence = new StaticHtmlVectorEvidence($formatter, $this->paintStackResolver);
        $this->vectorSvgRenderer = new VectorSvgRenderer($nodeInspector, $formatter, $this->vectorEvidence);
        $this->visualStyleResolver = new StaticHtmlVisualStyleResolver(
            $formatter,
            $this->vectorEvidence,
            $this->vectorSvgRenderer,
            $this->styleDeclarationBuilder,
            $this->paintStackResolver,
            $this->assetRegistry,
        );
    }

    public function pageState(): StaticHtmlPageState
    {
        return $this->pageState;
    }

    public function linkState(): StaticHtmlLinkState
    {
        return $this->linkState;
    }

    public function vectorSvgRenderer(): VectorSvgRenderer
    {
        return $this->vectorSvgRenderer;
    }

    public function assetRegistry(): StaticHtmlAssetRegistry
    {
        return $this->assetRegistry;
    }

    public function typographyState(): StaticHtmlTypographyState
    {
        return $this->typographyState;
    }

    public function textStyleDeclarationResolver(): TextStyleDeclarationResolver
    {
        return $this->textStyleDeclarationResolver;
    }

    public function decisionTraceState(): StaticHtmlDecisionTraceState
    {
        return $this->decisionTraceState;
    }

    public function paintStackResolver(): PaintStackResolver
    {
        return $this->paintStackResolver;
    }

    public function styleDeclarationBuilder(): StyleDeclarationBuilder
    {
        return $this->styleDeclarationBuilder;
    }

    public function childLayerCompositionResolver(): ChildLayerCompositionResolver
    {
        return $this->childLayerCompositionResolver;
    }

    public function vectorEvidence(): StaticHtmlVectorEvidence
    {
        return $this->vectorEvidence;
    }

    public function visualStyleResolver(): StaticHtmlVisualStyleResolver
    {
        return $this->visualStyleResolver;
    }

}
