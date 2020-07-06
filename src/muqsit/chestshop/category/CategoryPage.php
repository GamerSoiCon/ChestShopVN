<?php

declare(strict_types=1);

namespace muqsit\chestshop\category;

use Ds\Set;
use muqsit\chestshop\button\ButtonFactory;
use muqsit\chestshop\button\ButtonIds;
use muqsit\chestshop\button\CategoryNavigationButton;
use muqsit\chestshop\database\Database;
use muqsit\chestshop\economy\EconomyManager;
use muqsit\chestshop\Loader;
use muqsit\invmenu\InvMenu;
use muqsit\invmenu\SharedInvMenu;
use OutOfRangeException;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\item\Item;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\utils\TextFormat;

final class CategoryPage{

	public const MAX_ENTRIES_PER_PAGE = 45;

	/** @var Database */
	private $database;

	/** @var SharedInvMenu */
	private $menu;

	/** @var Category */
	private $category;

	/** @var Set<CategoryEntry>|CategoryEntry[] */
	private $entries;

	/** @var int */
	private $page;

	public function __construct(array $entries = []){
		$this->entries = new Set($entries);
	}

	public function init(Database $database, Category $category) : void{
		$this->database = $database;
		$this->menu = InvMenu::create(InvMenu::TYPE_DOUBLE_CHEST)->readonly();
		$this->category = $category;

		/** @var Loader $loader */
		$loader = Server::getInstance()->getPluginManager()->getPlugin("ChestShop");

		if(CategoryConfig::getBool(CategoryConfig::BACK_TO_CATEGORIES)){
			$this->menu->setInventoryCloseListener(static function(Player $player, Inventory $inventory) use($loader) : void{
				static $shop = null;
				if($shop === null){
					$shop = $loader->getChestShop();
				}
				$shop->send($player);
			});
		}

		$this->menu->getInventory()->setContents([
			48 => ButtonFactory::get(ButtonIds::TURN_LEFT, $category->getName()),
			49 => ButtonFactory::get(ButtonIds::CATEGORIES),
			50 => ButtonFactory::get(ButtonIds::TURN_RIGHT, $category->getName())
		]);

		$confirmation_ui = $loader->getConfirmationUi();
		$this->menu->setListener(function(Player $player, Item $itemClicked, Item $itemClickedWith, SlotChangeAction $action) use($confirmation_ui) : void{
			$slot = $action->getSlot();
			$entry = $this->getPurchasableEntry($slot);

			if($entry !== null){
				if($confirmation_ui !== null){
					$player->removeWindow($action->getInventory());
					$item = $entry->getItem();
					$confirmation_ui->send(
						$player,
						[
							"{NAME}" => $item->getName(),
							"{COUNT}" => $item->getCount(),
							"{PRICE}" => $entry->getPrice(),
							"{PRICE_FORMATTED}" => EconomyManager::get()->formatMoney($entry->getPrice()),
							"{CATEGORY}" => $this->category->getName(),
							"{PAGE}" => $this->page
						],
						function(Player $player, $data) use($slot, $entry) : void{
							if($data === 0 && $this->getPurchasableEntry($slot) === $entry){
								$this->attemptPurchase($player, $entry);
							}
							$this->send($player);
						}
					);
				}else{
					$this->attemptPurchase($player, $entry);
				}
			}else{
				$button = ButtonFactory::fromItem($itemClicked);
				if($button instanceof CategoryNavigationButton){
					$button->navigate($player, $this->category, $this->page);
				}
			}
		});
	}

	private function getPurchasableEntry(int $slot) : ?CategoryEntry{
		$entry = null;
		try{
			/** @var CategoryEntry $entry */
			$entry = $this->entries->get($slot);
		}catch(OutOfRangeException $e){
		}
		return $entry;
	}

	private function attemptPurchase(Player $player, CategoryEntry $entry) : void{
		$price = $entry->getPrice();
		$economy = EconomyManager::get();
		$money = $economy->getMoney($player);
		if($money >= $price){
			$economy->removeMoney($player, $price);
			foreach($player->getInventory()->addItem($entry->getItem()) as $item){
				$player->getLevel()->dropItem($player, $item);
			}
			$player->sendMessage(strtr(CategoryConfig::getString(CategoryConfig::PURCHASE_MESSAGE), [
				"{PLAYER}" => $player->getName(),
				"{PRICE}" => $price,
				"{PRICE_FORMATTED}" => $economy->formatMoney($price),
				"{ITEM}" => $entry->getItem()->getName(),
				"{COUNT}" => $entry->getItem()->getCount()
			]));
		}else{
			$player->sendMessage(strtr(CategoryConfig::getString(CategoryConfig::PURCHASE_MESSAGE), [
				"{PLAYER}" => $player->getName(),
				"{PRICE}" => $price,
				"{PRICE_FORMATTED}" => $economy->formatMoney($price),
				"{MONEY}" => $money,
				"{MONEY_FORMATTED}" => $economy->formatMoney($money),
				"{ITEM}" => $entry->getItem()->getName(),
				"{COUNT}" => $entry->getItem()->getCount()
			]));
		}
	}

	public function updatePageNumber(Category $category, int $page) : void{
		$this->page = $page;
		$this->menu->setName(strtr(CategoryConfig::getString(CategoryConfig::TITLE), [
			"{NAME}" => $category->getName(),
			"{PAGE}" => $page
		]));
	}

	public function addEntry(CategoryEntry $entry, bool $update) : void{
		if(count($this->entries) === self::MAX_ENTRIES_PER_PAGE){
			throw new \OverflowException("Không thể thêm nhiều hơn " . self::MAX_ENTRIES_PER_PAGE . " mục vào một trang.");
		}

		$slot = $this->entries->count();
		$this->entries[] = $entry;

		$item = clone $entry->getItem();

		$find = ["{NAME}", "{COUNT}", "{PRICE}", "{PRICE_FORMATTED}", "{CATEGORY}", "{PAGE}"];
		$replace = [$item->getName(), $item->getCount(), $entry->getPrice(), EconomyManager::get()->formatMoney($entry->getPrice()), $this->category->getName(), $this->page];

		$item->setCustomName(str_replace($find, $replace, CategoryConfig::getString(CategoryConfig::ITEM_BUTTON_NAME)));
		$lore = str_replace($find, $replace, CategoryConfig::getStringList(CategoryConfig::ITEM_BUTTON_LORE_VALUE));
		switch(CategoryConfig::getString(CategoryConfig::ITEM_BUTTON_LORE_TYPE)){
			case "push":
				$new = $item->getLore();
				array_push($new, ...$lore);
				$item->setLore($new);
				break;
			case "unshift":
				$new = $item->getLore();
				array_unshift($new, ...$lore);
				$item->setLore($new);
				break;
			case "override":
				$item->setLore($lore);
				break;
		}

		$this->menu->getInventory()->setItem($slot, $item);
		if($update){
			$this->database->addToCategory($this->category, $this->getOffset() + $slot, $entry);
		}
	}

	public function isEmpty() : bool{
		return count($this->entries) === 0;
	}

	public function removeItem(Item $item) : bool{
		$inventory = $this->menu->getInventory();
		$contents = $inventory->getContents();
		for($slot = $inventory->getSize() - 1; $slot >= 0; --$slot){
			if(isset($contents[$slot]) && $contents[$slot] === $item){
				$this->removeSlot($slot);
				$inventory->setContents(array_values($contents));
				return true;
			}
		}

		return false;
	}

	private function getOffset() : int{
		return ($this->page - 1) * self::MAX_ENTRIES_PER_PAGE;
	}

	public function removeSlot(int $slot) : void{
		$this->menu->getInventory()->clear($slot);
		$this->entries->remove($slot);
		$this->database->removeFromCategory($this->category, $this->getOffset() + $slot);
	}

	public function send(Player $player) : void{
		$this->menu->send($player);
	}

	public function onDelete() : void{
		$inventory = $this->menu->getInventory();
		foreach($inventory->getViewers() as $viewer){
			$viewer->sendMessage(TextFormat::GRAY . "Trang bạn đang xem không hoạt động.");
		}
		$inventory->removeAllViewers();
	}
}