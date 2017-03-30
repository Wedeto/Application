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

use Locale;

use Wedeto\HTTP\URL;
use Wedeto\Util\Dictionary;

class Site
{
    private $vhosts = array();
    private $locales = array();
    private $name = "default";

    public function __construct()
    {}

    public function setName(string $name)
    {
        $this->name = $name;
    }

    public function getName()
    {
        return $this->name;
    }

    public function addVirtualHost(VirtualHost $host)
    {
        $host->setSite($this);
        $this->vhosts[] = $host;
        foreach ($host->getLocales() as $locale)
            $this->locales[$locale] = true;
    }

    public function getVirtualHosts()
    {
        return $this->vhosts;
    }

    public function getLocales()
    {
        return array_keys($this->locales);
    }

    public function match($webroot)
    {
        $url = new URL($webroot);
        foreach ($this->vhosts as $vhost)
        {
            if ($vhost->match($url))
                return $vhost;
        }
        return null;
    }

    public function checkRedirect($url)
    {
        $url = new URL($url);
        foreach ($this->vhosts as $vhost)
        {
            if (!$vhost->match($url))
                continue;

            $redirect = $vhost->getRedirect($url);
            if ($redirect)
                return $redirect;

            $host = $vhost->getHost();
            if ($host->scheme !== $url->scheme)
            {
                $url->scheme = $host->scheme;
                return $url;
            }
        }
        return false;
    }

    public function URL($path, $locale = null)
    {
        foreach ($this->vhosts as $vhost)
        {
            if ($vhost->matchLocale($locale))
                return $vhost->URL($path);
        }
        return null;
    }

    /**
     * Set up the Site / VirtualHost structure based on the provided
     * configuration.
     *
     * A Site is a collection of VirtualHosts that each provide a localized version
     * of the same content. The VirtualHost in use determines the locale set in Wedeto.
     * 
     * Wedeto can serve multiple sites that contain different content. In absence of
     * multi-site, multi-vhost information, a single site with a single virtual
     * host is set up. 
     *
     * Vhosts can be set up to redirect to different addresses, or to define
     * the language in use.
     *
     * A very basic structure is defined as follows:
     *
     * [site]
     * url = "https://www.example.com"
     * 
     * This will result in one single vhost for one site. A redirect to www can
     * be accomplished by using:
     *
     * [site]
     * url[0] = "https://www.example.com"
     * url[1] = "https://example.com"
     * redirect[1] = "http://www.example.com"
     *
     * This will result in a single site with two vhosts, where one redirects to the other.
     * 
     * A multi-site system with language information could be:
     *
     * [site]
     * url[0] = "https://www.example.com"
     * site[0] = "default"
     * url[1] = "https://example.com"
     * site[1] = "default"
     * redirect[1] = "https://www.example.com"
     *
     * url[2] = "https:://www.foobar.com"
     * site[2] = "foobar"
     * language[2] = "en"
     * url[3] = "https://www.foobar.de"
     * site[3] = "foobar"
     * language[3] = "de"
     * 
     * This will result in two sites, default and foobar, each with two vhosts.
     * For the default vhost, these are a www. and a non-www. version. The non-www version
     * will redirect to the www. version.
     *
     * For foobar, there is a English and a German site, identified by
     * different domains, foobar.com and foobar.de.
     */
    public static function setupSites(Dictionary $config)
    {
        $urls = $config->getSection('url'); 
        $languages = $config->getSection('language');
        $sitenames = $config->getSection('site');
        $redirects = $config->getSection('redirect');
        $default_language = $config->get('default_language');
        $sites = array();

        foreach ($urls as $host_idx => $url)
        {
            $lang = isset($languages[$host_idx]) ? $languages[$host_idx] : $default_language;
            $site = isset($sitenames[$host_idx]) ? $sitenames[$host_idx] : "default";
            $redirect = isset($redirects[$host_idx]) ? $redirects[$host_idx] : null;

            if (!isset($sites[$site]))
            {
                $sites[$site] = new Site;
                $sites[$site]->setName($site);
            }

            $vhost = new VirtualHost($url, $lang);
            if ($redirect)
                $vhost->setRedirect($redirect);

            $sites[$site]->addVirtualHost($vhost);
        }

        return $sites;
    }
}
