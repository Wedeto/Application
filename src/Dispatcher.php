<?php
/*
This is part of WASP, the Web Application Software Platform.
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

namespace WASP\Platform;

use Throwable;
use DateTime;

use WASP\Util\LoggerAwareStaticTrait;

use WASP\Log\MemLogger;

use WASP\Resolve\Resolver;

use WASP\Util\Hook;
use WASP\Util\Dictionary;
use WASP\Util\Functions as WF;

use WASP\HTTP\Request;
use WASP\HTTP\Responder;
use WASP\HTTP\Response\{Response, DataResponse, StringResponse};
use WASP\HTTP\Response\Error as HTTPError;
use WASP\HTTP\StatusCode;
use WASP\HTTP\URL;

use WASP\IO\MimeTypes;

use WASP\FileFormats\WriterFactory;

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
    protected $url_args;

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

    public function __construct(Request $request, Resolver $resolver, Dictionary $config)
    {
        $this->request = $request;
        $this->resolver = $resolver;
        $this->setConfig($config);
    }

    public function getConfig()
    {
        return $this->config;
    }

    public function setConfig(Dictionary $config)
    {
        $this->config = $config;
        $this->configureSites();
        return $this;
    }

    public function configureSites()
    {
        $this->setSites(Site::setupSites($this->config->getSection('site')));
        return $this;
    }

    public function getSites()
    {
        return $this->sites;
    }

    public function setSites(array $sites)
    {
        $this->sites = array();
        foreach ($sites as $site)
            $this->addSite($site);
        return $this;
    }

    public function addSite(Site $site)
    {
        $this->sites[] = $site;
        return $this;
    }

    public function setVirtualHost(VirtualHost $vhost)
    {
        $this->vhost = $vhost;
        return $this;
    }

    public function getVirtualHost()
    {
        if ($this->vhost === null)
            $this->determineVirtualHost();
        return $this->vhost;
    }

    public function getURLArgs()
    {
        return $this->url_args;
    }

    public function setURLArgs(Dictionary $url_args)
    {
        $this->url_args = $url_args;
        return $this;
    }

    /**
     * @return WASP\HTTP\Request the request being served
     */
    public function getRequest()
    {
        return $this->request;
    }

    public function setRequest(Request $request)
    {
        $this->request = $request;
        $this->app = null;
        $this->vhost = null;
        $this->route = null;
        return $this;
    }

    /**
     * @return WASP\Autoload\Resolver The app, template and asset resolver
     */
    public function getResolver()
    {
        return $this->resolver;
    }

    /**
     * Set the resolver used for resolving apps
     * @param WASP\Resolve\Resolver The resolver
     * @return WASP\HTTP\Request Provides fluent interface
     */
    public function setResolver(Resolver $resolver)
    {
        $this->resolver = $resolver;
        return $this;
    }

    public function getTemplate()
    {
        if ($this->template === null)
        {
            echo "-- aTTACHING HOOK FOR ASSET MANAGER\n";
            $this->template = new Template($this->resolver);
            $asset_manager = new AssetManager($this->vhost, $this->resolver);
            Hook::subscribe('WASP.HTTP.Responder.Respond', array($asset_manager, 'executeHook'));
            echo "-- aTTACHED HOOK FOR ASSET MANAGER\n";
            $this->template
                ->setAssetManager($asset_manager)
                ->assign('request', $this->request)
                ->assign('config', $this->config)
                ->assign('dev', true);//$this->config->dget('site', 'dev'));
        }

        return $this->template;
    }

    public function setTemplate(Template $template)
    {
        $this->template = $template;
    }

    public function getApp()
    {
        return $this->app;
    }

    public function getRoute()
    {
        return $this->route;
    }

    /**
     * Run the selected application
     */
    public function dispatch()
    {
        try
        {
            $this->resolveApp();
            $this->request->startSession($this->vhost->getHost(), $this->config);

            if ($this->route === null)
                throw new HTTPError(404, 'Could not resolve ' . $this->url);

            $app = new AppRunner($this, $this->app);
            $app->execute();
        }
        catch (Throwable $e)
        {
            if (!($e instanceof Response))
                $e = new HTTPError(500, "Exception thrown", null, $e);

            if ($e instanceof HTTPError)
                $this->prepareErrorResponse($e);

            $rb = new Responder($this->request);

            if ($this->config->get('dev'))
            {
                $memlogger = MemLogger::getInstance();
                if ($memlogger)
                {
                    $loghook = new MemLogHook($memlogger, $this);
                    Hook::subscribe(
                        "WASP.HTTP.Responder.Respond", 
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
        $preferred_type = $this->request->getBestResponseType($mime_types);

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
            $status = $error->getStatusCode();
            $status_msg = isset(StatusCode::$CODES[$status]) ? StatusCode::$CODES[$status] : "Internal Server Error";
            $exception_str = WF::str($this);
            $exception_list = explode("\n", $exception_str);
            $data = array(
                'message' => $this->getMessage(),
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
                throw new Error(404, "Not found: " . $this->url);

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
        }
        $this->vhost = $vhost;
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

        $resolved = $this->resolver->app($path);

        if ($resolved !== null)
        {
            if ($resolved['ext'])
            {
                $mime = MimeTypes::getMimeFromExtension($resolved['ext']);
                if (!empty($mime))
                    $this->accept[$mime] = 1.5;
                $this->suffix = $resolved['ext'];
            }
            $this->route = $resolved['route'];
            $this->app = $resolved['path'];
            $this->url_args = new Dictionary($resolved['remainder']);
        }
        else
        {
            $this->route = null;
            $this->app = null;
            $this->url_args = new Dictionary();
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
}
