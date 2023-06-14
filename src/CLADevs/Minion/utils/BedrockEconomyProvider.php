<?php

declare(strict_types=1);

namespace CLADevs\Minion\utils;

use cooldogedev\BedrockEconomy\api\BedrockEconomyAPI;
use cooldogedev\BedrockEconomy\api\legacy\ClosureContext;
use pocketmine\player\Player;
use SOFe\AwaitGenerator\Await;

class BedrockEconomyProvider extends Economy
{

    /**
     * @return string
     */
    public function getPluginName(): string
    {
        return "BedrockEconomy";
    }

    /**
     * @param Player $player
     * @return void
     */
    public function getMoney(Player $player, \Closure $callback): void
    {
        BedrockEconomyAPI::legacy()->getPlayerBalance(
            $player->getName(),
            ClosureContext::create(
                function (?int $balance) use ($callback): void {
                    $callback($balance);
                },
            )
        );
    }

    /**
     * @param Player $player
     * @param int $amount
     * @return void
     */
    public function addMoney(Player $player, int $amount): void
    {
        BedrockEconomyAPI::legacy()->addToPlayerBalance(
            $player->getName(),
            $amount,
            ClosureContext::create(
                function (bool $wasUpdated): void {
                },
            )
        );
    }

    /**
     * @param Player $player
     * @param int $amount
     * @return void
     */
    public function reduceMoney(Player $player, int $amount): void
    {
        BedrockEconomyAPI::legacy()->subtractFromPlayerBalance(
            $player->getName(),
            $amount,
            ClosureContext::create(
                function (bool $wasUpdated): void {
                },
            )
        );
    }
}