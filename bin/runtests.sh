#!/bin/bash

ME=`dirname $0`
LOC=`realpath $ME/..`

PHPUNIT=`which phpunit`
if [ "$PHPUNIT" == "" ]
then
    echo "Please make sure the phpunit command is in the path"
fi

pushd $LOC

echo "**** Running core tests..."
$PHPUNIT --bootstrap bootstrap/initTest.php core/test --stop-on-error $@
if [ "$?" -ne 0 ]
then
    echo "**** Core tests failed!"
    exit 1
fi
echo "**** Core tests passed."
echo ""

for module in `ls modules`
do
    if [ -d "modules/$module/test" ]
    then
        echo "**** Running tests for module ${module}..."
        $PHPUNIT --bootstrap bootstrap/initTest.php modules/$module/test --stop-on-error $@
        if [ "$?" -ne 0 ]
        then
            echo "Tests for module $module failed!"
            exit 1
        fi
        echo "**** Tests for module ${module} passed."
        echo ""
    fi
done
