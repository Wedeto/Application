<?php
/*
This is part of WASP, the Web Application Software Platform.
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

namespace WASP;

use PHPUnit\Framework\TestCase;

/**
 * @covers WASP\Path
 */
final class PathTest extends TestCase
{
    private $wasproot;

    public function setUp()
    {
        $this->wasproot = dirname(dirname(dirname(dirname(realpath(__FILE__)))));
    }

    public function tearDown()
    {
        $this->wasproot = dirname(dirname(dirname(dirname(realpath(__FILE__)))));
        new Path(array('root' => $this->wasproot));
    }

    /**
     * @covers WASP\Path::__construct
     * @covers WASP\Path::checkPaths
     * @covers WASP\Path::current
     */
    public function testPath()
    {
        $root = $this->wasproot;

        $path = new Path(array('root' => $root));
        $this->assertEquals($root, $path->root);
        $this->assertEquals($root . '/var', $path->var);
        $this->assertEquals($root . '/var/cache', $path->cache);

        $this->assertEquals($root . '/http', $path->http);
        $this->assertEquals($root . '/http/assets', $path->assets);
        $this->assertEquals($root . '/http/assets/js', $path->js);
        $this->assertEquals($root . '/http/assets/css', $path->css);
        $this->assertEquals($root . '/http/assets/img', $path->img);

        $webroot = $root . '/var/test';
        $assets = $webroot . '/assets';
        IO\Dir::mkdir($assets);

        $path = new Path(array('root' => $root, 'http' => $webroot));
        $this->assertEquals($root, $path->root);
        $this->assertEquals($root . '/var', $path->var);
        $this->assertEquals($root . '/var/cache', $path->cache);

        $this->assertEquals($webroot, $path->http);
        $this->assertEquals($assets, $path->assets);
        $this->assertEquals($assets . '/js', $path->js);
        $this->assertEquals($assets . '/css', $path->css);
        $this->assertEquals($assets . '/img', $path->img);

        $path->checkPaths();

        $current = Path::current();
        $this->assertEquals($current, $path);


        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid path: foobar");
        $baz = $current->foobar;
    }

    /**
     * @covers WASP\Path::__construct
     */
    public function testExceptionRootInvalid()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Path root (/tmp/non/existing/dir) does not exist");
        new Path(array('root' => '/tmp/non/existing/dir'));
    }

    /**
     * @covers WASP\Path::__construct
     */
    public function testExceptionWebrootInvalid()
    {
        $path = $this->wasproot;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Path http (/tmp/non/existing/dir) does not exist");
        new Path(array('root' => $path, 'http' => '/tmp/non/existing/dir'));
    }
}
