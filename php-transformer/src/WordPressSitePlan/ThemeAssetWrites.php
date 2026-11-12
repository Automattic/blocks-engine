<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use InvalidArgumentException;

/**
 * The theme asset writes one site plan run emits, with the warnings they raise.
 *
 * Each asset's payload is resolved through the run's reference canonicalizer
 * and written as text or base64, or as a payload reference. A script or data
 * file is published verbatim, so a local address written in its strings is
 * reported once per file instead of rewritten.
 */
final class ThemeAssetWrites
{
    /** @var array<string,array<string,mixed>> */
    private array $unresolvedScriptReferenceDiagnostics = array();
    private int $omittedUnresolvedScriptReferenceDiagnostics = 0;

    /** @param array<int,array<string,mixed>> $assets @param array<int,array<string,string>> $tokens @return array<int,array<string,mixed>> */
    public function writes(array $assets, AssetReferenceCanonicalizer $references): array
    {
        $writes = array();
        $authorStylesheetOrigins = array_values(array_filter(array_map(static fn(array $asset): ?string => 'css' === ($asset['kind'] ?? null) && 'files' === ($asset['source'] ?? null) && is_string($asset['source_path'] ?? null) ? $asset['source_path'] : null, $assets)));
        foreach ( $assets as $asset ) {
            $content = is_string($asset['content'] ?? null) ? $asset['content'] : null;
            // Resolve copied author URLs before recovery judges them against the
            // generated stylesheet's directory (which is not their origin).
            if ('editor-static-state' === ($asset['source'] ?? null) && is_string($content)) $content = $references->cssFromOrigins($content, $authorStylesheetOrigins);
            // An inline stylesheet's urls are relative to the documents that held
            // the <style>, not to its generated inline/ path.
            if ('inline-style' === ($asset['source'] ?? null) && is_string($content)) $content = $references->cssFromOrigins($content, array_column($asset['inline_references'] ?? array(), 'source_path'));
            if (is_string($content)) $content = $references->content($content, (string) ($asset['reference_origin'] ?? $asset['source_path']));
            if (is_array($asset['payload_reference'] ?? null)) { $writes[] = WordPressSitePlan::referenceWrite('theme_asset', $asset['target_path'], $asset['source_path'], $asset['payload_reference']); continue; }
            $base64Transport = is_string($asset['content_base64'] ?? null);
            $text = is_string($content) && empty($asset['binary']) && 1 === preg_match('//u', $content) && (!$base64Transport || 'text/css' === ($asset['mime_type'] ?? null));
            $data = $text ? $content : (is_string($asset['content_base64'] ?? null) ? $asset['content_base64'] : (is_string($content) ? base64_encode($content) : null));
            if ( ! is_string($data) ) throw new InvalidArgumentException(sprintf('Compiled site asset %s lacks a materializable payload.', $asset['source_path']));
            $writes[] = WordPressSitePlan::write('theme_asset', $asset['target_path'], $data, $asset['source_path'], $text ? 'utf8' : 'base64');
            if ($text && LocalBrowserReferences::isVerbatimWrite('theme_asset', $asset['target_path'])) $this->recordUnresolvedScriptReferences($asset['target_path'], $data, $asset['source_path']);
        }
        return $writes;
    }

    /**
     * A script or data file is published verbatim, so a local route or asset
     * path written in its strings (`'<a href="' + slug + '.html">'`) cannot be
     * rewritten to its WordPress address. The page still imports: each distinct
     * string is reported once per file as a warning naming where it was written.
     */
    private function recordUnresolvedScriptReferences(string $targetPath, string $payload, string $sourcePath): void
    {
        LocalBrowserReferences::assertNoneInWrite($targetPath, $payload, $sourcePath, function (string $url, string $attribute) use ($sourcePath): void {
            $key = $sourcePath . "\0" . $url;
            if (isset($this->unresolvedScriptReferenceDiagnostics[$key])) return;
            if (count($this->unresolvedScriptReferenceDiagnostics) >= WordPressSitePlan::MAX_UNRESOLVED_NAVIGATION_DIAGNOSTICS) { $this->omittedUnresolvedScriptReferenceDiagnostics++; return; }
            $this->unresolvedScriptReferenceDiagnostics[$key] = array(
                'code' => 'wordpress_site_plan_unresolved_script_reference',
                'severity' => 'warning',
                'message' => mb_strcut("{$sourcePath} writes {$url} in its strings; the file is published verbatim, so that address is not rewritten for WordPress.", 0, 256, 'UTF-8'),
                'source_path' => mb_strcut($sourcePath, 0, 256, 'UTF-8'),
                'value' => mb_strcut($url, 0, 256, 'UTF-8'),
                'attribute' => $attribute,
                'reason_code' => 'unresolved_local_browser_reference',
            );
        });
    }

    /** @return array<int,array<string,mixed>> */
    public function diagnostics(): array
    {
        $diagnostics = array_values($this->unresolvedScriptReferenceDiagnostics);
        if ($this->omittedUnresolvedScriptReferenceDiagnostics > 0) $diagnostics[] = array('code' => 'wordpress_site_plan_unresolved_script_reference', 'severity' => 'warning', 'message' => sprintf('%d more unresolved script references were published verbatim; omitted from this diagnostic list.', $this->omittedUnresolvedScriptReferenceDiagnostics));
        return $diagnostics;
    }
}
