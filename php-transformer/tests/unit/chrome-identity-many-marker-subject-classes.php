<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ChromeIdentity;

// #7450: subject classes restating engine markers leave chrome identity no
// matter how many distinct markers the chrome carries; an alternation of
// every subject class outgrew PCRE and silently kept them all.
$failures = array();
$warnings = array();
set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
    $warnings[] = $message;
    return true;
});
$authored = EngineMarker::subjectClassClass('site-nav');
$markup = '';
for ($i = 0; $i < 6000; ++$i) {
    $marker = 'blocks-engine-attribute-' . substr(hash('sha256', (string) $i), 0, 12) . '-' . $i;
    $markup .= '<span class="item ' . $marker . ' ' . EngineMarker::subjectClassClass($marker) . ' ' . $authored . '">' . $i . '</span>';
}
$identity = ChromeIdentity::markup('<div class="site-nav">' . $markup . '</div>');
restore_error_handler();

if (array() !== $warnings) $failures[] = 'identity emits no warning: ' . $warnings[0];
$remaining = substr_count($identity, EngineMarker::SUBJECT_CLASS_PREFIX);
if (6000 !== $remaining || 6000 !== substr_count($identity, $authored)) $failures[] = 'every marker subject class leaves identity and authored subject classes stay (' . $remaining . ' subject classes remain)';

if (array() !== $failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "chrome identity many marker subject classes: ok\n";
