<?php
declare(strict_types=1);

// An event post stamps its start and end times with the post's day
// (<time datetime="2025-06-14">4:00 PM</time>). The day proves the date slot,
// but a time of day is not a date: post-date would render the day instead and
// the times would be lost, so the time leaf stays authored content.

require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$event = static fn(string $title, string $day, string $date, string $start, string $end): string => '<html><head><title>' . $title . '</title></head><body><main><article class="eventitem">'
    . '<h1 class="eventitem-title">' . $title . '</h1><ul class="eventitem-meta">'
    . '<li class="eventitem-meta-date"><time class="event-date" datetime="' . $day . '">' . $date . '</time></li>'
    . '<li class="eventitem-meta-time"><span class="event-time"><time class="event-time-start" datetime="' . $day . '">' . $start . '</time><span class="divider"></span><time class="event-time-end" datetime="' . $day . '">' . $end . '</time></span></li>'
    . '</ul><p>All about ' . $title . '.</p></article></main></body></html>';
$files = array(
    'index.html' => '<main><h1>Events</h1><a href="/events/concert/">Concert</a><a href="/events/taverna/">Taverna</a></main>',
    'events/concert/index.html' => $event('Concert', '2025-06-14', 'Saturday, June 14, 2025', "4:00\u{202F}PM", "5:00\u{202F}PM"),
    'events/taverna/index.html' => $event('Taverna', '2025-03-08', 'Saturday, March 8, 2025', '7:00 PM', '10:00 PM'),
);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($result);
$lost = array_values(array_filter($plan['diagnostics'] ?? array(), static fn(array $row): bool => 'wordpress_site_plan_content_lost' === ($row['code'] ?? null)));
if (array() !== $lost) {
    throw new RuntimeException('Event times were replaced by post-date: ' . json_encode($lost, JSON_UNESCAPED_UNICODE));
}
$pages = array_column($plan['pages'], null, 'source_path');
if ('post' !== ($pages['events/concert/index.html']['post_type'] ?? null) || '2025-06-14T00:00:00Z' !== ($pages['events/concert/index.html']['publication_timestamp'] ?? null)
    || !str_contains(implode("\n", array_column($plan['templates'], 'canonical_block_markup')), '<!-- wp:post-date {"format":"l, F j, Y"')) {
    throw new RuntimeException('Dated events must stay posts whose visible date is the post-date slot.');
}
echo "Event time not post-date passed.\n";
