<?php

namespace GithubAutoDeploy\views;

class Forbidden implements ViewInterface {
    function render() {
        header('HTTP/1.1 403 Forbidden');
        echo "<span style=\"color: #ff0000\">Sorry, no hamster - better convince your parents!</span>\n";
    }
}
