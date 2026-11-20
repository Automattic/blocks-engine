<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Closure;

/** Artifact image references carried by products rather than rendered markup. */
final class ProductImages
{
    /** @param list<array<string,mixed>> $declarations @return list<string> */
    public static function paths(array $declarations): array
    {
        $paths = array();
        foreach ($declarations as $declaration) {
            if ('entity_collection' !== ($declaration['kind'] ?? null) || 'products' !== ($declaration['type'] ?? null) || 'generic/products/v1' !== ($declaration['payload']['schema'] ?? null)) continue;
            foreach ($declaration['payload']['entities'] ?? array() as $row) {
                $values = array($row['image'] ?? null);
                foreach ($row['images'] ?? array() as $image) $values[] = $image['src'] ?? null;
                foreach ($row['variants'] ?? array() as $variant) $values[] = $variant['image'] ?? null;
                foreach ($values as $value) {
                    if (!is_string($value) || '' === $value || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $value)) continue;
                    $paths[] = $value;
                }
            }
        }
        return array_values(array_unique($paths));
    }

    /** @param array<string,mixed> $asset */
    public static function isImage(array $asset): bool
    {
        return str_starts_with((string) ($asset['mime_type'] ?? ''), 'image/');
    }

    /** @param list<array<string,mixed>> $assets @param list<array<string,mixed>> $declarations @param Closure(array<string,mixed>):void $recordFinding */
    public static function diagnose(array $assets, array $declarations, Closure $recordFinding): void
    {
        $bySource = array_column($assets, null, 'source_path');
        foreach (self::paths($declarations) as $path) {
            $reason = !WordPressSitePlan::safePath($path) ? 'unsafe_path' : (!isset($bySource[$path]) ? 'missing_image' : (!self::isImage($bySource[$path]) ? 'non_image' : null));
            if (null !== $reason) $recordFinding(array('code' => 'wordpress_site_plan_product_image_unavailable', 'unit' => 'asset', 'source_path' => $path, 'reason_code' => $reason, 'message' => 'A declared product image is not a safe, materializable artifact image.'));
        }
    }

    /** @param array<string,mixed> $plan @return array<string,true> */
    public static function referencedPaths(array $plan): array
    {
        return array_fill_keys(self::paths(RuntimeDeclarations::materialize($plan['runtime_declarations'] ?? array(), $plan['runtime_records'] ?? array())), true);
    }
}
