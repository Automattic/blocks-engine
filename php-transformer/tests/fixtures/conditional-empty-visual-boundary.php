<?php
return '<style>
* { border: 0 solid; }
.frame { position: relative; height: 180px; }
@media (max-width: 800px) {
    :where(.variant) .paint { background: linear-gradient(red, blue); width: 100%; height: 100%; }
    :where(.variant) .divider { height: 3px; background-color: green; }
    .zero-box { height: 0; padding: 0; background: none; }
}
@media (min-width: 801px) {
    .desktop-paint { background-image: linear-gradient(yellow, purple); height: 40px; }
}
.state-only:hover { background-color: red; height: 30px; }
</style><div class="variant"><section class="frame"><div class="paint"></div><p>Content</p></section><div class="divider"></div><div class="desktop-paint"></div><div class="inert"></div><div class="zero-box"></div><div class="state-only"></div></div>';
