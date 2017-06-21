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

namespace Wedeto\Application\Dispatch;

use Throwable;
use DateTime;

use Wedeto\Util\LoggerAwareStaticTrait;

use Wedeto\Log\MemLogger;

use Wedeto\Resolve\Manager as ResolveManager;

use Wedeto\Util\Hook;
use Wedeto\Util\Dictionary;
use Wedeto\Util\Type;
use Wedeto\Util\Functions as WF;

use Wedeto\HTTP\Accept;
use Wedeto\HTTP\Request;
use Wedeto\HTTP\Responder;
use Wedeto\HTTP\Response\{Response, DataResponse, StringResponse, RedirectRequest};
use Wedeto\HTTP\Response\Error as HTTPError;
use Wedeto\HTTP\StatusCode;
use Wedeto\HTTP\URL;

use Wedeto\HTML\Template;
use Wedeto\HTML\AssetManager;

use Wedeto\IO\FileType;

use Wedeto\FileFormats\WriterFactory;

use Wedeto\Application\Application;

/**
 * Dispatcher dispatches a HTTP Request to the correct route.
 *
 * It will dispatch the request to the correct app by resolving the route in
 * the configured paths.
 */
class Dispatcher
{
    use LoggerAwareStaticTrait;

    /** The default language used for responses */
    private static $default_language = 'en';

    /** The site configuration */
    protected $config;

    /** The arguments after the selected route */
    protected $arguments;

    /** The configured sites */
    protected $sites = array();

    /** The selected VirtualHost for this request */
    protected $vhost = null;

    /** The file / asset resolver */
    protected $resolver = null;

    /** The template render engine */
    protected $template = null;

    /** The request being served */
    protected $request;

    /** The resolved route */
    protected $route;

    /** The resolved app */
    protected $app;

    /** The suffix of the resolved route - file extension */
    protected $suffix;

    /** The variables to pass to the app */
    protected $variables = [];

    /**
     * Create the dispatcher. 
     *
     * @param Request $request The request to dispatch
     * @param ResolveManager $resolver The resolve manager able to resolve assets, templates and routes
     * @param Dictionary $config The configuration used for setting up Site and VirtualHost instances
     */
    public function __construct(Request $request, ResolveManager $resolver, $config = [])
    {
        self::getLogger();

        if (!($config instanceof Dictionary))
            $config = new Dictionary($config);

        $this->setRequest($request);
        $this->setResolveManager($resolver);
        $this->setConfig($config);
        $this->getTemplate();
    }

    /**
     * Create a new dispatcher using an application object
     * @param Application $app The Application object from which to get the required variables
     * @return Dispatcher The dispatcher instance
     */
    public static function createFromApplication(Application $app)
    {
        $dispatch = new static($app->request, $app->resolver, $app->config);
        $dispatch->setApplication($app);
        return $dispatch;
    }

    /**
     * @return Dictionary The configuration used
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * Set the configuration dictionary used for config
     * @param Dictionary $config The configuration
     * @return Dispatcher Provides fluent interface
     */
    public function setConfig(Dictionary $config)
    {
        $this->config = $config;
        $this->configureSites();
        $this->setVariable('config', $config);
        return $this;
    }

    /**
     * Configure the sites based on the provided configuration
     *
     * @return Dispatcher Provides fluent interface
     */
    public function configureSites()
    {
        $this->setSites(Site::setupSites($this->config->getSection('site')));
        return $this;
    }

    /**
     * @return array The list of sites
     */
    public function getSites()
    {
        return $this->sites;
    }

    /**
     * Set the list of available sites
     *
     * @param array $sites The list of sites
     * @return Dispatcher Provides fluent interface
     */
    public function setSites(array $sites)
    {
        $this->sites = array();
        foreach ($sites as $site)
            $this->addSite($site);
        return $this;
    }

    /**
     * Add a Site to the dispatcher configuration.
     * @param Site $site The site to add
     * @return Dispatcher Provides fluent interface
     */
    public function addSite(Site $site)
    {
        $this->sites[] = $site;
        return $this;
    }

    /**
     * Set the VirtualHost for this request
     *
     * @param VirtualHost $vhost The VirtualHost matching the request
     * @return Dispatcher Provides fluent interface
     */
    public function setVirtualHost(VirtualHost $vhost)
    {
        $this->vhost = $vhost;
        $this->setVariable('vhost', $vhost);
        return $this;
    }

    /**
     * @return VirtualHost the virtual host matching the request
     */
    public function getVirtualHost()
    {
        if ($this->vhost === null)
            $this->determineVirtualHost();
        return $this->vhost;
    }

    /**
     * @return Dictionary the arguments passed to the script
     */
    public function getArguments()
    {
        return $this->arguments;
    }

    /**
     * @return Wedeto\HTTP\Request the request being served
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * Set the request being handled
     * @param Request $request The request instance
     * @return Dispatcher Provides fluent interface
     */
    public function setRequest(Request $request)
    {
        $this->request = $request;
        $this->app = null;
        $this->vhost = null;
        $this->route = null;
        $this->setVariable('request', $request);
        return $this;
    }

    /**
     * Set the value for a variable
     * @param string $name The name of the variable
     * @param object $value The value to reference to
     * @return Dispatch Provides fluent interface
     */
    public function setVariable(string $name, $value)
    {
        $this->variables[$name] = $value;
        return $this;
    }

    /** 
     * @return mixed The value for the variable
     */
    public function getVariable(string $name)
    {
        return $this->variables[$name];
    }

    /**
     * @return Wedeto\Autoload\Resolver The app, template and asset resolver
     */
    public function getResolver()
    {
        return $this->resolver;
    }

    /**
     * Set the resolver used for resolving apps
     * @param Wedeto\Resolve\Resolver The resolver
     * @return Wedeto\HTTP\Request Provides fluent interface
     */
    public function setResolveManager(ResolveManager $resolver)
    {
        $this->resolver = $resolver;
        $this->setVariable('resolver', $resolver);
        return $this;
    }

    /**
     * @return Template The template instance
     */
    public function getTemplate()
    {
        if ($this->template === null)
        {
            $asset_manager = new AssetManager($this->resolver->getResolver('assets'));
            $template = new Template($this->resolver->getResolver('template'), $asset_manager);
            Hook::subscribe('Wedeto.HTTP.Responder.Respond', array($asset_manager, 'executeHook'));

            $vhost = $this->getVirtualHost();
            $template
                ->setAssetManager($asset_manager)
                ->setURLPrefix($vhost->getHost()->path)
                ->assign('request', $this->request)
                ->assign('config', $this->config)
                ->assign('dev', $this->config->dget('site', 'dev', true));

            $this->setTemplate($template);
        }

        return $this->template;
    }

    /**
     * Set the template instance to have apps use.
     * @param Template $template The template to use
     * @return Dispatcher Provides fluent interface
     */
    public function setTemplate(Template $template)
    {
        $this->template = $template;
        $this->setVariable('template', $template);
        $this->setVariable('tpl', $template);
        return $this;
    }

    /**
     * @return string The path to the app to execute
     */
    public function getApp()
    {
        return $this->app;
    }

    /**
     * @return Application The application for which the request is dispatched. Null if none available
     */
    public function getApplication()
    {
        if (!array_key_exists('app', $this->variables['app']))
        {
            $app = Application::hasInstance() ? Application::getInstance() : null;
            $this->variables['app'] = $app;
        }
        return $this->variables['app'];
    }

    /**
     * Set the application instance
     * @param Application $app The Application instance
     * @return Dispatcher Provides fluent interface
     */
    public function setApplication(Application $app)
    {
        $this
            ->setVariable('app', $app)
            ->setVariable('path_config', $app->pathConfig)
            ->setVariable('i18n', $app->i18n)
            ->setVariable('db', $app->db);
        return $this;
    }


    /**
     * @return string The resolved route
     */
    public function getRoute()
    {
        return $this->route;
    }

    /**
     * Run the selected application
     * 
     * @return Response The response of the executed script
     */
    public function dispatch()
    {
        $response = null;
        try
        {
            $this->resolveApp();

            $this->request->startSession($this->vhost->getHost(), $this->config);

            if ($this->route === null)
                throw new HTTPError(404, 'Could not resolve ' . $this->url);

            $app = new AppRunner($this->app, $this->arguments);
            $app->setVariables($this->variables);
            $app->setVariable('dispatcher', $this);

            $app->execute();
        }
        catch (Throwable $e)
        {
            if (!($e instanceof Response))
                $e = new HTTPError(500, "Exception of type " . get_class($e) . " thrown: " . $e->getMessage(), null, $e);

            if ($e instanceof HTTPError)
                $this->prepareErrorResponse($e);

            $response = $e;
        }
        return $response;
    }

    /** 
     * Prepare error response: to give a better error output,
     * a template must be executed or it must be wrapped in a 
     * DataResponse to that it will be JSON or XML-formatted.
     * 
     * @param HTTPError $error The error to prepare
     */
    protected function prepareErrorResponse(HTTPError $error)
    {
        $writers = WriterFactory::getAvailableWriters();
        $mime_types = array_keys($writers);

        array_unshift($mime_types, 'text/plain');
        array_unshift($mime_types, 'text/html');
        $preferred_type = $this->request->accept->getBestResponseType($mime_types);

        if ($preferred_type === 'text/html')
        {
            $ex = $error->getPrevious() ?: $error;
            $template = $this->getTemplate();
            $template->assign('exception', $ex);

            $template->setExceptionTemplate($ex);
            
            $response = $template->renderReturn();

            if ($response instanceof HTTPError)
                $error->setResponse(new StringResponse(WF::str($response), "text/plain"));
            else
                $error->setResponse($response);
        }
        elseif ($preferred_type !== 'text/plain')
        {
            $ex = $error->getPrevious() ?: $error;
            $status = $error->getStatusCode();
            $status_msg = isset(StatusCode::$CODES[$status]) ? StatusCode::$CODES[$status] : "Internal Server Error";
            $exception_str = WF::str($ex);
            $exception_list = explode("\n", $exception_str);
            $data = array(
                'message' => $ex->getMessage(),
                'status_code' => $status,
                'exception' => $exception_list,
                'status' => $status_msg
            );

            $dict = new Dictionary($data);
            $error->setResponse(new DataResponse($dict));
        }
        // text/plain is handled by Error directly
    }

    /**
     * Find out which VirtualHost was targeted and redirect if configuration
     * requests so.
     *
     * @return Dispatcher Provides fluent interface
     * @throws Throwable Various exceptions depending on the configuration - 
     *                   Error(404) when the configuration says to prohibit use of
     *                   unknown hosts, RedirectRequest when a redirect to a different
     *                   host is requested, RuntimeException when unexpected things happen.
     */
    public function determineVirtualHost()
    {
        // Determine the proper VirtualHost
        $cfg = $this->config->getSection('site');
        $vhost = self::findVirtualHost($this->request->webroot, $this->sites);
        if ($vhost === null)
        {
            $result = $this->handleUnknownHost($this->request->webroot, $this->sites, $cfg);
            
            // Handle according to the outcome
            if ($result === null)
                throw new HTTPError(404, "Not found: " . $this->url);

            if ($result instanceof URL)
                throw new RedirectRequest($result, 301);

            if ($result instanceof VirtualHost)
            {
                $vhost = $result;
                $site = $vhost->getSite();
                if (isset($this->sites[$site->getName()]))
                    $this->sites[$site->getName()] = $site;
            }
            else
                throw \RuntimeException("Unexpected response from handleUnknownWebsite");
        }
        else
        {
            // Check if the VirtualHost we matched wants to redirect somewhere else
            $target = $vhost->getRedirect($this->request->url);
            if ($target)
                throw new RedirectRequest($target, 301);
            
            // The virtual host may prescribe a language
            if (isset($this->variables['i18n']))
            {
                $locales = $vhost->getLocales();
                $session = $this->request->session;
                $locale = null;
                if ($session->has('locale', Type::STRING))
                {
                    $locval = $session['locale'];
                    foreach ($locales as $supported_locale)
                    {
                        if ($locval === $supported_locale)
                        {
                            $locale = $locval;
                            break;
                        }
                    }
                }

                if ($locale === null && !empty($locales))
                    $locale = reset($locales);

                if (!empty($locale))
                {
                    self::$logger->debug('Setting locale to {0}', [$locale]);
                    $this->variables['i18n']->setLocale($locale);
                }
            }


        }
        $this->setVirtualHost($vhost);
        return $this;
    }

    /** 
     * Resolve the app to run based on the incoming URL
     */
    public function resolveApp()
    {
        // Determine the correct vhost first
        $this->determineVirtualHost();

        // Resolve the application to start
        $path = $this->vhost->getPath($this->request->url);

        $resolved = $this->resolver->resolve("app", $path);

        if ($resolved !== null)
        {
            if ($resolved['ext'])
            {
                $mime = new FileType($resolved['ext'], "");
                if (!empty($mime))
                {
                    $str = $mime->getMimeType() . ";q=1.5," . (string)$this->request->accept;
                    $this->request->setAccept(new Accept($str));
                }
                $this->suffix = $resolved['ext'];
            }
            $this->route = $resolved['route'];
            $this->app = $resolved['path'];
            $this->arguments = new Dictionary($resolved['remainder']);
        }
        else
        {
            $this->route = null;
            $this->app = null;
            $this->arguments = new Dictionary();
        }
    }

    /**
     * Find the VirtualHost matching the provided URL.
     * @param URL $url The URL to match
     * @param array $sites A list of Site objects from which the correct
     *                     VirtualHost should be extracted.
     * @return VirtualHost The correct VirtualHost. Null if not found.
     */
    public static function findVirtualHost(URL $url, array $sites)
    {
        foreach ($sites as $site)
        {
            $vhost = $site->match($url);
            if ($vhost !== null)
                return $vhost;
        }
        return null;
    }

    /**
     * Determine what to do when a request was made to an unknown host.
     * 
     * The default configuration is IGNORE, which means that a new vhost will be
     * generated on the fly and attached to the site of the closest matching VirtualHost.
     * If no site is configured either, a new Site named 'defaul't is created and the new
     * VirtualHost is attached to that site. This makes configuration non-required for 
     * simple sites with one site and one hostname.
     * 
     * @param URL $url The URL that was requested
     * @param array $sites The configured sites
     * @param Dictionary $cfg The configuration to get the policy from
     * @return mixed One of:
     *               * null: if the policy is to error out on unknown hosts
     *               * URI: if the policy is to redirect to the closest matching host
     *               * VirtualHost: if the policy is to ignore / accept unknown hosts
     */
    public static function handleUnknownHost(URL $webroot, array $sites, Dictionary $cfg)
    {
        // Determine behaviour on unknown host
        $on_unknown = strtoupper($cfg->dget('unknown_host_policy', "IGNORE"));
        $best_matching = self::findBestMatching($webroot, $sites);

        if ($on_unknown === "ERROR" || ($best_matching === null && $on_unknown === "REDIRECT"))
            return null;

        if ($on_unknown === "REDIRECT")
        {
            $redir = $best_matching->URL($webroot->path);
            return $redir;
        }

        // Generate a proper VirtualHost on the fly
        $url = new URL($webroot);
        $url->fragment = null;
        $url->query = null;
        $vhost = new VirtualHost($url, self::$default_language);

        // Add the new virtualhost to a site.
        if ($best_matching === null)
        {
            // If no site has been defined, create a new one
            $site = new Site();
            $site->addVirtualHost($vhost);
        }
        else
            $best_matching->getSite()->addVirtualHost($vhost);

        return $vhost;
    }

    /**
     * Find the best matching VirtualHost. In case the URL used does not
     * match any defined VirtualHost, this function will find the VirtualHost
     * that matches the URL as close as possible, in an attempt to guess at
     * which information the visitor is interested.
     *
     * @param URL $url The URL that was requested
     * @param array $sites The configured sites
     * @return VirtualHost The best matching VirtualHost in text similarity.
     */ 
    public static function findBestMatching(URL $url, array $sites)
    {
        $vhosts = array();
        foreach ($sites as $site)
            foreach ($site->getVirtualHosts() as $vhost)
                $vhosts[] = $vhost;

        // Remove query and fragments from the URL in use
        $my_url = new URL($url);
        $my_url->set('query', null)->set('fragment', null)->toString();

        // Match the visited URL with all vhosts and calcualte their textual similarity
        $best_percentage = 0;
        $best_idx = null;
        foreach ($vhosts as $idx => $vhost)
        {
            $host = $vhost->getHost()->toString();
            similar_text($my_url, $host, $percentage);
            if ($best_idx === null || $percentage > $best_percentage)
            {
                $best_idx = $idx;
                $best_percentage = $percentage;
            }
        }
        
        // Return the best match, or null if none was found.
        if ($best_idx === null)
            return null;
        return $vhosts[$best_idx];
    }

    /**
     * Return a value
     * @param string $key The name of the variable
     * @return mixed The value, or null if not existent
     */
    public function __get(string $key)
    {
        return property_exists($this, $key) ? $this->$key : null;
    }
}
