<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;

class StructuredHtmlSanitizer
{
    /**
     * @var object|null
     */
    private $purifier;

    /**
     * @var array<string, array<int, string>>
     */
    private const ALLOWED_TAG_ATTRS = [
        'p' => [],
        'div' => [],
        'span' => [],
        'strong' => [],
        'em' => [],
        'b' => [],
        'i' => [],
        'u' => [],
        'br' => [],
        'code' => [],
        'pre' => [],
        'sub' => [],
        'sup' => [],
        'h1' => [],
        'h2' => [],
        'h3' => [],
        'h4' => [],
        'h5' => [],
        'h6' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'table' => [],
        'thead' => [],
        'tbody' => [],
        'tfoot' => [],
        'tr' => [],
        'th' => ['colspan', 'rowspan', 'scope', 'headers'],
        'td' => ['colspan', 'rowspan', 'headers'],
        'caption' => [],
        'colgroup' => [],
        'col' => ['span', 'width'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
    ];

    /**
     * Elements removed with all children.
     *
     * @var string[]
     */
    private const DROP_WITH_CONTENT = [
        'script',
        'style',
        'iframe',
        'form',
        'input',
        'button',
        'textarea',
        'select',
        'object',
        'embed',
        'link',
        'meta',
        'base',
    ];

    public function __construct()
    {
        // Use HTMLPurifier when installed; otherwise use strict DOM allowlist sanitizer.
        if (class_exists('HTMLPurifier_Config') && class_exists('HTMLPurifier')) {
            $config = \HTMLPurifier_Config::createDefault();
            $config->set('Core.Encoding', 'UTF-8');
            $config->set('Cache.DefinitionImpl', null);
            $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
            $config->set('URI.AllowedSchemes', ['https' => true]);
            $config->set('HTML.Allowed', implode(',', [
                'p',
                'div',
                'span',
                'strong',
                'em',
                'b',
                'i',
                'u',
                'br',
                'code',
                'pre',
                'sub',
                'sup',
                'h1',
                'h2',
                'h3',
                'h4',
                'h5',
                'h6',
                'ul',
                'ol',
                'li',
                'table',
                'thead',
                'tbody',
                'tfoot',
                'tr',
                'th[colspan|rowspan|scope|headers]',
                'td[colspan|rowspan|headers]',
                'caption',
                'colgroup',
                'col[span|width]',
                'img[src|alt|title|width|height]',
            ]));
            $config->set('HTML.ForbiddenElements', array_merge(self::DROP_WITH_CONTENT, ['a']));
            $config->set('Attr.EnableID', false);

            $this->purifier = new \HTMLPurifier($config);
            return;
        }

        $this->purifier = null;
    }

    public function sanitize(?string $html): string
    {
        if (!is_string($html) || trim($html) === '') {
            return '';
        }

        $clean = $html;
        if ($this->purifier) {
            $clean = $this->purifier->purify($clean);
        }

        $clean = $this->sanitizeWithDomAllowlist($clean);
        return trim($clean);
    }

    private function sanitizeWithDomAllowlist(string $html): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><div id="__root__">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $dom->getElementById('__root__');
        if (!$root instanceof DOMElement) {
            return '';
        }

        $this->sanitizeNodeChildren($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }

    private function sanitizeNodeChildren(DOMNode $parent): void
    {
        $children = [];
        foreach ($parent->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof DOMElement) {
                $this->sanitizeElement($child);
                continue;
            }

            // Remove comments and processing instructions.
            if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                $parent->removeChild($child);
            }
        }
    }

    private function sanitizeElement(DOMElement $element): void
    {
        $tag = strtolower($element->tagName);

        if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
            $element->parentNode?->removeChild($element);
            return;
        }

        if (!array_key_exists($tag, self::ALLOWED_TAG_ATTRS)) {
            $this->unwrapNode($element);
            return;
        }

        $this->sanitizeAttributes($element, self::ALLOWED_TAG_ATTRS[$tag]);

        if ($tag === 'img') {
            $src = trim((string) $element->getAttribute('src'));
            if (!$this->isAllowedHttpsUrl($src)) {
                $element->parentNode?->removeChild($element);
                return;
            }
        }

        $this->sanitizeNodeChildren($element);
    }

    /**
     * @param string[] $allowedAttrs
     */
    private function sanitizeAttributes(DOMElement $element, array $allowedAttrs): void
    {
        $attrs = [];
        foreach ($element->attributes as $attr) {
            $attrs[] = strtolower($attr->name);
        }

        foreach ($attrs as $name) {
            if (str_starts_with($name, 'on')) {
                $element->removeAttribute($name);
                continue;
            }
            if ($name === 'style') {
                $element->removeAttribute($name);
                continue;
            }
            if (!in_array($name, $allowedAttrs, true)) {
                $element->removeAttribute($name);
                continue;
            }

            // Tighten numeric-like table/image attrs.
            if (in_array($name, ['colspan', 'rowspan', 'span', 'width', 'height'], true)) {
                $value = trim((string) $element->getAttribute($name));
                if ($value !== '' && !preg_match('/^\d+$/', $value)) {
                    $element->removeAttribute($name);
                }
            }
        }
    }

    private function unwrapNode(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if (!$parent) {
            return;
        }

        while ($element->firstChild) {
            $parent->insertBefore($element->firstChild, $element);
        }
        $parent->removeChild($element);
    }

    private function isAllowedHttpsUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        if (!preg_match('/^https:\/\/.+/i', $url)) {
            return false;
        }

        $parts = parse_url($url);
        return is_array($parts) && (($parts['scheme'] ?? '') === 'https') && isset($parts['host']);
    }
}
