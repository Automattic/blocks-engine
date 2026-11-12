<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Closure;
use InvalidArgumentException;

/**
 * The theme asset rows a site plan builds from the compiler's assets.
 *
 * Each materializable compiled asset becomes one row under `assets/`, with a
 * target path, a token derived from that target, its payload or payload
 * reference, and the stylesheet facts that place it. A payload reference
 * (`blocks-engine/payload-reference/v1`) carries a non-SVG binary by id
 * instead of bytes. Two assets that resolve to one target are merged when
 * their bytes match and renumbered when they differ, and either way the run
 * records a unit finding.
 */
final class CompiledAssets
{
    /** @param mixed $assets @param Closure(array<string,mixed>):void $recordFinding @return array<int,array<string,mixed>> */
    public static function rows(mixed $assets, Closure $recordFinding): array
    {
        if ( ! is_array($assets) ) throw new InvalidArgumentException('Compiled site assets must be an array.');
        $rows = array();
        $rowByTarget = array();
        foreach ( $assets as $asset ) {
            if ( ! is_array($asset) || ! WordPressSitePlan::safePath($asset['path'] ?? null) ) throw new InvalidArgumentException('Compiled site asset lacks a safe source identity.');
            // The compiler retains rejected source assets for diagnostics. They have no
            // payload and therefore are not materializable theme artifacts.
            if ( ! is_string($asset['content'] ?? null) && ! is_string($asset['content_base64'] ?? null) && !self::payloadReference($asset['payload_reference'] ?? null) ) continue;
            $compiledTarget = $asset['target_path'] ?? $asset['path'];
            if ( ! WordPressSitePlan::safePath($compiledTarget) ) throw new InvalidArgumentException('Compiled site asset lacks a safe target identity.');
            $target = 'assets/' . str_replace('\\', '/', $compiledTarget);
            if ( ! WordPressSitePlan::safePath($target) ) throw new InvalidArgumentException('Compiled site asset lacks a safe target identity.');
            $assetContent = is_string($asset['content'] ?? null) ? $asset['content'] : null;
            $payload = is_string($asset['content_base64'] ?? null) ? $asset['content_base64'] : (string) ($assetContent ?? '');
            $reference = self::payloadReference($asset['payload_reference'] ?? null);
            if (null !== $reference && !self::referenceBackedBinary($asset)) throw new InvalidArgumentException('WordPress site plan payload references are limited to non-SVG binary assets.');
            $transportHash = is_string($asset['content_base64'] ?? null) ? WordPressSitePlan::contentHash($asset['content_base64']) : null;
            $contentHash = $reference['sha256'] ?? WordPressSitePlan::contentHash($payload);
            $priorReferences = array();
            if (isset($rowByTarget[strtolower($target)])) [$target, $priorReferences] = self::collidingTarget($rows, $rowByTarget, $target, (string) $asset['path'], $contentHash, $recordFinding);
            $rows[] = array_filter(array('source_path' => $asset['path'], 'target_path' => $target, 'token' => 'asset-' . substr(hash('sha256', $target), 0, 16), 'source' => WordPressSitePlan::value($asset, 'source'), 'source_role' => WordPressSitePlan::value($asset, 'source_role'), 'pipeline_sanitized' => $asset['pipeline_sanitized'] ?? null, 'kind' => WordPressSitePlan::value($asset, 'kind'), 'role' => WordPressSitePlan::value($asset, 'role'), 'stylesheet_placement' => WordPressSitePlan::value($asset, 'stylesheet_placement'), 'stylesheet_target' => 'css' === ($asset['kind'] ?? '') ? (WordPressSitePlan::value($asset, 'stylesheet_target') ?? 'both') : null, 'intent' => WordPressSitePlan::value($asset, 'intent'), 'mime_type' => WordPressSitePlan::value($asset, 'mime_type'), 'media' => WordPressSitePlan::value($asset, 'media'), 'placement' => WordPressSitePlan::value($asset, 'placement'), 'defer' => !empty($asset['defer']) ? true : null, 'async' => !empty($asset['async']) ? true : null, 'selector' => WordPressSitePlan::value($asset, 'selector'), 'references' => is_array($asset['references'] ?? null) || array() !== $priorReferences ? WordPressSitePlan::mergedReferences($priorReferences, ArrayField::array($asset, 'references')) : null, 'bytes' => (int) ($asset['bytes'] ?? 0), 'hash' => WordPressSitePlan::value($asset, 'hash'), 'content' => $assetContent, 'content_base64' => $asset['content_base64'] ?? null, 'payload_reference' => $reference, 'raw_sha256' => $reference['sha256'] ?? ($asset['raw_sha256'] ?? null), 'transport_sha256' => $transportHash, 'binary' => ! empty($asset['binary']), 'compilation' => is_array($asset['compilation'] ?? null) ? $asset['compilation'] : null, 'stylesheet_link_position' => is_int($asset['stylesheet_link_position'] ?? null) ? $asset['stylesheet_link_position'] : null, 'reconciliation_identity' => WordPressSitePlan::identity('asset', $asset['path'], $target), 'content_hash' => $contentHash), static fn(mixed $value): bool => null !== $value);
            $rowByTarget[strtolower($target)] = array_key_last($rows);
            if (isset($asset['inline_references'])) $rows[array_key_last($rows)]['inline_references'] = $asset['inline_references'];
            if (isset($asset['source_media']) && is_string($asset['source_media'])) $rows[array_key_last($rows)]['source_media'] = $asset['source_media'];
            if (isset($asset[CaptureEvidence::DEVICE_STYLE])) $rows[array_key_last($rows)][CaptureEvidence::DEVICE_STYLE] = $asset[CaptureEvidence::DEVICE_STYLE];
            if (isset($asset['stylesheet_activation'])) {
                $rows[array_key_last($rows)]['stylesheet_activation'] = $asset['stylesheet_activation'];
                $rows[array_key_last($rows)]['stylesheet_source_path'] = $asset['stylesheet_source_path'] ?? $asset['path'];
            }
        }
        $rows = array_values($rows);
        $assetsByPath = array_column($assets, null, 'path');
        foreach ($rows as &$row) {
            $source = $assetsByPath[$row['source_path']];
            if (is_string($source['stylesheet_parent'] ?? null)) $row['stylesheet_parent'] = $source['stylesheet_parent'];
            if (is_int($source['stylesheet_inline_order'] ?? null)) $row['stylesheet_inline_order'] = $source['stylesheet_inline_order'];
        }
        unset($row);
        return $rows;
    }

    /**
     * Two compiled assets that resolve to one theme target cannot both be
     * written. When their bytes are identical they are one asset: the earlier
     * row is removed and the later one, whose position wins the cascade for
     * the same rules, keeps every reference. Otherwise the later asset gets a
     * deterministic numbered target (`name-2.css`) and a token derived from
     * it. Either way the plan records a `wordpress_site_plan_colliding_asset_target`
     * finding; the strict validator keeps rejecting a plan that still collides.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,int> $rowByTarget
     * @param Closure(array<string,mixed>):void $recordFinding
     * @return array{0:string,1:array<int,mixed>} The target for the incoming asset and references carried over from a removed duplicate.
     */
    private static function collidingTarget(array &$rows, array $rowByTarget, string $target, string $sourcePath, string $contentHash, Closure $recordFinding): array
    {
        $index = $rowByTarget[strtolower($target)];
        $prior = $rows[$index];
        $finding = array('code' => 'wordpress_site_plan_colliding_asset_target', 'unit' => 'asset', 'source_path' => $sourcePath, 'colliding_source_path' => (string) $prior['source_path'], 'target_path' => $target);
        if ($contentHash === ($prior['content_hash'] ?? null)) {
            unset($rows[$index]);
            $recordFinding($finding + array('message' => 'Two assets resolved to one target with identical content; they were written once.', 'reason_code' => 'identical_content', 'resolved_target_path' => $target));
            return array($target, ArrayField::array($prior, 'references'));
        }
        $dot = strrpos($target, '.');
        $slash = strrpos($target, '/');
        $stem = false !== $dot && (false === $slash || $dot > $slash) ? substr($target, 0, $dot) : $target;
        $extension = $stem === $target ? '' : substr($target, strlen($stem));
        for ($suffix = 2; isset($rowByTarget[strtolower($candidate = $stem . '-' . $suffix . $extension)]); ++$suffix);
        $recordFinding($finding + array('message' => 'Two assets resolved to one target with different content; the later one was given a suffixed target.', 'reason_code' => 'colliding_asset_target', 'resolved_target_path' => $candidate));
        return array($candidate, array());
    }

    /** @param array<string,mixed> $asset */
    public static function referenceBackedBinary(array $asset): bool { return !empty($asset['binary']) && 'image/svg+xml' !== strtolower((string) ($asset['mime_type'] ?? '')) && !str_ends_with(strtolower((string) ($asset['source_path'] ?? $asset['path'] ?? '')), '.svg'); }

    /** @return array{schema:string,id:string,bytes:int,sha256:string}|null */
    public static function payloadReference(mixed $reference): ?array { if (!is_array($reference) || 'blocks-engine/payload-reference/v1' !== ($reference['schema'] ?? null) || !is_string($reference['id'] ?? null) || '' === $reference['id'] || !is_int($reference['bytes'] ?? null) || $reference['bytes'] < 0 || !WordPressSitePlan::hash($reference['sha256'] ?? null)) return null; return array('schema' => $reference['schema'], 'id' => $reference['id'], 'bytes' => $reference['bytes'], 'sha256' => $reference['sha256']); }
}
