<?php
declare(strict_types=1);

/** A downstream-shaped projection that accepts only a resolved public plan and receipt. */
final class ResolvedPlanProjection
{
    /** @param array<string,mixed> $plan @param array<string,mixed> $receipt @return array<string,mixed> */
    public static function fromPlanAndReceipt(array $plan, array $receipt): array
    {
        $writes = array();
        foreach ($receipt['writes'] ?? array() as $write) {
            if (!is_array($write) || 'written' !== ($write['status'] ?? null) || !is_string($write['target_path'] ?? null)) throw new InvalidArgumentException('Receipt write is invalid.');
            $writes[$write['target_path']] = true;
        }
        foreach ($plan['writes'] ?? array() as $write) if (!isset($writes[$write['target_path'] ?? ''])) throw new InvalidArgumentException('Receipt omits a declared write.');
        $receiptPages = array();
        foreach ($receipt['pages'] ?? array() as $page) if (is_array($page) && is_string($page['reconciliation_identity'] ?? null)) $receiptPages[$page['reconciliation_identity']] = true;
        $documents = array();
        foreach ($plan['pages'] ?? array() as $page) {
            if (!is_array($page) || !isset($receiptPages[$page['reconciliation_identity'] ?? ''])) throw new InvalidArgumentException('Receipt omits a resolved page.');
            $documents[] = array('source_path' => $page['source_path'], 'title' => $page['document_metadata']['title'], 'metadata' => $page['document_metadata']);
        }
        return array('documents' => $documents, 'reporting' => $plan['reporting'], 'write_count' => count($writes));
    }
}
