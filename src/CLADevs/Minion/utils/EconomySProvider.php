<?php

declare(strict_types=1);

namespace CLADevs\Minion\utils;

use onebone\economyapi\EconomyAPI;
use pocketmine\player\Player;

class EconomySProvider extends Economy
{

    /**
     * @return string
     */
    public function getPluginName(): string
    {
        return "EconomyAPI";
    }

    /**
     * @param Player $player
     * @return void
     */
    public function getMoney(Player $player, \Closure $callback): void
    {
        $callback(EconomyAPI::getInstance()->myMoney($player));
    }

    /**
     * @param Player $player
     * @param int $amount
     * @return void
     */
    public function addMoney(Player $player, int $amount): void
    {
        EconomyAPI::getInstance()->addMoney($player, $amount);
    }

    /**
     * @param Player $player
     * @param int $amount
     * @return void
     */
    public function reduceMoney(Player $player, int $amount): void
    {
        EconomyAPI::getInstance()->reduceMoney($player, $amount);
    }
}