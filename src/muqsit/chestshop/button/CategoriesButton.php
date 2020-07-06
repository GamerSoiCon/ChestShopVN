<?php

declare(strict_types=1);

namespace muqsit\chestshop\button;

use muqsit\chestshop\category\Category;
use muqsit\chestshop\ChestShop;
use muqsit\chestshop\Loader;
use pocketmine\item\Item;
use pocketmine\Player;
use pocketmine\utils\Config;

class CategoriesButton extends Button implements CategoryNavigationButton{

	/** @var Item */
	private static $item;

	/** @var ChestShop */
	private static $shop;

	public static function init(Loader $loader, Config $config) : void{
		self::$shop = $loader->getChestShop();
		self::$item = ButtonUtils::itemFromConfig($config->get("categories"));
	}

	public function getItem() : Item{
		return clone self::$item;
	}

	public function navigate(Player $player, Category $category, int $current_page) : void{
		self::$shop->send($player);
	}
}