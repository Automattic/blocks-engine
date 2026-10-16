<?php
declare(strict_types=1);

/**
 * ElementTreeDiff names every place where a rendered element tree diverges
 * from its source tree: engine-added wrappers, dropped source wrappers,
 * retagged elements and removed elements. Engine-owned classes never count
 * toward identity.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Topology\ElementTreeDiff;
use Automattic\BlocksEngine\PhpTransformer\Topology\TopologyCorpusRunner;

$passes = 0;
$failures = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$passes, &$failures): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};
$kinds = static fn (string $source, string $rendered): array => ( new TopologyCorpusRunner() )->compare($source, $rendered);
$show = static fn (array $result): string => json_encode($result['kinds'], JSON_UNESCAPED_SLASHES);

$same = $kinds(
    '<main class="page"><section id="hero" class="hero"><h1>Title</h1><p class="lede">Text</p></section></main>',
    '<main class="wp-block-group page blocks-engine-root-child-0123456789ab-0"><section id="hero" class="wp-block-group hero is-layout-flow"><h1 class="wp-block-heading">Title</h1><p class="lede has-text-color">Text</p></section></main>'
);
$assert(array() === $same['kinds'] && 4 === $same['matched'], 'identical trees differing only in engine classes have no divergence', $show($same));

$figure = $kinds(
    '<div class="card"><img class="photo" src="a.jpg"><h3>Card</h3></div>',
    '<div class="wp-block-group card"><figure class="wp-block-image blocks-engine-synthetic-image-figure"><img class="photo" src="a.jpg"></figure><h3 class="wp-block-heading">Card</h3></div>'
);
$assert(array( 'added figure [blocks-engine-synthetic-image-figure]' => 1 ) === $figure['kinds'], 'an engine wrapper is added and what it wraps keeps its identity', $show($figure));
$assert(3 === $figure['matched'], 'the wrapped image still matches its source element', $show($figure));

$paragraph = $kinds(
    '<div class="eyebrow"><span class="dot"></span>New</div>',
    '<div class="wp-block-group eyebrow"><span class="dot"></span><p class="blocks-engine-synthetic-paragraph">New</p></div>'
);
$assert(array( 'added p [blocks-engine-synthetic-paragraph]' => 1 ) === $paragraph['kinds'], 'a paragraph around a bare text run is engine-added', $show($paragraph));

$carrier = $kinds(
    '<div class="eyebrow"><span>Now in beta</span></div>',
    '<div class="wp-block-group eyebrow"><p class="blocks-engine-inline-layout-carrier"><span>Now in beta</span></p></div>'
);
$assert(array( 'added p [blocks-engine-inline-layout-carrier]' => 1 ) === $carrier['kinds'] && 2 === $carrier['matched'], 'a classless wrapper around a source element is added, and the source element still matches', $show($carrier));

$moved = $kinds(
    '<div class="gallery"><img class="thumb" src="a.jpg"></div>',
    '<div class="wp-block-group gallery"><figure class="wp-block-image thumb blocks-engine-synthetic-image-figure"><img src="a.jpg"></figure></div>'
);
$assert(array( 'added figure [blocks-engine-synthetic-image-figure]' => 1 ) === $moved['kinds'] && 2 === $moved['matched'], 'a wrapper that took the source element classes is added, and the wrapped element is the counterpart', $show($moved));

$retag = $kinds(
    '<nav class="links"><a class="btn primary" href="/">Go</a></nav>',
    '<nav class="links"><div class="wp-block-group btn primary"><p>Go</p></div></nav>'
);
$assert(isset($retag['kinds']['retagged a>div [wp-block-group]']), 'an element keeping its classes under another tag is retagged', $show($retag));

$dropped = $kinds(
    '<section class="s"><div class="inner"><h2 class="t">A</h2><p class="d">B</p></div></section>',
    '<section class="wp-block-group s"><h2 class="t">A</h2><p class="d">B</p></section>'
);
$assert(array( 'removed-wrapper div' => 1 ) === $dropped['kinds'], 'a dropped source wrapper is reported and its children still match', $show($dropped));

$removed = $kinds(
    '<div class="row"><i class="icon"></i><p class="x">X</p></div>',
    '<div class="wp-block-group row"><p class="x">X</p></div>'
);
$assert(array( 'removed i' => 1 ) === $removed['kinds'], 'a source element with no rendered counterpart is removed', $show($removed));

$dynamic = $kinds('<nav class="n"><a href="/">A</a></nav>', '<!-- wp:navigation {"ref":3} /--><!-- wp:site-title /--><!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->');
$assert(2 === $dynamic['dynamic_blocks'], 'dynamic blocks without saved markup are counted', (string) $dynamic['dynamic_blocks']);

$assert('blocks-engine-synthetic-image-figure' === ElementTreeDiff::marker(( static function (): DOMElement {
    $d = new DOMDocument();
    $d->loadHTML('<figure class="wp-block-image blocks-engine-synthetic-image-figure-0123456789ab-0"></figure>', LIBXML_NOERROR);
    return $d->getElementsByTagName('figure')->item(0);
} )()), 'markers drop their per-site hash and prefer engine reasons over block names');

$report = array( 'fixtures' => array( 'site' => array( 'kinds' => array( 'added p' => 2, 'added mark' => 1 ) ) ) );
$baseline = TopologyCorpusRunner::baseline($report);
$assert(array() === TopologyCorpusRunner::check($report, $baseline)['regressions'] + TopologyCorpusRunner::check($report, $baseline)['improvements'], 'a report matches its own baseline');
$rising = TopologyCorpusRunner::check(array( 'fixtures' => array( 'site' => array( 'kinds' => array( 'added p' => 3, 'added mark' => 1, 'added figure' => 1 ) ) ) ), $baseline);
$assert(array( 'site: added figure 0 -> 1', 'site: added p 2 -> 3' ) === $rising['regressions'], 'rising and new divergences are regressions', json_encode($rising));
$falling = TopologyCorpusRunner::check(array( 'fixtures' => array( 'site' => array( 'kinds' => array( 'added p' => 2 ) ) ) ), $baseline);
$assert(array() === $falling['regressions'] && array( 'site: added mark 1 -> 0' ) === $falling['improvements'], 'falling divergences are improvements that must lower the baseline', json_encode($falling));
$assert(array( 'other: not in the baseline' ) === TopologyCorpusRunner::check(array( 'fixtures' => array( 'other' => array( 'kinds' => array() ) ) ), $baseline)['regressions'], 'an unknown fixture fails until it is baselined');

if ( 0 < $failures ) {
    fwrite(STDERR, "element tree diff FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "element tree diff passed: {$passes} assertions\n";
