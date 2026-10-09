(function(){
function triggers(){return document.querySelectorAll('[data-dla-dialog-trigger]');}
function panel(trigger){var id=trigger.getAttribute('aria-controls');return id?document.getElementById(id):null;}
function closeFor(trigger){var id=trigger.getAttribute('aria-controls');return id?document.querySelector('[data-dla-dialog-close="'+id+'"]'):null;}
function concealed(trigger){var style=getComputedStyle(trigger);if(style.display==='none'||style.visibility==='hidden')return true;var rect=trigger.getBoundingClientRect();return rect.width===0||rect.height===0;}
function ancestorBindings(trigger){try{return JSON.parse(trigger.getAttribute('data-dla-dialog-ancestor-state')||'[]');}catch(error){return [];}}
function ancestorAt(trigger,depth){var node=trigger;while(depth--&&node)node=node.parentElement;return node;}
function applyAncestors(trigger,open){ancestorBindings(trigger).forEach(function(binding){var node=ancestorAt(trigger,binding.depth);if(!node)return;
  Object.keys(binding.closed).forEach(function(name){var value=open?binding.opened[name]:binding.closed[name];
    if(!open){var owner=Array.prototype.find.call(triggers(),function(other){return other!==trigger&&!concealed(other)&&other.getAttribute('aria-expanded')==='true'&&ancestorBindings(other).some(function(row){return ancestorAt(other,row.depth)===node&&Object.prototype.hasOwnProperty.call(row.opened,name);});});if(owner){var row=ancestorBindings(owner).find(function(row){return ancestorAt(owner,row.depth)===node&&Object.prototype.hasOwnProperty.call(row.opened,name);});value=row.opened[name];}}
    if(value===null)node.removeAttribute(name);else node.setAttribute(name,value);
  });
});}
function apply(trigger){
  var target=panel(trigger);if(!target)return;
  var inactive=concealed(trigger),open=trigger.getAttribute('aria-expanded')==='true'&&!inactive;
  applyAncestors(trigger,open);
  if(!open&&Array.prototype.some.call(triggers(),function(other){return other!==trigger&&panel(other)===target&&!concealed(other)&&other.getAttribute('aria-expanded')==='true';}))return;
  var captured=trigger.getAttribute('data-dla-dialog-panel-state');
  var opened=captured?JSON.parse(captured):null;
  if(open&&opened&&target.__dlaDialogState!==captured){target.innerHTML=opened.html;target.__dlaDialogState=captured;}
  var existing=target.getAttribute('data-dla-existing-panel');
  if(existing){
    var states=JSON.parse(existing),state=inactive?states.resting:(opened||states.opened);
    ['style','class'].forEach(function(name){if(state[name]===null)target.removeAttribute(name);else target.setAttribute(name,state[name]);});
    target.toggleAttribute('data-dla-resting-panel',inactive);
    target.hidden=inactive?states.resting.hidden:!open;
  }else target.hidden=!open;
  var close=closeFor(trigger);if(close)close.hidden=!open;
}
function set(trigger,open){if(open&&concealed(trigger))open=false;if(open)triggers().forEach(function(other){if(other!==trigger&&panel(other)===panel(trigger)&&other.getAttribute('aria-expanded')==='true')set(other,false);});trigger.setAttribute('aria-expanded',open?'true':'false');var label=trigger.getAttribute('data-dla-disclosure-label');if(label)trigger.setAttribute('aria-label',open?'Close '+label:label);apply(trigger);}
function toggle(trigger){set(trigger,trigger.getAttribute('aria-expanded')!=='true');}
function onClick(event){var close=event.target.closest&&event.target.closest('[data-dla-dialog-close]');if(close){event.preventDefault();var id=close.getAttribute('data-dla-dialog-close');var owner=id&&document.querySelector('[data-dla-dialog-trigger][aria-controls="'+id+'"]');if(owner){set(owner,false);owner.focus();}return;}var trigger=event.target.closest&&event.target.closest('[data-dla-dialog-trigger]');if(!trigger||!panel(trigger))return;var nested=event.target.closest('a,button');if(nested&&nested!==trigger)return;event.preventDefault();var was=trigger.getAttribute('aria-expanded')==='true';toggle(trigger);if(was)trigger.focus();}
function onKey(event){if(event.key==='Escape'){var open=Array.prototype.slice.call(triggers()).filter(function(item){var target=panel(item);return !concealed(item)&&item.getAttribute('aria-expanded')==='true'&&target&&!target.hidden;}).pop();if(open){event.preventDefault();set(open,false);open.focus();return;}var details=Array.prototype.slice.call(document.querySelectorAll('details.dla-initial-dialog[open]')).pop();if(!details)return;event.preventDefault();details.open=false;var summary=details.querySelector(':scope > summary');if(summary)summary.focus();return;}if(event.key!=='Enter'&&event.key!==' ')return;var trigger=event.target.closest&&event.target.closest('[data-dla-dialog-trigger]');if(!trigger||!panel(trigger))return;if(trigger.tagName==='BUTTON'||(trigger.tagName==='A'&&event.key==='Enter'))return;event.preventDefault();toggle(trigger);}
function ready(){document.addEventListener('click',onClick);document.addEventListener('keydown',onKey);window.addEventListener('resize',function(){triggers().forEach(apply);});triggers().forEach(apply);}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',ready);else ready();
})();
