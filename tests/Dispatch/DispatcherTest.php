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
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamWrapper;
use org\bovigo\vfs\vfsStreamDirectory;

use Wedeto\IO\Path;

use Wedeto\Resolve\Resolver;
use Wedeto\Application\Application;
use Wedeto\Application\PathConfig;

use Wedeto\HTML\Template;

use Wedeto\Util\Dictionary;
use Wedeto\Util\Functions as WF;

use Wedeto\HTTP\Request;
use Wedeto\HTTP\Responder;
use Wedeto\HTTP\URL;
use Wedeto\HTTP\Response\Response;
use Wedeto\HTTP\Response\Error as HTTPError;
use Wedeto\HTTP\Response\RedirectRequest;
use Wedeto\HTTP\Response\StringResponse;
use Wedeto\HTTP\Response\DataResponse;

use Wedeto\Log\Logger;

/**
 * @covers Wedeto\Application\Dispatch\Dispatcher
 */
final class DispatcherTest extends TestCase
{
    protected static $it = 0;

    protected $app;
    protected $pathconfig;
    protected $config;

    protected $get;
    protected $post;
    protected $server;
    protected $cookie;
    protected $files;

    protected $request;
    protected $resolver;

    public function setUp()
    {
        Logger::resetGlobalState();
        vfsStreamWrapper::register();
        $d = 'dispatchdir' . (++self::$it);
        vfsStreamWrapper::setRoot(new vfsStreamDirectory($d));
        $this->wedetoroot = vfsStream::url($d);

        mkdir($this->wedetoroot . DIRECTORY_SEPARATOR . 'config');
        mkdir($this->wedetoroot . DIRECTORY_SEPARATOR . 'language');
        mkdir($this->wedetoroot . DIRECTORY_SEPARATOR . 'http');
        mkdir($this->wedetoroot . DIRECTORY_SEPARATOR . 'var');
        mkdir($this->wedetoroot . DIRECTORY_SEPARATOR . 'var/log');

        $this->pathconfig = new PathConfig($this->wedetoroot);
        $this->config = new Dictionary();

        $this->app = Application::setup($this->pathconfig, $this->config);
        $this->resolver = $this->app->resolver;

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

        $this->files = [];

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

        $this->request = new Request($this->get, $this->post, $this->cookie, $this->server, $this->files);

        $this->config = new Dictionary($config);
        $this->resolve = $this->app->resolver;
    }

    public function tearDown()
    {
        Logger::resetGlobalState();
    }

    public function testRouting()
    {
        $dispatch = new Dispatcher($this->request, $this->resolver, $this->config);
        $dispatch->resolveApp();
        $this->assertEquals('/', $dispatch->getRoute());

        $vhost = $dispatch->getVirtualHost();
        $this->assertInstanceOf(VirtualHost::class, $vhost);
        $expected_url = new URL('https://www.example.com');
        $this->assertEquals($expected_url, $vhost->getHost());

        $resolve = new MockRequestResolver();
        $this->resolver->setResolver('app', $resolve);
        $resolve->return_value = array('path' => '/assets.php', 'route' => '/assets', 'ext' => null, 'module' => 'test', 'remainder' => []);

        $this->server['REQUEST_URI'] = '/assets';
        $dispatch->resolveApp();
        $this->assertEquals('/assets', $dispatch->getRoute());
    }

    public function testRoutingInvalid()
    {
        $resolve = new MockRequestResolver();
        $this->resolver->setResolver('app', $resolve);
        $this->server['SERVER_NAME'] = 'www.example.com';
        $this->server['REQUEST_URI'] = '/foo';

        $dispatch = new Dispatcher($this->request, $this->resolver, $this->config);

        $vhost = $dispatch->getVirtualHost();
        $this->assertInstanceOf(VirtualHost::class, $vhost);
        $expected_url = new URL('https://www.example.com');
        $this->assertEquals($expected_url, $vhost->getHost());

        $dispatch->resolveApp();
        $this->assertNull($dispatch->route);
        $this->assertNull($dispatch->app);

        $this->server['REQUEST_URI'] = '/';
        $dispatch->resolveApp();
        $this->assertNull($dispatch->route);
        $this->assertNull($dispatch->app);
    }

    public function testIntegrationWithApp()
    {
        $root = $this->wedetoroot;
        $appdir = $this->wedetoroot . '/app';
        mkdir($appdir);
        $filename = $appdir . '/test-integration.php';

        $controller = <<<PHP
<?php
throw new Wedeto\HTTP\Response\DataResponse([
    'template' => \$tpl,
    'tpl' => \$tpl,
    'request' => \$request,
    'app' => \$app,
    'config' => \$config,
    'path_config' => \$path_config,
    'db' => \$db,
    'arguments' => \$arguments
]);
PHP;

        file_put_contents($filename, $controller);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/test-integration/foo/bar';

        $pc = new PathConfig($this->wedetoroot);
        $c = new Dictionary;
        $app = Application::setup($pc, $c);
        $dispatch = Dispatcher::createFromApplication($app);
        $res = $app->resolver;
        $res->registerModule('test', $this->wedetoroot, 0);

        $req = $app->request;
        $this->assertSame($req, $dispatch->getRequest());
        $url = $req->url;
        $response = $dispatch->dispatch();
        $this->assertInstanceOf(DataResponse::class, $response);

        $data = $response->getData();

        $tpl = $app->template;
        $req = $app->request;
        $res = $app->resolver;
        $i18n = $app->i18n;
        $db = $app->db;
        $args = $dispatch->getArguments();

        $this->assertEquals($filename, $dispatch->getApp());

        $this->assertSame($pc, $data['path_config']);
        $this->assertSame($tpl, $data['template']);
        $this->assertSame($tpl, $data['tpl']);
        $this->assertSame($req, $data['request']);
        $this->assertSame($app, $data['app']);
        $this->assertSame($c, $data['config']);
        $this->assertSame($db, $data['db']);
        $this->assertEquals($args, $data['arguments']);

        $this->assertSame($pc, $dispatch->getVariable('path_config'));
        $this->assertSame($tpl, $dispatch->getVariable('template'));
        $this->assertSame($tpl, $dispatch->getVariable('tpl'));
        $this->assertSame($req, $dispatch->getVariable('request'));
        $this->assertSame($app, $dispatch->getVariable('app'));
        $this->assertSame($c, $dispatch->getVariable('config'));
        $this->assertSame($db, $dispatch->getVariable('db'));

        $this->assertSame($res, $dispatch->getResolver());
        $this->assertSame($req, $dispatch->getRequest());
        $this->assertSame($tpl, $dispatch->getTemplate());
        $this->assertSame($app, $dispatch->getApplication());
    }

    public function testRoutingInvalidHostIgnorePolicy()
    {
        $this->server['REQUEST_SCHEME'] = 'https';
        $this->server['SERVER_NAME'] = 'www.example.nl';
        $this->server['REQUEST_URI'] = '/assets';
        $this->config = [];

        $resolve = new MockRequestResolver();
        $this->resolver->setResolver('app', $resolve);
        $resolve->return_value = array('path' => '/assets.php', 'route' => '/assets', 'ext' => null, 'module' => 'test', 'remainder' => []);

        $this->request = new Request($this->get, $this->post, $this->cookie, $this->server, $this->files);
        $dispatch = new Dispatcher($this->request, $this->resolver, $this->config);
        $this->assertInstanceOf(Dictionary::class, $dispatch->getConfig());
        $dispatch->resolveApp();
        $this->assertEquals('/assets', $dispatch->route);
    }

    public function testRoutingInvalidHostErrorPolicy()
    {
        $this->server['REQUEST_SCHEME'] = 'https';
        $this->server['SERVER_NAME'] = 'www.example.nl';
        $this->server['REQUEST_URI'] = '/assets';
        $this->config['site']['unknown_host_policy'] = 'ERROR';

        $dispatch = new Dispatcher($this->request, $this->resolver, $this->config);
        $this->assertSame($this->config, $dispatch->getConfig());

        $this->expectException(HTTPError::class);
        $this->expectExceptionCode(404);
        $this->expectExceptionMessage('Not found');
        $dispatch->resolveApp();
    }

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
        $this->request = new Request($this->get, $this->post, $this->cookie, $this->server, $this->files);

        $this->expectException(RedirectRequest::class);
        $dispatch = new Dispatcher($this->request, $this->resolver, $this->config);

        $sites = $dispatch->getSites();
        $this->assertTrue(is_array($sites));
        $this->assertEquals(1, count($sites));
        $this->assertInstanceOf(Site::class, $sites[0]);
        $dispatch->resolveApp();
    }

    public function testRoutingRedirectHostWithUnknownDomain()
    {
        $this->server['REQUEST_SCHEME'] = 'http';
        $this->server['SERVER_NAME'] = 'www.foo.bar';
        $this->server['REQUEST_URI'] = '/assets';
        $this->config['site'] = 
            array(
                'url' => array(
                    'http://www.example.com',
                    'http://www.example.nl'
                ),
                'language' => array('en'),
                'unknown_host_policy' => 'REDIRECT',
                'redirect' => array(1 => 'http://www.example.com/')
            );
        $this->request = new Request($this->get, $this->post, $this->cookie, $this->server, $this->files);

        $this->expectException(RedirectRequest::class);
        $dispatch = new Dispatcher($this->request, $this->resolver, $this->config);

        $sites = $dispatch->getSites();
        $this->assertTrue(is_array($sites));
        $this->assertEquals(1, count($sites));
        $this->assertInstanceOf(Site::class, $sites[0]);

        $dispatch->resolveApp();
    }

    public function testRoutingFailsThrowsException()
    {
        $pathconfig = Application::pathConfig();
        $testpath = $pathconfig->root . '/app';
        Path::mkdir($testpath);
        Path::mkdir($testpath . '/foo');

        $filename = $testpath . '/foo/baz.php';
        file_put_contents($filename, '<?php echo \'foo\'; ?>');

        $this->resolver->registerModule('test', $this->wedetoroot, 0);
        $this->server['REQUEST_SCHEME'] = 'http';
        $this->server['REQUEST_URI'] = '/foo/bar';
        $this->request = new Request($this->get, $this->post, $this->cookie, $this->server, $this->files);

        $dispatch = new Dispatcher($this->request, $this->resolver, $this->config);

        $response = $dispatch->dispatch();
        $this->assertInstanceOf(HTTPError::class, $response);
        $this->assertContains('Could not resolve', $response->getMessage());
    }

    public function testBadControllerThrowsException()
    {
        $pathconfig = Application::pathConfig();
        $testpath = $pathconfig->root . '/app';
        Path::mkdir($testpath);
        $filename = $testpath . '/baz.php';
        file_put_contents($filename, '<?php throw new LogicException("boo");');

        $this->resolver->registerModule('test', $this->wedetoroot, 0);
        $this->server['REQUEST_SCHEME'] = 'http';
        $this->server['REQUEST_URI'] = '/baz';
        $this->request = new Request($this->get, $this->post, $this->cookie, $this->server, $this->files);

        $dispatch = new Dispatcher($this->request, $this->resolver, $this->config);

        $response = $dispatch->dispatch();
        $this->assertInstanceOf(HTTPError::class, $response);
        $this->assertContains('Exception of type LogicException thrown', $response->getMessage());

        // Validate some other types
        $this->server['HTTP_ACCEPT'] = 'text/html';
        $this->request = new Request($this->get, $this->post, $this->cookie, $this->server, $this->files);
        $dispatch->setRequest($this->request);
        $response = $dispatch->dispatch();

        $this->assertInstanceOf(HTTPError::class, $response);
        $sub = $response->getResponse();
        $this->assertInstanceOf(StringResponse::class, $sub);
        $this->assertContains('foobar', $sub->getOutput('text/plain'));
    }

    public function testNoSiteConfig()
    {
        $this->server['REQUEST_SCHEME'] = 'https';
        $this->server['SERVER_NAME'] = 'www.example.nl';
        $this->server['REQUEST_URI'] = '/assets';
        $this->config['site'] = array();

        $dispatch = new Dispatcher($this->request, $this->resolver, $this->config);
        $dispatch->resolveApp();
        $this->assertEquals($this->get, $this->request->get->getAll());
    }

    public function testGetTemplate()
    {
        $dispatch = new Dispatcher($this->request, $this->resolver, $this->config);

        $tpl = $dispatch->getTemplate();
        $this->assertInstanceOf(Template::class, $tpl);

        $mocker = $this->prophesize(Template::class);
        $mock = $mocker->reveal();

        $this->assertSame($dispatch, $dispatch->setTemplate($mock));
        $this->assertEquals($mock, $dispatch->getTemplate());
    }

    public function testDispatchWithValidApp()
    {
        $pathconfig = Application::pathConfig();
        $testpath = $pathconfig->root . '/app';
        Path::mkdir($testpath);
        $filename = $testpath . '/wasptest-validapp.php';
        $classname = "cl_" . str_replace(".", "", basename($filename));

        $phpcode = <<<EOT
<?php
throw new Wedeto\HTTP\Response\StringResponse('foo');
EOT;

        file_put_contents($filename, $phpcode);

        $this->resolver->registerModule('test', $this->wedetoroot, 0);

        $this->server['REQUEST_URI'] = '/' . basename($filename, '.php');
        $this->request = new Request($this->get, $this->post, $this->cookie, $this->server, $this->files);
        $dispatch = new Dispatcher($this->request, $this->resolver, $this->config);
        $l = Dispatcher::getLogger();
        $response = $dispatch->dispatch();

        $this->assertInstanceOf(StringResponse::class, $response);
        $this->assertEquals(200, $response->getCode());
        $this->assertEquals('foo', $response->getOutput());
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
        $req = new Request($this->get, $this->post, $this->cookie, $this->server, $this->files);
        $resolve = new MockRequestResolver();
        $this->resolver->setResolver('app', $resolve);
        $dispatch = new Dispatcher($this->request, $this->app->resolver, $this->config);

        $resolve->return_value = array('path' => '/foo/bar.php', 'route' => '/foo', 'ext' => '.json', 'module' => 'test', 'remainder' => []);
        
        $dispatch->resolveApp();
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

    public function resolve(string $path, bool $retry = true)
    {
        return $this->return_value;
    }
}
