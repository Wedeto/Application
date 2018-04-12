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

use Wedeto\Application\Application;
use Wedeto\DB\Migrate\Repository;
use Wedeto\DB\Migrate\Module;

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
    }

    /**
     * Factory method to create the DB\Migrate\Repository instance. Registered with Wedeto\DI to
     * make sure that new instances are created here.
     */
    public function createMigrateRepository(array $args)
    {
        $db = $app->db;
        $repo = new Repository($db);

        // Add all module paths to the Migration object
        $modules = $app->resolver->getResolver("migrations");
        foreach ($modules as $name => $path)
        {
            $module = new Module($name, $path, $db);
            $repo->addModule($module);
        }

        return $repo;
    }
}
