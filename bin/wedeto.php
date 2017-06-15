<?php

use Wedeto\Application\Application;
use Wedeto\Application\PathConfig;
use Wedeto\Application\CLI\CLI;
use Wedeto\Application\Task\TaskRunner;
use Wedeto\Util\Dictionary;

$my_dir = dirname(getcwd() . DIRECTORY_SEPARATOR . $_SERVER['argv'][0]);
$parent_dir = dirname($my_dir);

if (!class_exists(Application::class))
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

    if (!class_exists(Application::class))
        throw new \RuntimeException("Cannot load application");
}

$pathconfig = new PathConfig;
$ini_file = $pathconfig->config . '/main.ini';

if (file_exists($ini_file))
{
    $config = parse_ini_file($ini_file, true, INI_SCANNER_TYPED);
    $config = new Dictionary($config);
}
else
{
    $config = new Dictionary;
}

$app = Application::setup($pathconfig, $config);

$app->handleCLIRequest();
