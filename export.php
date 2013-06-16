<?php

error_reporting(E_ALL ^ E_NOTICE);
set_time_limit(0);
date_default_timezone_set('Asia/Katmandu');

require_once __DIR__ . '/class/OpenERPService.php';
//require_once __DIR__ . '/class/PrestaService.php';

$erp = new OpenERPService();
//$ps = new PrestaService(false);
//$ps->setHomeCategories(array('live fish'));

$fp = fopen('erp_export.csv', 'w');
$products = $erp->getErpProductIds();
foreach ($products as $i => $productId) {
    $productInfo = $erp->getErpProductInfo($productId);

    $productInfo['code'] = str_replace('_', '-', strtoupper($productInfo['code']));
    $productInfo['lst_price'] = number_format($productInfo['lst_price']);

    if ($productInfo['cat_name'] == 'Aquarium Accessories') {
        $percentage = 40;
    } else
    if ($productInfo['cat_name'] == 'Fish Food') {
        $percentage = 40;
    } else if ($productInfo['cat_name'] == 'Live Fish') {
        $percentage = 70;
    } else {
        $percentage = 1;
    }

    if ($percentage <= 1) {
        $productInfo['cost_price'] = $productInfo['lst_price'];
    } else {
        $productInfo['cost_price'] = number_format(($productInfo['lst_price'] - ($productInfo['lst_price'] * $percentage / 100)), 2);
    }

    if ($i == 0) {
        fputcsv($fp, array_keys($productInfo));
    }

    if ($productInfo['image'] && $productInfo['code']) {
        file_put_contents('images/' . $productInfo['code'] . '.jpg', base64_decode($productInfo['image']));
    }

    print_r($productInfo);

    fputcsv($fp, $productInfo);
}
fclose($fp);