<?php

class Autoloader {
    const NAMESPACE = 'GithubAutoDeploy';
    const SRC_PATH = 'src';

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
            $require_file_path = implode(
                DIRECTORY_SEPARATOR, [
                    __DIR__,
                    self::SRC_PATH,
                    $class
                ]
            );
            if (file_exists($require_file_path)) {
                require_once($require_file_path);
                return true;
            }
            return false;
        });
    }
}
