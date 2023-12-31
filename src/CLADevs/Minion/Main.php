<?php

declare(strict_types=1);

namespace CLADevs\Minion;

use CLADevs\Minion\database\Database;
use CLADevs\Minion\database\Vault;
use CLADevs\Minion\minion\Minion;
use CLADevs\Minion\utils\BedrockEconomyProvider;
use CLADevs\Minion\utils\Economy;
use CLADevs\Minion\utils\EconomySProvider;
use muqsit\invmenu\InvMenuHandler;
use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\console\ConsoleCommandSender;
use pocketmine\entity\EntityDataHelper;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\Human;
use pocketmine\entity\Skin;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\item\ItemBlock;
use pocketmine\item\Pickaxe;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\TextFormat as C;
use pocketmine\world\World;
use Vecnavium\FormsUI\CustomForm;
use Vecnavium\FormsUI\SimpleForm;

class Main extends PluginBase{

    use SingletonTrait;

    CONST SELL = 0;
    CONST ORE = 1;

    private Database $database;
    private Config $sell;
    private ?Economy $EconomyAPI;

    public function onLoad(): void{
        self::setInstance($this);
    }

    public function onEnable(): void{
        $this->initVirions();
        $this->initEconomy();
        if ($this->EconomyAPI === null) {
            $this->getLogger()->error("Invalid economy provider selected. Please select a valid economy provider.");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }
        $this->createDatabase();
        $this->saveResource("img/geometry.json");
        $this->saveResource("img/Minion.png");
        $this->saveResource("sell.yml");
        $this->sell = new Config($this->getDataFolder() . "sell.yml", Config::YAML);
        $this->getServer()->getPluginManager()->registerEvents(new EventListener(), $this);
        Vault::setNameFormat((string) $this->getConfig()->get("inventory-name"));
        EntityFactory::getInstance()->register(Minion::class, function(World $world, CompoundTag $nbt) : Minion{
            return new Minion(EntityDataHelper::parseLocation($nbt, $world), Human::parseSkinNBT($nbt), $nbt);
        }, ['Minion', 'minecraft:minion']);
    }

    public function initEconomy(): void{
        $this->EconomyAPI = match(intval($this->getConfig()->get("economy"))) {
            1 => new BedrockEconomyProvider(),
            2 => new EconomySProvider(),
            default => null
        };
    }

    public function getDatabase() : Database{
        return $this->database;
    }

    public function onDisable() : void{
        $this->getDatabase()->close();
    }

    private function initVirions() : void{
        if(!InvMenuHandler::isRegistered()){
            InvMenuHandler::register($this);
        }
    }

    private function createDatabase() : void{
        $this->saveDefaultConfig();
        $this->database = new Database($this, $this->getConfig()->get("database"));
        Vault::setNameFormat((string) $this->getConfig()->get("inventory-name"));
    }

    public static function get(): self{
        return self::$instance;
    }

    public function sell(Player $player, Inventory $tile): void
    {
        $items = $tile->getContents();
        $prices = 0;
        /** @var Item|ItemBlock $item */
        foreach($items as $item){
            if ($item instanceof ItemBlock) {
                $item = $item->getBlock()->asItem();
            }
            if($this->sell->get($item->getName()) !== null && $this->sell->get($item->getName()) > 0){
                $price = $this->sell->get($item->getName()) * $item->getCount();
                $this->EconomyAPI->addMoney($player, $price);
                $prices = $prices + $price;
                $tile->remove($item);
            }
        }
        $player->sendMessage("§l§e•> §6Minion sold everything and got :§a $prices $");
    }

    /**
     * @throws \JsonException
     */
    public function getSkin(){
        $files = glob($this->getDataFolder() . "img" . DIRECTORY_SEPARATOR . "*.png");
        foreach($files as $file){
             $fileName = pathinfo($file, PATHINFO_FILENAME);
            $path = $this->getDataFolder() . "img" . DIRECTORY_SEPARATOR . "$fileName.png";
            $img = @imagecreatefrompng($path);
            $skinBytes = "";
            $s = (int)@getimagesize($path)[1];
            for($y = 0; $y < $s; $y++){
                for($x = 0; $x < 64; $x++){
                    $colorat = @imagecolorat($img, $x, $y);
                    $a = ((~((int)($colorat >> 24))) << 1) & 0xff;
                    $r = ($colorat >> 16) & 0xff;
                    $g = ($colorat >> 8) & 0xff;
                    $b = $colorat & 0xff;
                    $skinBytes .= chr($r) . chr($g) . chr($b) . chr($a);
                }
            }
            @imagedestroy($img);
            return new Skin("Standard_CustomSlim", $skinBytes, "", "geometry.$fileName", file_get_contents($this->getDataFolder() . "img" . DIRECTORY_SEPARATOR ."geometry.json"));
        }
    }
    
    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool{
     
            if(!$sender->hasPermission("minion.commands")){
                $sender->sendMessage(C::RED . "§cYou don't have permission to run this command.");
                return false;
            }
            if($sender instanceof ConsoleCommandSender){
                if(!isset($args[0])){
                    $sender->sendMessage("§aUsage: /minion <player>");
                    return false;
                }
                if(!$p = $this->getServer()->getPlayerByPrefix($args[0])){
                    $sender->sendMessage(C::RED . "§cThat player could not be found.");
                    return false;
                }
                if($p->getInventory()->canAddItem($this->getItem($p))){
                    $p->sendMessage("§l§e•> §6Bạn đã mua thành công 1 Minion. Vui lòng tự bảo quản, nếu mất server không chịu trách nhiệm.");
                    $this->giveItem($p);
                      $sender->sendMessage("§6Đã đưa Minion cho: ". $p->getName());                                        
                } else $sender->sendMessage($p->getName(). " inventory is full");
                return false;
            }elseif($sender instanceof Player){
                if(isset($args[0])){
                    if(!$p = $this->getServer()->getPlayerByPrefix($args[0])){
                        $sender->sendMessage(C::RED . "That player could not be found.");
                        return false;
                    }
                    $this->giveItem($p);
                    return false;
                }
                $this->giveItem($sender);
                return false;
            }
        
        return true;
    }

    public function giveItem(Player $sender): void{
        $sender->getInventory()->addItem($this->getItem($sender));
    }

    public function getItem(Player $sender, int $level = 1): Item{
        $item = VanillaItems::EGG();
        $item->setCustomName(C::BOLD . C::BLUE . "§dTrứng Minion");
        $item->setLore(
            ["§l§eHãy đặt tôi xuống đất tôi sẽ giúp bạn đào khoáng sản.",
            "§l§cLưu Ý: Minion là do bạn tự bảo quản, khi xảy ra mất, sẽ không giải quyết"]
        );
        $nbt = $item->getNamedTag();
        $nbt->setString("summon", "miner");
        $nbt->setString("player", $sender->getName());
        $item->setNamedTag($nbt);
        return $item;
    }

    public function sendStaffForm(Player $player, string $owner, Minion $minion) :void {
        $form = new SimpleForm(function(Player $player, $data) use ($minion, $owner){
            if(is_null($data)) return;
            if($data === 0) $this->flagEntity($player, $minion);
            if($data === 1) $this->sendMinionInv($player, $minion, $owner);
            if($data === 2) $this->setItemToMinion($player, $minion); 
        });
        $form->setTitle("§l§6Minion");
        $form->addButton("§l§f●§0 Lấy lại minion §f●", 1, "https://img.icons8.com/officel/2x/delete-sign.png");
        $form->addButton("§l§f●§0 Túi đồ §f●", 1, "https://cdn.iconscout.com/icon/free/png-512/backpack-50-129941.png");
        $form->addButton("§l§f●§0 Cúp §f●", 1, "https://art.pixilart.com/a1318a07386930f.png");
        $form->sendToPlayer($player);        
    }

    public function sendForm($player, $minion): void
    {
        $form = new SimpleForm(function(Player $player, $data) use ($minion){
            if(is_null($data)) return;
            if($data === 0) $this->flagEntity($player, $minion);
            if($data === 1) $this->sendMinionInv($player, $minion);
            if($data === 2) $this->setItemToMinion($player, $minion); 
            if($data === 3) $this->upgradeMinion($player, $minion);
        });
        $form->setTitle("§l§6Minion");
        $form->addButton("§l§e●§9 Lấy lại Minion §e●", 1, "https://img.icons8.com/officel/2x/delete-sign.png");
        $form->addButton("§l§e●§9 Túi đồ §e●", 1, "https://cdn.iconscout.com/icon/free/png-512/backpack-50-129941.png");
        $form->addButton("§l§e●§9 Cúp §e●", 1, "https://art.pixilart.com/a1318a07386930f.png");
        $form->addButton("§l§e●§9 Nâng cấp minion §e●", 1, "https://cdn0.iconfinder.com/data/icons/round-arrows-1/134/Up_blue-512.png");
        $form->sendToPlayer($player);
    }

    public function upgradeMinion(Player $player, Minion $minion) : void{
        $this->EconomyAPI->getMoney($player, function ($money) use ($minion, $player) {
            $form = new SimpleForm(function(Player $player, ?int $data) use ($minion, $money){
                if(is_null($data)){
                    $this->sendForm($player, $minion);
                    return;
                }
                $form = new CustomForm(function(Player $player, ?array $data){});
                if($minion->getPropertyMinion($data)){
                    $form->addLabel("§l§cYou have already purchased this upgrade.");
                    $form->sendToPlayer($player);
                }else{
                    if ($money >= 5000) {
                        $minion->setPropertyMinion($data, true);
                        $this->EconomyAPI->reduceMoney($player, 5000);
                        $form->addLabel("§l§aUpgrade Minion success");
                        $form->sendToPlayer($player);
                    } else {
                        $form->addLabel("§l§cYou don't have enough money.");
                        $form->sendToPlayer($player);
                    }
                }
            });
            $form->setTitle("§l§6Upgrade Minion");
            $form->setContent("§l§fMoney:§e ". $money);
            $form->addButton("§l§e●§9 Auto Sell §e●\n§l§b「§c 5000$ 」");
            $form->addButton("§l§e●§9 Auto Smelt §e●\n§l§b「§c 5000$ 」");
            //$form->addButton("Mở rộng túi đồ\n(50 LCoin)");
            $form->sendToPlayer($player);
        });
    }

    public function sendMinionInv(Player $player, Minion $minion, $owner = null): void
    {
        if($owner !== null){
            $this->getDatabase()->loadVault($owner, 1, function(Vault $vault) use($player): void{
                $vault->send($player);
            });            
        }else{
            $this->getDatabase()->loadVault($player->getName(), 1, function(Vault $vault) use($player): void{
                $vault->send($player);
            });       
        }
    }

    public function setMoveType($player, $minion): void
    {
        $form = new CustomForm(function(Player $player, $data) use ($minion){
            if(is_null($data)) return;
            if($data[1]){
                $minion->setMove(true);

            }else $minion->setMove(false);
        });
        $form->setTitle("§l§eMinion");
        $form->addLabel("");
        $form->addToggle("§l§0「 Bật/tắt di chuyển 」", $minion->getMoveType());
        $form->sendToPlayer($player);
    }

    public function flagEntity(Player $player, Minion $entity): void
    {
        $hand = $entity->getInventory()->getItemInHand();
        if(!$hand->equals(VanillaItems::AIR())){
            $player->sendMessage("§r§cHãy lấy lại cúp trước khi thu hồi minion.");
            return;
        }
        $item = $this->getItem($player);
        $properties = [
            Main::SELL => "SELL",
            Main::ORE => "ORE"
        ];
        foreach ($properties as $key => $value) {
            if ($entity->getPropertyMinion($key)) $item->getNamedTag()->setString($value, "true");
        }
        if($player->getInventory()->canAddItem($item)){
            $block = $entity->getLookingBlock();
            $entity->setDelay(1);
            $entity->flagForDespawn();
            $player->sendMessage("§r§aMinion đã được lấy lại.");
            if(!$block->isSameState(VanillaBlocks::AIR())) $this->stopBreakAnimation($block);
            $player->getInventory()->addItem($item);
        }else{
           $player->sendMessage("§r§cTúi đồ của bạn đã đầy.");
        }
    }

    public function setItemToMinion(Player $player, Minion $minion): void
    {
        $form = new SimpleForm(function(Player $player, $data) use ($minion){
            if($data === 0){
                $handMinion = $minion->getInventory()->getItemInHand();
                $handPlayer = $player->getInventory()->getItemInHand();
                if(!$handPlayer instanceof Pickaxe){
                    $player->sendMessage("§6§l● §r§aMinion cần dụng cụ.");
                    return;
                }
                $minion->getInventory()->setItemInHand($handPlayer);
                $minion->setSkin($this->getSkin());
                $player->getInventory()->setItemInHand($handMinion);
                $block = $minion->getLookingBlock();
                if($block->isSameState(VanillaBlocks::AIR())) $this->stopBreakAnimation($block);
                $minion->setDelay(1);
                $block = $minion->getLookingBlock();
                $this->stopBreakAnimation($block);
            }
            if($data === 1){
                $handMinion = $minion->getInventory()->getItemInHand();
                if(!$handMinion->equals(VanillaItems::AIR())) {
                    if($player->getInventory()->canAddItem($handMinion)){
                        $player->getInventory()->addItem($handMinion);
                        $minion->getInventory()->setItemInHand(VanillaItems::AIR());
                        $minion->setSkin($this->getSkin());
                        $block = $minion->getLookingBlock();
                        $minion->setDelay(1);
                        if($block->isSameState(VanillaBlocks::AIR())) $this->stopBreakAnimation($block);
                    }else $player->sendMessage("§6§l● §r§cTúi đồ của bạn đầy.");
                }
            }
        });
        $form->setTitle("§l§6Quản Lí Minion");
        $handItem = $minion->getInventory()->getItemInHand();
        $contents = "";
        if(($enchantments = $this->getAllEnchantments($handItem)) !== null){
            $content = [];
            foreach ($enchantments as $enchantment) {
                $name = $enchantment->getType()->getName();
                $level = $enchantment->getLevel();
                $content[] = "$name : $level";
            }
            $contents = implode("\n", $content);
        }
        $form->setContent("§c§l↣ §eMinion đang cầm item: ". $handItem->getName(). "\n". $contents);
        $form->addButton("§l§f●§0 Cho minion mượn cúp §f●");
        $form->addButton("§l§f●§0 Lấy lại cúp §f●");
        $form->sendToPlayer($player);
    }

    public function getAllEnchantments($item) :?array {
        if($item->hasEnchantments()){
            $enchantments = [];
            foreach($item->getEnchantments() as $enchantment){
                $enchantments[] = $enchantment;
            }
            return $enchantments;
        }
        return null;
    }

    public function checkName($player){
        $name = $player->getName();
        if($this->hasSpaces($name)){
            $name = str_replace(" ", "_", $name);
        }
        return $name;
    }

    private function hasSpaces(string $string): bool{
        return $string === trim($string) && str_contains($string, ' ');
    }

    public function stopBreakAnimation(Block $block) :void {
        $pos = $block->getPosition();
        $pos->getWorld()->broadcastPacketToViewers($pos, LevelEventPacket::create(
             LevelEvent::BLOCK_STOP_BREAK,
            0,
            $pos
        ));
    }

    public function hasPer(int $type, Player $player) :bool{
        return ($player->hasPermission("minion.".$type));
    }
}
