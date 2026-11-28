<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;

/** Canonical, content-cached reader; never rewrites the captured sidecar. */
final class CapturedInteractionReport
{
    /** Keep a bounded cache across projector instances and repeated route work. */
    private static array $cache = array();

    /** Same four-entry bound as decoding; keys retain hashes, not page HTML. */
    private static array $resolvedCache = array();

    public static function read(array $files): ?array
    {
        $report = self::decoded($files);
        if (!self::valid($report)) return $report;
        $key = CapturedComponentStates::inputKey($report, $files);
        if (array_key_exists($key, self::$resolvedCache)) return self::$resolvedCache[$key];
        $report = CapturedComponentStates::shareEvidence($report, $files);
        self::cleanMarkup($report);
        if (4 <= count(self::$resolvedCache)) array_shift(self::$resolvedCache);
        return self::$resolvedCache[$key] = $report;
    }

    private static function decoded(array $files): ?array
    {
        foreach ($files as $file) {
            if (!is_string($file['path'] ?? null) || 'interaction-states.json' !== basename($file['path'])) continue;
            $content = $file['content'] ?? null;
            if (!is_string($content)) return null;
            $key = hash('sha256', $content);
            if (!array_key_exists($key, self::$cache)) {
                if (4 <= count(self::$cache)) array_shift(self::$cache);
                self::$cache[$key] = self::decode($content);
            }
            return self::$cache[$key];
        }
        return null;
    }

    public static function valid(?array $report): bool
    {
        return null !== $report
            && in_array($report['schema'] ?? null, array(CaptureEvidence::CAPTURED_INTERACTIONS_V2_SCHEMA, CaptureEvidence::CAPTURED_INTERACTIONS_SCHEMA), true)
            && is_array($report['pages'] ?? null);
    }

    /** Reader warnings depend only on decode/reference expansion, never on shared HTML evidence. */
    public static function diagnostics(array $files): array
    {
        return array_merge(CaptureReceiptReport::diagnostics($files), self::decoded($files)['_reader_diagnostics'] ?? array());
    }

    /** Merge projector findings, reporting the shared reader warning only once. */
    public static function mergeDiagnostics(array ...$groups): array
    {
        $result = array();
        $readerSeen = array();
        foreach ($groups as $group) foreach ($group as $diagnostic) {
            $code = $diagnostic['code'] ?? '';
            if (in_array($code, array('captured_interaction_ref_unresolved', 'capture_receipt_rejected'), true)) {
                if (isset($readerSeen[$code])) continue;
                $readerSeen[$code] = true;
            }
            $result[] = $diagnostic;
        }
        return $result;
    }

    /** Clean all captured HTML shards, including nested semantic evidence and snapshots.
     * The original sidecar stays untouched; only markup consumed by projectors is cleaned.
     */
    private static function cleanMarkup(array &$evidence, ?string $sourceUrl = null): void
    {
        $sourceUrl = is_string($evidence['sourceUrl'] ?? null) ? $evidence['sourceUrl'] : $sourceUrl;
        foreach ($evidence as $key => &$value) {
            if (is_array($value)) {
                self::cleanMarkup($value, $sourceUrl);
            } elseif (is_string($key) && is_string($value) && str_ends_with(strtolower($key), 'html')) {
                $corroborated = ($evidence[$key . 'Bytes'] ?? null) === strlen($value);
                if (false !== stripos($value, \Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree::NODE_ATTRIBUTE)) {
                    $document = \Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization::parseFragment('<div>' . $value . '</div>');
                    $root = $document->documentElement;
                    if ($root instanceof \DOMElement) {
                        \Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree::removeEvidenceAttributes($root);
                        $html = '';
                        foreach ($root->childNodes as $child) $html .= $document->saveHTML($child);
                        $value = $html;
                        unset($html, $child);
                    }
                    unset($root, $document);
                }
                if (null !== $sourceUrl) $value = CapturedStateUrls::html($value, $sourceUrl);
                // Do not turn invalid capture sizes into valid evidence during cleanup.
                if ($corroborated) $evidence[$key . 'Bytes'] = strlen($value);
            }
        }
        unset($value);
    }

    private static function decode(string $content): ?array
    {
        $report = json_decode($content, true);
        if (!is_array($report)) return null;
        $report['_reader_diagnostics'] = array();
        if (!self::valid($report)) return $report;
        $missing = array();
        foreach ($report['pages'] as &$page) {
            if (!is_array($page) || !is_array($page['components'] ?? null)) continue;
            $components = array();
            foreach ($page['components'] as $component) {
                if (is_array($component) && array_key_exists('ref', $component)) {
                    $ref = $component['ref'];
                    $shared = is_string($ref) ? ($report['sharedComponents'][$ref] ?? null) : null;
                    if (!is_array($shared) || array_key_exists('ref', $shared)) {
                        $missing[] = array('source_url' => $page['sourceUrl'] ?? null, 'ref' => $ref);
                        continue;
                    }
                    $component = $shared;
                }
                $components[] = $component;
            }
            $page['components'] = $components;
        }
        unset($page);
        if ($missing) {
            $report['_reader_diagnostics'][] = array(
                'code' => 'captured_interaction_ref_unresolved',
                'severity' => 'warning',
                'message' => 'Unresolved captured interaction references were skipped.',
                'source' => self::class,
                'context' => array('references' => $missing),
            );
        }
        return $report;
    }
}
