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
namespace WASP\Platform\Module;

use WASP\Resolve\Resolver;
use WASP\Util\LoggerAwareStaticTrait;

/**
 * Find, initialize and manage modules.
 * The Manager::setup() function should be called as soon as the location of the modules
 * is known. Without calling setup, a call to getModules() will throw an exception.
 */
class Manager
{
    use LoggerAwareStaticTrait;

    private static $initialized = false;
    private static $modules = array();

    /** 
     * Find and initialize installed modules in the module path
     *
     * @param $module_path string Where to find the modules
     * @param Resolver $resolver The resolver that find modules
     */
    public static function setup(string $module_path, Resolver $resolver)
    {
        if (self::$initialized)
            return;

        self::getLogger();
        $modules = $resolver->listModules($module_path);

        foreach ($modules as $mod_name => $path)
        {
            self::$logger->debug("Found module {0} in path {1}", [$mod_name, $path]);
            $resolver->registerModule($mod_name, $path);
            self::$modules[$mod_name] = $path;

            // Create the module object, using the module implementation if available
            $load_class = BasicModule::class;
            $mod_class = $mod_name . '\\Module';
            if (class_exists($mod_class))
            {
                if (is_subclass_of($mod_class, Module::class))
                    $load_class = $mod_class;
                else
                    self::$logger->warn('Module {0} has class {1} but it does not implement {2}', [$mod_name, $mod_class, Module::class]);
            }

            self::$modules[$mod_name] = new $load_class($mod_name, $path);
        }
        self::$initialized = true;
    }

    /**
     * Return the list of found modules
     *
     * @return array A list of WASP\Module objects
     */
    public static function getModules()
    {
        if (!self::$initialized)
            throw new \RuntimeException("You need to initialize the module manager before using it");

        return array_values(self::$modules);
    }
}
