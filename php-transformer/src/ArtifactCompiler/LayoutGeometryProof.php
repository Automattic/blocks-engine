<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

/** Validates optional, producer-neutral evidence for a topology reduction. */
final class LayoutGeometryProof
{
    public const SCHEMA = 'blocks-engine/php-transformer/layout-geometry-proof/v1';
    private const MAX_NODES = 256;
    private const MAX_VIEWPORTS = 8;
    private const MAX_DECLARATIONS = 32;

    /** @param array<string,mixed>|null $proof @param array<int,array<string,mixed>> $files @return array{proof:array<string,mixed>,diagnostics:array<int,array<string,mixed>>} */
    public static function normalize(?array $proof, array $files): array
    {
        if (null === $proof) return array('proof' => array(), 'diagnostics' => array());
        if (self::SCHEMA !== ($proof['schema'] ?? null)) return self::rejected('layout_geometry_proof_schema_invalid');
        $nodes = $proof['nodes'] ?? null;
        $reductions = $proof['reductions'] ?? null;
        if (!is_array($nodes) || !array_is_list($nodes) || !is_array($reductions) || !array_is_list($reductions) || count($nodes) > self::MAX_NODES || count($reductions) > self::MAX_NODES) return self::rejected('layout_geometry_proof_bounds_invalid');
        $hashes = array();
        foreach ($files as $file) if ('html' === ($file['kind'] ?? null)) $hashes[(string) $file['path']] = (string) ($file['provenance']['hash'] ?? '');
        $validNodes = array();
        foreach ($nodes as $node) {
            if (!is_array($node) || !is_string($node['id'] ?? null) || !preg_match('/^[A-Za-z0-9._:-]{1,80}$/', $node['id']) || isset($validNodes[$node['id']]) || !self::nodeIsCurrent($node, $hashes)) return self::rejected('layout_geometry_proof_identity_stale');
            if (!self::geometryIsValid($node['boxes'] ?? null)) return self::rejected('layout_geometry_proof_geometry_unproven');
            $validNodes[$node['id']] = $node;
        }
        $valid = array();
        foreach ($reductions as $reduction) {
            if (!is_array($reduction) || !is_string($reduction['wrapper'] ?? null) || !is_string($reduction['target'] ?? null) || !isset($validNodes[$reduction['wrapper']], $validNodes[$reduction['target']])) return self::rejected('layout_geometry_proof_reduction_incomplete');
            $wrapper = $validNodes[$reduction['wrapper']];
            $target = $validNodes[$reduction['target']];
            if ($wrapper['source_path'] !== $target['source_path'] || $wrapper['source_hash'] !== $target['source_hash'] || !self::geometryIsEquivalent($target['boxes']) || !self::invariantsHold($reduction['invariants'] ?? null) || !self::correctiveCss($reduction['corrective_css'] ?? null)) return self::rejected('layout_geometry_proof_reduction_incomplete');
            $valid[] = array(
                'source_path' => $wrapper['source_path'],
                'source_hash' => $wrapper['source_hash'],
                'wrapper_selector' => $wrapper['selector'],
                'target_selector' => $target['selector'],
                'corrective_css' => $reduction['corrective_css'],
            );
        }
        return array('proof' => array('schema' => self::SCHEMA, 'reductions' => $valid), 'diagnostics' => array());
    }

    /** @param array<string,mixed> $node @param array<string,string> $hashes */
    private static function nodeIsCurrent(array $node, array $hashes): bool
    {
        return is_string($node['source_path'] ?? null) && is_string($node['source_hash'] ?? null) && ($hashes[$node['source_path']] ?? null) === $node['source_hash'] && is_string($node['selector'] ?? null) && preg_match('/^[a-z][a-z0-9-]*(?::nth-of-type\([1-9][0-9]*\))(?: > [a-z][a-z0-9-]*(?::nth-of-type\([1-9][0-9]*\)))*$/', $node['selector']);
    }

    private static function geometryIsValid(mixed $boxes): bool
    {
        if (!is_array($boxes) || !array_is_list($boxes) || count($boxes) < 1 || count($boxes) > self::MAX_VIEWPORTS) return false;
        $viewports = array();
        foreach ($boxes as $box) {
            if (!is_array($box) || !is_int($box['viewport'] ?? null) || $box['viewport'] < 1 || $box['viewport'] > 10000 || isset($viewports[$box['viewport']]) || !is_string($box['state'] ?? null) || '' === $box['state'] || !self::isBox($box['source'] ?? null) || !self::isBox($box['simulated'] ?? null)) return false;
            $viewports[$box['viewport']] = true;
        }
        return true;
    }

    private static function geometryIsEquivalent(mixed $boxes): bool
    {
        if (!self::geometryIsValid($boxes)) return false;
        foreach ($boxes as $box) if (!self::sameBox($box['source'], $box['simulated'])) return false;
        return true;
    }

    private static function invariantsHold(mixed $invariants): bool
    {
        return is_array($invariants) && true === ($invariants['selectors'] ?? null) && true === ($invariants['runtime'] ?? null) && true === ($invariants['semantics'] ?? null) && true === ($invariants['viewports'] ?? null);
    }

    private static function correctiveCss(mixed $css): bool
    {
        if (!is_array($css) || !is_array($css['declarations'] ?? null) || !array_is_list($css['declarations']) || count($css['declarations']) > self::MAX_DECLARATIONS) return false;
        foreach ($css['declarations'] as $declaration) {
            if (!is_array($declaration) || !is_string($declaration['property'] ?? null) || !preg_match('/^(?:--[a-z0-9_-]+|[a-z-]+)$/i', $declaration['property']) || !is_string($declaration['value'] ?? null) || '' === trim($declaration['value']) || strlen($declaration['value']) > 512 || preg_match('~[{}<>;]|/\*~', $declaration['value'])) return false;
        }
        return true;
    }

    private static function sameBox(mixed $left, mixed $right): bool
    {
        if (!self::isBox($left) || !self::isBox($right)) return false;
        foreach (array('x', 'y', 'width', 'height') as $key) if (abs((float) $left[$key] - (float) $right[$key]) > 1.0) return false;
        return true;
    }

    private static function isBox(mixed $box): bool
    {
        if (!is_array($box)) return false;
        foreach (array('x', 'y', 'width', 'height') as $key) if (!is_numeric($box[$key] ?? null)) return false;
        return true;
    }

    /** @return array{proof:array<string,mixed>,diagnostics:array<int,array<string,mixed>>} */
    private static function rejected(string $code): array
    {
        return array('proof' => array(), 'diagnostics' => array(array('code' => $code, 'severity' => 'warning', 'source' => self::class, 'message' => 'Optional layout geometry evidence was ignored because it is incomplete, stale, or contradictory.')));
    }
}
