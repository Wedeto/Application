<?php

use Wedeto\Application\PathConfig;
use Wedeto\Application\Application;
use Wedeto\Application\CLI\CLI;
use Wedeto\IO\Path;
use Wedeto\Util\Hook;
use Wedeto\Util\Functions as WF;
use Wedeto\Application\CLI\ANSI;

$my_dir = dirname(getcwd() . DIRECTORY_SEPARATOR . $_SERVER['argv'][0]);
$parent_dir = dirname($my_dir);

// Attempt to automatically find the autoloader file
if (!class_exists(PathConfig::class))
{
    $paths = [
        $my_dir,
        $parent_dir,
        $parent_dir . '/vendor'
    ];

    while (true)
    {
        $parent_dir = dirname($parent_dir);
        $name = basename($parent_dir);

        if (empty($name))
            break;

        if ($name === 'vendor')
            $paths[] = $parent_dir;
    }

    $found_autoloader = false;
    foreach ($paths as $path)
    {
        $file = $path . DIRECTORY_SEPARATOR . 'autoload.php';
        echo $file . "\n";
        if (file_exists($file))
        {
            require_once $file;
            $found_autoloader = true;
        }
    }

    if (!$found_autoloader)
        throw new \RuntimeException("Cannot load autoloader");

    if (!class_exists(PathConfig::class))
        throw new \RuntimeException("Cannot load application");
}

$pc = new PathConfig;

$LN = "\n";
$DS = DIRECTORY_SEPARATOR;

echo ANSI::underline(ANSI::bright("Welcome to Wedeto Setup!")) . $LN;
echo "This script will take you through a few simple steps to start working with Wedeto" . $LN;
echo $LN;

$refl = new ReflectionClass(Application::class);
$f = $refl->getFileName();

$app_dir = dirname(dirname($f));

if (CLI::input(ANSI::bright("Would you like to set up a http webroot in " . $pc->webroot . "?")) === 'y')
{
    if (!is_dir($pc->webroot))
        Path::mkdir($pc->webroot);
        
    $tgt = $pc->webroot . $DS . 'index.php';
    if (!file_exists())
    {
        if (CLI::input(ANSI::bright("Would you like to create a default index.php in the webroot?")) === 'y')
        {
            copy($app_dir . $DS . 'http' . $DS . 'index.php', $tgt);
            Hook::execute('Wedeto.IO.FileCreated', ['path' => $tgt]);
        }
    }

    $tgt = $pc->webroot . $DS . '.htaccess';
    if (!file_exists($tgt))
    {
        if (CLI::input(ANSI::bright("Would you like to create a default .htaccess for Apache in the webroot?")) === 'y')
        {
            copy($app_dir . $DS . 'http' . $DS . '.htaccess', $tgt);
            Hook::execute('Wedeto.IO.FileCreated', ['path' => $tgt]);
        }
    }

    $assets = $pc->webroot . $DS . 'assets';
    if (!is_dir($assets))
    {
        if (CLI::input(ANSII::bright("Would you like to create a directory named assets in the webroot for storing assets?")) === 'y')
        {
            Path::mkdir($pc->webroot . $DS . 'assets');
        }
    }
}

$src = $app_dir . $DS . 'config' . $DS . 'main.ini';
$tgt = $pc->config . $DS . 'main.ini';
if (!file_exists($tgt))
{
    if (CLI::input(ANSI::bright("Would you like to set up a configuration file in {$tgt}?")) === 'y')
    {
        if (!is_dir($pc->config))
            Path::mkdir($pc->config);

        copy($app_dir . $DS . 'main.ini', $tgt);
        Hook::execute('Wedeto.IO.FileCreated', ['path' => $tgt]);
    }
}

if (!is_dir($pc->var))
{
    if (CLI::input(ANSI::bright("Would you like to create the {$pc->var} directory for logs and cache?")) === 'y')
    {
        Path::mkdir($pc->var);
    }
}

if (!is_dir($pc->log))
{
    if (CLI::input(ANSI::bright("Would you like to create the {$pc->var} directory for log files?")) === 'y')
    {
        Path::mkdir($pc->log);
    }
}

if (!is_dir($pc->cache))
{
    if (CLI::input(ANSI::bright("Would you like to create the {$pc->var} directory for cache storage?")) === 'y')
    {
        Path::mkdir($pc->cache);
    }
}

$tgt = $pc->config . $DS . 'main.ini';
if (file_exists($tgt))
{
    if (CLI::input(ANSI::bright("Would you like to set up a database in {$tgt}?")) === 'y')
    {
        $type = CLI::input(ANSI::bright('Enter the database type: [MySQL/PGSQL]: '));
        $host = CLI::input(ANSI::bright('Enter the database server address:  '));
        $username = CLI::input(ANSI::bright('Enter the username: '));
        $password = CLI::input(ANSI::bright('Enter the password: '));
        $db = CLI::input(ANSI::bright('Enter the database name: '));

        $reader = new Wedeto\FileFormaters\INI\Reader;
        $cur = $reader->read($tgt);

        if (!isset($cur['db']))
            $cur['db'] = [];

        $cur['db']['type'] = $type;
        $cur['db']['hostname'] = $host;
        $cur['db']['username'] = $username;
        $cur['db']['password'] = $password;

        echo WF::str($cur) . $LN;

        $writer = new Wedeto\FileFormaters\INI\Writer;
        $writer->rewrite($cur, $tgt);
    }
}
