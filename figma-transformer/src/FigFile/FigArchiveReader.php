<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\FigFile;

use ZipArchive;

/**
 * Reads safe metadata from Figma .fig archives and .fig wrapper ZIP files.
 */
final class FigArchiveReader
{
    public function __construct(
        private readonly FigKiwiParser $figKiwiParser = new FigKiwiParser()
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function read(string $path): array
    {
        if ( ! is_readable($path) ) {
            return $this->errorResult($path, 'figma_transformer_unreadable_file', 'Figma file is not readable.');
        }

        $bytes = filesize($path);
        $input = array(
            'path'  => $path,
            'bytes' => false === $bytes ? 0 : $bytes,
        );

        if ( ! class_exists(ZipArchive::class) ) {
            return array(
                'input'       => $input,
                'archive'     => array(),
                'meta'        => array(),
                'assets'      => array(),
                'diagnostics' => array($this->diagnostic('figma_transformer_zip_extension_missing', 'ZipArchive is required to inspect .fig archives.', 'FigArchiveReader')),
            );
        }

        $zip = new ZipArchive();
        if ( true !== $zip->open($path) ) {
            return $this->errorResult($path, 'figma_transformer_invalid_zip', 'Figma file is not a readable ZIP archive.');
        }

        $entries = $this->entries($zip);
        $figEntry = $this->findNestedFigEntry($entries);

        if ( null !== $figEntry ) {
            $stream = $zip->getFromName($figEntry);
            $zip->close();

            if ( false === $stream ) {
                return $this->errorResult($path, 'figma_transformer_nested_fig_unreadable', 'Nested .fig file could not be read.');
            }

            $tmp = tempnam(sys_get_temp_dir(), 'blocks-engine-figma-');
            if ( false === $tmp ) {
                return $this->errorResult($path, 'figma_transformer_tempfile_failed', 'Temporary file could not be created for nested .fig inspection.');
            }

            file_put_contents($tmp, $stream);
            $result = $this->read($tmp);
            @unlink($tmp);
            $result['input'] = $input + array('nested_fig' => $figEntry);
            return $result;
        }

        $meta = $this->readMeta($zip);
        $assets = $this->assetManifest($zip);
        $canvasResult = $this->readCanvas($zip);
        $canvas = $canvasResult['canvas'];
        $zip->close();

        $diagnostics = $canvasResult['diagnostics'];
        if ( null === $canvas ) {
            $diagnostics[] = $this->diagnostic('figma_transformer_missing_canvas', 'Archive does not contain canvas.fig.', 'FigArchiveReader');
        }

        return array(
            'input'       => $input,
            'archive'     => array(
                'entries' => $entries,
                'canvas'  => $canvas,
            ),
            'meta'        => $meta,
            'assets'      => $assets,
            'diagnostics' => $diagnostics,
        );
    }

    /**
     * @return array<int, string>
     */
    private function entries(ZipArchive $zip): array
    {
        $entries = array();
        for ( $index = 0; $index < $zip->numFiles; $index++ ) {
            $name = $zip->getNameIndex($index);
            if ( false !== $name && ! str_starts_with($name, '__MACOSX/') ) {
                $entries[] = $name;
            }
        }

        return $entries;
    }

    /**
     * @param array<int, string> $entries
     */
    private function findNestedFigEntry(array $entries): ?string
    {
        foreach ( $entries as $entry ) {
            if ( str_ends_with(strtolower($entry), '.fig') && 'canvas.fig' !== $entry ) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function readMeta(ZipArchive $zip): array
    {
        $raw = $zip->getFromName('meta.json');
        if ( false === $raw ) {
            return array();
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function assetManifest(ZipArchive $zip): array
    {
        $assets = array();
        for ( $index = 0; $index < $zip->numFiles; $index++ ) {
            $name = $zip->getNameIndex($index);
            if ( false === $name || ! str_starts_with($name, 'images/') || str_ends_with($name, '/') ) {
                continue;
            }

            $stat = $zip->statIndex($index);
            $assets[] = array(
                'path'  => $name,
                'hash'  => basename($name),
                'bytes' => is_array($stat) ? (int) ($stat['size'] ?? 0) : 0,
            );
        }

        return $assets;
    }

    /**
     * @return array{canvas: array<string, mixed>|null, diagnostics: array<int, array<string, mixed>>}
     */
    private function readCanvas(ZipArchive $zip): array
    {
        $raw = $zip->getFromName('canvas.fig');
        if ( false === $raw ) {
            return array(
                'canvas'      => null,
                'diagnostics' => array(),
            );
        }

        return $this->figKiwiParser->parse($raw);
    }

    /**
     * @return array<string, mixed>
     */
    private function errorResult(string $path, string $code, string $message): array
    {
        return array(
            'input'       => array('path' => $path, 'bytes' => 0),
            'archive'     => array(),
            'meta'        => array(),
            'assets'      => array(),
            'diagnostics' => array($this->diagnostic($code, $message, 'FigArchiveReader')),
        );
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function diagnostic(string $code, string $message, string $source, array $context = array()): array
    {
        return array(
            'code'    => $code,
            'message' => $message,
            'source'  => $source,
            'context' => $context,
        );
    }
}
