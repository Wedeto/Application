<?php

use WASP\Template;
use WASP\System;
use WASP\Dictionary;
use WASP\Http\DataResponse;
use WASP\Http\Error as HttpError;

if (!System::config()->get('site', 'dev'))
    throw new HttpError(403, 'Forbidden', t('Developer mode is disabled'));

/**
 * LogOutput provides a way to show the logs using your browser
 */
class LogOutput
{
    public $request;

    public function index(Template $tpl) 
    {
        $tpl->setTemplate('dev/log');
        $tpl->setTitle(t('WASP Log Viewer'));
        $tpl->render();
    }

    public function getLog()
    {
        if (!System::config()->get('site', 'dev'))
            throw new HttpError(403, 'Forbidden', t('Developer mode is disabled'));

        $lines = 10;
        if ($this->request->get->has('count', Dictionary::TYPE_INT))
            $lines = $this->request->get->getInt('count');

        $lines = min(100, $lines);
        $lines = max(1, $lines);

        $path = WASP\System::path();
        $log = $path->log . '/wasp.log';
        $phplog = $path->log . '/error-php.log';

        $log_contents = shell_exec('cat ' . $log . ' | grep -v "dev\/log\/getLog" | tail -n ' . $lines);
        $phplog_contents = shell_exec('tail -n ' . $lines . ' ' . $phplog);

        $wasplog = explode("\n", $log_contents);
        $phplog = explode("\n", $phplog_contents);

        $data = new Dictionary(array(
            'phplog' => $phplog,
            'wasplog' => $wasplog
        ));
        throw new DataResponse($data);
    }
}

return new LogOutput();
