# Git Auto Deployment Tool

## Introduction:

This project was inspired in: [This Gist](https://gist.github.com/nichtich/5290675)

[Original Documentation](ORIGINAL.md)

This project is meant to be used as a Github/Gitlab web hook endpoint.

## Installation:

Clone or copy the repository and serve it trough nginx or apache + php

Copy the example config file

```
cp config.example.json config.json
```

## Configuration parameters

The config parameters are json keys in file config.json

### IPsAllowList (array, mandatory)

List of IP addresses that are allowed to hit the endpoint

### SSHKeysPath (string, mandatory)

Base path where the SSH keys are stored

### ReposBasePath (string, mandatory)

Base path where the git repositories are stored

### CustomCommands (array, optional)

Is the list of commands that are needed to be executed in order to update and refresh a deployment (it could include for instance a docker-compose restart service command)

This commands may make use of the following placeholders with the format of a dollar sign followed by a string.

For example, if you have this config in your config.js file:

```
{
    "ReposBasePath": "repos_with_code",
    "CustomCommands": [
        "ls /var/www",
        "cd $ReposBasePath",
        "rm tempfile"
    ]
}
```

That means that the triggered endpoint will cd into the /var/www/repos_with_code at the 2nd command given the ReposBasePath config parameter that is set.

The placeholders options available by now are these:


| Placeholder       | Description                                   |
|-------------------|-----------------------------------------------|
| ReposBasePath     | Is the config parameter with same name        |
| SSHKeysPath       | Is the config parameter with same name        |
| repo              | Is the value of the 'repo' query param given  |
| key               | Is the value of the 'key' query param given   |

This parameter is optional because there's a list of commands that are executed by default to update the projects. This list is as following:

```
cd $ReposBasePath
echo $PWD
whoami
GIT_SSH_COMMAND="ssh -i $SSHKeysPath/$key git pull
git status
git submodule sync
git submodule update
git submodule status
```


## Thanks:

To the github user @nichtich for uploading the inpirational gist this project was based on.
