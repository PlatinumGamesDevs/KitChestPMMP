<?php

namespace AdvancedKits;

use AdvancedKits\economy\EconomyManager;
use AdvancedKits\lang\LangManager;
use AdvancedKits\tasks\CoolDownTask;
use pocketmine\utils\TextFormat;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\item\Item;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;

class Main extends PluginBase {

	/**@var kit[] */
	public $kits = [];
	/**@var kit[] */
	public $hasKit = [];
	/**@var EconomyManager */
	public $economy;
	public $permManager = false;
	/**@var LangManager */
	public $langManager;

	public function onEnable() : void{
		@mkdir($this->getDataFolder() . "cooldowns/");
		$this->saveDefaultConfig();
		$this->loadKits();
		$this->economy = new EconomyManager($this);
		$this->langManager = new LangManager($this);
		if($this->getServer()->getPluginManager()->getPlugin("PurePerms") !== null and !$this->getConfig()->get("force-builtin-permissions")){
			$this->permManager = true;
		}
		$this->getScheduler()->scheduleDelayedRepeatingTask(new CoolDownTask($this), 1200, 1200);
		$this->getPluginManager()->registerEvents(new EventListener($this), $this);
	}

	public function onDisable() : void{
		foreach($this->kits as $kit){
			$kit->save();
		}
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool{
		switch(strtolower($command->getName())){
			case "kit":
				if(!($sender instanceof Player)){
					$sender->sendMessage($this->langManager->getTranslation("in-game"));
					return true;
				}
				if(!isset($args[0])){
					$sender->sendMessage($this->langManager->getTranslation("av-kits", implode(", ", array_keys($this->kits))));
					return true;
				}
				$kit = $this->getKit($args[0]);
				if($kit === null){
					$sender->sendMessage($this->langManager->getTranslation("no-kit", $args[0]));
					return true;
				}
				//$kit->handleRequest($sender);
				$player = $sender;
				if($kit->testPermission($player) || $player->isOp()){
					if(!isset($kit->coolDowns[strtolower($player->getName())])){
						if(!($this->getConfig()->get("one-kit-per-life") and isset($this->hasKit[strtolower($player->getName())]))){
							if($kit->cost){
								if($this->economy->grantKit($player, $kit->cost)){
									$chest = Item::get(Item::CHEST, 25, 1);
									$nbt = $chest->getNamedTag();
									$nbt->setString("kit", $kit->getName());
									$chest->setNamedTag($nbt);
									$chest->setCustomName(TextFormat::RESET . TextFormat::BOLD . TextFormat::DARK_GRAY . "[" . TextFormat::GOLD . "+" . TextFormat::DARK_GRAY . "] " . TextFormat::GOLD . $kit->getName() . " Kit " . TextFormat::DARK_GRAY . "[" . TextFormat::GOLD . "+" . TextFormat::DARK_GRAY . "]" . TextFormat::RESET);
									$chest->setLore([
										"",
										TextFormat::GRAY . "Tap Anywhere To Redeem",
										TextFormat::GRAY . "A Container That Contains The " . $kit->getName() . " Kit",
										"",
										TextFormat::RED . "Be Sure To Clear Your Inventory Use This In /wild",
									]);
									$sender->getInventory()->addItem($chest);
									$player->sendMessage($this->langManager->getTranslation("sel-kit", $this->name));
									return true;
								}else{
									$player->sendMessage($this->langManager->getTranslation("cant-afford", $this->name));
									return true;
								}
							}else{
								$chest = Item::get(Item::CHEST, 25, 1);
								$nbt = $chest->getNamedTag();
								$nbt->setString("kit", $kit->getName());
								$chest->setNamedTag($nbt);
								$chest->setCustomName(TextFormat::RESET . TextFormat::BOLD . TextFormat::DARK_GRAY . "[" . TextFormat::GOLD . "+" . TextFormat::DARK_GRAY . "] " . TextFormat::GOLD . $kit->getName() . " Kit " . TextFormat::DARK_GRAY . "[" . TextFormat::GOLD . "+" . TextFormat::DARK_GRAY . "]" . TextFormat::RESET);
								$chest->setLore([
									"",
									TextFormat::GRAY . "Tap Anywhere To Redeem",
									TextFormat::GRAY . "A Container That Contains The " . $kit->getName() . " Kit",
									"",
									TextFormat::RED . "Be Sure To Clear Your Inventory Use This In /wild",
								]);
								$sender->getInventory()->addItem($chest);
								$player->sendMessage($this->langManager->getTranslation("sel-kit", $kit->name));
							}
							if($kit->coolDown > 0){
								$kit->coolDowns[strtolower($player->getName())] = $kit->coolDown;
							}
						}else{
							$player->sendMessage($this->langManager->getTranslation("one-per-life"));
						}
					}else{
						$player->sendMessage($this->langManager->getTranslation("cooldown1", $kit->name));
						$player->sendMessage($this->langManager->getTranslation("cooldown2", $kit->getCoolDownLeft($player)));
					}
				}else{
					$player->sendMessage($this->langManager->getTranslation("no-perm", $kit->name));
				}

				return true;
			case "akreload":
				foreach($this->kits as $kit){
					$kit->save();
				}
				$this->kits = [];
				$this->loadKits();
				$sender->sendMessage($this->langManager->getTranslation("reload"));

				return true;
		}

		return true;
	}

	private function loadKits(){
		$this->saveResource("kits.yml");
		$kitsData = yaml_parse_file($this->getDataFolder() . "kits.yml");
		$this->fixConfig($kitsData);
		foreach($kitsData as $kitName => $kitData){
			$this->kits[$kitName] = new Kit($this, $kitData, $kitName);
		}
	}

	private function fixConfig(&$config){
		foreach($config as $name => $kit){
			if(isset($kit["users"])){
				$users = array_map("strtolower", $kit["users"]);
				$config[$name]["users"] = $users;
			}
			if(isset($kit["worlds"])){
				$worlds = array_map("strtolower", $kit["worlds"]);
				$config[$name]["worlds"] = $worlds;
			}
		}
	}

	/**
	 * @param string $kit
	 * @return Kit|null
	 */
	public function getKit(string $kit) : Kit{
		/**@var Kit[] $lowerKeys */
		$lowerKeys = array_change_key_case($this->kits, CASE_LOWER);
		if(isset($lowerKeys[strtolower($kit)])){
			return $lowerKeys[strtolower($kit)];
		}

		return null;
	}

	/**
	 * @param $player
	 * @param bool $object whether to return the kit object or the kit name
	 * @return kit|null
	 */
	public function getPlayerKit($player, bool $object = false) : Kit{
		if($player instanceof Player) $player = $player->getName();

		return isset($this->hasKit[strtolower($player)]) ? ($object ? $this->hasKit[strtolower($player)] : $this->hasKit[strtolower($player)]->getName()) : null;
	}

}
