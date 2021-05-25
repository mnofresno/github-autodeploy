<?php

namespace GithubAutoDeploy\views;

class Forbidden implements ViewInterface {
    function render() {
        echo "<span style=\"color: #ff0000\">Sorry, no hamster - better convince your parents!</span>\n";
    }
}
