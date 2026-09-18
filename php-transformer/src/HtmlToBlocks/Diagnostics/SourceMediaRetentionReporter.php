<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics;

use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionFindingContract;

/**
 * Reverse-direction media retention check: every source image must still be
 * addressed by the output.
 *
 * {@see ContentRoundTripReporter} runs deliberately one-directional, output ⊆
 * source, to catch invented copy. Nothing ran the other way for media, and the
 * gap is not theoretical. A conversion that lowered media cards to a control
 * reduced each `<img>` to its `alt` text: six of eight images left the page as
 * run-on sentences, and every quality counter stayed at zero because nothing
 * had been *dropped*. The counters model absence. This models demotion —
 * content that survives only as a strictly weaker representation.
 *
 * The check is by address, not by count, because an image legitimately arrives
 * in several shapes: an `<img>`, a `srcset` candidate, a `core/cover` or
 * `core/media-text` attribute, or a CSS `background-image` on a carrier. All of
 * them name the file. So a source image is retained when its address appears
 * anywhere the output can reference it, and lost only when the address appears
 * nowhere at all — which no reshaping of the block tree can cause.
 *
 * Pure: no DOM, no I/O, no global state; the same inputs always yield the same
 * report.
 */
final class SourceMediaRetentionReporter
{
    public const SCHEMA = 'blocks-engine/php-transformer/source-media-retention/v1';

    /**
     * Maximum stored length of a finding's offending address.
     */
    private const MAX_SNIPPET = 200;

    /**
     * @param array<int, string> $outputHaystacks Everything the output can name a
     *        file from: serialized blocks, plus any generated stylesheets whose
     *        `background-image` declarations carry an address.
     */
    public function evaluate(string $sourceHtml, string $serializedBlocks, array $outputHaystacks = array()): SourceMediaRetentionEvaluation
    {
        $haystack = $serializedBlocks;
        foreach ( $outputHaystacks as $extra ) {
            $haystack .= "\n" . $extra;
        }

        $findings = array();
        foreach ( $this->sourceImageAddresses($sourceHtml) as $address ) {
            if ( str_contains($haystack, $address) ) {
                continue;
            }

            $findings[] = ConversionFindingContract::withClassification(array(
                'code'     => 'source_media_not_in_output',
                'severity' => 'error',
                'text'     => substr($address, 0, self::MAX_SNIPPET),
                'summary'  => 'A source image is not addressed anywhere in the converted output.',
            ));
        }

        return new SourceMediaRetentionEvaluation($findings);
    }

    /** @return array<string, mixed> */
    public function report(string $sourceHtml, string $serializedBlocks, array $outputHaystacks = array()): array
    {
        return $this->evaluate($sourceHtml, $serializedBlocks, $outputHaystacks)->report();
    }

    /**
     * Distinct addressable file names the source's images resolve to.
     *
     * The comparison uses the file name rather than the whole URL: an importer
     * rewrites `/media/x.jpg` to a theme-relative or uploads path, and only the
     * name survives that intact. Data URIs and SVG sprites name no file and are
     * skipped — they cannot be looked up, so their absence proves nothing.
     *
     * @return array<int, string>
     */
    private function sourceImageAddresses(string $sourceHtml): array
    {
        if ( ! preg_match_all('/<img\b[^>]*>/i', $sourceHtml, $tags) ) {
            return array();
        }

        $addresses = array();
        foreach ( $tags[0] as $tag ) {
            if ( ! preg_match('/\bsrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $match) ) {
                continue;
            }
            $url = trim($match[1] ?? '') . trim($match[2] ?? '') . trim($match[3] ?? '');
            $name = $this->addressableFileName($url);
            if ( '' !== $name ) {
                $addresses[ $name ] = true;
            }
        }

        return array_keys($addresses);
    }

    /**
     * The file name a URL addresses, or '' when it addresses no retrievable file.
     */
    private function addressableFileName(string $url): string
    {
        $url = trim($url);
        if ( '' === $url || str_starts_with(strtolower($url), 'data:') ) {
            return '';
        }

        $path = (string) preg_replace('/[?#].*$/', '', $url);
        $name = basename($path);
        if ( '' === $name || ! str_contains($name, '.') ) {
            return '';
        }

        return $name;
    }
}
