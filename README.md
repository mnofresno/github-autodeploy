# Git Auto Deployment Tool

[![Git Autodeploy CI](https://github.com/mnofresno/github-autodeploy/actions/workflows/push.yml/badge.svg)](https://github.com/mnofresno/github-autodeploy/actions/workflows/push.yml)

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

### custom_commands (array|object, optional)

Is the list of commands that are needed to be executed in order to update and refresh a deployment (it could include for instance a docker-compose restart service command)

This commands may make use of the following placeholders with the format of a dollar sign followed by a string.

For example, if you have this config in your config.js file as a collection of commands:

```
{
    "ReposBasePath": "repos_with_code",
    "custom_commands": [
        "ls /var/www",
        "cd $ReposBasePath",
        "rm tempfile"
    ]
}
```

That means that the triggered endpoint will cd into the /var/www/repos_with_code at the 2nd command given the ReposBasePath config parameter that is set.

Additionally you could configure the custom commands parameter with an object using valid "repo" query parameter as keys, this allows to use a different set of commands for every repository:

```
{
    "ReposBasePath": "repos_with_code",
    "custom_commands": {
        "example-repo1": [
            "ls /var/www",
            "cd $ReposBasePath",
            "rm tempfile"
        ],
        "example-repo2": [
            "other",
            "set",
            "of commands"
        ],
        "_default_": [
            "default",
            "commands to run"
        ]
}
```
So, when you request the endpoint with the query parameter localhost?repo=example-repo1, you will run the first set of commands and if you trigger the hook with localhost?repo=example-repo2 query param, you will run "other, set, of commands".

And if you pass a value for repo query param localhost?repo=other-repo that is not in available as a key of custom_commands config param, the _default_ key will be used as the list of commands: "default, commands to run".

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
GIT_SSH_COMMAND="ssh -i $SSHKeysPath/$key git fetch origin
git reset --hard origin/$(git symbolic-ref --short HEAD)
```

## Contributing

To run the tests you must first install composer dependencies, and then run the following composer script:

```
composer run-script test
```

For any suggestion please write me or raise an issue on this repo.

## Thanks:

To the github user @nichtich for uploading the inpirational gist this project was based on.
