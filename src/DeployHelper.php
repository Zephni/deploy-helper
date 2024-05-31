<?php
// IMPORTANT:
// RENAME 'deployhelper.json.example' TO 'deplyhelper.json' AND CONFIGURE BEFORE USING THIS SCRIPT
// 'deplyhelper.json' WILL AUTOMATICALLY BE IMPORTED BY NAME WHEN THIS SCRIPT IS RUN

/*------------------------------------
    DeployHelper
    Author: Craig Dennis
    Date: 2024-05-17
    Version: 1.0.3
    Description: Helper script to upload (or manage) files to a server via SFTP

    RUN IN TERMINAL WITH:
    > php deployhelper

    NOTE: When running a "Prepare commands:" option, you will be asked to confirm before the commands are added to the queue.
    Now you can do a dry run with the "dry" command, or "run" command to run them. Commands will stack up in the queue until
    you run (run) them or clear (clr) them.

    NOTE: The deploy command will run through all the steps in the correct order and prompt you for confirmation before each step.

    OPTION TAGS:    You can pass --dry or -d to any option to run it in dry run mode.
                    You can pass --run or -r to any option to run it in run mode.

------------------------------------*/

namespace WebRegulate\DeployHelper;

use \AppendIterator;
use \ArrayIterator;
use \FilesystemIterator;
use \RecursiveDirectoryIterator;
use \RecursiveIteratorIterator;
use \SplFileInfo;
use WebRegulate\DeployHelper\DeployHelperOption;

// DeployHelper class, instantiated automatically when this file is run
class DeployHelper
{
    // Properties
    private string $environmentConfigKey;
    private object $environmentConfig;
    private object $mainConfig;
    public array $options = [];
    private array $commandsToRun = [];
    private array $commandsCache = [];
    private $sftpConnection;
    private $sftpObject;
    private bool $dryRun = true;
    private string $localBaseDirectory;
    private bool $supressOutput = false;
    private $jsonConfigFile = null;
    private $numConfigKeys = 0;
    private $forceSFTPRefresh = false;
    private $keepCommandsAfterRunning = false;
    private $hideOptionsDisplay = false;
    private const IGNORE_COMMAND = "IGNORE_COMMAND";
    private const PATH_SELECTOR_MODE_FILE = 1;
    private const PATH_SELECTOR_MODE_FOLDER = 2;

    // Constructor
    public function __construct(string $jsonConfigFile)
    {
        $this->jsonConfigFile = $jsonConfigFile;

        $this->environmentConfig = $this->verifyAndBuildConfig($jsonConfigFile);

        $this->localBaseDirectory = $this->unix_path(getcwd());

        $this->options = [
            "COMMANDS",

            new DeployHelperOption("file", "Upload a file", function ($autoConfirm = true, $additionalArgs = []) {
                $this->runUploadFile(null, $autoConfirm);
            }),

            new DeployHelperOption("dir", "Upload a directory", function ($autoConfirm = true, $additionalArgs = []) {
                $this->runUploadDirectory(null, $autoConfirm);
            }),

            new DeployHelperOption("list", "Present a list of files to upload", function ($autoConfirm = true, $additionalArgs = []) {
                $this->runList($autoConfirm);
            }),

            new DeployHelperOption("local", "Run a command in the local terminal", function ($autoConfirm = true, $additionalArgs = []) {
                // Check if user has passed a command through args
                $command = trim(implode(' ', $additionalArgs));

                // If no command then ask user for command
                if(empty($command)) {
                    $command = $this->ask("Enter command to run", null);
                }

                if(empty($command)) {
                    $this->echo("No command entered\n", "red");
                    return;
                }

                // Run the command with proc_open to allow for interactive commands
                $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);

                // If process is null then bail
                if($process == null) {
                    $this->echo("Failed to run command\n", "red");
                    return;
                }

                // Loop through the pipes and output the data
                foreach($pipes as $pipe) {
                    stream_set_blocking($pipe, 0);
                    while($line = fgets($pipe)) {
                        echo $line;
                    }
                }

                // Allow user to continue interacting with the terminal
                proc_close($process);
            }),

            "UPLOAD COMMANDS",

            new DeployHelperOption("gitchanges", "Sync active git changes", function ($autoConfirm = true, $additionalArgs = []) {
                $this->runGitChanges($autoConfirm, 'git status --short --porcelain --untracked-files');
            }),

            new DeployHelperOption("uploadbuild", "Upload build directory", function ($autoConfirm = true, $additionalArgs = []) {
                $this->runUploadDirectory($this->environmentConfig->buildDirectory, $autoConfirm);
            }),

            new DeployHelperOption("commits", "Upload changes from a specific branch->commit", function ($autoConfirm = true, $additionalArgs = []) {
                $this->uploadChangesFromCommit($autoConfirm);
            }),

            "DRY RUN OR RUN COMMANDS",

            new DeployHelperOption("dry", "Dry run prepared commands", function ($autoConfirm = true, $additionalArgs = []) {
                $this->dryRun = true;
                $this->runPreparedCommands(true);
            }),

            new DeployHelperOption("run", "Run prepared commands", function ($autoConfirm = true, $additionalArgs = []) {
                $this->dryRun = false;
                $this->runPreparedCommands(false);
            }),

            "OTHER",

            new DeployHelperOption("config", "Show current config (deployhelper.json)", function ($autoConfirm = true, $additionalArgs = []) {
                $this->showConfig();
            }),

            new DeployHelperOption("sftp", "Check SFTP connection", function ($autoConfirm = true, $additionalArgs = []) {
                $this->dryRun = false;
                $this->createSFTPConnectionIfNotSet();
                $this->dryRun = true;
            }),

            new DeployHelperOption("keep", "Set keep commands mode [arg_1: boolean]", function ($autoConfirm = true, $additionalArgs = []) {
                $arg1_bool = count($additionalArgs) > 0 ? $additionalArgs[0] : null;

                if($arg1_bool == 'true' || $arg1_bool == '1' || $arg1_bool == 'on' || $arg1_bool == 'yes') {
                    $this->keepCommandsAfterRunning = true;
                    $this->echo("Keep mode set to: ".$this->color('ON', 'green')."\n", "green");
                } else if($arg1_bool == 'false' || $arg1_bool == '0' || $arg1_bool == 'off' || $arg1_bool == 'no') {
                    $this->keepCommandsAfterRunning = false;
                    $this->echo("Keep mode set to: ".$this->color('OFF', 'red')."\n", "red");
                } else {
                    $this->echo("Invalid argument [1]: Must be boolean eg. 'true', '1', 'on', 'yes', or the negative".PHP_EOL, "red");
                }
            }),

            // Open shell on remote machine
            new DeployHelperOption("shell", "Open interactive shell on remote", function ($autoConfirm = true, $additionalArgs = []) {
                $this->remoteInteractiveShell($autoConfirm);
            }),

            // Test
            new DeployHelperOption("command", "Run a list of customer commands set in deployhelper.json commands list", function ($autoConfirm = true, $additionalArgs = []) {
                // Hide options display until all commands are run
                $this->hideOptionsDisplay = true;

                // Get the passed first argument (command key)
                $arg1_command = count($additionalArgs) > 0 ? $additionalArgs[0] : null;

                // Get available commands from config
                $availableCommands = $this->mainConfig->config->commands;
                $availableCommandKeys = array_keys((array)$availableCommands);

                // If available commands is empty or null then bail
                if($availableCommands == null || count($availableCommandKeys) == 0) {
                    $this->echo("No commands found in config->commands\n", "red");
                    return;
                }

                // If no command key then present user with available keys and ask which one they would like to run by either index or name
                if($arg1_command == null) {
                    $this->echo("\nAvailable commands:\n", "yellow");
                    $i = 0;
                    foreach($availableCommandKeys as $key) {
                        $i++;
                        $this->echo($i.': '.$this->color($key, 'white')."\n", "yellow");
                    }

                    $arg1_command = $this->ask("Enter command index or name to run", null);
                }

                // If null then bail
                if($arg1_command == null) {
                    $this->echo("\nNo command key entered\n", "red");
                    return;
                }

                // If integer then get the key from the array
                if(is_int((int)$arg1_command) && $arg1_command > 0 && $arg1_command <= count($availableCommandKeys)) {
                    $arg1_command = $availableCommandKeys[$arg1_command - 1];
                }

                // Check that array key passed exists in the commands array
                if(!isset($availableCommands->{$arg1_command})) {
                    $this->echo("Command index or key not found in commands list\n", "red");
                    return;
                }

                // Set the user simulated commands
                $this->setUserSimulatedCommands($availableCommands->{$arg1_command});
            }),

            ($this->numConfigKeys > 1) ? new DeployHelperOption("switch", "Switch config", function ($autoConfirm = true, $additionalArgs = []) {
                $arg1_environment = count($additionalArgs) > 0 ? $additionalArgs[0] : null;

                $this->environmentConfig = $this->verifyAndBuildConfig($this->jsonConfigFile, $arg1_environment);
                $this->forceSFTPRefresh = true;
            }) : null,

            new DeployHelperOption("clear", "Clear prepared commands", function ($autoConfirm = true, $additionalArgs = []) {
                $this->commandsToRun = [];
                $this->commandsCache = [];
                $this->echo("Commands cleared\n", "green");
            }),

            new DeployHelperOption("exit", "Exit", function ($autoConfirm = true, $additionalArgs = []) {
                $this->exitWithMessage("Exiting...\n", "grey");
            }),
        ];
    }

    // Run
    public function run()
    {
        // Echo welcome to user in yellow
        $this->echo("\n------------------------------------\n", 'yellow');
        $this->echo('|'. str_pad("WELCOME TO DEPLOYHELPER", 34, ' ', STR_PAD_BOTH).'|', "yellow");
        $this->echo("\n------------------------------------\n", 'yellow');

        // Present options until user selects to exit or uses ctrl+c
        while(true)
        {
            if($this->simulatedCommandsCompleted === true) {
                $this->helperBot("All commands have been executed!", "🎉");
                $this->simulatedCommandsCompleted = false;
                $this->hideOptionsDisplay = false;
            }

            // Get user input
            if(!$this->hideOptionsDisplay) {
                $userInput = $this->presentOptions();
            } else {
                $userInput = $this->ask("Select an option", "");
            }

            // Get inline command options
            $optionParts = explode(' ', $userInput);
            $selectedOption = $optionParts[0];
            $selectedOptionArgs = array_slice($optionParts, 1);

            // Apply inline command options
            // $autoConfirm = $this->any_in_array(['--force', '-f'], $selectedOptionArgs);
            $autoConfirm = true;
            $autoDryRun = $this->any_in_array(['--dry', '-d'], $selectedOptionArgs);
            $autoRun = $this->any_in_array(['--run', '-r'], $selectedOptionArgs);

            // iF selected option is q or exit, then break out of loop
            if($this->any_of_values([null, false, 'q', 'exit'], $selectedOption))
            {
                $this->echo("Exiting...\n\n", "grey");
                break;
            }

            // Get option and bail if invalid
            $optionObject = $this->getOptionByKey($selectedOption);
            if(!$optionObject || !($optionObject instanceof DeployHelperOption))
            {
                $this->echo("Invalid option: $selectedOption\n", "red");
                continue;
            }

            // Run selected option
            $optionFunction = $optionObject->run;
            $optionFunction($autoConfirm, $selectedOptionArgs);

            // If $dryRun is true then automatically run the current command list in dry run mode
            if($autoDryRun)
            {
                $this->runPreparedCommands(true);
            }

            // If $run is true then automatically run the current command list in run mode
            if($autoRun)
            {
                $this->runPreparedCommands(false);
            }

            // Reset any inner passed options
            $autoConfirm = true;
            $autoDryRun = false;
            $autoRun = false;
        }
    }

    private function presentOptions()
    {
        $this->wait(0.4);

        // Find largest key length from options
        $largestKeyLength = 0;
        foreach($this->options as $option)
        {
            if($option instanceof DeployHelperOption)
            {
                $keyLength = strlen($option->key);
                if($keyLength > $largestKeyLength)
                {
                    $largestKeyLength = $keyLength;
                }
            }
        }

        foreach($this->options as $option)
        {
            if($option instanceof DeployHelperOption)
            {
                $this->echo(str_pad($option->key, $largestKeyLength + 2), "blue");
                $this->echo($option->description."\n");
            }
            else if(is_string($option))
            {
                $this->echo("\n".$option."\n", "grey");
                $this->echo(str_pad('', 38, '-')."\n", "grey");
            }
        }

        // Show current config
        $this->echo("\n[Current config: \033[1;33m".$this->environmentConfigKey."\033[0m - ".$this->environmentConfig->remoteBasePath."]", "grey");

        // Display number of prepared commands
        $this->echo("\n[", "grey");
        $this->echo("Prepared commands: ".$this->color(count($this->commandsToRun), "green"), "grey");
        $this->echo(", Keep mode: ".$this->color($this->keepCommandsAfterRunning ? "ON" : "OFF", $this->keepCommandsAfterRunning ? "red" : "green"), "grey");
        $this->echo("]\n", "grey");

        // Allow user to select option
        return $this->ask("Select an option", "");
    }

    private function switchConfig()
    {
        // Get all config files
        $environmentConfigFiles = glob("deployhelper*.json");

        // If no config files found then bail
        if(count($environmentConfigFiles) == 0)
        {
            return $this->returnWithMessage("No config files found", "red");
        }

        // Get user to select config file
        $environmentConfigFile = $this->interactivePathSelector($environmentConfigFiles, self::PATH_SELECTOR_MODE_FILE);

        // If config file is null then bail
        if($environmentConfigFile == null)
        {
            return $this->returnWithMessage("No config file selected", "red");
        }

        // Get config key from file name
        $environmentConfigKey = str_replace('deployhelper', '', $environmentConfigFile);
        $environmentConfigKey = str_replace('.json', '', $environmentConfigKey);

        // If config key is null then bail
        if($environmentConfigKey == null)
        {
            return $this->returnWithMessage("Invalid config file selected", "red");
        }

        // Set config key
        $this->environmentConfigKey = $environmentConfigKey;

        // Rebuild config
        $this->environmentConfig = $this->verifyAndBuildConfig($environmentConfigFile);

        // Show success message
        $this->echo("Config switched to: ".$this->color($environmentConfigKey, "yellow")."\n", "green");
    }

    private function runPreparedCommands($dryRun = true)
    {
        $this->dryRun = $dryRun;

        $this->echo("\nRUNNING PREPARED COMMANDS ", 'yellow');
        $this->echo("(" . count($this->commandsToRun) . ")", 'green');
        $this->echo(": ", "yellow");
        $this->echo($this->dryRun ? " (DRY RUN)" : "", 'blue');
        $this->echo("\n--------------------------\n", "yellow");

        // Get count of commands to run
        $commandsToRunCount = count($this->commandsToRun);

        // Get str padding length based on number of digits
        $strPaddingLength = strlen($commandsToRunCount);

        // If any, loop through commands and run
        if(count($this->commandsToRun) > 0)
        {
            for($i = 0; $i < count($this->commandsToRun); $i++)
            {
                // Get command
                $command = $this->commandsToRun[$i];

                // If command is false, then skip
                if($command === self::IGNORE_COMMAND)
                {
                    continue;
                }

                $this->echo(str_pad(($i + 1) . ". ", $strPaddingLength + 2, ' ', STR_PAD_RIGHT));
                $command();
            }

            // Reset commands to run
            if(!$this->dryRun && !$this->keepCommandsAfterRunning) {
                $this->commandsToRun = [];
            }
        }
        // Else, notify user that no commands were found
        else
        {
            $this->echo("\nNo commands found to run", "red");
        }

        $this->echo("\n");

        $this->dryRun = true;
    }

    public function helperBot(string $message, string $endIcon = null, float $endDelay = 0.7)
    {
        $this->echo("\n🤖  HELPER BOT: ", "yellow");
        $this->wait(0.25);
        $this->simulateTyping($message, 0.015, "green");
        $this->echo(" $endIcon\n\n");
        $this->wait($endDelay);
    }

    // The below is a special function that takes an array of "user typed commands", and executes them in order
    // This has nothing to do with the commands that are prepared and run in the main script, rather it simulates 
    // a user typing commands in the terminal and pressing enter, therefore we can run a series of commands or even
    // enter the shell and run commands by script here
    private function setUserSimulatedCommands(array $userTypedOutStrings)
    {
        $this->userSimulatedCommands = $userTypedOutStrings;

        // Helper bot tells us which commands we are about to execute
        $this->helperBot("Ready to run the following commands!", "💻");

        foreach($this->userSimulatedCommands as $command) {
            $this->echo("➡️   $command\n", "white");
            $this->wait(0.15);
        }

        $this->wait(0.7);

        // Return true
        return true;
    }

    private bool $simulatedCommandsCompleted = false;

    private function runNextUserSimulatedCommand(float $delayBetweenKeyStrokes = 0.015, float $delayBetweenExecution = 0.3)
    {
        $this->simulatedCommandsCompleted = false;

        // If no user simulated commands then bail and return false
        if($this->userSimulatedCommands == null || count($this->userSimulatedCommands) == 0) {
            return false;
        }

        // Get the first command
        $command = array_shift($this->userSimulatedCommands);

        // If command is null then bail and return false
        if($command == null) {
            return false;
        }

        // Echo the command (Simulate typing each character)
        $this->simulateTyping($command, $delayBetweenKeyStrokes);

        // Wait a little before executing the command
        $this->wait($delayBetweenExecution);

        // Echo new line
        echo PHP_EOL;

        if(count($this->userSimulatedCommands) == 0) {
            $this->simulatedCommandsCompleted = true;
        }

        // Return command
        return $command;
    }

    private function simulateTyping($string, $delayBetweenKeyStrokes = 0.015, $color = "white")
    {
        foreach(str_split($string) as $char) {
            $this->echo($char, $color);
            $this->wait($delayBetweenKeyStrokes);
        }
    }

    private function runRemoveLocalDirectory(string $directory = null, $autoConfirm = true)
    {
        // Get the directory from user if not passed
        if($directory == null)
        {
            $directory = $this->interactivePathSelector(null, self::PATH_SELECTOR_MODE_FOLDER);
        }

        $directory = trim($directory);

        // If directory starts with a slash or . then we just complain and die
        if(strlen($directory) == 0 || substr($directory, 0, 1) == '/' || substr($directory, 0, 1) == '.')
        {
            return $this->returnWithMessage("Directory path cannot start with a / or .\n", "red");
        }

        // If local directory doesn't exist then explain and bail
        if(!is_dir($directory))
        {
            return $this->returnWithMessage("Directory does not exist: ".$directory."\n", "grey");
        }

        // Recursively get a list of files that will be deleted
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        $filesIncludingBaseDirectory = new AppendIterator();
        $filesIncludingBaseDirectory->append($files);
        $filesIncludingBaseDirectory->append(new ArrayIterator([new SplFileInfo($directory)]));

        $this->prepareNewCommands(function() use ($filesIncludingBaseDirectory) {
            $commands = [];

            foreach ($filesIncludingBaseDirectory as $fileinfo) {
                $commands[] = function() use ($fileinfo) {
                    // If not a directory or a file, then skip
                    if(!$fileinfo->isDir() && !$fileinfo->isFile()){
                        $this->echo("PATH DOES NOT EXIST ", "red");
                        $this->echo($fileinfo->getRealPath()."\n", "white");
                        return;
                    }
                    
                    if($this->dryRun) $this->echo("DRY RUN: ", "grey");
                    $this->echo(($fileinfo->isDir()) ? "RMDIR " : "DEL ", "red");
                    $finalPath = str_replace('\\', '/', $fileinfo->getRealPath());
                    $this->echo($finalPath."\n");

                    // If directory then rmdir
                    if($fileinfo->isDir()) {
                        if(!$this->dryRun) rmdir($finalPath);
                    // If file then unlink
                    } else {
                        if(!$this->dryRun) unlink($finalPath);
                    }
                };
            }

            return $commands;
        }, $autoConfirm);
    }

    /**
     * Run Git changes: Checks for git changes and asks user if they want to SFTP to selected server
     * If $baseCommand is null then alert and bail
     * @return void
     */
    private function runGitChanges($autoConfirm = true, $baseCommand = null)
    {
        // Prepare heading
        $this->echo($this->prepareHeading("ACTIVE GIT CHANGES"));

        // If base command is null then bail
        if($baseCommand == null)
        {
            return $this->returnWithMessage("No base command provided", "red");
        }

        // Use git status to get all of the changes
        $_gitChangesOutput = shell_exec($baseCommand) ?? "";

        // Extract files from git changes that start with a modifed, added or deleted signal (M, ??, D)
        $_gitChangesOutput = preg_split('/\n/', $_gitChangesOutput);

        // For now, just loop and show the files
        $this->echo("Changes found:\n", "yellow");
        foreach($_gitChangesOutput as $file)
        {
            $file = trim($file);
            if($file == '') continue;
            $this->echo($file."\n");
        }
        $this->echo("\n");

        // Get modified files and store in appropriate array key
        $gitChanges = [
            'modified' => [],
            'added' => [],
            'deleted' => [],
        ];

        foreach($_gitChangesOutput as $file) {
            $file = trim($file);
            if($file == '') continue;
            // For some reason, git status --short --porcelain --untracked-files returns ?? for new files
            if(preg_match('/^\?\?/', $file))
                $gitChanges['added'][] = preg_replace('/^\?\?\s+/', '', $file);
            // git diff uses A like you'd expect
            else if(preg_match('/^A/', $file))
                $gitChanges['added'][] = preg_replace('/^A\s+/', '', $file);
            // Modified
            else if (preg_match('/^M/', $file))
                $gitChanges['modified'][] = preg_replace('/^M\s+/', '', $file);
            // Deleted
            else if(preg_match('/^D/', $file))
                $gitChanges['deleted'][] = preg_replace('/^D\s+/', '', $file);
        }

        // If no changes, notify user and ask to continue
        if(count($gitChanges['modified']) == 0 && count($gitChanges['added']) == 0 && count($gitChanges['deleted']) == 0) {
            return $this->returnWithMessage("No changes found\n", "green");
        }

        $this->echo("Preparing commands:\n", "yellow");

        $this->prepareNewCommands(function() use ($gitChanges) {
            $commands = [];

            // Loop through each file and add to sftp commands
            foreach($gitChanges as $type => $files) {
                foreach($files as $file) {
                    $commands[] = function () use ($type, $file) {
                        // If ignored file then skip
                        if($this->isIgnoredFile($file))
                        {
                            return self::IGNORE_COMMAND;
                        }

                        // Check and get SFTP connection (if not dry run and not already connected)
                        $this->createSFTPConnectionIfNotSet();

                        $fileShow = $file;

                        // If dry run then show user
                        if($this->dryRun) $this->echo("DRY RUN: ", "grey");

                        // Show type of change
                        match ($type) {
                            'modified' => $this->echo("MOD ", "blue"),
                            'added' => $this->echo("ADD ", "green"),
                            'deleted' => $this->echo("DEL ", "red"),
                            default => null
                        };

                        // Show local to remote file paths
                        $this->echo($fileShow, 'white');
                        $this->echo(" -> ", 'green');
                        $this->echo($this->environmentConfig->remoteBasePath . $fileShow, 'white');

                        // If not dry run then run sftp command
                        if(!$this->dryRun)
                        {
                            if($type == 'modified' || $type == 'added')
                            {
                                $this->sftpUpload($file, $this->environmentConfig->remoteBasePath . $file);
                            }
                            else if($type == 'deleted')
                            {
                                $this->sftpDelete($this->environmentConfig->remoteBasePath . $file);
                            }
                        }

                        $this->echo("\n");
                    };
                }
            }

            return $commands;
        }, $autoConfirm);
    }

    /**
     * Run build: Uploads the public_html/build directory to the server
     * @return void
     */
    private function runUploadDirectory($directory = null, $autoConfirm = true)
    {
        // Get the file from user if not passed
        if($directory == null)
        {
            $directory = $this->interactivePathSelector(null, self::PATH_SELECTOR_MODE_FOLDER);

            // ltrim the local base path from the directory
            $directory = $this->remove_prefix($this->localBaseDirectory.'/', $directory ?? '');
        }

        // If file is null then bail and warn user
        if($directory == null || $directory == '')
        {
            return $this->returnWithMessage("No directory selected, aborting.", "grey");
        }

        $directory = trim($directory);

        // Prepare heading
        $this->echo($this->prepareHeading("DIRECTORY UPLOAD: ".$directory));

        // If directory starts with a slash or . then we just complain and die
        if(strlen($directory) == 0 || substr($directory, 0, 1) == '/' || substr($directory, 0, 1) == '.')
        {
            $this->exitWithMessage("Directory path cannot start with a / or .\n");
        }

        // If local directory doesn't exist then explain and bail
        if(!is_dir($directory))
        {
            return $this->returnWithMessage("Directory does not exist: ".$directory, "grey");
        }

        // Get recursive directory files and folders without . and ..
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        // Prepend the base directory to the files
        $filesIncludingBaseDirectory = new AppendIterator();
        $filesIncludingBaseDirectory->append(new ArrayIterator([new SplFileInfo($directory)]));
        $filesIncludingBaseDirectory->append($files);

        $this->prepareNewCommands(function () use ($filesIncludingBaseDirectory) {
            $commands = [];

            foreach($filesIncludingBaseDirectory as $file) {
                $commands[] = function () use ($file) {
                    // IMPORTANT: If checking IGNORE then use $file->getRealPath() instead of $file->getFileInfo()

                    // Check and get SFTP connection (if not dry run and not already connected)
                    $this->createSFTPConnectionIfNotSet();

                    // Get full path
                    $fullPath = $this->environmentConfig->remoteBasePath . $file->getFileInfo();

                    // Show command to user
                    if($this->dryRun) $this->echo("DRY RUN: ", "grey");
                    $this->echo("UPLOAD ".$this->color($file->getFileInfo(), "white")." -> ".$this->color($fullPath, "white"), 'green');

                    // If not dry run then run sftp command
                    if(!$this->dryRun)
                    {
                        $this->sftpUpload($file, $fullPath);
                    }
                    $this->echo("\n");
                };
            }

            return $commands;
        }, $autoConfirm);
    }

    private function runUploadFile(string $file = null, bool $autoConfirm = true)
    {
        // Get the file from user if not passed
        if($file == null)
        {
            $file = $this->interactivePathSelector(null, self::PATH_SELECTOR_MODE_FILE);

            // ltrim the local base path from the file
            $file = $this->remove_prefix($this->localBaseDirectory.'/', $file ?? '');
        }

        // If file is null then bail and warn user
        if($file == null || $file == '')
        {
            return $this->returnWithMessage("No file selected, aborting.\n", "grey");
        }

        $file = trim($file);
        $file = $this->unix_path($file);

        // Prepare heading
        $this->echo($this->prepareHeading("FILE UPLOAD: ".$file));

        // If file starts with a slash or . then we just complain and die
        if(strlen($file) == 0 || substr($file, 0, 1) == '/' || substr($file, 0, 1) == '.')
        {
            $this->exitWithMessage("File path cannot start with a / or .\n");
        }

        // If local file doesn't exist then explain and bail
        if(!is_file($file))
        {
            return $this->returnWithMessage("File does not exist: ".$file, "grey");
        }

        $this->prepareNewCommands(function () use ($file) {
            $commands = [];

            $commands[] = function () use ($file) {
                // Check and get SFTP connection (if not dry run and not already connected)
                $this->createSFTPConnectionIfNotSet();

                // Get full path
                $fullPath = $this->environmentConfig->remoteBasePath . $file;

                // Show command to user
                if($this->dryRun) $this->echo("DRY RUN: ", "grey");
                $this->echo("UPLOAD ".$this->color($file, "white")." -> ".$this->color($fullPath, "white"), 'green');

                // If not dry run then run sftp command
                if(!$this->dryRun)
                {
                    $this->sftpUpload($file, $fullPath);
                }
                $this->echo("\n");
            };

            return $commands;
        }, $autoConfirm);
    }

    private function uploadChangesFromCommit(bool $autoConfirm = true)
    {
        // Prepare heading
        $this->echo($this->prepareHeading("UPLOAD CHANGES FROM BRANCH COMMIT"));

        // User must select a branch
        $userSelectedBranchName = $this->showBranchNameSelector();

        // If $userSelectedBranchName is null then bail
        if($userSelectedBranchName == null) {
            return;
        }

        // User must select a commit
        $userSelectedCommitHash = $this->showCommitSelector($userSelectedBranchName);

        // If user selected commit is null then bail
        if($userSelectedCommitHash == null) {
            return;
        }

        // Command to run
        $commandToRun = "git diff --name-status $userSelectedCommitHash^^";

        // Show selected commit and command to run
        $this->echo("\nSelected commit: ".$this->color($userSelectedCommitHash, "green")."\n", "grey");
        $this->echo("RUNNING: $commandToRun\n");

        // Get all files that have changed since the selected commit
        $this->runGitChanges($autoConfirm, $commandToRun);
    }

    private function showBranchNameSelector()
    {
        // Get all current branches and assign a number to each
        $i = 1;
        foreach(array_reverse(explode("\n", shell_exec('git branch'))) as $branch) {
            $branch = str_replace('*', '', $branch);
            $branch = str_replace('*', '', $branch);
            $branch = trim($branch);
            if($branch != '') {
                $branches[$i] = $branch;
                $this->echo($i . ". " . $branch . "\n");
                $i++;
            }
        }
        unset($i);

        // Get user to select a branch
        $userSelectedBranchIndex = $this->ask("Select a branch by index (default 1)", 1);

        // If not numeric, find the index of the branch name
        if(!is_numeric($userSelectedBranchIndex)) {
            $userSelectedBranchIndex = array_search($userSelectedBranchIndex, $branches);
        }

        // If user selected branch number is null or not in branches array then bail
        if($userSelectedBranchIndex == null || !array_key_exists($userSelectedBranchIndex, $branches)) {
            return $this->returnWithMessage("Invalid branch index selected\n\n", "red");
        }

        // Get the branch name from the selected number
        return $branches[$userSelectedBranchIndex];
    }

    private function showCommitSelector(string $branchName)
    {
        // Echo a single new line
        $this->echo("\n");

        // Get all commits from the selected branch
        $commits = explode("\n", shell_exec('git log --pretty=oneline '.$branchName));

        // Because there may be many we show X per page, and user can type '>' or '<' to go to next or previous page
        $commitsPerPage = 10;
        $currentPage = 1;
        $totalPages = ceil(count($commits) / $commitsPerPage);
        $validCommitSelected = false;

        while($validCommitSelected == false) {
            // Loop through commits and show them but only loop through the current page results
            for($i = ($currentPage - 1) * $commitsPerPage; $i < $currentPage * $commitsPerPage; $i++) {
                if(isset($commits[$i])) {
                    $this->echo(($i) . ". " . $commits[$i] . "\n"); // Display starts at 1
                }
            }

            // If there are more than 1 page then show page number
            if($totalPages > 1) {
                $this->echo("\nPage #$currentPage of $totalPages (Change page with '>' or '<', or go leave with 'cancel')\n", "grey");
            }

            // Get user to select a commit
            $userSelectedCommitIndex = $this->ask("Select a commit by index", null);

            // If page turn requested then do that
            if($userSelectedCommitIndex == '>') {
                if($currentPage < $totalPages) $currentPage++;
                continue;
            }
            else if($userSelectedCommitIndex == '<') {
                if($currentPage > 1) $currentPage--;
                continue;
            }
            else if($userSelectedCommitIndex == 'cancel') {
                return null;
            }

            // If invalid then tell user and continue
            if($userSelectedCommitIndex == null || !array_key_exists($userSelectedCommitIndex, $commits)) {
                $this->echo("Invalid commit index selected (Tried to select $userSelectedCommitIndex) \n\n", "red");
                continue;
            }

            // If got this far then valid commit selected
            $validCommitSelected = true;
        }

        // Get the commit hash from the selected number
        return explode(' ', $commits[$userSelectedCommitIndex])[0];
    }

    private function runList(bool $autoConfirm = true)
    {
        // Prepare heading
        $this->echo($this->prepareHeading("LIST"));

        // Ask user to paste a list of files to upload, one per line, capture them all to store in array
        $this->echo("\nType or paste a list of line seperated files to upload, use empty line '' to complete proccess\n\n", 'yellow');

        // Build files array
        $files = [];

        while(true)
        {
            $file = $this->ask("File path", null);
            if($file == '') break;
            else if($file != null && $file != ''){
                if(substr($file, 0, 1) == 'D') continue;

                // Remove the git starting character if it exists, (M, A or D)
                if(substr($file, 0, 1) == 'M' || substr($file, 0, 1) == 'A' || substr($file, 0, 1) == 'D')
                {
                    $file = substr($file, 1);
                }

                // Swap backslashes for forward slashes
                $file = str_replace('\\', '/', $file);

                $files[] = trim($file);
            }
        }

        // Prepare commands for uploading all files
        $this->prepareNewCommands(function () use ($files) {
            $commands = [];

            foreach($files as $file) {
                $commands[] = function () use ($file) {
                    // IMPORTANT: If checking IGNORE then use $file->getRealPath() instead of $file->getFileInfo()

                    // Check and get SFTP connection (if not dry run and not already connected)
                    $this->createSFTPConnectionIfNotSet();

                    // Get full path
                    $fullPath = $this->environmentConfig->remoteBasePath . $file;

                    // Show command to user
                    if($this->dryRun) $this->echo("DRY RUN: ", "grey");
                    $this->echo("UPLOAD ".$this->color($file, "white")." -> ".$this->color($fullPath, "white"), 'green');

                    // If not dry run then run sftp command
                    if(!$this->dryRun)
                    {
                        $this->sftpUpload($file, $fullPath);
                    }
                    $this->echo("\n");
                };
            }

            return $commands;
        }, $autoConfirm);
    }

    private function remoteInteractiveShell($autoConfirm = true)
    {
        // Connect with SSH
        $this->dryRun = false;
        $this->createSFTPConnectionIfNotSet();
        $this->dryRun = true;

        // If $this->sftpConnection is null then bail
        if($this->sftpConnection == null)
        {
            return $this->returnWithMessage("SSH connection not set", "red");
        }

        // Set the cwd to the base laravel directory (remoteBasePath + applicationDirectory)
        $cwd = $this->environmentConfig->remoteBasePath . $this->environmentConfig->applicationDirectory;

        // Start the ssh2 shell stream and cd to the $cwd
        $stream = ssh2_shell($this->sftpConnection, 'vanilla', null, 200);
        fwrite($stream, "cd $cwd".PHP_EOL);
        flush();
        stream_set_blocking($stream, true);

        // If $shellResource is false then notify user and show the ssh error
        if($stream === false)
        {
            $sshError = error_get_last();
            $this->echo("SSH error: ".$sshError['message']."\n", "red");
        }

        // If $shellResource is not false then run the shell in a while loop until user types 'exit'
        while(true)
        {
            // Get user input
            $userInput = $this->ask($this->color("['exit' to return] ", 'grey').$this->color($cwd, 'green'), "", " >> ");

            // If user input is 'exit' then break out of loop
            if($userInput == 'exit') {
                break;
            }

            // Write the user input to the shell and execute with PHP_EOL (simulates enter key)
            flush();
            $endMarker = '__DH_END_OF_OUTPUT__'; // Generate a unique end marker and add it as a final command to echo
            $appendEchoEndMarkerCommand = '; echo "' . $endMarker . '"';
            fwrite($stream, $userInput . $appendEchoEndMarkerCommand . PHP_EOL);
            stream_set_blocking($stream, true);

            // Get the output from the shell 
            while($line = fgets($stream)) {
                flush();
                $trimmedLine = trim($line);

                // If the trimmed line is empty or "cd $cwd" then skip it
                if($trimmedLine == '' || $trimmedLine == "cd $cwd") {
                    continue;
                }

                // If the trimmed line starts with 'Last login: ' or the user input then skip it
                if(str_starts_with($trimmedLine, 'Last login: ') || str_starts_with($trimmedLine, $userInput)) {
                    // For now, debug this by outputting in red
                    // $this->echo($trimmedLine, 'red');
                    continue;
                }

                // If the line contains the linux [any chars]$ regex, then skip
                if(preg_match('/\[[^\]]+\]\$/', $trimmedLine)) {
                    // For now, debug this by outputting in yellow
                    // $this->echo($trimmedLine, 'yellow');
                    continue;
                }

                if (strpos($trimmedLine, $endMarker) !== false) {
                    // For now, debug this by outputting in green
                    // $this->echo($trimmedLine, 'green');
                    // We've found the end marker, break out of the loop
                    break;
                }
                
                // Echo the output to the user
                $this->echo($trimmedLine . PHP_EOL);
            }

            // Silently get the pwd to update the current working directory
            flush();
            fwrite($stream, "pwd" . $appendEchoEndMarkerCommand . PHP_EOL);
            stream_set_blocking($stream, true);

            // Get the output from the shell (only take the last non empty line)
            $findingNewCwd = '';
            while($line = fgets($stream)) {
                // If the line contains $appendEchoEndMarkerCommand then skip it, as this is the command we just ran being outputted back by the server
                if (strpos($line, $appendEchoEndMarkerCommand) !== false) {
                    continue;
                }

                if (strpos($line, $endMarker) !== false) {
                    // We've found the end marker, break out of the loop
                    break;
                }

                flush();
                if(trim($line) != '') {
                    $findingNewCwd = trim($line);
                }
            }

            // Update the current working directory
            $cwd = $findingNewCwd;
        }

        fclose($stream);

        // Return
        return;
    }

    private array $userSimulatedCommands = [];

    private function ask(string $question, mixed $default = null, string $preQuestionString = ': '): string | null
    {
        // Show question wrapped in yellow
        $this->echo("\n$question$preQuestionString", 'yellow');

        // Get user input
        if(count($this->userSimulatedCommands) == 0) {
            $read = [STDIN];
            $line = fgets(STDIN);
            unset($read);
        }
        else {
            $simulatedCommand = $this->runNextUserSimulatedCommand();
            return $simulatedCommand;
        }

        if (trim($line) != '') {
            return trim($line);
        }

        return $default;
    }

    private function askContinue(string $message = 'Continue?'): bool
    {
        $input = $this->ask($message.' (y/n)', 'n');
        return $input === 'y';
    }

    private function echo(string $text, string $color = null): void
    {
        if($this->supressOutput) return;

        if($color != null)
        {
            echo $this->color($text, $color);
        }
        else
        {
            echo $text;
        }
    }

    private function color(string $text, string $color): string
    {
        $color = match($color) {
            'white' => 37,
            'red' => 31,
            'green' => 32,
            'yellow' => 33,
            'blue' => 34,
            'magenta' => 35,
            'cyan' => 36,
            'grey' => 90,
            default => 37,
        };

        return "\033[1;".$color."m".$text."\033[0m";
    }

    private function returnWithMessage(string $message, $color = 'red', mixed $return = null)
    {
        $this->echo($message, $color);
        if($return !== null) return $return;
        return;
    }

    private function exitWithMessage(string $message, $color = 'red')
    {
        $this->echo($message."\n", $color);
        exit;
    }

    private function sftpUpload($localFile, $remoteFile)
    {
        // Check and get SFTP connection (if not dry run and not already connected)
        $this->createSFTPConnectionIfNotSet();

        if(is_dir($localFile))
        {
            // Check if directory already exists remotely
            $exists = @ssh2_sftp_stat($this->sftpObject, $remoteFile);

            if(!$exists)
            {
                // Create directory
                $success = ssh2_sftp_mkdir($this->sftpObject, $remoteFile, 0775, false);

                ($success)
                    ? $this->echo(" [CREATED DIRECTORY]", 'green')
                    : $this->echo(" [ERROR CREATING DIRECTORY]", 'red');
            }
            else
            {
                $this->echo(" [DIRECTORY EXISTS]", 'yellow');
            }

            return;
        }
        else
        {
            try
            {
                $success = ssh2_scp_send($this->sftpConnection, $localFile, $remoteFile, 0775);
            }
            catch(\Exception $e)
            {
                $success = false;
            }

            if($success)
            {
                $this->echo(" [OK]", 'green');
            }
            else
            {
                // Check if because of non existing directory
                $exists = @ssh2_sftp_stat($this->sftpObject, $remoteFile);

                if(!$exists)
                {
                    // Create directory and try again
                    $remoteDir = dirname($remoteFile);
                    $success = ssh2_sftp_mkdir($this->sftpObject, $remoteDir, 0775, true);
                    if($success)
                    {
                        $this->echo("\n[CREATED DIRECTORY ($remoteDir)]", 'green');
                        $this->echo("\nRetrying '".$localFile."'", 'white');
                        $this->sftpUpload($localFile, $remoteFile);
                        return;
                    }
                    else
                    {
                        $this->echo("\n[ERROR CREATING DIRECTORY ($remoteDir)]", 'red');
                    }
                    return;
                }

                $this->echo("ERROR: Could not upload file: " . $localFile, 'red');
            }
        }

    }

    private function sftpDelete($remoteFile)
    {
        // Check and get SFTP connection (if not dry run and not already connected)
        $this->createSFTPConnectionIfNotSet();

        // SFTP check if file exists
        $exists = @ssh2_sftp_stat($this->sftpObject, $remoteFile);
        if(!$exists || $exists['size'] == -1)
        {
            $this->echo(" [DOES NOT EXIST]", 'yellow');
            return;
        }

        // Check is not a directory
        if($exists['mode'] & 0040000)
        {
            $this->echo(" [CANNOT DELETE REMOTE DIRECTORY]", 'yellow');
            return;
        }

        // Unlink file
        $success = ssh2_sftp_unlink($this->sftpObject, $remoteFile);

        if($success)
        {
            $this->echo(" [OK]\n", 'green');
        }
        else
        {
            $this->echo("ERROR: Could not delete file: " . $remoteFile . "\n", 'red');
            exit();
        }
    }

    private function prepareNewCommands($commandSetterFunction, $autoConfirm = true)
    {
        // Reset the commandsCache array
        $this->commandsCache = [];

        // Check command setter function is a function
        if(!is_callable($commandSetterFunction)) {
            $this->exitWithMessage("ERROR: Command setter function is not callable.");
        }

        // Get commands from command setter function
        $readyToPassCommands = $commandSetterFunction();

        // If commands is not an array, explain and bail
        if(!is_array($readyToPassCommands)) {
            $this->exitWithMessage("ERROR: Command setter function did not return an array.");
        }

        // Get count of commands to run
        $commandCount = count($readyToPassCommands);

        // Get str padding length based on number of digits
        $strPaddingLength = strlen($commandCount);

        // If commands is empty, explain and bail
        if(count($readyToPassCommands) == 0) {
            $this->echo("No commands passed to add to queue.", "red");
            return [];
        }

        // Do a dry run and capture output and set commandsCache
        ob_start();
        $this->dryRun = true;
        for($i = 0; $i < count($readyToPassCommands); $i++) {
            $this->echo(str_pad(($i + 1).". ", $strPaddingLength + 2, " ", STR_PAD_RIGHT));
            $result = $readyToPassCommands[$i]();
            if($result != self::IGNORE_COMMAND) {
                $this->commandsCache[] = $readyToPassCommands[$i];
            }
        }
        $output = ob_get_contents();
        ob_end_clean();
        unset($temporaryCommands);

        // If commandsCache is empty, return empty array
        if(count($this->commandsCache) == 0) {
            $this->echo($output);

            $this->echo("No valid commands to add to queue\n", "red");
            return [];
        }

        // Show dry run output
        $this->echo($output);

        // If no auto confirm, first time through we do a dry run to show the user what commands will be added
        if(!$autoConfirm)
        {
            // Ask user for confirmation
            if (!$this->askContinue("Prepare above commands \033[1;32m(".count($this->commandsCache).")\033[0m?")) {
                $this->echo("Aborting\n", "red");
                return;
            }
        }

        // Append new commands to the end of the commandsToRun array
        foreach($this->commandsCache as $command) {
            $this->commandsToRun[] = $command;
        }

        return $this->commandsCache;
    }

    private function getOptionByKey($key): DeployHelperOption | bool
    {
        foreach($this->options as $option) {
            if(!($option instanceof DeployHelperOption)) {
                continue;
            }

            if($option->key == $key) {
                return $option;
            }
        }
        return false;
    }

    private function prepareHeading(string $heading): string
    {
        return $this->color("\n{$heading}\n--------------------------------------\n", "grey");
    }

    private function isIgnoredFile($file, $showMessage = true): bool
    {
        $file = $this->unix_path($file);

        foreach($this->environmentConfig->activeGitChangesIgnoreFiles as $ignoreFile) {
            // Allow for * as wildcards (fnmatch)
            if(fnmatch($ignoreFile, $file)) {
                $this->echo("IGNORING ".$file."\n", "grey");
                return true;
            }
        }
        return false;
    }

    private function interactivePathSelector($cwd = null, $mode = self::PATH_SELECTOR_MODE_FILE)
    {
        // Set current working directory
        $cwd = $cwd ?? dir($this->localBaseDirectory);

        // If cwd is a string, convert to directory object
        if(is_string($cwd)) {
            $cwd = dir($cwd);
        }

        // Show current directory
        $this->echo("\nDIR: ".$this->color($cwd->path, "white")."\n", "grey");

        $folders = [];
        $files = [];
        while (false !== ($entry = $cwd->read())) {
            // Always skip .
            if($entry == ".") continue;

            // If base directory, skip ..
            if($cwd->path == $this->localBaseDirectory && $entry == "..") continue;

            // Unix path
            $unixPath = $this->unix_path($cwd->path."/".$entry);

            // Add to path to appropriate array
            if(is_dir($unixPath)) {
                $folders[] = $unixPath;
            } else {
                $files[] = $unixPath;
            }
        }

        // Show folder and file count
        $this->echo(
            "FOLDERS: " . $this->color(count(array_filter($folders, function ($folder) {return substr($folder, -3) != "/..";})), "green")
            .", FILES: ".$this->color(count($files), "green")."\n", "grey"
        );
        $this->echo("--------------------------------------\n", "grey");

        // Sort folders and files
        sort($folders);
        sort($files);

        // Create empty array for folders and files
        $foldersAndFiles = [];

        // Order by folders first, then files
        $foldersAndFiles = $mode == self::PATH_SELECTOR_MODE_FOLDER ? $folders : array_merge($folders, $files);

        // Add additional options
        if($mode == self::PATH_SELECTOR_MODE_FOLDER) {
            $foldersAndFiles[":this"] = $this->color(">>", "white").$this->color(" Select this directory (:this or :select)", "grey");
            $foldersAndFiles[":show"] = $this->color(">>", "white").$this->color(" Show files in this directory (:show or :files)", "grey");
        }

        $foldersAndFiles[":exit"] = $this->color(">>", "white").$this->color(" Exit ".($mode == self::PATH_SELECTOR_MODE_FILE ? "file" : "folder")." selector (:exit)", "grey");

        // Show options
        $i = 0;
        foreach($foldersAndFiles as $key => $entry) {
            if(is_string($key)) {
                if($key == ":this"){
                    $this->echo("[".$this->color(":this", "blue")."] $entry\n", "grey");
                    continue;
                }elseif($key == ":show"){
                    $this->echo("[".$this->color(":show", "blue")."] $entry\n", "grey");
                    continue;
                }elseif($key == ":exit"){
                    $this->echo("[".$this->color(":exit", "blue")."] $entry\n", "grey");
                    continue;
                }
            }

            // Otherwise show file or folder
            $isDirectory = is_dir($entry);
            $showName = basename($entry).($isDirectory ? "/" : "");
            $this->echo("[".$this->color($i, "blue")."] ".$this->color($showName, $mode != self::PATH_SELECTOR_MODE_FOLDER && $isDirectory ? "grey" : "white")."\n", "grey");
            $i++;
        }

        // Ask user to select a file or folder
        $selectedFileOrFolder = $this->ask("Select a ".($mode == self::PATH_SELECTOR_MODE_FILE ? "file" : "folder"));

        // If user typed a string, try to find a match
        if(!is_numeric($selectedFileOrFolder)) {
            // If user typed exit command, exit
            if($selectedFileOrFolder == ":exit") {
                return;
            }

            // If directory mode, check if user typed select command
            if($mode == self::PATH_SELECTOR_MODE_FOLDER)
            {
                // If selected this directory, return current directory
                if($this->any_of_values([":this", ":select"], $selectedFileOrFolder)) {
                    return $this->unix_path($cwd->path);
                }

                // If selected show files, show list of files from $files array
                if($this->any_of_values([":show", ":files"], $selectedFileOrFolder)) {
                    $this->echo("\nFiles in (".$cwd->path."):\n", "grey");
                    $this->echo("--------------------------------------\n", "grey");
                    foreach($files as $file) {
                        $this->echo(basename($file)."\n", "white");
                    }
                    $this->wait(0.4);
                    return $this->interactivePathSelector($this->unix_path($cwd->path), $mode);
                }
            }

            // Get base names only to test against
            $foldersAndFilesBasenames = array_map(function($entry) {
                return basename($entry);
            }, $foldersAndFiles);

            // Search for match
            $selectedFileOrFolder = array_search(trim($selectedFileOrFolder ?? '', '/'), $foldersAndFilesBasenames, true);
        }

        // Check if valid selection
        if($selectedFileOrFolder === false || !isset($foldersAndFiles[$selectedFileOrFolder])) {
            $this->echo("Invalid selection\n", "red");
            return $this->interactivePathSelector($this->unix_path($cwd->path), $mode);
        }

        // If folder, change directory
        if(is_dir($foldersAndFiles[$selectedFileOrFolder])) {
            $realPath = $this->unix_path(realpath($foldersAndFiles[$selectedFileOrFolder]));
            $cwd = dir($realPath);
            return $this->interactivePathSelector($this->unix_path($cwd->path), $mode);
        }

        // Repeat option back to user and return
        $finalPath = $foldersAndFiles[$selectedFileOrFolder];
        $this->echo("You selected: ".$this->color($finalPath, "green")."\n", "grey");
        return $finalPath;
    }

    // Check and get SFTP connection
    private function createSFTPConnectionIfNotSet()
    {
        // If dry run or SFTP connection already exists, cancel and return
        if ($this->forceSFTPRefresh == false && ($this->dryRun || $this->sftpConnection != null)) {
            return;
        }

        $this->forceSFTPRefresh = false;

        $this->echo("Checking SFTP connection...\n", "grey");

        // Check ssh2_connect installed locally
        if(!function_exists('ssh2_connect')) {
            $this->echo("Error: ssh2_connect not installed locally", "red");

            // Suggest how to install on windows ()
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $this->echo("Suggest: Install php-ssh2 on windows: ", "red");
                $this->echo("http://pecl.php.net/package/ssh2", "white");
                $this->echo("Suggest: And / Or enable extension (php_ssh2.dll) in php.ini: ", "red");
                $this->echo(php_ini_loaded_file(), "white");
            }
            // Suggest how to install on linux
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'LIN') {
                $this->echo("Suggest: Install php-ssh2 on linux: ", "red");
                $this->echo("sudo apt-get install php-ssh2", "white");
            }
            // Suggest how to install on mac
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'DAR') {
                $this->echo("Suggest: Install php-ssh2 on mac: ", "red");
                $this->echo("brew install php-ssh2", "white");
            }

            $this->exitWithMessage("");
        }

        // Check if SFTP connection is valid
        try{
            $this->sftpConnection = ssh2_connect($this->environmentConfig->host, $this->environmentConfig->port);
        }catch(\Exception $e){
            $this->exitWithMessage("Error: Could not connect to SFTP server:".$e->getMessage());
        }

        if(!$this->sftpConnection || $this->sftpConnection == null) {
            $this->exitWithMessage("Error: Could not connect to SFTP server.");
        }

        // Check if SFTP login is valid
        if(!ssh2_auth_pubkey_file($this->sftpConnection, $this->environmentConfig->user, $this->environmentConfig->localPrivateKeyPath.'.pub', $this->environmentConfig->localPrivateKeyPath, $this->environmentConfig->pass)) {
            $this->exitWithMessage("Error: Could not login to SFTP server.");
        }

        // If connected and logged in, confirm to user and return SFTP connection
        $this->echo("Connected to SFTP server: " . $this->color($this->environmentConfig->host, 'white') . $this->color(", as user: ", 'green') . $this->color($this->environmentConfig->user, 'white') . "\n", "green");

        // Create SFTP object (for use with ssh2_sftp_* functions)
        $this->sftpObject = ssh2_sftp($this->sftpConnection);
    }

    private function verifyAndBuildConfig($jsonConfigFile, ?string $presetEnvironment = null): object
    {
        // Verify config file exists
        if(!file_exists($jsonConfigFile)) {
            $this->exitWithMessage($jsonConfigFile." config file not found. Make sure to install with `php artisan deploy:install`.\n");
        }

        // Parse config file
        try {
            $this->mainConfig = (object)json_decode(file_get_contents($this->jsonConfigFile));
        }catch(\Exception $e) {
            $this->exitWithMessage("Error parsing ".$jsonConfigFile." config file. Please check the file is valid JSON.\n");
        }

        // Each root key other than "commands" is equal to a config object, show the keys to the user so they can select which one to use
        $environmentConfig = $this->mainConfig->environments;
        $environmentConfigKeys = array_keys((array)$this->mainConfig->environments);

        // Set the number of config keys
        $this->numConfigKeys = count($environmentConfigKeys);

        // If there is only one, set the selectedConfigKey to 0
        if($this->numConfigKeys == 1) {
            $selectedConfigKey = $environmentConfigKeys[0];
        }
        // Otherwise ask user to select which config object to use
        else {
            if($presetEnvironment == null) {
                $this->echo("\nConfig file contains the following environments:\n", "grey");
                foreach($environmentConfigKeys as $environmentConfigKey) {
                    $this->echo(" - ".$environmentConfigKey."\n", "white");
                }

                $selectedConfigKey = $this->ask("Which environment would you like to use? (".implode(", ", $environmentConfigKeys).")", "grey");
            }
            else {
                $selectedConfigKey = trim($presetEnvironment);
            }
        }

        // Check if valid selection
        if(!isset($environmentConfig->{$selectedConfigKey})) {
            $this->echo("Invalid selection\n", "red");
            return $this->verifyAndBuildConfig($jsonConfigFile);
        }

        $this->environmentConfigKey = $selectedConfigKey;

        // Set config object to selected config object
        $environmentConfig = $environmentConfig->{$this->environmentConfigKey};

        // Verify config file has all required properties
        $requiredProperties = [
            "remoteBasePath",
            "localPrivateKeyPath",
            "host",
            "user",
            "pass",
            "port",
            "applicationDirectory",
            "buildDirectory",
            "activeGitChangesIgnoreFiles"
        ];

        foreach($requiredProperties as $requiredProperty) {
            if(!isset($environmentConfig->{$requiredProperty}) || $environmentConfig->{$requiredProperty} === "") {
                $this->exitWithMessage("Missing required property '".$requiredProperty."' in ".$jsonConfigFile." config file.\n");
            }elseif($requiredProperty === "activeGitChangesIgnoreFiles" && !is_array($environmentConfig->{$requiredProperty})) {
                $this->exitWithMessage("Property '".$requiredProperty."' in ".$jsonConfigFile." config file must be an array.\n");
            }
        }

        // Return config object
        return $environmentConfig;
    }

    private function showConfig()
    {
        // Find the largest key length in config
        $padLength = 0;
        foreach($this->environmentConfig as $key => $value) {
            if(strlen($key) > $padLength) {
                $padLength = strlen($key);
            }
        }

        // Loop through config properties and show to user
        foreach($this->environmentConfig as $key => $value) {
            $this->echo($this->color(str_pad($key, $padLength), "white")." => ", "grey");
            if(is_array($value)) {
                foreach($value as $arrayItem) {
                    $this->echo($arrayItem.", ", "white");
                }
                $this->echo("\n");
            }else{
                if ($key === "pass") {
                    $value = str_repeat("*", strlen($value));
                }
                $this->echo($value."\n", "white");
            }
        }
    }

    // Helper functions
    function any_in_array(array $needles, array $haystack): bool
    {
        foreach ($needles as $needle) {
            if (in_array($needle, $haystack)) {
                return true;
            }
        }
        return false;
    }

    function any_of_values(array $needles, mixed $value): bool
    {
        foreach ($needles as $needle) {
            if ($needle == $value) {
                return true;
            }
        }
        return false;
    }

    function wait(float $seconds): void
    {
        usleep($seconds * 1000000);
    }

    function unix_path(string $path): string
    {
        return str_replace("\\", "/", $path);
    }

    function remove_prefix(string $prefix, string $string): string
    {
        if (str_starts_with($string, $prefix)) {
            return substr($string, strlen($prefix));
        }

        return $string;
    }
}
