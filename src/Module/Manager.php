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

use Wedeto\Resolve\Manager as ResolveManager;
use Wedeto\Util\LoggerAwareStaticTrait;

/**
 * Find, initialize and manage modules.
 * The Manager::setup() function should be called as soon as the location of the modules
 * is known. Without calling setup, a call to getModules() will throw an exception.
 */
class Manager
{
    use LoggerAwareStaticTrait;

    protected $modules = array();

    /** 
     * Find and initialize installed modules in the module path
     *
     * @param ResolveManager $resolver The resolver that find modules
     */
    public function __construct(ResolveManager $manager)
    {
        self::getLogger();
        $modules = $manager->getModules();

        foreach ($modules as $mod_name => $path)
        {
            self::$logger->debug("Found module {0} in path {1}", [$mod_name, $path]);

            // Create the module object, using the module implementation if available
            $load_class = BasicModule::class;

            $init = $path . '/src/initModule.php';

            $module_instance = null;
            if (file_exists($init))
            {
                $result = include $init;
                if ($result instanceof ModuleInterface)
                {
                    $module_instance = $result;
                }
                elseif (is_string($result) && class_exists($result))
                {
                    $module_instance = new $result($mod_name, $path);
                }
                elseif (!empty($result) && $result !== 1)
                {
                    self::getLogger()->warning(
                        'Module {0} has init file {1} which returns a value that is not a ModuleInterface instance: {2}', 
                        [$mod_name, $init, $result]
                    );
                }
            }

            if ($module_instance === null)
                $module_instance = new BasicModule($mod_name, $path);

            $this->modules[$mod_name] = $module_instance;
        }
    }

    /**
     * Return the list of found modules
     *
     * @return array A list of Wedeto\Module objects
     */
    public function getModules()
    {
        return $this->modules;
    }
}
