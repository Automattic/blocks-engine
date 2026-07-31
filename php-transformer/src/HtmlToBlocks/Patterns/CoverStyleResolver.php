<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter;

/**
 * Derives core/cover attributes and recognition gates from resolved CSS.
 */
final class CoverStyleResolver
{
    /**
     * @return array<string, string>
     */
    public function declarations(string $style): array
    {
        $declarations = array();
        foreach ( CssValueSplitter::splitTopLevel($style, array( ';' )) as $declaration ) {
            $parts = CssValueSplitter::splitTopLevel($declaration, array( ':' ));
            if ( count($parts) < 2 ) {
                continue;
            }

            $name  = strtolower(trim((string) array_shift($parts)));
            $value = trim(implode(':', $parts));
            if ( '' !== $name && '' !== $value ) {
                $declarations[ $name ] = $value;
            }
        }

        return $declarations;
    }

    /**
     * @return array{dimRatio:int, customOverlayColor:string}
     */
    public function dimFromStyle(string $style): array
    {
        $default = array(
            'dimRatio'           => 0,
            'customOverlayColor' => '',
        );

        $declarations = $this->declarations($style);
        foreach ( array( 'background', 'background-image' ) as $property ) {
            $value = (string) ($declarations[ $property ] ?? '');
            if ( '' === $value ) {
                continue;
            }

            $layers   = CssValueSplitter::splitTopLevel($value, array( ',' ));
            $urlIndex = null;
            foreach ( $layers as $index => $layer ) {
                if ( preg_match('/\burl\s*\(/i', $layer) ) {
                    $urlIndex = $index;
                    break;
                }
            }

            if ( null === $urlIndex ) {
                continue;
            }

            for ( $index = 0; $index < $urlIndex; ++$index ) {
                if ( ! preg_match('/^linear-gradient\s*\((.*)\)$/is', trim($layers[ $index ]), $matches) ) {
                    continue;
                }

                $stops = CssValueSplitter::splitTopLevel($matches[1], array( ',' ));
                if ( 2 !== count($stops) ) {
                    return $default;
                }

                $first  = $this->overlayColor($stops[0]);
                $second = $this->overlayColor($stops[1]);
                if ( null === $first || $first !== $second ) {
                    return $default;
                }

                return array(
                    'dimRatio'           => ( (int) round($first['alpha'] * 10) ) * 10,
                    'customOverlayColor' => sprintf('#%02x%02x%02x', $first['red'], $first['green'], $first['blue']),
                );
            }
        }

        return $default;
    }

    /**
     * @return array{minHeight:float|int, minHeightUnit:string}|null
     */
    public function minHeightFromStyle(string $style): ?array
    {
        $declarations = $this->declarations($style);
        $value        = array_key_exists('min-height', $declarations)
            ? $declarations['min-height']
            : (string) ($declarations['height'] ?? '');
        $value        = strtolower(trim($value));
        if ( ! preg_match('/^(\d+(?:\.\d+)?)(px|vh|rem)$/', $value, $matches) ) {
            return null;
        }

        $number = str_contains($matches[1], '.') ? (float) $matches[1] : (int) $matches[1];

        return array(
            'minHeight'     => $number,
            'minHeightUnit' => $matches[2],
        );
    }

    /**
     * @return array{x:float, y:float}|null
     */
    public function focalPointFromStyle(string $style): ?array
    {
        $declarations = $this->declarations($style);
        $value        = strtolower(trim((string) ($declarations['background-position'] ?? '')));
        if ( '' === $value ) {
            return null;
        }

        $parts = CssValueSplitter::splitTopLevelWhitespace($value);
        if ( count($parts) < 1 || count($parts) > 2 ) {
            return null;
        }

        $x = $this->positionValue($parts[0], array(
            'left'   => 0.0,
            'center' => 0.5,
            'right'  => 1.0,
        ));
        $y = 1 === count($parts)
            ? 0.5
            : $this->positionValue($parts[1], array(
                'top'    => 0.0,
                'center' => 0.5,
                'bottom' => 1.0,
            ));
        if ( null === $x || null === $y || ( 0.5 === $x && 0.5 === $y ) ) {
            return null;
        }

        return array(
            'x' => $x,
            'y' => $y,
        );
    }

    public function meetsHeroSizeGate(string $style): bool
    {
        $declarations = $this->declarations($style);
        $size         = strtolower(trim((string) ($declarations['background-size'] ?? '')));
        foreach ( CssValueSplitter::splitTopLevel($size, array( ',' )) as $layerSize ) {
            if ( array( 'cover' ) === CssValueSplitter::splitTopLevelWhitespace($layerSize) ) {
                return true;
            }
        }

        $background = (string) ($declarations['background'] ?? '');
        foreach ( CssValueSplitter::splitTopLevel($background, array( ',' )) as $layer ) {
            $slashParts = CssValueSplitter::splitTopLevel($layer, array( '/' ));
            foreach ( array_slice($slashParts, 1) as $sizeAndRepeat ) {
                $tokens = CssValueSplitter::splitTopLevelWhitespace(strtolower($sizeAndRepeat));
                if ( 'cover' === ($tokens[0] ?? '') ) {
                    return true;
                }
            }
        }

        $minHeight = $this->minHeightFromStyle($style);
        if ( null === $minHeight ) {
            return false;
        }

        $thresholds = array(
            'px'  => 200,
            'vh'  => 30,
            'rem' => 12.5,
        );

        return $minHeight['minHeight'] >= $thresholds[ $minHeight['minHeightUnit'] ];
    }

    public function hasRepeatingBackground(string $style): bool
    {
        $declarations = $this->declarations($style);
        $repeat       = strtolower(trim((string) ($declarations['background-repeat'] ?? '')));
        foreach ( CssValueSplitter::splitTopLevel($repeat, array( ',' )) as $layerRepeat ) {
            $tokens = CssValueSplitter::splitTopLevelWhitespace($layerRepeat);
            foreach ( $tokens as $token ) {
                if ( in_array($token, array( 'repeat', 'repeat-x', 'repeat-y' ), true) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{red:int, green:int, blue:int, alpha:float}|null
     */
    private function overlayColor(string $literal): ?array
    {
        $literal = strtolower(trim($literal));
        if ( preg_match('/^#([0-9a-f]{4}|[0-9a-f]{8})$/', $literal, $matches) ) {
            $hex = $matches[1];
            if ( 4 === strlen($hex) ) {
                $red   = hexdec(str_repeat($hex[0], 2));
                $green = hexdec(str_repeat($hex[1], 2));
                $blue  = hexdec(str_repeat($hex[2], 2));
                $alpha = hexdec(str_repeat($hex[3], 2)) / 255;
            } else {
                $red   = hexdec(substr($hex, 0, 2));
                $green = hexdec(substr($hex, 2, 2));
                $blue  = hexdec(substr($hex, 4, 2));
                $alpha = hexdec(substr($hex, 6, 2)) / 255;
            }

            if ( $alpha >= 1 ) {
                return null;
            }

            return array(
                'red'   => $red,
                'green' => $green,
                'blue'  => $blue,
                'alpha' => $alpha,
            );
        }

        if ( ! preg_match('/^rgba\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*((?:0(?:\.\d+)?|1(?:\.0+)?|\.\d+))\s*\)$/', $literal, $matches) ) {
            return null;
        }

        $red   = (int) $matches[1];
        $green = (int) $matches[2];
        $blue  = (int) $matches[3];
        $alpha = (float) $matches[4];
        if ( $red > 255 || $green > 255 || $blue > 255 || $alpha >= 1 ) {
            return null;
        }

        return array(
            'red'   => $red,
            'green' => $green,
            'blue'  => $blue,
            'alpha' => $alpha,
        );
    }

    /**
     * @param array<string, float> $keywords
     */
    private function positionValue(string $value, array $keywords): ?float
    {
        if ( array_key_exists($value, $keywords) ) {
            return $keywords[ $value ];
        }

        if ( ! preg_match('/^(-?(?:\d+(?:\.\d+)?|\.\d+))%$/', $value, $matches) ) {
            return null;
        }

        return max(0.0, min(1.0, (float) $matches[1] / 100));
    }
}
