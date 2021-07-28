<?php

namespace DarkGames98\SimpleBoss;

use pocketmine\{
	Player,
	plugin\PluginBase,
	event\Listener,
	entity\Skin,
	command\ConsoleCommandSender,
	entity\Entity,
	level\Level,
	nbt\tag\CompoundTag,
	nbt\tag\FloatTag,
	scheduler\Task,
	nbt\tag\StringTag,
	utils\TextFormat,
	entity\Human,
	network\mcpe\protocol\PlayerListPacket,
	network\mcpe\protocol\AddPlayerPacket,
	network\mcpe\protocol\ActorEventPacket,
	network\mcpe\protocol\types\PlayerListEntry,
	network\mcpe\protocol\types\SkinData,
	network\mcpe\protocol\types\SkinImage,
	network\mcpe\protocol\types\inventory\ItemStackWrapper,
	Server,
	utils\UUID,
	timings\Timings,
	item\enchantment\Enchantment, 
	item\enchantment\EnchantmentInstance,
	math\Vector3,
	event\entity\EntityDamageByEntityEvent,
	event\entity\EntityDamageEvent,
	item\Item,
	math\AxisAlignedBB,
	block\Slab,
	block\Stair,
	block\Flowable,
	block\Liquid,
	event\entity\EntityDeathEvent,
	event\player\PlayerMoveEvent,
};
class Main extends PluginBase implements Listener
{
	private static $instance;
	public $spawnedEntity = false;
	public $entitiesAll = [];
	
	public function onLoad(){
		self::$instance = $this;
	}
	
	public static function getInstance():self{
		return self::$instance;
	}
	
	public function onMove(PlayerMoveEvent $e): void{
		$player = $e->getPlayer();
		$entities = $player->getLevel()->getEntities();
		foreach($entities as $entity){
			if(!$entity instanceof Player){
				if($this->spawnedEntity instanceof Vindicator){
					if($this->spawnedEntity->getId() !== $entity->getId()){
						$entity->flagForDespawn();
						return;
					}
				}
				if($entity instanceof Vindicator){
					$this->spawnedEntity = $entity;
				}
			}
		}
	}
	
	public function onEnable(){
		$this->saveDefaultConfig();
		$this->saveResource("vindicator.png");
		Entity::registerEntity(Vindicator::class, true);
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->getScheduler()->scheduleRepeatingTask(new SpawnBoss($this, $this->getConfig()->get("spawn_time")), 20);
	}
	
	public function onDisable(){
		if($this->spawnedEntity instanceof Vindicator && $this->spawnedEntity->isClosed() === false){
			var_dump("Boss Removed");
			$this->spawnedEntity->flagForDespawn();
		}
		foreach($this->entitiesAll as $entities) if($entities instanceof Vindicator && $entities->isClosed() === false){
			var_dump("All Saved Boss Removed");
			$entities->flagForDespawn();
		}
    }
	
	public function onReward(EntityDeathEvent $event):void{
		if($event->getEntity() instanceof Vindicator){
			$cause = $event->getEntity()->getLastDamageCause();
			if($cause instanceof EntityDamageByEntityEvent) {
				$player = $cause->getDamager();                    
				if($player instanceof Player) {
					foreach($this->getDrops() as $items){
						$player->getInventory()->canAddItem($items) ? $player->getInventory()->addItem($items) : $player->getLevel()->dropItem($player, $items);
					}
					if($this->getConfig()->exists("commands")){
						foreach($this->getConfig()->get("commands") as $cmd){
							$this->getServer()->dispatchCommand(new ConsoleCommandSender(), str_replace("{player}", '"' . $player->getName() . '"', $cmd));
						}
					}
					$this->getServer()->broadcastMessage(str_replace("{player}", $player->getName(), $this->getConfig()->get("boss-end")));
					$this->getScheduler()->scheduleRepeatingTask(new SpawnBoss($this, $this->getConfig()->get("spawn_time")), 20);
				}
			}
		}
	}
	
	public function getDrops():array{
		$items = [];
		foreach($this->getConfig()->get("reward") as $data){
			$item = Item::get($data["id"], $data["meta"] ?? 0, $data["count"] ?? 1);
			if(isset($data["name"])) $item->setCustomName($data["name"]);
			if(isset($data["lore"])) $item->setLore($data["lore"]);
			if(isset($data["enchantment"])) foreach($data["enchantment"] as $enchantment) $item->addEnchantment(new EnchantmentInstance(Enchantment::getEnchantment($enchantment["id"]), $enchantment["level"]));
			$items[] = $item;
		}
		return $items;
	}
	
	public function getSkin(string $fileName) : ?Skin{
        $path = $this->getDataFolder() .  $fileName . ".png";
        if(!is_file($path)){
            return null;
        }
        $img = @imagecreatefrompng($path);
        $bytes = '';
        $l = (int) @getimagesize($path)[1];
        for ($y = 0; $y < $l; $y++) {
            for ($x = 0; $x < 64; $x++) {
                $rgba = @imagecolorat($img, $x, $y);
                $a = ((~((int)($rgba >> 24))) << 1) & 0xff;
                $r = ($rgba >> 16) & 0xff;
                $g = ($rgba >> 8) & 0xff;
                $b = $rgba & 0xff;
                $bytes .= chr($r) . chr($g) . chr($b) . chr($a);
            }
        }
        @imagedestroy($img);
        return new Skin("Standard_CustomSlim", $bytes);
    }
	
	public function toSkinData(Skin $skin) : SkinData{
		$capeData = $skin->getCapeData();
		$capeImage = $capeData === "" ? new SkinImage(0, 0, "") : new SkinImage(32, 64, $capeData);
		$geometryName = $skin->getGeometryName();
		if($geometryName === ""){
			$geometryName = "geometry.humanoid.custom";
		}
		return new SkinData(
			$skin->getSkinId(),
			"", //TODO: playfab ID
			json_encode(["geometry" => ["default" => $geometryName]]),
			SkinImage::fromLegacy($skin->getSkinData()), [],
			$capeImage,
			$skin->getGeometryData()
		);
	}
}
class SpawnBoss extends Task
{
	private $main;
	private $cooldown;
	
	public function __construct(Main $main, int $cooldown){
		$this->main = $main;
		$this->cooldown = $cooldown;
	}
	
	public function onRun(int $currentTask){
		if(in_array($this->cooldown, $this->main->getConfig()->get("alert-cooldown"))){
			$this->main->getServer()->broadcastMessage(str_replace("{time}", $this->cooldown, $this->main->getConfig()->get("alert-message")));
		}
		if($this->cooldown <= 0){
			$x = $this->main->getConfig()->get("position")["x"];
			$y = $this->main->getConfig()->get("position")["y"];
			$z = $this->main->getConfig()->get("position")["z"];
			$level = $this->main->getConfig()->get("position")["level"];
			$level = $this->main->getServer()->getLevelByName($level);
			if($level === null){
				$this->main->getScheduler()->cancelTask($this->getTaskId());
				return;
			}
			$level->loadChunk($x, $z);
			try{
				$nbt = Entity::createBaseNBT(new Vector3($x, $y + 1.5, $z));
				$npc = new Vindicator($level, $nbt);
				$npc->setNametag(Main::getInstance()->getConfig()->get("boss-name"));
				$npc->setScoreTag(TextFormat::RED . Main::getInstance()->getConfig()->get("health") . " ❤");
				$npc->setNameTagAlwaysVisible(true);
				$npc->spawnToAll();
				$this->main->entitiesAll[] = $npc;
				$this->main->spawnedEntity = $npc;
				$this->main->getScheduler()->cancelTask($this->getTaskId());
				$this->main->getServer()->broadcastMessage($this->main->getConfig()->get("boss-spawned"));
			}catch(\InvalidStateException $err){}
		}
		$this->cooldown--;
	}
}
class Boss extends Human{

	const FIND_DISTANCE = 15;
	const LOSE_DISTANCE = 25;

	public $target = "";
	public $findNewTargetTicks = 0;
	public $randomPosition = null;
	public $findNewPositionTicks = 200;
	public $jumpTicks = 5;
	public $attackWait = 20;
	public $attackDamage = 4;
	public $speed = 0.50;
	public $startingHealth = 20;
	public $assisting = [];

	public function __construct(Level $level, CompoundTag $nbt){
		parent::__construct($level, $nbt);
		$this->setMaxHealth($this->startingHealth);
		$this->setHealth($this->startingHealth);
		#$this->setNametag($this->getNametag());
		$this->generateRandomPosition();
		$this->attackWait = Main::getInstance()->getConfig()->get("attack_delayed") * 20;
	}

	public function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if(!$this->isAlive()){
			if(!$this->closed) $this->flagForDespawn();
			return false;
		}
		$this->setNametag(Main::getInstance()->getConfig()->get("boss-name"));
		$this->setScoreTag(TextFormat::RED . $this->getHealth() . " ❤");
		if($this->hasTarget()){
			return $this->attackTarget();
		}
		if($this->findNewTargetTicks > 0){
			$this->findNewTargetTicks--;
		}
		if(!$this->hasTarget() && $this->findNewTargetTicks === 0){
			$this->findNewTarget();
		}
		if($this->jumpTicks > 0){
			$this->jumpTicks--;
		}
		if($this->findNewPositionTicks > 0){
			$this->findNewPositionTicks--;
		}
		if(!$this->isOnGround()){
			if($this->motion->y > -$this->gravity * 4){
				$this->motion->y = -$this->gravity * 4;
			}else{
				$this->motion->y += $this->isUnderwater() ? $this->gravity : -$this->gravity;
			}
		}else{
			$this->motion->y -= $this->gravity;
		}
		$this->move($this->motion->x, $this->motion->y, $this->motion->z);
		if($this->shouldJump()){
			$this->jump();
		}
		if($this->atRandomPosition() || $this->findNewPositionTicks === 0){
			$this->generateRandomPosition();
			$this->findNewPositionTicks = 200;
			return true;
		}
		$position = $this->getRandomPosition();
		$x = $position->x - $this->getX();
		$y = $position->y - $this->getY();
		$z = $position->z - $this->getZ();
		if($x * $x + $z * $z < 4 + $this->getScale()) {
			$this->motion->x = 0;
			$this->motion->z = 0;
		} else {
			$this->motion->x = $this->getSpeed() * 0.15 * ($x / (abs($x) + abs($z)));
			$this->motion->z = $this->getSpeed() * 0.15 * ($z / (abs($x) + abs($z)));
		}
		$this->yaw = rad2deg(atan2(-$x, $z));
		$this->pitch = 0;
		$this->move($this->motion->x, $this->motion->y, $this->motion->z);
		if($this->shouldJump()){
			$this->jump();
		}
		$this->updateMovement();
		return $this->isAlive();
	}
	
	public function attackTarget(){
		$target = $this->getTarget();
		if($target == null || $target->distance($this) >= self::LOSE_DISTANCE){
			$this->target = null;
			return true;
		}
		if($this->jumpTicks > 0) {
			$this->jumpTicks--;
		}
		if(!$this->isOnGround()) {
			if($this->motion->y > -$this->gravity * 4){
				$this->motion->y = -$this->gravity * 4;
			}else{
				$this->motion->y += $this->isUnderwater() ? $this->gravity : -$this->gravity;
			}
		}else{
			$this->motion->y -= $this->gravity;
		}
		$this->move($this->motion->x, $this->motion->y, $this->motion->z);
		if($this->shouldJump()){
			$this->jump();
		}
		$x = $target->x - $this->x;
		$y = $target->y - $this->y;
		$z = $target->z - $this->z;
		if($x * $x + $z * $z < 1.2){
			$this->motion->x = 0;
			$this->motion->z = 0;
		} else {
			$this->motion->x = $this->getSpeed() * 0.15 * ($x / (abs($x) + abs($z)));
			$this->motion->z = $this->getSpeed() * 0.15 * ($z / (abs($x) + abs($z)));
		}
		$this->yaw = rad2deg(atan2(-$x, $z));
		$this->pitch = rad2deg(-atan2($y, sqrt($x * $x + $z * $z)));
		$this->move($this->motion->x, $this->motion->y, $this->motion->z);
		if($this->shouldJump()){
			$this->jump();
		}
		if($this->distance($target) <= $this->getScale() + 0.3 && $this->attackWait <= 0){
			$event = new EntityDamageByEntityEvent($this, $target, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $this->getBaseAttackDamage());
			$this->broadcastEntityEvent(4);
			$target->attack($event);
			$this->attackWait = Main::getInstance()->getConfig()->get("attack_delayed") * 20;
		}
		$this->updateMovement();
		$this->attackWait--;
		return $this->isAlive();
	}
	
	public function attack(EntityDamageEvent $source) : void{
        if($source->isCancelled()){
            $source->setCancelled();
            return;
        }
		if($source instanceof EntityDamageByEntityEvent){
			$killer = $source->getDamager();
			if($killer instanceof Player){
				if($killer->isSpectator()){
					$source->setCancelled(true);
					return;
				}
				if($this->target != $killer->getName() && mt_rand(1,5) == 1 || $this->target == ""){
					$this->target = $killer->getName();
				}
				if(!isset($this->assisting[$killer->getName()])){
					$this->assisting[$killer->getName()] = true;
				}
			}
		}
		parent::attack($source);
	}
	
	public function knockBack(Entity $attacker, float $damage, float $x, float $z, float $base = 0.4) : void{
		parent::knockBack($attacker, $damage, $x, $z, $base * 2);
	}

	public function kill() : void{
		parent::kill();
	}
	//Targetting//
	public function findNewTarget(){
		$distance = self::FIND_DISTANCE;
		$target = null;
		foreach($this->getLevel()->getPlayers() as $player){
			if($player->distance($this) <= $distance && !$player->isSpectator()){
				$distance = $player->distance($this);
				$target = $player;
			}
		}
		$this->findNewTargetTicks = 60;
		$this->target = ($target != null ? $target->getName() : "");
	}
	public function hasTarget(){
		$target = $this->getTarget();
		if($target == null) return false;
		$player = $this->getTarget();
		if($player->isCreative()) return false;
		return !$player->isSpectator();
	}
	public function getTarget(){
		return Server::getInstance()->getPlayerExact((string) $this->target);
	}
	public function atRandomPosition(){
		return $this->getRandomPosition() == null || $this->distance($this->getRandomPosition()) <= 2;
	}
	public function getRandomPosition(){
		return $this->randomPosition;
	}
	public function generateRandomPosition(){
		$minX = $this->getFloorX() - 8;
		$minY = $this->getFloorY() - 8;
		$minZ = $this->getFloorZ() - 8;
		$maxX = $minX + 16;
		$maxY = $minY + 16;
		$maxZ = $minZ + 16;
		$level = $this->getLevel();
		for($attempts = 0; $attempts < 16; ++$attempts){
			$x = mt_rand($minX, $maxX);
			$y = mt_rand($minY, $maxY);
			$z = mt_rand($minZ, $maxZ);
			while($y >= 0 and !$level->getBlockAt($x, $y, $z)->isSolid()){
				$y--;
			}
			if($y < 0){
				continue;
			}
			$blockUp = $level->getBlockAt($x, $y + 1, $z);
			$blockUp2 = $level->getBlockAt($x, $y + 2, $z);
			if($blockUp->isSolid() or $blockUp instanceof Liquid or $blockUp2->isSolid() or $blockUp2 instanceof Liquid){
				continue;
			}
			break;
		}
		$this->randomPosition = new Vector3($x, $y + 1, $z);
	}
	public function getSpeed(){
		return ($this->isUnderwater() ? $this->speed / 2 : $this->speed);
	}
	public function getBaseAttackDamage(){
		return $this->attackDamage;
	}
	public function getAssisting(){
		$assisting = [];
		foreach($this->assisting as $name => $bool){
			$player = Server::getInstance()->getPlayerExact($name);
			if($player instanceof Player) $assisting[] = $player;
		}
		return $assisting;
	}
	public function getFrontBlock($y = 0){
		$dv = $this->getDirectionVector();
		$pos = $this->asVector3()->add($dv->x * $this->getScale(), $y + 1, $dv->z * $this->getScale())->round();
		return $this->getLevel()->getBlock($pos);
	}
	public function shouldJump(){
		if($this->jumpTicks > 0) return false;
		return $this->isCollidedHorizontally || 
		($this->getFrontBlock()->getId() != 0 || $this->getFrontBlock(-1) instanceof Stair) ||
		($this->getLevel()->getBlock($this->asVector3()->add(0,-0,5)) instanceof Slab &&
		(!$this->getFrontBlock(-0.5) instanceof Slab && $this->getFrontBlock(-0.5)->getId() != 0)) &&
		$this->getFrontBlock(1)->getId() == 0 && 
		$this->getFrontBlock(2)->getId() == 0 && 
		!$this->getFrontBlock() instanceof Flowable &&
		$this->jumpTicks == 0;
	}
	public function getJumpMultiplier(){
		return 16;
		if(
			$this->getFrontBlock() instanceof Slab ||
			$this->getFrontBlock() instanceof Stair ||
			$this->getLevel()->getBlock($this->asVector3()->subtract(0,0.5)->round()) instanceof Slab &&
			$this->getFrontBlock()->getId() != 0
		){
			$fb = $this->getFrontBlock();
			if($fb instanceof Slab && $fb->getDamage() & 0x08 > 0) return 8;
			if($fb instanceof Stair && $fb->getDamage() & 0x04 > 0) return 8;
			return 4;
		}
		return 8;
	}
	
	public function jump() : void{
		$this->motion->y = $this->gravity * $this->getJumpMultiplier();
		$this->move($this->motion->x * 1.25, $this->motion->y, $this->motion->z * 1.25);
		$this->jumpTicks = 5; //($this->getJumpMultiplier() == 4 ? 2 : 5);
	}
}
class Vindicator extends Boss{

	public $attackDamage = 10;
	public $startingHealth = 20;

	public function __construct(Level $level, CompoundTag $nbt){
		$this->attackDamage = Main::getInstance()->getConfig()->get("damage");
		$this->startingHealth = Main::getInstance()->getConfig()->get("health");
		$skin = Main::getInstance()->getSkin("vindicator");
		$nbt->setTag(new CompoundTag('Skin', [
            new StringTag('Data', $skin->getSkinData()),
            new StringTag('Name', 'Standard_CustomSlim')]));
        $this->skin = $skin;
		parent::__construct($level, $nbt);
		if (!$this->namedtag->hasTag("Scale", FloatTag::class)) {
            $this->namedtag->setFloat("Scale", Main::getInstance()->getConfig()->get("size"), true);
        }
        $this->getDataPropertyManager()->setFloat(Entity::DATA_SCALE, $this->namedtag->getFloat("Scale"));
	}
}
