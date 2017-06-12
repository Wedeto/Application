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
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamWrapper;
use org\bovigo\vfs\vfsStreamDirectory;

use Wedeto\Util\Dictionary;

use Wedeto\Log\Logger;

/**
 * @covers Wedeto\Application\Application
 */
final class ApplicationTest extends TestCase
{
    public function setUp()
    {
        Logger::resetGlobalState(); 
        vfsStreamWrapper::register();
        vfsStreamWrapper::setRoot(new vfsStreamDirectory('appdir'));
        $this->wedetoroot = vfsStream::url('appdir');

        mkdir($this->wedetoroot . DIRECTORY_SEPARATOR . 'config');
        mkdir($this->wedetoroot . DIRECTORY_SEPARATOR . 'http');

        PathConfig::setInstance();
        $this->pathconfig = new PathConfig($this->wedetoroot);
    }

    public function tearDown()
    {
        Logger::resetGlobalState(); 
    }

    /**
     * @covers Wedeto\Application\Application::getInstance
     */
    public function testInstance()
    {
        $config = new Dictionary();

        $app = Application::setup($this->pathconfig, $config);
        $this->assertInstanceOf(Application::class, $app);
    }
}
