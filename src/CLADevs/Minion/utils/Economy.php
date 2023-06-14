<?php

declare(strict_types=1);

namespace CLADevs\Minion\utils;

use pocketmine\player\Player;

abstract class Economy
{

    abstract public function getPluginName(): string;

    abstract public function getMoney(Player $player, \Closure $callback): void;

    abstract public function addMoney(Player $player, int $amount): void;

    abstract public function reduceMoney(Player $player, int $amount): void;
}