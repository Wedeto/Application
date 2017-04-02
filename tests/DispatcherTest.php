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

namespace Wedeto\HTTP;

use PHPUnit\Framework\TestCase;
use Wedeto\Resolve\Resolver;
use Wedeto\System;
use Wedeto\Path;
use Wedeto\Template;
use Wedeto\Dictionary;
use Wedeto\HTTP\RedirectRequest;

/**
 * @covers Wedeto\HTTP\Request
 */
final class RequestTest extends TestCase
{
    private $get;
    private $post;
    private $server;
    private $cookie;
    private $config;

    private $path;
    private $resolve;

    public function setUp()
    {
        $this->get = array(
            'foo' => 'bar'
        );

        $this->post = array(
            'test' => 'value'
        );

        $this->server = array(
            'REQUEST_SCHEME' => 'https',
            'SERVER_NAME' => 'www.example.com',
            'REQUEST_URI' => '/foo',
            'REMOTE_ADDR' => '127.0.0.1',
            'REQUEST_METHOD' => 'POST',
            'REQUEST_TIME_FLOAT' => $_SERVER['REQUEST_TIME_FLOAT'],
            'HTTP_ACCEPT' => 'text/plain;q=1,text/html;q=0.9'
        );

        $this->cookie = array(
            'session_id' => '1234'
        );

        $config = array(
            'site' => array(
                'url' => 'http://www.example.com',
                'language' => 'en'
            ),
            'cookie' => array(
                'lifetime' => 60,
                'httponly' => true
            )
        );
        $this->config = new Dictionary($config);

        $this->path = System::path();
        $this->resolve = new Resolver($this->path);
    }

    /**
     * @covers Wedeto\HTTP\Request::__construct
     * @covers Wedeto\Session::start
     */
    public function testRequestVariables()
    {
        $req = new Request($this->get, $this->post, $this->cookie, $this->server, $this->config, $this->path, $this->resolve);

        $this->assertEquals($req->get->getAll(), $this->get);
        $this->assertEquals($req->post->getAll(), $this->post);
        $this->assertEquals($req->cookie->getAll(), $this->cookie);
        $this->assertEquals($req->server->getAll(), $this->server);

        $this->get['foobarred_get'] = true;
        $this->assertEquals($req->get->getAll(), $this->get);

        $this->post['foobarred_post'] = true;
        $this->assertEquals($req->post->getAll(), $this->post);

        $this->cookie['foobarred_cookie'] = true;
        $this->assertEquals($req->cookie->getAll(), $this->cookie);

        $this->server['foobarred_server'] = true;
        $this->assertEquals($req->server->getAll(), $this->server);
    }

    /**
     * @covers Wedeto\HTTP\Request::__construct
     * @covers Wedeto\HTTP\Request::determineVirtualHost
     * @covers Wedeto\HTTP\Request::resolveApp
     */
    public function testRouting()
    {
        $this->server['SERVER_NAME'] = 'www.example.com';
        $this->server['REQUEST_URI'] = '/foo';
        $req = new Request($this->get, $this->post, $this->cookie, $this->server, $this->config, $this->path, $this->resolve);
        $req->resolveApp();
        $this->assertEquals('/', $req->route);

        $this->server['REQUEST_URI'] = '/assets';
        $req = new Request($this->get, $this->post, $this->cookie, $this->server, $this->config, $this->path, $this->resolve);
        $req->resolveApp();
        $this->assertEquals('/assets', $req->route);
    }

    /**
     * @covers Wedeto\HTTP\Request::__construct
     * @covers Wedeto\Session::start
     */
    public function testRoutingInvalid()
    {
        $resolve = new MockRequestResolver();
        $this->server['SERVER_NAME'] = 'www.example.com';
        $this->server['REQUEST_URI'] = '/foo';

        $req = new Request($this->get, $this->post, $this->cookie, $this->server, $this->config, $this->path, $resolve);
        $req->resolveApp();
        $this->assertNull($req->route);
        $this->assertNull($req->app);

        $this->server['REQUEST_URI'] = '/';
        $req = new Request($this->get, $this->post, $this->cookie, $this->server, $this->config, $this->path, $resolve);
        $req->resolveApp();
        $this->assertNull($req->route);
        $this->assertNull($req->app);
    }

    /**
     * @covers Wedeto\HTTP\Request::__construct
     * @covers Wedeto\HTTP\Request::findVirtualHost
     * @covers Wedeto\HTTP\Request::handleUnknownHost
     */
    public function testRoutingInvalidHostIgnorePolicy()
    {
        $this->server['REQUEST_SCHEME'] = 'https';
        $this->server['SERVER_NAME'] = 'www.example.nl';
        $this->server['REQUEST_URI'] = '/assets';
        $req = new Request($this->get, $this->post, $this->cookie, $this->server, $this->config, $this->path, $this->resolve);
        $req->resolveApp();
        $this->assertEquals($req->route, '/assets');
    }

    /**
     * @covers Wedeto\HTTP\Request::__construct
     * @covers Wedeto\HTTP\Request::findVirtualHost
     * @covers Wedeto\HTTP\Request::handleUnknownHost
     */
    public function testRoutingInvalidHostErrorPolicy()
    {
        $this->server['REQUEST_SCHEME'] = 'https';
        $this->server['SERVER_NAME'] = 'www.example.nl';
        $this->server['REQUEST_URI'] = '/assets';
        $this->config['site']['unknown_host_policy'] = 'ERROR';
        $this->expectException(Error::class);
        $this->expectExceptionCode(404);
        $this->expectExceptionMessage('Not found');
        $req = new Request($this->get, $this->post, $this->cookie, $this->server, $this->config, $this->path, $this->resolve);
        $req->resolveApp();
    }

    /**
     * @covers Wedeto\HTTP\Request::__construct
     * @covers Wedeto\HTTP\Request::findVirtualHost
     * @covers Wedeto\HTTP\Request::handleUnknownHost
     */
    public function testRoutingRedirectHost()
    {
        $this->server['REQUEST_SCHEME'] = 'http';
        $this->server['SERVER_NAME'] = 'www.example.nl';
        $this->server['REQUEST_URI'] = '/assets';
        $this->config['site'] = 
            array(
                'url' => array(
                    'http://www.example.com',
                    'http://www.example.nl'
                ),
                'language' => array('en'),
                'redirect' => array(1 => 'http://www.example.com/')
            );

        $this->expectException(RedirectRequest::class);
        $req = new Request($this->get, $this->post, $this->cookie, $this->server, $this->config, $this->path, $this->resolve);
        $req->resolveApp();
    }

    /**
     * @covers Wedeto\HTTP\Request::findBestMatching
     * @covers Wedeto\HTTP\Request::handleUnknownHost
     */
    public function testNoSiteConfig()
    {
        $this->server['REQUEST_SCHEME'] = 'https';
        $this->server['SERVER_NAME'] = 'www.example.nl';
        $this->server['REQUEST_URI'] = '/assets';
        $this->config['site'] = array();

        $req = new Request($this->get, $this->post, $this->cookie, $this->server, $this->config, $this->path, $this->resolve);
        $req->resolveApp();
        $this->assertEquals($this->get, $req->get->getAll());
    }

    /**
     * @covers Wedeto\HTTP\Request::parseAccept
     */
    public function testAcceptParser()
    {
        unset($this->server['HTTP_ACCEPT']);
        $req = new Request($this->get, $this->post, $this->cookie, $this->server, $this->config, $this->path, $this->resolve);
        $this->assertEquals($req->accept, array('text/html' => 1.0));

        $accept = Request::parseAccept("garbage");
        $this->assertEquals($accept, array("garbage" => 1.0));
    }

    /**
     * @covers Wedeto\HTTP\Request::cli
     */
    public function testCLI()
    {
        $this->assertTrue(Request::cli());
    }

    /**
     * @covers Wedeto\HTTP\Request::isAccepted
     * @covers Wedeto\HTTP\Request::getBestResponseType
     */
    public function testAccept()
    {
        $request = new Request($this->get, $this->post, $this->cookie, $this->server, $this->config, $this->path, $this->resolve);
        $request->accept = array(); 
        $this->assertTrue($request->isAccepted("text/html") == true);
        $this->assertTrue($request->isAccepted("foo/bar") == true);

        $request->accept = array(
            'text/html' => 0.9,
            'text/plain' => 0.8,
            'application/*' => 0.7
        );

        $this->assertTrue($request->isAccepted("text/html") == true);
        $this->assertTrue($request->isAccepted("text/plain") == true);
        $this->assertTrue($request->isAccepted("application/bar") == true);
        $this->assertFalse($request->isAccepted("foo/bar") == true);

        $resp = $request->getBestResponseType(array('foo/bar', 'application/bar/', 'text/plain', 'text/html'));
        $this->assertEquals($resp, "text/html");

        $resp = $request->getBestResponseType(array('application/bar', 'application/foo'));
        $this->assertEquals($resp, "application/bar");

        $resp = $request->getBestResponseType(array('application/foo', 'application/bar'));
        $this->assertEquals($resp, "application/foo");

        $request->accept = array();
        $resp = $request->getBestResponseType(array('foo/bar', 'application/bar/', 'text/plain', 'text/html'));
        $this->assertEquals($resp, "text/plain");

        $op = array(
            'text/plain' => 'Plain text',
            'text/html' => 'HTML Text'
        );

        ob_start();
        $request->outputBestResponseType($op);
        $c = ob_get_contents();
        ob_end_clean();
    }

    public function testGetStartTime()
    {
        $request = new Request($this->get, $this->post, $this->cookie, $this->server, $this->config, $this->path, $this->resolve);
        $this->assertEquals($_SERVER['REQUEST_TIME_FLOAT'], $request->getStartTime());
    }

    public function testGetTemplate()
    {
        $request = new Request($this->get, $this->post, $this->cookie, $this->server, $this->config, $this->path, $this->resolve);

        $tpl = $request->getTemplate();
        $this->assertInstanceOf(\Wedeto\Template::class, $tpl);

        $tpl2 = $request->getTemplate();
        $this->assertInstanceOf(\Wedeto\Template::class, $tpl);
    }

    public function testGetResponseBuilder()
    {
        $request = new Request($this->get, $this->post, $this->cookie, $this->server, $this->config, $this->path, $this->resolve);
        $rb = $request->getResponseBuilder();
        $this->assertInstanceOf(ResponseBuilder::class, $rb);
    }

    public function testDispatchNoRoute()
    {
        $request = new MockRequestTestRequest(new Dictionary($this->server), new Dictionary($this->config));
        $request->route = null;
        
        $this->expectException(Error::class);
        $this->expectExceptionCode(404);
        $request->dispatch();
    }

    public function testDispatchNoRouteRespond()
    {
        $request = new MockRequestTestRequest(new Dictionary($this->server), new Dictionary($this->config));
        $request->route = null;
        $request->getResponseBuilder()->setTestRespond();

        $this->expectException(Error::class);
        $this->expectExceptionCode(404);
        $request->dispatch();
    }

    public function testDispatchWithValidApp()
    {
        $pathconfig = System::path();
        $testpath = $pathconfig->var . '/test';
        \Wedeto\IO\Dir::mkdir($testpath);
        $filename = tempnam($testpath, "wasptest") . ".php";
        $classname = "cl_" . str_replace(".", "", basename($filename));

        $phpcode = <<<EOT
<?php
throw new Wedeto\HTTP\Response\StringResponse('foo');
EOT;

        $this->expectException(StringResponse::class);
        $this->expectExceptionCode(200);
        try
        {
            file_put_contents($filename, $phpcode);
            $request = new MockRequestTestRequest(new Dictionary($this->server), new Dictionary($this->config));
            $request->app = $filename;
            $request->route = '/';
            $request->dispatch();
        }
        finally
        {
            \Wedeto\IO\Dir::rmtree($testpath);
        }
    }

    public function testDispatchReturn()
    {
        $request = new MockRequestTestRequest(new Dictionary($this->server), new Dictionary($this->config));
        $request->getResponseBuilder()->setTestRespond();
        $request->getResponseBuilder()->setTestReturn();
        $this->assertNull($request->dispatch());
    }

    public function testHandleUnknownHost()
    {
        $dict = new Dictionary();
        $dict['unknown_host_policy'] = "REDIRECT";

        $site = new \Wedeto\Site;
        $site->setName('default');
        $vhost1 = new \Wedeto\VirtualHost('http://www.foo.bar', 'en');
        $vhost2 = new \Wedeto\VirtualHost('http://www.foo.xxx', 'en');
        $site->addVirtualHost($vhost1);
        $site->addVirtualHost($vhost2);

        $sites = array(
            'default' => $site
        );

        $webroot = new URL('http://www.foo.baz');
        $actual = Request::handleUnknownHost($webroot, $sites, $dict);
        $this->assertEquals($vhost1->getHost(), $actual);

        $webroot = new URL('http://www.foo.xxy');
        $actual = Request::handleUnknownHost($webroot, $sites, $dict);
        $this->assertEquals($vhost2->getHost(), $actual);
    }

    public function testNoScheme()
    {
        unset($this->server['REQUEST_SCHEME']);
        $req = new Request($this->get, $this->post, $this->cookie, $this->server, $this->config, $this->path, $this->resolve);
        
        $expected = new URL('/foo');
        $this->assertEquals($expected, $req->url);

        $expected = new URL('/');
        $this->assertEquals($expected, $req->webroot);
    }

    public function testHandleUnknownHostInConstructor()
    {
        $dict = new Dictionary();
        $dict['url'] = array('https://foobar.de', 'https://foobar.com', 'https://foobar.nl', 'https://example.com', 'http://example.nl');
        $dict['languages'] = array('de', 'com', 'nl', 'en');
        $dict['site'] = array('default', 'default', 'default', 'example', 'example');
        $dict['redirect'] = array(4 => 'https://example.com');
        $dict['unknown_host_policy'] = "REDIRECT";
        
        $this->config['site'] = $dict;
        $this->server['SERVER_NAME'] = 'example.comm';
        $this->server['REQUEST_SCHEME'] = 'https';
        $this->server['REQUEST_URI'] = '/';

        try
        {
            $req = new Request($this->get, $this->post, $this->cookie, $this->server, $this->config, $this->path, $this->resolve);
            $req->resolveApp();
        }
        catch (RedirectRequest $e)
        {
            $expected = new URL('https://example.com/');
            $this->assertEquals($expected, $e->getURL());
        }
    }
    
    public function testStartSession()
    {
        $req = new Request($this->get, $this->post, $this->cookie, $this->server, $this->config, $this->path, $this->resolve);
        $req->resolveApp();
        $req->startSession();

        $sess_object = $req->session;

        $this->assertEquals($_SESSION, $req->session->getAll());
        $_SESSION['foobar'] = rand();
        $this->assertEquals($_SESSION, $req->session->getAll());

        $req->startSession();
        $this->assertEquals($sess_object, $req->session);

    }

    public function testWants()
    {
        $req = new Request($this->get, $this->post, $this->cookie, $this->server, $this->config, $this->path, $this->resolve);

        $req->accept = Request::parseAccept('text/html;q=1,text/plain;q=0.9');
        $this->assertFalse($req->wantJSON());
        $this->assertTrue($req->wantHTML() !== false);
        $this->assertTrue($req->wantText() !== false);
        $this->assertFalse($req->wantXML());

        $req->accept = Request::parseAccept('application/json;q=1,application/*;q=0.9');
        $this->assertTrue($req->wantJSON() !== false);
        $this->assertFalse($req->wantHTML());
        $this->assertFalse($req->wantText());
        $this->assertTrue($req->wantXML() !== false);

        $req->accept = Request::parseAccept('application/json;q=1,text/html;q=0.9,text/plain;q=0.8');
        $type = $req->chooseResponse(array('application/json', 'text/html'));
        $this->assertEquals('application/json', $type);

        $type = $req->chooseResponse(array('text/plain', 'text/html'));
        $this->assertEquals('text/html', $type);
    }

    public function testSetTemplate()
    {
        $req = new Request($this->get, $this->post, $this->cookie, $this->server, $this->config, $this->path, $this->resolve);

        $tpl = $req->getTemplate();
        $tpl->setMimeType('text/plain');

        $new_tpl = new Template($req);
        $new_tpl->setMimeType('text/html');
        $req->setTemplate($new_tpl);

        $this->assertNotEquals($tpl, $req->getTemplate());
        
    }

    public function testResolveExtension()
    {
        $req = new Request($this->get, $this->post, $this->cookie, $this->server, $this->config, $this->path, $this->resolve);

        $resolve = new MockRequestResolver();
        $req->setResolver($resolve);

        $resolve->return_value = array('path' => '/foo/bar.php', 'route' => '/foo', 'ext' => '.json', 'module' => 'test', 'remainder' => []);
        
        $req->resolveApp();
        $this->assertEquals(1.5, $req->isAccepted('application/json'));
        $this->assertEquals('.json', $req->suffix);
        $this->assertEquals('/foo', $req->route);
        $this->assertEquals('/foo/bar.php', $req->app);
    }

}

class MockRequestTestRequest extends Request
{
    public function __construct(Dictionary $server, Dictionary $config)
    {
        $this->server = $server;
        $this->config = $config;
        $this->response_builder = new MockRequestResponseBuilder($this);
    }

    public function resolveApp()
    {}

    public function startSession()
    {
        $this->session = new \Wedeto\Session(new URL('/'), $this->config, $this->server);
    }
}

class MockRequestResponseBuilder extends ResponseBuilder
{
    private $testrespond = false;
    private $testreturn = false;

    public function setTestRespond()
    {
        $this->testrespond = true;
    }

    public function setTestReturn()
    {
        $this->testreturn = true;
    }

    public function setThrowable(\Throwable $e)
    {
        if (!$this->testrespond)
            throw $e;
        parent::setThrowable($e);
    }

    public function respond()
    {
        if ($this->testreturn)
            return;

        throw $this->response;
    }
}

class MockRequestResolver extends \Wedeto\Resolve\Resolver
{
    public $return_value = null;

    public function __construct()
    {}

    public function app(string $path, bool $retry = true)
    {
        return $this->return_value;
    }
}
