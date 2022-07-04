<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\data\bedrock\block\BlockStateNames;
use pocketmine\data\bedrock\block\BlockStateStringValues;
use pocketmine\data\bedrock\block\BlockTypeNames;
use pocketmine\errorhandler\ErrorToExceptionHandler;
use pocketmine\nbt\NbtException;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\convert\BlockStateDictionary;
use pocketmine\network\mcpe\convert\BlockStateDictionaryEntry;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\utils\Utils;

require dirname(__DIR__) . '/vendor/autoload.php';

class BlockPaletteReport{
	/**
	 * @var string[]
	 * @phpstan-var array<string, string>
	 */
	public array $seenTypes = [];
	/**
	 * @var string[][]
	 * @phpstan-var array<string, array<mixed, string|int>>
	 */
	public array $seenStateValues = [];
}

function generateBlockPaletteReport(BlockStateDictionary $dictionary) : BlockPaletteReport{
	$result = new BlockPaletteReport();

	foreach($dictionary->getStates() as $state){
		$stateData = $state->getStateData();
		$name = $stateData->getName();
		$result->seenTypes[$name] = $name;
		foreach($stateData->getStates() as $k => $v){
			$result->seenStateValues[$k][$v->getValue()] = $v->getValue();
			asort($result->seenStateValues[$k]);
		}
	}

	ksort($result->seenTypes, SORT_STRING);
	ksort($result->seenStateValues, SORT_STRING);
	return $result;
}

function constifyMcId(string $id) : string{
	return strtoupper(explode(":", $id, 2)[1]);
}

function generateClassHeader(string $className) : string{
	$namespace = substr($className, 0, strrpos($className, "\\"));
	$shortName = substr($className, strrpos($className, "\\") + 1);
	return <<<HEADER
<?php

declare(strict_types=1);

namespace $namespace;

/**
 * This class is generated automatically from the block palette for the current version. Do not edit it manually.
 */
final class $shortName{
	private function __construct(){
		//NOOP
	}


HEADER;
}

/**
 * @phpstan-param list<string> $seenIds
 */
function generateBlockIds(array $seenIds) : void{
	$output = ErrorToExceptionHandler::trapAndRemoveFalse(fn() => fopen(dirname(__DIR__) . '/src/data/bedrock/block/BlockTypeNames.php', 'wb'));

	fwrite($output, generateClassHeader(BlockTypeNames::class));

	foreach($seenIds as $id){
		fwrite($output, "\tpublic const " . constifyMcId($id) . " = \"" . $id . "\";\n");
	}

	fwrite($output, "}\n");
	fclose($output);
}

function generateBlockStateNames(BlockPaletteReport $data) : void{
	$output = ErrorToExceptionHandler::trapAndRemoveFalse(fn() => fopen(dirname(__DIR__) . '/src/data/bedrock/block/BlockStateNames.php', 'wb'));

	fwrite($output, generateClassHeader(BlockStateNames::class));
	foreach(Utils::stringifyKeys($data->seenStateValues) as $state => $values){
		$constName = mb_strtoupper($state, 'US-ASCII');
		fwrite($output, "\tpublic const $constName = \"$state\";\n");
	}

	fwrite($output, "}\n");
	fclose($output);
}

function generateBlockStringValues(BlockPaletteReport $data) : void{
	$output = ErrorToExceptionHandler::trapAndRemoveFalse(fn() => fopen(dirname(__DIR__) . '/src/data/bedrock/block/BlockStateStringValues.php', 'wb'));

	fwrite($output, generateClassHeader(BlockStateStringValues::class));
	foreach(Utils::stringifyKeys($data->seenStateValues) as $stateName => $values){
		$anyWritten = false;
		sort($values, SORT_STRING);
		foreach($values as $value){
			if(!is_string($value)){
				continue;
			}
			$anyWritten = true;
			$constName = mb_strtoupper($stateName . "_" . $value, 'US-ASCII');
			fwrite($output, "\tpublic const $constName = \"$value\";\n");
		}
		if($anyWritten){
			fwrite($output, "\n");
		}
	}
	fwrite($output, "}\n");
	fclose($output);
}

if(count($argv) !== 2){
	fwrite(STDERR, "This script regenerates BlockTypeNames, BlockStateNames and BlockStateStringValues from a given palette file\n");
	fwrite(STDERR, "Required arguments: path to block palette file\n");
	exit(1);
}

$palettePath = $argv[1];
$paletteRaw = file_get_contents($palettePath);
if($paletteRaw === false){
	fwrite(STDERR, "Failed to read block palette file\n");
	exit(1);
}

try{
	$states = array_map(
		fn(TreeRoot $root) => BlockStateData::fromNbt($root->mustGetCompoundTag()),
		(new NetworkNbtSerializer())->readMultiple($paletteRaw)
	);
}catch(NbtException){
	fwrite(STDERR, "Invalid block palette file $argv[1]\n");
	exit(1);
}
$entries = [];
$fakeMeta = [];
foreach($states as $state){
	$fakeMeta[$state->getName()] ??= 0;
	$entries[] = new BlockStateDictionaryEntry($state, $fakeMeta[$state->getName()]++);
}
$dictionary = new BlockStateDictionary($entries);
$report = generateBlockPaletteReport($dictionary);
generateBlockIds($report->seenTypes);
generateBlockStateNames($report);
generateBlockStringValues($report);

echo "Done. Don't forget to run CS fixup after generating code.\n";