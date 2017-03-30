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

use PHPUnit\Framework\TestCase;

/**
 * @covers Wedeto\Platform\CLI
 */
class CLITest extends TestCase
{
    /**
     * Since getopt access argv directly, there is no way to modify
     * the command line arguments from within the script - changing
     * $argv or $_SERVER['argv'] has no effect. Therefore, the
     * output of getopt // CLI::parse cannot be reliably unit tested.
     *
     * @covers Wedeto\Platform\CLI::__construct
     * @covers Wedeto\Platform\CLI::formatText
     */
    public function testFormatText()
    {
        $cli = new CLI();
        $txt = "12345 67890 12345 67890 12345 67890 12345 67890";

        $fh = fopen("php://memory", "rw");

        $cli->formatText(2, 10, $txt, $fh);

        fseek($fh, 0);
        $ostr = fread($fh, 500);
        fclose($fh);

        $this->assertEquals($ostr, "12345 \n  67890 \n  12345 \n  67890 \n  12345 \n  67890 \n  12345 \n  67890 \n");
    }

    /**
     * @covers Wedeto\Platform\CLI::__construct
     * @covers Wedeto\Platform\CLI::addOption
     * @covers Wedeto\Platform\CLI::getOptString
     * @covers Wedeto\Platform\CLI::mapOptions
     */
    public function testGetOptString()
    {
        $cli = new CLI();
        $cli->clearOptions();
        $cli->addOption('a', 'archive', true, 'Archive to file');
        $cli->addOption('b', 'bootstrap', false, 'Perform bootstrap sequence');
        $cli->addOption('c', null, true, 'Compile a file');
        $cli->addOption('d', null, false, 'Run developer mode');
        $cli->addOption(null, 'indicate', true, 'Indicate a file');
        $cli->addOption(null, 'jar', false, 'Generate JAR output');

        list($short, $long, $mapping) = $cli->getOptString();

        $this->assertEquals($short, 'a:bc:d');

        $this->assertEquals($long, array(
            'archive:',
            'bootstrap',
            'indicate:',
            'jar'
        ));

        $this->assertEquals($mapping, array(
            'a' => 'archive',
            'b' => 'bootstrap'
        ));
    
        $args = array('a' => 'filename', 'b' => 0, 'c' => 'filename2');
        $mapped = $cli->mapOptions($args, $mapping);

        $this->assertEquals($mapped, array(
            'archive' => 'filename',
            'bootstrap' => 0,
            'c' => 'filename2'
        ));
    }
}
