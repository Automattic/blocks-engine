<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeIslandPackageBuilder;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;

/**
 * The scripts a site plan's documents load: which captured scripts a runtime
 * island already owns, which local script assets documents reference, and the
 * script-loading plan the theme enqueues (each executable script once per
 * scope, with its loading attributes and local target).
 */
final class DocumentScripts
{
    /** Inline scripts are published only when the script-loading plan enqueues them. */
    public static function unreferencedInline(array $plan): array
    {
        $loading = self::loading($plan['pages'], $plan['template_parts'], $plan['assets'], $plan['reference_tokens'], $plan['operations'], $plan['runtime_declarations']);
        $targets = array_fill_keys(array_filter(array_column($loading['scripts'], 'local_target'), 'is_string'), true);
        $unreferenced = array();
        foreach ($plan['assets'] as $asset) {
            if ('inline-script' === ($asset['source'] ?? null) && !isset($targets[$asset['target_path']])) $unreferenced[$asset['token']] = true;
        }
        return $unreferenced;
    }

    /** @param array<string,mixed> $runtimeIslandPackage @param array<int,array<string,mixed>> $compiledPages @param array<int,array<string,mixed>> $runtimeDeclarations @return array{documents:array<string,bool>,assets:array<string,bool>} */
    public static function runtimeOwnership(array $runtimeIslandPackage, array $compiledPages, array $runtimeDeclarations): array
    {
        $documents = array();
        $assets = array();
        $superseded = array();
        foreach ( $runtimeDeclarations as $declaration ) foreach ( $declaration['payload']['entities'] ?? array() as $entity ) foreach ( $entity['superseded_scripts'] ?? array() as $script ) {
            if ( is_array($script) && is_string($script['source_path'] ?? null) && is_string($script['selector'] ?? null) ) $superseded[$script['source_path'] . "\n" . $script['selector']] = true;
        }
        $package = $runtimeIslandPackage;
        $themeOwnedRequiredScripts = RuntimeIslandPackageBuilder::themeOwnedRequiredScriptOccurrences($package, $compiledPages);
        foreach ( $package['islands'] ?? array() as $island ) {
            if ( !is_array($island) ) continue;
            $sourcePath = ArrayField::string($island, 'source_path');
            $selector = ArrayField::string($island, 'selector');
            if ( isset($superseded[$sourcePath . "\n" . $selector]) ) continue;
            $drop = 'drop' === ($island['disposition'] ?? null);
            $carried = false;
            foreach ( $island['scripts'] ?? array() as $script ) {
                if ( !is_array($script) ) continue;
                $scriptSelector = ArrayField::string($script, 'selector');
                if ( isset($themeOwnedRequiredScripts[$sourcePath . "\n" . $scriptSelector]) ) continue;
                $scriptDropped = 'telemetry' === ($script['role'] ?? null);
                $scriptCarried = !$scriptDropped && is_string($script['content'] ?? null) && '' !== trim($script['content']);
                $carried = $carried || $scriptCarried;
                if ( ($drop || $scriptDropped || $scriptCarried) && is_string($script['resolved_path'] ?? null) ) $assets[$script['resolved_path']] = true;
                if ( ($drop || $scriptDropped || $scriptCarried) && '' !== $sourcePath && is_string($script['src'] ?? null) ) $documents[$sourcePath . "\n" . $script['src']] = true;
            }
            if ( ($drop || $carried) && '' !== $sourcePath && '' !== $selector ) $documents[$sourcePath . "\n" . $selector] = true;
        }
        return array('documents' => $documents, 'assets' => $assets);
    }

    /** @param array<int,array<string,mixed>> $documents @param array<string,bool> $owned @return array<int,array<string,mixed>> */
    public static function withoutOwned(array $documents, array $owned): array
    {
        foreach ( $documents as &$document ) {
            $sourcePath = ArrayField::string($document, 'source_path');
            if ( !is_array($document['document_metadata']['scripts'] ?? null) ) continue;
            $document['document_metadata']['scripts'] = array_values(array_filter(
                $document['document_metadata']['scripts'],
                static fn(mixed $script): bool => !is_array($script) || (!isset($owned[$sourcePath . "\n" . (string) ($script['selector'] ?? '')]) && !isset($owned[$sourcePath . "\n" . (string) ($script['url'] ?? '')]))
            ));
        }
        unset($document);
        return $documents;
    }

    /** @param array<int,array<string,mixed>> $documents @return array<string,bool> */
    public static function assets(array $documents): array
    {
        $assets = array();
        foreach ( $documents as $document ) {
            $sourcePath = ArrayField::string($document, 'source_path');
            foreach ( $document['document_metadata']['scripts'] ?? array() as $script ) {
                if ( !is_array($script) || !is_string($script['url'] ?? null) ) continue;
                $url = $script['url'];
                $resolved = ArtifactPath::resolveRelativePath($url, $sourcePath);
                if ( '' !== $resolved ) $assets[$resolved] = true;
                if ( str_starts_with($url, '/') && '.' !== dirname($sourcePath) ) $assets[trim(dirname($sourcePath), '/') . '/' . ltrim($url, '/')] = true;
            }
        }
        return $assets;
    }

    /** @param array<int,array<string,mixed>> $pages @param array<int,array<string,mixed>> $parts @param array<int,array<string,mixed>> $assets @param array<int,array<string,string>> $tokens @param array<int,array<string,mixed>> $operations @return array{scripts:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>} */
    public static function loading(array $pages, array $parts, array $assets, array $tokens, array $operations, array $runtimeDeclarations): array
    {
        $targets = array(); foreach ($tokens as $token) $targets[$token['token']] = $token['target_path'];
        $contents = array(); foreach ($assets as $asset) if (is_string($asset['content'] ?? null)) $contents[$asset['target_path']] = $asset['content'];
        $inlineTargets = array(); foreach ($assets as $asset) if ('inline-script' === ($asset['source'] ?? null) && is_string($asset['content'] ?? null)) $inlineTargets[WordPressSitePlan::contentHash($asset['content'])] = $asset['target_path'];
        $frontPages = array(); foreach ($operations as $operation) if ('site_reading' === ($operation['kind'] ?? null)) $frontPages[$operation['front_page_reconciliation_identity']] = true;
        $superseded = array();
        foreach ( $runtimeDeclarations as $declaration ) foreach ( $declaration['payload']['entities'] ?? array() as $entity ) foreach ( $entity['superseded_scripts'] ?? array() as $script ) if ( is_array($script) && is_string($script['source_path'] ?? null) && is_string($script['selector'] ?? null) && is_string($script['body_hash'] ?? null) && is_string($script['target_selector'] ?? null) ) $superseded[$script['source_path'] . "\n" . $script['selector'] . "\n" . $script['body_hash'] . "\n" . $script['target_selector']] = true;
        $scripts = array(); $diagnostics = array(); $instances = array();
        foreach (array_merge($pages, $parts) as $document) foreach ($document['document_metadata']['scripts'] ?? array() as $script) {
            $source = $document['source_path'] . '#' . ($script['order'] ?? '');
            $unsupported = static function (string $code, string $message) use (&$diagnostics, $source): void { $diagnostics[] = array('code' => $code, 'severity' => 'warning', 'message' => $message, 'source_path' => $source); };
            if (!is_array($script)) { $unsupported('wordpress_site_plan_script_invalid', 'Document script metadata is invalid.'); continue; }
            if (!self::isExecutableType((string) ($script['type'] ?? ''), true === ($script['module'] ?? false))) continue;
            $supersessionKey = $document['source_path'] . "\n" . ($script['selector'] ?? '') . "\n" . ($script['body_hash'] ?? '') . "\n" . ($script['superseded_by'] ?? '');
            if ( isset($superseded[$supersessionKey]) ) continue;
            // A form-runtime script (marked with a supersession target) that a
            // provider binding did not safely supersede must not be materialized
            // as an ordinary inline asset: its retained behavior (network or
            // global side effects) is exactly what made it ineligible for
            // supersession. Keep the plan not_proven so the materializer treats
            // the residual runtime island as unresolved rather than silently
            // shipping the unsafe handler.
            if ( '' !== (string) ($script['superseded_by'] ?? '') ) { $unsupported('wordpress_site_plan_script_form_runtime_unsuperseded', 'A form-runtime script was not safely superseded by a provider binding and cannot be materialized as a static inline asset.'); continue; }
            $localTarget = null;
            if ('inline' === ($script['source_kind'] ?? null)) { $localTarget = $inlineTargets[$script['body_hash'] ?? ''] ?? null; if (null === $localTarget) { $unsupported('wordpress_site_plan_script_inline_unbound', 'Inline document script metadata has no matching canonical asset.'); continue; } }
            if (true === ($script['module'] ?? false) && true === ($script['nomodule'] ?? false)) { $unsupported('wordpress_site_plan_script_module_nomodule_conflict', 'A document script cannot combine module and nomodule semantics.'); continue; }
            if (isset($document['placement']) && !in_array($document['placement']['kind'] ?? null, array('entry_shell', 'shared_shell'), true)) { $unsupported('wordpress_site_plan_script_unbound_template_part', 'A template-part script cannot be materialized because its template placement is unbound.'); continue; }
            $suffix = ''; $url = null;
            if (null === $localTarget) {
                if (is_string($script['asset_reference'] ?? null) && preg_match('/^\{\{wordpress-site-plan:asset:([^}]+)\}\}(.*)$/', $script['asset_reference'], $match) && isset($targets[$match[1]])) { $localTarget = $targets[$match[1]]; $suffix = $match[2]; }
                elseif (is_string($script['url'] ?? null) && preg_match('~^(?:https?:)?//[^\x00-\x20]+$~i', $script['url'])) { $url = $script['url']; $unsupported('wordpress_site_plan_script_external_unproven', 'An external script URL is emitted but cannot prove its runtime references without a declared local artifact.'); }
                else { $unsupported('wordpress_site_plan_script_url_unsupported', 'A document script must reference a declared local write or an absolute HTTP(S) URL.'); continue; }
            }
            if (null !== $localTarget && !CaptureEvidence::isRecognizedRuntime($contents[$localTarget] ?? '') && self::hasDynamicReferences($contents[$localTarget] ?? '')) { $unsupported('wordpress_site_plan_script_dynamic_references', 'A local script contains dynamic imports, script injection, or runtime URL construction that cannot be proven from the canonical write.'); continue; }
            $attributes = array('placement' => $script['placement'], 'local_target' => $localTarget, 'suffix' => $suffix, 'url' => $url, 'async' => $script['async'], 'defer' => $script['defer'], 'module' => $script['module'], 'nomodule' => $script['nomodule'], 'type' => $script['type'] ?? ($script['module'] ? 'module' : null), 'integrity' => $script['integrity'] ?? null, 'crossorigin' => $script['crossorigin'] ?? null, 'referrerpolicy' => $script['referrerpolicy'] ?? null, 'fetchpriority' => $script['fetchpriority'] ?? null);
            $scope = isset($document['placement']) ? array('kind' => 'global', 'order' => $script['order']) : array('kind' => 'post' === ($document['post_type'] ?? null) ? 'post' : 'page', 'source_path' => $document['source_path'], 'route_path' => trim($document['route']['path'], '/'), 'reconciliation_identity' => $document['reconciliation_identity'], 'front_page' => isset($frontPages[$document['reconciliation_identity']]), 'order' => $script['order']);
            $scopeKey = ($scope['kind'] ?? '') . ':' . ($scope['source_path'] ?? 'global');
            $signature = hash('sha256', serialize($attributes)); $instance = $instances[$scopeKey][$signature] ?? 0; $instances[$scopeKey][$signature] = $instance + 1;
            $identity = $signature . ':' . $instance;
            if (!isset($scripts[$identity])) $scripts[$identity] = array_merge(array('identity' => $identity, 'scopes' => array()), $attributes);
            $scripts[$identity]['scopes'][] = $scope;
        }
        return array('scripts' => array_values($scripts), 'diagnostics' => $diagnostics);
    }

    /** @internal Shared with document-head materialization. */
    public static function isExecutableType(string $type, bool $module): bool
    {
        $type = strtolower(trim($type));
        return $module || '' === $type || in_array($type, array('module', 'text/javascript', 'application/javascript', 'text/ecmascript', 'application/ecmascript'), true);
    }

    private static function hasDynamicReferences(string $content): bool { return preg_match('/\bimport\s*\(|\bdocument\s*\.\s*createElement\s*\(\s*["\']script|\.\s*src\s*=|\bsetAttribute\s*\(\s*["\']src(?:set)?["\']|\bnew\s+URL\s*\(/i', $content) === 1; }
}
