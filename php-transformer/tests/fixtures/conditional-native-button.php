<?php
declare(strict_types=1);

return '<style>
body{font:16px/1.4 sans-serif}
.action{display:inline-block;font-size:13px;font-weight:400;line-height:1.5;padding:4px 8px;background-color:#126abc;color:#fff;border:0;border-radius:6px}
.label{font-size:12px}
@media(min-width:1px){.frame > :not(.designer) > :not(.designer) .action{font-size:20px;font-weight:500;line-height:28px;padding:12px 24px;background-color:#294dd0;border-radius:28px!important}}
@media(max-width:600px){.frame > :not(.designer) > :not(.designer) .action{font-size:16px;line-height:22px;padding:9px 20px;background-color:#345678;border-radius:24px!important}.label{font-size:18px}}
</style><main class="frame"><section><div>
<div><a class="action" href="#destination">Contact</a></div>
<div><button class="action" type="submit">Send</button></div>
<div><a class="action" href="#destination" style="font-size:23px;background-color:#983456;padding:7px 11px">Explicit</a></div>
<div><a class="action" href="#destination"><span class="label">Label</span></a></div>
</div></section><p id="destination">Destination</p></main>';
