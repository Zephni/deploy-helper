<?php

// NOTE: WE MUST REQUIRE COMPOSER'S `autoload.php` BEFORE RUNNING THIS SCRIPT

// Die if WebRegulate\DeployHelper\DeployHelper class does not exist, and note that user must autoload first
if (!class_exists('WebRegulate\DeployHelper\DeployHelper')) {
    die("\nDeployHelper run.php must be required after Composer's autoload.php.\nPlease check deployhelper script is up to date by running:\nphp ./vendor/webregulate/deploy-helper/install.php\n\n");
}

use WebRegulate\DeployHelper\DeployHelper;

$deployHelper = new DeployHelper('deployhelper.json');
$deployHelper->run();