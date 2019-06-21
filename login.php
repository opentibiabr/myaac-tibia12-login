<?php

require_once('common.php');
require_once('config.php');
require_once('config.local.php');

require_once(SYSTEM . 'functions.php');
require_once(SYSTEM . 'init.php');
require_once(SYSTEM . 'status.php');

# error function
function sendError($msg){
    $ret = [];
    $ret["errorCode"] = 3;
    $ret["errorMessage"] = $msg;
    
    die(json_encode($ret));
}

$action = isset($_GET['action']) ? $_GET['action'] : '';
switch ($action) {

	case 'cacheinfo':
		die(json_encode([
			'playersonline' => $status['players'],
			'twitchstreams' => 0,
			'twitchviewer' => 0,
			'gamingyoutubestreams' => 0,
			'gamingyoutubeviewer' => 0
		]));
	break;

	case 'eventschedule':
		die(json_encode([
			'eventlist' => []
		]));
	break;

    case 'boostedcreature':
		die(json_encode([
			'boostedcreature' => false,
		]));
	break;
		
    case 'login':
		$request = file_get_contents('php://input');
		$result = json_decode($request);

		// check if cast is enable and accountname is cast
		$casting = $config['lua']['enableLiveCasting'] && $result->accountname == 'cast';
		if (!$casting && (!$result->accountname || !$result->password)) {
			sendError('Account name or password is not correct.');
		}

		$port = $config['lua']['gameProtocolPort'];
		// change port if is accountname is cast
		if ($casting) {
			$port = $config['lua']['liveCastPort'];
		}

		// default world info
		$world = [
			'id' => 0,
            'name' => $config['lua']['serverName'],
            'pvptype' => array_search($config['server']['worldType'], ['pvp', 'no-pvp', 'pvp-enforced']),
            'externaladdress' => $config['lua']['ip'],
            'externalport' => $port,
            'previewstate' => 0,
            'location' => 'BRA',
            'externaladdressunprotected' => $config['lua']['ip'],
            'externaladdressprotected' => $config['lua']['ip'],
            'externalportunprotected' => $port,
            'externalportprotected' => $port,
            'anticheatprotection' => false
		];

		$characters = [];
		$account = null;

		// common columns
		$columns = 'name, level, sex, vocation, looktype, lookhead, lookbody, looklegs, lookfeet, lookaddons, deleted, lastlogin';
		if ($casting) {
			// get players casting
			$casters = $db->query("select {$columns} from players join live_casts on player_id = id")->fetchAll();

			if (!count($casters)) {
				sendError('There is no live casts right now!');
			}

			foreach ($casters as $caster) {
				$characters[] = create_char($caster);
			}
		} else {
			$account = new Account();
			$account->find($result->accountName);

			if (!$account->isLoaded() || $account->getPassword() != Website::encryptPassword($result->password)) {
				sendError('Account name or password is not correct.');
			}

			$players = $db->query("select {$columns} from players where account_id = " . $account->getId())->fetchAll();
			foreach ($players as $player) {
				$characters[] = create_char($player);
			}
		}

		$worlds = [$world];
		$playdata = compact('worlds', 'characters');
		$session = [
			'fpstracking' => false,
            'optiontracking' => false,
            'isreturner' => true,
            'returnernotification' => false,
            'showrewardnews' => false,
            'sessionkey' => "{$result->accountname}\n{$result->password}",
            'lastlogintime' => ($casting || !$account) ? 0 : $account->getLastLogin(),
            'ispremium' => ($casting || !$account) ? true : $account->isPremium(),
            'premiumuntil' => ($casting || !$account) ? 0 : $account->getPremiumEnd(),
            'status' => 'active'
		];

		die(json_encode(compact('session', 'playdata')));
	break;

	default:
		sendError("Unrecognized event.");
	break;
}

function create_char($player) {
	return [
		'worldid' => 0,
		'name' => $player->name,
		'ismale' => intval($player->sex) === 1,
		'tutorial' => intval($player->lastlogin) === 0,
		'vocation' => $config['vocations'][$player->vocation_id],
		'outfitid' => intval($player['looktype']),
		'headcolor' => intval($player['lookhead']),
		'torsocolor' => intval($player['lookbody']),
		'legscolor' => intval($player['looklegs']),
		'detailcolor' => intval($player['lookfeet']),
		'addonsflags' => intval($player['lookaddons']),
		'ishidden' => intval($player->deleted) === 1
	];
}

/*
# Declare variables with array structure
$characters = array();
$playerData = array();
$data = array();
$isCasting = false;


# getting infos
$request = file_get_contents('php://input');
$result = json_decode($request, true);

# account infos
$accountName = $result["accountname"];
$password = $result["password"];

# game port
$port = $config['lua']['gameProtocolPort'];

# check if player wanna see cast list
if (strtolower($accountName) == "cast") {
	$isCasting = true;
}

if ($isCasting) {
	$casts = $db->query("SELECT `player_id` FROM `live_casts`")->fetchAll();
	if (count($casts[0]) == 0) {
		sendError("There is no live casts right now!");
	}

	foreach($casts as $cast) {
		$character = new OTS_Player();
		$character->load($cast['player_id']);
		
		if ($character->isLoaded()) {
			$char = array("worldid" => 0, "name" => $character->getName(), "ismale" => (($character->getSex() == 1) ? true : false), "tutorial" => true);
			$characters[] = $char;
		}
	}
	
	$port = 7173;
	$lastLogin = 0;

	$premiumAccount = true;
	$timePremium = 30 * 86400;
}
else {
	$account = new OTS_Account();
	$account->find($accountName);
	
	if (!$account->isLoaded())
		sendError("Failed to get account. Try again!");

	$config_salt_enabled = fieldExist('salt', 'accounts');
	$current_password = encrypt(($config_salt_enabled ? $account->getCustomField('salt') : '') . $password);
	if ($account->getPassword() != $current_password)
		sendError("The password for this account is wrong. Try again!");
	
	foreach($account->getPlayersList() as $character) {
		$char = array("worldid" => 0, "name" => $character->getName(), "ismale" => (($character->getSex() == 1) ? true : false), "tutorial" => true);
		$characters[] = $char;
	}
	
	$save = false;
	$timeNow = time();

	$query = $db->query('SELECT `premdays`, `lastday` FROM `accounts` WHERE `id` = ' . $account->getId());
	if($query->rowCount() > 0) {
		$query = $query->fetch();
		$premDays = (int)$query['premdays'];
		$lastDay = (int)$query['lastday'];
		$lastLogin = $lastDay;
	}
	else {
		sendError("Error while fetching your account data. Please contact admin.");
	}
	
	if($premDays != 0 && $premDays != PHP_INT_MAX ) {
		if($lastDay == 0) {
			$lastDay = $timeNow;
			$save = true;
		} else {
			$days = (int)(($timeNow - $lastDay) / 86400);
			if($days > 0) {
				if($days >= $premDays) {
					$premDays = 0;
					$lastDay = 0;
				} else {
					$premDays -= $days;
					$remainder = (int)(($timeNow - $lastDay) % 86400);
					$lastDay = $timeNow - remainder;
				}

				$save = true;
			}
		}
	} else if ($lastDay != 0) {
		$lastDay = 0;
		$save = true;
	}

	if($save) {
		$db->query('UPDATE `accounts` SET `premdays` = ' . $premDays . ', `lastday` = ' . $lastDay . ' WHERE `id` = ' . $account->getId());
	}

	$premiumAccount = $premDays > 0;
	$timePremium = time() + ($premDays * 86400);
}

$session = array(
/*	"fpstracking" => false,
	"isreturner" => true,
	"returnernotification" => false,
	"showrewardnews" => false,*/
// 	"sessionkey" => $accountName . "\n" . $password,
// 	"lastlogintime" => $lastLogin,
// 	"ispremium" => $premiumAccount,
// 	"premiumuntil" => $timePremium,
// 	"status" => "active"
// );

// $world = array(
// 	"id" => 0,
// 	"name" => $config['lua']['serverName'],
// 	"externaladdress" => $config['lua']['ip'],
// 	"externalport" => $port,
// 	"previewstate" => 0,
// 	"location" => "BRA",
// 	"anticheatprotection" => false,
// 	"externaladdressunprotected" => $config["lua"]["ip"],
// 	"externaladdressprotected" => $config["lua"]["ip"]
// );

// $worlds = array($world);

// $data["session"] = $session;
// $playerData["worlds"] = $worlds;
// $playerData["characters"] = $characters;
// $data["playdata"] = $playerData;

// echo json_encode($data);
//echo '<pre>' . var_export($data, true) . '</pre>';