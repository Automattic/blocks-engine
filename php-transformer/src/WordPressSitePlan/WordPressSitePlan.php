<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use InvalidArgumentException;

/** The stable, self-contained handoff for materializing compiled artifacts. */
final class WordPressSitePlan
{
    public const SCHEMA = 'blocks-engine/wordpress-site-plan/v1';

    /** @return array<string,mixed> */
    public function fromResult(TransformerResult|array $result): array
    {
        $data = $result instanceof TransformerResult ? $result->toArray() : $result;
        TransformerResult::assertCanonicalEnvelope($data);
        $compiledSite = $data['source_reports']['compiled_site'] ?? null;
        $materializationPlan = $data['source_reports']['materialization_plan'] ?? null;
        if ( ! is_array($compiledSite) || ! is_array($materializationPlan) ) {
            throw new InvalidArgumentException('WordPress site plan requires compiled-site and materialization-plan reports.');
        }

        $plan = array(
            'schema' => self::SCHEMA,
            'source' => array(
                'schema' => $compiledSite['schema'] ?? null,
                'source_hash' => $compiledSite['source_hash'] ?? null,
                'entry_path' => $compiledSite['entry_path'] ?? null,
                'provenance' => $data['provenance'],
            ),
            'pages' => $this->documents($compiledSite['pages'] ?? null, 'page'),
            // ArtifactCompiler does not currently infer reusable full templates.
            'templates' => array(),
            'template_parts' => $this->documents($compiledSite['template_parts'] ?? null, 'template_part'),
            'assets' => $this->assets($compiledSite['assets'] ?? null),
            'writes' => $this->writes($compiledSite['assets'] ?? null),
            'routes' => $materializationPlan['routes'] ?? null,
            'navigation_links' => $materializationPlan['navigation_links'] ?? null,
            'menus' => $materializationPlan['menus'] ?? null,
            'asset_rewrite_candidates' => $materializationPlan['asset_rewrite_candidates'] ?? null,
            'theme' => $materializationPlan['theme'] ?? null,
            'visual_repair' => $compiledSite['visual_repair'] ?? array(),
            'diagnostics' => $data['diagnostics'],
            'quality' => array(
                'status' => $data['status'],
                'metrics' => array_diff_key($data['metrics'], array('transform_duration_ms' => true)),
                'fallbacks' => $data['fallbacks'],
            ),
        );
        self::assertValid($plan);

        return $plan;
    }

    /** @param array<string,mixed> $plan */
    public static function assertValid(array $plan): void
    {
        if ( self::SCHEMA !== ($plan['schema'] ?? null) ) {
            throw new InvalidArgumentException('WordPress site plan has an unsupported schema.');
        }
        foreach ( array('source', 'pages', 'templates', 'template_parts', 'assets', 'writes', 'routes', 'navigation_links', 'menus', 'asset_rewrite_candidates', 'theme', 'visual_repair', 'diagnostics', 'quality') as $key ) {
            if ( ! is_array($plan[$key] ?? null) ) {
                throw new InvalidArgumentException(sprintf('WordPress site plan %s must be an array.', $key));
            }
        }
        self::assertSource($plan['source']);
        foreach ( $plan['pages'] as $page ) {
            self::assertDocument($page, 'page', false);
        }
        foreach ( $plan['templates'] as $template ) {
            self::assertDocument($template, 'template', false);
        }
        foreach ( $plan['template_parts'] as $part ) {
            self::assertDocument($part, 'template part', true);
        }
        foreach ( $plan['assets'] as $asset ) {
            self::assertAsset($asset);
        }
        foreach ( $plan['writes'] as $write ) {
            self::assertWrite($write);
        }
        self::assertRows($plan['routes'], 'route', array('kind', 'source_path', 'target_path', 'target_slug', 'source_relation', 'order'));
        self::assertRows($plan['navigation_links'], 'navigation link', array('kind', 'source_path', 'source_relation', 'order'), array('target_path', 'target_slug'));
        self::assertRows($plan['menus'], 'menu', array('kind', 'source_path', 'target_slug', 'source_relation', 'order', 'items'));
        self::assertRows($plan['asset_rewrite_candidates'], 'asset rewrite candidate', array('scope', 'source_path', 'asset_path'));
        self::assertTheme($plan['theme']);
        self::assertQuality($plan['quality']);
    }

    /** @param mixed $documents @return array<int,array<string,mixed>> */
    private function documents(mixed $documents, string $kind): array
    {
        if ( ! is_array($documents) ) {
            throw new InvalidArgumentException(sprintf('Compiled site %ss must be an array.', $kind));
        }
        $projected = array();
        foreach ( $documents as $document ) {
            if ( ! is_array($document) ) {
                throw new InvalidArgumentException(sprintf('Compiled site %s must be an array.', $kind));
            }
            $projected[] = $this->document($document, 'template_part' === $kind);
        }
        return $projected;
    }

    /** @param array<string,mixed> $document @return array<string,mixed> */
    private function document(array $document, bool $templatePart): array
    {
        $sourcePath = $document['source_path'] ?? null;
        $markup = $document['block_markup'] ?? null;
        $metadata = $document['metadata'] ?? array();
        $provenance = $document['provenance'] ?? array();
        if ( ! self::safeTargetPath($sourcePath) || ! is_string($markup) || '' === trim($markup) || ! is_array($metadata) || ! is_array($provenance) ) {
            throw new InvalidArgumentException(sprintf('Compiled site %s lacks a safe source identity, final block markup, or metadata.', $templatePart ? 'template part' : 'page'));
        }
        $area = $templatePart ? ($document['area'] ?? null) : null;
        if ( $templatePart && (! is_string($area) || '' === $area) ) {
            throw new InvalidArgumentException('Compiled site template part lacks an area.');
        }
        return array(
            'source_path' => $sourcePath,
            'slug' => self::stringValue($document, 'slug'),
            'title' => self::stringValue($document, 'title'),
            'post_type' => self::stringValue($metadata, 'post_type', 'page'),
            'parent_source_path' => self::stringValue($metadata, 'parent_source_path'),
            'entrypoint' => ! empty($document['entrypoint']),
            'area' => $area,
            'final_block_markup' => $markup,
            'metadata' => $metadata,
            'provenance' => $provenance,
            'reconciliation_identity' => hash('sha256', $sourcePath . "\n" . $markup),
        );
    }

    /** @param mixed $assets @return array<int,array<string,mixed>> */
    private function assets(mixed $assets): array
    {
        if ( ! is_array($assets) ) {
            throw new InvalidArgumentException('Compiled site assets must be an array.');
        }
        $projected = array();
        foreach ( $assets as $asset ) {
            if ( ! is_array($asset) || ! self::safeTargetPath($asset['path'] ?? null) || ! self::safeTargetPath($asset['target_path'] ?? null) ) {
                throw new InvalidArgumentException('Compiled site asset lacks safe source or target identity.');
            }
            $projected[] = array(
                'source_path' => $asset['path'],
                'target_path' => $asset['target_path'],
                'source' => self::stringValue($asset, 'source'),
                'kind' => self::stringValue($asset, 'kind'),
                'role' => self::stringValue($asset, 'role'),
                'intent' => self::stringValue($asset, 'intent'),
                'mime_type' => self::stringValue($asset, 'mime_type'),
                'media' => self::stringValue($asset, 'media'),
                'hash' => self::stringValue($asset, 'hash'),
                'load' => $this->load($asset),
            );
        }
        return $projected;
    }

    /** @param mixed $assets @return array<int,array<string,mixed>> */
    private function writes(mixed $assets): array
    {
        if ( ! is_array($assets) ) {
            throw new InvalidArgumentException('Compiled site assets must be an array.');
        }
        $writes = array();
        foreach ( $assets as $asset ) {
            if ( ! is_array($asset) ) {
                throw new InvalidArgumentException('Compiled site asset must be an array.');
            }
            $base64 = $asset['content_base64'] ?? null;
            $content = $asset['content'] ?? null;
            if ( is_string($base64) && '' !== $base64 ) {
                $payload = array('encoding' => 'base64', 'data' => $base64);
            } elseif ( is_string($content) ) {
                $payload = array('encoding' => ! empty($asset['binary']) || 1 !== preg_match('//u', $content) ? 'base64' : 'utf8', 'data' => ! empty($asset['binary']) || 1 !== preg_match('//u', $content) ? base64_encode($content) : $content);
            } else {
                continue;
            }
            $writes[] = array(
                'kind' => 'theme_asset',
                'source_path' => $asset['path'] ?? null,
                'target_path' => $asset['target_path'] ?? null,
                'payload' => $payload,
                'mime_type' => self::stringValue($asset, 'mime_type'),
                'media' => self::stringValue($asset, 'media'),
                'hash' => self::stringValue($asset, 'hash'),
                'load' => $this->load($asset),
            );
        }
        return $writes;
    }

    /** @param array<string,mixed> $asset @return array<string,mixed> */
    private function load(array $asset): array
    {
        return array('placement' => self::stringValue($asset, 'placement'), 'type' => self::stringValue($asset, 'type'), 'defer' => ! empty($asset['defer']), 'async' => ! empty($asset['async']));
    }

    /** @param array<string,mixed> $source */
    private static function assertSource(array $source): void
    {
        if ( 'blocks-engine/php-transformer/compiled-site/v1' !== ($source['schema'] ?? null) || ! is_string($source['source_hash'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', $source['source_hash']) || ! is_string($source['entry_path'] ?? null) || ('' !== $source['entry_path'] && ! self::safeTargetPath($source['entry_path'])) || ! is_array($source['provenance'] ?? null) ) {
            throw new InvalidArgumentException('WordPress site plan source identity is invalid.');
        }
    }

    private static function assertDocument(mixed $document, string $kind, bool $templatePart): void
    {
        if ( ! is_array($document) || ! self::safeTargetPath($document['source_path'] ?? null) || ! is_string($document['slug'] ?? null) || ! is_string($document['title'] ?? null) || ! is_string($document['post_type'] ?? null) || ! is_string($document['parent_source_path'] ?? null) || ! is_bool($document['entrypoint'] ?? null) || ! is_string($document['final_block_markup'] ?? null) || '' === trim($document['final_block_markup']) || ! is_array($document['metadata'] ?? null) || ! is_array($document['provenance'] ?? null) || ! is_string($document['reconciliation_identity'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', $document['reconciliation_identity']) || ($templatePart && (! is_string($document['area'] ?? null) || '' === $document['area'])) || (! $templatePart && null !== ($document['area'] ?? null)) ) {
            throw new InvalidArgumentException(sprintf('WordPress site plan %s is structurally invalid.', $kind));
        }
    }

    private static function assertAsset(mixed $asset): void
    {
        if ( ! is_array($asset) || ! self::safeTargetPath($asset['source_path'] ?? null) || ! self::safeTargetPath($asset['target_path'] ?? null) || ! is_string($asset['source'] ?? null) || ! is_string($asset['kind'] ?? null) || ! is_string($asset['role'] ?? null) || ! is_string($asset['intent'] ?? null) || ! is_string($asset['mime_type'] ?? null) || ! is_string($asset['media'] ?? null) || ! is_string($asset['hash'] ?? null) || ! is_array($asset['load'] ?? null) ) {
            throw new InvalidArgumentException('WordPress site plan asset is structurally invalid.');
        }
        self::assertLoad($asset['load']);
    }

    private static function assertWrite(mixed $write): void
    {
        if ( ! is_array($write) || 'theme_asset' !== ($write['kind'] ?? null) || ! self::safeTargetPath($write['source_path'] ?? null) || ! self::safeTargetPath($write['target_path'] ?? null) || ! is_array($write['payload'] ?? null) || ! is_string($write['mime_type'] ?? null) || ! is_string($write['media'] ?? null) || ! is_string($write['hash'] ?? null) || ! is_array($write['load'] ?? null) ) {
            throw new InvalidArgumentException('WordPress site plan has a structurally invalid write.');
        }
        $encoding = $write['payload']['encoding'] ?? null;
        $data = $write['payload']['data'] ?? null;
        if ( ! in_array($encoding, array('utf8', 'base64'), true) || ! is_string($data) || ('base64' === $encoding && ('' === $data || false === base64_decode($data, true))) ) {
            throw new InvalidArgumentException('WordPress site plan write has an invalid payload encoding.');
        }
        self::assertLoad($write['load']);
    }

    /** @param array<int,mixed> $rows @param array<int,string> $fields @param array<int,string> $optionalFields */
    private static function assertRows(array $rows, string $kind, array $fields, array $optionalFields = array()): void
    {
        foreach ( $rows as $row ) {
            if ( ! is_array($row) ) {
                throw new InvalidArgumentException(sprintf('WordPress site plan %s must be an array.', $kind));
            }
            foreach ( $fields as $field ) {
                if ( ! array_key_exists($field, $row) || ! is_string($row[$field]) && ! is_int($row[$field]) ) {
                    throw new InvalidArgumentException(sprintf('WordPress site plan %s lacks %s.', $kind, $field));
                }
            }
            foreach ( $optionalFields as $field ) {
                if ( array_key_exists($field, $row) && ! is_string($row[$field]) ) {
                    throw new InvalidArgumentException(sprintf('WordPress site plan %s has an invalid %s.', $kind, $field));
                }
            }
        }
    }

    private static function assertTheme(array $theme): void
    {
        foreach ( array('stylesheets', 'scripts', 'fonts', 'images', 'template_parts') as $key ) {
            if ( isset($theme[$key]) && ! is_array($theme[$key]) ) {
                throw new InvalidArgumentException(sprintf('WordPress site plan theme %s must be an array.', $key));
            }
        }
    }

    private static function assertQuality(array $quality): void
    {
        if ( ! is_string($quality['status'] ?? null) || ! is_array($quality['metrics'] ?? null) || ! is_array($quality['fallbacks'] ?? null) ) {
            throw new InvalidArgumentException('WordPress site plan quality is structurally invalid.');
        }
    }

    /** @param array<string,mixed> $load */
    private static function assertLoad(array $load): void
    {
        if ( ! is_string($load['placement'] ?? null) || ! is_string($load['type'] ?? null) || ! is_bool($load['defer'] ?? null) || ! is_bool($load['async'] ?? null) ) {
            throw new InvalidArgumentException('WordPress site plan load metadata is structurally invalid.');
        }
    }

    /** @param array<string,mixed> $data */
    private static function stringValue(array $data, string $key, string $default = ''): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : $default;
    }

    private static function safeTargetPath(mixed $path): bool
    {
        if ( ! is_string($path) || '' === $path || str_contains($path, "\0") || str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:/', $path) ) {
            return false;
        }
        foreach ( explode('/', str_replace('\\', '/', $path)) as $segment ) {
            if ( '' === $segment || '.' === $segment || '..' === $segment ) {
                return false;
            }
        }
        return true;
    }
}
