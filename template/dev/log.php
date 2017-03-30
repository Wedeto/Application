<?php

$this->addJS('vendor/jquery');
$this->addStyle('#phplog,#wasplog {overflow: auto; white-space: pre-wrap;font-size:0.8em;}');
include tpl('parts/header');
?>
        <div class="large-12 medium-12 columns callout">
            <pre id="wasplog" title="<?=txt(t('WASP log messages are displayed here'));?>">
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
                // Get the log files from WASP
                if (!window.jQuery)
                    return;

                $.get('/dev/log/getLog', {'count': 50}, function (response) {
                    var phpl = $('#phplog');
                    phpl.text('');
                    for (var i = 0; i < response.phplog.length; ++i)
                        phpl.append(response.phplog[i], "<br>");

                    phpl[0].scrollTop = phpl[0].scrollHeight;

                    var waspl = $('#wasplog');
                    waspl.text('');
                    for (var i = 0; i < response.wasplog.length; ++i)
                        waspl.append(response.wasplog[i], "<br>");
                    waspl[0].scrollTop = waspl[0].scrollHeight;
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
