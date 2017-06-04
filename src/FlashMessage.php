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

use Wedeto\Util\Dictionary;
use Wedeto\Util\Functions as WF;

class FlashMessage
{
    const SUCCESS = 1;
    const INFO = 2;
    const WARNING = 3;
    const WARN = 3;
    const ERROR = 4;

    private static $KEY = "WEDETO_FM";

    public static $types = array(
        self::ERROR => "ERROR",
        self::WARN => "WARNING",
        self::INFO => "INFO",
        self::SUCCESS => "SUCCESS"
    );

    private $msg;
    private $type;
    private $date;

    public function __construct($msg, $type = FlashMessage::INFO)
    {
        if (WF::is_array_like($msg))
        {
            $this->msg = $msg[0];
            $this->type = $msg[1];
            $this->date = new \DateTime("@" . $msg[2]);
            return;
        }

        $storage = self::getStorage();
        if ($storage === null)
            throw new \RuntimeException("No session available - cannot store Flash Message");

        $this->msg = $msg;
        $this->type = $type;
        $this->date = new \DateTime();

        $storage->push(array($this->msg, $this->type, $this->date->getTimestamp()));
    }

    public function getMessage()
    {
        return $this->msg;
    }

    public function getDate()
    {
        return $this->date;
    }

    public function getType()
    {
        return $this->type;
    }

    public function getTypeName()
    {
        return self::$types[$this->type];
    }

    public static function hasNext()
    {
        return self::count() > 0;
    }

    private static function getStorage()
    {
        $session = System::request()->session;
        if ($session === null)
            return null;

        if (!$session->has(self::$KEY, Dictionary::TYPE_ARRAY))
            $session->set(self::$KEY, array());

        return $session->get(self::$KEY);
    }

    public static function next()
    {
        $dict = self::getStorage();
        if ($dict === null || count($dict) === 0)
            return;

        $next = $dict->shift();
        return new FlashMessage($next);
    }

    public static function count()
    {
        $storage = self::getStorage();
        if ($storage === null)
            return 0;

        return count($storage);
    }
}
