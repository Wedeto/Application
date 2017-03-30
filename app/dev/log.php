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

use Wedeto\Template;
use Wedeto\System;
use Wedeto\Dictionary;
use Wedeto\HTTP\Response\DataResponse;
use Wedeto\HTTP\Response\Error as HTTPError;

if (!System::config()->get('site', 'dev'))
    throw new HTTPError(403, 'Forbidden', t('Developer mode is disabled'));

/**
 * LogOutput provides a way to show the logs using your browser
 */
class LogOutput
{
    public $request;

    public function index(Template $tpl) 
    {
        $tpl->setTemplate('dev/log');
        $tpl->setTitle(t('Wedeto Log Viewer'));
        $tpl->render();
    }

    public function getLog()
    {
        if (!System::config()->get('site', 'dev'))
            throw new HTTPError(403, 'Forbidden', t('Developer mode is disabled'));

        $lines = 10;
        if ($this->request->get->has('count', Dictionary::TYPE_INT))
            $lines = $this->request->get->getInt('count');

        $lines = min(100, $lines);
        $lines = max(1, $lines);

        $path = Wedeto\Platform\System::path();
        $log = $path->log . '/wedeto.log';
        $phplog = $path->log . '/error-php.log';

        $log_contents = shell_exec('cat ' . $log . ' | grep -v "dev\/log\/getLog" | tail -n ' . $lines);
        $phplog_contents = shell_exec('tail -n ' . $lines . ' ' . $phplog);

        $wedetolog = explode("\n", $log_contents);
        $phplog = explode("\n", $phplog_contents);

        $data = new Dictionary(array(
            'phplog' => $phplog,
            'wedetolog' => $wedetolog
        ));
        throw new DataResponse($data);
    }
}

return new LogOutput();
