<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Compression;

/**
 * Reports whether native Zstandard decoding is available to PHP.
 */
final class ZstdCapability
{
    public function isAvailable(): bool
    {
        return extension_loaded('zstd') && function_exists('zstd_uncompress');
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnostic(string $source, int $chunkIndex): array
    {
        if ( $this->isAvailable() ) {
            return array(
                'code'    => 'figma_transformer_zstd_available',
                'message' => 'Zstandard chunk detected and ext-zstd is available.',
                'source'  => $source,
                'context' => array('chunk_index' => $chunkIndex),
            );
        }

        return array(
            'code'    => 'figma_transformer_zstd_extension_missing',
            'message' => 'Zstandard chunk detected; install ext-zstd to decode zstd-compressed fig-kiwi chunks.',
            'source'  => $source,
            'context' => array('chunk_index' => $chunkIndex),
        );
    }
}
