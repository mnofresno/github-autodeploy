<?php

spl_autoload_register(function ($class_name) {
    $class_name = str_replace('GithubAutoDeploy', '', $class_name);
    $class = str_replace('\\', DIRECTORY_SEPARATOR, $class_name) . '.php';
	require_once(__DIR__ . DIRECTORY_SEPARATOR . $class);
});
