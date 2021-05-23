# Deploy your site with git

This gist assumes:

* you have an online remote repository (github / bitbucket etc.)
* you have a local git repo
* and a cloud server (Rackspace cloud / Amazon EC2 etc)
  * your (PHP) scripts are served from /var/www/html/
  * your webpages are executed by Apache
  * the Apache user is named `www-data` (may be `apache` on other systems)
  * apache's home directory is /var/www/ 

# 1 - On your local machine

*Here we add the deployment script and push it to the origin, the deployment script runs git commands to PULL from the origin thus updating your server*

## Grab a deployment script for your site

See [deploy.php](#file-deploy-php)

## Add, commit and push this to github

    git add deploy.php
    git commit -m 'Added the git deployment script'
    git push -u origin master

# 2 - On your server

*Here we install and setup git on the server, we also create an SSH key so the server can talk to the origin without using passwords etc*

## Install git...

After you've installed git, make sure it's a relatively new version - old scripts quickly become problematic as github / bitbucket / whatever will have the latests and greatest, if you don't have a recent version you'll need to figure out how to upgrade it :-)

    git --version

## Setup git (optionally)

    git config --global user.name "Server"
    git config --global user.email "server@server.com"

## Create an ssh directory for the apache user

    sudo mkdir /var/www/.ssh
    sudo chown -R apache:apache /var/www/.ssh/

## Generate a deploy key for apache user

    sudo -Hu apache ssh-keygen -t rsa # choose "no passphrase"
    sudo cat /var/www/.ssh/id_rsa.pub

# 3 - On your origin (github)

*Here we add the SSH key to the origin to allow your server to talk without passwords. In the case of GitHub we also setup a post-receive hook which will automatically call the deploy URL thus triggering a PULL request from the server to the origin*

## GitHub instructions

### Add the SSH key to your user

1. https://github.com/settings/ssh
1. Create a new key
1. Paste the deploy key you generated on the server

### Set up service hook

1. https://github.com/oodavid/server.com/admin/hooks
1. Select the **Post-Receive URL** service hook
1. Enter the URL to your deployment script - http://server.com/deploy.php
1. Click **Update Settings**

## Bitbucket instructions

### Add the SSH key to your account

1. https://bitbucket.org/account/ssh-keys/
1. Create a new key
1. Paste the deploy key you generated on the server

### Set up service hook

1. Go to: Repo > Admin > Services
1. Select "POST"
1. Add the URL to your deployment script - http://server.com/deploy.php
1. Save

# 4 - On the Server

*Here we clone the origin repo into a chmodded /var/www/html folder*

## Pull from origin

    sudo chown -R www-data:www-data /var/www/html
    sudo -Hu www-data git clone git@github.com:you/server.git /var/www/html

# Rejoice!

Now you're ready to go :-)

## Some notes

* Navigate the the deployment script to trigger a pull and see the output:
  * http://server.com/deploy.php
  * ***this is useful for debugging too ;-)***
 * When you push to GitHub your site will automatically ping the above url (and pull your code)
 * When you push to Bitbucket you will need to manually ping the above url

## Sources
 * https://gist.github.com/1809044 who in turn referenced
   * [Build auto-deploy with php and git(hub) on an EC2 Amazon AMI instance](https://gist.github.com/1105010) - who in turn referenced:
     * [ec2-webapp / INSTALL.md](https://github.com/rsms/ec2-webapp/blob/master/INSTALL.md#readme)
     * [How to deploy your code from GitHub automatically](http://writing.markchristian.org/how-to-deploy-your-code-from-github-automatic)