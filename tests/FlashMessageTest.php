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

namespace Wedeto\Application;

use PHPUnit\Framework\TestCase;
use Wedeto\Util\Dictionary;
use Wedeto\HTTP\Request;

use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamWrapper;
use org\bovigo\vfs\vfsStreamDirectory;

/**
 * @covers Wedeto\Application\FlashMessage
 */
final class FlashMessageTest extends TestCase
{
    protected $storage;

    public function setUp()
    {
        $this->storage = new Dictionary;
        FlashMessage::setStorage($this->storage);
    }

    public function tearDown()
    {
        FlashMessage::setStorage();
    }

    /**
     * @covers Wedeto\Application\FlashMessage::__construct
     * @covers Wedeto\Application\FlashMessage::getMessage
     * @covers Wedeto\Application\FlashMessage::getType
     * @covers Wedeto\Application\FlashMessage::getTypeName
     * @covers Wedeto\Application\FlashMessage::hasNext
     * @covers Wedeto\Application\FlashMessage::next
     * @covers Wedeto\Application\FlashMessage::count
     */
    public function testFlashMessage()
    {
        // Make sure there are no flash messages in the queue
        $this->assertEquals(0, FlashMessage::count());

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
                $this->assertEquals("Message 1", $fm->getMessage());
                $this->assertEquals(FlashMessage::ERROR, $fm->getType());
                $this->assertEquals("ERROR", $fm->getTypeName());
            }
            elseif ($cnt === 1)
            {
                $this->assertEquals("Message 2", $fm->getMessage());
                $this->assertEquals(FlashMessage::WARNING, $fm->getType());
                $this->assertEquals(FlashMessage::WARN, $fm->getType());
                $this->assertEquals("WARNING", $fm->getTypeName());
            }
            elseif ($cnt === 2)
            {
                $this->assertEquals("Message 3", $fm->getMessage());
                $this->assertEquals(FlashMessage::INFO, $fm->getType());
                $this->assertEquals("INFO", $fm->getTypeName());
            }
            elseif ($cnt === 3)
            {
                $this->assertEquals("Message 4", $fm->getMessage());
                $this->assertEquals(FlashMessage::SUCCESS, $fm->getType());
                $this->assertEquals("SUCCESS", $fm->getTypeName());
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
     * @covers Wedeto\Application\FlashMessage::count
     */
    public function testFlashMessageCountEmpty()
    {
        $this->assertEquals(0, FlashMessage::count());

        $fm = new FlashMessage("FOO");
        $this->assertEquals(1, FlashMessage::count());

        unset($this->storage['WEDETO_FM']);
        $this->assertEquals(0, FlashMessage::count());
    }

    /**
     * @covers Wedeto\Application\FlashMessage::__construct
     */
    public function testNoSession()
    {
        FlashMessage::setStorage();

        $this->expectException(\RuntimeException::class);
        $fm = new FlashMessage('error');
    }

    /**
     * @covers Wedeto\Application\FlashMessage::hasNext
     * @covers Wedeto\Application\FlashMessage::next
     */
    public function testNoNextAvailable()
    {
        $this->assertEquals(FlashMessage::count(), 0);

        $this->assertNull(FlashMessage::next());
    }

    public function testNoMessages()
    {
        FlashMessage::setStorage();
        $this->assertEquals(0, FlashMessage::count());
    }
}
