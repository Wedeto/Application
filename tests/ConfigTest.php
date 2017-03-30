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
 * @covers Wedeto\Config
 */
class ConfigTest extends TestCase
{
    /**
     * @covers Wedeto\Config::getConfig
     * @covers Wedeto\Config::writeConfig
     */
    public function testGetConfig()
    {
        $ini = <<<EOT
;precomment about this file
[sec1]
;a-comment for section 1
;testcomment for section 1
var1 = "value1"
var2 = "value2"

[sec2]
;z-comment for section 2
;testcomment for section 2
var3 = "value3"
var4 = "value4"
EOT;
    
        $old_path = Path::current();

        $old_root = $old_path->root;
        $cfg_dir = $old_root . '/var/test';
        if (!is_dir($cfg_dir))
            mkdir($cfg_dir);

        $path = new Path(array('root' => $old_root, 'config' => $cfg_dir));
        $file = $cfg_dir . '/test.ini';
        file_put_contents($file, $ini);

        $config = Config::getConfig('test');
        $this->assertEquals($config->get('sec1', 'var1'), 'value1');
        $this->assertEquals($config->get('sec1', 'var2'), 'value2');
        $this->assertEquals($config->get('sec2', 'var3'), 'value3');
        $this->assertEquals($config->get('sec2', 'var4'), 'value4');

        $config->set('sec2', 'var5', 'value5');
        Config::writeConfig('test');

        $cfg = parse_ini_file($file, true, INI_SCANNER_TYPED);
        $this->assertEquals($cfg['sec2']['var5'], 'value5');

        unlink($file);
        rmdir($path->config);

        Path::setCurrent($old_path);
    }

    /**
     * @covers Wedeto\Config::getConfig
     */
    public function testGetConfigFailSafe()
    {
        $path = Path::current();
        $cfgdir = $path->config;
        // Make sure we have a non-existing config file
        for ($i = 0; $i < 10000; ++$i)
        {
            $confname = 'foobar' . $i;
            $filename = $cfgdir . '/' . $confname . '.ini';
            if (!file_exists($filename))
                break;
        }
        if ($i == 10000)
            throw new \RuntimeException("You have too many foobar config files for this test");

       $config = Config::getConfig($confname, true);
       $this->assertNull($config);
    }

    /**
     * @covers Wedeto\Config::getConfig
     */
    public function testGetConfigNonExisting()
    {
        $path = Path::current();
        $cfgdir = $path->config;
        // Make sure we have a non-existing config file
        for ($i = 0; $i < 10000; ++$i)
        {
            $confname = 'foobar' . $i;
            $filename = $cfgdir . '/' . $confname . '.ini';
            if (!file_exists($filename))
                break;
        }
        if ($i == 10000)
            throw new \RuntimeException("You have too many foobar config files for this test");

        $this->expectException(Http\Error::class);
        $config = Config::getConfig($confname);
    }

    /**
     * @covers Wedeto\Config::writeConfig
     */
    public function testWriteConfigException()
    {
        $this->expectException(\RuntimeException::class);
        $config = Config::writeConfig('foobar' . rand(100, 10000));
    }
}
