<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\RouteSelectorMatchIndex;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;

function routeDocument(string $markup): DOMDocument
{
    $document = new DOMDocument();
    $document->loadHTML('<html><body>' . $markup . '</body></html>');
    return $document;
}

$index = new RouteSelectorMatchIndex(array(
    'present' => routeDocument('<section class="a rare-ancestor"><div id="id" class="literal"></div><div class="' . EngineMarker::editorAnchorClass('id') . ' anchor"></div></section><i class="peer"></i><b></b><div class="sibling"></div>'),
    'absent' => routeDocument('<div class="literal" id="id"></div><div class="sibling"></div>'),
));
// Each row names the expected subjects on both routes. Unsupported arms are
// unknown, not evidence of inertness, even when their positive keys are absent.
$cases = array(
    array(':where(.a)>:where(:is(#id,.blocks-engine-editor-anchor-id))', '.literal,.anchor', '.none'),
    array('.rare-ancestor div', '.literal,.anchor', '.none'),
    array('.rare-ancestor > div:not(.anchor)', '.literal', '.none'),
    array('.rare-ancestor + .peer', '.peer', '.none'),
    array('.rare-ancestor ~ .sibling', '.sibling', '.none'),
    array('.missing + div', '.none', '.none'),
    array('.missing ~ div', '.none', '.none'),
    array(':is(.missing, .rare-ancestor) > div', '.literal,.anchor', '.none'),
    array(':is(.missing, *) > .sibling', '.sibling', '.sibling'),
    array(':where(.missing, [id])', '.literal', '.literal'),
    array(':is(.missing .literal, .anchor)', '.anchor', '.none'),
    array('div:not(.missing)', '.literal,.anchor,.sibling', '.literal,.sibling'),
    array('div:not(.rare-ancestor div)', '.sibling', '.literal,.sibling'),
    array('#id', '.literal,.anchor', '.literal'),
    array('#id::before', '.literal::before,.anchor::before', '.literal::before'),
    array('.missing || div', null, null),
    array('.missing[broken=', null, null),
);
foreach ($cases as [$arm, $present, $absent]) {
    foreach (array('present' => $present, 'absent' => $absent) as $owner => $expectedSelectors) {
        $expected = null;
        if (null !== $expectedSelectors) {
            $expected = array();
            foreach (explode(',', $expectedSelectors) as $selector) $expected += $index->matchedElements($selector, $owner);
        }
        $actual = $index->matchedElements($arm, $owner);
        if ($actual !== null) ksort($actual);
        if ($expected !== null) ksort($expected);
        if ($actual !== $expected) throw new RuntimeException('Wrong subjects: ' . $arm . ' on ' . $owner);
        // The inertness API deliberately does not reduce pseudo-elements.
        $inert = str_contains($arm, '::') ? false : array() === $expected;
        if ($index->inertOutside($arm, array($owner => true)) !== $inert) throw new RuntimeException('Wrong inertness: ' . $arm . ' on ' . $owner);
    }
}
if (null !== $index->matchedElements('.missing', 'unknown') || $index->inertOutside('.missing', array('unknown' => true))) throw new RuntimeException('Missing documents are not evidence');
unset($index);

// Exclude DOM construction/indexing: measure fresh (arm, route) queries, not
// memo hits. Each route has many divs but lacks the queried ancestor/subject.
// Route-local work should remain flat, rather than paying for every other DOM.
$times = array();
foreach (array(4, 32) as $n) {
    $documents = array();
    for ($r = 0; $r < $n; ++$r) {
        $documents['route-' . $r] = routeDocument('<section class="a rare-ancestor-' . $r . '">' . str_repeat('<div class="ordinary"></div>', 600) . '</section>');
    }
    $index = new RouteSelectorMatchIndex($documents);
    $start = hrtime(true);
    for ($r = 0; $r < $n; ++$r) {
        $owner = 'route-' . $r;
        for ($probe = 0; $probe < 32; ++$probe) {
            foreach (array(
                ':where(.a)>:where(:is(#missing-' . $r . '-' . $probe . ',.blocks-engine-editor-anchor-missing-' . $r . '-' . $probe . '))',
                '.rare-ancestor-missing-' . $r . ' div:not(.probe-' . $probe . ')',
            ) as $arm) {
                if (!$index->inertOutside($arm, array($owner => true)) || array() !== $index->matchedElements($arm, $owner)) throw new RuntimeException('Absent route subjects must be inert');
            }
            // Shared type-leaf arms queried across every route must not iterate
            // the site's divs again for each owner.
            for ($shared = 0; $shared < 16; ++$shared) {
                if (array() !== $index->matchedElements('.absent-' . $probe . '-' . $shared . ' div', $owner)) throw new RuntimeException('Absent ancestor matched');
            }
        }
    }
    $times[$n] = (hrtime(true) - $start) / 1e9;
    unset($index, $documents);
    gc_collect_cycles();
}
if ($times[32] / 32 > 3 * $times[4] / 4) throw new RuntimeException('Route query per-route cost grew with site size: ' . json_encode($times));
// Also bound unkeyed scans that are linear in route count but visit every
// unrelated element. The fixed workload takes well below a second; allow 5s.
if ($times[32] > 5) throw new RuntimeException('Route query work exceeded the bound: ' . json_encode($times));
fwrite(STDOUT, 'Route selector match index regression passed: ' . json_encode($times) . "\n");
