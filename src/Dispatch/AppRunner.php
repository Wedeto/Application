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

namespace Wedeto\Application\Dispatch;

use Wedeto\DB\Model;
use Wedeto\DB\Exception\DAOException;

use Wedeto\Util\Functions as WF;
use Wedeto\Util\Dictionary;
use Wedeto\Util\Validation\Type;
use Wedeto\Util\LoggerAwareStaticTrait;
use Wedeto\Util\DI\DI;

use Wedeto\Log\Logger;

use Wedeto\HTTP\Response\Response;
use Wedeto\HTTP\Response\Error as HTTPError;
use Wedeto\HTTP\StatusCode;

use ReflectionMethod;
use Throwable;
use RuntimeException;

/**
 * Run / include an application and make sure it produces response.
 * All output if buffered and will be logged to the logger at the end, to
 * avoid sending garbage to the client.
 */
class AppRunner
{
    use LoggerAwareStaticTrait;

    /** The variables to make available in the app */
    protected $variables = [];

    /** The instances to make available in the app */
    protected $instances = [];

    /** The arguments to the script */
    protected $arguments;

    /** The application to execute */
    protected $app;

    /** The initial output buffer level */
    protected $output_buffer_level;

    /**
     * Create the AppRunner with the request and the app path
     * @param string $app The path to the appplication to run
     * @param Dictionary $arguments The arguments to the app
     */
    public function __construct(string $app, $arguments = null)
    {
        self::getLogger();
        $this->app = $app;

        if (!empty($arguments))
        {
            if (!($arguments instanceof Dictionary))
                $arguments = new Dictionary($arguments);
            $this->arguments = $arguments;
        }
        else
            $this->arguments = new Dictionary;
    }

    /**
     * Assign a variable to make available to the app.
     *
     * Depending on the type of controller, the variables are assigned in
     * various ways.  For a plain script, the variables are available directly
     * as their name. When the script contains a class, the variables may be
     * assigned to public properties of that class with the same name.
     * 
     * Additionally, they may be assigned to parameters to the controller
     * method. When $as_instance is set to true, an equal class name
     * is sufficient to perform the matching. When $as_instance is set to false,
     * class and name must be equal. This only works for objects.
     *
     * @param string $name The name of the variable
     * @param string $value The value to assign
     * @param bool $as_instance To allow referencing this variable by class
     * @return AppRunner Provides fluent interface
     */
    public function setVariable(string $name, $value, bool $as_instance = true)
    {
        $this->variables[$name] = $value;
        if (is_object($value))
            $this->instances[get_class($value)] = $value;
        return $this;
    }

    /**
     * Set multiple variables. A wrapper around AppRunner#setVariable to loop
     * over an array.
     * @param array|Traversable $variables The variables to set
     * @param bool $as_instance To allow referincing this variable by class
     * @return AppRunner Provides fluent interface
     */
    public function setVariables($variables, bool $as_instance = true)
    {
        foreach ($variables as $key => $var)
            $this->setVariable($key, $var, $as_instance);
        return $this;
    }

    /**
     * @return mixed A set variable, or null if it isn't set
     */
    public function getVariable(string $name)
    {
        return $this->variables[$name] ?? null;
    }
    
    /**
     * Set the argument Dictionary. Parameters for controller methods
     * may be filled using these values, in which case they will be removed
     * from the dictionary. The remaining contents will be assigned to 
     * a variable called $arguments, automatically. This takes precedence
     * over AppRunner#setVariable.
     *
     * @param Dictionary $arguments The arguments to set
     * @return AppRunner Provides fluent interface
     */
    public function setArguments(Dictionary $arguments)
    {
        $this->arguments = $arguments; 
        return $this;
    }


    /**
     * Run the app and make produce a response.
     * @throws Wedeto\HTTP\Response\Response or another exception thrown by the controller
     */
    public function execute()
    {
        try
        {
            // No output should be produced by apps directly, so buffer
            // everything, and log afterwards
            $this->output_buffer_level = ob_get_level();

            ob_start();
            $response = $this->doExecute();

            if (
                (is_object($response) && !($response instanceof Response)) || 
                (is_string($response) && class_exists($response))
            )
            {
                $response = $this->reflect($response);
            }

            if ($response instanceof Response)
                throw $response;


            throw new HTTPError(500, "App did not produce any response");
        }
        catch (Response $response)
        {
            self::$logger->debug("Response type {0} returned from controller: {1}", [get_class($response), $this->app]);
            throw $response;
        }
        catch (Throwable $e)
        {
            self::$logger->debug("While executing controller: {0}", [$this->app]);
            self::$logger->notice(
                "Unexpected exception of type {0} thrown while processing request: {1}", 
                [get_class($e), $e]
            );

            throw $e;
        }
        finally
        {
            $this->logScriptOutput();
        }
    }

    /**
     * A wrapper to execute / include the selected route.  It puts the
     * app in a private scope, which access to a set of predefined variables.
     *
     * @param string $path The file to execute
     * @return mixed The response produced by the script, if any
     */
    protected function doExecute()
    {
        // Prepare some variables that come in handy in apps
        extract($this->variables);
    
        // Set the arguments
        $arguments = $this->arguments;

        // Get the app to execute
        $path = $this->app;

        self::$logger->debug("Including {0}", [$path]);
        $resp = include $path;

        return $resp;
    }

    /**
     * Get a list of public variables of the object, and set them to any variable assigned
     * using AppRunner#setVariable. A variable called 'logger' will be filled with a logger
     * instance of the class. If a method called 'setLogger' exists it will also be called
     * with this instance.
     *
     * @param object $object The object to fill
     */
    protected function injectVariables($object)
    {
        // Inject some properties when they're public
        $vars = array_keys(get_object_vars($object));

        foreach ($this->variables as $name => $value)
        {
            if (in_array($name, $vars))
                $object->$name = $value;
        }

        if (in_array('arguments', $vars))
            $object->arguments = $this->arguments;

        if (in_array('logger', $vars))
            $object->logger = Logger::getLogger(get_class($object));

        if (method_exists($object, 'setLogger'))
            $object->setLogger(Logger::getLogger(get_class($object)));
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
        $urlargs = $this->arguments;
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
     * of Wedeto\DB\Model. In the latter case, the object will be instantiated
     * using the parameter as identifier, that will be passed to the
     * DAO::get method.
     *
     * It is also possible to set variables, using AppRunner#setVariable.
     * Variables set this way will be assigned to corresponding parameters of the
     * method. These parameters must match in type and name.
     * 
     * Finally, all remaining arguments can be passed to a parameter called
     * $arguments with type Dictionary, or, a Dictionary parameter that is the
     * last parameter.
     */
    protected function reflect($object)
    {
        // Instantiate an object when a classname is specified
        if (is_string($object) && class_exists($object))
            $object = DI::getInjector()->newInstance($object, $this->variables);

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
        $urlargs = $this->arguments;
        $vars = $this->variables;
        $vars['arguments'] = $this->variables;

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

            // Check if a variable by the correct name was set
            $name = $param->getName();
            if (isset($vars[$name]))
            {
                $val = $vars[$name];
                if ((is_object($val) && is_a($val, $tp)) || (gettype($val) === $tp))
                {
                    $args[] = $val;
                    continue;
                }
            }

            // Arguments may be passed in by having a Dictionary parameter as the last parameter
            if ($tp === Dictionary::class)
            {
                if ($cnt !== (count($parameters) - 1))
                    throw new HTTPError(500, "Dictionary must be last parameter");

                $args[] = $urlargs;
                break;
            }

            if (class_exists($tp) && isset($this->instances[$tp]))
            {
                $args[] = $this->instances[$tp];
                continue;
            }

            ++$arg_cnt;
            if ($tp === "int")
            {
                if (!$urlargs->has(0, Type::INT))
                    throw new HTTPError(400, "Invalid arguments - missing integer as argument $cnt");
                $args[] = (int)$urlargs->shift();
                continue;
            }

            if ($tp === "string")
            {
                if (!$urlargs->has(0, Type::STRING))
                    throw new HTTPError(400, "Invalid arguments - missing string as argument $cnt");
                $args[]  = (string)$urlargs->shift();
                continue;
            }

            if (class_exists($tp) && is_subclass_of($tp, Model::class))
            {
                if (!$urlargs->has(0))
                    throw new HTTPError(400, "Invalid arguments - missing identifier as argument $cnt");
                $object_id = $urlargs->shift();    
                $dao = $tp::getDAO();
                try
                {
                    $obj = $dao->getByID($object_id);
                }
                catch (DAOException $e)
                {
                    $namespace_parts = explode("\\", $tp);
                    $base_class_name = end($namespace_parts);
                    throw new HTTPError(404, "Unable to load {$base_class_name} with ID $object_id", "Invalid entity");
                }
                $args[] = $obj;
                continue;
            }

            throw new HTTPError(500, "Invalid parameter type: " . $tp);
        }

        return call_user_func_array([$object, $controller], $args);
    }

    /**
     * Log all output of the script to the logger.
     */
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
}
