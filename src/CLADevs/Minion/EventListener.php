<?php

declare(strict_types=1);

namespace CLADevs\Minion;

use CLADevs\Minion\minion\Minion;
use pocketmine\entity\Location;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\Server;
use pocketmine\nbt\tag\{StringTag, CompoundTag};
use pocketmine\event\entity\EntityDeathEvent;
use pocketmine\event\entity\EntityMotionEvent;

class EventListener implements Listener{

    /**
     * @throws \JsonException
     */
    public function onInteract(PlayerInteractEvent $e): void{
        $player = $e->getPlayer();
        $name = Main::get()->checkName($player);
        $item = $e->getItem();
        $dNBT = $item->getNamedTag();
        if(!$e->isCancelled()){
            if($dNBT->getTag("summon") !== null){
                if(($dNBT->getTag("player", StringTag::class)->getValue() == $name) || Server::getInstance()->isOp($player->getName())){
                    $pos = $player->getPosition()->add(0, 0.5, 0);
                    $nbt = CompoundTag::create();
                    $nbt->setString("player", $dNBT->getTag("player")->getValue());
                    if(Main::get()->hasPer(Main::SELL, $player)){ $nbt->setString("SELL", "yes");} else $nbt->setString("SELL", "no");
                    if(Main::get()->hasPer(Main::DOUBLE_CHEST, $player)) $nbt->setString("DOUBLE_CHEST", "yes");
                    if(Main::get()->hasPer(Main::SELL, $player)){ $nbt->setString("ORE", "yes");} else $nbt->setString("ORE", "no");
                    $skinTag = $player->getSkin();
                    assert($skinTag !== null);
                    $entity = new Minion(
                        Location::fromObject(
                            $player->getPosition(),
                            $player->getWorld()),
                        Main::get()->getSkin(),
                        $nbt
                    );
                    $entity->spawnToAll();
                    $entity->setOwner($player);
                    $item->setCount($item->getCount() - 1);
                    $player->getInventory()->setItemInHand($item);
                }else $player->sendMessage("§l§e•>§c Minion này không thuộc quyền sở hửu của bạn, hãy trả lại nó");            
            }            
        }

    }

    public function onDeath(EntityDeathEvent $event) :void {
        $entity = $event->getEntity();
        if($entity instanceof Minion){
            $owner = $entity->getOwner();
            $inv = $owner->getInventory();
            $item = Main::get()->getItem($owner);
            if($inv->canAddItem($item)){
                $inv->addItem($item);
            }
        }
    }

    public function onEntityMotion(EntityMotionEvent $event): void {
        $entity = $event->getEntity();
        if ($entity instanceof Minion) {
            $event->cancel();
        }
    }
}
