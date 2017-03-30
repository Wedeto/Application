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

namespace Wedeto\Model;

use Wedeto\DB\DAO;
use Wedeto\DB\DB;
use Wedeto\DB\Table\Table;
use Wedeto\DB\Table\Column;
use Wedeto\DB\Table\Index;

class DBVersion extends DAO
{
    protected static $table = "db_version";
    protected static $idfield = "id";

    public function __construct($module)
    {
        $this->record = self::fetchSingle(array("module" => $module));
        if (empty($this->record))
        {
            $this->record = array('module' => $module, 'version' => 0);
            $this->save();
        }
    }

    public static function createTable()
    {
        $table = new Table(
            self::$table,
            new Column\SerialColumn('id'),
            new Column\StringColumn('module', 128),
            new Column\IntColumn('version'),
            new Index(Index::PRIMARY, 'id'),
            new Index(Index::UNIQUE, 'module')
        );

        // We are now at version 1
        $drv = DB::get()->driver();
        $drv->createTable($table);

        $rec = new DBVersion('core');
        $rec->setField('version', 1);
        $rec->save();
    }

    public static function dropTable()
    {
        // We are now at version 1
        $drv = DB::get()->driver();
        $drv->dropTable(self::$table);
    }
}
