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
