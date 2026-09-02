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
        $result = $this->transformer->transform($sourceHtml, array( 'static_css' => $authorCss ))->toArray();
        $candidateHtml = self::candidateHtmlFromSerializedBlocks((string) ($result['serialized_blocks'] ?? ''));
        $candidateCss = '';
        foreach ($result['assets'] ?? array() as $asset) {
            if (! is_array($asset) || 'css' !== ($asset['kind'] ?? '') || ! is_string($asset['content'] ?? null)) {
                continue;
            }
            $candidateCss .= "\n" . $asset['content'];
        }

        // The render-free proxy carries the SAME author CSS to both sides so the
        // only variable is the transformed DOM. Candidate CSS comes from the
        // generated asset itself, including geometry carriers and author CSS.
        return $this->compareSourceToCandidate($sourceHtml, $candidateHtml, $authorCss, $candidateCss);
    }

    /**
     * Return the fixed v1 signal alongside a separately versioned geometry score.
     *
     * @return array{static_v1: array<string, mixed>, geometry_v2: array<string, mixed>}
     */
    public function compareSourceToTransformWithGeometry(string $sourceHtml, string $authorCss = ''): array
    {
        $result = $this->transformer->transform($sourceHtml, array( 'static_css' => $authorCss ))->toArray();
        $candidateHtml = self::candidateHtmlFromSerializedBlocks((string) ($result['serialized_blocks'] ?? ''));
        $candidateCss = '';
        foreach ($result['assets'] ?? array() as $asset) {
            if (is_array($asset) && 'css' === ($asset['kind'] ?? '') && is_string($asset['content'] ?? null)) {
                $candidateCss .= "\n" . $asset['content'];
            }
        }

        return array(
            'static_v1' => $this->compareSourceToCandidate($sourceHtml, $candidateHtml, $authorCss, $candidateCss),
            'geometry_v2' => (new StaticStyleParityComparator())->compare(
                (new StaticStyleParityProbe(true))->extract($sourceHtml, $authorCss),
                (new StaticStyleParityProbe(true))->extract($candidateHtml, $candidateCss)
            ),
        );
    }

    /**
     * Compare a source document against an EXTERNALLY produced candidate document.
     *
     * Unlike {@see compareSourceToTransform}, which builds the candidate render-free
     * from serialized blocks, this entry accepts a candidate HTML string produced by
     * a real WordPress render (e.g. the DOM HTML wp-codebox fetches after SSI import
     * + activate). It runs the IDENTICAL deterministic probe + comparator so the
     * report contract, score, and per-property diff are exactly the same shape as the
     * render-free gate — only the candidate's provenance differs.
     *
     * This exercises WordPress's own block rendering + global-styles layer (the layer
     * the render-free proxy cannot see) while staying fully deterministic: no browser,
     * no rasterization, no network, no screenshots. The candidate's effective styling
     * is resolved statically from whatever CSS the rendered DOM already carries
     * (WP global-styles / block-supports / layout `<style>` blocks) plus any explicit
     * $candidateCss the caller inlined; nothing is fetched.
     *
     * The source and candidate take SEPARATE CSS inputs on purpose: the source side
     * carries the fixture's own author CSS, while the live-WP candidate must be judged
     * solely on the styling WordPress actually emitted — feeding the source's author
     * CSS to the candidate would mask exactly the rendering/global-styles regressions
     * this variant exists to catch.
     *
     * @param string $sourceHtml    Source HTML document.
     * @param string $candidateHtml Candidate HTML document (e.g. live-WP rendered DOM).
     * @param string $sourceCss     Author CSS merged into the source-side cascade.
     * @param string $candidateCss  Extra CSS merged into the candidate-side cascade.
     *                              Defaults to empty: a self-contained rendered DOM
     *                              already carries its own <style> blocks.
     * @return array<string, mixed> VisualParityReportContract report (same contract
     *                              as {@see compareSourceToTransform}).
     */
    public function compareSourceToCandidate(
        string $sourceHtml,
        string $candidateHtml,
        string $sourceCss = '',
        string $candidateCss = ''
    ): array {
        $sourceProbes = $this->probe->extract($sourceHtml, $sourceCss);
        $candidateProbes = $this->probe->extract($candidateHtml, $candidateCss);

        return $this->comparator->compare($sourceProbes, $candidateProbes);
    }

    /**
     * Convert serialized WordPress block markup into render-free candidate HTML by
     * stripping the block delimiter comments and keeping the saved inner markup
     * (which preserves the transformer's class -> className and semantic tags).
     */
    public static function candidateHtmlFromSerializedBlocks(string $serializedBlocks): string
    {
        // Navigation blocks also save as a comment-only tree. Reconstruct only
        // the stable server-rendered structure needed for selector/style probes;
        // responsive overlays and behavior remain WordPress runtime concerns.
        $serializedBlocks = preg_replace_callback(
            '/<!--\s*(\/?)wp:(navigation(?:-submenu|-link)?)(?:\s+(\{(?:(?!-->).)*\}))?\s*(\/?)-->/s',
            static function (array $match): string {
                $closing = '/' === ($match[1] ?? '');
                $name = (string) ($match[2] ?? '');
                if ($closing) {
                    return array(
                        'navigation' => '</ul></nav>',
                        'navigation-submenu' => '</ul></li>',
                        'navigation-link' => '</li>',
                    )[$name] ?? '';
                }

                $attrs = json_decode((string) ($match[3] ?? ''), true);
                $attrs = is_array($attrs) ? $attrs : array();
                $className = htmlspecialchars(trim((string) ($attrs['className'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $selfClosing = '/' === ($match[4] ?? '');

                if ('navigation' === $name) {
                    $navigation = '<nav class="' . trim('wp-block-navigation ' . $className) . '"><ul class="'
                        . trim('wp-block-navigation__container wp-block-navigation ' . $className) . '">';
                    return $navigation . ($selfClosing ? '</ul></nav>' : '');
                }

                $url = htmlspecialchars((string) ($attrs['url'] ?? '#'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $label = strip_tags((string) ($attrs['label'] ?? ''), '<span><strong><em><small><mark>');
                $itemClasses = htmlspecialchars(trim('wp-block-navigation-item ' . $className), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $link = '<a class="wp-block-navigation-item__content" href="' . $url . '">'
                    . '<span class="wp-block-navigation-item__label">' . $label . '</span></a>';

                if ('navigation-link' === $name) {
                    return '<li class="' . $itemClasses . '">' . $link . '</li>';
                }

                $submenu = '<li class="' . trim($itemClasses . ' has-child') . '">' . $link
                    . '<ul class="wp-block-navigation__submenu-container">';
                return $submenu . ($selfClosing ? '</ul></li>' : '');
            },
            $serializedBlocks
        ) ?? $serializedBlocks;

        // Dynamic social-link children save only block comments. Materialize the
        // stable server-rendered structure so the render-free proxy can compare
        // the source controls and icons without embedding WordPress icon data.
        $serializedBlocks = preg_replace_callback(
            '/<!--\s+wp:social-link(?:\s+({.*?}))?\s+\/-->/s',
            static function (array $match): string {
                $attrs = json_decode((string) ($match[1] ?? ''), true);
                if (! is_array($attrs) || '' === trim((string) ($attrs['url'] ?? ''))) {
                    return '';
                }

                $url = htmlspecialchars((string) $attrs['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $service = preg_replace('/[^a-z0-9-]+/', '-', strtolower((string) ($attrs['service'] ?? 'share'))) ?? 'share';
                $label = htmlspecialchars(trim((string) ($attrs['label'] ?? $service)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $className = trim((string) ($attrs['className'] ?? ''));
                $classes = htmlspecialchars(trim('wp-social-link wp-social-link-' . $service . ' ' . $className), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                return '<li class="' . $classes . '"><a href="' . $url . '" class="wp-block-social-link-anchor">'
                    . '<svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true" focusable="false"></svg>'
                    . '<span class="wp-block-social-link-label screen-reader-text">' . $label . '</span></a></li>';
            },
            $serializedBlocks
        ) ?? $serializedBlocks;

        return preg_replace('/<!--\s*\/?wp:[^>]*-->/', '', $serializedBlocks) ?? $serializedBlocks;
    }
}
