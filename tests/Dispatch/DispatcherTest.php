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

namespace Wedeto\Application\Dispatch;

use PHPUnit\Framework\TestCase;
use Wedeto\Resolve\Resolver;
use Wedeto\Application\Application;
use Wedeto\Application\PathConfig;
use Wedeto\HTML\Template;
use Wedeto\Util\Dictionary;

use Wedeto\HTTP\Request;
use Wedeto\HTTP\Responder;
use Wedeto\HTTP\URL;
use Wedeto\HTTP\Response\RedirectRequest;

/**
 * @covers Wedeto\Application\Dispatch\Dispatcher
 */
final class DispatcherTest extends TestCase
{
    private $get;
    private $post;
    private $server;
    private $cookie;

    private $config;

    private $request;

    private $path;
    private $resolver;

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

        $this->request = new Request($this->get, $this->post, $this->cookie, $this->server);

        $this->config = new Dictionary($config);
        $this->path = Application::path();
        $this->resolve = new Resolver($this->path);
    }

    public function testRouting()
    {
        $dispatch = new Dispatcher($this->request, $this->resolver, $this->config);
        $dispatch->resolveApp();
        $this->assertEquals('/', $dispatcher->getRoute());

        $this->server['REQUEST_URI'] = '/assets';
        $dispatch->resolveApp();
        $this->assertEquals('/assets', $dispatch->getRoute());
    }

    public function testRoutingInvalid()
    {
        $resolve = new MockRequestResolver();
        $this->server['SERVER_NAME'] = 'www.example.com';
        $this->server['REQUEST_URI'] = '/foo';

        $dispatch = new Dispatcher($this->request, $this->resolver, $this->config);
        $dispatch->resolveApp();
        $this->assertNull($dispatcher->route);
        $this->assertNull($dispatcher->app);

        $this->server['REQUEST_URI'] = '/';
        $dispatcher->resolveApp();
        $this->assertNull($dispatcher->route);
        $this->assertNull($dispatcher->app);
    }

    public function testRoutingInvalidHostIgnorePolicy()
    {
        $this->server['REQUEST_SCHEME'] = 'https';
        $this->server['SERVER_NAME'] = 'www.example.nl';
        $this->server['REQUEST_URI'] = '/assets';

        $dispatch = new Dispatcher($this->request, $this->resolver, $this->config);
        $dispatch->resolveApp();
        $this->assertEquals($dispatch->route, '/assets');
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

        $dispatch = new Dispatcher($this->request, $this->resolver, $this->config);

        $this->expectException(Error::class);
        $this->expectExceptionCode(404);
        $this->expectExceptionMessage('Not found');
        $dispatch->resolveApp();
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
        $dispatch = new Dispatcher($this->request, $this->resolver, $this->config);
        $dispatch->resolveApp();
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

        $dispatch = new Dispatcher($this->request, $this->resolver, $this->config);
        $dispatch->resolveApp();
        $this->assertEquals($this->get, $dispatcher->get->getAll());
    }

    public function testGetTemplate()
    {
        $dispatch = new Dispatcher($this->request, $this->resolver, $this->config);

        $tpl = $dispatch->getTemplate();
        $this->assertInstanceOf(\Wedeto\Template::class, $tpl);

        $mocker = $this->prophesize(\Wedeto\Template::class);
        $mock = $mocker->reveal();

        $this->assertEquals($dispatcher, $dispatcher->setTemplate($mock));
        $this->assertEquals($mock, $dispatcher->getTemplate());
    }

    public function testDispatchWithValidApp()
    {
        $pathconfig = Application::path();
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
            $dispatch = new Dispatcher($this->request, $this->resolver, $this->config);
            $dispatch->app = $filename;
            $dispatch->route = '/';
            $request->dispatch();
        }
        finally
        {
            \Wedeto\IO\Dir::rmtree($testpath);
        }
    }

    public function testHandleUnknownHost()
    {
        $dict = new Dictionary();
        $dict['unknown_host_policy'] = "REDIRECT";

        $site = new Site;
        $site->setName('default');

        $vhost1 = new VirtualHost('http://www.foo.bar', 'en');
        $vhost2 = new VirtualHost('http://www.foo.xxx', 'en');
        $site->addVirtualHost($vhost1);
        $site->addVirtualHost($vhost2);

        $sites = array(
            'default' => $site
        );

        $webroot = new URL('http://www.foo.baz');
        $actual = Dispatcher::handleUnknownHost($webroot, $sites, $dict);
        $this->assertEquals($vhost1->getHost(), $actual);

        $webroot = new URL('http://www.foo.xxy');
        $actual = Dispatcher::handleUnknownHost($webroot, $sites, $dict);
        $this->assertEquals($vhost2->getHost(), $actual);
    }

    public function testResolveExtension()
    {
        $req = new Request($this->get, $this->post, $this->cookie, $this->server, $this->config, $this->path, $this->resolve);
        $resolve = new MockRequestResolver();
        $dispatch = new Dispatcher($this->request, $resolve, $this->config);

        $resolve->return_value = array('path' => '/foo/bar.php', 'route' => '/foo', 'ext' => '.json', 'module' => 'test', 'remainder' => []);
        
        $dispatch->resolveApp();
        $this->assertEquals(1.5, $dispatch->isAccepted('application/json'));
        $this->assertEquals('.json', $dispatch->suffix);
        $this->assertEquals('/foo', $dispatch->route);
        $this->assertEquals('/foo/bar.php', $dispatch->app);
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
