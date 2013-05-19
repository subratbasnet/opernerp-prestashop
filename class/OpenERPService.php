<?php

class OpenERPService {

    public $erpDebug = false;
    private $erpDb = 'my_erp_db';
    private $erpUserId = 1; // 1 is for admin, usually
    private $erPwd = 'mypassword';
    private $erpEndPoint = 'http://example.com:8069/xmlrpc/object';

    public function __construct() {

        // override from environment variable
        $vars = get_class_vars(__CLASS__);
        print_r($vars);
        foreach ($vars as $var) {

            $this->$var = $this->getEnv($var);
        }
    }

    private function getEnv($variable) {
        $val = getenv($variable);
        echo "processing $variable\n";
        $ret = ($val != '') ? $val : $this->$variable;
        if ($val == 'erpDebug') {
            $ret = (int) $ret;
        }
        return $ret;
    }

    private function xmlRpcCall($method, $params, $endPointUrl = null) {
        if ($this->erpDebug) {
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
        $args = array(array("available_in_pos", "=", true));
        $model = 'product.product';
        return $this->xmlRpcCall('execute', array($this->erpDb, $this->erpUserId, $this->erPwd, $model, 'search', $args));
    }

    public function getErpProductInfo($id) {
        $args = array('id', 'name', 'lst_price', 'code', 'qty_available', 'price_extra', 'image', 'pos_categ_id', 'active');
        $model = 'product.product';
        $arr = $this->xmlRpcCall('execute', array($this->erpDb, $this->erpUserId, $this->erPwd, $model, 'read', $id, $args));
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
        $args = array('description');
        $model = 'product.template';
        $desc = ($this->xmlRpcCall('execute', array($this->erpDb, $this->erpUserId, $this->erPwd, $model, 'read', $id, $args)));
        return trim($desc['description']);
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
