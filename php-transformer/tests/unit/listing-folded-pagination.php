<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$card = static fn(int $n): string => '<article><h2><a href="/journal/post-' . $n . '/">Entry ' . $n . '</a></h2><p>A short summary.</p></article>';
foreach (array(
    'numbers and next' => array(true, '<a href="/journal/page/2/">2</a><a href="/journal/page/3/">3</a><a href="/journal/page/2/" rel="next">Next</a>', array('numbers', 'next')),
    'previous and next' => array(true, '<a href="/journal/" rel="prev">Previous</a><a href="/journal/page/2/" rel="next">Next</a>', array('previous', 'next')),
    'navigation pager' => array(true, '<a href="/journal/page/2/">2</a><a href="/journal/page/2/" rel="next">Next</a>', array('numbers', 'next')),
    'numbers only' => array(true, '<a href="/journal/page/2/">2</a><a href="/journal/page/3/">3</a>', array('numbers')),
    'no captures' => array(false, '<a href="/journal/page/2/">2</a>', array()),
    'no pager' => array(true, '', array()),
) as $name => [$captures, $links, $parts]) {
    $files = array(
        'index.html' => '<main><h1>Home</h1><p>Welcome.</p></main>',
        'journal/index.html' => '<main><h1>Journal</h1><section>' . $card(1) . $card(2) . '</section>' . ('' !== $links ? '<ul class="pager"><li>' . str_replace('</a><a', '</a></li><li><a', $links) . '</li></ul>' : '') . '</main>',
    );
    for ($n = 1; $n <= 6; ++$n) {
        $files['journal/post-' . $n . '/index.html'] = '<html><head><meta property="og:type" content="article"><meta property="article:published_time" content="2026-01-0' . $n . 'T00:00:00Z"></head><body><main><h1>Entry ' . $n . '</h1><p>Independent article content ' . $n . '.</p></main></body></html>';
    }
    if ($captures) {
        $files['journal/page/2/index.html'] = '<main><section>' . $card(3) . $card(4) . '</section></main>';
        $files['journal/page/3/index.html'] = '<main><section>' . $card(5) . $card(6) . '</section></main>';
    }
    if ('navigation pager' === $name) $files['journal/index.html'] = str_replace(array('<ul class="pager">', '</ul>'), array('<nav aria-label="Pagination"><ul class="pager">', '</ul></nav>'), $files['journal/index.html']);
    $plan = (new WordPressSitePlan())->fromCompilerResult((new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray());
    $pages = array_column($plan['pages'], null, 'source_path');
    $markup = $pages['journal/index.html']['canonical_block_markup'];
    $assert(str_contains($markup, 'wp:query ') && str_contains($markup, '"inherit":false') && str_contains($markup, '"perPage":2'), $name . ': page-owned listing');
    $assert(count(array_filter($pages, static fn(array $page): bool => 'post' === $page['post_type'])) === 6, $name . ': all posts imported');
    foreach (array('previous', 'numbers', 'next') as $part) {
        $assert(str_contains($markup, 'wp:query-pagination-' . $part) === in_array($part, $parts, true), $name . ': source pager parts: ' . $part);
    }
    if (array() !== $parts) {
        $assert(str_contains($markup, 'wp:query-pagination '), $name . ': native pagination');
        $assert(!preg_match('~href=["\'][^"\']*/journal/page/[23]/?~', $markup), $name . ': no folded route links');
        $assert(!isset($pages['journal/page/2/index.html'], $pages['journal/page/3/index.html']), $name . ': captures folded');
        $assert(!in_array('next', $parts, true) || str_contains($markup, '"label":"Next"'), $name . ': source next wording');
        $assert(!in_array('previous', $parts, true) || str_contains($markup, '"label":"Previous"'), $name . ': source previous wording');
        $lost = array_merge(array(), ...array_map(static fn(array $row): array => 'wordpress_site_plan_content_lost' === ($row['code'] ?? null) ? $row['lost_text'] : array(), $plan['diagnostics']));
        $assert(array() === array_intersect($lost, array('Next', 'Previous')), $name . ': pager text conserved');
    } elseif (!$captures) {
        $assert(str_contains($markup, '/journal/page/2'), 'Unfolded listing keeps its literal pager');
    }
}
fwrite(STDOUT, "listing-folded-pagination: passed\n");
