<?php

class Autoloader {
    const NAMESPACE = 'GithubAutoDeploy';

    static function load() {
        spl_autoload_register(function ($class_name) {
            $class_name_without_root_ns = str_replace(
                self::NAMESPACE . '\\',
                '',
                $class_name
            );
            $class = str_replace(
                '\\',
                DIRECTORY_SEPARATOR,
                $class_name_without_root_ns
            ) . '.php';
            $require_file_path = __DIR__ . DIRECTORY_SEPARATOR . $class;
            require_once($require_file_path);
            return true;
        });
    }
}
