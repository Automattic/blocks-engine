<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;

/** Small Interactivity primitives around one native, owner-editable tree. */
final class CollectionFilterBlockGenerator
{
    public const ROOT = 'collection-filter';
    public const FIELD = 'collection-filter-field';
    public const CHOICE = 'collection-filter-choice';
    public const EMPTY = 'collection-filter-empty';

    public function definition(string $namespace, string $local): array
    {
        $name = $namespace . '/' . $local;
        $store = $namespace . '/' . self::ROOT;
        $attributes = array(
            'className' => array('type' => 'string', 'default' => ''),
            'anchor' => array('type' => 'string', 'default' => ''),
            'sourceStyle' => array('type' => 'object'),
        );
        if (self::ROOT === $local) $attributes += array('tagName' => array('type' => 'string', 'default' => 'div'), 'items' => array('type' => 'array', 'default' => array()), 'initialCategory' => array('type' => 'number', 'default' => 0));
        if (self::FIELD === $local) $attributes += array('inputType' => array('type' => 'string', 'default' => 'search'), 'placeholder' => array('type' => 'string', 'default' => ''), 'ariaLabel' => array('type' => 'string', 'default' => ''), 'value' => array('type' => 'string', 'default' => ''));
        if (self::CHOICE === $local) $attributes += array('label' => array('type' => 'string', 'default' => ''), 'ariaLabel' => array('type' => 'string', 'default' => ''), 'index' => array('type' => 'number', 'default' => 0), 'initial' => array('type' => 'boolean', 'default' => false), 'active' => array('type' => 'object'), 'inactive' => array('type' => 'object'));
        $editor = <<<'JS'
(function(blocks,editor,components,element){
var el=element.createElement,role=__ROLE__,store=__STORE__;
function common(a){return {className:a.className||undefined,id:a.anchor||undefined,style:Object.keys(a.sourceStyle||{}).length?a.sourceStyle:undefined};}
function context(a){return role==='collection-filter'?{query:'',category:a.initialCategory||0,items:a.items||[],matchCount:(a.items||[]).length}:{choiceIndex:a.index,active:a.active,inactive:a.inactive};}
function props(a){var p=common(a);if(role==='collection-filter'){p.className=((p.className||'')+' blocks-engine-collection-scope').trim();p['data-wp-interactive']=store;p['data-wp-context']=JSON.stringify(context(a));p['data-wp-init']='callbacks.init';p['data-wp-watch']='callbacks.refresh';}
if(role==='collection-filter-field'){p.type=a.inputType||'search';p.placeholder=a.placeholder||undefined;p['aria-label']=a.ariaLabel||undefined;p.value=a.value||'';p['data-wp-on--input']=store+'::actions.query';}
if(role==='collection-filter-choice'){var state=a.initial?a.active:a.inactive;p.type='button';p.className=(state&&state.className)||undefined;p.style=(state&&state.style)||undefined;p['aria-label']=a.ariaLabel||undefined;p['aria-pressed']=String(!!a.initial);p['aria-selected']=state&&state.selected||undefined;p['data-state']=state&&state.dataState||undefined;p['data-wp-context']=JSON.stringify(context(a));p['data-wp-on--click']=store+'::actions.choose';p['data-wp-bind--class']=store+'::state.choiceClass';p['data-wp-bind--style']=store+'::state.choiceStyle';p['data-wp-bind--aria-pressed']=store+'::state.choicePressed';p['data-wp-bind--aria-selected']=store+'::state.choiceSelected';p['data-wp-bind--data-state']=store+'::state.choiceDataState';}
if(role==='collection-filter-empty'){p.hidden=true;p['data-wp-bind--hidden']=store+'::state.hasMatches';}return p;}
blocks.registerBlockType(__NAME__,{attributes:__ATTRIBUTES__,supports:{html:false,customClassName:false,interactivity:true},
edit:function(p){var a=p.attributes,inspector;
if(role==='collection-filter-field'){inspector=el(editor.InspectorControls,null,el(components.PanelBody,{title:'Collection search'},el(components.TextControl,{label:'Placeholder',value:a.placeholder,onChange:function(value){p.setAttributes({placeholder:value});}}),el(components.TextControl,{label:'Accessible label',value:a.ariaLabel,onChange:function(value){p.setAttributes({ariaLabel:value});}})));return el(element.Fragment,null,inspector,el('input',Object.assign({},editor.useBlockProps(common(a)),{type:a.inputType||'search',placeholder:a.placeholder,'aria-label':a.ariaLabel,value:a.value,onChange:function(event){p.setAttributes({value:event.target.value});}})));}
if(role==='collection-filter-choice')return el(editor.RichText,Object.assign({},editor.useBlockProps(common(a)),{tagName:'button',type:'button',value:a.label,allowedFormats:[],onChange:function(value){p.setAttributes({label:value});}}));
if(role==='collection-filter')inspector=el(editor.InspectorControls,null,el(components.PanelBody,{title:'Collection membership'},el(components.TextareaControl,{label:'Item membership (JSON)',value:JSON.stringify(a.items,null,2),onChange:function(value){try{var items=JSON.parse(value);if(Array.isArray(items)&&items.every(function(item){return /^blocks-engine-collection-item-[a-z0-9-]+$/.test(item.marker)&&Array.isArray(item.categories)&&item.categories.every(Number.isInteger);})){p.setAttributes({items:items});}}catch(error){}}})));
var tag=role==='collection-filter'?a.tagName||'div':'div';return el(element.Fragment,null,inspector,el(tag,editor.useInnerBlocksProps(editor.useBlockProps(common(a)))));},
save:function(p){var a=p.attributes;if(role==='collection-filter-field')return el('input',props(a));if(role==='collection-filter-choice')return el(editor.RichText.Content,Object.assign(props(a),{tagName:'button',value:a.label}));var tag=role==='collection-filter'?a.tagName||'div':'div';return el(tag,editor.useInnerBlocksProps.save(props(a)));}
});
})(window.wp.blocks,window.wp.blockEditor,window.wp.components,window.wp.element);
JS;
        $view = <<<'JS'
import {getContext,getElement,store} from '@wordpress/interactivity';
const namespace=__STORE__;
const choice=()=>{const c=getContext(namespace);return c.choiceIndex===c.category?c.active:c.inactive;};
const text=node=>{const walker=document.createTreeWalker(node,NodeFilter.SHOW_TEXT),parts=[];while(walker.nextNode()){const parent=walker.currentNode.parentElement;if(parent?.closest('[aria-hidden="true"]'))continue;parts.push(walker.currentNode.textContent);}return parts.join(' ').replace(/\s+/g,' ').trim().toLowerCase();};
const css=styles=>Object.entries(styles||{}).map(([key,value])=>(key.startsWith('--')?key:key==='cssFloat'?'float':key.replace(/[A-Z]/g,letter=>'-'+letter.toLowerCase()))+':'+value).join(';');
store(namespace,{
actions:{query(event){getContext(namespace).query=event.target.value;},choose(){const c=getContext(namespace);c.category=c.choiceIndex;}},
state:{get choiceClass(){return choice()?.className||null;},get choiceStyle(){return css(choice()?.style)||null;},get choicePressed(){const c=getContext(namespace);return String(c.choiceIndex===c.category);},get choiceSelected(){return choice()?.selected??null;},get choiceDataState(){return choice()?.dataState??null;},get hasMatches(){return getContext(namespace).matchCount>0;}},
callbacks:{init(){const root=getElement().ref,field=root.querySelector('[data-wp-on--input="'+namespace+'::actions.query"]');if(field)getContext(namespace).query=field.value;},refresh(){const c=getContext(namespace),root=getElement().ref,query=(c.query||'').toLowerCase(),category=c.category;let count=0;for(const item of c.items||[]){const nodes=root.querySelectorAll('.'+item.marker);for(const node of nodes){const show=item.categories.includes(category)&&text(node).includes(query);node.hidden=!show;if(show)count++;}}if(c.matchCount!==count)c.matchCount=count;}}
});
JS;
        $replace = array('__NAME__' => json_encode($name), '__STORE__' => json_encode($store), '__ROLE__' => json_encode($local), '__ATTRIBUTES__' => json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $json = array('apiVersion' => 3, 'name' => $name, 'title' => match ($local) { self::ROOT => 'Filtered Collection', self::FIELD => 'Collection Search Field', self::CHOICE => 'Collection Category', default => 'Collection Empty State' }, 'category' => 'widgets', 'editorScript' => 'file:./index.js', 'attributes' => $attributes, 'supports' => array('html' => false, 'customClassName' => false, 'interactivity' => true));
        $definition = array('name' => $local, 'block_json' => $json, 'assets' => array('index.js' => strtr($editor, $replace)), 'script_dependencies' => array('index.js' => array('wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element')));
        if (self::ROOT === $local) {
            $definition['block_json']['viewScriptModule'] = 'file:./view.js';
            $definition['block_json']['style'] = 'file:./style.css';
            $definition['assets']['style.css'] = '.blocks-engine-collection-scope [hidden]{display:none!important}';
            $definition['view_js'] = strtr($view, $replace);
            $definition['script_dependencies']['view.js'] = array('@wordpress/interactivity');
        }
        return $definition;
    }

    public function opening(array $attrs, string $local, string $namespace): string
    {
        $store = $namespace . '/' . self::ROOT;
        $html = array('class' => $attrs['className'] ?? '', 'id' => $attrs['anchor'] ?? '', 'style' => $this->style($attrs['sourceStyle'] ?? array()));
        $tag = 'div';
        if (self::ROOT === $local) {
            $tag = $attrs['tagName'] ?? 'div';
            $html['class'] = trim($html['class'] . ' blocks-engine-collection-scope');
            $html['data-wp-interactive'] = $store;
            $html['data-wp-context'] = json_encode(array('query' => '', 'category' => $attrs['initialCategory'] ?? 0, 'items' => $attrs['items'] ?? array(), 'matchCount' => count($attrs['items'] ?? array())), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $html['data-wp-init'] = 'callbacks.init';
            $html['data-wp-watch'] = 'callbacks.refresh';
        } elseif (self::FIELD === $local) {
            $tag = 'input';
            $html += array('type' => $attrs['inputType'] ?? 'search', 'placeholder' => $attrs['placeholder'] ?? '', 'aria-label' => $attrs['ariaLabel'] ?? '', 'value' => $attrs['value'] ?? '', 'data-wp-on--input' => $store . '::actions.query');
        } elseif (self::CHOICE === $local) {
            $tag = 'button';
            $state = !empty($attrs['initial']) ? $attrs['active'] : $attrs['inactive'];
            $html['class'] = $state['className'] ?? '';
            $html['style'] = $this->style($state['style'] ?? array());
            $html['aria-label'] = $attrs['ariaLabel'] ?? '';
            $html += array('type' => 'button', 'aria-pressed' => !empty($attrs['initial']) ? 'true' : 'false', 'aria-selected' => $state['selected'] ?? '', 'data-state' => $state['dataState'] ?? '', 'data-wp-context' => json_encode(array('choiceIndex' => $attrs['index'], 'active' => $attrs['active'], 'inactive' => $attrs['inactive']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'data-wp-on--click' => $store . '::actions.choose', 'data-wp-bind--class' => $store . '::state.choiceClass', 'data-wp-bind--style' => $store . '::state.choiceStyle', 'data-wp-bind--aria-pressed' => $store . '::state.choicePressed', 'data-wp-bind--aria-selected' => $store . '::state.choiceSelected', 'data-wp-bind--data-state' => $store . '::state.choiceDataState');
        } else {
            $html['hidden'] = true;
            $html['data-wp-bind--hidden'] = $store . '::state.hasMatches';
        }
        $output = '<' . $tag;
        foreach ($html as $key => $value) {
            if (true === $value) $output .= ' ' . $key;
            elseif ('' !== $value && null !== $value) $output .= ' ' . $key . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            elseif ('value' === $key && self::FIELD === $local) $output .= ' value=""';
        }
        return $output . ('input' === $tag ? '/>' : '>');
    }

    public function sourceStyle(string $style): array
    {
        $result = array();
        foreach (CssValueSplitter::splitTopLevel($style, array(';')) as $declaration) {
            $colon = strpos($declaration, ':');
            if (false === $colon) continue;
            $property = trim(substr($declaration, 0, $colon));
            $value = trim(substr($declaration, $colon + 1));
            if (1 !== preg_match('/^(?:--[A-Za-z0-9_-]+|[a-z-]+)$/', $property) || '' === $value || str_contains($value, '!important')) continue;
            $key = str_starts_with($property, '--') ? $property : preg_replace_callback('/-([a-z])/', static fn ($match): string => strtoupper($match[1]), $property);
            if (str_starts_with($property, '-ms-')) $key = lcfirst($key);
            if ('float' === $property) $key = 'cssFloat';
            $result[$key] = $value;
        }
        return $result;
    }

    private function style(array $style): string
    {
        $parts = array();
        foreach ($style as $key => $value) {
            $property = str_starts_with($key, '--') ? $key : preg_replace_callback('/[A-Z]/', static fn ($match): string => '-' . strtolower($match[0]), $key);
            if ('cssFloat' === $key) $property = 'float';
            $parts[] = $property . ':' . $value;
        }
        return implode(';', $parts);
    }
}
