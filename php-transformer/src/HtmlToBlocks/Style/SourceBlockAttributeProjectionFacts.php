<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/** Conversion decisions computed before source attributes are projected. */
final class SourceBlockAttributeProjectionFacts
{
    public function __construct(
        public readonly bool $isInlineSourceElement,
        public readonly bool $isAuthorLayoutItem,
        public readonly bool $hasAuthorControlProjection,
        public readonly bool $isDirectChildOfAuthorFlexLayout,
        public readonly bool $preserveGeneratedStyle
    ) {}
}
