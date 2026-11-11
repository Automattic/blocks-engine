<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = '<!doctype html><html><head><title>Choice forms</title></head><body><main>'
    . '<form method="post" action="/answers"><label><input id="answer-yes" type="radio" name="answer" value="yes" checked>Yes</label>'
    . '<label><input id="answer-no" type="radio" name="answer" value="no">No</label><button>Send</button></form>'
    . '<form method="post" action="/answers"><label><input id="topic-a" type="checkbox" name="topic" value="a">Topic A</label>'
    . '<label><input id="topic-b" type="checkbox" name="topic" value="b" checked>Topic B</label><input type="submit" value="Send"></form>'
    . '</main></body></html>';
echo json_encode((new ArtifactCompiler())->compile(array(
    'block_namespace' => 'neutral',
    'site_slug' => 'inactive-choice-proof',
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $html),
))->toArray(), JSON_THROW_ON_ERROR);
