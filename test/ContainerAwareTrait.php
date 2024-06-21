<?php

namespace Mariano\GitAutoDeploy\Test;

use DI\Container;
use Mariano\GitAutoDeploy\ContainerProvider;

trait ContainerAwareTrait {
    protected function set($id, $object): void {
        $this->getContainer()->set($id, $object);
    }

    protected function get($id) {
        return $this->getContainer()->get($id);
    }

    private function getContainer(): Container {
        $provider = new ContainerProvider();
        return $provider->provide();

    }
}
