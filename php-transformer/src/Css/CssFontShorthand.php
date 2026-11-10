<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Conservative expansion of the ordinary (non-system) CSS font shorthand. */
final class CssFontShorthand
{
    /**
     * @param int|null $familyOffset Receives the family start in the original value.
     * @return array<string, string> Empty when the value cannot safely be expanded.
     */
    public static function expand(string $value, bool $allowFluidSize = false, ?int &$familyOffset = null): array
    {
        $familyOffset = null;
        $leading = strlen($value) - strlen(ltrim($value));
        $important = 1 === preg_match('/\s*!\s*important\s*$/i', $value) ? ' !important' : '';
        $value = trim(preg_replace('/\s*!important\s*$/i', '', $value) ?? $value);
        // Substitution and system/CSS-wide keywords require computed-value data.
        if (!$allowFluidSize && preg_match('/\bvar\s*\(/i', $value)) return array();
        $tokens = CssValueSplitter::splitTopLevelWhitespace($value);
        $result = array('font-style' => 'normal', 'font-variant' => 'normal', 'font-weight' => 'normal');
        $seen = array();
        while (array() !== $tokens) {
            $token = array_shift($tokens);
            $lower = strtolower($token);
            $size = CssValueSplitter::splitTopLevel($token, array('/'));
            if (($allowFluidSize && preg_match('/^(?:calc|min|max|clamp)\(.+\)$/is', $size[0] ?? '')) || preg_match('/^(?:0|(?:\d*\.)?\d+(?:px|pt|pc|in|cm|mm|q|em|rem|ex|ch|vw|vh|vmin|vmax|%)|xx-small|x-small|small|medium|large|x-large|xx-large|xxx-large|smaller|larger)$/i', $size[0] ?? '')) {
                $result['font-size'] = $size[0];
                $lineHeight = $size[1] ?? 'normal';
                if (count($size) > 2) return array();
                if (str_ends_with($token, '/')) {
                    $lineHeight = array_shift($tokens) ?? '';
                } elseif (isset($tokens[0]) && str_starts_with($tokens[0], '/')) {
                    $lineHeight = substr(array_shift($tokens), 1);
                    if ('' === $lineHeight) $lineHeight = array_shift($tokens) ?? '';
                }
                if (!preg_match('/^(?:normal|(?:\d*\.)?\d+(?:px|pt|pc|in|cm|mm|q|em|rem|ex|ch|vw|vh|vmin|vmax|%)?)$/i', $lineHeight)) return array();
                if (array() === $tokens) return array();
                $result['line-height'] = $lineHeight;
                $result['font-family'] = implode(' ', $tokens);
                // Locate the remaining tokens in the source rather than rebuilding
                // the prefix: shorthand whitespace and spelling must survive binding.
                $offset = strlen($value);
                foreach (array_reverse($tokens) as $familyToken) {
                    $offset = strrpos(substr($value, 0, $offset), $familyToken);
                    if (false === $offset) return array();
                }
                $familyOffset = $leading + $offset;
                foreach ($result as &$longhand) $longhand .= $important;
                unset($longhand);
                return $result;
            }
            if ('normal' === $lower) continue;
            $property = match (true) {
                in_array($lower, array('italic', 'oblique'), true) => 'font-style',
                'small-caps' === $lower => 'font-variant',
                in_array($lower, array('bold', 'bolder', 'lighter'), true),
                1 === preg_match('/^[1-9]00$/', $lower) => 'font-weight',
                default => null,
            };
            if (null === $property || isset($seen[$property])) return array();
            $seen[$property] = true;
            $result[$property] = $token;
        }
        return array();
    }
}
