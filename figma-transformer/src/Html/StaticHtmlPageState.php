<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Mutable state whose lifetime is one emitted HTML page.
 */
final class StaticHtmlPageState
{
    /** @var array<string, string> */
    public array $headingLevels = array();

    /** @var array<string, string> */
    public array $headingAnchorIds = array();

    /** @var array<string, string> */
    public array $tocHrefByText = array();

    public string $path = 'index.html';

    public string $templateType = '';

    public string $templateSlug = '';

    /** @var array<string, array<int, string>> */
    public array $listItemIdCache = array();

    /** @var array<string, int> */
    public array $formControlNameCounts = array();

    public int $sectionDepth = 0;

    public int $inlineVectorSvgBytes = 0;

    public function reset(string $path, string $templateType, string $templateSlug, int $sectionDepth): void
    {
        $this->headingLevels = array();
        $this->headingAnchorIds = array();
        $this->tocHrefByText = array();
        $this->path = $path;
        $this->templateType = $templateType;
        $this->templateSlug = $templateSlug;
        $this->listItemIdCache = array();
        $this->formControlNameCounts = array();
        $this->sectionDepth = $sectionDepth;
        $this->inlineVectorSvgBytes = 0;
    }
}
