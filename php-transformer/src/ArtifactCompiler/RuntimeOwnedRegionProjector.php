<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use DOMElement;
use DOMXPath;

/** Replace capture-proven runtime roots before form discovery or DOM conversion. */
final class RuntimeOwnedRegionProjector
{
    private const MARKER = CaptureEvidence::RUNTIME_OWNER;

    /**
     * @param array<int,array<string,mixed>> $files
     * @return array{files:array<int,array<string,mixed>>,diagnostics:list<array<string,mixed>>}
     */
    public function project(array $files): array
    {
        $diagnostics = array();
        foreach ($files as &$file) {
            if (!ArtifactFileIndex::isMaterializedHtmlDocument($file) || !is_string($file['content'] ?? null)
                || !str_contains($file['content'], self::MARKER)) continue;
            $document = HtmlSerialization::parseDocument($file['content']);
            $xpath = new DOMXPath($document);
            $regions = array();
            foreach ($xpath->query('//script[@type="application/json"][@' . CaptureEvidence::SOURCE_EVIDENCE . '="' . CaptureEvidence::RUNTIME_OWNED_REGIONS_EVIDENCE_KEY . '"]') ?: array() as $script) {
                $evidence = json_decode($script->textContent, true);
                if (!is_array($evidence) || CaptureEvidence::RUNTIME_OWNED_REGIONS_SCHEMA !== ($evidence['schema'] ?? null)) continue;
                foreach (is_array($evidence['regions'] ?? null) ? $evidence['regions'] : array() as $region) {
                    if (is_array($region) && is_string($region['id'] ?? null) && '' !== $region['id']) $regions[$region['id']] = $region;
                }
            }
            $changed = false;
            $occurrence = 0;
            foreach (iterator_to_array($xpath->query('//*[@' . self::MARKER . ']') ?: array()) as $root) {
                if (!$root instanceof DOMElement || !$root->parentNode) continue;
                $region = $regions[$root->getAttribute(self::MARKER)] ?? null;
                if (null === $region) continue;
                $origin = $region['script']['origin'] ?? null;
                $host = is_string($origin) ? parse_url($origin, PHP_URL_HOST) : null;
                if (!is_string($host) || '' === $host || !in_array(parse_url($origin, PHP_URL_SCHEME), array('http', 'https'), true)) continue;
                // A nested marked root is covered by its outer handoff, not emitted twice.
                for ($parent = $root->parentNode; null !== $parent; $parent = $parent->parentNode) {
                    if ($parent instanceof DOMElement && $parent->hasAttribute(self::MARKER) && isset($regions[$parent->getAttribute(self::MARKER)])) continue 2;
                }
                $notice = $document->createElement('p');
                $notice->appendChild($document->createTextNode('This page embedded a form from ' . $host . '. Re-add it with the provider\'s embed code.'));
                $controlCount = $xpath->query('descendant-or-self::input | descendant-or-self::select | descendant-or-self::textarea | descendant-or-self::button', $root)->length;
                $sourceNodePath = $root->getNodePath();
                $root->parentNode->replaceChild($notice, $root);
                $diagnostics[] = array(
                    'code' => 'runtime_owned_region_handoff',
                    'severity' => 'warning',
                    'message' => $notice->textContent,
                    'source_path' => $file['path'],
                    'selector' => '[' . self::MARKER . '="' . $region['id'] . '"]',
                    'loss_class' => 'omitted_unit',
                    'unit' => 'runtime_owned_region',
                    'acceptability' => 'accepted_reported_loss',
                    'owning_layer' => 'blocks-engine',
                    'runtime_owner' => $region['id'],
                    'source_node_path' => $sourceNodePath,
                    'occurrence' => ++$occurrence,
                    'script' => $region['script'],
                    'roots' => $region['roots'] ?? array(),
                    'control_count' => $controlCount,
                );
                $changed = true;
            }
            if ($changed) $file['content'] = $document->saveHTML();
        }
        unset($file);
        return array('files' => $files, 'diagnostics' => $diagnostics);
    }
}
