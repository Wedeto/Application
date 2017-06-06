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

use PHPUnit\Framework\TestCase;

use Wedeto\Util\Dictionary;
use Wedeto\HTTP\URL;
use Wedeto\HTTP\URLException;

/**
 * @covers Wedeto\Application\Site
 * @covers Wedeto\Application\VirtualHost
 */
final class SiteTest extends TestCase
{
    public function testSite()
    {
        $s =  new Site;
        $s->setName('foobar');
        
        $this->assertEquals('foobar', $s->getName());

        $vhost1 = new VirtualHost('http://foobar.com', 'en');
        $s->addVirtualHost($vhost1);

        $vhost2 = new VirtualHost('https://foobar.nl', 'nl');
        $s->addVirtualHost($vhost2);

        $this->assertEquals(['en', 'nl'], $s->getLocales());

        $actual = $s->match('http://foobar.com/');
        $this->assertEquals($vhost1, $actual);

        $actual = $s->match('https://foobar.com/');
        $this->assertNull($actual);

        $actual = $s->match('http://foobar.nl/');
        $this->assertNull($actual);

        $actual = $s->match('https://foobar.nl/');
        $this->assertEquals($vhost2, $actual);

        $actual = $s->match('http://foobar.de/');
        $this->assertNull($actual);

        $actual = $s->getVirtualHosts();
        $this->assertEquals([$vhost1, $vhost2], $actual);

        $vhost3 = new VirtualHost('http://foobar.de', 'de');
        $vhost3->setRedirect('https://foobar.nl');
        $s->addVirtualHost($vhost3);

        $actual = $s->getVirtualHosts();
        $this->assertEquals([$vhost1, $vhost2, $vhost3], $actual);

        $actual = $s->checkRedirect('http://foobar.de/foo/bar/baz');
        $expected = new URL('https://foobar.nl/foo/bar/baz');
        $this->assertEquals($expected, $actual);

        $actual = $s->checkRedirect('http://foobar.com/foo/bar');
        $this->assertFalse($actual);

        $actual = $s->URL('/assets', 'nl');
        $expected = new URL('https://foobar.nl/assets');
        $this->assertEquals($expected, $actual);

        $actual = $s->URL('/assets', 'af');
        $this->assertNull($actual);
    }

    public function testSetupSites()
    {
        $dict = new Dictionary();
        $dict['url'] = array('https://foobar.de', 'https://foobar.com', 'https://foobar.nl', 'https://example.com', 'http://example.nl');
        $dict['languages'] = array('de', 'com', 'nl', 'en');
        $dict['site'] = array('default', 'default', 'default', 'example', 'example');
        $dict['redirect'] = array(4 => 'https://example.com');
        
        $resp = Site::setupSites($dict);
        $this->assertEquals(2, count($resp));
        
        $this->assertTrue(isset($resp['default']));
        $this->assertTrue(isset($resp['example']));
        $first = $resp['default'];
        $second = $resp['example'];
        
        $this->assertEquals('default', $first->getName());
        $this->assertEquals('example', $second->getName());

        $fhosts = $first->getVirtualHosts();
        $shosts = $second->getVirtualHosts();

        $this->assertEquals(3, count($fhosts));
        $this->assertEquals(2, count($shosts));

        $actual = $fhosts[0]->getHost();
        $expected = new URL('https://foobar.de');
        $this->assertEquals($expected, $actual);

        $actual = $fhosts[1]->getHost();
        $expected = new URL('https://foobar.com');
        $this->assertEquals($expected, $actual);

        $actual = $fhosts[2]->getHost();
        $expected = new URL('https://foobar.nl');
        $this->assertEquals($expected, $actual);

        $actual = $shosts[0]->getHost();
        $expected = new URL('https://example.com');
        $this->assertEquals($expected, $actual);

        $actual = $shosts[1]->getHost();
        $expected = new URL('http://example.nl');
        $this->assertEquals($expected, $actual);

        $url = new URL('http://example.nl/foo');
        $actual = $shosts[1]->getRedirect($url);
        $expected = new URL('https://example.com/foo');
        $this->assertEquals($expected, $actual);

        $this->assertEquals($first, $fhosts[0]->getSite());
        $this->assertEquals($first, $fhosts[1]->getSite());
        $this->assertEquals($first, $fhosts[2]->getSite());
        $this->assertEquals($second, $shosts[0]->getSite());
        $this->assertEquals($second, $shosts[1]->getSite());

        $shosts[1]->setRedirect(null);
        $url = new URL('http://example.nl/foo');
        $actual = $shosts[1]->getRedirect($url);
        $this->assertNull($actual);

        $current = $shosts[1]->getHost();
        $actual = $shosts[1]->URL('assets', $current);
        $expected = new URL('/assets');
        $this->assertEquals($expected, $actual);
    }

    public function testIsSecure()
    {
        $vhost1 = new VirtualHost('http://www.foobar.com', null);
        $vhost2 = new VirtualHost('https://foobar.com', null);
        $this->assertFalse($vhost1->isSecure());
        $this->assertTrue($vhost2->isSecure());
    }

    public function testHasWww()
    {
        $vhost1 = new VirtualHost('http://www.foobar.com', null);
        $vhost2 = new VirtualHost('http://foobar.com', null);
        $this->assertTrue($vhost1->hasWWW());
        $this->assertFalse($vhost2->hasWWW());
    }

    public function testMatchUrl()
    {
        $vhost1 = new VirtualHost('http://www.foobar.com', null);

        $this->assertTrue($vhost1->match('www.foobar.com'));

        $this->expectException(URLException::class);
        $this->expectExceptionMessage('Unsupported scheme: \'garbage\'');
        $vhost1->match('garbage://scheme');
    }

    public function testMatchLocale()
    {
        $vhost1 = new VirtualHost('http://www.foobar.com', 'en');
        $vhost2 = new VirtualHost('http://www.foobar.de', 'de');
        $vhost2->setRedirect('http://www.foobar.com');

        $this->assertTrue($vhost1->matchLocale('en'));
        $this->assertTrue($vhost1->matchLocale(null));
        $this->assertFalse($vhost1->matchLocale('de'));

        $this->assertFalse($vhost2->matchLocale('en'));
        $this->assertFalse($vhost2->matchLocale(null));
        $this->assertFalse($vhost2->matchLocale('de'));
    }
}
