<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\FigFile;

use Automattic\BlocksEngine\FigmaTransformer\Compression\ZstdCapability;

/**
 * Parses the lightweight fig-kiwi envelope used by canvas.fig.
 */
final class FigKiwiParser
{
    private const PRELUDE = 'fig-kiwi';
    private const ZSTD_MAGIC = "\x28\xb5\x2f\xfd";

    public function __construct(
        private readonly ZstdCapability $zstdCapability = new ZstdCapability()
    ) {
    }

    /**
     * @return array{canvas: array<string, mixed>|null, diagnostics: array<int, array<string, mixed>>}
     */
    public function parse(string $raw): array
    {
        if ( strlen($raw) < 12 ) {
            return array(
                'canvas'      => null,
                'diagnostics' => array($this->diagnostic('figma_transformer_canvas_too_short', 'canvas.fig is too short to contain a fig-kiwi header.')),
            );
        }

        $prelude = substr($raw, 0, 8);
        $version = $this->uint32(substr($raw, 8, 4));

        $canvas = array(
            'prelude' => $prelude,
            'version' => $version,
            'bytes'   => strlen($raw),
        );

        if ( self::PRELUDE !== $prelude ) {
            return array(
                'canvas'      => $canvas,
                'diagnostics' => array(),
            );
        }

        $chunks = array();
        $diagnostics = array();
        $offset = 12;
        $index = 0;
        $totalBytes = strlen($raw);

        while ( $offset < $totalBytes ) {
            if ( $offset + 4 > $totalBytes ) {
                $diagnostics[] = $this->diagnostic('figma_transformer_kiwi_truncated_chunk_table', 'fig-kiwi chunk table ends with an incomplete chunk length.', array('offset' => $offset));
                break;
            }

            $compressedBytes = $this->uint32(substr($raw, $offset, 4));
            $dataOffset = $offset + 4;
            $nextOffset = $dataOffset + $compressedBytes;

            if ( $nextOffset > $totalBytes ) {
                $diagnostics[] = $this->diagnostic(
                    'figma_transformer_kiwi_truncated_chunk',
                    'fig-kiwi chunk length exceeds the available canvas.fig bytes.',
                    array(
                        'chunk_index'      => $index,
                        'offset'           => $offset,
                        'compressed_bytes' => $compressedBytes,
                    )
                );
                break;
            }

            $payload = substr($raw, $dataOffset, $compressedBytes);
            $chunk = array(
                'index'            => $index,
                'offset'           => $offset,
                'data_offset'      => $dataOffset,
                'compressed_bytes' => $compressedBytes,
                'compression'      => $this->detectCompression($payload),
            );

            if ( 'zlib' === $chunk['compression'] ) {
                $inflated = @gzinflate($payload);
                if ( false === $inflated ) {
                    $diagnostics[] = $this->diagnostic('figma_transformer_kiwi_zlib_inflate_failed', 'zlib-compressed fig-kiwi chunk could not be inflated.', array('chunk_index' => $index));
                } else {
                    $chunk['inflated_bytes'] = strlen($inflated);
                    $chunk['inflated_preview_hex'] = bin2hex(substr($inflated, 0, 32));
                }
            } elseif ( 'zstd' === $chunk['compression'] ) {
                $diagnostics[] = $this->zstdCapability->diagnostic('FigKiwiParser', $index);
            } else {
                $diagnostics[] = $this->diagnostic('figma_transformer_kiwi_unknown_compression', 'fig-kiwi chunk compression could not be identified.', array('chunk_index' => $index));
            }

            $chunks[] = $chunk;
            $offset = $nextOffset;
            $index++;
        }

        $canvas['chunks'] = $chunks;

        return array(
            'canvas'      => $canvas,
            'diagnostics' => $diagnostics,
        );
    }

    private function uint32(string $bytes): int
    {
        $value = unpack('V', $bytes);
        return is_array($value) ? (int) $value[1] : 0;
    }

    private function detectCompression(string $payload): string
    {
        if ( str_starts_with($payload, self::ZSTD_MAGIC) ) {
            return 'zstd';
        }

        if ( false !== @gzinflate($payload) ) {
            return 'zlib';
        }

        return 'unknown';
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function diagnostic(string $code, string $message, array $context = array()): array
    {
        return array(
            'code'    => $code,
            'message' => $message,
            'source'  => 'FigKiwiParser',
            'context' => $context,
        );
    }
}
