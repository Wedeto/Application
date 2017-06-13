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
use Wedeto\Util\Hook;

use Wedeto\Log\Logger;
use Wedeto\Log\Writer\MemLogWriter;

use Wedeto\Application\Dispatch\Dispatcher;

use Wedeto\Resolve\Manager as ResolveManager;

use Wedeto\HTTP\Request;
use Wedeto\HTTP\Responder;
use Wedeto\HTTP\Response\{StringResponse, DataResponse};

/**
 * @covers Wedeto\Application\LogAttachHook
 */
class LogAttachHookTest extends TestCase
{
    public function setUp()
    {
        $this->memlogger = new MemLogWriter("DEBUG");
        $root = Logger::getLogger('');
        $root->addLogWriter($this->memlogger);

        $this->resolver = new ResolveManager();
        $req = Request::createFromGlobals();
        $this->dispatcher = new Dispatcher($req, $this->resolver);
        $this->lahook = new LogAttachHook($this->memlogger, $this->dispatcher);

        $this->assertSame($this->memlogger, $this->lahook->getMemLogWriter());
        $this->assertSame($this->dispatcher, $this->lahook->getDispatcher());
        $this->assertSame($this->lahook, $this->lahook->setMemLogWriter($this->memlogger));

        $this->lahook->attach();

        $wedeto_logger = Logger::getLogger('Wedeto.Test');
        $wedeto_logger->info('Foo <>bar');

    }

    public function tearDown()
    {
        Logger::resetGlobalState();
    }

    public function testLogHookWithPlainText()
    {
        // -------------------------
        $req = Request::createFromGlobals();
        $_SERVER['HTTP_ACCEPT'] = 'text/plain';
        $dispatcher = new Dispatcher($req, $this->resolver);
        $this->assertSame($this->lahook, $this->lahook->setDispatcher($dispatcher));

        $responder = new MockLogAttachResponder($req);
        $response = new StringResponse("Foobar", "text/plain");
        $this->assertSame($responder, $responder->setResponse($response));

        $lvl = ob_get_level();
        try
        {
            $responder->respond();
        }
        catch (StringResponse $e)
        {
            $this->assertSame($response, $e);

            $content = $response->getOutput('text/plain');
            $this->assertContains('// DEVELOPMENT LOG', $content);
            $this->assertContains('Foo <>bar', $content);
        }

        while ($lvl !== ob_get_level())
        {
            ob_start();
        }
    }

    public function testLogHookWithHTMLResponse()
    {
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        $req = Request::createFromGlobals();
        $dispatcher = new Dispatcher($req, $this->resolver);
        $this->assertSame($this->lahook, $this->lahook->setDispatcher($dispatcher));

        $response = new StringResponse("Foobar", "text/html");
        $responder = new MockLogAttachResponder($req);
        $this->assertSame($responder, $responder->setResponse($response));

        $lvl = ob_get_level();
        try
        {
            $responder->respond();
        }
        catch (StringResponse $e)
        {
            $this->assertSame($response, $e);

            $content = $response->getOutput('text/html');
            $this->assertContains('<!-- DEVELOPMENT LOG -->', $content);
            $this->assertContains('Foo &lt;&gt;bar', $content);
        }

        while ($lvl !== ob_get_level())
        {
            ob_start();
        }
    }

    public function testLogHookWithJSONResponse()
    {
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $req = Request::createFromGlobals();
        $dispatcher = new Dispatcher($req, $this->resolver);
        $this->assertSame($this->lahook, $this->lahook->setDispatcher($dispatcher));

        $response = new DataResponse(['foo' => 'bar']);
        $responder = new MockLogAttachResponder($req);
        $this->assertSame($responder, $responder->setResponse($response));

        $lvl = ob_get_level();
        try
        {
            $responder->respond();
        }
        catch (DataResponse $e)
        {
            $this->assertSame($response, $e);

            $content = $response->getData();
            $this->assertInstanceOf(Dictionary::class, $content);

            $this->assertTrue(isset($content['devlog']));
            $this->assertEquals(1, count($content['devlog']));
            $line = $content['devlog'][0];

            $this->assertContains('Foo <>bar', $line);
        }

        while ($lvl !== ob_get_level())
        {
            ob_start();
        }
    }
}

class MockLogAttachResponder extends Responder
{
    protected function doOutput(string $mime)
    {
        throw $this->response;
    }
}
