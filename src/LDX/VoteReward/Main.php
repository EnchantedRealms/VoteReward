<?php

namespace LDX\VoteReward;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\item\Item;

class Main extends PluginBase {

  private $message = "";
  private $items = [];
  private $commands = [];
  private $debug = false;
  public $queue = [];

  public function onLoad() {
    if(file_exists($this->getDataFolder() . "config.yml")) {
      $c = $this->getConfig()->getAll();
      if(isset($c["API-Key"])) {
        if(trim($c["API-Key"]) != "") {
          if(!is_dir($this->getDataFolder() . "Lists/")) {
            mkdir($this->getDataFolder() . "Lists/");
          }
          file_put_contents($this->getDataFolder() . "Lists/minecraftpocket-servers.com.vrc", "{\"website\":\"http://minecraftpocket-servers.com/\",\"check\":\"http://minecraftpocket-servers.com/api-vrc/?object=votes&element=claim&key=" . $c["API-Key"] . "&username={USERNAME}\",\"claim\":\"http://minecraftpocket-servers.com/api-vrc/?action=post&object=votes&element=claim&key=" . $c["API-Key"] . "&username={USERNAME}\"}");
          rename($this->getDataFolder() . "config.yml", $this->getDataFolder() . "config.old.yml");
          $this->getLogger()->info("§eConverting API key to VRC file...");
        } else {
          rename($this->getDataFolder() . "config.yml", $this->getDataFolder() . "config.old.yml");
          $this->getLogger()->info("§eSetting up new configuration file...");
        }
      }
    }
  }

  public function onEnable() {
    $this->reload();
  }

  public function reload() {
    $this->saveDefaultConfig();
    if(!is_dir($this->getDataFolder() . "Lists/")) {
      mkdir($this->getDataFolder() . "Lists/");
    }
    $this->lists = [];
    foreach(scandir($this->getDataFolder() . "Lists/") as $file) {
      $ext = explode(".", $file);
      $ext = (count($ext) > 1 && isset($ext[count($ext) - 1]) ? strtolower($ext[count($ext) - 1]) : "");
      if($ext == "vrc") {
        $this->lists[] = json_decode(file_get_contents($this->getDataFolder() . "Lists/$file"),true);
      }
    }
    $this->reloadConfig();
    $config = $this->getConfig()->getAll();
    $this->message = $config["Message"];
    $this->items = [];
    foreach($config["Items"] as $i) {
      $r = explode(":", $i);
      $this->items[] = new Item($r[0], $r[1], $r[2]);
    }
    $this->commands = $config["Commands"];
    $this->debug = isset($config["Debug"]) && $config["Debug"] === true ? true : false;
  }

  public function onCommand(CommandSender $sender, Command $command, $label, array $args) {
    switch(strtolower($command->getName())) {
      case "vote":
        if(isset($args[0]) && strtolower($args[0]) == "help") {
          $sender->sendMessage("§e§lVote Help:");
          $sender->sendMessage("§e/vote help -§7 Displays this!");
          $sender->sendMessage("§e/vote tutorial -§7 Guide on how to vote!");
          $sender->sendMessage("§e/vote reload -§7 Reloads the plugin!§b [OP ONLY]");
          $sender->sendMessage("§e/vote rewards -§7 Displays the rewards from /vote!");
          break;
        }
        if(isset($args[0]) && strtolower($args[0]) == "rewards") {
          $sender->sendMessage("§e§lVote Rewards:");
          $sender->sendMessage("§72 Vote Keys, 1 Basic Key, full leather armour, stone sword, iron pickaxe(Efficiency 1), 10 oak logs, 20 torches, 64 cobblestone, 9 diamonds, 5 iron ingots, 15 steak!");
          break;
        }
        if(isset($args[0]) && strtolower($args[0]) == "tutorial") {
          $sender->sendMessage("§e§lVote Tutorial:");
          $sender->sendMessage("§1[1]:§7 Do /vote §b[2]:§7 Visit one of the links on a browser (Google Chrome, Safari, etc) §b[3]:§7 Enter your username §b[4]:§7 Solve the captcha §b[5]:§7 Come back in-game and type§b /vote§7!");
          break;
        }
        if(isset($args[0]) && strtolower($args[0]) == "reload") {
          if(Utils::hasPermission($sender, "votereward.command.reload")) {
            $this->reload();
            $sender->sendMessage("§e§lVote Reload:");
            $sender->sendMessage("§7Reloaded!");
            break;
          }
          $sender->sendMessage("§e§lVote Reload:");
          $sender->sendMessage("§cOPs only can use this command!");
          break;
        }
        if(!$sender instanceof Player) {
          $sender->sendMessage("This command must be used in-game!");
          break;
        }
        if(!Utils::hasPermission($sender, "votereward.command.vote")) {
          $sender->sendMessage("You do not have permission to use this command.");
          break;
        }
        if(in_array(strtolower($sender->getName()), $this->queue)) {
          $sender->sendMessage("§e§lVote§r§e>§7 Loading...");
          break;
        }
        $this->queue[] = strtolower($sender->getName());
        $requests = [];
        foreach($this->lists as $list) {
          if(isset($list["check"]) && isset($list["claim"])) {
            $requests[] = new ServerListQuery($list["check"], $list["claim"]);
          }
        }
        $query = new RequestThread(strtolower($sender->getName()), $requests);
        $this->getServer()->getScheduler()->scheduleAsyncTask($query);
        break;
      default:
        $sender->sendMessage("Invalid command!");
        break;
    }
    return true;
  }

  public function rewardPlayer($player, $multiplier) {
    if(!$player instanceof Player) {
      return;
    }
    if($multiplier < 1) {
      $player->sendMessage("§eVote>§7 You haven't voted on any of our websites!");
      $player->sendMessage("§eVote>§7 Use §b/vote help §7to find out how to vote!");
      $player->sendMessage("§e§lVote Links:");
      $player->sendMessage("§a- §bbit.ly/erpevote1");
      $player->sendMessage("§a- §bbit.ly/erpevote2");
      $player->sendMessage("§eVote Count: §3(§a§l0§r§3/§l§a2§r§3)");
      return;
    }
    if($multiplier == 1) {
      $player->sendMessage("§eVote>§7 You have voted on §b1 §7website. You can vote on another website!");
      $player->sendMessage("§7 §eUse §b/vote help §eto find out how to vote!");
      $player->sendMessage("§e§l Vote Links:");
      $player->sendMessage("§a- §bbit.ly/erpevote1");
      $player->sendMessage("§a- §bbit.ly/erpevote2");
      $player->sendMessage("§eVote Count: §3(§a§l1§r§3/§l§a2§r§3)");
      return;
    }
    if($multiplier > 1) {
      $player->sendMessage("§eVote>§a Thank you for voting on all of our websites!");
      $player->sendMessage("§eVote Count: §3(§a§l2§r§3/§l§a2§r§3)");
      return;
    }
    $clones = [];
    foreach($this->items as $item) {
      $clones[] = clone $item;
    }
    foreach($clones as $item) {
      $item->setCount($item->getCount() * $multiplier);
      $player->getInventory()->addItem($item);
    }
    foreach($this->commands as $command) {
      $this->getServer()->dispatchCommand(new ConsoleCommandSender, str_replace(array(
        "{USERNAME}",
        "{NICKNAME}",
        "{X}",
        "{Y}",
        "{Y1}",
        "{Z}"
      ), array(
        $player->getName(),
        $player->getDisplayName(),
        $player->getX(),
        $player->getY(),
        $player->getY() + 1,
        $player->getZ()
      ), Utils::translateColors($command)));
    }
    if(trim($this->message) != "") {
      $message = str_replace(array(
        "{USERNAME}",
        "{NICKNAME}"
      ), array(
        $player->getName(),
        $player->getDisplayName()
      ), Utils::translateColors($this->message));
      foreach($this->getServer()->getOnlinePlayers() as $p) {
        $p->sendMessage($message);
      }
      $this->getServer()->getLogger()->info($message);
    }
    $player->sendMessage("§eVote>§7 You voted on§b $multiplier §7server!" . ($multiplier == 1 ? "" : "s") . "!");
  }

}
