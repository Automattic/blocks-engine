<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ChromeIdentity;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

// The same header captured on two routes carries route-local capture
// evidence: box-tree node ids, and the subject class restating a state
// marker the route's own cascade allocated. Neither is authored markup, so
// both copies are one chrome.
$header = static fn(string $node, string $marker): string => '<!-- wp:group {"tagName":"header","className":"site-header"} --><header class="wp-block-group site-header">'
    . '<!-- wp:paragraph {"className":"menu-item ' . $marker . ' ' . EngineMarker::subjectClassClass($marker) . '"} --><p class="menu-item ' . $marker . ' ' . EngineMarker::subjectClassClass($marker) . '" ' . BoxTree::NODE_ATTRIBUTE . '="' . $node . '">Menu</p><!-- /wp:paragraph -->'
    . '</header><!-- /wp:group -->';

$same = 'blocks-engine-source-p-aaaaaaaaaaaa-0';
$assert(ChromeIdentity::markup($header('n-12', $same)) === ChromeIdentity::markup($header('n-87', $same)), 'Box-tree node ids are not chrome identity.');
$assert(ChromeIdentity::markup($header('n-12', $same)) === ChromeIdentity::markup($header('n-12', 'blocks-engine-source-p-bbbbbbbbbbbb-0')), 'A subject class restating an engine marker is not chrome identity.');

// An authored class that differs still tells two headers apart.
$assert(ChromeIdentity::markup($header('n-12', $same)) !== ChromeIdentity::markup(str_replace('menu-item', 'menu-link', $header('n-12', $same))), 'Authored classes remain chrome identity.');

echo "chrome identity capture evidence: ok\n";
