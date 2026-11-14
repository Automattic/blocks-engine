<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;

trait ResolvedPatternStyle
{
    public function resolvedStyle(DOMElement $element): string
    {
        if ( ! $this->styleResolver instanceof StyleResolver ) {
            return '';
        }

        return $this->styleResolver->customProperties->resolve(
            $this->styleResolver->specificityResolvedPresentationStyle($element),
            $element
        );
    }
}
