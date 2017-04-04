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
namespace Wedeto\Resolve;

use Wedeto\Util\LoggerAwareStaticTrait;

class ModuleManager
{
    use LoggerAwareStaticTrait;

    protected $modules = array();
    protected $template_resolver = null;
    protected $asset_resolver = null;
    protected $router = null;

    public function __construct(Cache $cache = null)
    {
        $this->template_resolver = new Resolver("template");
        $this->asset_resolver = new Resolver("assets");

        if ($cache !== null)
        {
            $this->template_resolver->setCache($cache);
            $this->asset_resolver->setCache($cache);

            if ($cache->has('router'))
            {
                $router = $cache['router'];
                if ($router instanceof Router)
                    $this->router = $router;
            }

            if ($router === null)
                $this->router = new Router;
        }
    }

    /**
     * Resolve an app using the router
     * @param array $parts The route to resolve
     * @param string $ext The file extension used to match apps
     */
    public function resolveApp(array $parts, string $ext)
    {
        return $this->router->resolve($parts, $ext);
    }

    /**
     * Resolve a template file. 
     *
     * @param $template string The template identifier. 
     * @return string The location of a matching template.
     */
    public function resolveTemplate(string $template)
    {
        if (substr($template, -4) != ".php")
            $template .= ".php";

        return $this->tpl_resolver->resolve($template);
    }

    /**
     * Resolve a asset file.
     *
     * @param $asset string The name of the asset file
     * @return string The location of a matching asset
     */
    public function resolveAsset(string $asset)
    {
        return $this->asset_resolver->resolve($asset);
    }
    
    /**
     * Register a module
     * @param string $module The name of the module
     * @param string $path The path where the module stores its data
     * @param int $precedence Determines the order in which the paths are
     *                        searched. Used for templates and assets, to
     *                        make it possible to reliably override others.
     * @return ModuleManager Provides fluent interface
     */
    public function registerModule(string $module, string $path, int $priority)
    {
        $app_path = $path . DIRECTORY_SEPARATOR . 'app';
        $asset_path = $path . DIRECTORY_SEPARATOR . 'assets';
        $template_path = $path . DIRECTORY_SEPARATOR . 'template';
        $language_path = $path . DIRECTORY_SEPARATOR . 'language';

        if (is_dir($app_path))
            $this->router->addModule($module, $app_path);
        if (is_dir($asset_path))
            $this->asset_resolver->addModule($module, $asset_path, $priority);
        if (is_dir($template_path))
            $this->template_resolver->addModule($module, $template_path, $priority);
        if (is_dir($language_path))
            $this->translator->addPattern($module, $language_path);

        $this->modules[$module] = $path;
        return $this;
    }

    /**
     * Import modules from the Composer configuration
     * @param string $composer_autoloader_class The Composer Autoloader class
     * @return array The list of found modules
     */
    public function importComposerAutoloaderConfiguration(string $composer_autoloader_class)
    {
        // Find the Composer Autoloader class using its (generated) name
        $logger = self::getLogger();
        if ($cl === null)
        {
            $logger->error("Could not find Composer Autoloader class - could not deduce vendor path");
            return;
        }

        // Find the file the composer autoloader was defined in, and use that
        // to infer the vendor path and the base path.
        $ref = new ReflectionClass($cl);
        $fn = $ref->getFileName();
        $vendorDir = dirname(dirname($fn));
        $baseDir = dirname($vendorDir);
        $class_file = dirname($fn) . DIRECTORY_SEPARATOR . "autoload_psr4.php";

        // Check base directory to be a Wedeto module
        $modules = array();
        $base_name = basename($baseDir);
        if (is_dir($base_dir . '/template') || is_dir($base_dir . '/app') || is_dir($template . '/assets'))
            $modules[$base_name] = $base_dir;

        // Find all modules
        $paths = array();
        foreach (glob($vendorDir . "/*") as $vendor)
        {
            if (is_dir($vendor))
            {
                $new_modules = self::findModules($module, ucfirst(basename($vendor)));
                if (count($new_modules))
                    array_merge($modules, $new_modules);
            }
        }

        // Register the modules
        foreach ($modules as $name => $path)
            $this->registerModule($name, $path);

        return $modules;
    }

    /** 
     * Find installed modules in the module path
     * @param $module_path string Where to look for the modules
     */
    public static function findModules(string $module_path, $module_name_prefix = "")
    {
        $dirs = glob($module_path . '/*');

        $logger = self::getLogger();
        $modules = array();
        foreach ($dirs as $dir)
        {
            if (!is_dir($dir))
                continue;

            $has_template = is_dir($dir . '/template');
            $has_app = is_dir($dir . '/app');
            $has_assets = is_dir($dir . '/assets');

            if (!($has_template || $has_app || $has_assets))
            {
                $logger->info("Path {0} does not contain any usable elements", [$dir]);
                continue;
            }
            
            $mod_name = $module_name_prefix . ucfirst(basename($dir));
            $modules[$mod_name] = $dir;
            $logger->debug("Found module {0} in {1}", [$mod_name, $dir]);
        }

        return $modules;
    }
}
