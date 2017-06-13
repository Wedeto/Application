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

namespace Wedeto\Application\Module;

use PHPUnit\Framework\TestCase;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamWrapper;
use org\bovigo\vfs\vfsStreamDirectory;

use Wedeto\Util\Dictionary;
use Wedeto\Log\Logger;
use Wedeto\Log\Writer\MemLogWriter;

use Wedeto\Resolve\Manager as ResolveManager;

/**
 * @covers Wedeto\Application\Module\Manager
 * @covers Wedeto\Application\Module\BasicModule
 */
class ManagerTest extends TestCase
{
    protected $wedetoroot;
    protected $resolver;

    public function setUp()
    {
        Logger::resetGlobalState();
        vfsStreamWrapper::register();
        vfsStreamWrapper::setRoot(new vfsStreamDirectory('appdir'));
        $this->wedetoroot = vfsStream::url('appdir');

        mkdir($this->wedetoroot . DIRECTORY_SEPARATOR . 'config');
        mkdir($this->wedetoroot . DIRECTORY_SEPARATOR . 'http');
        mkdir($this->wedetoroot . DIRECTORY_SEPARATOR . 'app');
        mkdir($this->wedetoroot . DIRECTORY_SEPARATOR . 'src');

        $this->resolver = new ResolveManager;
        $this->resolver
            ->addResolverType('template', 'template', '.php')
            ->addResolverType('assets', 'assets')
            ->addResolverType('app', 'app');

        $this->resolver->registerModule('test', $this->wedetoroot, 0);
    }

    public function tearDown()
    {
        Logger::resetGlobalState();
    }

    public function testManager()
    {
        $fn = $this->wedetoroot . '/src/initModule.php';
        file_put_contents($fn, '<?php echo "initializing";');

        ob_start();
        $mgr = new Manager($this->resolver);
        $op = ob_get_contents();
        ob_end_clean();

        $mods = $mgr->getModules();
        $this->assertTrue(is_array($mods));
        $this->assertTrue(isset($mods['test']));
        $this->assertEquals(BasicModule::class, get_class($mods['test']));

        $this->assertEquals('initializing', $op);
    }

    public function testManagerWithCustomObject()
    {
        $fn = $this->wedetoroot . '/src/initModule.php';
        $php = <<<PHP
<?php
class MyModObj extends Wedeto\Application\Module\BasicModule
{}

return new MyModObj('test', dirname(__DIR__));
PHP;
        file_put_contents($fn, $php);

        ob_start();
        $mgr = new Manager($this->resolver);
        $op = ob_get_contents();
        ob_end_clean();

        $mods = $mgr->getModules();
        $this->assertTrue(is_array($mods));
        $this->assertTrue(isset($mods['test']));
        $this->assertEquals('test', $mods['test']->getName());
        $this->assertEquals($this->wedetoroot, $mods['test']->getPath());

        $this->assertEquals('MyModObj', get_class($mods['test']));
    }

    public function testManagerWithCustomClass()
    {
        $fn = $this->wedetoroot . '/src/initModule.php';
        $php = <<<PHP
<?php
class MyModClass extends Wedeto\Application\Module\BasicModule
{}

return MyModClass::class;
PHP;
        file_put_contents($fn, $php);

        ob_start();
        $mgr = new Manager($this->resolver);
        $op = ob_get_contents();
        ob_end_clean();

        $mods = $mgr->getModules();
        $this->assertTrue(is_array($mods));
        $this->assertTrue(isset($mods['test']));
        $this->assertEquals('test', $mods['test']->getName());
        $this->assertEquals($this->wedetoroot, $mods['test']->getPath());

        $mods['test']->registerTasks();

        $this->assertEquals('MyModClass', get_class($mods['test']));
    }

    public function testManagerWithInvalidReturn()
    {
        $fn = $this->wedetoroot . '/src/initModule.php';
        $php = <<<PHP
<?php

return new StdClass;
PHP;
        file_put_contents($fn, $php);

        $log = Logger::getLogger(Manager::class);
        Manager::setLogger($log);

        $memlog = new MemLogWriter("DEBUG");
        $log->addLogWriter($memlog);

        ob_start();
        $mgr = new Manager($this->resolver);
        $op = ob_get_contents();
        ob_end_clean();

        $mods = $mgr->getModules();
        $this->assertTrue(is_array($mods));
        $this->assertTrue(isset($mods['test']));

        $this->assertEquals(BasicModule::class, get_class($mods['test']));
        $this->assertEquals('test', $mods['test']->getName());
        $this->assertEquals($this->wedetoroot, $mods['test']->getPath());

        $log = $memlog->getLog();
        $found = false;
        foreach ($log as $line)
        {
            if (strpos($line, 'which returns a value that is not a ModuleInterface instance'))
                $found = true;
        }
        $this->assertTrue($found, "Error about invalid return value was not found");
    }
}
