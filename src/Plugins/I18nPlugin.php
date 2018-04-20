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

use Wedeto\Log\Logger;

use Wedeto\I18n\I18n;
use Wedeto\I18n\I18nShortcut;
use Wedeto\I18n\Translator\TranslationLogger;

/**
 * Plugin that enables I18n setup for Wedeto
 */
class I18nPlugin implements WedetoPlugin
{
    private $app;

    public function initialize(Application $app)
    {
        $this->app = $app;
        $app->injector->registerFactory(I18n::class, new BasicFactory([$this, "createI18n"]));
    }

    /**
     * Factory method to create the I18n instance. Registered with Wedeto\DI to
     * make sure that new instances are created here.
     */
    public function createI18n(array $args)
    {
        $i18n = new I18n;
        I18nShortcut::setInstance($i18n);

        // Add all module paths to the I18n object
        $modules = $this->app->resolver->getResolver("language");
        foreach ($modules as $name => $path)
            $i18n->registerTextDomain($name, $path);

        // Set a language
        $site_language = $this->app->config->dget('site', 'default_language', 'en');
        $locale = $args['locale'] ?? $site_language;
        $i18n->setLocale($locale);

        $this->setupTranslateLog();

        return $i18n;
    }

    /**
     * Set up a logger that stores untranslated messages to a separate pot file.
     */
    private function setupTranslateLog()
    {
        $logger = Logger::getLogger('Wedeto.I18n.Translator.Translator');
        $writer = new TranslationLogger($this->app->pathConfig->log . '/translate-%s-%s.pot');
        $logger->addLogWriter($writer);
    }
}
