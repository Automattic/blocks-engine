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
    private readonly VectorSvgRenderer $vectorSvgRenderer;

    public function __construct(
        StaticHtmlNodeInspector $nodeInspector,
        StaticHtmlValueFormatter $formatter,
        private readonly StaticHtmlVectorEvidence $vectorEvidence,
    ) {
        $this->pageState = new StaticHtmlPageState();
        $this->linkState = new StaticHtmlLinkState();
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

    public function vectorEvidence(): StaticHtmlVectorEvidence
    {
        return $this->vectorEvidence;
    }

}
