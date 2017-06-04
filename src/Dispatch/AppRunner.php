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

use Wedeto\DB\DB;
use Wedeto\DB\DAO;
use Wedeto\Util\Dictionary;
use Wedeto\Util\LoggerAwareStaticTrait;
use Wedeto\Log\Logger;
use Wedeto\HTTP\Request;
use Wedeto\HTTP\Response\Response;
use Wedeto\HTTP\Error as HTTPError;
use Wedeto\HTTP\StatusCode;

use ReflectionMethod;
use Throwable;

/**
 * Run / include an application and make sure it produces response.
 * All output if buffered and will be logged to the logger at the end, to
 * avoid sending garbage to the client.
 */
class AppRunner
{
    use LoggerAwareStaticTrait;

    /** The request being handled */
    private $request;

    /** The dispatcher instance */
    private $dispatcher;

    /** The application to execute */
    private $app;

    /** The initial output buffer level */
    private $output_buffer_level;

    /**
     * Create the AppRunner with the request and the app path
     * @param Wedeto\HTTP\Request $request The request being answered
     * @param string $app The path to the appplication to run
     */
    public function __construct(Dispatcher $dispatcher, string $app)
    {
        self::getLogger();
        $this->request = $dispatcher->getRequest();
        $this->dispatcher = $dispatcher;
        $this->app = $app;
    }

    private function logScriptOutput()
    {
        $output_buffers = array();
        $ob_cnt = 0;
        while (ob_get_level() > $this->output_buffer_level)
        {
            $output = trim(ob_get_contents());
            ++$ob_cnt;
            ob_end_clean();
            if (!empty($output))
            {
                $lines = explode("\n", $output);
                foreach ($lines as $n => $line)
                    self::$logger->debug("Script output: {0}/{1}: {2}", [$ob_cnt, $n + 1, $line]);
            }
        }
    }


    /**
     * Run the app and make produce a response.
     * @throws Wedeto\HTTP\Response
     */
    public function execute()
    {
        $tr = System::translate();

        try
        {
            // No output should be produced by apps directly, so buffer
            // everything, and log afterwards
            $this->output_buffer_level = ob_get_level();

            ob_start();
            $response = $this->doExecute();

            if (is_object($response) && !($response instanceof Response))
                $response = $this->reflect($response);

            if ($response instanceof Response)
                throw $response;

            throw new HTTPError(500, "App did not produce any response");
        }
        catch (Response $response)
        {
            self::$logger->debug("While executing controller: {0}", [$this->app]);
            $desc = StatusCode::description($response->getCode());
            self::$logger->info(
                "{0} {1} - {2}",
                [$response->getCode(), $desc, $this->request->url]
            );
            throw $response;
        }
        catch (Throwable $e)
        {
            self::$logger->debug("While executing controller: {0}", [$this->app]);
            self::$logger->notice(
                "Unexpected exception of type {0} thrown while processing request to URL: {1}", 
                [get_class($e), $this->request->url]
            );
            throw $e;
        }
        finally
        {
            $this->logScriptOutput();
        }
    }

    /**
     * A wrapper to execute / include the selected route. This puts the app in a
     * private scope, with access to the most commonly used variables:
     * $request The request object
      
     * $db A database connection
     * $url The URL that was requested
     * $get The GET parameters sent to the script
     * $post The POST parameters sent to the script
     * $url_args The URL arguments sent to the script (remained of the URL after the selected route)
     *
     * @param string $path The file to execute
     * @return mixed The response produced by the script, if any
     */
    private function doExecute()
    {
        // Prepare some variables that come in handy in apps
        $request = $this->request;
        $resolver = $this->dispatcher->getResolver();
        $tpl = $template = $this->dispatcher->getTemplate();
        $config = $this->dispatcher->getConfig();
        $url = $request->url;

        try
        {
            $db = DB::getDefault();
        }
        catch (\RuntimeException $e)
        {
            // Running without database
            $db = null;
        }

        $get = $request->get;
        $post = $request->post;
        $url_args = $this->dispatcher->getURLArgs();
        $path = $this->app;

        self::$logger->debug("Including {0}", [$path]);
        $resp = include $path;

        return $resp;
    }

    /**
     * Get a list of public variables of the object, and see if they
     * match any common variables:
     * - $template
     * - $request -> The HTTP Request
     * - $resolve -> The resolver object
     * - $url_args -> The remaining URL arguments
     * - $logger -> Create a logger instance
     *
     * @param object $object The object to fill
     */
    protected function injectVariables($object)
    {
        // Inject some properties when they're public
        $vars = array_keys(get_object_vars($object));

        if (in_array('template', $vars))
            $object->template = $this->dispatcher->getTemplate();

        if (in_array('request', $vars))
            $object->request = $this->request;

        if (in_array('resolve', $vars))
            $object->resolve = $this->dispatcher->getResolver();

        if (in_array('url_args', $vars))
            $object->url_args = $this->request->url_args;

        if (in_array('logger', $vars))
            $object->logger = Logger::getLogger(get_class($object));
    }
    
    /**
     * Find the correct method in a controller to execute for the current request.
     * The route led to this controller, so the method is the first following URL argument.
     * Any suffix is stripped - method names may not contain dots anyway.
     * @param object $object The object from which to find a method to call.
     * @return callable A callable method in this object
     */
    protected function findController($object)
    {
        // Get the next URL argument
        $urlargs = $this->request->url_args;
        $arg = $urlargs->shift();

        // Store it as controller name
        $controller = $arg;
        
        // Strip any suffix
        if (($pos = strpos($controller, '.')) !== false)
            $controller = substr($controller, 0, $pos);
        
        // Check if the method exists
        if (!method_exists($object, $controller))
        {
            // Fall back to an index method
            if (method_exists($object, "index"))
            {
                if ($controller !== null)
                    $urlargs->unshift($arg);
                $controller = "index";
            }
            else
                throw new HTTPError(404, "Unknown controller: " . $controller);
        }
        return $controller;
    }

    
    /**
     * If you prefer to encapsulate your controllers in classes, you can 
     * have your app files return an object instead of a response.
     * 
     * Create a class and add methods to this class with names corresponding to
     * the first unmatched part of the route. E.g., if your controller is
     * /image and the called route is /image/edit/3, your class should contain
     * a method called 'edit' that accepts one argument of int-type.
     *
     * The parameters of your methods are extracted using reflection and
     * matched to the request. You can use string or int types, or subclasses
     * of Wedeto\DB\DAO. In the latter case, the object will be instantiated
     * using the parameter as identifier, that will be passed to the
     * DAO::get method.
     */
    protected function reflect($object)
    {
        // Find the correct method in the object
        $controller = $this->findController($object);
        
        // Fill public member variables of controller
        $this->injectVariables($object);

        $method = new ReflectionMethod($object, $controller);
        $parameters = $method->getParameters();

        // No required parameters, call the method!
        if (count($parameters) === 0)
            return call_user_func(array($object, $controller));

        // Iterate over the parameters and assign a value to them
        $args = array();
        $arg_cnt = 0;
        $urlargs = $this->request->url_args;
        foreach ($parameters as $cnt => $param)
        {
            $tp = $param->getType();
            if ($tp === null)
            {
                ++$arg_cnt;
                if (!$urlargs->has(0))
                    throw new HTTPError(400, "Invalid arguments - expecting argument $arg_cnt");

                $args[] = $urlargs->shift();
                continue;
            }

            $tp = (string)$tp;
            if ($tp === Dictionary::class)
            {
                if ($cnt !== (count($parameters) - 1))
                    throw new HTTPError(500, "Dictionary must be last parameter");

                $args[] = $urlargs;
                break;
            }

            if ($tp === Request::class)
            {
                $args[] = $this->request;
                continue;
            }

            if ($tp === Template::class)
            {
                $args[] = $this->dispatcher->getTemplate();
                continue;
            }
            
            ++$arg_cnt;
            if ($tp === "int")
            {
                if (!$urlargs->has(0, Dictionary::TYPE_INT))
                    throw new HTTPError(400, "Invalid arguments - missing integer as argument $cnt");
                $args[] = (int)$urlargs->shift();
                continue;
            }

            if ($tp === "string")
            {
                if (!$urlargs->has(0, Dictionary::TYPE_STRING))
                    throw new HTTPError(400, "Invalid arguments - missing string as argument $cnt");
                $args[]  = (string)$urlargs->shift();
                continue;
            }

            if (class_exists($tp) && is_subclass_of($tp, DAO::class))
            {
                if (!$urlargs->has(0))
                    throw new HTTPError(400, "Invalid arguments - missing identifier as argument $cnt");
                $object_id = $urlargs->shift();    
                $obj = call_user_func(array($tp, "get"), $object_id);
                $args[] = $obj;
                continue;
            }

            throw new HTTPError(500, "Invalid parameter type: " . $tp);
        }

        return call_user_func_array([$object, $controller], $args);
    }
}
