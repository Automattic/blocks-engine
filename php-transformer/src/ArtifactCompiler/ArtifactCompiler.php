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
        $documents = $this->compileSourceDocuments($normalized);
        $diagnostics = array_merge($normalized['diagnostics'], $documents['diagnostics']);

        if ( null === $entry && array() === $documents['documents'] ) {
            $diagnostics[] = $this->diagnostic('missing_entry_html', 'error', 'No HTML entry file was available to compile.');
        }

        $entryPath = is_array($entry) ? (string) $entry['path'] : '';
        $html = is_array($entry) ? (string) $entry['content'] : '';
        $assets = $this->assetManifest($normalized['files'], $entryPath);
        $components = $this->detectComponents($normalized['files'], $entryPath, $documents['components']);
        $blockTypes = $this->detectBlockTypes($normalized['files'], $diagnostics);
        $serializedBlocks = '' === trim($html) ? '' : '<!-- wp:html -->' . "\n" . $html . "\n" . '<!-- /wp:html -->';
        if ( '' === $serializedBlocks && ! empty($documents['documents'][0]['block_markup']) ) {
            $serializedBlocks = (string) $documents['documents'][0]['block_markup'];
        }
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
            documents: $documents['documents'],
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

            if ( 'mdx' === $kind ) {
                $diagnostics[] = $this->diagnostic('mdx_source_document_detected', 'warning', 'MDX source document support is partial; the source was preserved and inspectable document/component metadata was extracted.', array('path' => $path));
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
     * @param array{files: array<int, array<string, mixed>>} $artifact
     * @return array{documents: array<int, array<string, mixed>>, components: array<int, array<string, mixed>>, diagnostics: array<int, array<string, mixed>>}
     */
    private function compileSourceDocuments(array $artifact): array
    {
        $documents = array();
        $components = array();
        $diagnostics = array();

        foreach ( $artifact['files'] as $file ) {
            if ( ! in_array($file['kind'], array('markdown', 'mdx'), true) || ! empty($file['binary']) ) {
                continue;
            }

            $parsed = $this->parseFrontmatter((string) $file['content']);
            $body = $parsed['body'];
            $frontmatter = $parsed['frontmatter'];
            $documentDiagnostics = array();

            if ( 'mdx' === $file['kind'] ) {
                $mdx = $this->extractMdxSemantics($body, $file, $artifact);
                $body = $mdx['markdown_body'];
                $components = array_merge($components, $mdx['components']);
                $documentDiagnostics = array_merge($documentDiagnostics, $mdx['diagnostics']);
            }

            $conversion = $this->convertMarkdownToBlocks($body);
            $documentDiagnostics = array_merge($documentDiagnostics, $conversion['diagnostics']);
            $diagnostics = array_merge($diagnostics, $documentDiagnostics);

            $documents[] = array(
                'source_path'  => $file['path'],
                'kind'         => $file['kind'],
                'post_type'    => $this->frontmatterString($frontmatter, array('post_type', 'type'), 'page'),
                'slug'         => $this->frontmatterString($frontmatter, array('slug'), $this->slugFromPath((string) $file['path'])),
                'title'        => $this->frontmatterString($frontmatter, array('title'), $this->titleFromPath((string) $file['path'])),
                'excerpt'      => $this->frontmatterString($frontmatter, array('excerpt', 'description'), ''),
                'date'         => $this->frontmatterString($frontmatter, array('date', 'published', 'published_at'), ''),
                'template'     => $this->frontmatterString($frontmatter, array('template', 'layout'), ''),
                'taxonomies'   => $this->frontmatterTaxonomies($frontmatter),
                'frontmatter'  => $frontmatter,
                'body'         => $body,
                'body_format'  => 'mdx' === $file['kind'] ? 'mdx' : 'markdown',
                'block_markup' => $conversion['serialized_blocks'],
                'diagnostics'  => $documentDiagnostics,
                'provenance'   => $file['provenance'],
            );
        }

        return array(
            'documents'   => $documents,
            'components'  => $components,
            'diagnostics' => $this->dedupeDiagnostics($diagnostics),
        );
    }

    /**
     * @return array{serialized_blocks: string, diagnostics: array<int, array<string, mixed>>}
     */
    private function convertMarkdownToBlocks(string $markdown): array
    {
        if ( function_exists('bfb_convert') ) {
            $blockMarkup = (string) bfb_convert($markdown, 'markdown', 'blocks');
            return array(
                'serialized_blocks' => $blockMarkup,
                'diagnostics'       => array(),
            );
        }

        return array(
            'serialized_blocks' => '<!-- wp:html -->' . "\n" . $markdown . "\n" . '<!-- /wp:html -->',
            'diagnostics'       => array(
                $this->diagnostic('markdown_adapter_unavailable', 'warning', 'A Markdown adapter is unavailable; preserved source Markdown as a core/html fallback.'),
            ),
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
        return preg_replace('/[^a-z0-9_-]+/', '-', strtolower(trim($key))) ?? '';
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
     * @param array<int, array<string, mixed>> $sourceDocumentComponents
     * @return array<int, array<string, mixed>>
     */
    private function detectComponents(array $files, string $entryPath, array $sourceDocumentComponents = array()): array
    {
        $components = array();
        $classes = array();
        foreach ( $sourceDocumentComponents as $component ) {
            $key = 'mdx:' . (string) ($component['source'] ?? '') . ':' . (string) ($component['name'] ?? '');
            $components[$key] = $component;
        }

        foreach ( $files as $file ) {
            if ( in_array($file['kind'], array('jsx', 'tsx'), true) && empty($file['binary']) ) {
                foreach ( $this->detectJsxFileComponents($file) as $component ) {
                    $components['jsx-file:' . (string) $component['source'] . ':' . (string) $component['name']] = $component;
                }
            }

            if ( 'html' !== $file['kind'] || ! empty($file['binary']) ) {
                continue;
            }

            $content = (string) $file['content'];
            if ( preg_match_all('/data-component\s*=\s*(["\'])([^"\']+)\1/i', $content, $matches) ) {
                foreach ( $matches[2] as $name ) {
                    $key = $this->sanitizeKey($name);
                    if ( '' === $key ) {
                        continue;
                    }
                    $components['explicit:' . $key] = array(
                        'name'        => $key,
                        'source'      => $file['path'],
                        'signal'      => 'data-component',
                        'occurrences' => ($components['explicit:' . $key]['occurrences'] ?? 0) + 1,
                        'provenance'  => array('source_path' => $file['path']),
                    );
                }
            }

            if ( preg_match_all('/class\s*=\s*(["\'])([^"\']+)\1/i', $content, $matches) ) {
                foreach ( $matches[2] as $classList ) {
                    $classTokens = preg_split('/\s+/', trim($classList));
                    foreach ( false === $classTokens ? array() : $classTokens as $class ) {
                        $class = $this->sanitizeKey($class);
                        if ( '' === $class || strlen($class) < 3 ) {
                            continue;
                        }
                        $classes[$class] = ($classes[$class] ?? 0) + 1;
                    }
                }
            }
        }

        foreach ( $classes as $class => $count ) {
            if ( $count < 2 && ! preg_match('/(?:card|grid|hero|nav|header|footer|feature|testimonial|pricing|product|gallery|section)/', $class) ) {
                continue;
            }

            $components['class:' . $class] = array(
                'name'        => $class,
                'source'      => $entryPath,
                'signal'      => 'class-token',
                'occurrences' => $count,
                'provenance'  => array('source_path' => $entryPath),
            );
        }

        usort(
            $components,
            static function (array $left, array $right): int {
                $occurrenceComparison = ($right['occurrences'] ?? 1) <=> ($left['occurrences'] ?? 1);
                return 0 !== $occurrenceComparison ? $occurrenceComparison : strcmp((string) $left['name'], (string) $right['name']);
            }
        );

        return array_slice($components, 0, 25);
    }

    /**
     * @param array<string, mixed> $file
     * @return array<int, array<string, mixed>>
     */
    private function detectJsxFileComponents(array $file): array
    {
        $components = array();
        $content = (string) ($file['content'] ?? '');

        if ( preg_match_all('/(?:export\s+default\s+)?function\s+([A-Z][A-Za-z0-9_]*)\s*\(/', $content, $matches) ) {
            foreach ( $matches[1] as $name ) {
                $components[$name] = true;
            }
        }

        if ( preg_match_all('/(?:export\s+)?(?:const|let|var)\s+([A-Z][A-Za-z0-9_]*)\s*=\s*(?:\([^)]*\)|[A-Za-z0-9_]+)\s*=>/', $content, $matches) ) {
            foreach ( $matches[1] as $name ) {
                $components[$name] = true;
            }
        }

        return array_map(
            fn (string $name): array => array(
                'name'        => $name,
                'source'      => (string) ($file['path'] ?? ''),
                'signal'      => 'jsx-component-file',
                'occurrences' => 1,
                'provenance'  => array('source_path' => (string) ($file['path'] ?? '')),
            ),
            array_keys($components)
        );
    }

    /**
     * @return array{frontmatter: array<string, mixed>, body: string}
     */
    private function parseFrontmatter(string $content): array
    {
        if ( ! preg_match('/\A---\s*\R(.*?)\R---\s*\R?/s', $content, $matches) ) {
            return array(
                'frontmatter' => array(),
                'body'        => $content,
            );
        }

        $frontmatter = array();
        $lines = preg_split('/\R/', trim($matches[1]));
        foreach ( false === $lines ? array() : $lines as $line ) {
            if ( ! preg_match('/^([A-Za-z0-9_-]+)\s*:\s*(.*)$/', $line, $pair) ) {
                continue;
            }

            $value = trim($pair[2], " \t\n\r\0\x0B\"'");
            if ( preg_match('/^\[(.*)\]$/', $value, $list) ) {
                $value = array_values(array_filter(array_map(static fn (string $item): string => trim($item, " \t\n\r\0\x0B\"'"), explode(',', $list[1])), static fn (string $item): bool => '' !== $item));
            }

            $frontmatter[$this->sanitizeKey($pair[1])] = $value;
        }

        return array(
            'frontmatter' => $frontmatter,
            'body'        => substr($content, strlen($matches[0])),
        );
    }

    /**
     * @param array<string, mixed> $file
     * @param array{files: array<int, array<string, mixed>>} $artifact
     * @return array{markdown_body: string, components: array<int, array<string, mixed>>, diagnostics: array<int, array<string, mixed>>}
     */
    private function extractMdxSemantics(string $body, array $file, array $artifact): array
    {
        $imports = $this->extractMdxImports($body);
        $components = array();
        $diagnostics = array();
        $sourcePath = (string) $file['path'];

        if ( preg_match_all('/<([A-Z][A-Za-z0-9._-]*)(?:\s[^>]*)?\s*(?:>|\/>)/', $body, $matches) ) {
            foreach ( $matches[1] as $name ) {
                $import = $imports[$name] ?? null;
                $resolved = is_array($import) ? $this->resolveComponentImport((string) $import['path'], $sourcePath, $artifact) : '';
                $component = array(
                    'name'        => $name,
                    'source'      => $sourcePath,
                    'signal'      => 'mdx-jsx',
                    'occurrences' => ($components[$name]['occurrences'] ?? 0) + 1,
                    'provenance'  => array('source_path' => $sourcePath),
                );

                if ( is_array($import) ) {
                    $component['import_path'] = $import['path'];
                }
                if ( '' !== $resolved ) {
                    $component['resolved_path'] = $resolved;
                }

                $components[$name] = $component;

                if ( ! is_array($import) ) {
                    $diagnostics[] = $this->diagnostic('mdx_component_unresolved', 'warning', 'MDX component reference has no matching import.', array('path' => $sourcePath, 'component' => $name));
                } elseif ( '' === $resolved && str_starts_with((string) $import['path'], '.') ) {
                    $diagnostics[] = $this->diagnostic('mdx_import_unresolved', 'warning', 'MDX component import could not be linked to a generated source file.', array('path' => $sourcePath, 'component' => $name, 'import_path' => $import['path']));
                }
            }
        }

        $markdownBody = preg_replace('/^\s*import\s+[^;\r\n]+;?\s*$/m', '', $body) ?? $body;
        $markdownBody = preg_replace('/^\s*export\s+[^\r\n]+\s*$/m', '', $markdownBody) ?? $markdownBody;
        $markdownBody = preg_replace('/<([A-Z][A-Za-z0-9._-]*)(?:\s[^>]*)?\s*\/>/', '', $markdownBody) ?? $markdownBody;
        $markdownBody = preg_replace('/<\/?[A-Z][A-Za-z0-9._-]*(?:\s[^>]*)?>/', '', $markdownBody) ?? $markdownBody;

        return array(
            'markdown_body' => trim($markdownBody),
            'components'    => array_values($components),
            'diagnostics'   => $this->dedupeDiagnostics($diagnostics),
        );
    }

    /**
     * @return array<string, array{path: string}>
     */
    private function extractMdxImports(string $body): array
    {
        $imports = array();
        if ( ! preg_match_all('/^\s*import\s+(.+?)\s+from\s+["\']([^"\']+)["\'];?\s*$/m', $body, $matches, PREG_SET_ORDER) ) {
            return $imports;
        }

        foreach ( $matches as $match ) {
            $clause = trim($match[1]);
            $path = $match[2];
            if ( preg_match('/^([A-Z][A-Za-z0-9_]*)/', $clause, $default) ) {
                $imports[$default[1]] = array('path' => $path);
            }
            if ( preg_match('/\{([^}]+)\}/', $clause, $named) ) {
                foreach ( explode(',', $named[1]) as $name ) {
                    $parts = preg_split('/\s+as\s+/i', trim($name));
                    $alias = trim((string) end($parts));
                    if ( preg_match('/^[A-Z][A-Za-z0-9_]*$/', $alias) ) {
                        $imports[$alias] = array('path' => $path);
                    }
                }
            }
        }

        return $imports;
    }

    /**
     * @param array{files: array<int, array<string, mixed>>} $artifact
     */
    private function resolveComponentImport(string $importPath, string $sourcePath, array $artifact): string
    {
        if ( ! str_starts_with($importPath, '.') ) {
            return '';
        }

        $base = dirname($sourcePath);
        $path = $this->normalizeRelativeImportPath(('.' === $base ? '' : $base . '/') . $importPath);
        if ( '' === $path ) {
            return '';
        }

        $candidates = array($path);
        foreach ( array('js', 'jsx', 'ts', 'tsx', 'mdx') as $extension ) {
            $candidates[] = $path . '.' . $extension;
            $candidates[] = $path . '/index.' . $extension;
        }

        foreach ( $artifact['files'] as $file ) {
            if ( in_array($file['path'], $candidates, true) ) {
                return (string) $file['path'];
            }
        }

        return '';
    }

    private function normalizeRelativeImportPath(string $path): string
    {
        $segments = array();
        foreach ( explode('/', str_replace('\\', '/', $path)) as $segment ) {
            if ( '' === $segment || '.' === $segment ) {
                continue;
            }
            if ( '..' === $segment ) {
                array_pop($segments);
                continue;
            }
            $segments[] = preg_replace('/[^A-Za-z0-9._-]/', '-', $segment);
        }

        return implode('/', array_filter($segments));
    }

    /**
     * @param array<string, mixed> $frontmatter
     * @param array<int, string> $keys
     */
    private function frontmatterString(array $frontmatter, array $keys, string $fallback): string
    {
        foreach ( $keys as $key ) {
            if ( isset($frontmatter[$key]) && is_scalar($frontmatter[$key]) && '' !== trim((string) $frontmatter[$key]) ) {
                return (string) $frontmatter[$key];
            }
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $frontmatter
     * @return array<string, mixed>
     */
    private function frontmatterTaxonomies(array $frontmatter): array
    {
        $taxonomies = array();
        foreach ( array('category', 'categories', 'tag', 'tags') as $key ) {
            if ( isset($frontmatter[$key]) ) {
                $taxonomies[$key] = $frontmatter[$key];
            }
        }

        return $taxonomies;
    }

    private function slugFromPath(string $path): string
    {
        $base = preg_replace('/\.[A-Za-z0-9]+$/', '', basename($path));
        $base = '' === $base || null === $base ? 'document' : $base;
        return $this->sanitizeKey(str_replace(array('_', '.'), '-', $base));
    }

    private function titleFromPath(string $path): string
    {
        return ucwords(str_replace('-', ' ', $this->slugFromPath($path)));
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function detectBlockTypes(array $files, array &$diagnostics): array
    {
        $blockTypes = array();
        $blockRoots = array();

        foreach ( $files as $file ) {
            if ( 'block.json' !== basename((string) $file['path']) ) {
                continue;
            }
            $directory = dirname((string) $file['path']);
            $directory = '.' === $directory ? '' : $directory;
            $blockRoots[$directory] = $file;
        }

        foreach ( $blockRoots as $directory => $blockJsonFile ) {
            $blockJson = json_decode((string) $blockJsonFile['content'], true);
            if ( ! is_array($blockJson) ) {
                $blockJson = array();
                $diagnostics[] = $this->diagnostic('invalid_block_json', 'warning', 'A generated block.json file could not be decoded.', array('path' => $blockJsonFile['path']));
            }

            $name = isset($blockJson['name']) && is_string($blockJson['name']) ? trim($blockJson['name']) : '';
            if ( '' === $name ) {
                $name = 'generated/' . ('' === $directory ? 'block' : $this->sanitizeKey(basename($directory)));
                $diagnostics[] = $this->diagnostic('block_json_missing_name', 'warning', 'A generated block.json file did not declare a name; a stable generated name was assigned.', array('path' => $blockJsonFile['path'], 'name' => $name));
            }

            $blockFiles = $this->filesUnderDirectory($files, $directory);
            $blockTypes[] = array(
                'schema'          => 'chubes4/wordpress-block-type-artifact/v1',
                'name'            => $name,
                'slug'            => $this->sanitizeKey(basename($name)),
                'directory'       => $directory,
                'block_json_path' => $blockJsonFile['path'],
                'block_json'      => $blockJson,
                'metadata'        => $this->blockMetadataContract($blockJson),
                'assets'          => $this->blockAssetContract($blockJson, $blockFiles),
                'dependencies'    => $this->blockDependencyContract($blockJson, $blockFiles),
                'provenance'      => array(
                    'source'      => $blockJsonFile['source'] ?? 'artifact',
                    'source_hash' => hash('sha256', $this->fileHashPayload($blockFiles)),
                    'files'       => array_values(array_map(static fn (array $file): string => (string) $file['path'], $blockFiles)),
                ),
                'files'           => array_values(
                    array_map(
                        static fn (array $file): array => array(
                            'path'  => $file['path'],
                            'kind'  => $file['kind'],
                            'bytes' => $file['bytes'],
                        ),
                        $blockFiles
                    )
                ),
            );
        }

        usort(
            $blockTypes,
            static fn (array $left, array $right): int => strcmp((string) $left['name'], (string) $right['name'])
        );

        return $blockTypes;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function filesUnderDirectory(array $files, string $directory): array
    {
        $matched = array();
        $prefix = '' === $directory ? '' : $directory . '/';
        foreach ( $files as $file ) {
            if ( '' === $prefix || str_starts_with((string) $file['path'], $prefix) ) {
                $matched[] = $file;
            }
        }

        return $matched;
    }

    /**
     * @param array<string, mixed> $blockJson
     * @return array<string, mixed>
     */
    private function blockMetadataContract(array $blockJson): array
    {
        $metadata = array();
        foreach ( array('apiVersion', 'title', 'category', 'description', 'keywords', 'attributes', 'supports', 'usesContext', 'providesContext', 'textdomain', 'example', 'variations', 'parent', 'ancestor', 'allowedBlocks') as $key ) {
            if ( array_key_exists($key, $blockJson) ) {
                $metadata[$key] = $blockJson[$key];
            }
        }

        return $metadata;
    }

    /**
     * @param array<string, mixed> $blockJson
     * @param array<int, array<string, mixed>> $files
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function blockAssetContract(array $blockJson, array $files): array
    {
        $assets = array(
            'render'        => array(),
            'editor_script' => array(),
            'script'        => array(),
            'view_script'   => array(),
            'editor_style'  => array(),
            'style'         => array(),
            'view_style'    => array(),
        );

        foreach ( array(
            'render'       => 'render',
            'editorScript' => 'editor_script',
            'script'       => 'script',
            'viewScript'   => 'view_script',
            'editorStyle'  => 'editor_style',
            'style'        => 'style',
            'viewStyle'    => 'view_style',
        ) as $sourceField => $targetField ) {
            foreach ( $this->normalizeAssetReferences($blockJson[$sourceField] ?? null, $files, $sourceField) as $reference ) {
                $assets[$targetField][] = $reference;
            }
        }

        return $assets;
    }

    /**
     * @param mixed $value
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function normalizeAssetReferences(mixed $value, array $files, string $sourceField): array
    {
        $references = array();
        $values = is_array($value) ? array_values($value) : array($value);
        foreach ( $values as $item ) {
            if ( ! is_string($item) || '' === trim($item) ) {
                continue;
            }

            $item = trim($item);
            $isFileRef = str_starts_with($item, 'file:');
            $file = $isFileRef ? $this->findBlockFileByRelativePath($files, substr($item, 5)) : null;

            $reference = array(
                'reference'    => $item,
                'source_field' => $sourceField,
                'type'         => $isFileRef ? 'file' : 'handle',
            );
            if ( is_array($file) ) {
                $reference['path'] = $file['path'];
                $reference['kind'] = $file['kind'];
                $reference['bytes'] = $file['bytes'];
            }

            $references[] = $reference;
        }

        return $references;
    }

    /**
     * @param array<string, mixed> $blockJson
     * @param array<int, array<string, mixed>> $files
     * @return array<string, mixed>
     */
    private function blockDependencyContract(array $blockJson, array $files): array
    {
        $declared = array();
        foreach ( array('editorScript', 'script', 'viewScript', 'editorStyle', 'style', 'viewStyle') as $field ) {
            if ( array_key_exists($field, $blockJson) ) {
                $declared[$field] = $blockJson[$field];
            }
        }

        $assetFiles = array();
        foreach ( $files as $file ) {
            if ( ! str_ends_with((string) $file['path'], '.asset.php') ) {
                continue;
            }

            $assetFile = array(
                'path'  => $file['path'],
                'kind'  => $file['kind'],
                'bytes' => $file['bytes'],
            );
            $parsed = $this->parseAssetPhpManifest((string) ($file['content'] ?? ''));
            if ( array() !== $parsed ) {
                $assetFile['manifest'] = $parsed;
            }
            $assetFiles[] = $assetFile;
        }

        return array(
            'declared'    => $declared,
            'asset_files' => $assetFiles,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function parseAssetPhpManifest(string $content): array
    {
        $manifest = array();
        if ( preg_match('/["\']version["\']\s*=>\s*["\']([^"\']+)["\']/', $content, $version) ) {
            $manifest['version'] = $version[1];
        }
        if ( preg_match('/["\']dependencies["\']\s*=>\s*array\s*\((.*?)\)/s', $content, $dependencies) && preg_match_all('/["\']([^"\']+)["\']/', $dependencies[1], $matches) ) {
            $manifest['dependencies'] = array_values($matches[1]);
        }

        return $manifest;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<string, mixed>|null
     */
    private function findBlockFileByRelativePath(array $files, string $relativePath): ?array
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), './');
        foreach ( $files as $file ) {
            if ( basename((string) $file['path']) === $relativePath || str_ends_with((string) $file['path'], '/' . $relativePath) ) {
                return $file;
            }
        }

        return null;
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
