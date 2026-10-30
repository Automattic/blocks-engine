<?php
// Reduced, builder-neutral single-stage gallery with a responsive counter.
return '<style>
.gallery{display:flex;flex-direction:column-reverse;width:600px;max-width:100%}
.thumbs{display:flex}.thumbs img{width:80px;height:60px}
.slides{position:relative;width:100%;aspect-ratio:4/3}
.slide{display:none}.slide.selected{display:block}.slide img{width:100%;height:100%}
@media (min-width:768px){.indicator{display:none}}
</style><div class="gallery"><div class="thumbs"><button><img src="a.jpg" width="80"></button><button><img src="b.jpg" width="80"></button><button><img src="c.jpg" width="80"></button></div><div class="slides"><div class="slide selected"><img src="a.jpg" width="600" height="450"></div><div class="slide"><img src="b.jpg" width="600" height="450"></div><div class="slide"><img src="c.jpg" width="600" height="450"></div><div class="indicator">1 / 3</div></div></div>';
