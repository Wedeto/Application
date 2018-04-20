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

use RuntimeException;
use InvalidArgumentException;

use Psr\Log\LogLevel;

use Wedeto\IO\Path;
use Wedeto\IO\PermissionError;

use Wedeto\Util\Cache;
use Wedeto\Util\Configuration;
use Wedeto\Util\DI\DI;
use Wedeto\Util\DI\InjectionTrait;
use Wedeto\Util\Dictionary;
use Wedeto\Util\ErrorInterceptor;
use Wedeto\Util\Functions as WF;
use Wedeto\Util\Hook;
use Wedeto\Util\LoggerAwareStaticTrait;
use Wedeto\Util\Validation\Type;

use Wedeto\Log\{Logger, LoggerFactory};
use Wedeto\Log\Writer\{FileWriter, MemLogWriter, AbstractWriter};

use Wedeto\Resolve\Autoloader;
use Wedeto\Resolve\Resolver;
use Wedeto\Resolve\Router;

use Wedeto\Application\Dispatch\Dispatcher;
use Wedeto\Auth\Authentication;

use Wedeto\HTTP\ProcessChain;
use Wedeto\HTTP\Request;
use Wedeto\HTTP\Response\Response;
use Wedeto\HTTP\Responder;

use Wedeto\HTML\Template;
use Wedeto\HTML\AssetManager;

use Wedeto\Mail\SMTPSender;
use Wedeto\DB\DB;
use Wedeto\I18n\I18n;


// @codeCoverageIgnoreStart
if (!defined('WEDETO_TEST'))
    define('WEDETO_TEST', 0);
// @codeCoverageIgnoreEnd

class Application
{
    use LoggerAwareStaticTrait;
    use InjectionTrait;

    const WDT_NO_AUTO = true;
    const WDI_REUSABLE = true;

    protected $autoloader = null;

    protected $dev = true;
    protected $config;
    protected $injector;
    protected $cachemanager;
    protected $module_manager;
    protected $path_config;
    protected $resolver;
    protected $request;
    protected $is_shutdown = false;
    protected $http_chain = null;

    /**
     * Create the object. To do this, a path configuration and a application configuration is required
     * but these can be omitted assume configuration out of convention.
     */
    public function __construct(PathConfig $path_config = null, Configuration $config = null)
    {
        $this->injector = DI::getInjector();
        $this->injector->setInstance(static::class, $this);
        self::setLogger(Logger::getLogger(static::class));

        $this->path_config = $path_config ?? new PathConfig;
        $this->config = $config ?? new Configuration;
        $this->injector->setInstance(Configuration::class, $this->config);
        $this->bootstrap();
    }

    /**
     * Helper method to bootstrap the system - adjust PHP configuration, set up logging and autoloading
     */
    private function bootstrap()
    {
        // Attach the error handler - all PHP Errors should be thrown as Exceptions
        ErrorInterceptor::registerErrorHandler();
        set_exception_handler(array(static::class, 'handleException'));

        // Set character set
        ini_set('default_charset', 'UTF-8');
        mb_internal_encoding('UTF-8');

        // Set up logging
        ini_set('log_errors', '1');

        // Load configuration
        $ini_file = $this->path_config->config . '/main.ini';
        if (file_exists($ini_file))
        {
            $config = parse_ini_file($ini_file, true, INI_SCANNER_TYPED);
            if ($config !== false)
            {
                $ini_config = new Dictionary($config);
                $this->config->addAll($ini_config);
                if ($ini_config->has('path', Type::ARRAY))
                {
                    foreach ($ini_config->get('path') as $element => $path)
                        $this->path_config->$element = $path;
                }
            }
        }

        $this->cachemanager = $this->injector->getInstance(Cache\Manager::class);
        $this->dev = $this->config->dget('site', 'dev', true);

        // Set up the autoloader
        $this->configureAutoloaderAndResolver();

        // Make sure permissions are adequate
        try
        {
            $this->path_config->checkPaths();
        }
        catch (PermissionError $e)
        {
            return $this->showPermissionError($e);
        }

        $test = defined('WEDETO_TEST') && WEDETO_TEST === 1 ? 'test' : '';
        if (PHP_SAPI === 'cli')
            ini_set('error_log', $this->path_config->log . '/error-php-cli' . $test . '.log');
        else
            ini_set('error_log', $this->path_config->log . '/error-php' . $test . '.log');

        // Set default permissions for files and directories
        $this->setCreatePermissions();

        // Autoloader requires manual logger setup to avoid depending on external files
        LoggerFactory::setLoggerFactory(new LoggerFactory());
        Autoloader::setLogger(LoggerFactory::getLogger(['class' => Autoloader::class]));

        // Set up root logger
        $this->setupLogging();

        // Save the cache if configured so
        if ($this->path_config->cache)
        {
            $this->cachemanager->setCachePath($this->path_config->cache);
            $this->cachemanager->setHook($this->config->dget('cache', 'expiry', 60));
        }

        // Find installed modules and initialize them
        $this->module_manager = new Module\Manager($this->resolver);

        // Prepare the HTTP Request Object
        $this->request = Request::createFromGlobals();

        // Load plugins
        $this->setupPlugins();
    }
    
    /**
     * Set up all plugins specified in the config file. Plugins can be specified
     * in the [plugins] section. The key can be any reference, the value
     * should either be a fully qualified class name, or a name of a class in 
     * Wedeto\Application\Plugins. Each plugin should implement
     * Wedeto\Application\Plugins\WedetoPlugin
     */
    private function setupPlugins()
    {
        $plugins = $this->config->has('plugins', Type::ARRAY) ?
            $this->config->get('plugins', Type::ARRAY)
            :
            ['i18n' => 'I18nPlugin', 'httpchain' => 'ProcessChainPlugin']
        ;

        foreach ($plugins as $key => $value)
        {
            if (!is_string($value))
            {
                self::$logger->error("Not a plugin class: {0}", [$value]);
                continue;
            }
                
            if (is_a($value, Plugins\WedetoPlugin::class, true))
            {
                $fqcn = $value;
            }
            else
            {
                $fqcn = __NAMESPACE__ . "\\Plugins\\" . $value;
            }


            if (is_a($fqcn, Plugins\WedetoPlugin::class, true))
            {
                $instance = $this->injector->getInstance($fqcn);
                $instance->initialize($this);
            }
            else
            {
                self::$logger->error("Not a plugin class: {0}", [$value]);
            }
        }
    }

    /**
     * Magic method to allow access to modules as properties.
     *
     * @param string $parameter The module to get
     */
    public function __get($parameter)
    {
        return $this->get($parameter);
    }

    /**
     * Allow access to one or more of the core Wedeto components.
     *
     * Objects non-essential for the core are instantiated upon first request.
     */
    public function get($parameter)
    {
        switch ($parameter)
        {
            // Essential components are instantiated during application set up
            case "dev":
                return $this->dev ?? true;
            case "config":
                return $this->config;
            case "injector":
                return $this->injector;
            case "pathConfig":
                return $this->path_config;
            case "request":
                return $this->request;
            case "resolver":
                return $this->resolver;
            case "moduleManager":
                return $this->module_manager;

            // Non-essential components are created on-the-fly
            case "auth":
                return $this->injector->getInstance(Authentication::class);
            case "db":
                return $this->injector->getInstance(DB::class);
            case "dispatcher":
                return $this->injector->getInstance(Dispatcher::class);
            case "template":
                return $this->injector->getInstance(Template::class);
            case "mailer":
                return $this->injector->getInstance(SMTPSender::class);
            case "i18n":
                return $this->injector->getInstance(I18n::class);
            case "processChain":
                return $this->injector->getInstance(ProcessChain::class);
        }
        throw new InvalidArgumentException("No such object: $parameter");
    }

    /**
     * Helper to display permission errors when system setup fails due to
     * broken permissions.
     */
    private function showPermissionError(PermissionError $e)
    {
        if (PHP_SAPI !== "cli")
        {
            http_response_code(500);
            header("Content-type: text/plain");
        }

        if ($this->dev)
        {
            $file = $e->path;
            echo "{$e->getMessage()}\n";
            echo "\n";
            echo WF::str($e, false);
        }
        else
        {
            echo "A permission error is preventing this page from displaying properly.";
        }
        die();
    }

    /**
     * Configure the create permissions when new files are created by Wedeto.
     */
    private function setCreatePermissions()
    {
        if ($this->config->has('io', 'group'))
            Path::setDefaultFileGroup($this->config->get('io', 'group'));

        $file_mode = (int)$this->config->get('io', 'file_mode');
        if ($file_mode)
        {
            $of = $file_mode;
            $file_mode = octdec(sprintf("%04d", $file_mode));
            Path::setDefaultFileMode($file_mode);
        }

        $dir_mode = (int)$this->config->get('io', 'dir_mode');
        if ($dir_mode)
        {
            $of = $dir_mode;
            $dir_mode = octdec(sprintf("%04d", $dir_mode));
            Path::setDefaultDirMode($dir_mode);
        }
    }

    /**
     * Configure the auto loader - replace the default autoloader provided by composer
     * and register all resolvable assets.
     */
    private function configureAutoloaderAndResolver()
    {
        if ($this->autoloader !== null)
            return;

        // Construct the Wedeto autoloader and resolver
        $cache = $this->cachemanager->getCache("resolution");

        $this->autoloader = new Autoloader();
        $this->autoloader->setCache($cache);
        $this->injector->setInstance(Autoloader::class, $this->autoloader);

        $this->resolver = new Resolver($cache);
        $this->resolver
            ->addResolverType('template', 'template', '.php')
            ->addResolverType('assets', 'assets')
            ->addResolverType('app', 'app')
            ->addResolverType('code', 'src')
            ->addResolverType('language', 'language')
            ->addResolverType('migrations', 'migrations')
            ->setResolver("app", new Router("router"));
        $this->injector->setInstance(Resolver::class, $this->resolver);

        spl_autoload_register(array($this->autoloader, 'autoload'), true, true);

        $cl = Autoloader::findComposerAutoloader();
        if (!empty($cl))
        {
            $vendor_dir = Autoloader::findComposerAutoloaderVendorDir($cl);
            $this->autoloader->importComposerAutoloaderConfiguration($vendor_dir);
            $this->resolver->autoConfigureFromComposer($vendor_dir);
        }
        else
        {
            // Apparently, composer is not in use. Assume that Wedeto is packaged in one directory and add that
            $my_dir = __DIR__;
            $wedeto_dir = dirname(dirname($my_dir));

            $this->autoloader->registerNS("Wedeto\\", $wedeto_dir, Autoloader::PSR4);

            // Find modules containing assets, templates or apps
            $modules = $this->resolver->findModules($wedeto_dir , '/modules', "", 0);
            foreach ($modules as $name => $path)
                $this->resolver->registerModule($name, $path);
        }
    }

    /**
     * Helper method to set up logging based on the application configuration file.
     */
    private function setupLogging()
    {
        $test = defined('WEDETO_TEST') && WEDETO_TEST === 1 ? 'test' : '';

        $root_logger = Logger::getLogger();
        $root_logger->setLevel(LogLevel::INFO);

        if ($this->path_config->log)
        {
            $logfile = $this->path_config->log . '/wedeto' . $test . '.log';
            $root_logger->addLogWriter(new FileWriter($logfile, LogLevel::DEBUG));
        }

        // Set up logger based on ini file
        if ($this->config->has('log', Type::ARRAY))
        {
            foreach ($this->config['log'] as $logname => $level)
            {
                if ($logname === "writer")
                {
                    foreach ($level as $logname => $parameters)
                    {
                        $logger = Logger::getLogger($logname);
                        $parameters = str_replace('{LOGDIR}', $this->path_config->log, $parameters);
                        $parameters = explode(';', $parameters);

                        $class = array_shift($parameters);
                        if (!class_exists($class))
                            throw new \DomainException("Invalid logger class: $class");

                        $refl = new \ReflectionClass($class);
                        $writer = $refl->newInstanceArgs($parameters);

                        if (!($writer instanceof AbstractWriter))
                            throw new \DomainException("Class $class is not a log writer");

                        $logger->addLogWriter($writer);
                    }
                    continue;
                }
                
                $logger = Logger::getLogger($logname);
                $level = strtolower($level);

                try
                {
                    $logger->setLevel($level);
                }
                catch (\DomainException $e)
                {
                    self::$logger->error("Failed to set log level for {0} to {1}: {2}", [$logname, $level, $e->getMessage()]);
                }
            }
        }

        // Load the configuration file
        // Attach the dev logger when dev-mode is enabled
        if ($this->dev)
        {
            $devlogger = new MemLogWriter(LogLevel::DEBUG);
            $root_logger->addLogWriter($devlogger);
        }

        // Log beginning of request handling
        if (isset($_SERVER['REQUEST_URI']))
        {
            self::$logger->debug(
                "*** Starting processing for {0} request to {1}", 
                [$_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']]
            );
        }

        // Change settings for CLI
        if (Request::cli())
        {
            $limit = (int)$this->config->dget('cli', 'memory_limit', 1024);
            ini_set('memory_limit', $limit . 'M');
            ini_set('max_execution_time', 0);

            ini_set('display_errors', 1);
        }
        else
            ini_set('display_errors', 0);
    }

    /**
     * The exception handler is called whenever an uncaught exception occurs.
     *
     * This will send a notification to the user that something went wrong,
     * attempting to format this as fancy as possible given the circumstances.
     *
     * @param Throwable $e The exception that wasn't caught elsewhere
     */
    public static function handleException(\Throwable $e)
    {
        $app = self::$instance;
        if ($app->request === null)
            $app->request = Request::createFromGlobals();

        $req = $app->request;

        try
        {
            // Try to form a good looking response
            if (!Request::cli())
            {
                $mgr = $app->resolver;
                $res = $mgr->getResolver('template');
                $assets = $mgr->getResolver('assets');
                $amgr = new AssetManager($assets);

                $tpl = new Template($res, $amgr, $req);
                $tpl->setExceptionTemplate($e);

                $tpl->assign('exception', $e);
                $tpl->assign('request', $req);
                $tpl->assign('dev', $app->dev);
                Application::i18n();

                $response = $tpl->renderReturn();
                $responder = new \Wedeto\HTTP\Responder($req);
                $responder->setResponse($response);

                // Inject CSS/JS
                $params = new Dictionary(['responder' => $responder, 'mime' => 'text/html']);
                $amgr->executeHook($params);

                $responder->respond();
            }
        }
        catch (\Throwable $e2)
        {
            echo "<h1>Error while showing error template:</h1>\n\n";
            echo "<pre>" . WF::html($e2) . "</pre>\n";
        }

        if (Request::cli())
        {
            fprintf(STDERR, \Wedeto\Application\CLI\ANSI::bright("An uncaught exception has occurred:") . "\n\n");
            WF::debug(WF::str($e));
        }
        else
        {
            echo "<h2>Original error:</h2>\n\n";
            echo "<pre>" . WF::html($e) . "</pre>\n";
        }
    }

    /**
     * Terminate the application - unregister handlers and autoloaders. Does a
     * best effort to restore the global state.
     */
    public function shutdown()
    {
        if (!$this->is_shutdown)
        {
            $this->is_shutdown = true;

            // Unregister the autoloader
            if (!empty($this->autoloader))
                spl_autoload_unregister(array($this->autoloader, 'autoload'));

            // Unregister the error handler
            ErrorInterceptor::unregisterErrorHandler();

            // Unregister the exception handler
            restore_exception_handler();
        }
    }

    /**
     * Handle an incoming web request: parse it, route it, execute the controller and
     * send the response back.
     */
    public function handleWebRequest()
    {
        $chain = $this->processChain;
        $chain->process($this->request);
    }

    /**
     * Handle a CLI request: parse the command line options and send it off to the task runner
     */
    public function handleCLIRequest()
    {
        $cli = new CLI\CLI;
        $cli->addOption("r", "run", "action", "Run the specified task");
        $cli->addOption("s", "list", false, "List the available tasks");
        $opts = $cli->parse($_SERVER['argv']);

        if (isset($opts['help']))
            $cli->syntax("");

        $taskrunner = new Task\TaskRunner($this);
        if ($opts->has('list'))
        {
            $taskrunner->listTasks();
            exit();
        }

        if (!$opts->has('run'))
            $cli->syntax("Please specify the action to run");

        $taskrunner->run($opts->get('run'));
    }
}
