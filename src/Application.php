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

namespace Wedeto\Application;

use RuntimeException;
use InvalidArgumentException;

use Psr\Log\LogLevel;

use Wedeto\IO\Path;
use Wedeto\IO\PermissionError;

use Wedeto\Util\Dictionary;
use Wedeto\Util\Cache;
use Wedeto\Util\Type;
use Wedeto\Util\Hook;
use Wedeto\Util\Functions as WF;
use Wedeto\Util\ErrorInterceptor;
use Wedeto\Util\LoggerAwareStaticTrait;

use Wedeto\Log\{Logger, LoggerFactory};
use Wedeto\Log\Writer\{FileWriter, MemLogWriter, AbstractWriter};
use Wedeto\Log\Formatter\PatternFormatter;

use Wedeto\Resolve\Autoloader;
use Wedeto\Resolve\Manager as ResolveManager;
use Wedeto\Resolve\Router;

use Wedeto\Application\Dispatch\Dispatcher;

use Wedeto\HTTP\Request;
use Wedeto\HTTP\Response\Response;

use Wedeto\I18n\I18n;
use Wedeto\I18n\I18nShortcut;
use Wedeto\I18n\Translator\TranslationLogger;

use Wedeto\HTTP\Responder;

use Wedeto\Auth\Authentication;

use Wedeto\DB\DB;

// @codeCoverageIgnoreStart
if (!defined('WEDETO_TEST'))
    define('WEDETO_TEST', 0);
// @codeCoverageIgnoreEnd

class Application
{
    use LoggerAwareStaticTrait;

    private static $instance = null;

    protected $autoloader = null;

    protected $config;
    protected $auth;
    protected $db;
    protected $dispatcher;
    protected $i18n;
    protected $module_manager;
    protected $path_config;
    protected $resolver;
    protected $request;
    protected $template;
    protected $dev = true;

    public static function hasInstance()
    {
        return self::$instance !== null;
    }

    public static function getInstance()
    {
        if (self::$instance === null)
            throw new RuntimeException("Wedeto has not been initialized yet");

        return self::$instance;
    }

    public static function setInstance(Application $application = null)
    {
        self::$instance = $application;
    }

    public function __construct(PathConfig $path_config = null, Dictionary $config = null)
    {
        self::setLogger(Logger::getLogger(static::class));
        self::$instance = $this;

        $this->path_config = $path_config ?? new PathConfig;
        $this->config = $config ?? new Dictionary;
        $this->bootstrap();
    }

    protected function bootstrap()
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
            Cache::setCachePath($this->path_config->cache);
            Cache::setHook($this->config->dget('cache', 'expiry', 60));
        }

        // Find installed modules and initialize them
        $this->module_manager = new Module\Manager($this->resolver);

        // Prepare the HTTP Request Object
        $this->request = Request::createFromGlobals();
    }
    
    public static function __callStatic($func, $arguments)
    {
        $instance = self::getInstance();
        return $instance->__get($func);
    }

    public function __get($parameter)
    {
        return $this->get($parameter);
    }

    public function getAuth()
    {
        if ($this->auth === null)
            $this->auth = new Authentication($this->config);
        return $this->auth;
    }

    public function getDB()
    {
        if ($this->db === null)
        {
            if ($this->config->has('sql', Type::ARRAY))
                $this->db = DB::get($this->config);
        }
        return $this->db;
    }

    public function getI18n()
    {
        if ($this->i18n === null)
        {
            $this->i18n = new I18n;
            I18nShortcut::setInstance($this->i18n);

            $this->i18n->registerTextDomain('wedeto', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'language');

            // Set a language
            $language = $this->config->dget('site', 'default_language', 'en');
            $this->i18n->setLocale($language);
        }
        return $this->i18n;
    }

    public function get($parameter)
    {
        switch ($parameter)
        {
            case "dev":
                return $this->dev ?? true;
            case "config":
                return $this->config;
            case "auth":
                return $this->getAuth();
            case "pathConfig":
                return $this->path_config;
            case "request":
                return $this->request;
            case "resolver":
                return $this->resolver;
            case "moduleManager":
                return $this->module_manager;
            case "db":
                return $this->getDB();
            case "i18n":
                return $this->getI18n();
            case "dispatcher":
                if ($this->dispatcher === null)
                {
                    $this->dispatcher = Dispatcher::createFromApplication($this);
                    $this->template = $this->dispatcher->getTemplate();
                    $amgr = $this->template->getAssetManager();
                    $cache = new Cache('wedeto-asset-manager-cache');
                    $amgr->setCache($cache);
                }
                return $this->dispatcher;
            case "template":
                if ($this->template === null)
                    $this->template = $this->get('dispatcher')->getTemplate();
                return $this->template;
        }
        throw new InvalidArgumentException("No such object: $parameter");
    }

    /**
     * Overwrite system objects, only to be used in unit tests
     */
    public function __set($parameter, $value)
    {
        if (!defined('WEDETO_TEST') || WEDETO_TEST !== 1)
            throw new RuntimeException("Cannot modify system objects");
        
        if (!property_exists($this, $parameter))
            throw new InvalidArgumentException("No such object: $parameter");

        $this->$parameter = $value;
    }

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

    private function setupTranslateLog()
    {
        $logger = Logger::getLogger('Wedeto.I18n.Translator.Translator');
        $writer = new TranslationLogger($this->path_config->log . '/translate-%s-%s.pot');
        $logger->addLogWriter($writer);
    }

    private function configureAutoloaderAndResolver()
    {
        if ($this->autoloader !== null)
            return;

        // Construct the Wedeto autoloader and resolver
        $cache = new Cache("resolution");
        $this->autoloader = new Autoloader();
        $this->autoloader->setCache($cache);
        $this->resolver = new ResolveManager($cache);
        $this->resolver
            ->addResolverType('template', 'template', '.php')
            ->addResolverType('assets', 'assets')
            ->addResolverType('app', 'app')
            ->addResolverType('code', 'src')
            ->addResolverType('language', 'language')
            ->setResolver("app", new Router("router"));

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

    protected function setupLogging()
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
                        if (empty($logname) || strtoupper($logname) === "ROOT")
                            $logname = '';
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

        // Translation log setup
        $this->setupTranslateLog();

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
                $amgr = new \Wedeto\HTML\AssetManager($assets);

                $tpl = new \Wedeto\HTML\Template($res, $amgr, $req);
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
     * Handle an incoming web request: parse it, route it, execute the controller and
     * send the response back.
     */
    public function handleWebRequest()
    {
        $responder = new Responder($this->request);

        try
        {
            $dispatcher = $this->get('dispatcher');
            if ($this->dev)
            {
                $memlogger = MemLogWriter::getInstance();
                if ($memlogger)
                {
                    $loghook = new LogAttachHook($memlogger, $dispatcher);
                    Hook::subscribe(
                        "Wedeto.HTTP.Responder.Respond", 
                        $loghook,
                        Hook::RUN_LAST
                    );
                }
            }

            $response = $dispatcher->dispatch();
        }
        catch (Response $thrown_response)
        {
            $response = $thrown_response;
        }

        $responder->setResponse($response);
        $session_cookie = $this->request->session !== null ? $this->request->session->getCookie() : null;
        if ($session_cookie)
            $responder->addCookie($session_cookie);

        $responder->respond();
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
