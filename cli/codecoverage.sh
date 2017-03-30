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
mkdir -p var/codecoverage/core
$PHPUNIT --bootstrap bootstrap/initTest.php core/test --whitelist core/lib --coverage-html var/codecoverage/core --stop-on-error $@
if [ "$?" -ne 0 ]
then
    echo "**** Core tests failed!"
    exit 1
fi
echo "**** Core tests passed."
echo ""

exit 0

for module in `ls modules`
do
    if [ -d "modules/$module/test" ]
    then
        echo "**** Running tests for module ${module}..."
        mkdir -p var/codecoverage/${module}
        $PHPUNIT --bootstrap bootstrap/initTest.php core/test --whitelist modules/${module}/lib --coverage-html var/codecoverage/${module} $2
        if [ "$?" -ne 0 ]
        then
            echo "Tests for module $module failed!"
            exit 1
        fi
        echo "**** Tests for module ${module} passed."
        echo ""
    fi
done
