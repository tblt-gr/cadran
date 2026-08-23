<?php

require dirname(__DIR__).'/vendor/autoload.php';

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
