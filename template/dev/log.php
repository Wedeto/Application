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

$this->addJS('vendor/jquery/jquery');
$this->addStyle('#phplog,#wedetolog {overflow: auto; white-space: pre-wrap;font-size:0.8em;}');
include tpl('parts/header');
?>
        <div class="large-12 medium-12 columns callout">
            <pre id="wedetolog" title="<?=txt(t('Wedeto log messages are displayed here'));?>">
            </pre>
        </div>
        <div class="large-12 medium-12 columns callout">
            <pre id="phplog" title="<?=txt(t('PHP errors are displayed here'));?>">
            </pre>
        </div>
        <script>
            setInterval(updateLog, 2500);
            setTimeout(updateLog, 0);

            function updateLog()
            {
                // Get the log files from Wedeto
                if (!window.jQuery)
                    return;

                $.get('/dev/log/getLog', {'count': 50}, function (response) {
                    var phpl = $('#phplog');
                    phpl.text('');
                    for (var i = 0; i < response.phplog.length; ++i)
                        phpl.append(response.phplog[i], "<br>");

                    phpl[0].scrollTop = phpl[0].scrollHeight;

                    var wedetol = $('#wedetolog');
                    wedetol.text('');
                    for (var i = 0; i < response.wedetolog.length; ++i)
                        wedetol.append(response.wedetolog[i], "<br>");
                    wedetol[0].scrollTop = wedetol[0].scrollHeight;
                }, "json");
            }

            setTimeout(function () {
                $(window).on('resize', resizeLogs);
                resizeLogs();
            }, 10);

            function resizeLogs()
            {
                var avail = $(window).innerHeight() * 0.8;
                var off = $('pre').first().position();

                var logh = avail / 2;
                $('pre').css('height', logh);
            };
        </script>
<?php
include tpl('parts/footer');
