<?php

declare(strict_types=1);

namespace CLADevs\Minion\database;

final class VaultAccess{

    public function __construct(
        /** @readonly */ public Vault $vault
    ){}

    public function release() : void{
        $this->vault->release($this);
    }

    /**
     * @internal
     */
    public function _destroy() : void{
        unset($this->vault);
    }
}