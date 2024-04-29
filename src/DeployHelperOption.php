<?php

namespace WebRegulate\DeployHelper;

// DeployHelperOption class, used to define menu options and their actions within the DeployHelper class
class DeployHelperOption
{
    public string $key;
    public string $description;
    public mixed $run;

    public function __construct(string $key, string $description, callable $run)
    {
        $this->key = $key;
        $this->description = $description;
        $this->run = $run;
    }
}