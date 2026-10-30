<?php
declare(strict_types=1);

// The reduced page, with the optional padded, painted contain variant.
return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Figures</title><style>
.imgcards{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;max-width:1140px}
.imgcard{margin:0;border:1px solid #ddd;border-radius:10px;overflow:hidden}
.imgcard-media{aspect-ratio:4/3;overflow:hidden}
.imgcard-media img{width:100%;height:100%;object-fit:cover;display:block}
.imgcard figcaption{padding:11px 13px;font-size:.84rem}
.is-diag .imgcard-media{box-sizing:border-box;background:#fff;display:flex;align-items:center;justify-content:center;padding:12px}
.is-diag img{object-fit:contain}
</style></head><body><main><div class="imgcards">
<figure class="imgcard"><div class="imgcard-media"><img src="wide.jpg" alt="Wide door"></div><figcaption>Wide door</figcaption></figure>
<figure class="imgcard"><div class="imgcard-media"><img src="tall.jpg" alt="Tall door"></div><figcaption>Tall door</figcaption></figure>
<figure class="imgcard is-diag"><div class="imgcard-media"><img src="tall.jpg" alt="Contained door"></div><figcaption>Contained door</figcaption></figure>
</div></main></body></html>';
