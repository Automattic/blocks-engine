<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$state = htmlspecialchars(json_encode(array('attributes' => array('class' => array('before' => 'reveal', 'after' => 'reveal in')), 'threshold' => array(0.12), 'rootMargin' => '0px 0px 0px 0px', 'repeat' => false), JSON_THROW_ON_ERROR), ENT_QUOTES);
$html = '<!doctype html><html><head><title>Reveal</title><style>.reveal{opacity:0;transform:translateY(24px);transition:opacity .8s,transform .8s}.reveal.in{opacity:1;transform:none}</style></head><body><main><div style="height:1400px"></div>';
foreach (array('section', 'p') as $tag) $html .= '<' . $tag . ' class="reveal in" data-blocks-engine-capture-viewport-entrance="' . $state . '">' . ('section' === $tag ? '<h2>Card</h2><p>Editable</p>' : 'Leaf') . '</' . $tag . '>';
$html .= '</main><script data-blocks-engine-capture-viewport-entrances>throw new Error("capture plumbing must not ship");</script></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$bootstrap = '';
foreach ($plan['writes'] as $operation) if ('theme_bootstrap' === ($operation['kind'] ?? '')) $bootstrap = $operation['canonical_payload'] ?? $operation['payload']['data'] ?? '';
if (!str_contains($bootstrap, 'blocks-engine-viewport-entrances') || 1 !== substr_count($bootstrap, 'function replayViewportEntrances()')) throw new RuntimeException('Exactly one engine-owned runtime is emitted.');
if (str_contains($bootstrap, 'capture plumbing must not ship')) throw new RuntimeException('Capture activator must not ship.');
if (!str_contains($result['serialized_blocks'], 'blocksEngineRuntimeAttributes')) throw new RuntimeException('Leaf entrance evidence survives in render metadata.');
if ('pass' !== (new BlockValidityValidator())->validateBlocks($result['blocks'])['status']) throw new RuntimeException('Entrance evidence must not invalidate editor blocks.');
echo "Viewport entrance runtime contract passed.\n";
