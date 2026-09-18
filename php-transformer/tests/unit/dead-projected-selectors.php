<?php
declare(strict_types=1);

/**
 * Dead author-selector projection check.
 *
 * A projected selector that binds to no element in the document scope it was
 * emitted for is definitionally a bug. The pre-#1954 golden-bull failure was
 * exactly this shape: `.h-9` / `.h-14` / `.w-14` projected onto semantic and
 * richtext markers that occurred zero times in any emitted document.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\DeadProjectedSelectorReporter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;

$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$reporter = new DeadProjectedSelectorReporter();
$deadMarker = 'blocks-engine-semantic-8ed8be17a645-5';
$liveMarker = 'blocks-engine-semantic-8ed8be17a645-9';
$richtextMarker = 'blocks-engine-richtext-8ed8be17a645-22';

$deadProjected = ':where(.' . $deadMarker . '):not(.blocks-engine-specificity-class-aaa-1)';
$liveProjected = ':where(.' . $liveMarker . '):not(.blocks-engine-specificity-class-aaa-1)';
$richtextProjected = ':where(span[data-blocks-engine-richtext-marker="' . $richtextMarker . '"]):not(.blocks-engine-specificity-class-aaa-1)';
$keptClassProjected = '.h-9:not(:where(.' . $deadMarker . ')),' . $deadProjected;

$deadHaystack = '<!-- wp:group --><div class="wp-block-group logo-slot"><img alt="Brand"/></div><!-- /wp:group -->';
$liveHaystack = '<!-- wp:group --><div class="wp-block-group ' . $liveMarker . '"><img alt="Brand"/></div><!-- /wp:group -->';
$richtextHaystack = '<span data-blocks-engine-richtext-marker="' . $richtextMarker . '">Logo</span>';
$classHaystack = '<!-- wp:group --><div class="wp-block-group h-9"><img alt="Brand"/></div><!-- /wp:group -->';

// --- dead projection is reported with authored/projected/scope detail --------

$dead = $reporter->evaluate(
    array(
        array(
            'authored'    => '.h-9',
            'projected'   => $deadProjected,
            'stylesheet'  => 'site.css',
            'scope'       => 'index.html',
        ),
    ),
    array('index.html' => $deadHaystack)
);
$assert('error' === $dead->status(), 'a marker that appears in no document is an error');
$assert(1 === count($dead->findings), 'one unbound authored selector produces one finding');
$assert(DeadProjectedSelectorReporter::CODE === ($dead->findings[0]['code'] ?? ''), 'the finding carries its own code');
$assert('.h-9' === ($dead->findings[0]['source_selector'] ?? ''), 'the finding names the authored selector');
$assert($deadProjected === ($dead->findings[0]['selector'] ?? ''), 'the finding names the projected selector');
$assert('site.css' === ($dead->findings[0]['path'] ?? ''), 'the finding names the stylesheet');
$assert(array('index.html') === ($dead->findings[0]['context']['scopes'] ?? null), 'the finding names the scope checked');
$assert('projected_author_selector' === ($dead->findings[0]['pattern_family'] ?? ''), 'the finding clusters as a projected-selector defect');
$assert('restore_projected_selector_binding' === ($dead->findings[0]['repair_bucket'] ?? ''), 'the finding routes to the projection-binding repair lane');

$deadRichtext = $reporter->evaluate(
    array(
        array(
            'authored'    => '.h-14',
            'projected'   => $richtextProjected,
            'stylesheet'  => 'site.css',
            'scope'       => 'index.html',
        ),
    ),
    array('index.html' => $deadHaystack)
);
$assert(1 === count($deadRichtext->findings) && '.h-14' === ($deadRichtext->findings[0]['source_selector'] ?? ''), 'a dead richtext-marker projection is reported');

// --- correctly-bound projection is not reported --------------------------------

$live = $reporter->evaluate(
    array(
        array(
            'authored'    => '.h-9',
            'projected'   => $liveProjected,
            'stylesheet'  => 'site.css',
            'scope'       => 'index.html',
        ),
    ),
    array('index.html' => $liveHaystack)
);
$assert('pass' === $live->status() && array() === $live->findings, 'a marker present in the emitted document is not reported');

$liveRichtext = $reporter->evaluate(
    array(
        array(
            'authored'    => '.h-14',
            'projected'   => $richtextProjected,
            'stylesheet'  => 'site.css',
            'scope'       => 'index.html',
        ),
    ),
    array('index.html' => $richtextHaystack)
);
$assert(array() === $liveRichtext->findings, 'a richtext marker present in markup is not reported');

$keptClass = $reporter->evaluate(
    array(
        array(
            'authored'    => '.h-9',
            'projected'   => $keptClassProjected,
            'stylesheet'  => 'site.css',
            'scope'       => 'index.html',
        ),
    ),
    array('index.html' => $classHaystack)
);
$assert(array() === $keptClass->findings, 'a class-bound projection that kept the authored class remains live even when its document-local marker is absent');

$slashUtility = $reporter->evaluate(
    array(
        array(
            'authored'    => '.border-primary\\/50',
            'projected'   => '.border-primary,.border-primary\\/50',
            'stylesheet'  => 'site.css',
            'scope'       => 'index.html',
        ),
    ),
    array('index.html' => $deadHaystack)
);
$assert(array() === $slashUtility->findings, 'a rewritten unused utility with no engine binding marker is not a dead projection');

// --- a rule emitted for one document is not dead in another --------------------

$scoped = $reporter->evaluate(
    array(
        array(
            'authored'    => '.h-9',
            'projected'   => $liveProjected,
            'stylesheet'  => 'site.css',
            'scope'       => 'about.html',
        ),
    ),
    array(
        'index.html' => $deadHaystack,
        'about.html' => $liveHaystack,
    )
);
$assert(array() === $scoped->findings, 'a projection is checked only against the document scope it was emitted for');

$wrongScope = $reporter->evaluate(
    array(
        array(
            'authored'    => '.h-9',
            'projected'   => $liveProjected,
            'stylesheet'  => 'about.inline.css',
            'scope'       => 'about.html',
        ),
    ),
    array(
        'index.html' => $liveHaystack,
        'about.html' => $deadHaystack,
    )
);
$assert(1 === count($wrongScope->findings), 'a page-scoped projection is dead when its own document does not carry the marker, even if another document does');

// --- report shape --------------------------------------------------------------

$report = $reporter->report(
    array(array('authored' => '.h-9', 'projected' => $deadProjected, 'stylesheet' => 'site.css', 'scope' => 'index.html')),
    array('index.html' => $deadHaystack)
);
$assert(DeadProjectedSelectorReporter::SCHEMA === ($report['schema'] ?? ''), 'the report declares its schema');
$assert('error' === ($report['status'] ?? ''), 'the report carries the evaluation status');

$first = $reporter->evaluate(
    array(array('authored' => '.h-9', 'projected' => $deadProjected, 'scope' => 'index.html')),
    array('index.html' => $deadHaystack)
)->findings;
$reporter->evaluate(
    array(array('authored' => '.other', 'projected' => $liveProjected, 'scope' => 'index.html')),
    array('index.html' => $liveHaystack)
);
$assert($first === $reporter->evaluate(
    array(array('authored' => '.h-9', 'projected' => $deadProjected, 'scope' => 'index.html')),
    array('index.html' => $deadHaystack)
)->findings, 'answers do not drift as the reporter is reused');

// --- HtmlTransformer surfaces the finding on a dropped projection identity -----

$dropped = ( new HtmlTransformer() )->transform(
    '<style>.ghost-bind{height:36px}</style><span class="ghost-bind"></span><p>Hello</p>'
)->toArray();
$droppedCodes = array_column($dropped['diagnostics'] ?? array(), 'code');
$droppedReport = $dropped['source_reports']['dead_projected_selectors'] ?? array();
$droppedFinding = array_values(array_filter(
    $dropped['diagnostics'] ?? array(),
    static fn (array $diagnostic): bool => DeadProjectedSelectorReporter::CODE === ($diagnostic['code'] ?? '')
))[0] ?? array();
$assert(in_array(DeadProjectedSelectorReporter::CODE, $droppedCodes, true), 'HtmlTransformer reports a projection whose marker left the document');
$assert('error' === ($droppedReport['status'] ?? ''), 'the HtmlTransformer report status is error when a projection is dead');
$assert('.ghost-bind' === ($droppedFinding['source_selector'] ?? ''), 'the HtmlTransformer finding names the authored selector');
$assert(str_contains((string) ($droppedFinding['selector'] ?? ''), 'blocks-engine-richtext-'), 'the HtmlTransformer finding names the projected richtext marker');
$assert('html' === (($droppedFinding['context']['scopes'] ?? array())[0] ?? ''), 'the HtmlTransformer finding names the scope checked');

// --- live class-bound chrome across documents is not a false positive ----------

$sharedChromeHeader = static function (string $home, string $about): string {
    return '<header id="site-chrome" class="site-header"><span class="logo-slot"><img src="logo.png" alt="Brand"></span><nav><a href="' . $home . '">Home</a><a href="' . $about . '">About</a></nav></header>';
};
$sharedChromeDocument = static function (string $header, string $title): string {
    return '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><a class="skip-link" href="#content">Skip to content</a>'
        . $header
        . '<main id="content"><h1>' . $title . '</h1></main><footer><p>Footer ' . $title . '</p></footer></body></html>';
};
$sharedChromePng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$sharedChrome = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        array( 'path' => 'index.html', 'kind' => 'html', 'content' => $sharedChromeDocument($sharedChromeHeader('index.html', 'about.html'), 'Home') ),
        array( 'path' => 'about.html', 'kind' => 'html', 'content' => $sharedChromeDocument($sharedChromeHeader('index.html', 'about.html'), 'About') ),
        array( 'path' => 'site.css', 'kind' => 'css', 'content' => '.logo-slot{display:block;background-color:#123456}' ),
        array( 'path' => 'logo.png', 'kind' => 'image', 'content_base64' => base64_encode($sharedChromePng), 'mime_type' => 'image/png' ),
    ),
))->toArray();
$sharedDead = array_values(array_filter(
    $sharedChrome['diagnostics'] ?? array(),
    static fn (array $diagnostic): bool => DeadProjectedSelectorReporter::CODE === ($diagnostic['code'] ?? '')
));
$assert(array() === $sharedDead, 'a shared stylesheet class rule that still matches chrome is not reported as dead');

$livePage = ( new HtmlTransformer() )->transform(
    '<style>.bound{color:red}</style><p class="bound">Hello</p>'
)->toArray();
$livePageCodes = array_column($livePage['diagnostics'] ?? array(), 'code');
$assert(
    ! in_array(DeadProjectedSelectorReporter::CODE, $livePageCodes, true)
        && 'pass' === ($livePage['source_reports']['dead_projected_selectors']['status'] ?? ''),
    'a correctly-bound class projection produces no dead-projection diagnostic'
);

echo 'Dead projected selector tests: ' . $passes . ' passed' . PHP_EOL;

if ( $failures > 0 ) {
    fwrite(STDERR, 'Dead projected selector tests: ' . $failures . ' FAILED' . PHP_EOL);
    exit(1);
}
