<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;

/**
 * Normalizes loose website artifact envelopes into compiler-ready file records.
 *
 * @internal Artifact normalization is owned by ArtifactCompiler.
 */
final class ArtifactNormalizer
{
    public const DEFAULT_MAX_FILES = 500;
    public const DEFAULT_MAX_FILE_BYTES = 5242880;
    public const DEFAULT_MAX_TOTAL_BYTES = 52428800;

    /**
     * @param array<string, mixed> $artifact
     * @return array{files: array<int, array<string, mixed>>, diagnostics: array<int, array<string, mixed>>, rejected_count: int, bytes: int, entrypoints: array<int, string>, hash_payload: string, runtime_declarations: array<int,array<string,mixed>>}
     */
    public function normalize(array $artifact): array
    {
        $runtimeDeclarations = RuntimeDeclarations::normalize($artifact);
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
        $reservedPaths = array();
        foreach ( $rawFiles as $file ) {
            $path = ArtifactPath::safeRelativePath((string) ($file['path'] ?? ''));
            if ( '' !== $path ) {
                $reservedPaths[$path] = true;
            }
        }
        $rawFiles = $this->withInlineScriptFiles($this->withInlineStyleFiles($rawFiles, $reservedPaths));
        $safeEntrypoints = array();
        foreach ( array_unique($entrypoints) as $entrypoint ) {
            $path = ArtifactPath::safeRelativePath($entrypoint);
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

            $path = ArtifactPath::safeRelativePath((string) ($file['path'] ?? ''));
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
            $binary = $payload['binary'] || ( ! $this->isTextKind($kind) && $this->isBinaryMimeType($mimeType) );
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
            foreach ( array('placement', 'type', 'media', 'source_path', 'selector', 'stylesheet_index') as $field ) {
                if ( isset($file[$field]) && is_scalar($file[$field]) && '' !== trim((string) $file[$field]) ) {
                    $normalized[$field] = (string) $file[$field];
                }
            }
            foreach ( array('defer', 'async') as $field ) {
                if ( isset($file[$field]) ) {
                    $normalized[$field] = (bool) $file[$field];
                }
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
            'hash_payload'   => $this->fileHashPayload($files) . "\n" . RuntimeDeclarations::canonicalJson($runtimeDeclarations),
            'runtime_declarations' => $runtimeDeclarations,
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
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function withInlineStyleFiles(array $files, array &$reservedPaths): array
    {
        $expanded = array();
        foreach ( $files as $file ) {
            $expanded[] = $file;

            $content = $this->payload($file, (string) ($file['path'] ?? ''))['content'];
            if ( '' === trim($content) || ! $this->isHtmlLikeFile($file) || ! preg_match_all('@<style\b([^>]*)>(.*?)</style>@is', $content, $matches, PREG_SET_ORDER) ) {
                continue;
            }

            $styles = array();
            foreach ( $matches as $match ) {
                $attributes = (string) $match[1];
                $css = trim((string) $match[2]);
                if ( '' === $css || ! $this->isCssType($this->htmlAttribute($attributes, 'type')) ) {
                    continue;
                }
                $styles[] = array( 'content' => $css, 'media' => $this->htmlAttribute($attributes, 'media'), 'type' => $this->htmlAttribute($attributes, 'type') );
            }
            foreach ( $styles as $index => $style ) {
                $path = $this->allocateGeneratedPath($this->inlineStylePath((string) ($file['path'] ?? 'index.html'), count($styles), $index + 1), $reservedPaths);
                $expanded[] = array(
                    'path'      => $path,
                    'content'   => $style['content'],
                    'kind'      => 'css',
                    'mime_type' => 'text/css',
                    'role'      => 'stylesheet',
                    'intent'    => 'style',
                    'source'    => 'inline-style',
                    'source_path' => (string) ($file['path'] ?? 'index.html'),
                    'stylesheet_index' => $index + 1,
                    'media' => $style['media'],
                    'type' => $style['type'],
                );
            }
        }

        return $expanded;
    }

    /**
     * @param array<string, mixed> $file
     */
    private function isHtmlLikeFile(array $file): bool
    {
        $kind = strtolower((string) ($file['kind'] ?? $file['type'] ?? ''));
        $mimeType = strtolower((string) ($file['mime_type'] ?? $file['mime'] ?? $file['media_type'] ?? ''));
        $path = strtolower((string) ($file['path'] ?? ''));

        return ( in_array($kind, array( '', 'html' ), true) || str_contains($kind, 'html') )
            && ( '' === $mimeType || str_contains($mimeType, 'html') )
            && ( '' === $path || preg_match('/\.html?$/', $path) || 'index.html' === $path );
    }

    private function inlineStylePath(string $htmlPath, int $count = 1, int $index = 1): string
    {
        $path = ArtifactPath::safeRelativePath($htmlPath);
        if ( '' === $path ) {
            return 1 === $count ? 'inline-styles.css' : 'inline-styles-' . $index . '.css';
        }

        $directory = trim((string) pathinfo($path, PATHINFO_DIRNAME), '.');
        $filename = pathinfo($path, PATHINFO_FILENAME);
        $stylePath = ('' === $filename ? 'inline' : $filename) . '.inline' . (1 === $count ? '' : '-' . $index) . '.css';

        return '' === $directory ? $stylePath : $directory . '/' . $stylePath;
    }

    /** @param array<string, true> $reservedPaths */
    private function allocateGeneratedPath(string $candidate, array &$reservedPaths): string
    {
        $path = $candidate;
        $index = 1;
        while ( isset($reservedPaths[$path]) ) {
            $extension = pathinfo($candidate, PATHINFO_EXTENSION);
            $base = '' === $extension ? $candidate : substr($candidate, 0, -strlen($extension) - 1);
            $path = $base . '-generated-' . $index++ . ('' === $extension ? '' : '.' . $extension);
        }
        $reservedPaths[$path] = true;
        return $path;
    }

    private function isCssType(string $type): bool
    {
        $type = strtolower(trim($type));
        return '' === $type || 1 === preg_match("/^text\\/css(?:\\s*;\\s*[!#$%&'*+\\-.^_`|~0-9a-z]+(?:\\s*=\\s*(?:[!#$%&'*+\\-.^_`|~0-9a-z]+|\"(?:[^\"\\\\]|\\\\.)*\"))?)*\\s*$/i", $type);
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function withInlineScriptFiles(array $files): array
    {
        $expanded = array();
        foreach ( $files as $file ) {
            $expanded[] = $file;

            $content = is_string($file['content'] ?? null) ? (string) $file['content'] : '';
            if ( '' === trim($content) || ! $this->isHtmlLikeFile($file) || ! preg_match_all('@<script\b([^>]*)>(.*?)</script>@is', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) ) {
                continue;
            }

            $scriptIndex = 0;
            foreach ( $matches as $match ) {
                ++$scriptIndex;
                $attributes = (string) $match[1][0];
                $body = trim((string) $match[2][0]);
                if ( '' === $body || '' !== $this->htmlAttribute($attributes, 'src') || ! $this->isExecutableScriptType($this->htmlAttribute($attributes, 'type')) ) {
                    continue;
                }

                $expanded[] = array(
                    'path'        => $this->inlineScriptPath((string) ($file['path'] ?? 'index.html'), $scriptIndex),
                    'content'     => $body,
                    'kind'        => 'js',
                    'mime_type'   => 'text/javascript',
                    'role'        => 'script',
                    'intent'      => 'behavior',
                    'source'      => 'inline-script',
                    'placement'   => $this->scriptPlacement($content, (int) $match[0][1]),
                    'type'        => $this->htmlAttribute($attributes, 'type'),
                    'defer'       => $this->hasBooleanAttribute($attributes, 'defer'),
                    'async'       => $this->hasBooleanAttribute($attributes, 'async'),
                    'source_path' => ArtifactPath::safeRelativePath((string) ($file['path'] ?? 'index.html')),
                    'selector'    => 'script:nth-of-type(' . $scriptIndex . ')',
                );
            }
        }

        return $expanded;
    }

    private function inlineScriptPath(string $htmlPath, int $index): string
    {
        $path = ArtifactPath::safeRelativePath($htmlPath);
        if ( '' === $path ) {
            return 1 === $index ? 'inline.js' : 'inline-' . $index . '.js';
        }

        $directory = trim((string) pathinfo($path, PATHINFO_DIRNAME), '.');
        $filename = pathinfo($path, PATHINFO_FILENAME);
        $scriptPath = ('' === $filename ? 'inline' : $filename) . (1 === $index ? '.inline.js' : '.inline-' . $index . '.js');

        return '' === $directory ? $scriptPath : $directory . '/' . $scriptPath;
    }

    private function isExecutableScriptType(string $type): bool
    {
        $type = strtolower(trim($type));
        return '' === $type || in_array($type, array('module', 'text/javascript', 'application/javascript', 'text/ecmascript', 'application/ecmascript'), true);
    }

    private function htmlAttribute(string $attributes, string $name): string
    {
        if ( preg_match('/(?:^|\s)' . preg_quote($name, '/') . '\s*=\s*(["\'])(.*?)\1/i', $attributes, $match) ) {
            return html_entity_decode((string) $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if ( preg_match('/(?:^|\s)' . preg_quote($name, '/') . '\s*=\s*([^\s>]+)/i', $attributes, $match) ) {
            return html_entity_decode((string) $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    }

    private function hasBooleanAttribute(string $attributes, string $name): bool
    {
        return 1 === preg_match('/(?:^|\s)' . preg_quote($name, '/') . '(?:\s|=|$)/i', $attributes);
    }

    private function scriptPlacement(string $html, int $offset): string
    {
        $headClose = stripos($html, '</head>');
        return false !== $headClose && $offset < $headClose ? 'head' : 'body';
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
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'wav' => 'audio/wav',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            'pdf' => 'application/pdf',
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

    private function isTextKind(string $kind): bool
    {
        return in_array($kind, array('html', 'css', 'js', 'jsx', 'tsx', 'json', 'markdown', 'mdx', 'blocks'), true);
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
        if ( str_starts_with($mimeType, 'audio/') ) {
            return 'audio';
        }
        if ( str_starts_with($mimeType, 'video/') ) {
            return 'video';
        }
        if ( 'application/pdf' === $mimeType ) {
            return 'document';
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
                'source'   => ArtifactCompiler::class,
                'context'  => $context,
            ),
            static fn (mixed $value): bool => array() !== $value
        );
    }
}
