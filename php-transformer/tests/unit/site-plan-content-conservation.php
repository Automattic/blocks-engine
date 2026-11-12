<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ContentConservation;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$losses = static fn(array $plan): array => array_values(array_filter($plan['diagnostics'], static fn(array $diagnostic): bool => 'wordpress_site_plan_content_lost' === ($diagnostic['code'] ?? null)));

// Shared header and footer text moves into template parts; that is not loss.
$page = static fn(string $title): string => '<body><header><nav><a href="/index.html">Home</a> <a href="/about.html">About</a></nav></header><main><h1>' . $title . '</h1><p>Only on ' . $title . '</p></main><footer><p>Shared colophon</p></footer></body>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $page('Home'), 'about.html' => $page('About'))))->toArray();
$plan = (new WordPressSitePlan())->fromResult($result);
$assert(array() !== array_filter($plan['template_parts'], static fn(array $part): bool => str_contains($part['canonical_block_markup'], 'Shared colophon')), 'The footer must extract into a template part');
$assert(0 === $plan['quality']['metrics']['content_loss_count'] && array() === $losses($plan), 'Text moved into template parts is not content loss');

// Text that reaches neither its page nor a shared surface is counted.
foreach ($plan['pages'] as &$planned) {
    if ('about.html' !== $planned['source_path']) continue;
    $planned['canonical_block_markup'] = str_replace('Only on About', '', $planned['canonical_block_markup']);
    $planned['content_hash'] = WordPressSitePlan::contentHash($planned['canonical_block_markup']);
}
unset($planned);
$lossy = ContentConservation::check($plan, $result['source_reports']['compiled_site']['pages']);
$found = $losses($lossy);
$assert(1 === $lossy['quality']['metrics']['content_loss_count'] && 1 === count($found), 'One page lost text, so one content-loss finding');
$assert('about.html' === $found[0]['source_path'] && array('Only on About') === $found[0]['lost_text'] && 'content_loss' === $found[0]['loss_class'], 'The finding names the page and the lost text');
// A listing that becomes a query loop renders its cards from the posts.
$document = static fn(string $title, string $head, string $main): string => '<html><head><title>' . $title . '</title>' . $head . '</head><body><header><nav><a href="/">Home</a></nav></header><main>' . $main . '</main></body></html>';
$titles = array('first' => 'First story', 'second' => 'Second story', 'third' => 'Third story');
$files = array('index.html' => $document('Home', '', '<h1>Home</h1>'), 'journal/index.html' => '');
$cards = '';
foreach (array_keys($titles) as $position => $slug) {
    $cards .= '<a class="post" href="/journal/' . $slug . '/"><h3>' . $titles[$slug] . '</h3><p>Summary of ' . $slug . '.</p></a>';
    $files['journal/' . $slug . '/index.html'] = $document($titles[$slug], '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Article","headline":"' . $titles[$slug] . '","datePublished":"2026-0' . ($position + 1) . '-01"}</script>', '<article><h1>' . $titles[$slug] . '</h1><p>Summary of ' . $slug . '.</p><p>Body of ' . $slug . '.</p></article>');
}
$files['journal/index.html'] = $document('Journal', '', '<h1>Journal</h1><div class="posts">' . $cards . '</div>');
$listing = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$journal = array_column($listing['pages'], 'canonical_block_markup', 'source_path')['journal/index.html'] ?? '';
$assert(str_contains($journal, '<!-- wp:query '), 'The listing becomes a query loop');
$assert(array() === array_filter($losses($listing), static fn(array $finding): bool => 'journal/index.html' === $finding['source_path']), 'Query-loop card text rendered from the queried posts is not content loss');
// Hydration markers inside raw option labels must not create phantom spaces.
foreach (array('Cape Cod<!---->, <!---->Osterville', 'Cape Cod, Osterville', 'Cape Cod, <!---->Osterville', "Cape Cod<!-- x\ny -->Osterville") as $label) {
    $html = '<!DOCTYPE html><html><head><title>Inquiry</title></head><body><main><h1>Inquiry</h1><form><label for="p">Property</label><select id="p" name="property"><option value="">Select a property</option><option value="cape">' . $label . '</option></select><label for="d">Departure</label><input id="d" type="date" name="departure"><button>Send</button></form></main></body></html>';
    $inquiry = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['source_reports']['wordpress_site_plan'];
    $assert(str_contains($inquiry['pages'][0]['canonical_block_markup'], $label), 'The option label stays in raw markup');
    $assert(0 === $inquiry['quality']['metrics']['content_loss_count'] && array() === $losses($inquiry), 'Comment-split option text is not lost');
}

// Exercise normalization independently of runtime records carrying form labels.
$checkMarkup = static function (string $source, string $markup) use ($plan): array {
    $plan['pages'][0]['canonical_block_markup'] = $markup;
    $plan['pages'][0]['content_hash'] = WordPressSitePlan::contentHash($markup);
    return ContentConservation::check($plan, array(array('source_path' => $plan['pages'][0]['source_path'], 'block_markup' => $source)));
};
$assert(0 === $checkMarkup('<p>Searchable label</p>', '<!-- wp:navigation-link {"label":"Searchable label"} /-->')['quality']['metrics']['content_loss_count'], 'Block delimiter attribute text stays searchable');
$hidden = $checkMarkup('<p>Missing label</p>', '<!-- Missing label -->');
$assert(1 === $hidden['quality']['metrics']['content_loss_count'], 'Ordinary comment bodies cannot mask genuinely missing text');
fwrite(STDOUT, "Site plan content conservation tests passed\n");
