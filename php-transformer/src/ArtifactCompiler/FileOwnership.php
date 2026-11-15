<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

/**
 * Which compilation scope owns an artifact file: a page (by id) or the shared
 * plan. Documents own themselves, inline styles and scripts follow the page they
 * were expanded from, and explicit `metadata.compilation` ownership is validated.
 */
final class FileOwnership
{
    /** @param array<string,mixed> $file @return array{scope:string,id:string} */
    public static function of(array $file): array
    {
        $ownership = $file['metadata']['compilation'] ?? null;
        if (null === $ownership) {
            if (in_array(($file['kind'] ?? null), array('html', 'markdown', 'mdx'), true)) {
                return array('scope' => 'page', 'id' => (string) $file['path']);
            }
            // Inline styles/scripts expanded out of an unannotated page must
            // follow that page: parking page-varying content in the immutable
            // shared plan would invalidate every page plan on a page edit.
            $inlineOwnership = ArtifactNormalizer::inlineExpansionOwnership($file);
            if (null !== $inlineOwnership) {
                return $inlineOwnership + array('id' => '');
            }
            if (isset($file['source_path']) && in_array($file['source'] ?? '', array('inline-style', 'inline-script'), true)) return array('scope' => 'page', 'id' => $file['source_path']);
            return array('scope' => 'shared', 'id' => '');
        }
        if (!is_array($ownership) || !is_string($ownership['scope'] ?? null) || !in_array($ownership['scope'], array('shared', 'page'), true)) {
            throw new \InvalidArgumentException('File compilation ownership requires a shared or page scope.');
        }
        if ('shared' === $ownership['scope']) {
            if (isset($ownership['id'])) {
                throw new \InvalidArgumentException('Shared file compilation ownership cannot declare a page id.');
            }
            return array('scope' => 'shared', 'id' => '');
        }
        if (!is_string($ownership['id'] ?? null) || '' === trim($ownership['id']) || strlen($ownership['id']) > 255) {
            throw new \InvalidArgumentException('Page file compilation ownership requires a bounded nonblank page id.');
        }
        return array('scope' => 'page', 'id' => $ownership['id']);
    }

    /**
     * Linked CSS files are shared artifact assets. Their class-bound rules must
     * keep addressing authored classes across every consuming document.
     *
     * @param array<int, array<string, mixed>> $files
     * @return list<string>
     */
    public static function sharedStylesheetPaths(array $files): array
    {
        $paths = array();
        foreach ( $files as $file ) {
            if ( 'css' !== ($file['kind'] ?? '') || ! is_string($file['path'] ?? null) || 'shared' !== self::of($file)['scope'] ) {
                continue;
            }
            $paths[$file['path']] = true;
        }

        return array_keys($paths);
    }

    /**
     * Remove everything a dropped page owns: its document and the page-scoped
     * files expanded out of it. Shared files stay.
     *
     * @param array<int,array<string,mixed>> $files
     * @param array<int,string> $omittedPaths
     * @return array<int,array<string,mixed>>
     */
    public static function withoutOmittedPages(array $files, array $omittedPaths): array
    {
        $omitted = array_flip($omittedPaths);
        return array_values(array_filter($files, function (array $file) use ($omitted): bool {
            if (isset($omitted[(string) ($file['path'] ?? '')])) return false;
            $ownership = self::of($file);
            return !('page' === $ownership['scope'] && isset($omitted[$ownership['id']]));
        }));
    }
}
