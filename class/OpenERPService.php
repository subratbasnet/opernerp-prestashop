<?php

require_once __DIR__ . '/OpenERP.php';

class OpenERPService extends OpenERP {

    public $erpDebug = false;
    private $erpDb = 'my_erp_db';
    private $erpUser = 1; // 1 is for admin, usually
    private $erpPwd = 'mypassword';
    private $erpEndPoint = 'http://example.com:8069/xmlrpc/object';

    public function __construct() {

        // override from environment variable
        $vars = get_class_vars(__CLASS__);
        foreach ($vars as $key => $var) {
            $this->$key = $this->getEnv($key);
        }
        $this->erpUser = 'subrat.basnet';
        parent::__construct($this->erpEndPoint, $this->erpDb);
        $this->login($this->erpUser, $this->erpPwd);
    }

    private function getEnv($key) {
        $value = getenv($key);
        $ret = ($value != '') ? $value : $this->$key;
        if ($value == 'erpDebug') {
            $ret = (int) $ret;
        }
        return $ret;
    }

    private function xmlRpcCall($method, $params, $endPointUrl = null) {
        if ($this->erpDebug) {
            echo "In params to $this->erpEndPoint:\n";
            print_r($params);
        }

        if (!$endPointUrl) {
            $endPointUrl = $this->erpEndPoint;
        }

        $request = xmlrpc_encode_request($method, $params);

        $context = stream_context_create(array('http' => array(
                        'method' => "POST",
                        'header' => "Content-Type: text/xml; charset=utf-8",
                        'content' => $request
                        )));
        $file = file_get_contents($endPointUrl, false, $context);
        if ($this->erpDebug) {
            echo "Out response:\n";
            echo $file;
        }
        $response = xmlrpc_decode($file);

        if (is_array($response) && xmlrpc_is_fault($response)) {
            throw new Exception($response['faultString'], $response['faultCode']);
        } else {
            return $response;
        }
    }

    public function getErpProductIds() {
        $arr = array();
        $res = $this->read(array(
                    'model' => 'product.product',
                    'fields' => array('id'),
                    'domain' => array(array("pos_categ_id", "<>", false),array("available_in_pos", "=", true))
                ));
        if (is_array($res['records'])) {
            foreach ($res['records'] as $record) {
                $arr[] = $record['id'];
            }
        }
        return $arr;
    }

    public function getErpProductInfo($id) {
        $arr = array();
        $res = $this->read(array(
                    'model' => 'product.product',
                    'fields' => array('id', 'name', 'lst_price', 'code', 'qty_available', 'price_extra', 'pos_categ_id', 'active', 'image','state'),
                    'domain' => array(array("id", "=", $id))
                ));
        $arr = $res['records'][0];
        if (is_array($arr) && !empty($arr)) {
            $arr['description'] = $this->getErpProductDesc($id);
            $catArr = $arr['pos_categ_id'];
            $arr['cat_id'] = $catArr[0];
            $arr['cat_name'] = $catArr[1];
            unset($arr['pos_categ_id']);
        }

        foreach ($arr as $k => $v) {
            $arr[$k] = $this->toUtf8($v);
        }
        return $arr;
    }

    public function getErpProductDesc($id) {
        $arr = array();
        $res = $this->read(array(
                    'model' => 'product.template',
                    'fields' => array('description'),
                    'domain' => array(array("id", "=", $id))
                ));
        $arr = $res['records'][0];
        if (is_array($arr) && !empty($arr)) {
            return trim($arr['description']);
        }

        return '';
    }

    private function toUtf8($in) {
        if (is_array($in)) {
            foreach ($in as $key => $value) {
                $out[$this->toUtf8($key)] = $this->toUtf8($value);
            }
        } elseif (is_string($in)) {
            if (mb_detect_encoding($in) != "UTF-8")
                return utf8_encode($in);
            else
                return $in;
        } else {
            return $in;
        }
        return $out;
    }

}
