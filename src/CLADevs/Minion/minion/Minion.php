<?php

declare(strict_types=1);

namespace CLADevs\Minion\minion;

use CLADevs\Minion\Main;
use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\EnchantmentIdMap;
use pocketmine\entity\Human;
use pocketmine\entity\Location;
use pocketmine\entity\Skin;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\ToolTier;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\{LevelEventPacket, AnimatePacket, types\LevelEvent};
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\utils\TextFormat as C;

use pocketmine\item\Pickaxe;
use pocketmine\item\Axe;
use pocketmine\item\TieredTool;

use pocketmine\inventory\Inventory;
use CLADevs\Minion\database\Vault;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\world\particle\BlockBreakParticle;

class Minion extends Human implements MinionInterface{

    private const CHEST_SIZE = 0;
    private const DOUBLE_CHEST_SIZE = 1;

    protected string $minionName = "";
    protected float $breakTime = 0.0;
    protected ?Inventory $inv;

    private string $player;
    private int $time = 0;
    private mixed $delay = null;
    private array $ores = [];

    protected bool $is_sell = false;
    protected bool $is_ore = false;

    private mixed $check = null;

    public function __construct(Location $location, Skin $skin, ?CompoundTag $nbt = null)
    {
        parent::__construct($location, $skin, $nbt);
    }

    public function initEntity(CompoundTag $nbt): void{
        parent::initEntity($nbt);
        $this->ores = [
            VanillaBlocks::IRON_ORE()->getName() => VanillaItems::IRON_INGOT(),
            VanillaBlocks::DEEPSLATE_IRON_ORE()->getName() => VanillaItems::IRON_INGOT(),
            VanillaBlocks::GOLD_ORE()->getName() => VanillaItems::GOLD_INGOT(),
            VanillaBlocks::DEEPSLATE_GOLD_ORE()->getName() => VanillaItems::GOLD_INGOT(),
            VanillaBlocks::ANCIENT_DEBRIS()->getName() => VanillaItems::NETHERITE_SCRAP()
        ];
        $this->player = $nbt->getString("player");
        $this->minionName = "§l§eMINION\n" ."§f(§a" .$this->player."§f)";
        $this->setSkin($this->getSkin());
        $this->setHealth(1);
        $this->setMaxHealth(1);
        $this->setNameTagAlwaysVisible();
        $this->setNameTag($this->minionName);
        $this->setScale(0.7);
        if ($nbt->getString("SELL") == "yes"){
            $this->is_sell = true;
        }
        if ($nbt->getString("ORE") == "yes"){
            $this->is_ore = true;
        }
    }

    public function getPropertyMinion(int $type): bool{
        $extractType = match($type){
            Main::SELL => "is_sell",
            Main::ORE => "is_ore"
        };
        return $this->$extractType;
    }

    public function setPropertyMinion(int $type, bool $value): void {
        match($type){
            Main::SELL => $this->is_sell = $value,
            Main::ORE => $this->is_ore = $value
        };
    }

    public function saveNBT(): CompoundTag{
        $nbt = parent::saveNBT();
        $nbt->setString("player", $this->player);
        $nbt->setString("SELL", $this->is_sell ? "yes" : "no");
        $nbt->setString("ORE", $this->is_ore ? "yes" : "no");
        return $nbt;
    }

    public function attack(EntityDamageEvent $source): void{
        $source->cancel();;
        if($source instanceof EntityDamageByEntityEvent){
            $attacker = $source->getDamager();
            if($attacker instanceof Player){
                $name = Main::get()->checkName($attacker);
                if($name !== $this->player){
                    if(!$attacker->hasPermission("minion.open.others")){
                        $attacker->sendMessage(C::RED . "Đây không phải Minion của bạn.");
                        return;
                    }
                    Main::get()->sendStaffForm($attacker, $this->player, $this);
                    return;
                }
                Main::get()->sendForm($attacker, $this);
            }
        }
    }

    public function setOwner(Player $player) :void{
        $this->player = $player->getName();
    }

    public function setDelay(int $sc): void
    {
        $this->delay = $sc;
    }

    public function getMineInv() : ?Inventory{
        $inv = null;
        Main::get()->getDatabase()->loadVault($this->player, 1, function(Vault $vault) use (&$inv){
            $inv = $vault->getInventory();
        });
        return $inv;
    }

    public function onBreakBlock() :void{
        if (
            $this->getLookingBlock()->getName() !== VanillaBlocks::AIR()->getName() and
            $this->getLookingBlock()->isSolid()
        ){
            $block = $this->getLookingBlock();
            $breakTime = $block->getBreakInfo()->getBreakTime($this->getInventory()->getItemInHand()) * 20;
            if(ceil($breakTime) > 0){
                foreach (Server::getInstance()->getOnlinePlayers() as $p) {
                    $p->getNetworkSession()->sendDataPacket(AnimatePacket::create(
                        actorRuntimeId: $this->getId(),
                        actionId: AnimatePacket::ACTION_SWING_ARM
                    ));
                }
                $breakTime = ceil($breakTime);
                $pk = LevelEventPacket::create(
                    eventId: LevelEvent::BLOCK_START_BREAK,
                    eventData: (int)round(65535 / $breakTime),
                    position: $block->getPosition()
                );
                foreach (Server::getInstance()->getOnlinePlayers() as $p) {
                    $p->getNetworkSession()->sendDataPacket($pk);
                }
                if($this->breakTime == 0){
                    $this->breakTime = ceil($breakTime);
                    $breakTime = ceil($block->getBreakInfo()->getBreakTime($this->getInventory()->getItemInHand()) * 20);
                    $pk = LevelEventPacket::create(
                        eventId: LevelEvent::BLOCK_START_BREAK,
                        eventData: (int)round(65535 / $breakTime),
                        position: $block->getPosition()
                    );
                    foreach (Server::getInstance()->getOnlinePlayers() as $p) {
                        $p->getNetworkSession()->sendDataPacket($pk);
                    }
                }
                if(--$this->breakTime <= 0){
                    $this->breakBlock($block);
                    $this->getWorld()->addParticle($block->getPosition()->add(0.5, 0.5, 0.5), new BlockBreakParticle($block));
                    $this->breakTime = 0;
                    $hand = $this->getInventory()->getItemInHand();
                    if($hand->hasEnchantment(EnchantmentIdMap::getInstance()->fromId(15))){
                        if($hand->getEnchantment(EnchantmentIdMap::getInstance()->fromId(15))->getLevel() > 5){
                            $this->setDelay(5);
                        }
                    }
                    $this->setDelay(3);
                }
            }
        }
    }

    public function entityBaseTick(int $tickDiff = 1): bool{
        $update = parent::entityBaseTick($tickDiff);
        if($this->delay !== null){
            if(--$this->delay <= 0){
                $this->delay = null;
            }
        }else {
            if($this->getOwner() !== null){
                $this->onBreakBlock();
            }
        }
        if(floor($this->getPosition()->getY()) < 0){
            if(!is_null($owner = $this->getOwner())){
                if(--$this->time <= 0){
                    $spawn = Main::get()->getItem($owner);
                    if($this->check == null){
                        if($owner->getInventory()->canAddItem($spawn)){
                           Main::get()->flagEntity($owner, $this);  
                        }else{
                            $owner->sendMessage("§l§e•>§6 Túi của bạn đã đầy, Minion không thể quay trở lại vào túi");
                            $owner->sendMessage("§l§e•>§6 Hãy cất bớt vật phẩm"); 
                            $this->check = true;
                        }                    
                    }else{
                        if($owner->getInventory()->canAddItem($spawn)){
                           Main::get()->flagEntity($owner, $this); 
                           $this->check = null;
                        }else{
                            $owner->sendMessage("§l§e•>§6 Túi của bạn đã đầy, Minion không thể quay trở lại vào túi");
                            $owner->sendMessage("§l§e•>§6 Hãy cất bớt vật phẩm");                            
                            $this->check = true;
                        } 
                    }
                    $this->time = 360;                       
                } 
            }
        }
        return $update;
    }

    public function getLookingBlock(): Block{
        $block = VanillaBlocks::AIR();
        switch($this->getDirection()){
            case 0:
                $block = $this->getWorld()->getBlock($this->getPosition()->add(1, 0, 0));
                break;
            case 1:
                $block = $this->getWorld()->getBlock($this->getPosition()->add(0, 0, 1));
                break;
            case 2:
                $block = $this->getWorld()->getBlock($this->getPosition()->add(-1, 0, 0));
                break;
            case 3:
                $block = $this->getWorld()->getBlock($this->getPosition()->add(0, 0, -1));
                break;
        }
        return $block;
    }

    public function getDirection() : ?int{
        $rotation = fmod($this->getLocation()->getYaw() - 90, 360);
        if($rotation < 0){
            $rotation += 360.0;
        }
        if((0 <= $rotation and $rotation < 45) or (315 <= $rotation and $rotation < 360)){
            return 2; //North
        }elseif(45 <= $rotation and $rotation < 135){
            return 3; //East
        }elseif(135 <= $rotation and $rotation < 225){
            return 0; //South
        }elseif(225 <= $rotation and $rotation < 315){
            return 1; //West
        }else{
            return null;
        }
    }

    public function getLookingBehind(): Block{
        $block = VanillaBlocks::AIR();
        switch($this->getDirection()){
            case 0:
                $block = $this->getWorld()->getBlock($this->getPosition()->add(-1, 0, 0));
                break;
            case 1:
                $block = $this->getWorld()->getBlock($this->getPosition()->add(0, 0, -1));
                break;
            case 2:
                $block = $this->getWorld()->getBlock($this->getPosition()->add(1, 0, 0));
                break;
            case 3:
                $block = $this->getWorld()->getBlock($this->getPosition()->add(0, 0, 1));
                break;
        }
        return $block;
    }

    public function breakBlock(Block $block): void{
        $inv = $this->getMineInv();
        $item = $block->asItem();
        if(isset($this->ores[$block->getName()]) && $this->is_ore){
            $item = $this->ores[$block->getName()];
        }
        if(!empty($inv)){
            if(!$inv->canAddItem($item) and $this->is_sell){
                 Main::get()->sell($this->getOwner(), $inv);
            } else $inv->addItem($item);

        }
        $this->getWorld()->setBlock($block->getPosition(), VanillaBlocks::AIR(), true, true);
    }

    public function getOwner():?Player{
        return Server::getInstance()->getPlayerByPrefix($this->player);
    }

    private function increaseDrops(array $drops, int $amount = 1): array
    {
        $newDrops = [];
        foreach($drops as $drop){
            $newDrops[] = $drop->setCount(1 + $amount);
        }
        return $newDrops;
    }

    public function isFortune($item) :bool{
        $inv = $this->getMineInv();
        $block = $this->getLookingBlock();
        $item = $this->getInventory()->getItemInHand();
        $fortuneEnchantment = $item->getEnchantment(EnchantmentIdMap::getInstance()->fromId(18));
        if($fortuneEnchantment instanceof EnchantmentInstance){
            $level = $fortuneEnchantment->getLevel() + 1;
            $rand = rand(1, $level);
            $drops = [];
            if($item instanceof TieredTool){
                switch($block->getName()){
                    case VanillaBlocks::COAL_ORE()->getName():
                        if($item instanceof Pickaxe){
                            $drops[] = $this->increaseDrops($block->getDrops($item), $rand);
                        }
                        break;
                    case VanillaBlocks::LAPIS_LAZULI_ORE()->getName():
                        if($item instanceof Pickaxe && $item->getTier() > ToolTier::WOOD()){
                            $drops[] = $this->increaseDrops($block->getDrops($item), rand(0, 4) + $rand);
                        }
                        break;
                    case VanillaBlocks::REDSTONE_ORE()->getName():
                        if($item instanceof Pickaxe && $item->getTier() > ToolTier::WOOD()){
                            $drops[] = $this->increaseDrops($block->getDrops($item), rand(1, 2) + $rand);
                        }
                        break;
                    case VanillaBlocks::NETHER_QUARTZ_ORE()->getName():
                        if($item instanceof Pickaxe && $item->getTier() > ToolTier::WOOD()){
                            $drops[] = $this->increaseDrops($block->getDrops($item), rand(0, 1) + $rand);
                        }
                        break;
                    case VanillaBlocks::EMERALD_ORE()->getName():
                    case VanillaBlocks::DIAMOND_ORE()->getName():
                        if($item instanceof Pickaxe && $item->getTier() >= ToolTier::IRON()){
                            $drops[] = $this->increaseDrops($block->getDrops($item), $rand);
                        }
                        break;
                    case VanillaBlocks::CARROTS()->getName():
                    case VanillaBlocks::POTATOES()->getName():
                    case VanillaBlocks::BEETROOTS()->getName():
                    case VanillaBlocks::WHEAT()->getName():
                        if($item instanceof Axe || $item instanceof Pickaxe){
                            if($block->getDamage() >= 7){
                                $drops[] = $this->increaseDrops($block->getDrops($item), rand(1, 3) + $rand);
                            }
                        }
                        break;
                    case VanillaBlocks::MELON()->getName():
                        if($item instanceof Axe || $item instanceof Pickaxe){
                           $drops[] = $this->increaseDrops($block->getDrops($item), rand(3, 9) + $rand);
                        }
                        break;
                    case VanillaBlocks::OAK_LEAVES()->getName():
                        if(rand(1, 100) <= 10 + $level * 2){
                           $inv->addItem(VanillaItems::APPLE());
                        }
                        break;
                        default:
                        unset($drops);
                        $drops = VanillaBlocks::STONE();
                        break;
                }
            }
            if(is_array($drops)){
                foreach($drops as $items){
                    if(is_array($items)){
                        foreach($items as $item){
                         $inv->addItem($item);   
                        }  
                    }else $inv->addItem($items);
                }
            }else return false;
            return true;
        }
        return false;
    }
}
