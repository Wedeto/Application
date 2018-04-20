<?php
/*
This is part of Wedeto, the WEb DEvelopment TOolkit.
It is published under the MIT Open Source License.

Copyright 2017-2018, Egbert van der Wal

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

use Wedeto\Resolve\Autoloader;
use Wedeto\Util\Functions as WF;
use Wedeto\IO\Path;
use Wedeto\IO\PermissionError;
use Wedeto\IO\IOException;
use Wedeto\Util\DI\InjectionTrait;

class PathConfig
{
    use InjectionTrait;

    /** Path where Composer stores the dependencies - obtained from Composer autoloader */
    protected $vendor_dir = null;

    /** Root path. Defaults to the parent directory of the Composer vendor directory */
    protected $root;

    /** Path where configuration files are stored. Defaults to root/config */
    protected $config;

    /** Path where web server serves the files of the Wedeto application. Defaults to root/webroot */
    protected $webroot;

    /** Path where web server serves the asset files. Defaults to webroot/assets */
    protected $assets;

    /** Path where Wedeto should have write access to store temporary files. Defaults to root/var */
    protected $var;

    /** Path where Wedeto should have write access to store cache files. Defaults to var/cache */
    protected $cache;

    /** Path where Wedeto should have write access to store log files. Defaults to var/log */
    protected $log;

    /** Path where Wedeto should have write access to store uploaded files. Defaults to root/uploads */
    protected $uploads;

    /** Whether the current path configuration has been validated */
    protected $path_checked = false;
    
    /** If the script is run from CLI or through the web */
    protected $cli;

    /**
     * Construct the PathConfig object. Pass in a root path,
     * or an array of path element => path pairs.
     *
     * @param string|array $root The root path or a set of path elements. May
     *                           be omitted to do auto-configuration. When using
     *                           composer, this means the root will be set to the
     *                           parent path of the Composer vendor directory.
     */
    public function __construct($root = null)
    {
        if (is_string($root))
            $root = ['root' => $root];

        if (WF::is_array_like($root))
        {
            // Validate values
            foreach ($root as $key => $path)
            {
                if (!property_exists($this, $key))
                    throw new \InvalidArgumentException("Invalid path element: " . $key);
                if (!is_string($path) || empty($path))
                    throw new \InvalidArgumentException("Invalid path: " . WF::str($path));
                if (!file_exists($path))
                    throw new IOException("Path '$key' does not exist: " . $path);
                if (!is_dir($path))
                    throw new IOException("Path is not a directory: " . $path);
            }

            extract($root);
        }
        elseif (!empty($root))
            throw new \InvalidArgumentException("Invalid path: " . WF::str($root));

        $SEP = DIRECTORY_SEPARATOR;
        $composer_class = Autoloader::findComposerAutoloader();
        if (!empty($composer_class))
        {
            $this->vendor_dir = Path::realpath(Autoloader::findComposerAutoloaderVendorDir($composer_class));
            $this->root = $root ?? dirname($this->vendor_dir);
        }
        else
        {
            // @codeCoverageIgnoreStart
            // PHPUnit is run using Composer
            $this->root = $root ?? Path::realpath(dirname($_SERVER['SCRIPT_FILENAME']));
            // @codeCoverageIgnoreEnd
        }

        $this->cli = PHP_SAPI === 'cli';
        if ($this->cli || PHP_SAPI === 'cli-server')
        {
            $this->webroot = $webroot ?? $this->root . $SEP . 'http';
        }
        else
        {
            // @codeCoverageIgnoreStart
            // PHPUnit is run in CLI
            $this->webroot = $webroot ?? Path::realpath(dirname($_SERVER['SCRIPT_FILENAME']));
            // @codeCoverageIgnoreEnd
        }

        $this->config = $config ?? $this->root . $SEP . 'config';
        $this->var = $var ?? $this->root . $SEP . 'var';
        $this->uploads = $uploads ?? $this->root . $SEP . 'uploads';

        $this->assets = $assets ?? $this->webroot . $SEP . 'assets';

        $this->log = $log ?? $this->var . $SEP . 'log';
        $this->cache = $cache ?? $this->var . $SEP . 'cache';
    }

    /**
     * Validate path configuration.
     * @return bool True when the path configuration is ok
     * @throws IOException When a path element does not exist
     * @throws PermissionException When permissions are incorrect
     */
    public function checkPaths()
    {
        if ($this->path_checked)
            return true;

        foreach (array('root', 'webroot') as $type)
        {
            $path = $this->$type;
            if (!file_exists($path) || !is_dir($path))
                throw new IOException("Path '$type' does not exist: " . $path);

            if (!is_readable($path))
                throw new PermissionError($path, "Path '$type' cannot be read");
        }

        if (!is_dir($this->config) || is_readable($this->config))
            $this->config = null;

        foreach (array('var', 'cache', 'log', 'uploads') as $write_dir)
        {
            $path = $this->$write_dir;
            if (!is_dir($path))
            {
                $dn = dirname($path);
                if (!file_exists($path) && $dn === $this->var)
                {
                    // The parent directory is var/ and must be writeable as
                    // this was checked earlier in this loop.
                    Path::mkdir($path);
                }
                else
                {
                    if (file_exists($path))
                        throw new IOException("Path '$write_dir' exists but is not a directory: " . $path);
                    $this->$write_dir = null;
                    continue;
                }
            }

            if (!is_writable($path))
            {
                try
                {
                    // We can try to fix permissions if we own the file
                    Path::makeWritable($path);
                }
                catch (PermissionError $e)
                {
                    $this->$write_dir = null;
                    if ($this->cli)
                        WF::debug("Failed to get write access to: %s", $e->getMessage());
                }
            }
        }
        $this->path_checked = true;
        return true;
    }

    /**
     * Get any of the path elements - magic method
     *
     * @param string $field The path to get
     * @return string The path to the requested path element
     */
    public function __get($field)
    {
        if (!property_exists($this, $field))
            throw new \InvalidArgumentException("Invalid path: $field");

        return $this->$field;
    }

    /**
     * Set any of the path elements - magic method
     *
     * @param string $field The path to set
     * @param string $value The path for this path element
     */
    public function __set($field, $value)
    {
        if (!is_string($value))
            throw new \InvalidArgumentException("Invalid path: " . WF::str($value));

        if (!property_exists($this, $field))
            throw new \InvalidArgumentException("Invalid path element: $field");

        $this->$field = $value;
    }
}
