<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

/** Immutable, document-owned serialized block structure. No global retention or hashing. */
final class BlockMarkupIndex
{
    /** @var list<array{offset:int,length:int}> */
    private array $ranges = array();
    /** @var array<int,list<array{offset:int,length:int}>> */
    private array $children = array();
    /** @var array<int,list<array{offset:int,length:int}>> */
    private array $depths = array();
    /** @var array<int,array{offset:int,length:int}> */
    private array $parents = array();
    /** @var list<array{token:string,offset:int,closing:bool,name:string,attributes:string,self_closing:bool}> */
    private array $tokens = array();

    public function __construct(public readonly string $markup)
    {
        $cursor = 0;
        $stack = array();
        $nodes = array();
        while (false !== ($start = strpos($markup, '<!--', $cursor))) {
            $end = strpos($markup, '-->', $start + 4);
            if (false === $end) break;
            $token = substr($markup, $start, $end + 3 - $start);
            $cursor = $end + 3;
            if (!preg_match('/^<!--\s*(\/?)wp:([^\s]+)(?:\s+(.*?))?\s*(\/?)-->$/s', $token, $match)) continue;
            $closing = '' !== $match[1];
            $selfClosing = '' !== ($match[4] ?? '') || str_ends_with(rtrim($token), '/-->');
            $this->tokens[] = array('token' => $token, 'offset' => $start, 'closing' => $closing, 'name' => $match[2], 'attributes' => trim($match[3] ?? ''), 'self_closing' => $selfClosing);
            if ($closing) {
                $id = array_pop($stack);
                if (null !== $id) $nodes[$id]['range']['length'] = $cursor - $nodes[$id]['range']['offset'];
                continue;
            }
            $id = count($nodes);
            $nodes[] = array('range' => array('offset' => $start, 'length' => $selfClosing ? strlen($token) : 0), 'depth' => count($stack), 'parent' => end($stack));
            if (!$selfClosing) $stack[] = $id;
        }
        foreach ($nodes as $node) {
            $range = $node['range'];
            if (0 === $range['length']) continue;
            $this->ranges[] = $range;
            $this->depths[$node['depth']][] = $range;
            if (false !== $node['parent'] && 0 < $nodes[$node['parent']]['range']['length']) {
                $parent = $nodes[$node['parent']]['range'];
                $this->children[$parent['offset']][] = $range;
                $this->parents[$range['offset']] = $parent;
            }
        }
    }

    /** @return list<array{offset:int,length:int}> */
    public function ranges(): array { return $this->ranges; }
    /** @return list<array{offset:int,length:int}> */
    public function topLevel(): array { return $this->atDepth(0); }
    /** @return list<array{offset:int,length:int}> */
    public function atDepth(int $depth): array { return $this->depths[$depth] ?? array(); }
    /** @param array{offset:int,length:int} $range @return list<array{offset:int,length:int}> */
    public function children(array $range): array { return $this->children[$range['offset']] ?? array(); }
    /** @param array{offset:int,length:int} $range @return array{offset:int,length:int}|null */
    public function parent(array $range): ?array { return $this->parents[$range['offset']] ?? null; }
    /** @return list<array{token:string,offset:int,closing:bool,name:string,attributes:string,self_closing:bool}> */
    public function tokens(): array { return $this->tokens; }
}
