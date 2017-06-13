<?php

use Wedeto\Application\Application;
use Wedeto\Application\PathConfig;
use Wedeto\Application\CLI\CLI;
use Wedeto\Application\Task\TaskRunner;
use Wedeto\Util\Dictionary;

$my_dir = dirname(__FILE__);
$parent_dir = dirname($my_dir);

if (!class_exists(Application::class))
{
    if (file_exists($parent_dir . '/autoload.php'))
        require_once $parent_dir . '/autoload.php';
    elseif (file_exists($parent_dir . '/vendor/autoload.php'))
        require_once $parent_dir . '/vendor/autoload.php';
    else
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
