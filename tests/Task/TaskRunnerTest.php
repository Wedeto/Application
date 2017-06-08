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

namespace Wedeto\Application\Task;

use PHPUnit\Framework\TestCase;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamWrapper;
use org\bovigo\vfs\vfsStreamDirectory;

use Wedeto\Util\Dictionary;
use Wedeto\Application\PathConfig;
use Wedeto\Application\Application;

/**
 * @covers Wedeto\Application\Task\TaskRunner
 * @covers Wedeto\Application\Task\TaskInterface
 */
final class TaskRunnerTest extends TestCase implements TaskInterface
{
    private static $task_ran = false;
    protected $app;
    protected $pathconfig;
    protected $config;

    public function setUp()
    {
        vfsStreamWrapper::register();
        vfsStreamWrapper::setRoot(new vfsStreamDirectory('taskdir'));
        $this->wedetoroot = vfsStream::url('taskdir');

        mkdir($this->wedetoroot . DIRECTORY_SEPARATOR . 'http');
        mkdir($this->wedetoroot . DIRECTORY_SEPARATOR . 'config');

        $this->pathconfig = new PathConfig($this->wedetoroot);
        $this->config = new Dictionary;
        $this->app = Application::setup($this->pathconfig, $this->config);
    }

    public function testTaskRunner()
    {
        $tr = new TaskRunner($this->app);

        $taskname = str_replace('\\', ':', static::class);
        $tr->registerTask($taskname, 'Run test tasks');
        $fh = fopen('php://memory', 'rw');
        $tr->listTasks($fh);
        fseek($fh, 0);
        $data = fread($fh, 10000);
        fclose($fh);

        // Do it again
        $fh = fopen('php://memory', 'rw');
        $tr->listTasks($fh);
        fseek($fh, 0);
        $data2 = fread($fh, 10000);
        fclose($fh);

        $this->assertEquals($data, $data2);

        $this->assertFalse(self::$task_ran);
        $fh = fopen('php://memory', 'rw');
        $this->assertTrue($tr->run($taskname, $fh));
        fseek($fh, 0);
        $data3 = fread($fh, 10000);
        fclose($fh);
        $this->assertTrue(self::$task_ran);
        $this->assertEmpty($data3);

        $fh = fopen('php://memory', 'rw');
        $this->assertFalse($tr->run(PathConfig::class, $fh));
        fseek($fh, 0);
        $data3 = fread($fh, 10000);
        fclose($fh);
        $this->assertContains("Error: invalid task: " . PathConfig::class, $data3);

        $fh = fopen('php://memory', 'rw');
        $this->assertFalse($tr->run('Wedeto:NonExistingTask', $fh));
        fseek($fh, 0);
        $data3 = fread($fh, 10000);
        fclose($fh);
        $this->assertEquals($data3, "Error: task does not exist: Wedeto\\NonExistingTask\n");

        $fh = fopen('php://memory', 'rw');
        $this->assertTrue(self::$task_ran);
        $this->assertFalse($tr->run($taskname, $fh));
        fseek($fh, 0);
        $exc = fread($fh, 10000);
        fclose($fh);

        $msg = "Error: error while running task: " . static::class;
		$this->assertEquals($msg, substr($exc, 0, strlen($msg)));
    }

    public function execute()
    {
        if (self::$task_ran)
            throw new \RuntimeException("Twice");

        self::$task_ran = true;
    }
}
