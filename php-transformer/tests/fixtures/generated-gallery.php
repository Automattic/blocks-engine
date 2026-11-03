<?php
declare(strict_types=1);

// Neutral capture-shaped gallery; item identities and inline geometry are distinct.
function generatedGallery(int $count): string
{
    $items = '';
    for ($i = 0; $i < $count; ++$i) {
        $id = 'item-' . $i . '--data-blocks-engine-capture-tablet';
        $top = $i * 420;
        $items .= <<<HTML
<div class="gallery-item-container item-container-hover visible clickable" id="pgi-$id" data-idx="$i" data-hook="item-container" style="overflow-y:hidden;position:absolute;bottom:auto;margin:5px;right:auto;overflow:hidden;transition:opacity .2s ease;opacity:1;display:block;top:{$top}px;left:0;width:547px;height:410px">
<div id="item-action-$id" class="item-action" tabindex="0" role="button" aria-haspopup="dialog"></div>
<div style=""><div data-hook="item-wrapper" class="gallery-item-wrapper visible" id="item-wrapper-$id" style="background-color:inherit;margin:0px;height:410px;width:547px">
<div class="gallery-item-content image-item gallery-item-visible" data-hook="image-item" style="margin-top:0;margin-left:0;width:547px;height:410px"><picture><source srcset="/media/image-$i.avif 1x, /media/image-$i.avif 2x" type="image/jpeg"><img id="$id" class="gallery-item-visible gallery-item" src="/media/image-$i.avif" alt="" style="width:547px;height:410px"></picture></div>
<div class="item-hover-flex-container" style="display:flex;position:absolute;align-items:flex-end;width:547px;height:410px;top:0;left:0"><div class="gallery-item-hover" style="position:relative;width:547px;height:410px"><div class="gallery-item-hover-inner"><div data-hook="info-element" style="height:100%;width:100%"><div class="hover-info-element" style="box-sizing:border-box;padding:0;height:100%"></div></div></div></div></div>
</div></div></div>
HTML;
    }
    return '<!doctype html><html><head><style>.gallery{position:relative}.gallery-item-container{box-sizing:border-box}.gallery-item-wrapper{overflow:hidden}.image-item img{object-fit:cover}.item-action{position:absolute;inset:0}</style></head><body><main><div class="gallery">' . $items . '</div></main></body></html>';
}
