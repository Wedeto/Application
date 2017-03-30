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

namespace Wedeto\HTTP;

use Wedeto\Util\Hook;
use Wedeto\Util\Date;
use Wedeto\Util\Functions as WF;

use Wedeto\Log\MemLogger;

/**
 * Log all output of the current script to memory, and attach a log to the end
 * of the response
 */
class MemLogHook implements ResponseHookInterface
{
    protected $memlog = null;
    protected $dispatcher = null;

    public function __construct(MemLogger $logger, Dispatcher $dispatcher)
    {
        $this->memlog = $logger;
        $this->dispatcher = $dispatcher;
    }

    public function getDispatcher()
    {
        return $this->dispatcher;
    }

    public function setDispatcher(Dispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
        return $this;
    }

    public function getMemLog()
    {
        return $this->memlog;
    }

    public function setMemLog(MemLogger $logger)
    {
        $this->memlog = $logger;
        return $this;
    }

    /**
     * Attach to the Response to add the log to the end of each HTML and
     * JSON/XML request Do note that this should only be used for development
     * as the log may expose sensitive information to the client.
     * 
     * @param array $params Expected to contain 'responder', the Responder object
     */
    public function __invoke(array $params)
    {
        $responder = $params['responder'];
        $request = $responder->getRequest();
        $response = $responder->getResponse();

        // Calculate elapsed time
        $now = Date::now();
        $start = $request->getStartTime();;
        $duration = round(Date::diff($now, $start), 3);

        // Add log
        $log = $this->memlog->getLog();
        if ($response instanceof DataResponse)
        {
            $response->getDictionary()->set('devlog', $log);
        }
        elseif ($response instanceof StringResponse)
        {
            $mime = $response->getMimeTypes();
            $buf = fopen('php://memory', 'rw');

            if (in_array('text/html', $mime))
            {
                fprintf($buf, "\n<!-- DEVELOPMENT LOG-->\n");
                fprintf($buf, "<!-- Executed in: %s s -->\n", $duration);
                fprintf($buf, "<!-- Route resolved: %s -->\n", $this->dispather->getRoute());
                fprintf($buf, "<!-- App executed: %s -->\n", $this->dispatcher->getApp());
                $width = strlen((string)count($this->log)) + 1;
                foreach ($log as $no => $line)
                    fprintf($buf, "<!-- %0" . $width . "d: %s -->\n", $no, htmlentities($line));

                fseek($buf, 0);
                $output = fread($buf, 100 * 1024);
                $response->append($output, 'text/html');
            }

            if (in_array('text/plain', $mime))
            {
                fprintf($buf, "\n// DEVELOPMENT LOG\n");
                fprintf($buf, "// Executed in: %s\n", $duration);
                fprintf($buf, "// Route resolved: %s\n", $this->dispatcher->getRoute());
                fprintf($buf, "// App executed: %s>\n", $this->dispatcher->getApp());
                foreach ($log as $no => $line)
                    fprintf($buf, "// %05d: %s\n", $no, $line);

                fseek($buf, 0);
                $output = fread($buf, 100 * 1024);
                $response->append($output, 'text/plain');
            }
        }
    }
}

