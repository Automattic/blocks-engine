<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/** Linear, site-wide check after chrome factoring and asset assembly. */
final class NavigationInstanceDiagnostics
{
    /** @param list<array<string,mixed>> $assets @return list<array<string,mixed>> */
    public static function collect(array $assets, string $markup): array
    {
        $pattern = '/(?<![A-Za-z0-9_-])blocks-engine-navigation-instance-[a-f0-9]{12}-[0-9]+(?![A-Za-z0-9_-])/';
        preg_match_all($pattern, $markup, $matches);
        $emitted = array_fill_keys($matches[0], true);
        $missing = array();
        foreach ($assets as $asset) {
            if ('css' !== ($asset['kind'] ?? null)) continue;
            preg_match_all($pattern, (string) ($asset['content'] ?? ''), $matches);
            foreach (array_unique($matches[0]) as $marker) {
                if (!isset($emitted[$marker])) $missing[$marker] ??= (string) ($asset['source_path'] ?? '');
            }
        }
        $diagnostics = array();
        foreach ($missing as $marker => $source) $diagnostics[] = array(
            'code' => 'wordpress_site_plan_orphaned_navigation_instance',
            'severity' => 'warning',
            'message' => 'Projected navigation CSS targets an instance absent from emitted markup: ' . $marker,
            'source_path' => $source,
            'instance_marker' => $marker,
        );
        return $diagnostics;
    }
}
