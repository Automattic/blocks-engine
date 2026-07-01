<?php

declare(strict_types=1);

final class SyntheticFigKiwiFixtureBuilder
{
    /**
     * @param array<int, string> $chunks
     */
    public static function canvas(array $chunks, int $version = 106): string
    {
        return 'fig-kiwi' . pack('V', $version) . implode('', $chunks);
    }

    public static function chunk(string $payload): string
    {
        return pack('V', strlen($payload)) . $payload;
    }

    public static function zlibChunk(string $payload): string
    {
        return self::chunk(gzdeflate($payload));
    }

    public static function zstdMarkerChunk(string $payload = 'synthetic-zstd-frame'): string
    {
        if ( function_exists('zstd_compress') ) {
            $compressed = zstd_compress($payload);
            if ( false !== $compressed ) {
                return self::chunk($compressed);
            }
        }

        return self::chunk("\x28\xb5\x2f\xfd" . $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function jsonZlibChunk(array $payload): string
    {
        return self::zlibChunk(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, string> $images
     */
    public static function figArchive(string $canvas, array $images = array(), array $meta = array('name' => 'Synthetic Fixture')): string
    {
        $path = tempnam(sys_get_temp_dir(), 'blocks-engine-fig-');
        if ( false === $path ) {
            throw new RuntimeException('Could not create temporary fig archive path.');
        }

        $zip = new ZipArchive();
        if ( true !== $zip->open($path, ZipArchive::OVERWRITE) ) {
            throw new RuntimeException('Could not open fig archive ZIP.');
        }

        $zip->addFromString('canvas.fig', $canvas);
        $zip->addFromString('meta.json', json_encode($meta, JSON_THROW_ON_ERROR));
        foreach ( $images as $pathInArchive => $content ) {
            $zip->addFromString($pathInArchive, $content);
        }
        $zip->close();

        return $path;
    }

    /**
     * @param array<int, string>     $payloads
     * @param array<string, string>  $images
     * @param array<string, mixed>   $meta
     */
    public static function zlibFigArchive(array $payloads, array $images = array(), array $meta = array('name' => 'Synthetic Fixture')): string
    {
        return self::figArchive(
            self::canvas(array_map(static fn (string $payload): string => self::zlibChunk($payload), $payloads)),
            $images,
            $meta
        );
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $images
     * @param array<string, mixed>  $meta
     */
    public static function jsonFigArchive(array $payload, array $images = array(), array $meta = array('name' => 'Synthetic Fixture')): string
    {
        return self::figArchive(
            self::canvas(array(self::jsonZlibChunk($payload))),
            $images,
            $meta
        );
    }

    /**
     * @param array<string, string> $images
     */
    public static function wrapperArchive(string $canvas, array $images = array('images/synthetic' => 'asset')): string
    {
        $inner = self::figArchive($canvas, $images);
        $outer = tempnam(sys_get_temp_dir(), 'blocks-engine-wrapper-fig-');
        if ( false === $outer ) {
            @unlink($inner);
            throw new RuntimeException('Could not create temporary fig wrapper path.');
        }

        $zip = new ZipArchive();
        if ( true !== $zip->open($outer, ZipArchive::OVERWRITE) ) {
            @unlink($inner);
            throw new RuntimeException('Could not open wrapper fig ZIP.');
        }

        $zip->addFromString('inner.fig', (string) file_get_contents($inner));
        $zip->close();
        @unlink($inner);

        return $outer;
    }

    public static function wireVarint(int $value): string
    {
        $bytes = '';
        do {
            $byte = $value & 0x7f;
            $value = intdiv($value, 128);
            if ( $value > 0 ) {
                $byte |= 0x80;
            }
            $bytes .= chr($byte);
        } while ( $value > 0 );

        return $bytes;
    }

    public static function sampleWirePayload(): string
    {
        return self::wireVarint(8)
            . self::wireVarint(150)
            . self::wireVarint(18)
            . self::wireVarint(5)
            . 'hello'
            . self::wireVarint(29)
            . "\x01\x02\x03\x04";
    }

    /**
     * @return array<string, mixed>
     */
    public static function nodeChangesPayload(string $prefix = 'Decoded'): array
    {
        return array(
            'name'         => $prefix . ' Node Changes Fixture',
            'NODE_CHANGES' => array(
                '4:1' => array(
                    'node' => array(
                        'id'       => '4:1',
                        'type'     => 'FRAME',
                        'name'     => $prefix . ' Landing',
                        'children' => array(
                            array(
                                'id'         => '4:2',
                                'type'       => 'TEXT',
                                'name'       => 'Heading',
                                'characters' => $prefix . ' First',
                            ),
                            array(
                                'id'         => '4:3',
                                'type'       => 'TEXT',
                                'name'       => 'Body',
                                'characters' => $prefix . ' Second',
                            ),
                            array(
                                'id'   => '4:4',
                                'type' => 'RECTANGLE',
                                'name' => $prefix . ' Photo',
                            ),
                        ),
                    ),
                ),
            ),
        );
    }
}
