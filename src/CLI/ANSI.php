<?php
namespace Wedeto\Application\CLI;

/**
 * Provides easy access to ANSI terminal escape codes.
 */
class ANSI
{
    const ESC = "\033[";
    const CLEARSCREEN = self::ESC . "2J" . self::ESC . "H";
    const CLS = self::CLEARSCREEN;
    const CLEARLINE = self::ESC . "2K" . self::ESC . "1G";

    const RESET = self::ESC . "0m";

    const BRIGHT = self::ESC . "1m";
    const NOBRIGHT = self::ESC . "22m";

    const UNDERLINE = self::ESC . "4m";
    const NOUNDERLINE = self::ESC . "24m";

    const BLINK = self::ESC . "5m";
    const NOBLINK = self::ESC . "25m";

    const INVERSE = self::ESC . "7m";
    const NOINVERSE = self::ESC . "27m";

    const BLACK = 0;
    const RED = 1;
    const GREEN = 2;
    const YELLOW = 3;
    const BLUE = 4;
    const MAGENTA = 5;
    const CYAN = 6;
    const WHITE = 7;

    public static $enabled;

    public static function isTerminal()
    {
        return posix_isatty(STDOUT);
    }

    public static function bright(string $str)
    {
        return self::$enabled ? self::BRIGHT . $str . self::RESET : $str;
    }

    public static function blink(string $str)
    {
        return self::$enabled ? self::BLINK . $str . self::NOBLINK : $str;
    }

    public static function underline(string $str)
    {
        return self::$enabled ? self::UNDERLINE . $str . self::NOUNDERLINE : $str;
    }

    public static function inverse(string $str)
    {
        return self::$enabled ? self::INVERSE . $str . self::NOINVERSE : $str;
    }

    public static function printColor(string $str, int $fg, int $bg)
    {
        return self::$enabled ? self::ESC . ($fg + 30) . ';' . ($bg + 40) . 'm' . $str . self::RESET : $str;
    }

    public static function setColor(int $fg, int $bg)
    {
        return self::$enabled ? self::ESC . ($fg + 30) . ';' . ($bg + 40) . 'm' : '';
    }

    public static function setFGColor(int $fg)
    {
        return self::$enabled ? self::ESC . ($fg + 30) . 'm' : '';
    }

    public static function setBGColor(int $bg)
    {
        return self::$enabled ? self::ESC . ($bg + 40) . 'm' : '';
    }

    public static function setPos(int $x = 1, int $y = 1)
    {
        return self::$enabled ? self::ESC . $x . ";" . $y . "H" : '';
    }

    public static function setColumn(int $y)
    {
        return self::$enabled ? self::ESC . $y . "G" : '';
    }

    public static function up(int $n)
    {
        return self::$enabled ? self::ESC . $n . "A" : '';
    }

    public static function down(int $n)
    {
        return self::$enabled ? self::ESC . $n . "B" : '';
    }

    public static function left(int $n)
    {
        return self::$enabled ? self::ESC . $n . "D" : '';
    }

    public static function right(int $n)
    {
        return self::$enabled ? self::ESC . $n . "C" : '';
    }

    public static function cls()
    {
        return self::$enabled ? self::CLS : '';
    }

    public static function clearline()
    {
        return self::$enabled ? self::CLEARLINE : '';
    }

    public static function reset()
    {
        return self::$enabled ? self::RESET : '';
    }
}

ANSI::$enabled = ANSI::isTerminal();
