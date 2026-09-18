<?php
declare(strict_types=1);

/**
 * Unit tests for the reverse-direction media retention check.
 *
 * The content round-trip reporter runs output ⊆ source to catch invented copy.
 * Nothing ran the other way for media, and the gap was not theoretical: a
 * conversion that lowered media cards to a control reduced each `<img>` to its
 * `alt` text, and every loss counter stayed at zero because nothing had been
 * dropped — it had been demoted.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SourceMediaRetentionReporter;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$reporter = new SourceMediaRetentionReporter();
$source = '<div><img src="/media/fjord-coffee-BYYKTPWC.jpg" alt="Fjord Coffee">'
    . '<img src="/media/portrait.jpg" alt="Portrait"></div>';

// --- retention ---------------------------------------------------------------

$retained = $reporter->evaluate(
    $source,
    '<!-- wp:image --><img src="/wp-content/themes/t/media/fjord-coffee-BYYKTPWC.jpg"/><img src="/media/portrait.jpg"/><!-- /wp:image -->'
);
$assert('pass' === $retained->status(), 'images the output still addresses are retained');
$assert(array() === $retained->findings, 'a retained image produces no finding');

// The comparison is by file name, so an importer's path rewrite does not read
// as a loss.
$rewritten = $reporter->evaluate(
    '<img src="/media/fjord-coffee-BYYKTPWC.jpg">',
    '<!-- wp:image --><img src="https://example.com/wp-content/uploads/2026/09/fjord-coffee-BYYKTPWC.jpg"/><!-- /wp:image -->'
);
$assert('pass' === $rewritten->status(), 'a rewritten asset path is still the same address');

// An image can legitimately arrive as a CSS background rather than an <img>.
$background = $reporter->evaluate(
    '<img src="/media/fjord-coffee-BYYKTPWC.jpg">',
    '<!-- wp:group --><div class="hero"></div><!-- /wp:group -->',
    array( '.hero{background-image:url(../media/fjord-coffee-BYYKTPWC.jpg)}' )
);
$assert('pass' === $background->status(), 'an image carried as a background is retained');

// --- demotion ----------------------------------------------------------------

$flattened = $reporter->evaluate(
    $source,
    '<!-- wp:button --><button>Fjord Coffee</button><!-- /wp:button --><img src="/media/portrait.jpg"/>'
);
$assert('error' === $flattened->status(), 'an image demoted to its own alt text is a loss');
$assert(1 === count($flattened->findings), 'only the unaddressed image is reported');
$assert(
    'fjord-coffee-BYYKTPWC.jpg' === ( $flattened->findings[0]['text'] ?? '' ),
    'the finding names the image that left the page'
);
$assert(
    'source_media_not_in_output' === ( $flattened->findings[0]['code'] ?? '' ),
    'the finding carries its own code rather than reusing an absence code'
);

$allLost = $reporter->evaluate($source, '<!-- wp:paragraph --><p>Fjord Coffee</p><!-- /wp:paragraph -->');
$assert(2 === count($allLost->findings), 'every unaddressed image is reported');

// --- addresses that prove nothing --------------------------------------------

$unaddressable = $reporter->evaluate(
    '<img src="data:image/png;base64,AAAA"><img src="/avatar"><img alt="no source">',
    '<p>nothing</p>'
);
$assert(
    'pass' === $unaddressable->status(),
    'a data URI, an extensionless path, and a sourceless tag name no file to look up'
);

// --- report shape ------------------------------------------------------------

$report = $reporter->report($source, '<p>nothing</p>');
$assert(SourceMediaRetentionReporter::SCHEMA === ( $report['schema'] ?? '' ), 'the report declares its schema');
$assert('error' === ( $report['status'] ?? '' ), 'the report carries the evaluation status');
$assert(2 === count($report['findings'] ?? array()), 'the report carries its findings');

// --- purity ------------------------------------------------------------------

$first = $reporter->evaluate($source, '<p>nothing</p>')->findings;
$reporter->evaluate('<img src="/other.png">', '<p>x</p>');
$assert($first === $reporter->evaluate($source, '<p>nothing</p>')->findings, 'answers do not drift as the reporter is reused');

echo 'Source media retention tests: ' . $passes . ' passed' . PHP_EOL;

if ( $failures > 0 ) {
    fwrite(STDERR, 'Source media retention tests: ' . $failures . ' FAILED' . PHP_EOL);
    exit(1);
}
