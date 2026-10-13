<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

/** Dominant opaque color of a small single-color glyph, or empty when the image is not one. */
final class MonochromeGlyphColor
{
    private const MAX_BYTES = 65536;

    private const MAX_EDGE = 256;

    private const MIN_OPAQUE = 8;

    private const ALPHA_OPAQUE = 16;

    private const CHANNEL_DELTA = 18;

    private const CLUSTER_SHARE = 0.85;

    /**
     * Inspect a glyph payload. `undecodable` is true only when the host GD
     * cannot decode the bytes (for example AVIF on a PHP built without
     * libavif), so callers can report the lost colour instead of dropping it
     * silently. Images that decode but are not monochrome are not undecodable.
     *
     * @return array{color:string, undecodable:bool}
     */
    public static function inspect(string $bytes): array
    {
        if ( '' === $bytes || strlen($bytes) > self::MAX_BYTES ) {
            return array('color' => '', 'undecodable' => false);
        }

        $image = function_exists('imagecreatefromstring') ? @imagecreatefromstring($bytes) : false;
        if ( false === $image ) {
            return array('color' => '', 'undecodable' => true);
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ( $width < 1 || $height < 1 || $width > self::MAX_EDGE || $height > self::MAX_EDGE ) {
            return array('color' => '', 'undecodable' => false);
        }

        if ( ! imageistruecolor($image) ) {
            $truecolor = imagecreatetruecolor($width, $height);
            if ( false === $truecolor ) {
                return array('color' => '', 'undecodable' => false);
            }
            imagealphablending($truecolor, false);
            imagesavealpha($truecolor, true);
            imagecopy($truecolor, $image, 0, 0, 0, 0, $width, $height);
            $image = $truecolor;
        }

        $counts = array();
        $opaque = 0;
        for ( $y = 0; $y < $height; $y++ ) {
            for ( $x = 0; $x < $width; $x++ ) {
                $rgba = imagecolorat($image, $x, $y);
                if ( (($rgba >> 24) & 0x7F) > self::ALPHA_OPAQUE ) {
                    continue;
                }
                $key = $rgba & 0xFFFFFF;
                $counts[ $key ] = ($counts[ $key ] ?? 0) + 1;
                $opaque++;
            }
        }

        if ( $opaque < self::MIN_OPAQUE || array() === $counts ) {
            return array('color' => '', 'undecodable' => false);
        }

        arsort($counts);
        $mode = (int) array_key_first($counts);
        $modeRed = ($mode >> 16) & 0xFF;
        $modeGreen = ($mode >> 8) & 0xFF;
        $modeBlue = $mode & 0xFF;
        $cluster = 0;
        foreach ( $counts as $key => $count ) {
            $key = (int) $key;
            $distance = max(
                abs((($key >> 16) & 0xFF) - $modeRed),
                abs((($key >> 8) & 0xFF) - $modeGreen),
                abs(($key & 0xFF) - $modeBlue)
            );
            if ( $distance <= self::CHANNEL_DELTA ) {
                $cluster += $count;
            }
        }

        if ( ($cluster / $opaque) < self::CLUSTER_SHARE ) {
            return array('color' => '', 'undecodable' => false);
        }
        if ( $modeRed >= 250 && $modeGreen >= 250 && $modeBlue >= 250 ) {
            return array('color' => '#ffffff', 'undecodable' => false);
        }
        if ( $modeRed <= 5 && $modeGreen <= 5 && $modeBlue <= 5 ) {
            return array('color' => '#000000', 'undecodable' => false);
        }

        return array('color' => sprintf('#%02x%02x%02x', $modeRed, $modeGreen, $modeBlue), 'undecodable' => false);
    }
}
