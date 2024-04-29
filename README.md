# Deploy Helper
---
## -- Installation --
### 1. Require with composer
```
composer require webregulate/deploy-helper --dev
```

### 2. IMPORTANT: Add the following to your .gitignore file
```
deployhelper*
```

### 3. Add this script to your composer.json
```
"scripts": {
    "deploy": "php ./vendor/webregulate/deploy-helper/deployhelper.php"
}
```

### 4. Run the install script
```
php ./vendor/webregulate/deploy-helper/install.php
```
This will create the default `deployhelper.json` file in the root of your project.

### 5. Setting up deployhelper.json
```
{
    "devserver": {
        "remoteBasePath": "/home/unixuser/",
        "localPrivateKeyPath": "/local/path/to/private/key",
        "host": "example.com",
        "user": "unixuser",
        "pass": "unixpassword",
        "port": 22,

        "applicationDirectory": "laravel_application",
        "buildDirectory": "public_html/build",
        "activeGitChangesIgnoreFiles": ["*deployhelper*", "*.htaccess", "*.env", "*rundev.bat"]
    },
    "production": {
        "remoteBasePath": "/home/unixuser2/",
        "localPrivateKeyPath": "/local/path/to/private/key",
        "host": "example2.com",
        "user": "unixuser2",
        "pass": "unixpassword2",
        "port": 22,

        "applicationDirectory": "laravel_application",
        "buildDirectory": "public_html/build",
        "activeGitChangesIgnoreFiles": ["*deployhelper*", "*.htaccess", "*.env", "*rundev.bat"]
    }
}
```
Note you can add or remove any of the groups (such as the devserver and production groups above) based on your setup. Only one is required.

---
## -- Running DeployHelper --

### Run with php
```
composer run deploy
```

#### More help coming soon...
