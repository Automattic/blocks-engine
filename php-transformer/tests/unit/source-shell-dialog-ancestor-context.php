<?php
declare(strict_types=1);

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\SourceShells;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;

function shell(array $state): string {
    return '<header><button ' . CaptureEvidence::DIALOG_ANCESTOR_STATE . '="' . htmlspecialchars(json_encode(array($state)), ENT_QUOTES) . '">Menu</button></header>';
}
$state = array('selector' => 'body', 'tag' => 'body', 'depth' => 18, 'closed' => array('class' => ''), 'opened' => array('class' => 'locked'), 'classTokensOnly' => true);
$first = shell($state);
$state['depth'] = 19;
$second = shell($state);
if (SourceShells::identity($first) !== SourceShells::identity($second)) throw new RuntimeException('Selector-resolved ancestor distance must not split source shells.');
if (SourceShells::identity($first, false) === SourceShells::identity($second, false)) throw new RuntimeException('Emission must retain ancestor distance.');
$state['opened']['class'] = 'different';
if (SourceShells::identity($first) === SourceShells::identity(shell($state))) throw new RuntimeException('Ancestor state remains structural.');
unset($state['selector']);
$first = shell($state);
$state['depth'] = 20;
if (SourceShells::identity($first) === SourceShells::identity(shell($state))) throw new RuntimeException('Depth-only ancestor targets remain structural.');
echo "Source shell dialog ancestor context: passed\n";
