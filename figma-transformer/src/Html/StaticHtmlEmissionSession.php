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
    private readonly PaintStackResolver $paintStackResolver;
    private readonly ChildLayerCompositionResolver $childLayerCompositionResolver;
    private readonly StaticHtmlVectorEvidence $vectorEvidence;
    private readonly VectorSvgRenderer $vectorSvgRenderer;

    public function __construct(
        StaticHtmlNodeInspector $nodeInspector,
        StaticHtmlValueFormatter $formatter,
    ) {
        $this->pageState = new StaticHtmlPageState();
        $this->linkState = new StaticHtmlLinkState();
        $this->assetRegistry = new StaticHtmlAssetRegistry($formatter);
        $this->paintStackResolver = new PaintStackResolver($this->assetRegistry, $formatter);
        $this->childLayerCompositionResolver = new ChildLayerCompositionResolver($this->assetRegistry, $formatter);
        $this->vectorEvidence = new StaticHtmlVectorEvidence($formatter, $this->paintStackResolver);
        $this->vectorSvgRenderer = new VectorSvgRenderer($nodeInspector, $formatter, $this->vectorEvidence);
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

    public function paintStackResolver(): PaintStackResolver
    {
        return $this->paintStackResolver;
    }

    public function childLayerCompositionResolver(): ChildLayerCompositionResolver
    {
        return $this->childLayerCompositionResolver;
    }

    public function vectorEvidence(): StaticHtmlVectorEvidence
    {
        return $this->vectorEvidence;
    }

}
