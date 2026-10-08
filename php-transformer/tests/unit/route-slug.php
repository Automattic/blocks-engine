<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Path\RouteSlug;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };
$is = static function (string $segment, string $expected) use ($assert): void { $actual = RouteSlug::segment($segment); $assert($expected === $actual, "RouteSlug::segment('{$segment}') is '{$actual}', expected '{$expected}'."); };

// WordPress's own sanitization, so the slugs these become read the way the
// author wrote them: accents fold onto ASCII and separators survive as `-`.
$is('Alisados Orgánicos', 'alisados-organicos');
$is('Para ellos', 'para-ellos');
$is('mañana', 'manana');
$is('Café', 'cafe');
$is('Our_Team', 'our-team');
$is('FAQ', 'faq');
$is('f.a.q.', 'f-a-q');
$is('ÄÖÜ ß', 'aou-s');
$is("dr.-jose-hernandez,-joins-main-board", 'dr-jose-hernandez-joins-main-board');
$is("starvault:-the-world's-first", 'starvault-the-worlds-first');
$is('--already--dashed--', 'already-dashed');
$is("hello\u{00a0}world", 'hello-world');
$is("en\u{2013}dash", 'en-dash');

// Folding is what keeps distinct source segments on distinct routes: deleting a
// separator or an accent made these pairs collide on one slug.
$assert(RouteSlug::segment('Para ellos') !== RouteSlug::segment('paraellos'), 'A spaced segment and its unspaced neighbour derive distinct slugs.');
$assert(RouteSlug::segment('mañana') !== RouteSlug::segment('maana'), 'An accented segment and its unaccented neighbour derive distinct slugs.');

// A segment with no ASCII alphanumerics kept a stable derived token rather than
// deleting to nothing and collapsing its whole route onto the front page.
foreach (array('产品', 'обо мне', 'Ελλάδα', 'مرحبا', '!!!') as $segment) {
    $token = RouteSlug::segment($segment);
    $assert('' !== $token && 1 === preg_match('/^[a-z0-9-]+$/', $token), "A non-ASCII segment derives a route-safe token ('{$segment}' gave '{$token}').");
    $assert($token === RouteSlug::segment($segment), 'A derived token is stable across calls.');
}
$assert(RouteSlug::segment('产品') !== RouteSlug::segment('服务'), 'Two distinct non-ASCII segments derive distinct tokens.');
$assert('' === RouteSlug::segment(''), 'An empty segment stays empty so it drops out of the route.');
$assert(RouteSlug::segment("\xff\xfe") === RouteSlug::segment("\xff\xfe"), 'A non-UTF-8 segment folds deterministically instead of throwing.');

echo "route slug unit test passed\n";
