<?php
declare(strict_types=1);

// A provider frame that fills a percentage-padding ratio box through an inner
// wrapper, as many hosted blog platforms publish video posts.
return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Video post</title><style>
body{margin:0;font-family:sans-serif}
.article{max-width:720px;margin:0 auto;padding:0 16px}
.movie{padding-top:60%;position:relative;overflow:hidden;width:100%}
.movie div,.movie iframe{position:absolute;top:0;left:0;display:block;width:100%;height:100%}
iframe{border:none}
</style></head><body><main class="article"><h1>Video post</h1><p>Before the video.</p>
<div class="movie"><div><iframe width="100%" height="444" src="https://www.youtube.com/embed/demo123" title="Video" allowfullscreen=""></iframe></div></div>
<p>After the video.</p></main></body></html>';
