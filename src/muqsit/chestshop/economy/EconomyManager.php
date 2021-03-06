<?php

declare(strict_types=1);

namespace muqsit\chestshop\economy;

use muqsit\chestshop\Loader;
use pocketmine\utils\Utils;

final class EconomyManager{

	/** @var string[] */
	private static $integrations = [];

	/** @var EconomyIntegration */
	private static $integrated;

	public static function init(Loader $loader) : void{
		self::registerDefaults();

		$config = $loader->getConfig();
		$plugin = $config->getNested("economy.plugin", "EconomyAPI");
		if(!isset(self::$integrations[$plugin])){
			throw new \InvalidArgumentException($loader->getName() . " không hỗ trợ plugin " . $plugin);
		}

		self::$integrated = new self::$integrations[$plugin]($config->getNested("economy." . $plugin, []));
	}

	private static function registerDefaults() : void{
		self::register("EconomyAPI", EconomyAPIIntegration::class);
		self::register("MultiEconomy", MultiEconomyIntegration::class);
	}

	public static function register(string $plugin, string $class) : void{
		Utils::testValidInstance($class, EconomyIntegration::class);
		self::$integrations[$plugin] = $class;
	}

	public static function get() : EconomyIntegration{
		return self::$integrated;
	}
}