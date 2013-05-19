<?php

require_once __DIR__ . '/PSWebServiceLibrary.php';

class SimpleXMLExtended extends SimpleXMLElement {

    public function addCData($cdataText) {
        $node = dom_import_simplexml($this);
        $no = $node->ownerDocument;
        $node->appendChild($no->createCDATASection($cdataText));
    }

}

class PrestaService extends PrestaShopWebservice {

    protected $prestaUrl = 'http://www.myprestawebsite.com';
    protected $prestaApiKey = 'apikeygoeshere';
    // modify these categories according to your installation
    private $isRootCategory = 1;
    private $parentCategoryId = 1;
    private $curMapIndex = null;
    private $mappingsFile;
    private $mappings = array();
    private $homeCategoryId = 2;
    private $logs = array();
    private $curImageId = 0;

    public function __construct($debug = false) {
        
        // give environment variables a higher priority
        $this->prestaUrl = $this->getEnv('prestaUrl');
        $this->prestaApiKey = $this->getEnv('prestaApiKey');

        parent::__construct($this->prestaUrl, $this->prestaApiKey, $debug);
        $this->mappingsFile = __DIR__ . '/../tmp/mappings.json';
        $this->mappings = json_decode(@file_get_contents($this->mappingsFile), true);
    }

    private function getEnv($variable) {
        $val = getenv($variable);
        $ret = ($val != '') ? $val : $this->$variable;
        return $ret;
    }

    private function getCategoryId($categoryName) {
        $opt = array('resource' => 'categories', 'display' => '[id,name,active]', 'filter[name]' => $categoryName);
        $xml = $this->get($opt);
        $categories = $xml->xpath('/prestashop/categories/category');
        foreach ($categories as $category) {
            return (int) $category->id;
        }

        return 0;
    }

    private function categoryExists($categoryId) {

        if (!$categoryId) {
            return 0;
        }

        $opt = array('resource' => 'products', 'display' => '[name,active,id,reference]', 'filter[id]' => $categoryId);
        $xml = $this->get($opt);
        $products = $xml->xpath('/prestashop/categories/category');

        foreach ($products as $product) {
            return (int) $product->id;
        }

        return 0;
    }

    private function getProductId($productName) {
        $opt = array('resource' => 'products', 'display' => 'full', 'filter[name]' => $productName);
        $xml = $this->get($opt);
        $products = $xml->xpath('/prestashop/products/product');

        foreach ($products as $product) {
            $this->curImageId = (int) $product->id_default_image;
            return (int) $product->id;
        }

        return 0;
    }

    private function productExists($productId) {

        if (!$productId) {
            return 0;
        }

        $opt = array('resource' => 'products', 'display' => 'full', 'filter[id]' => $productId);
        $xml = $this->get($opt);
        $products = $xml->xpath('/prestashop/products/product');

        foreach ($products as $product) {
            $this->curImageId = (int) $product->id_default_image;
            return (int) $product->id;
        }

        return 0;
    }

    private function createCategory($categoryName, $categoryDesc, $remoteCategoryId, $active = 1, $metaTitle = '', $metaDesc = '', $metaKeywords = '') {

        $categoryName = ucwords(trim($categoryName));
        $metaTitle = empty($metaTitle) ? $categoryName : $metaTitle;
        $metaDesc = empty($metaDesc) ? $categoryDesc : $metaDesc;
        $metaKeywords = empty($metaKeywords) ? strtolower($categoryName) : $metaKeywords;
        $linkRewrite = $this->getRewrite($categoryName);

        $remoteCategoryId = (int) $remoteCategoryId;
        $categoryId = $this->categoryExists($this->translateMapping($remoteCategoryId, 'openerp_categoryId', 'presta_categoryId'));

        if (!$categoryId) {
            $categoryId = $this->getCategoryId($categoryName);
        }

        $xml = $this->get(array('url' => $this->prestaUrl . '/api/categories?schema=blank'));
        $resources = $xml->children()->children();
        unset($resources->position);
        unset($resources->id_shop_default);
        //unset($resources->date_add);
        //unset($resources->date_upd);
        unset($resources->associations);
        $resources->date_upd->addCData(date("Y-m-d H:i:s"));
        $resources->date_add->addCData(date("Y-m-d H:i:s"));
        //$resources->id_shop_default = 1;
        $resources->id_parent = $this->parentCategoryId;
        $resources->is_root_category = $this->isRootCategory;
        $resources->name->language[0][0]->addCData($categoryName);
        $resources->description->language[0][0]->addCData($categoryDesc);
        $resources->link_rewrite->language[0][0]->addCData($linkRewrite);
        $resources->meta_title->language[0][0]->addCData($metaTitle);
        $resources->meta_description->language[0][0]->addCData($metaDesc);
        $resources->meta_keywords->language[0][0]->addCData($metaKeywords);

        try {
            $opt = array('resource' => 'categories');

            if ($categoryId) {
                $this->log("\t[Updating]");
                $resources->id = $categoryId;
                $resources->active = $active;
                $opt['putXml'] = $xml->asXML();
                $opt['id'] = $categoryId;
                $this->edit($opt);
            } else {
                $this->log("\t[Creating]");
                unset($resources->id);
                $resources->active = $active;
                $opt['postXml'] = $xml->asXML();
                $xml = $this->add($opt);
                $res = $xml->children()->children();
                $categoryId = (int) $res->id;
            }

            //$this->remoteToPrestaCategories[$remoteCategoryId] = $categoryId;

            $this->mappings[$this->curMapIndex]['openerp_categoryId'] = $remoteCategoryId;
            $this->mappings[$this->curMapIndex]['presta_categoryId'] = $categoryId;

            return $categoryId;
        } catch (PrestaShopWebserviceException $ex) {
            echo $ex->getMessage();
            return 0;
        }
    }

    private function createProduct($params) {

        $productName = ucwords(trim($params['name']));
        $productDesc = trim($params['description']);
        $metaTitle = empty($metaTitle) ? $productName : $metaTitle;
        //$metaDesc = empty($metaDesc) ? $productDesc : $metaDesc;
        $metaDesc = '';
        $metaKeywords = empty($metaKeywords) ? strtolower($productName) : $metaKeywords;
        $linkRewrite = $this->getRewrite($productName);
        $active = (int) $params['active'];
        $remoteProductId = $params['id'];
        $productRef = $params['code'];

        $productDesc = nl2br($productDesc);
        $productDesc = $this->makeLinks($productDesc);


        $xml = $this->get(array('url' => $this->prestaUrl . '/api/products?schema=blank'));
        $resources = $xml->children()->children();
        unset($resources->id);
        unset($resources->position);
        unset($resources->id_shop_default);
        //unset($resources->date_add);
        //unset($resources->date_upd);
        //unset($resources->associations);
        unset($resources->images);
        $resources->date_upd = $resources->date_add = date("Y-m-d H:i:s");
        //$resources->id_shop_default = 1;
        $resources->reference->addCData($productRef);
        $resources->id_category_default = $this->homeCategoryId; //$params['p_cat_id'];
        $resources->name->language[0][0]->addCData($productName);
        $resources->description->language[0][0]->addCData($productDesc);
        $resources->link_rewrite->language[0][0]->addCData($linkRewrite);
        $resources->meta_title->language[0][0]->addCData($metaTitle);
        $resources->meta_description->language[0][0]->addCData($metaDesc);
        $resources->meta_keywords->language[0][0]->addCData($metaKeywords);
        $resources->show_price = 1;
        $resources->price->addCData($params['lst_price']);
        $resources->available_for_order = $active;
        $resources->associations->categories->category[0]->id = $this->homeCategoryId;
        $resources->associations->categories->category[1]->id = $params['p_cat_id'];
        $resources->indexed = 1;
        $resources->minimal_quantity = 1;
        $resources->active = $active;

        $productId = $this->productExists($this->translateMapping($remoteCategoryId, 'openerp_productId', 'presta_productId'));

        if (!$productId) {
            $productId = $this->getProductId($productName);
        }

        try {
            $opt = array('resource' => 'products');

            if ($productId) {
                $this->log("\t[Updating]");
                $resources->id = $productId;
                $opt['putXml'] = $xml->asXML();
                $opt['id'] = $productId;
                $this->edit($opt);
            } else {
                $this->log("\t[Creating]");
                unset($resources->id);
                $resources->active = $active;
                $opt['postXml'] = $xml->asXML();
                $xml = $this->add($opt);
                $res = $xml->children()->children();
                $productId = (int) $res->id;
            }

            $this->mappings[$this->curMapIndex]['openerp_productId'] = $remoteProductId;
            $this->mappings[$this->curMapIndex]['presta_productId'] = $productId;

            return $productId;
        } catch (PrestaShopWebserviceException $ex) {
            echo $ex->getMessage();
            return 0;
        }
    }

    public function createImage($productId, $params) {

        try {
            if ($productId && strlen($params['image'])) {

                $tempFile = __DIR__ . '/../tmp/temp.png';
                file_put_contents($tempFile, base64_decode($params['image']));
                if (filesize($tempFile)) {

                    $curlParams = array(CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_POSTFIELDS => array('image' => '@' . $tempFile));
                    $res = $this->executeRequest($this->prestaUrl . '/api/images/products/' . $productId, $curlParams);
                    $response = $res['response'];
                    //echo $response;
                    $xml = $this->parseXML($response);
                    $images = $xml->xpath('/prestashop/image');

                    foreach ($images as $image) {
                        $this->mappings[$this->curMapIndex]['presta_imageId'] = (int) $image->id;
                        return (int) $image->id;
                    }
                }
            }
        } catch (PrestaShopWebserviceException $ex) {
            echo $ex->getMessage();
        }

        return 0;
    }

    public function sync($params) {



        if (!$params['name']) {
            //$this->log("Invalid product name $params[name]");
            return;
        }

        if (is_null($this->curMapIndex)) {
            $this->curMapIndex = 0;
        } else {
            $this->curMapIndex++;
        }
        $this->curImageId = 0;

        $this->log("Syncing OpenERP product '$params[name]' with ID: $params[id]' in category '$params[cat_name]' with ID: $params[cat_id] to => PRESTA");
        $this->log("\tCreating/updating category '$params[cat_name]'");
        if (!$params['cat_name']) {
            $this->log("\tEmpty OpenERP category name encountered, skipping!");
            return;
        }
        $catId = $this->createCategory($params['cat_name'], '', $params['cat_id']);

        if ($catId) {
            $this->log("\tOK! PRESTA category ID: $catId");
            $this->log("\tCreating/updating product '$params[name]'");
            $params['p_cat_id'] = $catId;
            $productId = $this->createProduct($params);
            if ($productId) {
                $this->log("\tOK! PRESTA product ID: $productId");

                if (!$this->curImageId && strlen($params['image'])) {
                    $this->log("\tAdding/updating image '$params[name]'");
                    $imageId = $this->createImage($productId, $params);

                    if ($imageId) {
                        $this->log("\tOK! PRESTA image ID: $imageId");
                    } else {
                        $this->log("\t*****FAILED*****");
                    }
                }
            } else {
                $this->log("\t*****FAILED*****");
            }
        } else {
            $this->log("\tCould not create/update '$params[cat_name]'");
        }
        $this->log('LINE');
    }

    public function __destruct() {

        file_put_contents($this->mappingsFile, json_encode($this->mappings));
        $this->log("\nSync Complete!");

        $headers = array();
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-type: text/plain; charset=iso-8859-1";
        $headers[] = "From: PrestaERPSync <sync@machapokhari.com>";
        $headers[] = "Bcc: Macha Pokhari <info@machapokhari.com>";
        $headers[] = "X-Mailer: PHP/" . phpversion();

        $msg = implode('', $this->logs);
        $msg = str_replace("\n", "\r\n", $msg);
        mail('subrat@myktm.com', 'Presta - OpenERP sync summary', $msg, implode("\r\n", $headers));
    }

    private function log($str) {
        if ($str == 'LINE') {
            $line = "=======================================================\n";
        } else {
            $line = '[' . date('Y-m-d H:i:s') . ']' . $str . "\n";
        }
        echo $line;
        $this->logs[] = $line;
    }

    private function getRewrite($text, $limit = 75) {
        // replace non letter or digits by -
        $text = preg_replace('~[^\\pL\d]+~u', '-', $text);

        // trim
        $text = trim($text, '-');

        // lowercase
        $text = strtolower($text);

        // remove unwanted characters
        $text = preg_replace('~[^-\w]+~', '', $text);

        if (strlen($text) > $limit) {
            $text = substr($text, 0, 70);
        }

        if (empty($text)) {
            //return 'n-a';
            return time();
        }

        return $text;
    }

    public function get($options) {
        $xml = parent::get($options);
        return new SimpleXMLExtended($xml->asXML());
    }

    private function makeLinks($text) {
        $text = preg_replace('%(((f|ht){1}tp://)[-a-zA-^Z0-9@:\%_\+.~#?&//=]+)%i', '<a href="\\1" target="_new">\\1</a>', $text);
        $text = preg_replace('%([[:space:]()[{}])(www.[-a-zA-Z0-9@:\%_\+.~#?&//=]+)%i', '\\1<a href="http://\\2" target="_new">\\2</a>', $text);

        return $text;
    }

    private function translateMapping($searchValue, $from, $to) {
        foreach ($this->mappings as $map) {
            if ($map[$from] == $searchValue) {
                return trim($map[$to]);
            }
        }

        return null;
    }

    public function printDebug($title, $content) {
        echo "Title: $title\nContent: $content\n\n";
    }

}
