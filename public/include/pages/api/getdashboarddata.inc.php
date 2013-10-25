<?php

// Make sure we are called from index.php
if (!defined('SECURITY')) die('Hacking attempt');

// Check if the API is activated
$api->isActive();

// Check user token and access level permissions
$user_id = $api->checkAccess($user->checkApiKey($_REQUEST['api_key']), @$_REQUEST['id']);

// Fetch RPC information
if ($bitcoin->can_connect() === true) {
  $dNetworkHashrate = $bitcoin->getnetworkhashps();
  $dDifficulty = $bitcoin->getdifficulty();
  $iBlock = $bitcoin->getblockcount();
} else {
  $dNetworkHashrate = 0;
  $dDifficulty = 1;
  $iBlock = 0;
}

// Some settings
if ( ! $interval = $setting->getValue('statistics_ajax_data_interval')) $interval = 300;
if ( ! $dPoolHashrateModifier = $setting->getValue('statistics_pool_hashrate_modifier') ) $dPoolHashrateModifier = 1;
if ( ! $dPersonalHashrateModifier = $setting->getValue('statistics_personal_hashrate_modifier') ) $dPersonalHashrateModifier = 1;
if ( ! $dNetworkHashrateModifier = $setting->getValue('statistics_network_hashrate_modifier') ) $dNetworkHashrateModifier = 1;

// Fetch raw data
$statistics->setGetCache(false);
$dPoolHashrate = $statistics->getCurrentHashrate($interval);
if ($dPoolHashrate > $dNetworkHashrate) $dNetworkHashrate = $dPoolHashrate;
$dPersonalHashrate = $statistics->getUserHashrate($user_id, $interval);
$dPersonalSharerate = $statistics->getUserSharerate($user_id, $interval);
$dPersonalShareDifficulty = $statistics->getUserShareDifficulty($user_id, $interval);
$statistics->setGetCache(true);

// Use caches for this one
$aUserRoundShares = $statistics->getUserShares($user_id);
$aRoundShares = $statistics->getRoundShares();

if ($config['payout_system'] != 'pps') {
  $aEstimates = $statistics->getUserEstimates($aRoundShares, $aUserRoundShares, $user->getUserDonatePercent($user_id), $user->getUserNoFee($user_id));
} else {
  if ($config['pps']['reward']['type'] == 'blockavg' && $block->getBlockCount() > 0) {
    $pps_reward = round($block->getAvgBlockReward($config['pps']['blockavg']['blockcount']));
  } else {
    if ($config['pps']['reward']['type'] == 'block') {
      if ($aLastBlock = $block->getLast()) {
        $pps_reward = $aLastBlock['amount'];
      } else {
        $pps_reward = $config['pps']['reward']['default'];
      }
    } else {
      $pps_reward = $config['pps']['reward']['default'];
    }
  }

  $ppsvalue = round($pps_reward / (pow(2,32) * $dDifficulty) * pow(2, $config['pps_target']), 12);
  $aEstimates = $statistics->getUserEstimates($dPersonalSharerate, $dPersonalShareDifficulty, $user->getUserDonatePercent($user_id), $user->getUserNoFee($user_id), $ppsvalue);
}

// Apply pool modifiers
$dPersonalHashrateAdjusted = $dPersonalHashrate * $dPersonalHashrateModifier;
$dPoolHashrateAdjusted = $dPoolHashrate * $dPoolHashrateModifier;
$dNetworkHashrateAdjusted = $dNetworkHashrate / 1000 * $dNetworkHashrateModifier;

// Worker information
$aWorkers = $worker->getWorkers($user_id, $interval);

// Coin price
$aPrice = $setting->getValue('price');

// Output JSON format
$data = array(
  'raw' => array( 'personal' => array( 'hashrate' => $dPersonalHashrate ), 'pool' => array( 'hashrate' => $dPoolHashrate ), 'network' => array( 'hashrate' => $dNetworkHashrate / 1000 ) ),
  'personal' => array (
    'hashrate' => $dPersonalHashrateAdjusted, 'sharerate' => $dPersonalSharerate, 'sharedifficulty' => $dPersonalShareDifficulty,
    'shares' => array('valid' => $aUserRoundShares['valid'], 'invalid' => $aUserRoundShares['invalid']),
    'balance' => $transaction->getBalance($user_id), 'estimates' => $aEstimates, 'workers' => $aWorkers ),
  'pool' => array( 'workers' => $worker->getCountAllActiveWorkers(), 'hashrate' => $dPoolHashrateAdjusted, 'shares' => $aRoundShares, 'price' => $aPrice ),
  'network' => array( 'hashrate' => $dNetworkHashrateAdjusted, 'difficulty' => $dDifficulty, 'block' => $iBlock ),
);
echo $api->get_json($data);

// Supress master template
$supress_master = 1;
?>
