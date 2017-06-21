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
include tpl('parts/header');
?>
        <div class="large-12 medium-12 columns callout">
            <h1><?=$error_code ?? "500";?> - <?=txt($error_title ?? td("Internal Server Error", "wedeto"));?></h1>
            <p>
                <?=txt($error_lead ?? td("An unexpected error occured", "wedeto"));?>
            </p>
            <div class="row">
                <div class="large-12 columns">
                    <div class="callout" style="max-width: 100%; overflow: auto;">
                        <pre><?=txt($error_description ?? td("An unexpected error occured", "wedeto"));?></pre>
                    </div>
                </div>
            </div>
        </div><?php
include tpl('parts/footer');
