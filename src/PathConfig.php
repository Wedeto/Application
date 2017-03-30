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

namespace WASP\Platform;

use WASP\IO\Path;
use WASP\IO\IOException;

final class PathConfig
{
    private $root;
    private $config;
    private $var;
    private $cache;
    private $log;

    private $http;
    private $assets;
    private $js;
    private $css;
    private $img;

    private $path_checked = false;

    private static $instance = null;

    public function __construct(array $paths)
    {
        self::$instance = $this;

        $keys = array_keys($paths);
        foreach ($keys as $key)
        {
            $p = $paths[$key];
            $rp = realpath($p);
            if ($rp === false)
                throw new IOException("Path $key ($p) does not exist");
            $paths[$key] = $rp;
        }

        // Starting point is a root path
        $this->root = isset($paths['root']) ? $paths['root'] : realpath(dirname(dirname(dirname(__FILE__))));

        // Determine other locations based on root if not specified
        $this->core = isset($paths['core']) ? $paths['core'] : $this->root . '/core';
        $this->config = isset($paths['config']) ? $paths['config'] : $this->root . '/config';
        $this->var = isset($paths['var']) ? $paths['var'] : $this->root . '/var';
        $this->modules = isset($paths['modules']) ? $paths['modules'] : $this->root . '/modules';
        $this->log = isset($paths['log']) ? $paths['log'] : $this->var . '/log';

        if (isset($paths['http']))
        {
            // Check if explicitly configured
            $this->http = $paths['http'];
        }
        elseif (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']))
        {
            // We should be able to detect the webroot automatically, based on the location of the index.php
            $filename = realpath($_SERVER['SCRIPT_FILENAME']);
            $this->http = dirname($filename);
        }
        else
            $this->http = $this->root . '/http';

        $this->cache = $this->var . '/cache';
        $this->assets = $this->http . '/assets';
        $this->js = $this->assets . '/js';
        $this->css = $this->assets . '/css';
        $this->img = $this->assets . '/img';

    }

    public function checkPaths()
    {
        if ($this->path_checked && (!is_defined('WASP_TEST') || WASP_TEST === 0))
            return;

        foreach (array('root', 'core', 'var', 'modules', 'http', 'config', 'assets') as $type)
        {
            $path = $this->$type;
            if (!file_exists($path) || !is_dir($path))
                throw new IOException("Path $type (" . $path . ") does not exist");

            if (!is_readable($path))
                throw new PermissionError($path, "Path '$type' cannot be read");
        }
        
        foreach (array('var', 'cache', 'log') as $write_dir)
        {
            $path = $this->$write_dir;
            Path::mkdir($path);

            if (!is_dir($path))
                throw new IOException("Path " . $path . " is not a directory");

            if (!is_writable($path)) // We can try to make it writable, if we own the file
                Path::makeWritable($path);
        }
        $this->path_checked = true;
    }

    public static function current()
    {
        if (self::$instance === null) throw new \RuntimeException("No path config available");

        return self::$instance;
    }

    public static function setCurrent(Path $instance)
    {
        if (!defined('WASP_TEST') || WASP_TEST !== 1)
            throw new \RuntimeException("Cannot change active path instance");
        self::$instance = $instance;
    }

    public function __get($field)
    {
        if (property_exists($this, $field))
            return $this->$field;
        throw new \InvalidArgumentException("Invalid path: $field");
    }
}
