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

namespace Wedeto;

use PHPUnit\Framework\TestCase;
use Wedeto\Http\Request;

/**
 * @covers Wedeto\FlashMessage
 */
final class FlashMessageTest extends TestCase
{
    public function setUp()
    {
        $this->request = new MockFlashMessageRequest;
        System::getInstance()->request = $this->request;
    }

    public function tearDown()
    {
        System::getInstance()->request = null;
    }

    /**
     * @covers Wedeto\FlashMessage::__construct
     * @covers Wedeto\FlashMessage::getMessage
     * @covers Wedeto\FlashMessage::getType
     * @covers Wedeto\FlashMessage::getTypeName
     * @covers Wedeto\FlashMessage::hasNext
     * @covers Wedeto\FlashMessage::next
     * @covers Wedeto\FlashMessage::count
     */
    public function testFlashMessage()
    {
        // Makre sure there are no flash messages in the queue
        while (FlashMessage::hasNext())
            FlashMessage::next();

        $msgs = array(
            "Message 1" => FlashMessage::ERROR,
            "Message 2" => FlashMessage::WARNING,
            "Message 3" => FlashMessage::INFO,
            "Message 4" => FlashMessage::SUCCESS
        );

        $fms = array();
        $start_t = time();
        foreach ($msgs as $key => $val)
        {
            $fm = new FlashMessage($key, $val);
            $fms[] = $fm;
        }
        $end_t = time();
        
        $this->assertEquals(4, FlashMessage::count());

        $cnt = 0;
        while (FlashMessage::hasNext())
        {
            $fm = FlashMessage::next();

            $this->assertEquals($fm->getDate()->getTimestamp(), $fms[$cnt]->getDate()->getTimestamp());
            if ($cnt === 0)
            {
                $this->assertEquals($fm->getMessage(), "Message 1");
                $this->assertEquals($fm->getType(), FlashMessage::ERROR);
                $this->assertEquals($fm->getTypeName(), "ERROR");
            }
            elseif ($cnt === 1)
            {
                $this->assertEquals($fm->getMessage(), "Message 2");
                $this->assertEquals($fm->getType(), FlashMessage::WARNING);
                $this->assertEquals($fm->getTypeName(), "WARNING");
            }
            elseif ($cnt === 2)
            {
                $this->assertEquals($fm->getMessage(), "Message 3");
                $this->assertEquals($fm->getType(), FlashMessage::INFO);
                $this->assertEquals($fm->getTypeName(), "INFO");
            }
            elseif ($cnt === 3)
            {
                $this->assertEquals($fm->getMessage(), "Message 4");
                $this->assertEquals($fm->getType(), FlashMessage::SUCCESS);
                $this->assertEquals($fm->getTypeName(), "SUCCESS");
            }

            $t = $fm->getDate();
            $this->assertInstanceOf(\DateTime::class, $t);
            $t = $t->getTimestamp();

            $this->assertTrue($t >= $start_t);
            $this->assertTrue($t <= $end_t);

            ++$cnt;
        }

        $this->assertEquals($cnt, 4);
    }

    /**
     * @covers Wedeto\FlashMessage::count
     */
    public function testFlashMessageCountEmpty()
    {
        unset($this->request->session['WEDETO_FM']);
        $this->assertEquals(FlashMessage::count(), 0);
    }

    /**
     * @covers Wedeto\FlashMessage::__construct
     */
    public function testNoSession()
    {
        $this->request->session = null;

        $this->expectException(\RuntimeException::class);
        $fm = new FlashMessage('error');
    }

    /**
     * @covers Wedeto\FlashMessage::hasNext
     * @covers Wedeto\FlashMessage::next
     */
    public function testNoNextAvailable()
    {
        while (FlashMessage::hasNext())
            FlashMessage::next();

        $this->assertNull(FlashMessage::next());
    }

    public function testNoMessages()
    {
        $this->request->session = null;
        $this->assertEquals(0, FlashMessage::count());
    }
}

class MockFlashMessageRequest extends Http\Request
{
    public function __construct()
    {
        $this->session = new Dictionary();
    }
}
