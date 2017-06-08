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
use Wedeto\Util\Type;
use Wedeto\Util\Functions as WF;

/**
 * A Flash Message is a message that is generated in the code, informing the
 * user of some event. The main use case is to confirm certain actions to the
 * user in a POST request, while avoiding repeating the action due to a refresh - 
 * the user can and should be redirected after the modification.
 *
 * In the next page request, the Flash Message will be presented to the user.
 * Flash Messages are stored in the session data for the current user.
 */
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

    protected $msg;
    protected $type;
    protected $date;

    protected static $storage = null;

    /**
     * Create and store a new flash message.
     *
     * @param string|array msg The message. If it is a string, a new flash message will be generated.
     *                         If this is an array, an existing message is reconstructed from the storage.
     */
    public function __construct($msg, $type = FlashMessage::INFO)
    {
        if (WF::is_array_like($msg))
        {
            // Construct a message from the storage
            $this->msg = $msg[0];
            $this->type = $msg[1];
            $this->date = new \DateTime("@" . $msg[2]);
            return;
        }

        $storage = self::getStorage();
        if ($storage === null)
            throw new \RuntimeException("No storage available - cannot store Flash Message");

        $this->msg = $msg;
        $this->type = $type;
        $this->date = new \DateTime();

        $storage[self::$KEY]->push(array($this->msg, $this->type, $this->date->getTimestamp()));
    }

    /**
     * @return string The message
     */
    public function getMessage()
    {
        return $this->msg;
    }

    /**
     * @return DateTime The moment when the message was generated
     */
    public function getDate()
    {
        return $this->date;
    }

    /**
     * @param int Get the enum value of the message type - one of the constants
     * FlashMessage::SUCCESS, INFO, WARNING, ERROR
     */
    public function getType()
    {
        return $this->type;
    }

    /** 
     * @param string Get the type of message: SUCCESS, INFO, WARNING, ERROR
     */
    public function getTypeName()
    {
        return self::$types[$this->type];
    }

    /**
     * Set the storage dictionary. Should be a a Session, but
     * can also be a different object, useful for testing.
     * @param Dictionary $storage Where to store and retrieve flash messages
     */
    public static function setStorage(Dictionary $storage = null)
    {
        self::$storage = $storage;
    }

    /**
     * @return bool True when another flash message is available, false when
     * there isn't
     */
    public static function hasNext()
    {
        return self::count() > 0;
    }

    /**
     * @return Dictionary The storage used for storing and retrieveing flash messages.
     */
    public static function getStorage()
    {
        if (self::$storage === null)
            return null;

        if (!self::$storage->has(self::$KEY, Type::ARRAY))
            self::$storage->set(self::$KEY, []);

        return self::$storage;
    }

    /**
     * @return FlashMessage Move the iterator to the next flash message and
     * return it.
     */
    public static function next()
    {
        $dict = self::getStorage();
        if ($dict === null || count($dict[self::$KEY]) === 0)
            return;
        $next = $dict[self::$KEY]->shift();
        return new FlashMessage($next);
    }

    /**
     * @return int The number of available flash messages
     */
    public static function count()
    {
        $storage = self::getStorage();
        if ($storage === null)
            return 0;

        return count($storage[self::$KEY]);
    }
}
