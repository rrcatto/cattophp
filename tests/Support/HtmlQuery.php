<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Support;

use LogicException;

/** XPath over drawn HTML for a test's assertions, its answers always a list of nodes. */
final class HtmlQuery
{
    public function __construct(private readonly \DOMXPath $xpath)
    {
    }

    /** @return \DOMNodeList<\DOMNode> */
    public function query(string $expression): \DOMNodeList
    {
        $nodes = $this->xpath->query($expression);
        if (!$nodes instanceof \DOMNodeList) throw new LogicException('Not an XPath expression: ' . $expression);

        /** @var \DOMNodeList<\DOMNode> $nodes */
        return $nodes;
    }

    /** @return list<\DOMElement> */
    public function elements(string $expression): array
    {
        return array_values(array_filter(iterator_to_array($this->query($expression)), static fn (\DOMNode $node): bool => $node instanceof \DOMElement));
    }

    public function evaluate(string $expression): mixed
    {
        return $this->xpath->evaluate($expression);
    }
}
