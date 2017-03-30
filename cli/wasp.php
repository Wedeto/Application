<?php

use WASP\Platform\CLI;
use WASP\Platform\TaskRunner;
use WASP\Util\Dictionary;
use WASP\File\Resolve;

require_once "../bootstrap/init.php";

$a = new CLI;
$a->addOption("r", "run", "action", "Run the specified task");
$a->addOption("s", "list", false, "List the available tasks");
$opts = $a->parse($_SERVER['argv']);

if (isset($opts['help']))
    $a->syntax("");

if ($opts->has('list'))
{
    TaskRunner::listTasks();
    exit();
}

if (!$opts->has('run'))
    $a->syntax("Please specify the action to run");

TaskRunner::run($opts->get('run'));
