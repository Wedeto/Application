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
use Wedeto\Log\Writer\{FileWriter, MemLogWriter};

use Wedeto\IO\Path;

use Wedeto\HTML\Template;
use Wedeto\HTTP\Request;
use Wedeto\Resolve\Manager as ResolveManager;
use Wedeto\Application\Module\Manager as ModuleManager;
use Wedeto\I18n\I18n;
use Wedeto\DB\DB;

define('WEDETO_TEST', 1);

/**
 * @covers Wedeto\Application\Application
 */
class ApplicationTest extends TestCase
{
    protected static $it = 0;

    public function setUp()
    {
        Application::setInstance();
        Logger::resetGlobalState(); 
        vfsStreamWrapper::register();
        $dirname = 'appdir' . (++self::$it);
        vfsStreamWrapper::setRoot(new vfsStreamDirectory($dirname));
        $this->wedetoroot = vfsStream::url($dirname);

        mkdir($this->wedetoroot . DIRECTORY_SEPARATOR . 'config');
        mkdir($this->wedetoroot . DIRECTORY_SEPARATOR . 'http');
        mkdir($this->wedetoroot . DIRECTORY_SEPARATOR . 'language');

        PathConfig::setInstance();
        $this->pathconfig = new PathConfig($this->wedetoroot);
    }

    public function tearDown()
    {
        Application::setInstance();
        Logger::resetGlobalState(); 
        Path::setDefaultFileMode(0660);
        Path::setDefaultDirMode(0770);
        Path::setDefaultFileGroup();
    }

    /**
     * @covers Wedeto\Application\Application::getInstance
     */
    public function testInstance()
    {
        $config = new Dictionary();

        $app = Application::setup($this->pathconfig, $config);
        $this->assertInstanceOf(Application::class, $app);

        $this->assertTrue(Application::hasInstance());

        $mocker = $this->prophesize(DB::class);
        $mock = $mocker->reveal();
        $app->db = $mock;

        $this->assertSame($config, $app->config);
        $this->assertInstanceOf(Request::class, $app->request);
        $this->assertInstanceOf(ResolveManager::class, $app->resolver);
        $this->assertInstanceOf(PathConfig::class, $app->pathConfig);
        $this->assertInstanceOf(Template::class, $app->template);
        $this->assertInstanceOf(I18n::class, $app->i18n);
        $this->assertInstanceOf(ModuleManager::class, $app->moduleManager);

        $this->assertSame($config, Application::config());
        $this->assertInstanceOf(Request::class, Application::request());
        $this->assertInstanceOf(ResolveManager::class, Application::resolver());
        $this->assertInstanceOf(PathConfig::class, Application::pathConfig());
        $this->assertInstanceOf(Template::class, Application::template());
        $this->assertInstanceOf(I18n::class, Application::i18n());
        $this->assertInstanceOf(ModuleManager::class, Application::moduleManager());

        $this->assertInstanceOf(DB::class, $app->db);



        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("No such object: foobar");
        $val = $app->foobar;
    }

    public function testDefaultPermissionsAreSet()
    {
        $gid = posix_getgid();
        $gri = posix_getgrgid($gid);
        $grn = $gri['name'];

        $config = new Dictionary([
            'io' => [
                'group' => $grn,
                'file_mode' => "0666",
                'dir_mode' => "0777"
            ]
        ]);

        $app = Application::setup($this->pathconfig, $config);
        $this->assertEquals($grn, Path::getDefaultFileGroup());
        $this->assertEquals(0666, Path::getDefaultFileMode());
        $this->assertEquals(0777, Path::getDefaultDirMode());
    }

    public function testNoInitialization()
    {
        $this->assertFalse(Application::hasInstance());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Wedeto has not been initialized yet");
        Application::getInstance();
    }

    public function testLoggerSetup()
    {
        Logger::resetGlobalState();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/foo';

        $config = new Dictionary(['site' => ['dev' => false]]);
        $app = Application::setup($this->pathconfig, $config);

        $log = Logger::getLogger();
        $writers = $log->getLogWriters();
        $this->assertEquals(1, count($writers));

        $fw_found = false;
        $ml_found = false;
        foreach ($writers as $writer)
        {
            if ($writer instanceof FileWriter)
                $fw_found = true;
            if ($writer instanceof MemLogWriter)
                $ml_found = true;
        }
        $this->assertTrue($fw_found, "No file writer found");
        $this->assertFalse($ml_found, "Mem log writer found");

        Logger::resetGlobalState();
        $config = new Dictionary(['site' => ['dev' => true]]);
        $app = Application::setup($this->pathconfig, $config);

        $log = Logger::getLogger();
        $writers = $log->getLogWriters();
        $this->assertEquals(2, count($writers));

        $fw_found = false;
        $ml_found = false;
        $ml = null;
        foreach ($writers as $writer)
        {
            if ($writer instanceof FileWriter)
                $fw_found = true;
            if ($writer instanceof MemLogWriter)
            {
                $ml_found = true;
                $ml = $writer;
            }
        }
        $this->assertTrue($fw_found, "No file writer found");
        $this->assertTrue($ml_found, "No mem log writer found");

        $log = $ml->getLog();
        $request_found = false;
        foreach ($log as $line)
        {
            if (strpos($line, 'Starting processing for GET request to /foo'))
                $request_found = true;
        }
        $this->assertTrue($request_found);
    }
}
