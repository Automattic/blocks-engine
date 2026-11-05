<?php
// Exact state-gated custom-property mechanism from PR #4422's latest review.
$fixture = require __DIR__ . '/inherited-logical-buttons.php';
$css = <<<'CSS'
:root{--jw-button-background-color:#cacaca;--jw-button-background-color-hover:var(--jw-button-background-color-hover-default);--jw-button-background-color-hover-default:var(--jw-button-background-color-hover-flat);--jw-button-background-color-hover-flat:var(--jw-accent-container-background-color-tint);--jw-accent-container-background-color-tint:#bdbdbd;--jw-button-content-color-hover:#000}
.jw-btn{--background-color:var(--jw-button-background-color);background-color:var(--background-color)}
.jw-btn:not(:disabled):active,.jw-btn:not(:disabled):focus,.jw-btn:not(:disabled):hover{--background-color:var(--jw-button-background-color-hover);--content-color:var(--jw-button-content-color-hover)}
/* --jw-button-background-color-hover → …-hover-default → …-hover-flat → var(--jw-accent-container-background-color-tint) */
CSS;
foreach ($fixture['files'] as &$html) {
    $html = str_replace('--hover-bg:oklch(from #cacaca calc(l - .04) c h);background-color:var(--hover-bg);', '', $html);
    $html = str_replace(array('</style>', 'class="btn"'), array($css . '</style>', 'class="btn jw-btn"'), $html);
}
unset($html);
return $fixture;
