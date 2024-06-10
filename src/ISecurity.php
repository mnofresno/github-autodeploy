<?php

namespace Mariano\GitAutoDeploy;

interface ISecurity {
    public function setParams(...$params): self;
    public function assert(): void;
}
