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

use Wedeto\Util\Dictionary;

/**
 * Provides functions for interacting with a GUI
 */
class CLI
{
    const MAX_LINE_LENGTH = 80;

    private $parameters = array();

    /**
     * Create the CLI object, parsing command line arguments.
     * @throws CLIException When the script is not run from the command line
     */
    public function __construct()
    {
        // Make sure we're running on CLI
        $this->checkCLI();

        // Add the default options
        $this
            ->addOption("l", "loglevel", "level", "Sets the log level for the output")
            ->addOption("f", "logfile", "filename", "Sets the log file where output will be written to")
            ->addOption("s", "silent", false, "If this is set, output is only written to logfile, not to stdout")
            ->addOption("h", "help", false, "Show this help");
    }

    /**
     * @codeCoverageIgnore Tests are always run using CLI SAPI
     */
    private function checkCLI()
    {
        if (PHP_SAPI !== "cli" || !isset($_SERVER['argv']))
            throw new CLIException("Not running on command line");
    }

    public function clearOptions()
    {
        $this->parameters = array();
    }

    /**
     * Register a valid option
     * @param $short string The single character version of this option
     * @param $long string The long form of this option
     * @param $arg string The name/type of parameter, shown in the help text. Set to false to disable the argument
     * @param $description string The description of this option, shown in the help text.
     */
    public function addOption($short, $long, $arg, $description)
    {
        $this->parameters[] = array(
            $short,
            $long,
            $arg,
            $description
        );
        return $this;
    }

    public function getOptString()
    {
        $opt_str = "";
        $long_opts = array();
        $mapping = array();

        foreach ($this->parameters as $param)
        {
            $short = $param[0];
            $long = $param[1];
            $arg = $param[2];

            if ($short)
            {
                if ($long)
                    $mapping[$short] = $long;

                if ($arg)
                    $short .= ":";

                $opt_str .= $short;
            }

            if ($long)
            {
                if ($arg)
                    $long .= ":";
                $long_opts[] = $long;
            }
        }
        return array($opt_str, $long_opts, $mapping);
    }

    /**
     * Parse the provided arguments and options.
     *
     * @param $arguments array The list of arguments to parse
     * @return array Associative array of option => argument pairs. For option without an argument, the argument === false
     * @codeCoverageIgnore We cannot fool getopt to modify the arguments, so this cannot be unit tested
     */
    public function parse()
    {
        list($opt_str, $long_opts, $mapping) = $this->getOptString();
        $opts = \getopt($opt_str, $long_opts);
        $options = $this->mapOptions($opts, $mapping);
        return new Dictionary($options);
    }

    public function mapOptions(array $opts, array $mapping)
    {
        // Map short options to the long options where available
        $res = array();
        foreach ($opts as $k => $v)
        {
            if (isset($mapping[$k]))
                $res[$mapping[$k]] = $v;
            else
                $res[$k] = $v;
        }
        return $res;
    }

    /**
     * Read from standard input
     * @param $prompt string The text explaining what input is expected, null to avoid prompt
     * @param $default string What to return in case the user enters nothing. If not specified,
     *                        this function keeps asking until input is provided
     * @return string The input or default if no input was specified
     * @codeCoverageIgnore STDIN can't be reliably unit tested - test manually
     */
    public static function input($prompt, $default = null)
    {
        $ret = false;
        while (!$ret)
        {
            if ($prompt)
            {
                echo $prompt;
                if ($default)
                    echo " (" . $default . ")";
                 
                echo ": ";
            }
            $ret = trim(fgets(STDIN));

            if (!$ret && $default !== null)
                return $default;
        }

        return trim($ret);
    }

    public static function formatText($indent, $max_width, $text, $ostr = STDOUT)
    {
        $parts = explode(" ", $text);
        $str_part = "";
        $first = true;
        foreach ($parts as $p)
        {
            if (strlen($str_part) + ($first ? $indent : 0) + strlen($p) > $max_width && strlen($str_part) != $indent)
            {
                fprintf($ostr, $str_part . "\n");
                $first = false;
                $str_part = str_pad("", $indent);
            }
            $str_part .= $p . " ";
        }
        if (trim($str_part) !== "")
            fprintf($ostr, $str_part . "\n");
    }

    /**
     * Show the help text, generated based on the configured options
     * @codeCoverageIgnore Validate output manually - formatText is unit tested. 
     */
    public function syntax($error = "Please specify valid options")
    {
        $ostr = (!$error) ? STDOUT : STDERR;
        if (is_string($error))
            fprintf($ostr, "Error: %s\n", $error);
        fprintf($ostr, "Syntax: php " . $_SERVER['argv'][0] . " <options> <action>\n\n");
        fprintf($ostr, "Options: \n");
        $max_opt_length = 0;
        $max_arg_length = 0;

        // Sort the parameters alphabetically
        $params = $this->parameters;
        usort($params, function ($a, $b) {
            $lo = !empty($a[0]) ? $a[0] : $a[1];
            $ro = !empty($b[0]) ? $b[0] : $b[1];
            return strcmp($lo, $ro);
        });

        foreach ($params as $param)
        {
            $max_opt_length = max(strlen($param[1]) + 3, $max_opt_length);
            $max_arg_length = max(strlen($param[2]) + 3, $max_arg_length);
        }

        foreach ($this->parameters as $param)
        {
            fprintf($ostr, "    ");
            $so = $param[0] ? "-" . $param[0] : "";
            $lo = $param[1] ? "--" . $param[1] : "";
            $arg = $param[2] ? '<' . $param[2] . '>' : "";

            $pstr = sprintf("%-2s %-" . $max_opt_length . "s %-" . $max_arg_length . "s ", $so, $lo, $arg);
            $indent = strlen($pstr) + 4;
            fprintf($ostr, $pstr);

            self::formatText($indent, self::MAX_LINE_LENGTH, $param[3], $ostr);
        }
        exit($error === false ? 0 : 1);
    }
}
