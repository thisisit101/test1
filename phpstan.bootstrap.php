<?php

// environment initialization for PHPStan

set_include_path(implode(PATH_SEPARATOR, [
    'program/lib',
    'plugins/calendar/drivers',
    'plugins/libkolab/lib',
    'plugins/libcalendaring',
    'plugins/libcalendaring/lib',
    ini_get('include_path'),
]));

require_once 'program/include/iniset.php';

// Stub jqueryui plugin, we're using it, but do not have an easy way to include
class jqueryui extends rcube_plugin
{
    public function init()
    {
    }

    public static function miniColors()
    {
    }

    public static function tagedit()
    {
    }
}
