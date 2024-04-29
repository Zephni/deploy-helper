<?php

// Get the cwd that the user called the script from
$cwd = getcwd();

// Set an array of the paths and filenames we need to copy, not that the value is the destination from the cwd root
$copyFiles = [
    __DIR__.'/files/deployhelper.json' => 'deployhelper.json',
    __DIR__.'/files/deployhelper' => 'deployhelper',
];

echo PHP_EOL;

// Loop through the files and copy them
foreach ($copyFiles as $source => $destination) {
    // Check if the file exists
    if (!file_exists($destination)) {
        // Copy the file
        copy($source, $cwd . '/' . $destination);

        // With green text using ANSI escape codes, show success
        echo "\033[0;32mFile " . $destination . ' copied successfully.' . "\033[0m" . PHP_EOL;
    } else {
        // Output that the file already exists with yellow warning text, tell the user to delete it and try again
        echo "\033[0;33mFile " . $destination . ' already exists, please delete it and try again.' . "\033[0m" . PHP_EOL;
    }
}

echo PHP_EOL;

// In yellow, remind the user to add the following to their composer.json file:
echo "\033[0;33mPlease add the following to your composer.json scripts:" . PHP_EOL;
/* Output the following in white:
"scripts": {
    "deploy": "php ./vendor/webregulate/deploy-helper/deployhelper.php"
}
*/
echo "\033[0;37m\"scripts\": {" . PHP_EOL;
echo "    \"deploy\": \"php ./vendor/webregulate/deploy-helper/deployhelper.php\"" . PHP_EOL;
echo "}";

echo PHP_EOL;