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

namespace Wedeto\Platform;

use Wedeto\Util\Functions as WF;
use Wedeto\HTTP\URL;

/**
 * A VirtualHost is one site location managed by this Wedeto setup.
 * It can be different domain names but can also be several paths
 * on the same host.
 *
 * Each site can have multiple VirtualHosts that serve the same content,
 * but with different locales.
 */
class VirtualHost
{
    /** The base URL of this virtualhost */
    protected $url;

    /** The site this virtual host belongs to */
    protected $site = null;
    
    /** A redirect target if this virtual host should redirect to somewhere else */
    protected $redirect;

    /** The locales provided by this virtual host */
    protected $locales;

    /**
     * Set up a VirtualHost using a hostname and one or more languages
     * Languages can also be null to support any locale.
     * @param string $hostname The hostname to construct
     * @param mixed $locale null, a locale or an array of locales
     */
    public function __construct($hostname, $locale)
    {
        $this->url = new URL($hostname);
        $this->locales = array();
        $this->setLocale($locale);
    }

    /**
     * Set the site where this virtual host belongs to
     * @param Wedeto\Platform\Site $site The site
     * @return Wedeto\Platform\VirtualHost Provides fluent interface
     */
    public function setSite(Site $site)
    {
        $this->site = $site;
    }

    /**
     * @return Wedeto\Platform\Site The site this VirtualHost belongs to
     */
    public function getSite()
    {
        return $this->site;
    }

    /**
     * Check if this VirtualHost matches the locale. A VirtualHost that is
     * a redirect will never match.
     *
     * @param string $locale The locale to match
     * @return boolean True if it matches, false if not
     */
    public function matchLocale($locale)
    {
        if (!empty($this->redirect))
            return false;
            
        if ($locale === null)
            return true;

        return in_array($locale, $this->locales, true);
    }

    /** 
     * @return array The locales supported by this VirtualHost
     */
    public function getLocales()
    {
        return $this->locales;
    }

    /** 
     * Set the locales supported by this VirtualHost.
     * @param mixed $locale Can be one locale or an array of locales
     * @return Wedeto\Platform\VirtualHost Provides fluent interface
     */
    public function setLocale($locale)
    {
        $locale = WF::cast_array($locale);
        foreach ($locale as $l)
            $this->locales[] = \Locale::canonicalize($l);

        // Remove duplicate locales
        $this->locales = array_unique($this->locales);
        return $this;
    }

    /** 
     * Configure this VirtualHost to be a redirect to another location
     * @param mixed $hostname A string or a URL object where to redirect to. Can be empty to disable redirecting
     * @return Wedeto\Platform\VirtualHost Provides fluent interface
     */
    public function setRedirect($hostname)
    {
        if (!empty($hostname))
        {
            $this->redirect = new URL($hostname); 
            $this->redirect->set('path', rtrim($this->redirect->path, '/'));
        }
        else
            $this->redirect = false;
        return $this;
    }

    /**
     * Return a URL on the current VirtualHost with the path appended.
     * @param string $path The path to resolve
     * @param mixed $current_url The current URL. If this is provided and the
     *                           host and scheme match, they are omitted in the result.
     * @return Wedeto\HTTP\URL The resolved URL
     */
    public function URL($path = '', $current_url = null)
    {
        $url = new URL($this->url);
        $path = ltrim($path, '/');
        $url->set('path', $url->path . $path);

        if ($current_url instanceof URL)
        {
            if ($url->host === $current_url->host && $url->scheme === $current_url->scheme && $url->port === $current_url->port)
            {
                $url->host = null;
                $url->scheme = null;
            }
        }
        return $url;
    }

    /**
     * @return Wedeto\HTTP\URL The URL of this VirtualHost
     */
    public function getHost()
    {
        return $this->url;
    }

    /**
     * Extract the path of the provided URL that is below the webroot if this VirtualHost
     *
     * @param string $url The URL to get the path from
     * @return string The path relative to this VirtualHost
     */
    public function getPath($url)
    {
        $url = new URL($url);
        $to_replace = $this->url->path;
        $path = $url->path;

        if (strpos($path, $to_replace) === 0)
            $path = substr($path, strlen($to_replace));

        $path = '/' . $path;
        return $path; 
    }

    /**
     * @return bool If the VirtualHost uses https
     */
    public function isSecure()
    {
        return $this->url->scheme === "https";
    }

    /**
     * @return bool If the VirtualHost is prefixed with www
     */
    public function hasWWW()
    {
        return strtolower(substr($this->url->host, 0, 4)) === "www.";
    }

    /**
     * @param string $webroot The current webroot
     * @return bool If the VirtualHost matches the provided URL
     */
    public function match($webroot)
    {
        $url = new URL($webroot);
        if ($url->host !== $this->url->host || $url->scheme !== $this->url->scheme)
            return false;

        $path = $this->url->path;
        $wrpath = $url->path;
        return substr($wrpath, 0, strlen($path)) === $path;
    }

    /**
     * @param string $url The visited URL
     * @return Wedeto\HTTP\URL The URL to redirect to, or null if no redirect is needed
     */
    public function getRedirect($url)
    {
        if (empty($this->redirect))
            return null;

        $url = new URL($url);
        $path = $this->getPath($url);

        $target = new URL($this->redirect);
        $target->setPath($path);

        return $target;
    }
}

