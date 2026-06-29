<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\VisualParity;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

/**
 * Render-free end-to-end static visual-parity runner.
 *
 * Given a SOURCE document and its author CSS, runs the deterministic transform
 * pipeline (HTML -> blocks) to produce the CANDIDATE, then compares the two with
 * {@see StaticStyleParityProbe} + {@see StaticStyleParityComparator} under the
 * SAME author CSS so the only variable is the transformed DOM. Pure PHP: no
 * browser, no screenshots, no network. Same inputs -> byte-identical report.
 *
 * This is the deterministic replacement for full-page pixelmatch as the primary
 * parity signal: it answers "after the transform, does the same effective
 * styling still apply to the same content?" and names the exact CSS property on
 * the exact selector that diverged.
 */
final class StaticStyleParityRunner
{
    private HtmlTransformer $transformer;
    private StaticStyleParityProbe $probe;
    private StaticStyleParityComparator $comparator;

    public function __construct(
        ?HtmlTransformer $transformer = null,
        ?StaticStyleParityProbe $probe = null,
        ?StaticStyleParityComparator $comparator = null
    ) {
        $this->transformer = $transformer ?? new HtmlTransformer();
        $this->probe = $probe ?? new StaticStyleParityProbe();
        $this->comparator = $comparator ?? new StaticStyleParityComparator();
    }

    /**
     * Compare a source document against its own transform output.
     *
     * @param string $sourceHtml Source HTML document.
     * @param string $authorCss  Author CSS (inline <style> + linked stylesheets)
     *                           carried to both sides for a fair comparison. When
     *                           empty, each document's own <style> blocks are used.
     * @return array<string, mixed> VisualParityReportContract report.
     */
    public function compareSourceToTransform(string $sourceHtml, string $authorCss = ''): array
    {
        $result = $this->transformer->transform($sourceHtml, array())->toArray();
        $candidateHtml = self::candidateHtmlFromSerializedBlocks((string) ($result['serialized_blocks'] ?? ''));

        $sourceProbes = $this->probe->extract($sourceHtml, $authorCss);
        $candidateProbes = $this->probe->extract($candidateHtml, $authorCss);

        return $this->comparator->compare($sourceProbes, $candidateProbes);
    }

    /**
     * Convert serialized WordPress block markup into render-free candidate HTML by
     * stripping the block delimiter comments and keeping the saved inner markup
     * (which preserves the transformer's class -> className and semantic tags).
     */
    public static function candidateHtmlFromSerializedBlocks(string $serializedBlocks): string
    {
        return preg_replace('/<!--\s*\/?wp:[^>]*-->/', '', $serializedBlocks) ?? $serializedBlocks;
    }
}
