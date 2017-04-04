<?php
/*
This is part of Wedeto, the WEb DEvelopment TOolkit.
It is published under the MIT Open Source License.

Copyright 2017, Egbert van der Wal

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to
use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
the Software, and to permit persons to whom the Software is furnished to do so,
subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
*/

namespace Wedeto\Platform;

use PHPUnit\Framework\TestCase;

/**
 * @covers Wedeto\Platform\SafeHTML
 */
final class SafeHTMLTest extends TestCase
{
    public $counter = 0;

    /**
     * @covers Wedeto\Platform\SafeHTML::__construct
     * @covers Wedeto\Platform\SafeHTML::setDefault
     * @covers Wedeto\Platform\SafeHTML::allowTag
     * @covers Wedeto\Platform\SafeHTML::removeTag
     * @covers Wedeto\Platform\SafeHTML::allowAttribute
     * @covers Wedeto\Platform\SafeHTML::getHTML
     * @covers Wedeto\Platform\SafeHTML::addCallback
     * @covers Wedeto\Platform\SafeHTML::allowLinks
     * @covers Wedeto\Platform\SafeHTML::allowProtocol
     * @covers Wedeto\Platform\SafeHTML::sanitizeNode
     * @covers Wedeto\Platform\SafeHTML::unwrapContents
     * @covers Wedeto\Platform\SafeHTML::sanitizeAttributes
     */
    public function testSafeHTML()
    {
        $html = "<p><a href=\"javascript:alert('evil');\">Click me</a> <a href=\"http://example.com\">Click me</a> <span title=\"test\" onclick=\"alert('evil');\">Click me</span><script>myEvilFunction();</script>";

        $safe = new SafeHTML($html, false);
        $clean = $safe->getHTML();
        $this->assertEquals($clean, "Click me Click me Click me");

        $safe->allowTag('a');
        $safe->allowAttribute('href');
        $safe->allowAttribute('title');
        $safe->removeTag('script');
        $clean = $safe->getHTML();
        $this->assertEquals($clean, "<a>Click me</a> <a>Click me</a> Click me");

        $safe->allowProtocol('javascript');
        $clean = $safe->getHTML();
        $this->assertEquals($clean, "<a href=\"javascript:alert('evil');\">Click me</a> <a>Click me</a> Click me");

        $safe->allowAttribute('onclick');
        $safe->allowTag('span');
        $clean = $safe->getHTML();
        $this->assertEquals($clean, "<a href=\"javascript:alert('evil');\">Click me</a> <a>Click me</a>"
            . " <span title=\"test\" onclick=\"alert('evil');\">Click me</span>");

        $safe = new SafeHTML($html, false);
        $safe->allowLinks();
        $safe->allowProtocol('http');
        $clean = $safe->getHTML();
        $this->assertEquals($clean, "<a>Click me</a> <a href=\"http://example.com\">Click me</a> Click me");

        $html2 = '<a href="//example.com/">Click me</a>';
        $safe = new SafeHTML($html2, false);
        $safe->allowLinks();
        $safe->allowProtocol(array('http', 'https'));
        $clean = $safe->getHTML();
        $this->assertEquals($clean, "<a href=\"//example.com/\">Click me</a>");

        $html2 = '<a href="/login">Click me</a>';
        $safe = new SafeHTML($html2, false);
        $safe->allowLinks();
        $clean = $safe->getHTML();
        $this->assertEquals($clean, "<a href=\"/login\">Click me</a>");

        $safe = new SafeHTML($html, false);
        $safe->setDefault();
        $clean = $safe->getHTML();
        $this->assertEquals($clean, "<p>Click me Click me <span title=\"test\">Click me</span></p>");

        $safe = new SafeHTML($html, false);
        $safe->removeTag('p');
        $safe->setDefault();
        $clean = $safe->getHTML();
        $this->assertEquals($clean, "<p>Click me Click me <span title=\"test\">Click me</span></p>");

        $safe = new SafeHTML($html, true);
        $safe->removeTag('p');
        $safe->allowTag('p');
        $clean = $safe->getHTML();
        $this->assertEquals($clean, "<p>Click me Click me <span title=\"test\">Click me</span></p>");

        $safe = new SafeHTML($html, true);
        $safe->removeTag(array('p'));
        $safe->allowTag('p');
        $clean = $safe->getHTML();
        $this->assertEquals($clean, "<p>Click me Click me <span title=\"test\">Click me</span></p>");

        $safe = new SafeHTML($html, false);
        $safe->allowTag('a');
        $this->counter = 0;
        $test = $this;
        $safe->addCallback('a', function ($tag) use ($test) {
            $list = array();
            foreach ($tag->attributes as $attrib)
                $list[] = $attrib;

            foreach ($list as $attrib)
                $child->removeAttributeNode($attrib);
            ++$test->counter;
        });

        $clean = $safe->getHTML();
        $this->assertEquals($test->counter, 2);
        $this->assertEquals($clean, '<a>Click me</a> <a>Click me</a> Click me');

        $this->expectException(\RuntimeException::class);
        $safe->addCallback('a', array('non', 'existing', 'function'));
    }

    public function testInvalidHTML()
    {
        $html = '<a href="login">test</span> <a>test2</a> <span>test3</span>';

        $safe = new SafeHTML($html, false);
        $safe->allowTag('a');
        $clean = $safe->getHTML();
        $this->assertEquals($clean, '<a>test </a><a>test2</a> test3');

        $safe = new SafeHTML($html, false);
        $safe->allowTag('span');
        $clean = $safe->getHTML();
        $this->assertEquals($clean, 'test test2 <span>test3</span>');
    }
}
