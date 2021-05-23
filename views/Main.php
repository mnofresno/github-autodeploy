<?php

namespace GithubAutoDeploy\views;

class Main {
    static function render() {
        $TITLE   = 'Git Deployment Hamster';
        $VERSION = '0.11';
        echo <<<EOT
<!DOCTYPE HTML>
<html lang="en-US">
<head>
    <meta charset="UTF-8">
    <title>$TITLE</title>
</head>
<body style="background-color: #000000; color: #FFFFFF; font-weight: bold; padding: 0 10px;">
<pre>
  o-o    $TITLE
 /\\"/\   v$VERSION
(`=*=')
 ^---^`-.
EOT;
    }
}
