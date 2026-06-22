<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Compression;

/**
 * Reports whether native Zstandard decoding is available to PHP.
 */
final class ZstdCapability
{
    /**
     * @param callable|null $decoder Optional test/dev decoder with signature fn(string $payload, array $context): string|null.
     */
    public function __construct(private readonly mixed $decoder = null)
    {
    }

    public function isAvailable(): bool
    {
        $status = $this->status();
        return true === $status['available'];
    }

    /**
     * @return array{available: bool, extension_loaded: bool, extension_version: string|null, functions: array<string, bool>}
     */
    public function status(): array
    {
        $extensionLoaded = extension_loaded('zstd');

        return array(
            'available'         => $extensionLoaded && function_exists('zstd_uncompress'),
            'extension_loaded'  => $extensionLoaded,
            'extension_version' => $extensionLoaded ? phpversion('zstd') ?: null : null,
            'functions'         => array(
                'zstd_compress'   => function_exists('zstd_compress'),
                'zstd_uncompress' => function_exists('zstd_uncompress'),
            ),
        );
    }

    /**
     * @return array{data: string|null, diagnostics: array<int, array<string, mixed>>}
     */
    public function uncompress(string $payload, string $source, int $chunkIndex): array
    {
        $injected = $this->decodeWithInjectedDecoder($payload, array('source' => $source, 'chunk_index' => $chunkIndex));
        if ( null !== $injected['data'] || ! empty($injected['diagnostics']) ) {
            return $injected;
        }

        if ( ! $this->isAvailable() ) {
            return array(
                'data'        => null,
                'diagnostics' => array($this->diagnostic($source, $chunkIndex)),
            );
        }

        try {
            $decoded = zstd_uncompress($payload);
        } catch ( \Throwable $throwable ) {
            return array(
                'data'        => null,
                'diagnostics' => array(
                    array(
                        'code'    => 'figma_transformer_zstd_uncompress_failed',
                        'message' => 'Zstandard chunk detected but ext-zstd raised an error while decoding the payload.',
                        'source'  => $source,
                        'context' => array_merge(
                            array(
                                'chunk_index' => $chunkIndex,
                                'error'       => $throwable->getMessage(),
                            ),
                            $this->status()
                        ),
                    ),
                ),
            );
        }

        if ( false === $decoded ) {
            return array(
                'data'        => null,
                'diagnostics' => array(
                    array(
                        'code'    => 'figma_transformer_zstd_uncompress_failed',
                        'message' => 'Zstandard chunk detected but ext-zstd could not decode the payload.',
                        'source'  => $source,
                        'context' => array_merge(array('chunk_index' => $chunkIndex), $this->status()),
                    ),
                ),
            );
        }

        return array(
            'data'        => $decoded,
            'diagnostics' => array($this->diagnostic($source, $chunkIndex)),
        );
    }

    /**
     * @param array<string, mixed> $context
     * @return array{data: string|null, diagnostics: array<int, array<string, mixed>>}
     */
    private function decodeWithInjectedDecoder(string $payload, array $context): array
    {
        $decoder = is_callable($this->decoder) ? $this->decoder : null;
        if ( null === $decoder && function_exists('apply_filters') ) {
            $decoder = apply_filters('blocks_engine_figma_transformer_zstd_decoder', null, $context);
        }

        if ( ! is_callable($decoder) ) {
            return array('data' => null, 'diagnostics' => array());
        }

        try {
            $decoded = $decoder($payload, $context);
        } catch ( \Throwable $throwable ) {
            return array(
                'data'        => null,
                'diagnostics' => array(
                    array(
                        'code'    => 'figma_transformer_zstd_decoder_failed',
                        'message' => 'Injected Zstandard decoder raised an error while decoding the payload.',
                        'source'  => (string) ($context['source'] ?? 'ZstdCapability'),
                        'context' => array_merge($context, array('error' => $throwable->getMessage())),
                    ),
                ),
            );
        }

        if ( ! is_string($decoded) ) {
            return array('data' => null, 'diagnostics' => array());
        }

        return array(
            'data'        => $decoded,
            'diagnostics' => array(
                array(
                    'code'    => 'figma_transformer_zstd_injected_decoder_used',
                    'message' => 'Zstandard chunk decoded by an injected decoder callable.',
                    'source'  => (string) ($context['source'] ?? 'ZstdCapability'),
                    'context' => $context,
                ),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnostic(string $source, int $chunkIndex): array
    {
        $status = $this->status();

        if ( true === $status['available'] ) {
            return array(
                'code'    => 'figma_transformer_zstd_available',
                'message' => 'Zstandard chunk detected and ext-zstd is available.',
                'source'  => $source,
                'context' => array_merge(array('chunk_index' => $chunkIndex), $status),
            );
        }

        if ( true === $status['extension_loaded'] ) {
            return array(
                'code'    => 'figma_transformer_zstd_function_missing',
                'message' => 'Zstandard chunk detected; ext-zstd is loaded but zstd_uncompress is unavailable.',
                'source'  => $source,
                'context' => array_merge(array('chunk_index' => $chunkIndex), $status),
            );
        }

        return array(
            'code'    => 'figma_transformer_zstd_extension_missing',
            'message' => 'Zstandard chunk detected; install ext-zstd to decode zstd-compressed fig-kiwi chunks.',
            'source'  => $source,
            'context' => array_merge(array('chunk_index' => $chunkIndex), $status),
        );
    }
}
