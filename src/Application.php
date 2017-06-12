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

use Wedeto\Util\Dictionary;
use Wedeto\Util\Cache;
use Wedeto\Util\ErrorInterceptor;

use Wedeto\Log\{Logger, LoggerFactory};
use Wedeto\Log\Writer\{FileWriter, MemLogWriter};

use Wedeto\Resolve\Autoloader;
use Wedeto\Resolve\Manager as ResolveManager;
use Wedeto\Resolve\Router;

use Wedeto\Application\Dispatch\Dispatcher;

use Wedeto\HTTP\Request;
use Wedeto\HTTP\Error as HTTPError;

use Wedeto\I18n\I18n;
use Wedeto\I18n\Translator\TranslationLogger;

if (!defined('WEDETO_TEST'))
    define('WEDETO_TEST', 0);

class Application
{
    private static $instance = null;

    protected $autoloader = null;
    protected $bootstrapped = false;
    protected $path_config;
    protected $config;
    protected $request;
    protected $resolver;
    protected $dispatcher;
    protected $i18n;
    protected $template;
    protected $module_manager;

    public static function setup(PathConfig $path, Dictionary $config)
    {
        //if (self::$instance !== null)
        //    throw new RuntimeException("Cannot initialize more than once");

        self::$instance = new Application($path, $config);

        // Attach the error handler - all PHP Errors should be thrown as Exceptions
        ErrorInterceptor::registerErrorHandler();
        return self::$instance;
    }

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
        self::$application = $application;
    }

    private function __construct(PathConfig $path_config, Dictionary $config)
    {
        $this->path_config = $path_config;
        $this->config = $config;
        $this->bootstrap();
    }

    public function bootstrap()
    {
        if ($this->bootstrapped)
            throw new RuntimeException("Cannot bootstrap more than once");

        // Set character set
        ini_set('default_charset', 'UTF-8');
        mb_internal_encoding('UTF-8');

        // Set up logging
        ini_set('log_errors', '1');

        $test = defined('WEDETO_TEST') && WEDETO_TEST === 1;

        if (PHP_SAPI === 'cli')
            ini_set('error_log', $this->path_config->log . '/error-php-cli' . $test . '.log');
        else
            ini_set('error_log', $this->path_config->log . '/error-php' . $test . '.log');

        // Set up the autoloader
        $this->configureAutoloaderAndResolver();

        // Set default permissions for files and directories
        $this->setCreatePermissions();

        // Make sure permissions are adequate
        try
        {
            $this->path_config->checkPaths();
        }
        catch (PermissionError $e)
        {
            return $this->showPermissionError($e);
        }

        // Autoloader requires manual logger setup to avoid depending on external files
        LoggerFactory::setLoggerFactory(new LoggerFactory());
        Autoloader::setLogger(LoggerFactory::getLogger(['class' => Autoloader::class]));

        // Set up root logger
        $root_logger = Logger::getLogger();
        $root_logger->setLevel(LogLevel::DEBUG);
        $logfile = $this->path_config->log . '/wedeto' . $test . '.log';
        $root_logger->addLogWriter(new FileWriter($logfile, LogLevel::INFO));

        //
        $this->setupTranslateLog();

        // Load the configuration file
        // Attach the dev logger when dev-mode is enabled
        if ($this->config->get('site', 'dev'))
        {
            $devlogger = new MemLogger(LogLevel::DEBUG);
            $root_logger->addLogHandler($devlogger);
        }

        // Log beginning of request handling
        if (isset($_SERVER['REQUEST_URI']))
        {
            Log\debug(
                "Wedeto.Application", 
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
        }


        // Save the cache if configured so
        Cache::setCachePath($this->path_config->cache);
        Cache::setHook($this->config->dget('cache', 'expiry', 60));

        // Find installed modules and initialize them
        Module\Manager::setup($this->resolver);

        // Prepare the HTTP Request Object
        $this->request = Request::createFromGlobals();

        // Do not run again
        $this->bootstrapped = true;
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

    public function get($parameter)
    {
        switch ($parameter)
        {
            case "config":
                return $this->config;
            case "pathConfig":
                return $this->path_config;
            case "request":
                return $this->request;
            case "resolver":
                return $this->resolver;
            case "moduleManager":
                if ($this->module_manager === null)
                    $this->module_manager = new Module\Manager($this->resolver);
                return $this->module_manager;
            case "i18n":
                if ($this->i18n === null)
                {
                    $this->i18n = new I18n;
                    $this->i18n->registerTextDomain('core', $this->path_config->root . '/language');
                }
                return $this->i18n;
            case "dispatcher":
                if ($this->dispatcher === null)
                {
                    $this->dispatcher = new Dispatcher($this->get('request'), $this->resolver, $this->config);
                    $this->dispatcher->configureSites($this->config);
                    $this->dispatcher->determineVirtualHost();
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
        $dev = $this->config->get('site', 'dev');

        if (PHP_SAPI !== "CLI")
        {
            http_response_code(500);
            header("Content-type: text/plain");
        }

        if ($dev)
        {
            $file = $e->path;
            echo "{$e->getMessage()}\n";
            echo "\n";
            echo WF::str($e, $html);
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

    public function handleWebRequest()
    {
        $rb = new Responder($this->request);

        if ($this->config->get('dev'))
        {
            $memlogger = MemLogger::getInstance();
            if ($memlogger)
            {
                $loghook = new MemLogHook($memlogger, $this);
                Hook::subscribe(
                    "Wedeto.HTTP.Responder.Respond", 
                    new MemLogHook($memlogger, $this),
                    Hook::RUN_LAST
                );
            }
        }
        $session_cookie = $this->request->session !== null ? $this->request->session->getCookie() : null;
        if ($session_cookie)
            $rb->addCookie($session_cookie);
        $rb->setResponse($e);
        $rb->respond();
    }
}
