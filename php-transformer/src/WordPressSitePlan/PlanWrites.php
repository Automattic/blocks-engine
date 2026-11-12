<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use InvalidArgumentException;

/**
 * The shape of one file write in a site plan.
 *
 * A write names its kind, source and target paths, a reconciliation identity
 * derived from them, and a payload with its hash. The payload is inline UTF-8
 * or base64 data, or a reference to stored bytes carrying their raw SHA-256.
 */
final class PlanWrites
{
    /** @return array<string,mixed> */
    public static function inline(string $kind, string $target, string $content, ?string $sourcePath = null, string $encoding = 'utf8'): array { $sourcePath ??= 'wordpress-site-plan/' . $target; return array('kind' => $kind, 'source_path' => $sourcePath, 'target_path' => $target, 'reconciliation_identity' => WordPressSitePlan::identity('write', $sourcePath, $target), 'payload_hash' => WordPressSitePlan::contentHash($content), 'payload' => array('encoding' => $encoding, 'data' => $content)); }

    /** @param array{schema:string,id:string,bytes:int,sha256:string} $reference */
    public static function reference(string $kind, string $target, string $sourcePath, array $reference): array { return array('kind' => $kind, 'source_path' => $sourcePath, 'target_path' => $target, 'reconciliation_identity' => WordPressSitePlan::identity('write', $sourcePath, $target), 'payload_hash' => WordPressSitePlan::contentHash(RuntimeDeclarations::canonicalJson($reference)), 'raw_sha256' => $reference['sha256'], 'payload' => array('encoding' => 'reference', 'reference' => $reference)); }

    /** @param array<string,string> $tokens */
    public static function assertValid(mixed $write, array $tokens, bool $browserReferences): void { if (!is_array($write) || !is_string($write['kind'] ?? null) || !WordPressSitePlan::safePath($write['source_path'] ?? null) || !WordPressSitePlan::safePath($write['target_path'] ?? null) || !WordPressSitePlan::hash($write['reconciliation_identity'] ?? null) || !WordPressSitePlan::hash($write['payload_hash'] ?? null) || !is_array($write['payload'] ?? null) || !in_array($write['payload']['encoding'] ?? null, array('utf8','base64','reference'), true) || $write['reconciliation_identity'] !== WordPressSitePlan::identity('write', $write['source_path'], $write['target_path'])) throw new InvalidArgumentException('WordPress site plan write has a stale payload hash or invalid structure.'); if ('reference' === $write['payload']['encoding']) { $reference = CompiledAssets::payloadReference($write['payload']['reference'] ?? null); if (!is_array($reference) || ($write['raw_sha256'] ?? null) !== $reference['sha256'] || $write['payload_hash'] !== WordPressSitePlan::contentHash(RuntimeDeclarations::canonicalJson($reference))) throw new InvalidArgumentException('WordPress site plan write has an invalid payload reference.'); return; } if (!is_string($write['payload']['data'] ?? null) || $write['payload_hash'] !== WordPressSitePlan::contentHash($write['payload']['data'])) throw new InvalidArgumentException('WordPress site plan write has a stale payload hash or invalid structure.'); if ('base64' === $write['payload']['encoding'] && false === base64_decode($write['payload']['data'], true)) throw new InvalidArgumentException('WordPress site plan write has invalid base64 payload.'); if ('utf8' === $write['payload']['encoding']) { WordPressSitePlan::assertTokens($write['payload']['data'], $tokens); if ($browserReferences && !LocalBrowserReferences::isVerbatimWrite($write['kind'], $write['target_path'])) LocalBrowserReferences::assertNoneInWrite($write['target_path'], $write['payload']['data'], $write['source_path']); } }
}
