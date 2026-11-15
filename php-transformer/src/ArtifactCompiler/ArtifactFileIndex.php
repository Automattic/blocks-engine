<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;

/**
 * The artifact's files indexed for one compile: each file by path, its
 * images, and its materialized script contents. Readers fall back to
 * scanning the files they are given when nothing has been indexed yet.
 */
final class ArtifactFileIndex
{
    /** @var array<string, array<string, mixed>> */
    private array $filesByPath = array();

    /** @var array<int, array<string, mixed>> */
    private array $imageFiles = array();

    /** @var array<int, string> */
    private array $scriptContents = array();

    /**
     * Build immutable lookup state for one normalized artifact compilation.
     *
     * @param array<int, array<string, mixed>> $files
     */
    public function index(array $files): void
    {
        $this->filesByPath = array();
        $this->imageFiles = array();
        $this->scriptContents = array();

        foreach ( $files as $file ) {
            if ( ! is_array($file) ) {
                continue;
            }
            $path = (string) ($file['path'] ?? '');
            if ( '' !== $path ) {
                $this->filesByPath[$path] = $file;
            }
            if ( str_starts_with((string) ($file['mime_type'] ?? ''), 'image/') && ! self::isMaterializedHtmlDocument($file) ) {
                $this->imageFiles[] = $file;
            }
            if ( self::isMaterializedScriptAsset($file) && is_string($file['content'] ?? null) ) {
                $this->scriptContents[] = (string) $file['content'];
            }
        }
    }

    /** Whether any file has been indexed by path. */
    public function isIndexed(): bool
    {
        return array() !== $this->filesByPath;
    }

    /**
     * The indexed image files.
     *
     * @return array<int, array<string, mixed>>
     */
    public function imageFiles(): array
    {
        return $this->imageFiles;
    }

    /**
     * Known artifact paths for `isset()` lookups: the indexed files, or the
     * given files' paths before indexing.
     *
     * @param array<int, array<string, mixed>> $files
     * @return array<string, mixed>
     */
    public function knownPaths(array $files): array
    {
        return array() !== $this->filesByPath ? $this->filesByPath : array_fill_keys(array_column($files, 'path'), true);
    }

    /** @param array<string, mixed> $asset */
    public static function isMaterializedScriptAsset(array $asset): bool
    {
        return in_array($asset['kind'] ?? '', array('js', 'mjs'), true)
            || 'script' === ($asset['role'] ?? '')
            || in_array($asset['mime_type'] ?? '', array('application/javascript', 'text/javascript', 'application/ecmascript', 'text/ecmascript'), true);
    }

    /**
     * @param array<string, mixed> $file
     */
    public static function isLinkableDocument(array $file): bool
    {
        return in_array($file['kind'] ?? '', array('html', 'blocks'), true) && ! HtmlFragmentIncludes::isTemplatePartFile($file);
    }

    /**
     * @param array<string, mixed> $file
     */
    public static function isMaterializedHtmlDocument(array $file): bool
    {
        return 'html' === ($file['kind'] ?? '') && (self::isLinkableDocument($file) || HtmlFragmentIncludes::isTemplatePartFile($file));
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, string>
     */
    public function allScriptContents(array $files): array
    {
        if ( array() !== $this->filesByPath ) {
            return $this->scriptContents;
        }

        $scripts = array();
        foreach ( $files as $file ) {
            if ( self::isMaterializedScriptAsset($file) && is_string($file['content'] ?? null) ) {
                $scripts[] = (string) $file['content'];
            }
        }

        return $scripts;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<string, mixed>|null
     */
    public function findAssetByHtmlReference(string $reference, string $entryPath, array $files): ?array
    {
        if ( '' === trim($reference) || preg_match('#^[a-z][a-z0-9+.-]*:#i', $reference) ) {
            return null;
        }

        $path = str_starts_with($reference, '/')
            ? ArtifactPath::artifactRootRelativePath($reference, $entryPath, array_fill_keys(array_column($files, 'path'), true))
            : ArtifactPath::resolveRelativePath($reference, $entryPath);
        if ( '' === $path ) {
            return null;
        }

        if ( isset($this->filesByPath[$path]) ) {
            return $this->filesByPath[$path];
        }

        foreach ( $files as $file ) {
            if ( ($file['path'] ?? '') === $path ) {
                return $file;
            }
        }

        return null;
    }
}
