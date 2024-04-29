<?php

require_once str_replace('\\', '/', __DIR__.'/../../autoload.php');

use WebRegulate\DeployHelper\DeployHelper;

$deployHelper = new DeployHelper('deployhelper.json');
$deployHelper->run();