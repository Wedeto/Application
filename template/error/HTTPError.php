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

$error_code = 500;
if ($exception instanceof Wedeto\HTTP\Response\Error)
    $error_code = (int)$exception->getCode();

$error_title = "Unexpected error";
$error_lead = "Your request cannot be handled";
$error_description = "The server encountered an error while processing your request";

$error_title = Wedeto\HTTP\StatusCode::description($error_code);
switch ($error_code)
{
    case 404:
        $error_title = "Not Found";
        $error_lead = $request->url->path . " could not be found on this server";
        $error_description = "The resource you requested can not be found";
        break;
    case 400:
        $error_title = "Bad Request";
        $error_lead = "The request data is incompatible or incomplete";
        $error_description = "Your browser sent a request that cannot be handled";
        break;
    case 403:
        $error_title = "Forbidden";
        $error_lead = "You are not authorized for the action you tried to perform. Make sure you are logged in correctly.";
        $error_description = "Your request cannot be handled because you are not authorized for it.";
        break;
}

if ($dev)
{
    $error_description .= 
        "\n\nDescription: " . $exception->getMessage() . "\n" 
        . Wedeto\Util\Functions::str($exception);
}
elseif (method_exists($exception, 'getUserMessage'))
{
    $user_message = $exception->getUserMessage();
    if (!empty($user_message))
        $error_description .= "\n\nDescription: " . $user_message;
}

require tpl('error/HTMLErrorTemplate');

?>
