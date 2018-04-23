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

use Wedeto\Application\Task\TaskRunner;

/**
 * The Module interface should be implemented by all installed modules that
 * need initialization code. Once the module is located, a class named
 * <ModuleName>\Module will be loaded when it exists, and if it implements
 * this interface, it will be instantiated.
 */
interface ModuleInterface
{
    /**
     * Store the name and path of the module, and run any required
     * initialization code.
     *
     * @param string $name The name of the module
     * @param string $path The path to the module
     */
    public function __construct(string $name, string $path);

    /**
     * Called in order to allow the module to register tasks for the scheduler
     * and the CLI task runner.
     */
    public function registerTasks(TaskRunner $taskrunner);

    /**
     * @return string the name of the module
     */
    public function getName();

    /**
     * @return string the path of the module
     */
    public function getPath();
}
