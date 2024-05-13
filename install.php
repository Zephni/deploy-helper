<?php

// Get the cwd that the user called the script from
$cwd = getcwd();

// Set an array of the paths and filenames we need to copy, not that the value is the destination from the cwd root
$copyFiles = [
    __DIR__.'/files/deployhelper' => 'deployhelper',
    __DIR__.'/files/deployhelper.json' => 'deployhelper.json',
];

echo PHP_EOL;

// Loop through the files and copy them
foreach ($copyFiles as $source => $destination) {
    // Check if the file exists
    if (!file_exists($destination)) {
        // Copy the file
        copy($source, $cwd . '/' . $destination);

        // With green text using ANSI escape codes, show success
        echo "\033[0;32mFile " . $destination . ' copied successfully.' . "\033[0m" . PHP_EOL . PHP_EOL;
    } else {
        // Output that the file already exists with yellow warning text, tell the user to delete it and try again
        // echo "\033[0;33mFile " . $destination . ' already exists, please delete it and try again.' . "\033[0m" . PHP_EOL;

        // Now we instead ask the user if they want to overwrite the file
        echo "\033[0;33mFile " . $destination . ' already exists, would you like to overwrite it? (y/n) [n]: ' . "\033[0m";

        // Get the user input
        $input = readline();

        // If the user input is 'y' or 'yes', overwrite the file
        if ($input === 'y' || $input === 'yes') {
            // Copy the file
            copy($source, $cwd . '/' . $destination);

            // With green text using ANSI escape codes, show success
            echo "\033[0;32mFile " . $destination . ' overwritten successfully.' . "\033[0m" . PHP_EOL . PHP_EOL;
        } else {
            // With grey text using ANSI escape codes, show that the file was not copied
            echo "\033[0;37mFile " . $destination . ' not copied.' . "\033[0m" . PHP_EOL . PHP_EOL;
        }
    }
}

// In white, tell the user how to run the deployhelper command
echo "\033[0;37mTo run deployhelper, use the following command:" . PHP_EOL;

// In yellow, show the command php ./deployhelper
echo "\033[0;33mphp ./deployhelper" . "\033[0m" . PHP_EOL;


echo PHP_EOL;