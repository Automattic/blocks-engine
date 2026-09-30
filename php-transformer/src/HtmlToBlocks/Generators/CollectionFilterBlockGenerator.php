<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

/** One editable control/region primitive for a verified local collection predicate. */
final class CollectionFilterBlockGenerator
{
    public const LOCAL_NAME = 'collection-filter';

    public function definition(string $namespace): array
    {
        $name = $namespace . '/' . self::LOCAL_NAME;
        $attributes = array(
            'kind' => array('type' => 'string', 'default' => 'root'),
            'tag' => array('type' => 'string', 'default' => 'div'),
            'className' => array('type' => 'string', 'default' => ''),
            'label' => array('type' => 'string', 'default' => ''),
            'placeholder' => array('type' => 'string', 'default' => ''),
            'index' => array('type' => 'number', 'default' => 0),
            'config' => array('type' => 'object', 'default' => array('initialCategory' => 0, 'memberships' => array(), 'categories' => array())),
        );
        $editor = <<<'JS'
(function(blocks, editor, element) {
    const el = element.createElement, InnerBlocks = editor.InnerBlocks, RichText = editor.RichText;
    function props(a) {
        const p = { className: a.className || undefined };
        if (a.kind === 'root') Object.assign(p, { 'data-wp-interactive': '__NAME__', 'data-wp-context': JSON.stringify(Object.assign({}, a.config, { category: a.config.initialCategory, query: '' })), 'data-wp-init': 'callbacks.init', 'data-wp-on--input': 'actions.search', 'data-wp-on--click': 'actions.choose' });
        if (a.kind === 'field') Object.assign(p, { type: 'search', placeholder: a.placeholder, 'aria-label': a.label || a.placeholder || 'Filter collection', 'data-collection-field': 'true' });
        if (a.kind === 'category') Object.assign(p, { type: 'button', 'data-collection-category': String(a.index), 'aria-pressed': String(a.index === a.config.initialCategory) });
        if (a.kind === 'empty') Object.assign(p, { 'data-collection-empty': 'true', hidden: true, 'aria-live': 'polite' });
        return p;
    }
    blocks.registerBlockType('__NAME__', {
        attributes: __ATTRIBUTES__, supports: { html: false, customClassName: false, interactivity: true },
        edit({attributes:a,setAttributes}) {
            if(a.kind === 'field') return el('input',Object.assign(props(a),{onChange:()=>{},value:'',disabled:true}));
            if(a.kind === 'category') return el(RichText,Object.assign(props(a),{tagName:'button',value:a.label,allowedFormats:[],onChange:label=>setAttributes({label})}));
            return el(a.tag,{className:a.className},el(InnerBlocks));
        },
        save({attributes:a}) {
            if(a.kind === 'field') return el('input',props(a));
            if(a.kind === 'category') return el(RichText.Content,Object.assign(props(a),{tagName:'button',value:a.label}));
            return el(a.tag,props(a),el(InnerBlocks.Content));
        }
    });
})(window.wp.blocks,window.wp.blockEditor,window.wp.element);
JS;
        $view = <<<'JS'
import { store, getContext, getElement } from '@wordpress/interactivity';

const rootOf = ref => ref.closest('[data-wp-interactive="__NAME__"]');
const normalizedText = item => {
    const walker = document.createTreeWalker(item, NodeFilter.SHOW_TEXT), parts = [];
    while (walker.nextNode()) parts.push(walker.currentNode.textContent);
    return parts.join(' ').replace(/\s+/g, ' ').trim().toLowerCase();
};
export function refresh(root, context) {
    const target = root.querySelector('.blocks-engine-collection-target');
    if (!target) return;
    const nativeItems = target.querySelectorAll('.wp-block-accordion-item');
    const nativeContainer = target.querySelector(':scope > .wp-block-group') || target;
    const items = nativeItems.length ? Array.from(nativeItems) : Array.from(nativeContainer.children);
    if (items.length !== context.memberships.length) return;
    let count = 0;
    items.forEach((item, index) => {
        const show = context.memberships[index].includes(context.category) && normalizedText(item).includes(context.query.toLowerCase());
        item.hidden = !show;
        // Core disclosure styles must not override a filtered item's visibility.
        if (show) item.style.removeProperty('display'); else item.style.setProperty('display', 'none', 'important');
        if (show) count++;
    });
    root.querySelectorAll('[data-collection-empty]').forEach(empty => { empty.hidden = count !== 0; });
    root.querySelectorAll('[data-collection-category]').forEach(control => {
        const index = Number(control.dataset.collectionCategory), selected = index === context.category;
        const attributes = context.categories[index][selected ? 'active' : 'inactive'];
        Object.entries(attributes).forEach(([name,value]) => value === null ? control.removeAttribute(name) : control.setAttribute(name,value));
        control.setAttribute('aria-pressed', String(selected));
    });
}
store('__NAME__', {
    actions: {
        search(event) {
            if (!event.target.matches('[data-collection-field]')) return;
            const context = getContext(); context.query = event.target.value;
            refresh(rootOf(getElement().ref), context);
        },
        choose(event) {
            const control = event.target.closest('[data-collection-category]');
            if (!control) return;
            const context = getContext(); context.category = Number(control.dataset.collectionCategory);
            refresh(rootOf(getElement().ref), context);
        }
    },
    callbacks: { init() { refresh(getElement().ref, getContext()); } }
});
JS;
        return array(
            'name' => self::LOCAL_NAME,
            'block_json' => array('apiVersion' => 3, 'name' => $name, 'title' => 'Collection Filter', 'category' => 'widgets', 'editorScript' => 'file:./index.js', 'viewScriptModule' => 'file:./view.js', 'attributes' => $attributes, 'supports' => array('html' => false, 'customClassName' => false, 'interactivity' => true)),
            'assets' => array('index.js' => str_replace(array('__NAME__', '__ATTRIBUTES__'), array($name, json_encode($attributes, JSON_THROW_ON_ERROR)), $editor)),
            'view_js' => str_replace('__NAME__', $name, $view),
            'script_dependencies' => array('index.js' => array('wp-blocks', 'wp-block-editor', 'wp-element'), 'view.js' => array('@wordpress/interactivity')),
        );
    }

    public function opening(array $a, string $name): string
    {
        $escape = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<' . $a['tag'] . ('' !== $a['className'] ? ' class="' . $escape($a['className']) . '"' : '');
        if ('root' === $a['kind']) $html .= ' data-wp-interactive="' . $escape($name) . '" data-wp-context="' . $escape(json_encode(array_merge($a['config'], array('category' => $a['config']['initialCategory'], 'query' => '')), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '" data-wp-init="callbacks.init" data-wp-on--input="actions.search" data-wp-on--click="actions.choose"';
        if ('field' === $a['kind']) $html .= ' type="search" placeholder="' . $escape($a['placeholder']) . '" aria-label="' . $escape($a['label'] ?: ($a['placeholder'] ?: 'Filter collection')) . '" data-collection-field="true"';
        if ('category' === $a['kind']) $html .= ' type="button" data-collection-category="' . $a['index'] . '" aria-pressed="' . ($a['index'] === $a['config']['initialCategory'] ? 'true' : 'false') . '"';
        if ('empty' === $a['kind']) $html .= ' data-collection-empty="true" hidden aria-live="polite"';
        return $html . ('field' === $a['kind'] ? '/>' : '>');
    }
}
