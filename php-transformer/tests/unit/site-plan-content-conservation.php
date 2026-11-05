<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

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
$lossy = WordPressSitePlan::withContentConservation($plan, $result['source_reports']['compiled_site']['pages']);
$found = $losses($lossy);
$assert(1 === $lossy['quality']['metrics']['content_loss_count'] && 1 === count($found), 'One page lost text, so one content-loss finding');
$assert('about.html' === $found[0]['source_path'] && array('Only on About') === $found[0]['lost_text'] && 'content_loss' === $found[0]['loss_class'], 'The finding names the page and the lost text');
fwrite(STDOUT, "Site plan content conservation tests passed\n");
