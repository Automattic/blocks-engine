<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlCompilation;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPatternContext;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$html = (string) file_get_contents(dirname(__DIR__) . '/fixtures/navigation-subject-marker/index.html');
$compilation = new HtmlCompilation();
$result = $compilation->transform($html)->toArray();
// Recorded before indexing or memoization: pin every byte, including markers.
$assert('dd5c26d69bbf2969237bd024b44d78a10fcbd5098c03b3a80179484fa22fee33' === hash('sha256', $result['serialized_blocks']), 'Navigation block markup stays byte-identical.');

$session = (new ReflectionProperty(HtmlCompilation::class, 'session'))->getValue($compilation);
$resolver = (new ReflectionProperty(HtmlCompilation::class, 'styleResolver'))->getValue($compilation);
$state = $session->authorStyleAnalysis();
$assert($state->subjectMarkerComputations > 0, 'Compilation computes navigation subject identities.');
$memo = (new ReflectionProperty($state, 'subjectMarkers'))->getValue($state);
$distinct = 0;
foreach ($memo as $element) $distinct += count($memo[$element]);
$assert($distinct === $state->subjectMarkerComputations, 'Compilation computes each (element, kind) only once.');

// Independent probe and conversion contexts share the session memo. Exercise
// two kinds on the same element and the same kind on different elements.
$probe = new NavigationPatternContext($resolver, $session, publishesSubjects: false);
$conversion = new NavigationPatternContext($resolver, $session);
$anchors = $session->authorStyleAnalysis()->sourceBody()->getElementsByTagName('a');
foreach ($anchors as $anchor) {
    $candidates = $state->styleRulesForSubject($anchor);
    $assert(count($candidates) < count($state->styleRules()), 'Subject index excludes unrelated author rules.');
    $print = array_filter($candidates, static fn(array $rule): bool => in_array('@media print', $rule['conditions'] ?? array(), true));
    $assert(array() !== $print, 'Identity candidates retain print rules, unlike screen-paint candidates.');
    $expected = $probe->navigationItemSubjectMarker($anchor);
    $anchorMarker = $probe->navigationAnchorSubjectMarker($anchor);
    $assert($expected !== $anchorMarker, 'Kinds retain separate identities on one element.');
    $before = $state->subjectMarkerComputations;
    for ($repeat = 0; $repeat < 3; ++$repeat) {
        $assert($expected === $conversion->navigationItemSubjectMarker($anchor), 'Repeated item identity is stable.');
        $assert($anchorMarker === $conversion->navigationAnchorSubjectMarker($anchor), 'Repeated anchor identity is stable.');
    }
    $assert($before === $state->subjectMarkerComputations, 'Repeated requests do not recompute either kind.');
}

// Reusing a compilation starts a fresh session, not a process-wide memo.
$again = $compilation->transform($html)->toArray();
$fresh = (new ReflectionProperty(HtmlCompilation::class, 'session'))->getValue($compilation);
$assert($fresh->authorStyleAnalysis() !== $state, 'A new compile owns a fresh memo.');
$assert($result['serialized_blocks'] === $again['serialized_blocks'], 'Repeated compilation preserves every markup byte.');
echo "navigation-subject-marker-memo: passed\n";
