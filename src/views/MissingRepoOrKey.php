<?php

namespace GithubAutoDeploy\views;

class MissingRepoOrKey implements ViewInterface {
    function render() {
        echo "<span style=\"color: #ff0000\">Error reading hook no repo or key passed</span>\n";
    }
}
