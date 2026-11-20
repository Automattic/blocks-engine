<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A labelled date linked to its own post (Blogger's timestamp permalink) is
// the post's date, so the single template renders it with core/post-date
// rather than storing each imported post's date markup in post meta.
$artifact = array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'content' => '<html><body><p>Home</p></body></html>')));
foreach (array('first', 'second', 'third') as $index => $slug) {
    $day = $index + 1;
    $artifact['files'][] = array('path' => $slug . '.html', 'metadata' => array('post_type' => 'post'), 'content' => '<html><head><title>' . $slug . '</title><meta property="article:published_time" content="2024-03-0' . $day . 'T10:00:00Z"></head><body><header><p>Publication</p></header><main><article class="post"><h1>' . $slug . '</h1><p class="post_timestamp"><span class="label">Posted at</span><a class="timestamp-link" href="/' . $slug . '.html" rel="bookmark"><time class="published" datetime="2024-03-0' . $day . 'T10:00:00Z">March 0' . $day . ', 2024</time></a></p><div class="post-body"><p>Unique body of ' . $slug . '.</p><p>Second paragraph of ' . $slug . '.</p></div><p class="post-share">Share this post</p></article></main><footer><p>Footer</p></footer></body></html>');
}
$plan = (new ArtifactCompiler())->compile($artifact)->toArray()['source_reports']['wordpress_site_plan'];
$single = array_column($plan['templates'], null, 'slug')['single']['canonical_block_markup'] ?? '';
if (1 !== preg_match('/<!-- wp:post-date (\{[^}]*"isLink":true[^}]*\}) \/-->/', $single)) throw new RuntimeException('The single template renders the linked date with core/post-date: ' . $single);
if (!str_contains($single, 'Posted at')) throw new RuntimeException('The authored date label stays in the template.');
if (str_contains($single, 'blocks_engine_post_field_')) throw new RuntimeException('No post date markup is stored in post meta.');
fwrite(STDOUT, "single-post-permalink-date: ok\n");
