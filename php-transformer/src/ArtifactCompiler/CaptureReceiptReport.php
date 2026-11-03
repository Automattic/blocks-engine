<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;

/** Canonical content-cached receipt reader; report budgets belong to the host. */
final class CaptureReceiptReport
{
    private static array $cache = array();

    public static function read(array $files): ?array
    {
        return self::result($files)['receipt'];
    }

    public static function diagnostics(array $files): array
    {
        return self::result($files)['diagnostics'];
    }

    /** Receipts removed at the host boundary must remain visible as losses. */
    public static function normalizationDiagnostics(array $diagnostics): array
    {
        $result = array();
        foreach ($diagnostics as $diagnostic) {
            $context = $diagnostic['context'] ?? array();
            if (!is_string($context['path'] ?? null) || 'capture-receipt.json' !== basename($context['path'])) continue;
            if (!str_contains($diagnostic['code'] ?? '', 'too_large')) continue;
            $result[] = array(
                'code' => 'capture_receipt_rejected',
                'severity' => 'error',
                'message' => sprintf('Rejected %s (%d bytes): %s; captured interactive controls cannot be reproduced.', $context['path'], $context['bytes'] ?? 0, $diagnostic['code']),
                'source' => self::class,
                'context' => $context,
            );
        }
        return $result;
    }

    private static function result(array $files): array
    {
        foreach ($files as $file) {
            $path = $file['path'] ?? null;
            if (!is_string($path) || 'capture-receipt.json' !== basename($path)) continue;
            $content = $file['content'] ?? null;
            $bytes = is_string($content) ? strlen($content) : (int) ($file['bytes'] ?? 0);
            $key = $path . ':' . (is_string($content) ? hash('sha256', $content) : 'missing:' . $bytes);
            if (array_key_exists($key, self::$cache)) return self::$cache[$key];
            $receipt = null;
            $reason = 'content_unavailable';
            if (is_string($content)) {
                $reason = 'report_budget_exceeded';
                if ($bytes <= ArtifactNormalizer::MAX_REPORT_FILE_BYTES) {
                    $receipt = json_decode($content, true);
                    $reason = 'invalid_json';
                    if (is_array($receipt)) {
                        $reason = 'invalid_schema';
                        if (CaptureEvidence::CAPTURE_RECEIPT_SCHEMA === ($receipt['schema'] ?? null)
                            && (!array_key_exists('routes', $receipt) || is_array($receipt['routes']))) {
                            $reason = '';
                        }
                    }
                }
            }
            $diagnostics = array();
            if ('' !== $reason) {
                $receipt = null;
                $diagnostics[] = array(
                    'code' => 'capture_receipt_rejected',
                    'severity' => 'error',
                    'message' => sprintf('Rejected %s (%d bytes): %s; captured interactive controls cannot be reproduced.', $path, $bytes, $reason),
                    'source' => self::class,
                    'context' => array('path' => $path, 'bytes' => $bytes, 'reason' => $reason, 'max_bytes' => ArtifactNormalizer::MAX_REPORT_FILE_BYTES),
                );
            }
            if (4 <= count(self::$cache)) array_shift(self::$cache);
            return self::$cache[$key] = array('receipt' => $receipt, 'diagnostics' => $diagnostics);
        }
        return array('receipt' => null, 'diagnostics' => array());
    }
}
