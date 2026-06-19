<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;

final class ArtifactCompiler
{
    private const INPUT_SCHEMA = 'block-artifact-compiler/website-artifact/v1';

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
                    'source_hash'   => hash('sha256', json_encode($normalized['hash_payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: ''),
                ),
            )
        );
    }

    /**
     * @param array<string, mixed> $artifact
     * @return array{files: array<int, array<string, mixed>>, diagnostics: array<int, array<string, mixed>>, rejected_count: int, bytes: int, entrypoints: array<int, string>, hash_payload: array<int, array<string, mixed>>}
     */
    private function normalizeArtifact(array $artifact): array
    {
        $diagnostics = array();
        $files = array();
        $entrypoints = array();
        $rejected = 0;
        $bytes = 0;

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
            $path = $this->safeRelativePath((string) ($file['path'] ?? ''));
            if ( '' === $path ) {
                ++$rejected;
                $diagnostics[] = $this->diagnostic('unsafe_artifact_path', 'warning', 'An artifact file was ignored because its path is empty, absolute, or escapes the artifact root.', array('index' => $index));
                continue;
            }

            $payload = $this->payload($file);
            if ( ! $payload['accepted'] ) {
                ++$rejected;
                $diagnostics[] = $this->diagnostic('invalid_base64_payload', 'warning', 'An artifact file was ignored because its base64 payload is invalid.', array('path' => $path));
                continue;
            }

            $mimeType = $this->mimeType((string) ($file['mime_type'] ?? $file['mime'] ?? $file['media_type'] ?? ''), $path);
            $kind = $this->kind((string) ($file['kind'] ?? $file['type'] ?? ''), $path, $mimeType);
            $role = is_string($file['role'] ?? null) && '' !== $file['role'] ? $file['role'] : ('html' === $kind ? 'entry' : 'asset');
            $binary = $payload['binary'] || $this->isBinaryMimeType($mimeType);
            $entrypoint = in_array($path, $safeEntrypoints, true) || ! empty($file['entrypoint']) || 'entry' === $role;
            if ( $entrypoint && ! in_array($path, $safeEntrypoints, true) ) {
                $safeEntrypoints[] = $path;
            }

            $normalized = array(
                'path'       => $path,
                'content'    => $payload['content'],
                'kind'       => $kind,
                'bytes'      => strlen($payload['content']),
                'mime_type'  => $mimeType,
                'role'       => $role,
                'encoding'   => $payload['encoding'],
                'binary'     => $binary,
                'entrypoint' => $entrypoint,
                'provenance' => array(
                    'source_path' => $path,
                    'hash'        => hash('sha256', '' !== $payload['content_base64'] ? $payload['content_base64'] : $payload['content']),
                ),
            );
            if ( '' !== $payload['content_base64'] ) {
                $normalized['content_base64'] = $payload['content_base64'];
            }

            $bytes += $normalized['bytes'];
            $files[] = $normalized;
        }

        return array(
            'files'          => $files,
            'diagnostics'    => $diagnostics,
            'rejected_count' => $rejected,
            'bytes'          => $bytes,
            'entrypoints'    => array_values(array_unique($safeEntrypoints)),
            'hash_payload'   => array_map(
                static fn (array $file): array => array(
                    'path' => $file['path'],
                    'hash' => $file['provenance']['hash'],
                ),
                $files
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
                    $file['path'] = is_scalar($file['path'] ?? null) ? (string) $file['path'] : (is_string($path) ? $path : '');
                    $files[] = $file;
                    continue;
                }
                if ( is_string($file) ) {
                    $files[] = array(
                        'path'    => is_string($path) ? $path : 'artifact-' . $path . '.html',
                        'content' => $file,
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
                );
            }
        }

        return $files;
    }

    /**
     * @param array<string, mixed> $file
     * @return array{accepted: bool, content: string, content_base64: string, encoding: string, binary: bool}
     */
    private function payload(array $file): array
    {
        if ( is_string($file['content_base64'] ?? null) ) {
            $decoded = base64_decode($file['content_base64'], true);
            if ( false === $decoded ) {
                return array('accepted' => false, 'content' => '', 'content_base64' => '', 'encoding' => 'base64', 'binary' => false);
            }

            return array('accepted' => true, 'content' => $decoded, 'content_base64' => $file['content_base64'], 'encoding' => 'base64', 'binary' => false);
        }

        $content = is_string($file['content'] ?? null) ? $file['content'] : '';
        return array('accepted' => true, 'content' => $content, 'content_base64' => '', 'encoding' => 'utf-8', 'binary' => false);
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
        foreach ( array('index.html', 'index.htm') as $preferred ) {
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

    private function kind(string $kind, string $path, string $mimeType): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match (true) {
            'html' === $kind || 'htm' === $extension || 'html' === $extension || 'text/html' === $mimeType => 'html',
            'json' === $extension => 'json',
            'css' === $extension || 'text/css' === $mimeType => 'css',
            'js' === $extension || 'javascript' === $kind => 'js',
            'png' === $extension || 'jpg' === $extension || 'jpeg' === $extension || str_starts_with($mimeType, 'image/') => 'asset',
            default => '' !== $kind && ! str_contains($kind, '/') ? $kind : 'asset',
        };
    }

    private function mimeType(string $mimeType, string $path): string
    {
        if ( '' !== $mimeType && str_contains($mimeType, '/') ) {
            return strtolower($mimeType);
        }
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'html', 'htm' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }

    private function isBinaryMimeType(string $mimeType): bool
    {
        return ! str_starts_with($mimeType, 'text/') && ! in_array($mimeType, array('application/json', 'application/javascript'), true);
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
                'mime_type'  => $file['mime_type'],
                'role'       => $file['role'],
                'encoding'   => $file['encoding'],
                'binary'     => $file['binary'],
                'provenance' => $file['provenance'],
            );
            if ( ! empty($file['content_base64']) ) {
                $asset['content_base64'] = $file['content_base64'];
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

        return $counts;
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
