<?php

namespace Zencart\ModuleSupport;

interface ModuleContract
{
    public function __construct();
    public function check(): bool;
    public function install();
    public function keys(): array;
    public function remove();

}
