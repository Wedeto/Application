<?php
/*
This is part of Wedeto, the WEb DEvelopment TOolkit.
It is published under the MIT Open Source License.

Copyright 2018, Egbert van der Wal

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

namespace Wedeto\Application\Plugins;

use Wedeto\Util\DI\BasicFactory;
use Wedeto\Util\DI\DI;
use Wedeto\Util\Functions as WF;
use Wedeto\Util\Hook;
use Wedeto\Util\Dictionary;

use Wedeto\Log\Logger;

use Wedeto\Application\Application;
use Wedeto\DB\Migrate\Repository;
use Wedeto\DB\Migrate\Module;

use Wedeto\Application\Task\TaskRunner;
use Wedeto\Application\Task\TaskInterface;

/**
 * Plugin that connects the migrations of Wedeto\DB to the application, to make
 * sure that all modules have their migrations automatically registered.
 */
class DBMigratePlugin implements WedetoPlugin
{
    private $app;

    public function initialize(Application $app)
    {
        $this->app = $app;
        $app->injector->registerFactory(Repository::class, new BasicFactory([$this, "createMigrateRepository"]));

        Hook::subscribe("Wedeto.Application.Task.TaskRunner.findTasks", [$this, 'registerTasks']);
    }

    public function registerTasks(Dictionary $params)
    {
        $taskRunner = $params['taskrunner'];
        $taskRunner->registerTask(MigrationRunner::class, 'Run database migrations');
    }

    /**
     * Factory method to create the DB\Migrate\Repository instance. Registered with Wedeto\DI to
     * make sure that new instances are created here.
     */
    public function createMigrateRepository(array $args)
    {
        $db = $this->app->db;
        $repo = new Repository($db);

        // Add all module paths to the Migration object
        $resolver = $this->app->resolver->getResolver("migrations");
        $mods = [];

        foreach ($resolver->getSearchPath() as $name => $path)
        {
            $module = new Module($name, $path, $db);
            if ($name === "wedeto.db")
                array_unshift($mods, $module);
            else
                array_push($mods, $module);
        }

        foreach ($mods as $module)
            $repo->addModule($module);

        return $repo;
    }
}

class MigrationRunner implements TaskInterface
{   
    public function execute()
    {
        $log = Logger::getLogger(MigrationRunner::class);
        $repository = DI::getInjector()->getInstance(Repository::class);

        $target_version = getenv("WDB_VERSION");
        $target_module = getenv("WDB_MODULE");
        
        if (!empty($target_version) && !empty($target_module))
        {
            $ver = (int)$target_version;
            $module = $repository->getMigration($target_module);
            if (empty($module))
            {
                WF::debug("Invalid module: $target_module");
            }
            else
            {
                $module->migrateTo($ver);
            }
            return;
        }

        foreach ($repository as $module)
        {
            $cv = $module->getCurrentVersion();
            $lv = $module->getLatestVersion();

            if ($module->isUpToDate())
            {
                $log->info("Module {0} is up to date (@{1})", [$module->getModule(), $cv]);
            }
            else
            {
                $log->info("Upgrading module {0} from {1} to {2}", [$module->getModule(), $cv, $lv]);
                $module->upgradeToLatest();
            }
        }
    }
}
