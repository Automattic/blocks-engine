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
            if (isset($writes[$write['target_path']])) throw new InvalidArgumentException('Receipt has colliding writes.');
            $writes[$write['target_path']] = true;
        }
        $declaredWrites = array();
        foreach ($plan['writes'] ?? array() as $write) $declaredWrites[$write['target_path'] ?? ''] = true;
        if ($writes !== $declaredWrites) throw new InvalidArgumentException('Receipt writes do not match declared writes.');
        $receiptPages = array();
        foreach ($receipt['pages'] ?? array() as $page) if (is_array($page) && is_string($page['reconciliation_identity'] ?? null)) { if (isset($receiptPages[$page['reconciliation_identity']])) throw new InvalidArgumentException('Receipt has colliding pages.'); $receiptPages[$page['reconciliation_identity']] = true; }
        $documents = array();
        $declaredPages = array();
        foreach ($plan['pages'] ?? array() as $page) {
            if (!is_array($page) || !isset($receiptPages[$page['reconciliation_identity'] ?? ''])) throw new InvalidArgumentException('Receipt omits a resolved page.');
            $declaredPages[$page['reconciliation_identity']] = true;
            $documents[] = array('source_path' => $page['source_path'], 'title' => $page['document_metadata']['title'], 'metadata' => $page['document_metadata']);
        }
        if ($receiptPages !== $declaredPages) throw new InvalidArgumentException('Receipt pages do not match resolved pages.');
        return array('documents' => $documents, 'reporting' => $plan['reporting'], 'write_count' => count($writes));
    }
}
