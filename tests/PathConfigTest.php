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

use Wedeto\IO\Path;
use Wedeto\IO\IOException;

/**
 * @covers Wedeto\Application\PathConfig
 */
final class PathConfigTest extends TestCase
{
    private $wedetoroot;

    public function setUp()
    {
        vfsStreamWrapper::register();
        vfsStreamWrapper::setRoot(new vfsStreamDirectory('pathdir'));
        $this->wedetoroot = vfsStream::url('pathdir');

        mkdir($this->wedetoroot . DIRECTORY_SEPARATOR . 'config');
        mkdir($this->wedetoroot . DIRECTORY_SEPARATOR . 'http');

        PathConfig::setInstance();
    }

    public function tearDown()
    {
        PathConfig::setInstance();
    }

    /**
     * @covers Wedeto\Application\PathConfig::__construct
     * @covers Wedeto\Application\PathConfig::checkPaths
     * @covers Wedeto\Application\PathConfig::getInstance
     */
    public function testPath()
    {
        $root = $this->wedetoroot;

        $path = new PathConfig(array('root' => $root));

        $this->assertEquals($root, $path->root);

        $this->assertEquals($root . '/var', $path->var);
        $this->assertEquals($root . '/var/cache', $path->cache);

        $this->assertEquals($root . '/http', $path->webroot);
        $this->assertEquals($root . '/http/assets', $path->assets);

        $webroot = $root . '/var/test';
        $assets = $webroot . '/assets';
        Path::mkdir($assets);

        $path = new PathConfig(array('root' => $root, 'webroot' => $webroot));
        $this->assertEquals($root, $path->root);
        $this->assertEquals($root . '/var', $path->var);
        $this->assertEquals($root . '/var/cache', $path->cache);

        $this->assertEquals($webroot, $path->webroot);
        $this->assertEquals($assets, $path->assets);

        $path->checkPaths();

        PathConfig::setInstance($path);
        $current = PathConfig::getInstance();
        $this->assertSame($current, $path);


        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid path: foobar");
        $baz = $current->foobar;
    }

    /**
     * @covers Wedeto\Application\PathConfig::__construct
     */
    public function testExceptionRootInvalid()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Path 'root' does not exist: /tmp/non/existing/dir");
        new PathConfig(array('root' => '/tmp/non/existing/dir'));
    }

    /**
     * @covers Wedeto\Application\PathConfig::__construct
     */
    public function testExceptionWebrootInvalid()
    {
        $path = $this->wedetoroot;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Path 'webroot' does not exist: /tmp/non/existing/dir");
        new PathConfig(array('root' => $path, 'webroot' => '/tmp/non/existing/dir'));
    }

    public function testProvideOnlyRoot()
    {
        $path = $this->wedetoroot;
        $pc = new PathConfig($path);

        $SEP = DIRECTORY_SEPARATOR;
        $this->assertEquals($path, $pc->root);
        $this->assertEquals($path . $SEP . 'config', $pc->config);
        $this->assertEquals($path . $SEP . 'http', $pc->webroot);
        $this->assertEquals($path . $SEP . 'uploads', $pc->uploads);
        $this->assertEquals($path . $SEP . 'http' . $SEP . 'assets', $pc->assets);
        $this->assertEquals($path . $SEP . 'var', $pc->var);
        $this->assertEquals($path . $SEP . 'var' . $SEP . 'log', $pc->log);
        $this->assertEquals($path . $SEP . 'var' . $SEP . 'cache', $pc->cache);
    }

    public function testProvideInvalidRoot()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid path: 3");
        new PathConfig(3);
    }

    public function testChangePaths()
    {
        $pc = new PathConfig($this->wedetoroot);
        
        $var_path = $this->wedetoroot . DIRECTORY_SEPARATOR . 'foo' . DIRECTORY_SEPARATOR . 'var';
        $pc->var = $var_path;
        $this->assertEquals($var_path, $pc->var);

        $log_path = $var_path . DIRECTORY_SEPARATOR . 'lug';
        $pc->log = $log_path;
        $this->assertEquals($log_path, $pc->log);

        mkdir($var_path, 0777, true);
        $pc->checkPaths();
        $this->assertTrue(is_dir($log_path));
    }

    public function testChangeInvalidPathElementThrowsException()
    {
        $pc = new PathConfig($this->wedetoroot);
        
        $tgt_path = $this->wedetoroot . DIRECTORY_SEPARATOR . 'foo' . DIRECTORY_SEPARATOR . 'var';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid path element: foo"); 
        $pc->foo = $tgt_path;
    }

    public function testChangePathToInvalidValueThrowsException()
    {
        $pc = new PathConfig($this->wedetoroot);
        
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid path: 3"); 
        $pc->foo = 3;
    }

    public function testSetInvalidPathElementInConstructorThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid path element: foo");
        $pc = new Pathconfig(['foo' => 'bar']);
    }

    public function testSetInvalidPathInConstructorThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid path: 3");
        $pc = new Pathconfig(['root' => 3]);
    }
}
