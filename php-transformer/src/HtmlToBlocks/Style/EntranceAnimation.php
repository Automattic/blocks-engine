<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;

/** Recognizes finite opacity/translation reveals, not slide positioning or loops. */
final class EntranceAnimation
{
    /** @param array<string, string> $declarations */
    public static function matches(array $declarations, string $css): bool
    {
        if ( ! isset($declarations['animation']) && ! isset($declarations['animation-name']) ) return false;
        $names = array();
        foreach ( CssValueSplitter::splitTopLevel($declarations['animation'] ?? '', array(',')) as $layer ) {
            foreach ( CssValueSplitter::splitTopLevelWhitespace($layer) as $token ) {
                if ( 'infinite' === strtolower($token) || (is_numeric($token) && 1.0 !== (float) $token) ) return false;
                $names[] = $token;
            }
        }
        if ( isset($declarations['animation-name']) ) {
            $names = CssValueSplitter::splitTopLevel($declarations['animation-name'], array(','));
        }
        foreach ( CssValueSplitter::splitTopLevel($declarations['animation-iteration-count'] ?? '1', array(',')) as $count ) {
            if ( ! is_numeric($count) || 1.0 !== (float) $count ) return false;
        }
        if ( isset($declarations['animation-direction']) && 'normal' !== trim($declarations['animation-direction']) ) return false;
        if ( preg_match('/\b(?:reverse|alternate|alternate-reverse)\b/i', $declarations['animation'] ?? '') ) return false;

        $transformer = new CssStylesheetTransformer();
        $reveals = array();
        $transformer->visitKeyframeRules($css, static function (string $name, string $body) use ($transformer, &$reveals): void {
            $valid = true;
            $start = array();
            $end = array();
            $transformer->visitStyleRules($body, static function (string $offset, string $body) use (&$valid, &$start, &$end): void {
                $frame = SourceDeclarations::parse($body);
                foreach ( $frame as $property => $value ) {
                    if ( 'opacity' !== $property && ('transform' !== $property || ! self::translation($value)) ) $valid = false;
                }
                foreach ( CssValueSplitter::splitTopLevel($offset, array(',')) as $part ) {
                    if ( in_array(trim($part), array('from', '0%'), true) ) $start = array_merge($start, $frame);
                    if ( in_array(trim($part), array('to', '100%'), true) ) $end = array_merge($end, $frame);
                }
            });
            $fadesIn = isset($start['opacity'], $end['opacity']) && is_numeric($start['opacity'])
                && (float) $start['opacity'] < 1.0 && 1.0 === (float) $end['opacity'];
            $slidesIn = isset($start['transform'], $end['transform'])
                && ! self::identityTranslation($start['transform']) && self::identityTranslation($end['transform']);
            if ( $valid && ($fadesIn || ($slidesIn && ! isset($start['opacity']) && ! isset($end['opacity'])))
                && (! isset($end['transform']) || self::identityTranslation($end['transform']))
            ) $reveals[$name] = array($start['transform'] ?? 'none', $end['transform'] ?? 'none');
        });
        $found = false;
        foreach ( $names as $name ) {
            if ( isset($reveals[$name]) ) {
                $found = true;
                if ( isset($declarations['transform']) && ! in_array($declarations['transform'], $reveals[$name], true) ) return false;
            } elseif ( isset($declarations['animation-name']) ) {
                return false;
            }
        }
        // Every shorthand layer must name a recognized reveal. Unknown animation
        // names cannot be treated as decoration merely because one layer reveals.
        foreach ( CssValueSplitter::splitTopLevel($declarations['animation'] ?? '', array(',')) as $layer ) {
            if ( array() === array_intersect(CssValueSplitter::splitTopLevelWhitespace($layer), array_keys($reveals)) ) return false;
        }
        return $found && ! array_intersect(array_keys($declarations), array('translate', 'rotate', 'scale', 'perspective'));
    }

    private static function translation(string $value): bool
    {
        return 'none' === $value || 1 === preg_match('/^(?:translate(?:X|Y|Z|3d)?\([^()]+\)\s*)+$/i', trim($value));
    }

    private static function identityTranslation(string $value): bool
    {
        return 'none' === $value || 1 === preg_match('/^(?:translate(?:X|Y|Z|3d)?\(\s*0(?:[a-z%]+)?(?:\s*,?\s*0(?:[a-z%]+)?)*\s*\)\s*)+$/i', trim($value));
    }
}
