<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DomCrawler;

use Masterminds\HTML5;
use Symfony\Component\CssSelector\CssSelectorConverter;

/**
 * Crawler eases navigation of a list of \DOMNode objects.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Crawler implements \Countable, \IteratorAggregate
{
    protected $uri;

    /**
     * @var string The default namespace prefix to be used with XPath and CSS expressions
     */
    private $defaultNamespacePrefix = 'default';

    /**
     * @var array A map of manually registered namespaces
     */
    private $namespaces = [];

    /**
     * @var string The base href value
     */
    private $baseHref;

    /**
     * @var \DOMDocument|null
     */
    private $document;

    /**
     * @var \DOMElement[]
     */
    private $nodes = [];

    /**
     * Whether the Crawler contains HTML or XML content (used when converting CSS to XPath).
     *
     * @var bool
     */
    private $isHtml = true;

    /**
     * @var HTML5|null
     */
    private $html5Parser;

    /**
     * @param mixed $node A Node to use as the base for the crawling
     */
    public function __construct($node = null, string $uri = null, string $baseHref = null)
    {
        $this->uri = $uri;
        $this->baseHref = $baseHref ?: $uri;
        $this->html5Parser = class_exists(HTML5::class) ? new HTML5(['disable_html_ns' => true]) : null;

        $this->add($node);
    }

    /**
     * Returns the current URI.
     *
     * @return string
     */
    public function getUri()
    {
        return $this->uri;
    }

    /**
     * Returns base href.
     *
     * @return string
     */
    public function getBaseHref()
    {
        return $this->baseHref;
    }

    /**
     * Removes all the nodes.
     */
    public function clear()
    {
        $this->nodes = [];
        $this->document = null;
    }

    /**
     * Adds a node to the current list of nodes.
     *
     * This method uses the appropriate specialized add*() method based
     * on the type of the argument.
     *
     * @param \DOMNodeList|\DOMNode|array|string|null $node A node
     *
     * @throws \InvalidArgumentException when node is not the expected type
     */
    public function add($node)
    {
        if ($node instanceof \DOMNodeList) {
            $this->addNodeList($node);
        } elseif ($node instanceof \DOMNode) {
            $this->addNode($node);
        } elseif (\is_array($node)) {
            $this->addNodes($node);
        } elseif (\is_string($node)) {
            $this->addContent($node);
        } elseif (null !== $node) {
            throw new \InvalidArgumentException(sprintf('Expecting a DOMNodeList or DOMNode instance, an array, a string, or null, but got "%s".', \is_object($node) ? \get_class($node) : \gettype($node)));
        }
    }

    /**
     * Adds HTML/XML content.
     *
     * If the charset is not set via the content type, it is assumed to be UTF-8,
     * or ISO-8859-1 as a fallback, which is the default charset defined by the
     * HTTP 1.1 specification.
     */
    public function addContent(string $content, string $type = null)
    {
        if (empty($type)) {
            $type = 0 === strpos($content, '<?xml') ? 'application/xml' : 'text/html';
        }

        // DOM only for HTML/XML content
        if (!preg_match('/(x|ht)ml/i', $type, $xmlMatches)) {
            return;
        }

        $charset = null;
        if (false !== $pos = stripos($type, 'charset=')) {
            $charset = substr($type, $pos + 8);
            if (false !== $pos = strpos($charset, ';')) {
                $charset = substr($charset, 0, $pos);
            }
        }

        // http://www.w3.org/TR/encoding/#encodings
        // http://www.w3.org/TR/REC-xml/#NT-EncName
        if (null === $charset &&
            preg_match('/\<meta[^\>]+charset *= *["\']?([a-zA-Z\-0-9_:.]+)/i', $content, $matches)) {
            $charset = $matches[1];
        }

        if (null === $charset) {
            $charset = preg_match('//u', $content) ? 'UTF-8' : 'ISO-8859-1';
        }

        if ('x' === $xmlMatches[1]) {
            $this->addXmlContent($content, $charset);
        } else {
            $this->addHtmlContent($content, $charset);
        }
    }

    /**
     * Adds an HTML content to the list of nodes.
     *
     * The libxml errors are disabled when the content is parsed.
     *
     * If you want to get parsing errors, be sure to enable
     * internal errors via libxml_use_internal_errors(true)
     * and then, get the errors via libxml_get_errors(). Be
     * sure to clear errors with libxml_clear_errors() afterward.
     */
    public function addHtmlContent(string $content, string $charset = 'UTF-8')
    {
        // Use HTML5 parser if the content is HTML5 and the library is available
        $dom = null !== $this->html5Parser && strspn($content, " \t\r\n") === stripos($content, '<!doctype html>') ? $this->parseHtml5($content, $charset) : $this->parseXhtml($content, $charset);
        $this->addDocument($dom);

        $base = $this->filterRelativeXPath('descendant-or-self::base')->extract(['href']);

        $baseHref = current($base);
        if (\count($base) && !empty($baseHref)) {
            if ($this->baseHref) {
                $linkNode = $dom->createElement('a');
                $linkNode->setAttribute('href', $baseHref);
                $link = new Link($linkNode, $this->baseHref);
                $this->baseHref = $link->getUri();
            } else {
                $this->baseHref = $baseHref;
            }
        }
    }

    /**
     * Adds an XML content to the list of nodes.
     *
     * The libxml errors are disabled when the content is parsed.
     *
     * If you want to get parsing errors, be sure to enable
     * internal errors via libxml_use_internal_errors(true)
     * and then, get the errors via libxml_get_errors(). Be
     * sure to clear errors with libxml_clear_errors() afterward.
     *
     * @param int $options Bitwise OR of the libxml option constants
     *                     LIBXML_PARSEHUGE is dangerous, see
     *                     http://symfony.com/blog/security-release-symfony-2-0-17-released
     */
    public function addXmlContent(string $content, string $charset = 'UTF-8', int $options = LIBXML_NONET)
    {
        // remove the default namespace if it's the only namespace to make XPath expressions simpler
        if (!preg_match('/xmlns:/', $content)) {
            $content = str_replace('xmlns', 'ns', $content);
        }

        $internalErrors = libxml_use_internal_errors(true);
        $disableEntities = libxml_disable_entity_loader(true);

        $dom = new \DOMDocument('1.0', $charset);
        $dom->validateOnParse = true;

        if ('' !== trim($content)) {
            @$dom->loadXML($content, $options);
        }

        libxml_use_internal_errors($internalErrors);
        libxml_disable_entity_loader($disableEntities);

        $this->addDocument($dom);

        $this->isHtml = false;
    }

    /**
     * Adds a \DOMDocument to the list of nodes.
     *
     * @param \DOMDocument $dom A \DOMDocument instance
     */
    public function addDocument(\DOMDocument $dom)
    {
        if ($dom->documentElement) {
            $this->addNode($dom->documentElement);
        }
    }

    /**
     * Adds a \DOMNodeList to the list of nodes.
     *
     * @param \DOMNodeList $nodes A \DOMNodeList instance
     */
    public function addNodeList(\DOMNodeList $nodes)
    {
        foreach ($nodes as $node) {
            if ($node instanceof \DOMNode) {
                $this->addNode($node);
            }
        }
    }

    /**
     * Adds an array of \DOMNode instances to the list of nodes.
     *
     * @param \DOMNode[] $nodes An array of \DOMNode instances
     */
    public function addNodes(array $nodes)
    {
        foreach ($nodes as $node) {
            $this->add($node);
        }
    }

    /**
     * Adds a \DOMNode instance to the list of nodes.
     *
     * @param \DOMNode $node A \DOMNode instance
     */
    public function addNode(\DOMNode $node)
    {
        if ($node instanceof \DOMDocument) {
            $node = $node->documentElement;
        }

        if (null !== $this->document && $this->document !== $node->ownerDocument) {
            throw new \InvalidArgumentException('Attaching DOM nodes from multiple documents in the same crawler is forbidden.');
        }

        if (null === $this->document) {
            $this->document = $node->ownerDocument;
        }

        // Don't add duplicate nodes in the Crawler
        if (\in_array($node, $this->nodes, true)) {
            return;
        }

        $this->nodes[] = $node;
    }

    /**
     * Returns a node given its position in the node list.
     *
     * @return static
     */
    public function eq(int $position)
    {
        if (isset($this->nodes[$position])) {
            return $this->createSubCrawler($this->nodes[$position]);
        }

        return $this->createSubCrawler(null);
    }

    /**
     * Calls an anonymous function on each node of the list.
     *
     * The anonymous function receives the position and the node wrapped
     * in a Crawler instance as arguments.
     *
     * Example:
     *
     *     $crawler->filter('h1')->each(function ($node, $i) {
     *         return $node->text();
     *     });
     *
     * @param \Closure $closure An anonymous function
     *
     * @return array An array of values returned by the anonymous function
     */
    public function each(\Closure $closure)
    {
        $data = [];
        foreach ($this->nodes as $i => $node) {
            $data[] = $closure($this->createSubCrawler($node), $i);
        }

        return $data;
    }

    /**
     * Slices the list of nodes by $offset and $length.
     *
     * @return static
     */
    public function slice(int $offset = 0, int $length = null)
    {
        return $this->createSubCrawler(\array_slice($this->nodes, $offset, $length));
    }

    /**
     * Reduces the list of nodes by calling an anonymous function.
     *
     * To remove a node from the list, the anonymous function must return false.
     *
     * @param \Closure $closure An anonymous function
     *
     * @return static
     */
    public function reduce(\Closure $closure)
    {
        $nodes = [];
        foreach ($this->nodes as $i => $node) {
            if (false !== $closure($this->createSubCrawler($node), $i)) {
                $nodes[] = $node;
            }
        }

        return $this->createSubCrawler($nodes);
    }

    /**
     * Returns the first node of the current selection.
     *
     * @return static
     */
    public function first()
    {
        return $this->eq(0);
    }

    /**
     * Returns the last node of the current selection.
     *
     * @return static
     */
    public function last()
    {
        return $this->eq(\count($this->nodes) - 1);
    }

    /**
     * Returns the siblings nodes of the current selection.
     *
     * @return static
     *
     * @throws \InvalidArgumentException When current node is empty
     */
    public function siblings()
    {
        if (!$this->nodes) {
            throw new \InvalidArgumentException('The current node list is empty.');
        }

        return $this->createSubCrawler($this->sibling($this->getNode(0)->parentNode->firstChild));
    }

    public function matches(string $selector): bool
    {
        if (!$this->nodes) {
            return false;
        }

        $converter = $this->createCssSelectorConverter();
        $xpath = $converter->toXPath($selector, 'self::');

        return 0 !== $this->filterRelativeXPath($xpath)->count();
    }

    /**
     * Return first parents (heading toward the document root) of the Element that matches the provided selector.
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/API/Element/closest#Polyfill
     *
     * @throws \InvalidArgumentException When current node is empty
     */
    public function closest(string $selector): ?self
    {
        if (!$this->nodes) {
            throw new \InvalidArgumentException('The current node list is empty.');
        }

        $domNode = $this->getNode(0);

        while (XML_ELEMENT_NODE === $domNode->nodeType) {
            $node = $this->createSubCrawler($domNode);
            if ($node->matches($selector)) {
                return $node;
            }

            $domNode = $node->getNode(0)->parentNode;
        }

        return null;
    }

    /**
     * Returns the next siblings nodes of the current selection.
     *
     * @return static
     *
     * @throws \InvalidArgumentException When current node is empty
     */
    public function nextAll()
    {
        if (!$this->nodes) {
            throw new \InvalidArgumentException('The current node list is empty.');
        }

        return $this->createSubCrawler($this->sibling($this->getNode(0)));
    }

    /**
     * Returns the previous sibling nodes of the current selection.
     *
     * @return static
     *
     * @throws \InvalidArgumentException
     */
    public function previousAll()
    {
        if (!$this->nodes) {
            throw new \InvalidArgumentException('The current node list is empty.');
        }

        return $this->createSubCrawler($this->sibling($this->getNode(0), 'previousSibling'));
    }

    /**
     * Returns the parents nodes of the current selection.
     *
     * @return static
     *
     * @throws \InvalidArgumentException When current node is empty
     */
    public function parents()
    {
        if (!$this->nodes) {
            throw new \InvalidArgumentException('The current node list is empty.');
        }

        $node = $this->getNode(0);
        $nodes = [];

        while ($node = $node->parentNode) {
            if (XML_ELEMENT_NODE === $node->nodeType) {
                $nodes[] = $node;
            }
        }

        return $this->createSubCrawler($nodes);
    }

    /**
     * Returns the children nodes of the current selection.
     *
     * @return static
     *
     * @throws \InvalidArgumentException When current node is empty
     * @throws \RuntimeException         If the CssSelector Component is not available and $selector is provided
     */
    public function children(string $selector = null)
    {
        if (!$this->nodes) {
            throw new \InvalidArgumentException('The current node list is empty.');
        }

        if (null !== $selector) {
            $converter = $this->createCssSelectorConverter();
            $xpath = $converter->toXPath($selector, 'child::');

            return $this->filterRelativeXPath($xpath);
        }

        $node = $this->getNode(0)->firstChild;

        return $this->createSubCrawler($node ? $this->sibling($node) : []);
    }

    /**
     * Returns the attribute value of the first node of the list.
     *
     * @return string|null The attribute value or null if the attribute does not exist
     *
     * @throws \InvalidArgumentException When current node is empty
     */
    public function attr(string $attribute)
    {
        if (!$this->nodes) {
            throw new \InvalidArgumentException('The current node list is empty.');
        }

        $node = $this->getNode(0);

        return $node->hasAttribute($attribute) ? $node->getAttribute($attribute) : null;
    }

    /**
     * Returns the node name of the first node of the list.
     *
     * @return string The node name
     *
     * @throws \InvalidArgumentException When current node is empty
     */
    public function nodeName()
    {
        if (!$this->nodes) {
            throw new \InvalidArgumentException('The current node list is empty.');
        }

        return $this->getNode(0)->nodeName;
    }

    /**
     * Returns the node value of the first node of the list.
     *
     * @param mixed $default When provided and the current node is empty, this value is returned and no exception is thrown
     *
     * @return string The node value
     *
     * @throws \InvalidArgumentException When current node is empty
     */
    public function text($default = null)
    {
        if (!$this->nodes) {
            if (0 < \func_num_args()) {
                return $default;
            }

            throw new \InvalidArgumentException('The current node list is empty.');
        }

        return $this->getNode(0)->nodeValue;
    }

    /**
     * Returns the first node of the list as HTML.
     *
     * @param mixed $default When provided and the current node is empty, this value is returned and no exception is thrown
     *
     * @return string The node html
     *
     * @throws \InvalidArgumentException When current node is empty
     */
    public function html($default = null)
    {
        if (!$this->nodes) {
            if (0 < \func_num_args()) {
                return $default;
            }

            throw new \InvalidArgumentException('The current node list is empty.');
        }

        $node = $this->getNode(0);
        $owner = $node->ownerDocument;

        if (null !== $this->html5Parser && '<!DOCTYPE html>' === $owner->saveXML($owner->childNodes[0])) {
            $owner = $this->html5Parser;
        }

        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $owner->saveHTML($child);
        }

        return $html;
    }

    public function outerHtml(): string
    {
        if (!\count($this)) {
            throw new \InvalidArgumentException('The current node list is empty.');
        }

        $node = $this->getNode(0);
        $owner = $node->ownerDocument;

        if (null !== $this->html5Parser && '<!DOCTYPE html>' === $owner->saveXML($owner->childNodes[0])) {
            $owner = $this->html5Parser;
        }

        return $owner->saveHTML($node);
    }

    /**
     * Evaluates an XPath expression.
     *
     * Since an XPath expression might evaluate to either a simple type or a \DOMNodeList,
     * this method will return either an array of simple types or a new Crawler instance.
     *
     * @return array|Crawler An array of evaluation results or a new Crawler instance
     */
    public function evaluate(string $xpath)
    {
        if (null === $this->document) {
            throw new \LogicException('Cannot evaluate the expression on an uninitialized crawler.');
        }

        $data = [];
        $domxpath = $this->createDOMXPath($this->document, $this->findNamespacePrefixes($xpath));

        foreach ($this->nodes as $node) {
            $data[] = $domxpath->evaluate($xpath, $node);
        }

        if (isset($data[0]) && $data[0] instanceof \DOMNodeList) {
            return $this->createSubCrawler($data);
        }

        return $data;
    }

    /**
     * Extracts information from the list of nodes.
     *
     * You can extract attributes or/and the node value (_text).
     *
     * Example:
     *
     *     $crawler->filter('h1 a')->extract(['_text', 'href']);
     *
     * @return array An array of extracted values
     */
    public function extract(array $attributes)
    {
        $count = \count($attributes);

        $data = [];
        foreach ($this->nodes as $node) {
            $elements = [];
            foreach ($attributes as $attribute) {
                if ('_text' === $attribute) {
                    $elements[] = $node->nodeValue;
                } elseif ('_name' === $attribute) {
                    $elements[] = $node->nodeName;
                } else {
                    $elements[] = $node->getAttribute($attribute);
                }
            }

            $data[] = 1 === $count ? $elements[0] : $elements;
        }

        return $data;
    }

    /**
     * Filters the list of nodes with an XPath expression.
     *
     * The XPath expression is evaluated in the context of the crawler, which
     * is considered as a fake parent of the elements inside it.
     * This means that a child selector "div" or "./div" will match only
     * the div elements of the current crawler, not their children.
     *
     * @return static
     */
    public function filterXPath(string $xpath)
    {
        $xpath = $this->relativize($xpath);

        // If we dropped all expressions in the XPath while preparing it, there would be no match
        if ('' === $xpath) {
            return $this->createSubCrawler(null);
        }

        return $this->filterRelativeXPath($xpath);
    }

    /**
     * Filters the list of nodes with a CSS selector.
     *
     * This method only works if you have installed the CssSelector Symfony Component.
     *
     * @return static
     *
     * @throws \RuntimeException if the CssSelector Component is not available
     */
    public function filter(string $selector)
    {
        $converter = $this->createCssSelectorConverter();

        // The CssSelector already prefixes the selector with descendant-or-self::
        return $this->filterRelativeXPath($converter->toXPath($selector));
    }

    /**
     * Selects links by name or alt value for clickable images.
     *
     * @return static
     */
    public function selectLink(string $value)
    {
        return $this->filterRelativeXPath(
            sprintf('descendant-or-self::a[contains(concat(\' \', normalize-space(string(.)), \' \'), %1$s) or ./img[contains(concat(\' \', normalize-space(string(@alt)), \' \'), %1$s)]]', static::xpathLiteral(' '.$value.' '))
        );
    }

    /**
     * Selects images by alt value.
     *
     * @return static A new instance of Crawler with the filtered list of nodes
     */
    public function selectImage(string $value)
    {
        $xpath = sprintf('descendant-or-self::img[contains(normalize-space(string(@alt)), %s)]', static::xpathLiteral($value));

        return $this->filterRelativeXPath($xpath);
    }

    /**
     * Selects a button by name or alt value for images.
     *
     * @return static
     */
    public function selectButton(string $value)
    {
        return $this->filterRelativeXPath(
            sprintf('descendant-or-self::input[((contains(%1$s, "submit") or contains(%1$s, "button")) and contains(concat(\' \', normalize-space(string(@value)), \' \'), %2$s)) or (contains(%1$s, "image") and contains(concat(\' \', normalize-space(string(@alt)), \' \'), %2$s)) or @id=%3$s or @name=%3$s] | descendant-or-self::button[contains(concat(\' \', normalize-space(string(.)), \' \'), %2$s) or @id=%3$s or @name=%3$s]', 'translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")', static::xpathLiteral(' '.$value.' '), static::xpathLiteral($value))
        );
    }

    /**
     * Returns a Link object for the first node in the list.
     *
     * @return Link A Link instance
     *
     * @throws \InvalidArgumentException If the current node list is empty or the selected node is not instance of DOMElement
     */
    public function link(string $method = 'get')
    {
        if (!$this->nodes) {
            throw new \InvalidArgumentException('The current node list is empty.');
        }

        $node = $this->getNode(0);

        if (!$node instanceof \DOMElement) {
            throw new \InvalidArgumentException(sprintf('The selected node should be instance of DOMElement, got "%s".', \get_class($node)));
        }

        return new Link($node, $this->baseHref, $method);
    }

    /**
     * Returns an array of Link objects for the nodes in the list.
     *
     * @return Link[] An array of Link instances
     *
     * @throws \InvalidArgumentException If the current node list contains non-DOMElement instances
     */
    public function links()
    {
        $links = [];
        foreach ($this->nodes as $node) {
            if (!$node instanceof \DOMElement) {
                throw new \InvalidArgumentException(sprintf('The current node list should contain only DOMElement instances, "%s" found.', \get_class($node)));
            }

            $links[] = new Link($node, $this->baseHref, 'get');
        }

        return $links;
    }

    /**
     * Returns an Image object for the first node in the list.
     *
     * @return Image An Image instance
     *
     * @throws \InvalidArgumentException If the current node list is empty
     */
    public function image()
    {
        if (!\count($this)) {
            throw new \InvalidArgumentException('The current node list is empty.');
        }

        $node = $this->getNode(0);

        if (!$node instanceof \DOMElement) {
            throw new \InvalidArgumentException(sprintf('The selected node should be instance of DOMElement, got "%s".', \get_class($node)));
        }

        return new Image($node, $this->baseHref);
    }

    /**
     * Returns an array of Image objects for the nodes in the list.
     *
     * @return Image[] An array of Image instances
     */
    public function images()
    {
        $images = [];
        foreach ($this as $node) {
            if (!$node instanceof \DOMElement) {
                throw new \InvalidArgumentException(sprintf('The current node list should contain only DOMElement instances, "%s" found.', \get_class($node)));
            }

            $images[] = new Image($node, $this->baseHref);
        }

        return $images;
    }

    /**
     * Returns a Form object for the first node in the list.
     *
     * @return Form A Form instance
     *
     * @throws \InvalidArgumentException If the current node list is empty or the selected node is not instance of DOMElement
     */
    public function form(array $values = null, string $method = null)
    {
        if (!$this->nodes) {
            throw new \InvalidArgumentException('The current node list is empty.');
        }

        $node = $this->getNode(0);

        if (!$node instanceof \DOMElement) {
            throw new \InvalidArgumentException(sprintf('The selected node should be instance of DOMElement, got "%s".', \get_class($node)));
        }

        $form = new Form($node, $this->uri, $method, $this->baseHref);

        if (null !== $values) {
            $form->setValues($values);
        }

        return $form;
    }

    /**
     * Overloads a default namespace prefix to be used with XPath and CSS expressions.
     */
    public function setDefaultNamespacePrefix(string $prefix)
    {
        $this->defaultNamespacePrefix = $prefix;
    }

    public function registerNamespace(string $prefix, string $namespace)
    {
        $this->namespaces[$prefix] = $namespace;
    }

    /**
     * Converts string for XPath expressions.
     *
     * Escaped characters are: quotes (") and apostrophe (').
     *
     *  Examples:
     *
     *     echo Crawler::xpathLiteral('foo " bar');
     *     //prints 'foo " bar'
     *
     *     echo Crawler::xpathLiteral("foo ' bar");
     *     //prints "foo ' bar"
     *
     *     echo Crawler::xpathLiteral('a\'b"c');
     *     //prints concat('a', "'", 'b"c')
     *
     * @return string Converted string
     */
    public static function xpathLiteral(string $s)
    {
        if (false === strpos($s, "'")) {
            return sprintf("'%s'", $s);
        }

        if (false === strpos($s, '"')) {
            return sprintf('"%s"', $s);
        }

        $string = $s;
        $parts = [];
        while (true) {
            if (false !== $pos = strpos($string, "'")) {
                $parts[] = sprintf("'%s'", substr($string, 0, $pos));
                $parts[] = "\"'\"";
                $string = substr($string, $pos + 1);
            } else {
                $parts[] = "'$string'";
                break;
            }
        }

        return sprintf('concat(%s)', implode(', ', $parts));
    }

    /**
     * Filters the list of nodes with an XPath expression.
     *
     * The XPath expression should already be processed to apply it in the context of each node.
     *
     * @return static
     */
    private function filterRelativeXPath(string $xpath): object
    {
        $prefixes = $this->findNamespacePrefixes($xpath);

        $crawler = $this->createSubCrawler(null);

        foreach ($this->nodes as $node) {
            $domxpath = $this->createDOMXPath($node->ownerDocument, $prefixes);
            $crawler->add($domxpath->query($xpath, $node));
        }

        return $crawler;
    }

    /**
     * Make the XPath relative to the current context.
     *
     * The returned XPath will match elements matching the XPath inside the current crawler
     * when running in the context of a node of the crawler.
     */
    private function relativize(string $xpath): string
    {
        $expressions = [];

        // An expression which will never match to replace expressions which cannot match in the crawler
        // We cannot drop
        $nonMatchingExpression = 'a[name() = "b"]';

        $xpathLen = \strlen($xpath);
        $openedBrackets = 0;
        $startPosition = strspn($xpath, " \t\n\r\0\x0B");

        for ($i = $startPosition; $i <= $xpathLen; ++$i) {
            $i += strcspn($xpath, '"\'[]|', $i);

            if ($i < $xpathLen) {
                switch ($xpath[$i]) {
                    case '"':
                    case "'":
                        if (false === $i = strpos($xpath, $xpath[$i], $i + 1)) {
                            return $xpath; // The XPath expression is invalid
                        }
                        continue 2;
                    case '[':
                        ++$openedBrackets;
                        continue 2;
                    case ']':
                        --$openedBrackets;
                        continue 2;
                }
            }
            if ($openedBrackets) {
                continue;
            }

            if ($startPosition < $xpathLen && '(' === $xpath[$startPosition]) {
                // If the union is inside some braces, we need to preserve the opening braces and apply
                // the change only inside it.
                $j = 1 + strspn($xpath, "( \t\n\r\0\x0B", $startPosition + 1);
                $parenthesis = substr($xpath, $startPosition, $j);
                $startPosition += $j;
            } else {
                $parenthesis = '';
            }
            $expression = rtrim(substr($xpath, $startPosition, $i - $startPosition));

            if (0 === strpos($expression, 'self::*/')) {
                $expression = './'.substr($expression, 8);
            }

            // add prefix before absolute element selector
            if ('' === $expression) {
                $expression = $nonMatchingExpression;
            } elseif (0 === strpos($expression, '//')) {
                $expression = 'descendant-or-self::'.substr($expression, 2);
            } elseif (0 === strpos($expression, './/')) {
                $expression = 'descendant-or-self::'.substr($expression, 3);
            } elseif (0 === strpos($expression, './')) {
                $expression = 'self::'.substr($expression, 2);
            } elseif (0 === strpos($expression, 'child::')) {
                $expression = 'self::'.substr($expression, 7);
            } elseif ('/' === $expression[0] || '.' === $expression[0] || 0 === strpos($expression, 'self::')) {
                $expression = $nonMatchingExpression;
            } elseif (0 === strpos($expression, 'descendant::')) {
                $expression = 'descendant-or-self::'.substr($expression, 12);
            } elseif (preg_match('/^(ancestor|ancestor-or-self|attribute|following|following-sibling|namespace|parent|preceding|preceding-sibling)::/', $expression)) {
                // the fake root has no parent, preceding or following nodes and also no attributes (even no namespace attributes)
                $expression = $nonMatchingExpression;
            } elseif (0 !== strpos($expression, 'descendant-or-self::')) {
                $expression = 'self::'.$expression;
            }
            $expressions[] = $parenthesis.$expression;

            if ($i === $xpathLen) {
                return implode(' | ', $expressions);
            }

            $i += strspn($xpath, " \t\n\r\0\x0B", $i + 1);
            $startPosition = $i + 1;
        }

        return $xpath; // The XPath expression is invalid
    }

    /**
     * @return \DOMElement|null
     */
    public function getNode(int $position)
    {
        return isset($this->nodes[$position]) ? $this->nodes[$position] : null;
    }

    /**
     * @return int
     */
    public function count()
    {
        return \count($this->nodes);
    }

    /**
     * @return \ArrayIterator|\DOMElement[]
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->nodes);
    }

    /**
     * @param \DOMElement $node
     *
     * @return array
     */
    protected function sibling($node, string $siblingDir = 'nextSibling')
    {
        $nodes = [];

        $currentNode = $this->getNode(0);
        do {
            if ($node !== $currentNode && XML_ELEMENT_NODE === $node->nodeType) {
                $nodes[] = $node;
            }
        } while ($node = $node->$siblingDir);

        return $nodes;
    }

    private function parseHtml5(string $htmlContent, string $charset = 'UTF-8'): \DOMDocument
    {
        return $this->html5Parser->parse($this->convertToHtmlEntities($htmlContent, $charset), [], $charset);
    }

    private function parseXhtml(string $htmlContent, string $charset = 'UTF-8'): \DOMDocument
    {
        $htmlContent = $this->convertToHtmlEntities($htmlContent, $charset);

        $internalErrors = libxml_use_internal_errors(true);
        $disableEntities = libxml_disable_entity_loader(true);

        $dom = new \DOMDocument('1.0', $charset);
        $dom->validateOnParse = true;

        if ('' !== trim($htmlContent)) {
            @$dom->loadHTML($htmlContent);
        }

        libxml_use_internal_errors($internalErrors);
        libxml_disable_entity_loader($disableEntities);

        return $dom;
    }

    /**
     * Converts charset to HTML-entities to ensure valid parsing.
     */
    private function convertToHtmlEntities(string $htmlContent, string $charset = 'UTF-8'): string
    {
        set_error_handler(function () { throw new \Exception(); });

        try {
            return mb_convert_encoding($htmlContent, 'HTML-ENTITIES', $charset);
        } catch (\Exception $e) {
            try {
                $htmlContent = iconv($charset, 'UTF-8', $htmlContent);
                $htmlContent = mb_convert_encoding($htmlContent, 'HTML-ENTITIES', 'UTF-8');
            } catch (\Exception $e) {
            }

            return $htmlContent;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function createDOMXPath(\DOMDocument $document, array $prefixes = []): \DOMXPath
    {
        $domxpath = new \DOMXPath($document);

        foreach ($prefixes as $prefix) {
            $namespace = $this->discoverNamespace($domxpath, $prefix);
            if (null !== $namespace) {
                $domxpath->registerNamespace($prefix, $namespace);
            }
        }

        return $domxpath;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function discoverNamespace(\DOMXPath $domxpath, string $prefix): ?string
    {
        if (isset($this->namespaces[$prefix])) {
            return $this->namespaces[$prefix];
        }

        // ask for one namespace, otherwise we'd get a collection with an item for each node
        $namespaces = $domxpath->query(sprintf('(//namespace::*[name()="%s"])[last()]', $this->defaultNamespacePrefix === $prefix ? '' : $prefix));

        return ($node = $namespaces->item(0)) ? $node->nodeValue : null;
    }

    private function findNamespacePrefixes(string $xpath): array
    {
        if (preg_match_all('/(?P<prefix>[a-z_][a-z_0-9\-\.]*+):[^"\/:]/i', $xpath, $matches)) {
            return array_unique($matches['prefix']);
        }

        return [];
    }

    /**
     * Creates a crawler for some subnodes.
     *
     * @param \DOMElement|\DOMElement[]|\DOMNodeList|null $nodes
     *
     * @return static
     */
    private function createSubCrawler($nodes): object
    {
        $crawler = new static($nodes, $this->uri, $this->baseHref);
        $crawler->isHtml = $this->isHtml;
        $crawler->document = $this->document;
        $crawler->namespaces = $this->namespaces;
        $crawler->html5Parser = $this->html5Parser;

        return $crawler;
    }

    /**
     * @throws \RuntimeException If the CssSelector Component is not available
     */
    private function createCssSelectorConverter(): CssSelectorConverter
    {
        if (!class_exists(CssSelectorConverter::class)) {
            throw new \LogicException('To filter with a CSS selector, install the CssSelector component ("composer require symfony/css-selector"). Or use filterXpath instead.');
        }

        return new CssSelectorConverter($this->isHtml);
    }
}
