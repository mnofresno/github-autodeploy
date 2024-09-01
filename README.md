# Git Auto Deployment Tool

[![Git Autodeploy CI](https://github.com/mnofresno/github-autodeploy/actions/workflows/push.yml/badge.svg)](https://github.com/mnofresno/github-autodeploy/actions/workflows/push.yml)

## Introduction

The Git Auto Deployment Tool facilitates seamless deployments from GitHub or GitLab repositories via webhooks. It automatically fetches changes, executes custom deployment commands, and keeps your projects up-to-date without manual intervention. This project is inspired by [this Gist](https://gist.github.com/nichtich/5290675).

Refer to the [original documentation](ORIGINAL.md) for more information.

## Features

- **Automatic deployment via webhooks** from GitHub or GitLab.
- **Configurable commands** to be executed before and after fetching updates.
- **Customizable deployment scripts** for each repository.
- **Integrated CI/CD pipeline** using GitHub Actions.
- **Comprehensive testing suite** to ensure stability and reliability.

## Installation

To install the Git Auto Deployment Tool, run the following command in your terminal:

```bash
sudo curl -sSL https://raw.githubusercontent.com/mnofresno/github-autodeploy/master/install.sh | bash -s <your-domain>
```

Replace `<your-domain>` with the domain you want to use for the deployment tool (e.g., `git-autodeploy.example.com`).

### What This Command Does

1. **Downloads the Installation Script**: Fetches `install.sh` directly from the GitHub repository.
2. **Executes the Installation Script**: Sets up the necessary Nginx configuration, checks prerequisites, downloads the tool, and configures it.

### Manual Installation

Alternatively, you can manually clone the repository and serve it through Nginx or Apache with PHP:

1. Clone the repository:

    ```bash
    git clone https://github.com/mnofresno/github-autodeploy.git
    cd github-autodeploy
    ```

2. Copy the example config file:

    ```bash
    cp config.example.json config.json
    ```

3. Serve the project with Nginx or Apache + PHP.

## Configuration Parameters

Configure parameters in the `config.json` file:

### IPsAllowList (array, mandatory)

List of IP addresses allowed to hit the endpoint.

### SSHKeysPath (string, mandatory)

Base path where the SSH keys are stored.

### ReposBasePath (string, mandatory)

Base path where the git repositories are stored.

### custom_commands (array|object, optional)

Commands executed to update and refresh a deployment. Commands can use placeholders with a format of a dollar sign followed by a string:

```json
{
    "ReposBasePath": "repos_with_code",
    "custom_commands": [
        "ls /var/www",
        "cd $ReposBasePath",
        "rm tempfile"
    ]
}
```

This means the triggered endpoint will `cd` into `/var/www/repos_with_code` using the `ReposBasePath` config parameter.

You can configure `custom_commands` with an object using valid "repo" query parameters as keys to define different command sets for each repository:

```json
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
}
```

## Available Placeholders

| Placeholder       | Description                                   |
|-------------------|-----------------------------------------------|
| ReposBasePath     | The `ReposBasePath` config parameter value    |
| SSHKeysPath       | The `SSHKeysPath` config parameter value      |
| repo              | The value of the 'repo' query parameter       |
| key               | The value of the 'key' query parameter        |

If `custom_commands` is not specified, a default list of commands is executed to update the projects:

```
cd $ReposBasePath
echo $PWD
whoami
GIT_SSH_COMMAND="ssh -i $SSHKeysPath/$key" git fetch origin
git reset --hard origin/$(git symbolic-ref --short HEAD)
```

## Customizing Deployment with `.git-auto-deploy.yml`

The `.git-auto-deploy.yml` file customizes the deployment process for each repository. It defines commands to execute at different stages, providing granular control over the deployment workflow.

### Structure of `.git-auto-deploy.yml`

The file is structured with two main sections:

- **`pre_fetch_commands`**: Executed **before** fetching changes from the repository.
- **`post_fetch_commands`**: Executed **after** fetching changes from the repository.

### Example `.git-auto-deploy.yml`

```yaml
---
pre_fetch_commands:
    - echo "Preparing environment for deployment..."
    - echo ${{ secrets.github_ghcr_token }}
    - 'echo "Repository base path is $ReposBasePath"'
    - echo ${{ secrets.github_ghcr_username }}
post_fetch_commands:
    - composer install
    - ./auto-update/create_revision.sh
    - ./build_apk.sh
    - 'echo "Successfully upgraded to the last version: $(cat auto-update/public/revision.js)"'
    - echo $SSHKeysPath
    - echo $secrets.github_ghcr_username
```

### Explanation

1. **`pre_fetch_commands`**:
   - Commands executed before fetching changes from the repository.
   - Examples: Preparing the environment, outputting a secret token, and printing configuration parameters.

2. **`post_fetch_commands`**:
   - Commands executed after changes have been fetched.
   - Examples: Installing dependencies, building artifacts, outputting deployment results.

### Using Placeholders in Commands

Placeholders in `.git-auto-deploy.yml` are replaced at runtime with values from the request, configuration, or secrets:

| Placeholder                          | Description                                                    |
|--------------------------------------|----------------------------------------------------------------|
| `{{ repo }}`                         | The 'repo' query parameter value                               |
| `{{ key }}`                          | The 'key' query parameter value                                |
| `{{ ReposBasePath }}`                | The `ReposBasePath` config parameter value                     |
| `{{ SSHKeysPath }}`                  | The `SSHKeysPath` config parameter value                       |
| `{{ Secrets.<secret_key> }}`         | Secret values from configuration, masked with `***` in logs    |

#### Example with Secret Handling and Config Placeholders

```yaml
---
pre_fetch_commands:
    - echo ${{ secrets.github_ghcr_token }}
    - echo ${{ secrets.github_ghcr_username }}
    - echo $SSHKeysPath
    - echo $secrets.github_ghcr_username
post_fetch_commands:
    - composer install
    - echo "Deployment for $repo has been successfully completed!"
```

## CI/CD Pipeline

This project uses GitHub Actions for continuous integration and deployment:

- **Build and Run Tests**:
  - Runs on any push or pull request.
  - Validates `composer.json` and `composer.lock`.
  - Caches Composer dependencies.
  - Installs dependencies and runs tests using `composer run-script test`.

- **PHP Linting**:
  - Uses a custom linter script (`linter/lint.sh`) in dry-run mode to detect potential issues.

- **Deployment**:
  - Deploys to the server only if tests and linting pass successfully.
  - Uses environment variables for deployment URL and key (`AUTODEPLOY_URL` and `KEY_FILE_FOR_DEPLOY`).

## Usage

Use the `trigger_for_repo` script in the `bin/` directory to trigger deployments manually:

```bash
./bin/trigger_for_repo <repo_name> <key_name>
```

Replace `<repo_name>` and `<key_name>` with the appropriate values.

## Testing

To run the tests, install Composer dependencies and execute the following:

```bash
composer run-script test
```

### Test Suite

The `test/` directory contains various tests for the Git Auto Deployment Tool:

- **`CustomCommandsTest.php`**: Tests placeholder replacement and command generation.
- **`ExecuterTest.php`**: Tests the execution of deployment commands.
- **`RequestTest.php`**: Validates request handling and parameters.
- **`RunnerTest.php`**: Ensures the main runner functions correctly.
- **`DeployConfigReaderTest.php`**: Validates configuration file parsing.
- **`IPAllowListManagerTest.php`**: Tests IP allowlist functionality.
- **`LoggerTest.php`**: Ensures logging is handled correctly.
- **`SecurityTest.php`**: Tests security-related aspects.

## Contributing

Contributions are welcome! Please raise an issue to discuss potential changes or fork the repository and submit a pull request.

## Thanks

Thanks to the GitHub user [@nichtich](https://github.com/nichtich) for the inspirational gist this project was based on.
