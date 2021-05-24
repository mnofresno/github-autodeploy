<?php

namespace GithubAutoDeploy\views;

class MissingRepoOrKey implements ViewInterface {
    function render() {
        header('HTTP/1.1 422 Invalid Hook');
        echo "<span style=\"color: #ff0000\">Error reading hook no repo or key passed</span>\n";
    }
}
