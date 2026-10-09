<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A column strip styles its direct children (`.strip > *`). The social anchors
// sit deeper inside a column, so that rule must not reach them once Core Social
// Links re-parents them. A rule that did select an anchor as a direct child
// (`.bar li > a`) still crosses the inserted ul/li.
$icon = '<img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" alt="">';
$source = '<style>.strip{display:flex}.strip>*{margin-top:60px;flex:1}.bar ul{display:flex;margin:0;padding:0;list-style:none}.bar a{display:block;width:24px;height:24px}.bar li>a{outline:2px solid red}</style>'
    . '<div class="strip"><div class="column"><p>About</p><div class="bar"><ul aria-label="Social Bar">'
    . '<li><a href="https://www.facebook.com/example" aria-label="Facebook">' . $icon . '</a></li>'
    . '<li><a href="https://www.instagram.com/example" aria-label="Instagram">' . $icon . '</a></li>'
    . '</ul></div></div></div>';
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();
$output = stripslashes(json_encode($compiled, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

if (!str_contains($output, 'wp-block-social-link-anchor')) {
    throw new RuntimeException('Fixture must materialize Core Social Links.');
}
if (preg_match('/\.strip\s[^{},]*wp-block-social-link-anchor/', $output)) {
    throw new RuntimeException('A child-only rule must not be widened onto social anchors nested deeper in the source.');
}
if (!preg_match('/\.bar li\s+a\.wp-block-social-link-anchor/', $output)) {
    throw new RuntimeException('A rule that selected source anchors as direct children must still cross the inserted ul/li.');
}
echo "Social anchor ancestor selector passed\n";
