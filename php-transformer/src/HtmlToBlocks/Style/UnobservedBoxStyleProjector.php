<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/** Author presentation for retained boxes absent from a sparse capture tree. */
final class UnobservedBoxStyleProjector
{
    public function project(array $attrs, DOMElement $element, StyleResolver $styles, LayoutGeometryState $state): array
    {
        // These are declarations, not observations. Keep percentages and logical
        // sizes intact: the retained source box still has its containing block.
        $properties = array_merge(BoxTree::PROPERTIES, array(
            'block-size', 'inline-size', 'min-block-size', 'max-block-size',
            'min-inline-size', 'max-inline-size', 'overflow', 'mask-size',
            'mask-position', 'mask-repeat', 'border-style', 'border-width', 'border-color',
        ));
        $widths = array_merge(array(0.0), $styles->authorViewport->widthBreakpoints());
        $rules = '';
        $previous = array();
        foreach ($widths as $width) {
            $declarations = $styles->authorViewport->atViewport($element, $properties, $width + 0.01);
            foreach ($declarations as $property => &$value) {
                $value = $styles->customProperties->resolveAtViewport($value, $element, $width + 0.01);
                $value = CssValueInspector::carriableDeclarationValue($value);
            }
            unset($value);
            $declarations = array_filter($declarations, static fn(string $value): bool => '' !== $value);
            $delta = array_diff_assoc($declarations, $previous);
            foreach (array_diff_key($previous, $declarations) as $property => $value) $delta[$property] = 'revert';
            $previous = $declarations;
            if (array() === $delta) continue;
            $body = '';
            foreach ($delta as $property => $value) $body .= $property . ':' . $value . ';';
            $rule = "\0carrier\0{" . $body . '}';
            $rules .= 0.0 === $width ? $rule : '@media (min-width:' . ($width + 0.01) . 'px){' . $rule . '}';
        }
        if ('' === $rules) return $attrs;
        $carrier = $state->allocateCarrier('unobserved-box' . "\n" . $rules);
        $state->registerRule($carrier, str_replace("\0carrier\0", '.' . $carrier . '.' . $carrier, $rules));
        $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $carrier);
        return $attrs;
    }
}
