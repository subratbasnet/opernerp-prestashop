<?php

error_reporting(E_ALL ^ E_NOTICE);
set_time_limit(0);
date_default_timezone_set('Asia/Katmandu');

require_once __DIR__ . '/class/OpenERPService.php';
require_once __DIR__ . '/class/PrestaService.php';

$erp = new OpenERPService();
$ps = new PrestaService(false);

//$productInfo = $erp->getErpProductInfo(73);
////$productInfo['image'] = '';
//print_r($productInfo);
//$ps->sync($productInfo);
//exit;
$products = $erp->getErpProductIds();
foreach ($products as $productId) {
    $productInfo = $erp->getErpProductInfo($productId);
    $ps->sync($productInfo);
}