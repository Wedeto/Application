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

use PHPUnit\Framework\TestCase;
use Wedeto\HTTP\StringResponse;
use Wedeto\HTTP\Request;
use Wedeto\HTTP\Error as HttpError;
use Wedeto\HTML\Template;

use RuntimeException;
use Psr\Log\LogLevel;

/**
 * @covers Wedeto\Application\Dispatch\AppRunner
 */
final class AppRunnerTest extends TestCase
{
    private $request;
    private $pathconfig;
    private $testpath;
    private $filename;
    private $classname;
    private $devlogger;

    public function setUp()
    {
        $this->request = new MockAppRunnerRequest();
        $this->pathconfig = System::path();

        $this->testpath = $this->pathconfig->var . '/test';
        IO\Dir::mkdir($this->testpath);
        $this->filename = tempnam($this->testpath, "wedetotest") . ".php";
        $this->classname = "cl_" . str_replace(".", "", basename($this->filename));

        $logger = Debug\Logger::getLogger(AppRunner::class);
        $this->devlogger = new Debug\DevLogger(LogLevel::DEBUG);
        $logger->addLogHandler($this->devlogger);
    }

    public function tearDown()
    {
        IO\Dir::rmtree($this->testpath);
        $logger = Debug\Logger::getLogger(AppRunner::class);
        $logger->removeLogHandlers();
    }

    /**
     * @covers Wedeto\Application\Dispatch\AppRunner::__construct
     * @covers Wedeto\Application\Dispatch\AppRunner::execute
     */
    public function testAppReturnsResponse()
    {
        $phpcode = <<<EOT
<?php

use Wedeto\HTTP\Response\StringResponse;

return new StringResponse("Mock", "text/plain");
EOT;
        file_put_contents($this->filename, $phpcode);

        $apprunner = new AppRunner($this->request, $this->filename);
        $this->assertInstanceOf(AppRunner::class, $apprunner);

        $this->expectException(StringResponse::class);
        $apprunner->execute();
    }

    public function testAppThrowsResponse()
    {
        $phpcode = <<<EOT
<?php

use Wedeto\HTTP\Response\StringResponse;

throw new StringResponse("Mock", "text/plain");
EOT;
        file_put_contents($this->filename, $phpcode);

        $apprunner = new AppRunner($this->request, $this->filename);
        $this->assertInstanceOf(AppRunner::class, $apprunner);

        $this->expectException(StringResponse::class);
        $apprunner->execute();
    }

    public function testAppProducesOutput()
    {
        $phpcode = <<<EOT
<?php

use Wedeto\HTTP\Response\StringResponse;

echo "MOCK";

throw new StringResponse("Mock", "text/plain");
EOT;
        file_put_contents($this->filename, $phpcode);

        $apprunner = new AppRunner($this->request, $this->filename);

        try
        {
            $apprunner->execute();
        }
        catch (StringResponse $e)
        {
            $output = $this->devlogger->getLog();
            $found = false;
            foreach ($output as $line)
                $found = $found || strpos($line, "Script output: 1/1: MOCK") !== false;
                
            $this->assertTrue($found);
        }
    }

    public function testAppUsesTemplate()
    {
        $phpcode = <<<EOT
<?php

use Wedeto\HTTP\Response\StringResponse;

\$tpl->setTemplate('foobar');
\$tpl->render();
EOT;
        file_put_contents($this->filename, $phpcode);

        $apprunner = new AppRunner($this->request, $this->filename);

        try
        {
            $apprunner->execute();
        }
        catch (StringResponse $e)
        {
            $op = $e->getOutput('text/html');
            $this->assertNotNull($op);
        }
    }

    public function testAppProducesNoResponse()
    {
        $phpcode = <<<EOT
<?php

use Wedeto\HTTP\Response\StringResponse;

for (\$i = 0; \$i < 10; ++\$i)
    ;
EOT;
        file_put_contents($this->filename, $phpcode);

        $apprunner = new AppRunner($this->request, $this->filename);

        $this->expectException(HttpError::class);
        $this->expectExceptionMessage("App did not produce any response");
        $apprunner->execute();
    }

    public function testAppProducesException()
    {
        $phpcode = <<<EOT
<?php

throw new RuntimeException("Foobarred");
EOT;
        file_put_contents($this->filename, $phpcode);

        $apprunner = new AppRunner($this->request, $this->filename);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Foobarred");
        $apprunner->execute();
    }

    public function testAppReturnsObject()
    {
        $classname = $this->classname;
        $phpcode = <<<EOT
<?php

use Wedeto\HTTP\Response\StringResponse;

class {$classname}
{
    public \$url_args;

    public function foo()
    {
        \$remain = \$this->url_args->getAll();
        return new StringResponse(implode(",", \$remain), "text/plain");
    }
}

return new {$classname}();
EOT;
        file_put_contents($this->filename, $phpcode);

        $apprunner = new AppRunner($this->request, $this->filename);

        try
        {
            $apprunner->execute();
        }
        catch (StringResponse $e)
        {
            $this->assertEquals("bar,baz", $e->getOutput('text/plain'));
        }
    }

    public function testAppReturnsObjectWithIndex()
    {
        $classname = $this->classname;
        $phpcode = <<<EOT
<?php

use Wedeto\HTTP\Response\StringResponse;

class {$classname}
{
    public \$url_args;

    public function index()
    {
        \$remain = \$this->url_args->getAll();
        return new StringResponse(implode(",", \$remain), "text/plain");
    }
}

return new {$classname}();
EOT;
        file_put_contents($this->filename, $phpcode);

        $apprunner = new AppRunner($this->request, $this->filename);

        try
        {
            $apprunner->execute();
        }
        catch (StringResponse $e)
        {
            $this->assertEquals("foo,bar,baz", $e->getOutput('text/plain'));
        }
    }

    public function testAppReturnsObjectArgs()
    {
        $classname = $this->classname;
        $phpcode = <<<EOT
<?php

use Wedeto\HTTP\Response\StringResponse;

class {$classname}
{
    public function foo(string \$arg1, string \$arg2)
    {
        return new StringResponse(\$arg1 . \$arg2, "text/plain");
    }
}

return new $classname();
EOT;
        file_put_contents($this->filename, $phpcode);

        $apprunner = new AppRunner($this->request, $this->filename);

        try
        {
            $apprunner->execute();
        }
        catch (StringResponse $e)
        {
            $this->assertEquals("barbaz", $e->getOutput('text/plain'));
        }
    }

    public function testAppReturnsObjectNotEnoughArgs()
    {
        $classname = $this->classname;
        $phpcode = <<<EOT
<?php

use Wedeto\HTTP\Response\StringResponse;

class {$classname}
{
    public function foo(string \$arg1, string \$arg2, int \$arg3)
    {
        return new StringResponse(\$arg1 . \$arg2 . \$arg3, "text/plain");
    }
}

return new $classname();
EOT;
        file_put_contents($this->filename, $phpcode);

        $apprunner = new AppRunner($this->request, $this->filename);

        $this->expectException(HttpError::class);
        $this->expectExceptionMessage("Invalid arguments - missing integer as argument 2");
        $apprunner->execute();
    }

    public function testAppReturnsObjectInvalidArgs()
    {
        $classname = $this->classname;
        $phpcode = <<<EOT
<?php

use Wedeto\HTTP\Response\StringResponse;

class {$classname}
{
    public function foo(string \$arg1, int \$arg2)
    {
        return new StringResponse(\$arg1 . \$arg2, "text/plain");
    }
}

return new $classname();
EOT;
        file_put_contents($this->filename, $phpcode);

        $apprunner = new AppRunner($this->request, $this->filename);

        $this->expectException(HttpError::class);
        $this->expectExceptionMessage("Invalid arguments - missing integer as argument 1");
        $apprunner->execute();
    }

    public function testAppReturnsObjectWantsRequestTemplateAndDictionary()
    {
        $classname = $this->classname;
        $phpcode = <<<EOT
<?php

use Wedeto\HTTP\Response\StringResponse;
use Wedeto\Util\Dictionary;
use Wedeto\HTTP\Request;
use Wedeto\Application\Dispatch\Template;

class {$classname}
{
    public function foo(Request \$arg1, Template \$arg2, Dictionary \$arg3)
    {
        \$vals = \$arg3->toArray();
        if (!(\$arg2 instanceof Wedeto\Application\Dispatch\Template))
            throw new Wedeto\HTTP\Response\Error('No template');
        \$vals[] = \$arg1->url;
        return new StringResponse(implode(",", \$vals), "text/plain");
    }
}

return new $classname();
EOT;
        file_put_contents($this->filename, $phpcode);

        $apprunner = new AppRunner($this->request, $this->filename);

        try
        {
            $apprunner->execute();
        }
        catch (StringResponse $e)
        {
            $this->assertEquals('bar,baz,/', $e->getOutput('text/plain'));
        }
    }

    public function testAppReturnsObjectWithResponseTypeSuffix()
    {
        $classname = $this->classname;
        $phpcode = <<<EOT
<?php

use Wedeto\HTTP\Response\StringResponse;

class {$classname}
{
    public \$request;

    public function foo()
    {
        return new StringResponse('foobar' . \$this->request->suffix, "text/plain");
    }
}

return new $classname();
EOT;
        file_put_contents($this->filename, $phpcode);
        $this->request->suffix = '.txt';
        $this->request->url_args[0] = 'foo.txt';

        $apprunner = new AppRunner($this->request, $this->filename);

        try
        {
            $apprunner->execute();
        }
        catch (StringResponse $e)
        {
            $this->assertEquals('foobar.txt', $e->getOutput('text/plain'));
        }
    }

    public function testAppReturnsObjectWithPublicProperties()
    {
        $classname = $this->classname;
        $phpcode = <<<EOT
<?php

use Wedeto\HTTP\Response\StringResponse;
use Wedeto\HTTP\Request;
use Wedeto\Application\Dispatch\Template;
use Wedeto\Util\Dictionary;
use Wedeto\Resolve\Resolver;
use Wedeto\Log\Logger;

class {$classname}
{
    public \$template;
    public \$request;
    public \$resolve;
    public \$url_args;
    public \$logger;

    public function foo(Request \$arg1, Template \$arg2, Dictionary \$arg3)
    {
        \$response = ['Foo'];
        if (\$this->template instanceof Template)
            \$response[] = "Template";
        if (\$this->request instanceof Request)
            \$response[] = "Request";
        if (\$this->logger instanceof Logger)
            \$response[] = "Logger";
        if (\$this->resolve instanceof Resolver)
            \$response[] = "Resolver";
        else
            throw new \RuntimeException('Invalid resolve: ' . Wedeto\\Util\\functions::str(\$this->resolve));

        if (\$this->url_args instanceof Dictionary)
            \$response[] = "Dictionary";
        else
            throw new \RuntimeException('Invalid url args');

        var_dump(\$response);
        return new StringResponse(implode(",", \$response), "text/plain");
    }
}

return new $classname();
EOT;
        file_put_contents($this->filename, $phpcode);

        $apprunner = new AppRunner($this->request, $this->filename);

        try
        {
            $apprunner->execute();
        }
        catch (StringResponse $e)
        {
            $this->assertEquals('Foo,Template,Request,Logger,Resolver,Dictionary', $e->getOutput('text/plain'));
        }
    }

    public function testAppReturnsObjectWantsDictionaryNotLast()
    {
        $classname = $this->classname;
        $phpcode = <<<EOT
<?php

use Wedeto\HTTP\Response\StringResponse;
use Wedeto\Util\Dictionary;
use Wedeto\HTTP\Request;

class {$classname}
{
    public function foo(Dictionary \$arg1, Request \$arg2)
    {
        return new StringResponse("FAIL", "text/plain");
    }
}

return new $classname();
EOT;
        file_put_contents($this->filename, $phpcode);

        $apprunner = new AppRunner($this->request, $this->filename);
        
        $this->expectException(HttpError::class);
        $this->expectExceptionMessage("Dictionary must be last parameter");
        $apprunner->execute();
    }

    public function testAppReturnsObjectUntypedParameters()
    {
        $classname = $this->classname;
        $phpcode = <<<EOT
<?php

use Wedeto\HTTP\Response\StringResponse;

class {$classname}
{
    public function foo(\$arg1, \$arg2)
    {
        return new StringResponse(\$arg1 . \$arg2, "text/plain");
    }
}

return new $classname();
EOT;
        file_put_contents($this->filename, $phpcode);

        $apprunner = new AppRunner($this->request, $this->filename);
        
        try
        {
            $apprunner->execute();
        }
        catch (StringResponse $e)
        {
            $this->assertEquals('barbaz', $e->getOutput('text/plain'));
        }
    }

    public function testAppReturnsObjectUnknownController()
    {
        $classname = $this->classname;
        $phpcode = <<<EOT
<?php

use Wedeto\HTTP\Response\StringResponse;

class {$classname}
{
    public function bar()
    {
        return new StringResponse("FOO", "text/plain");
    }
}

return new $classname();
EOT;
        file_put_contents($this->filename, $phpcode);

        $apprunner = new AppRunner($this->request, $this->filename);
        
        $this->expectException(HttpError::class);
        $this->expectExceptionMessage("Unknown controller: foo");
        $apprunner->execute();
    }

    public function testAppReturnsObjectWithUntypedParametersAndInsufficientArgs()
    {
        $classname = $this->classname;
        $phpcode = <<<EOT
<?php
use Wedeto\HTTP\Response\StringResponse;

class {$classname}
{
    public function foo(\$arg1, \$arg2, \$arg3)
    {
        return new StringResponse("FOO", "text/plain");
    }
}

return new $classname();
EOT;
        file_put_contents($this->filename, $phpcode);

        $apprunner = new AppRunner($this->request, $this->filename);
        
        $this->expectException(HttpError::class);
        $this->expectExceptionMessage("Invalid arguments - expecting argument 3");
        $apprunner->execute();
    }

    public function testAppReturnsObjectWithIntAndStringParameters()
    {
        $this->request->url_args[1] = 3;
        $classname = $this->classname;
        $phpcode = <<<EOT
<?php
use Wedeto\HTTP\Response\StringResponse;

class {$classname}
{
    public function foo(int \$arg1, string \$arg2)
    {
        return new StringResponse(\$arg1 . \$arg2, "text/plain");
    }
}

return new $classname();
EOT;
        file_put_contents($this->filename, $phpcode);

        $apprunner = new AppRunner($this->request, $this->filename);
        
        try
        {
            $apprunner->execute();
        }
        catch (StringResponse $e)
        {
            $this->assertEquals('3baz', $e->getOutput('text/plain'));
        }
    }

    public function testAppReturnsObjectWithStringAndStringParameters()
    {
        $classname = $this->classname;
        $this->request->url_args[1] = 3;
        $phpcode = <<<EOT
<?php
use Wedeto\HTTP\Response\StringResponse;

class {$classname}
{
    public function foo(string \$arg1, string \$arg2)
    {
        return new StringResponse(\$arg1 . \$arg2, "text/plain");
    }
}

return new $classname();
EOT;
        file_put_contents($this->filename, $phpcode);

        $apprunner = new AppRunner($this->request, $this->filename);
        
        $this->expectException(HttpError::class);
        $this->expectExceptionMessage("Invalid arguments - missing string as argument 0");
        $apprunner->execute();
    }

    public function testAppReturnsObjectWithInvalidParameters()
    {
        $classname = $this->classname;
        $this->request->url_args[1] = 3;
        $phpcode = <<<EOT
<?php
use Wedeto\HTTP\Response\StringResponse;

class {$classname}
{
    public function foo(float \$arg1, StdClass \$arg2)
    {
        return new StringResponse(\$arg1 . \$arg2, "text/plain");
    }
}

return new $classname();
EOT;
        file_put_contents($this->filename, $phpcode);

        $apprunner = new AppRunner($this->request, $this->filename);
        
        $this->expectException(HttpError::class);
        $this->expectExceptionMessage("Invalid parameter type: float");
        $apprunner->execute();
    }

    public function testAppReturnsObjectWithDAOParameter()
    {
        $classname = $this->classname;
        $phpcode = <<<EOT
<?php
use Wedeto\HTTP\Response\StringResponse;

class {$classname}
{
    public function foo(Wedeto\Application\Dispatch\MockAppRunnerDAO \$arg1, string \$arg2)
    {
        return new StringResponse(\$arg1->getName() . \$arg2, "text/plain");
    }
}

return new $classname();
EOT;
        file_put_contents($this->filename, $phpcode);

        $apprunner = new AppRunner($this->request, $this->filename);
        try
        {
            $apprunner->execute();
        }
        catch (StringResponse $e)
        {
            $this->assertEquals("barDAObaz", $e->getOutput('text/plain'));
        }
    }

    public function testAppReturnsObjectWithMissingDAOParameter()
    {
        $classname = $this->classname;
        $phpcode = <<<EOT
<?php
use Wedeto\HTTP\Response\StringResponse;

class {$classname}
{
    public function foo(string \$arg1, string \$arg2, Wedeto\Application\Dispatch\MockAppRunnerDAO \$arg3)
    {
        return new StringResponse(\$arg3->getName(), "text/plain");
    }
}

return new $classname();
EOT;
        file_put_contents($this->filename, $phpcode);

        $apprunner = new AppRunner($this->request, $this->filename);
        $this->expectException(HttpError::class);
        $this->expectExceptionMessage("Invalid arguments - missing identifier as argument 2");
        $apprunner->execute();
    }
}

class MockAppRunnerRequest extends Request
{
    public function __construct()
    {
        $this->url = '/';
        $this->resolver = System::resolver();
        $this->url_args = new Dictionary(["foo", "bar", "baz"]);
        $this->template = new MockAppRunnerTemplate();
    }
}

class MockAppRunnerDAO extends \Wedeto\DB\DAO
{
    protected $name;

    public function __construct($name)
    {
        $this->name = $name . "DAO";
    }

    public static function get($id, \Wedeto\DB\DB $database = null)
    {
        return new MockAppRunnerDAO($id);
    }

    public function getName()
    {
        return $this->name;
    }
}

class MockAppRunnerTemplate extends Template
{
    public function __construct()
    {}

    public function setTemplate(string $str, string $mime = 'text/html')
    {}

    public function render()
    {
        throw new StringResponse("Foobar", "text/html");
    }
}
