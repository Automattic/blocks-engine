<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use DOMDocument;

/** State-owned probe evidence from the v2 component inventory. */
final class CapturedComponentStates
{
    /** Retire only an exact recognized helper whose complete inventory was projected. */
    public static function retireRuntime(DOMDocument $document, string $marker, array $selectors): array
    {
        $retired = array();
        foreach (iterator_to_array($document->getElementsByTagName('script')) as $script) {
            if (!$script->hasAttribute($marker)) continue;
            $body = trim($script->textContent);
            $recognized = false;
            if (CaptureEvidence::COMPONENT_RUNTIME === $marker) {
                $recognized = '36922bf2813c545f39a0e9c559c72165e219e59185f477d6f3e207ecfe612aed' === hash('sha256', $body);
                foreach ($document->getElementsByTagName('*') as $root) {
                    if ($root->hasAttribute(CaptureEvidence::TABS) && 'true' !== $root->parentNode?->attributes?->getNamedItem('data-blocks-engine-captured-selectable-set')?->nodeValue) $recognized = false;
                }
            } elseif (CaptureEvidence::COMPONENT_CAROUSEL_RUNTIME === $marker && str_starts_with($body, '(function(){var configs=')) {
                $end = strpos($body, ';configs.forEach');
                if (false !== $end && 'fa1521586b999b80142ed3737550f073df0fbea75c6b2a1e9111bb7ce726d3c1' === hash('sha256', substr($body, $end))) {
                    $configs = json_decode(substr($body, strlen('(function(){var configs='), $end - strlen('(function(){var configs=')), true);
                    $recognized = is_array($configs) && array() !== $configs;
                    foreach ($configs ?? array() as $config) if (!in_array($config['selector'] ?? null, $selectors, true)) $recognized = false;
                }
            }
            if (!$recognized) continue;
            $retired[] = array('body' => $body, 'attribute' => $marker, 'reason' => 'native_component_replaces_capture_replay');
            $script->parentNode?->removeChild($script);
        }
        return $retired;
    }

    /** Remove the matching normalized inline assets and record replacement evidence. */
    public static function omitScripts(array &$files, array $retired): array
    {
        $proofs = array();
        $kept = array();
        foreach (ArtifactNormalizer::inlineOccurrences($files) as $file) {
            $source = (string) ($file['source_path'] ?? '');
            $record = null;
            foreach ($retired[$source] ?? array() as $candidate) if (trim((string) ($file['content'] ?? '')) === $candidate['body']) $record = $candidate;
            if (null === $record || 'inline-script' !== ($file['source'] ?? null)) { $kept[] = $file; continue; }
            $proofs[] = array('schema' => 'blocks-engine/native-runtime-replacement/v1', 'source_path' => $source,
                'asset_source_path' => $file['path'], 'body_hash' => hash('sha256', $record['body']),
                'attribute' => $record['attribute'], 'reason' => $record['reason']);
        }
        $files = (new ArtifactNormalizer())->contentAddressInlineFiles($kept);
        return $proofs;
    }

    /** @return array<int, array<string, mixed>> */
    public static function evidence(array $components, array $kinds): array
    {
        $result = array();
        foreach ($components as $component) {
            if ('slideshow' === ($component['function'] ?? null)) continue;
            if (!is_array($component) || !in_array($component['kind'] ?? null, $kinds, true)
                || !in_array($component['status'] ?? null, array('captured', 'partial'), true)
                || !is_array($component['states'] ?? null)) {
                continue;
            }
            foreach ($component['states'] as $state) {
                if (is_array($state) && is_array($state['evidence'] ?? null)) {
                    $result[] = $state['evidence'];
                }
            }
        }
        return $result;
    }
}
