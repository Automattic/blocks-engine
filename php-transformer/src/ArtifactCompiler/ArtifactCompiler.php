<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;

final class ArtifactCompiler
{
    private const INPUT_SCHEMA = 'block-artifact-compiler/website-artifact/v1';
    private const DEFAULT_MAX_FILES = 500;
    private const DEFAULT_MAX_FILE_BYTES = 1048576;
    private const DEFAULT_MAX_TOTAL_BYTES = 10485760;

    /**
     * @param array<string, mixed> $artifact
     */
    public function compile(array $artifact): TransformerResult
    {
        $normalized = $this->normalizeArtifact($artifact);
        $entry = $this->entryFile($normalized['files'], $normalized['entrypoints']);
        $diagnostics = $normalized['diagnostics'];

        if ( null === $entry ) {
            $diagnostics[] = $this->diagnostic('missing_entry_html', 'error', 'No HTML entry file was available to compile.');
        }

        $entryPath = is_array($entry) ? (string) $entry['path'] : '';
        $html = is_array($entry) ? (string) $entry['content'] : '';
        $assets = $this->assetManifest($normalized['files'], $entryPath);
        $components = $this->detectComponents($html);
        $blockTypes = $this->detectBlockTypes($normalized['files']);
        $serializedBlocks = '' === trim($html) ? '' : '<!-- wp:html -->' . "\n" . $html . "\n" . '<!-- /wp:html -->';
        $sourceReports = array(
            'artifact' => array(
                'schema'          => self::INPUT_SCHEMA,
                'original_schema' => is_string($artifact['schema'] ?? null) ? $artifact['schema'] : '',
                'entry_path'      => $entryPath,
                'entrypoints'     => $normalized['entrypoints'],
                'file_count'      => count($normalized['files']),
                'accepted_count'  => count($normalized['files']),
                'rejected_count'  => $normalized['rejected_count'],
                'bytes'           => $normalized['bytes'],
                'files_by_kind'   => $this->countBy($normalized['files'], 'kind'),
                'files_by_role'   => $this->countBy($normalized['files'], 'role'),
                'files_by_mime'   => $this->countBy($normalized['files'], 'mime_type'),
                'files_by_source' => $this->countBy($normalized['files'], 'source'),
                'files_by_intent' => $this->countBy($normalized['files'], 'intent'),
                'limits'          => array(
                    'max_files'       => self::DEFAULT_MAX_FILES,
                    'max_file_bytes'  => self::DEFAULT_MAX_FILE_BYTES,
                    'max_total_bytes' => self::DEFAULT_MAX_TOTAL_BYTES,
                ),
                'source_hash'     => hash('sha256', $normalized['hash_payload']),
                'html'            => array(
                    'bytes'         => strlen($html),
                    'element_count' => preg_match_all('/<\s*[a-z][a-z0-9:-]*(?:\s|>|\/)/i', $html),
                ),
            ),
        );

        return new TransformerResult(
            status: $this->statusFromDiagnostics($diagnostics),
            components: $components,
            blockTypes: $blockTypes,
            sourceReports: $sourceReports,
            legacyMapping: array(
                'block-artifact-compiler/result/v1' => array(
                    'status'                            => 'status',
                    'input'                             => 'source_reports.artifact',
                    'wordpress_artifacts.block_markup'  => 'serialized_blocks',
                    'wordpress_artifacts.blocks'        => 'blocks',
                    'wordpress_artifacts.block_types'   => 'block_types',
                    'wordpress_artifacts.components'    => 'components',
                    'wordpress_artifacts.files'         => 'assets',
                    'diagnostics'                       => 'diagnostics',
                    'provenance'                        => 'provenance',
                ),
            ),
            serializedBlocks: $serializedBlocks,
            assets: $assets,
            diagnostics: $diagnostics,
            provenance: array(
                array(
                    'source_format' => 'artifact',
                    'input_keys'    => array_keys($artifact),
                    'source_hash'   => hash('sha256', $normalized['hash_payload']),
                ),
            )
        );
    }

    /**
     * @param array<string, mixed> $artifact
     * @return array{files: array<int, array<string, mixed>>, diagnostics: array<int, array<string, mixed>>, rejected_count: int, bytes: int, entrypoints: array<int, string>, hash_payload: string}
     */
    private function normalizeArtifact(array $artifact): array
    {
        $diagnostics = array();
        $files = array();
        $entrypoints = array();
        $rejected = 0;
        $bytes = 0;
        $seenPaths = array();

        foreach ( array('entrypoint', 'entry', 'main') as $key ) {
            if ( is_string($artifact[$key] ?? null) ) {
                $entrypoints[] = $artifact[$key];
            }
        }
        if ( is_array($artifact['entrypoints'] ?? null) ) {
            foreach ( $artifact['entrypoints'] as $entrypoint ) {
                if ( is_string($entrypoint) ) {
                    $entrypoints[] = $entrypoint;
                }
            }
        }

        $rawFiles = $this->rawFiles($artifact);
        $safeEntrypoints = array();
        foreach ( array_unique($entrypoints) as $entrypoint ) {
            $path = $this->safeRelativePath($entrypoint);
            if ( '' === $path ) {
                $diagnostics[] = $this->diagnostic('unsafe_entrypoint_path', 'warning', 'An artifact entrypoint was ignored because its path is empty, absolute, or escapes the artifact root.', array('path' => $entrypoint));
                continue;
            }
            $safeEntrypoints[] = $path;
        }

        foreach ( $rawFiles as $index => $file ) {
            if ( count($files) >= self::DEFAULT_MAX_FILES ) {
                ++$rejected;
                $diagnostics[] = $this->diagnostic('file_limit_exceeded', 'warning', 'Additional artifact files were ignored because the file limit was reached.', array('max_files' => self::DEFAULT_MAX_FILES));
                break;
            }

            $path = $this->safeRelativePath((string) ($file['path'] ?? ''));
            if ( '' === $path ) {
                ++$rejected;
                $diagnostics[] = $this->diagnostic('unsafe_artifact_path', 'warning', 'An artifact file was ignored because its path is empty, absolute, or escapes the artifact root.', array('index' => $index));
                continue;
            }

            $payload = $this->payload($file, $path);
            $diagnostics = array_merge($diagnostics, $payload['diagnostics']);
            if ( ! $payload['accepted'] ) {
                ++$rejected;
                continue;
            }

            if ( $payload['bytes'] > self::DEFAULT_MAX_FILE_BYTES ) {
                ++$rejected;
                $diagnostics[] = $this->diagnostic('artifact_file_too_large', 'warning', 'An artifact file was ignored because it exceeds the per-file byte limit.', array('path' => $path, 'bytes' => $payload['bytes'], 'max_file_bytes' => self::DEFAULT_MAX_FILE_BYTES));
                continue;
            }

            if ( $bytes + $payload['bytes'] > self::DEFAULT_MAX_TOTAL_BYTES ) {
                ++$rejected;
                $diagnostics[] = $this->diagnostic('artifact_total_too_large', 'warning', 'An artifact file was ignored because the bundle byte limit was reached.', array('path' => $path, 'bytes' => $payload['bytes'], 'max_total_bytes' => self::DEFAULT_MAX_TOTAL_BYTES));
                continue;
            }

            $path = $this->dedupePath($path, $seenPaths);
            $seenPaths[$path] = true;
            $mimeType = $this->mimeType((string) ($file['mime_type'] ?? $file['mime'] ?? $file['media_type'] ?? (str_contains((string) ($file['type'] ?? ''), '/') ? $file['type'] : '')), $path);
            $kind = $this->kind((string) ($file['kind'] ?? $file['type'] ?? ''), $path, $payload['content'], $mimeType);
            $role = $this->role((string) ($file['role'] ?? ''), $kind, $mimeType, $path);
            $intent = $this->intent((string) ($file['intent'] ?? ''), $kind, $role);
            $binary = $payload['binary'] || $this->isBinaryMimeType($mimeType);
            $contentBase64 = $payload['content_base64'];
            if ( $binary && '' === $contentBase64 ) {
                $contentBase64 = base64_encode($payload['content']);
            }
            $entrypoint = in_array($path, $safeEntrypoints, true) || ! empty($file['entrypoint']) || 'entry' === $role;
            if ( $entrypoint && ! in_array($path, $safeEntrypoints, true) ) {
                $safeEntrypoints[] = $path;
            }

            $normalized = array(
                'path'       => $path,
                'content'    => $payload['content'],
                'kind'       => $kind,
                'bytes'      => $payload['bytes'],
                'source'     => (string) ($file['source'] ?? 'artifact'),
                'mime_type'  => $mimeType,
                'role'       => $role,
                'encoding'   => $payload['encoding'],
                'binary'     => $binary,
                'entrypoint' => $entrypoint,
                'provenance' => array(
                    'source_path' => $path,
                    'source'      => (string) ($file['source'] ?? 'artifact'),
                    'hash'        => hash('sha256', '' !== $contentBase64 ? $contentBase64 : $payload['content']),
                ),
            );
            if ( '' !== $contentBase64 ) {
                $normalized['content_base64'] = $contentBase64;
            }
            if ( '' !== $intent ) {
                $normalized['intent'] = $intent;
            }

            $bytes += $normalized['bytes'];
            $files[] = $normalized;
        }

        return array(
            'files'          => $files,
            'diagnostics'    => $this->dedupeDiagnostics($diagnostics),
            'rejected_count' => $rejected,
            'bytes'          => $bytes,
            'entrypoints'    => array_values(array_unique($safeEntrypoints)),
            'hash_payload'   => $this->fileHashPayload($files),
        );
    }

    /**
     * @param array<string, mixed> $artifact
     * @return array<int, array<string, mixed>>
     */
    private function rawFiles(array $artifact): array
    {
        $files = array();
        foreach ( array('files', 'artifacts', 'outputs') as $key ) {
            if ( ! is_array($artifact[$key] ?? null) ) {
                continue;
            }
            foreach ( $artifact[$key] as $path => $file ) {
                if ( is_array($file) ) {
                    $pathSource = $file['path'] ?? $file['name'] ?? $path;
                    $file['path'] = is_scalar($pathSource) ? (string) $pathSource : '';
                    $file['source'] = is_scalar($file['source'] ?? null) ? (string) $file['source'] : $key;
                    $files[] = $file;
                    continue;
                }
                if ( is_string($file) ) {
                    $files[] = array(
                        'path'    => is_string($path) ? $path : 'artifact-' . $path . '.html',
                        'content' => $file,
                        'kind'    => '',
                        'source'  => $key,
                    );
                }
            }
        }
        foreach ( array('html', 'generated_html', 'content', 'body') as $key ) {
            if ( is_string($artifact[$key] ?? null) && '' !== trim($artifact[$key]) ) {
                $files[] = array(
                    'path'    => 'index.html',
                    'content' => $artifact[$key],
                    'kind'    => 'html',
                    'source'  => $key,
                );
            }
        }
        foreach ( array(
            'css'        => 'style.css',
            'styles'     => 'style.css',
            'javascript' => 'site.js',
            'js'         => 'site.js',
            'script'     => 'site.js',
        ) as $key => $path ) {
            if ( is_string($artifact[$key] ?? null) && '' !== trim($artifact[$key]) ) {
                $files[] = array(
                    'path'    => $path,
                    'content' => $artifact[$key],
                    'kind'    => str_contains($path, '.css') ? 'css' : 'js',
                    'source'  => $key,
                );
            }
        }

        return $files;
    }

    /**
     * @param array<string, mixed> $file
     * @return array{accepted: bool, content: string, content_base64: string, encoding: string, binary: bool, bytes: int, diagnostics: array<int, array<string, mixed>>}
     */
    private function payload(array $file, string $path): array
    {
        if ( is_string($file['content_base64'] ?? null) ) {
            $base64 = preg_replace('/\s+/', '', $file['content_base64']) ?? '';
            $decoded = base64_decode($base64, true);
            if ( false === $decoded ) {
                return array('accepted' => false, 'content' => '', 'content_base64' => '', 'encoding' => 'base64', 'binary' => false, 'bytes' => 0, 'diagnostics' => array($this->diagnostic('invalid_base64_content', 'warning', 'An artifact file was ignored because content_base64 is not valid base64.', array('path' => $path))));
            }

            $binary = $this->looksBinary($decoded);
            $diagnostics = array();
            if ( ! $binary && is_string($file['content'] ?? null) && '' !== $file['content'] && $file['content'] !== $decoded ) {
                $diagnostics[] = $this->diagnostic('content_base64_preferred', 'info', 'Both content and content_base64 were provided; decoded content_base64 was used as the canonical payload.', array('path' => $path));
            }

            return array('accepted' => true, 'content' => $binary ? '' : $decoded, 'content_base64' => $base64, 'encoding' => 'base64', 'binary' => $binary, 'bytes' => strlen($decoded), 'diagnostics' => $diagnostics);
        }

        $content = $this->normalizeContent($file['content'] ?? $file['body'] ?? $file['text'] ?? '');
        return array('accepted' => true, 'content' => $content, 'content_base64' => '', 'encoding' => 'text', 'binary' => false, 'bytes' => strlen($content), 'diagnostics' => array());
    }

    private function safeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ( '' === $path || str_starts_with($path, '/') || preg_match('#^[A-Za-z]:/#', $path) ) {
            return '';
        }
        $parts = array();
        foreach ( explode('/', $path) as $part ) {
            if ( '' === $part || '.' === $part ) {
                continue;
            }
            if ( '..' === $part ) {
                return '';
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @param array<int, string> $entrypoints
     * @return array<string, mixed>|null
     */
    private function entryFile(array $files, array $entrypoints): ?array
    {
        foreach ( $entrypoints as $entrypoint ) {
            foreach ( $files as $file ) {
                if ( $entrypoint === $file['path'] && 'html' === $file['kind'] && empty($file['binary']) ) {
                    return $file;
                }
            }
        }
        foreach ( array('index.html', 'index.htm', 'static-site/index.html', 'public/index.html') as $preferred ) {
            foreach ( $files as $file ) {
                if ( $preferred === strtolower((string) $file['path']) && empty($file['binary']) ) {
                    return $file;
                }
            }
        }
        foreach ( $files as $file ) {
            if ( 'html' === $file['kind'] && empty($file['binary']) ) {
                return $file;
            }
        }

        return null;
    }

    private function kind(string $kind, string $path, string $content, string $mimeType): string
    {
        $kind = $this->sanitizeKey($kind);
        if ( in_array($kind, array('html', 'css', 'js', 'jsx', 'tsx', 'json', 'markdown', 'mdx', 'asset', 'blocks'), true) ) {
            return $kind;
        }
        if ( str_contains($mimeType, '/') ) {
            if ( str_contains($mimeType, 'html') ) {
                return 'html';
            }
            if ( 'text/css' === $mimeType ) {
                return 'css';
            }
            if ( in_array($mimeType, array('application/javascript', 'text/javascript', 'application/ecmascript', 'text/ecmascript'), true) ) {
                return 'js';
            }
            if ( 'application/json' === $mimeType ) {
                return 'json';
            }
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($extension) {
            'html', 'htm' => 'html',
            'css' => 'css',
            'js', 'mjs' => 'js',
            'jsx' => 'jsx',
            'tsx' => 'tsx',
            'json' => 'json',
            'md', 'markdown' => 'markdown',
            'mdx' => 'mdx',
            default => str_contains($content, '<!-- wp:') ? 'blocks' : 'asset',
        };
    }

    private function mimeType(string $mimeType, string $path): string
    {
        $mimeType = strtolower(trim($mimeType));
        if ( preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $mimeType) ) {
            return $mimeType;
        }
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'html', 'htm' => 'text/html',
            'css' => 'text/css',
            'js', 'mjs' => 'application/javascript',
            'jsx' => 'text/jsx',
            'tsx' => 'text/tsx',
            'json' => 'application/json',
            'md', 'markdown' => 'text/markdown',
            'mdx' => 'text/mdx',
            'txt' => 'text/plain',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            default => 'application/octet-stream',
        };
    }

    private function isBinaryMimeType(string $mimeType): bool
    {
        return ! str_starts_with($mimeType, 'text/') && ! in_array($mimeType, array('application/json', 'application/javascript', 'image/svg+xml'), true);
    }

    private function role(string $role, string $kind, string $mimeType, string $path): string
    {
        $role = $this->sanitizeKey($role);
        if ( '' !== $role ) {
            return $role;
        }
        if ( 'html' === $kind ) {
            return preg_match('#(^|/)index\.html?$#i', $path) ? 'entry' : 'document';
        }
        if ( 'css' === $kind ) {
            return 'stylesheet';
        }
        if ( 'js' === $kind ) {
            return 'script';
        }
        if ( str_starts_with($mimeType, 'image/') ) {
            return 'image';
        }
        if ( str_starts_with($mimeType, 'font/') ) {
            return 'font';
        }
        if ( in_array($kind, array('json', 'markdown'), true) ) {
            return 'data';
        }

        return 'asset';
    }

    private function intent(string $intent, string $kind, string $role): string
    {
        $intent = $this->sanitizeKey($intent);
        if ( '' !== $intent ) {
            return $intent;
        }
        if ( 'css' === $kind || 'stylesheet' === $role ) {
            return 'style';
        }
        if ( 'js' === $kind || 'script' === $role ) {
            return 'behavior';
        }

        return '';
    }

    private function looksBinary(string $content): bool
    {
        return str_contains($content, "\0");
    }

    private function normalizeContent(mixed $content): string
    {
        if ( is_string($content) ) {
            return str_replace("\r\n", "\n", str_replace("\r", "\n", $content));
        }
        if ( is_scalar($content) ) {
            return (string) $content;
        }

        return '';
    }

    /**
     * @param array<string, bool> $seen
     */
    private function dedupePath(string $path, array $seen): string
    {
        if ( ! isset($seen[$path]) ) {
            return $path;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $base = '' === $extension ? $path : substr($path, 0, -1 - strlen($extension));
        $suffix = '' === $extension ? '' : '.' . $extension;
        $index = 2;
        while ( isset($seen[$base . '-' . $index . $suffix]) ) {
            ++$index;
        }

        return $base . '-' . $index . $suffix;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     */
    private function fileHashPayload(array $files): string
    {
        $payload = '';
        foreach ( $files as $file ) {
            $content = isset($file['content_base64']) ? (string) $file['content_base64'] : (string) $file['content'];
            $payload .= $file['path'] . "\0" . $file['kind'] . "\0" . ($file['mime_type'] ?? '') . "\0" . $content . "\0";
        }

        return $payload;
    }

    private function sanitizeKey(string $key): string
    {
        return strtolower(preg_replace('/[^a-z0-9_-]+/', '-', trim($key)) ?? '');
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function assetManifest(array $files, string $entryPath): array
    {
        $assets = array();
        foreach ( $files as $file ) {
            if ( $entryPath === $file['path'] ) {
                continue;
            }
            $asset = array(
                'path'       => $file['path'],
                'kind'       => $file['kind'],
                'bytes'      => $file['bytes'],
                'source'     => $file['source'] ?? 'artifact',
                'mime_type'  => $file['mime_type'],
                'role'       => $file['role'],
                'encoding'   => $file['encoding'],
                'binary'     => $file['binary'],
                'provenance' => $file['provenance'],
            );
            if ( ! empty($file['content_base64']) ) {
                $asset['content_base64'] = $file['content_base64'];
            }
            if ( ! empty($file['intent']) ) {
                $asset['intent'] = $file['intent'];
            }
            $assets[] = $asset;
        }

        return $assets;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function detectComponents(string $html): array
    {
        if ( ! preg_match_all('/data-component=["\']([^"\']+)["\']/i', $html, $matches) ) {
            return array();
        }

        return array_map(
            static fn (string $name): array => array('name' => $name, 'signal' => 'data-component'),
            array_values(array_unique($matches[1]))
        );
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function detectBlockTypes(array $files): array
    {
        $blockTypes = array();
        foreach ( $files as $file ) {
            if ( 'block.json' !== basename((string) $file['path']) ) {
                continue;
            }
            $metadata = json_decode((string) $file['content'], true);
            if ( ! is_array($metadata) ) {
                continue;
            }
            $directory = dirname((string) $file['path']);
            $blockTypes[] = array(
                'schema'          => 'chubes4/wordpress-block-type-artifact/v1',
                'name'            => is_string($metadata['name'] ?? null) ? $metadata['name'] : '',
                'directory'       => '.' === $directory ? '' : $directory,
                'block_json_path' => $file['path'],
                'metadata'        => $metadata,
                'provenance'      => array(
                    'source_hash' => $file['provenance']['hash'],
                    'files'       => array($file['path']),
                ),
            );
        }

        return $blockTypes;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<string, int>
     */
    private function countBy(array $files, string $field): array
    {
        $counts = array();
        foreach ( $files as $file ) {
            $value = (string) ($file[$field] ?? '');
            if ( '' === $value ) {
                continue;
            }
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    private function dedupeDiagnostics(array $diagnostics): array
    {
        $seen = array();
        $deduped = array();
        foreach ( $diagnostics as $diagnostic ) {
            $key = json_encode($diagnostic, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: serialize($diagnostic);
            if ( isset($seen[$key]) ) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $diagnostic;
        }

        return $deduped;
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     */
    private function statusFromDiagnostics(array $diagnostics): string
    {
        foreach ( $diagnostics as $diagnostic ) {
            if ( 'error' === ($diagnostic['severity'] ?? '') ) {
                return 'failed';
            }
        }
        return array() === $diagnostics ? 'success' : 'success_with_warnings';
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function diagnostic(string $code, string $severity, string $message, array $context = array()): array
    {
        return array_filter(
            array(
                'code'     => $code,
                'severity' => $severity,
                'message'  => $message,
                'source'   => self::class,
                'context'  => $context,
            ),
            static fn (mixed $value): bool => array() !== $value
        );
    }
}
