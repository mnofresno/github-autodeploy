<?php

namespace Mariano\GitAutoDeploy\Test;

use DI\Container;
use Mariano\GitAutoDeploy\ContainerProvider;

trait ContainerAwareTrait {
    private $container;

    protected function set($id, $object): void {
        $this->getContainer()->set($id, $object);
    }

    protected function get($id) {
        return $this->getContainer()->get($id);
    }

    private function getContainer(): Container {
        if (!$this->container) {
            $provider = new ContainerProvider();
            return $this->container = $provider->provide();
        }
        return $this->container;
    }
}
