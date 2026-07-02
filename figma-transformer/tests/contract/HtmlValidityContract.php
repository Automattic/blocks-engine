<?php

declare(strict_types=1);

use Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter;

/**
 * @param callable(bool, string): void $assert
 */
function blocks_engine_figma_transformer_run_html_validity_contract(callable $assert, callable $fileContent): void
{
    $scenegraph = array(
        'name'  => 'HTML Validity Fixture',
        'nodes' => array(
            array(
                'id'       => 'validity:home',
                'type'     => 'FRAME',
                'name'     => 'Home Page',
                'width'    => 1440,
                'height'   => 900,
                'children' => array(
                    array(
                        'id'       => 'validity:nav',
                        'type'     => 'FRAME',
                        'name'     => 'Navigation',
                        'children' => array(
                            array(
                                'id'       => 'validity:news-item',
                                'type'     => 'FRAME',
                                'name'     => 'Menu Item',
                                'width'    => 80,
                                'height'   => 32,
                                'children' => array(
                                    array('id' => 'validity:news-text', 'type' => 'TEXT', 'name' => 'Text', 'characters' => 'News', 'fontSize' => 16),
                                ),
                            ),
                        ),
                    ),
                    array(
                        'id'       => 'validity:pagination',
                        'type'     => 'FRAME',
                        'name'     => 'Pagination',
                        'layout'   => array('display' => 'flex', 'flex_direction' => 'row'),
                        'children' => array(
                            array(
                                'id'       => 'validity:previous-button',
                                'type'     => 'FRAME',
                                'name'     => 'Button',
                                'width'    => 120,
                                'height'   => 44,
                                'children' => array(
                                    array('id' => 'validity:previous-text', 'type' => 'TEXT', 'name' => 'Text', 'characters' => 'Previous', 'fontSize' => 16),
                                ),
                            ),
                            array('id' => 'validity:page-1', 'type' => 'TEXT', 'name' => 'Number', 'characters' => '1', 'fontSize' => 16),
                            array('id' => 'validity:page-2', 'type' => 'TEXT', 'name' => 'Number', 'characters' => '2', 'fontSize' => 16),
                            array('id' => 'validity:page-3', 'type' => 'TEXT', 'name' => 'Number', 'characters' => '3', 'fontSize' => 16),
                            array(
                                'id'       => 'validity:next-button',
                                'type'     => 'FRAME',
                                'name'     => 'Button',
                                'width'    => 120,
                                'height'   => 44,
                                'children' => array(
                                    array('id' => 'validity:next-text', 'type' => 'TEXT', 'name' => 'Text', 'characters' => 'Next', 'fontSize' => 16),
                                ),
                            ),
                        ),
                    ),
                    array(
                        'id'       => 'validity:footer-links',
                        'type'     => 'FRAME',
                        'name'     => 'Frame 29',
                        'height'   => 24,
                        'children' => array(
                            array('id' => 'validity:footer-about', 'type' => 'TEXT', 'name' => 'Footer text', 'characters' => 'About', 'fontSize' => 16, 'height' => 20),
                            array('id' => 'validity:footer-contact', 'type' => 'TEXT', 'name' => 'Footer text', 'characters' => 'Contact', 'fontSize' => 16, 'height' => 20),
                            array('id' => 'validity:footer-privacy', 'type' => 'TEXT', 'name' => 'Footer text', 'characters' => 'Privacy Policy', 'fontSize' => 16, 'height' => 20),
                        ),
                    ),
                ),
            ),
            array(
                'id'       => 'validity:archive',
                'type'     => 'FRAME',
                'name'     => 'News',
                'width'    => 1440,
                'height'   => 900,
                'children' => array(
                    array('id' => 'validity:archive-heading', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'News', 'fontSize' => 48),
                ),
            ),
            array(
                'id'       => 'validity:about',
                'type'     => 'FRAME',
                'name'     => 'About Us',
                'width'    => 1440,
                'height'   => 900,
                'children' => array(
                    array('id' => 'validity:about-heading', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'About Us', 'fontSize' => 48),
                ),
            ),
        ),
    );

    $result = (new StaticHtmlEmitter())->emitSite($scenegraph, array(
        'pages' => array(
            array('frame_id' => 'validity:home', 'name' => 'Home Page', 'path' => 'index.html', 'entrypoint' => true, 'page_type' => 'front_page'),
            array('frame_id' => 'validity:archive', 'name' => 'News', 'path' => 'archive.html', 'page_type' => 'archive'),
            array('frame_id' => 'validity:about', 'name' => 'About Us', 'path' => 'page.html', 'page_type' => 'page'),
        ),
    ));

    $html = $fileContent($result, 'index.html');
    $assert(str_contains($html, '<a class="figma-link" href="archive.html" data-figma-link-type="implicit-route"><div class="figma-node-validity-news-item-menu-item"'), 'html-validity-menu-item-container-linked');
    $assert(! str_contains($html, '<a class="figma-link" href="archive.html" data-figma-link-type="implicit-route"><span class="figma-node-validity-news-text-text"'), 'html-validity-linked-menu-item-suppresses-descendant-anchor');
    $assert(1 === preg_match('/<a class="figma-link button" href="archive\.html" data-figma-link-type="implicit-route"><div class="[^"]*figma-node-validity-next-button-button/', $html), 'html-validity-linked-button-renders-structural-div');
    $assert(! str_contains($html, '<a class="figma-link button" href="archive.html" data-figma-link-type="implicit-route"><button'), 'html-validity-linked-button-not-anchor-wrapped-button');
    $assert(str_contains($html, '<li class="figma-node-validity-footer-about-footer-text" data-figma-node-id="validity:footer-about" data-figma-node-name="Footer text"><a class="figma-link" href="page.html" data-figma-link-type="implicit-route">About</a></li>'), 'html-validity-linked-list-item-anchor-inside-li');
    $assert(! str_contains($html, '<ul class="figma-node-validity-footer-links-frame-29" data-figma-node-id="validity:footer-links" data-figma-node-name="Frame 29"><a '), 'html-validity-list-has-no-direct-anchor-child');

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new DOMXPath($dom);
    $assert(0 === $xpath->query('//a[.//a]')->length, 'html-validity-no-nested-anchors');
    $assert(0 === $xpath->query('//a[.//button]')->length, 'html-validity-no-anchor-wrapped-buttons');
    $assert(0 === $xpath->query('//ul/*[not(self::li)]')->length, 'html-validity-ul-direct-children-are-li');
}
