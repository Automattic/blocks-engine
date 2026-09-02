<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Typography token variables active for the current artifact page.
 */
final class StaticHtmlTypographyState
{
    /** @var array<string, string> */
    private array $tokenVars = array();

    /** @param array<string, mixed> $tokenVars */
    public function replace(array $tokenVars): void
    {
        $this->tokenVars = $tokenVars;
    }

    /** @return array<string, string> */
    public function tokenVars(): array
    {
        return $this->tokenVars;
    }
}
