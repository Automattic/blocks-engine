<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main>Home</main>')))->toArray()['source_reports']['wordpress_site_plan'];
$tree = array('blockName' => 'core/group', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array(''));
for ($i = 0; $i < 40; $i++) $tree = array('blockName' => 'core/group', 'attrs' => array(), 'innerBlocks' => array($tree), 'innerHTML' => '', 'innerContent' => array('', null, ''));
$plan['quality']['fallbacks'][0]['readable_blocks'] = array($tree);
$identity = WordPressSitePlan::planIdentity($plan);
if ($identity !== WordPressSitePlan::planIdentity($plan) || !preg_match('/^[a-f0-9]{64}$/', $identity['hash'])) throw new RuntimeException('Deep plan identity is not stable SHA-256.');
$plan['plan_identity'] = $identity;
(new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => 'https://example.test/theme'));
$deepPayload = $tree;
try { RuntimeDeclarations::hash($deepPayload); throw new RuntimeException('Default runtime declaration hash accepted excessive depth.'); } catch (InvalidArgumentException $error) { if ('Runtime declaration payload contains an unsupported value.' !== $error->getMessage()) throw $error; }
try { RuntimeDeclarations::canonical($deepPayload); throw new RuntimeException('Default runtime declaration canonicalization accepted excessive depth.'); } catch (InvalidArgumentException $error) { if ('Runtime declaration payload contains an unsupported value.' !== $error->getMessage()) throw $error; }
echo "WordPress site plan identity depth contract passed.\n";
