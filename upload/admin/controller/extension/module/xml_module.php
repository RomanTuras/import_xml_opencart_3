<?php

class ControllerExtensionModuleXmlModule extends Controller {
	private $error = array();
	private $updated_cnt = 0;
	private $inserted_cnt = 0;
	private $language_id = 1; //current language

	public function index() {

		$this->load->language('extension/module/xml_module');
		$this->load->model('setting/setting');

		$this->document->setTitle($this->language->get('heading_title'));

		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
			$this->model_setting_setting->editSetting('module_xml_module', $this->request->post);
			$this->session->data['success'] = $this->language->get('text_success');
			$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true));
		}

		if (isset($this->error['warning'])) {
			$data['error_warning'] = $this->error['warning'];
		} else {
			$data['error_warning'] = '';
		}

		$data['breadcrumbs'] = array();

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/module/xml_module', 'user_token=' . $this->session->data['user_token'], true)
		);

		$data['action'] = $this->url->link('extension/module/xml_module', 'user_token=' . $this->session->data['user_token'], true);

		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true);

		if (isset($this->request->post['module_xml_module_status'])) {
			$data['module_xml_module_status'] = $this->request->post['module_xml_module_status'];
		} else {
			$data['module_xml_module_status'] = $this->config->get('module_xml_module_status');
		}

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$data['xml_import_link'] = 'https://b2b-sandi.com.ua/export_xml/4fb01c5bf3957d849de460be7eb84821';

		$data['import_xml'] = (HTTPS_SERVER . 'index.php?route=extension/module/xml_module/export_xml&token=' . $this->session->data['user_token']);

		$data['user_token'] = $this->session->data['user_token'];

		$data['export_xml'] = (HTTPS_SERVER . 'controller/export_xml/export.php');

		$this->response->setOutput($this->load->view('extension/module/xml_module', $data));
	}

	/**
	* Importing goods from XML url and save them do DB
	*/
	public function import_xml(){

		$this->load->language('extension/module/xml_module');

		$this->load->model('extension/module/xml_module');

		$this->load->model('catalog/attribute_group');

		$this->load->model('catalog/attribute');

		$this->load->model('catalog/manufacturer');

		$this->load->model('catalog/product');

		$json = array();
		$xml_url = "";

		// Check user has permission
		if (!$this->user->hasPermission('modify', 'extension/module/xml_module')) {
			$json['error'] = $this->language->get('error_permission');
		}

		if ($_POST["xml_url"]){
			$xml_url = $_POST["xml_url"];
			$xml = simplexml_load_file($xml_url) or die("feed not loading");
			$this->parseCategories($xml);
			$this->parseProducts($xml);
		}

		if (!$json) {
			$json['success'] = $this->language->get('text_import_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));

		$this->session->data['success'] = $this->language->get('text_success');
	}

	/**
	* Parsing XML and adding products to DB
	* @param SimpleXMLElement Object
	*/
	private function parseProducts($xml){
		$catalogue = $xml->shop->offers;
		$data = array();

		foreach ($catalogue as $offer) {
			
			foreach ($offer as $product) {

				$isMainImageSet = false;
				$data['product_image'] = array();

				foreach ($product->attributes() as $key => $value) {
					if ($key == 'id') $data['sku'] = $value;
					elseif ($key == 'available') $data["stock_status_id"] = $value == true ? 7 : 5;
					elseif ($key == 'instock') $data["quantity"] = $value;
				}

				$data['price'] = $product->price;
				$data['currencyId'] = $product->currencyId;
				$data['product_category'] = array( $product->categoryId );

				foreach ($product->picture as $image) {
					if(!$isMainImageSet) { //main image
						$data['image'] = $image;
						$isMainImageSet = true;
					}else{ //additional images
						array_push($data['product_image'], $image);
					}
				}

				$data['shipping'] = $product->delivery == true ? 1 : 0;
				$data['name'] = $product->name;
				$data['vendor'] = $product->vendor;
				$data['vendorCode'] = $product->vendorCode;
				$data['product_description'] = array(
					$this->language_id => 
					array(
						'language_id' => $this->language_id,
						'name' => $product->name,
						'description' => $product->description,
						'tag' => '',
						'meta_title' => $product->name,
						'meta_description' => $product->description,
						'meta_keyword' => ''
					)
				);
				$data['model'] = $product->model;

				$manufacturers = $this->model_extension_module_xml_module->getIdManufacturerByName($product->vendor);
				if( !empty($manufacturers) ) $data['manufacturer_id'] = $manufacturers['manufacturer_id'];
				else {
					$data['manufacturer_id'] = $this->model_catalog_manufacturer->addManufacturer(
						array(
							'name' => $product->vendor,
							'sort_order' => '0'
						));
				}

				$attribute_text = $product->param;
				$attribute_id = 0;

				$attribute_data['attribute_description'] = array();
				if(!empty($product->param)){
					foreach ($product->param->attributes() as $key => $value) {
						if ($key == 'name'){
							$list_id_attributs = $this->model_extension_module_xml_module->getIdAttributeByName($value);
							if( empty($list_id_attributs) ){//If attribute not exist
								$category_name = $this->model_extension_module_xml_module->getCategoryNameById($product->categoryId);
								$list_id_groups_attributes = $this->model_extension_module_xml_module->getIdAttributeGroupByName($category_name['name']);
								$attribute_data['attribute_group_id'] = $list_id_groups_attributes['attribute_group_id'];
								$attribute_data['attribute_description'] = array(
										$this->language_id => array( 'name' => $value	)
									);
								$attribute_data['sort_order'] = 0;
								$attribute_id = $this->model_catalog_attribute->addAttribute($attribute_data);
							}else{//Attribute already exist
								$attribute_id = $list_id_attributs['attribute_id'];
							}
						}
					}
				}
				


// $this->log->write( $key .' - '. $value );
				$data['product_attribute'] = array();
				if(!empty($product->param)){
					foreach ($product->param->attributes() as $key => $value) {
						if ($key == 'name'){
							array_push($data['product_attribute'], array(
								'attribute_id' => $attribute_id,
								'product_attribute_description' => array(
										$this->language_id => array( 'text' => $attribute_text )
									)
								)
							);
						}
					}
				}

				$data['upc'] = '';
				$data['ean'] = '';
				$data['jan'] = '';
				$data['isbn'] = '';
				$data['mpn'] = '';
				$data['location'] = '';
				$data['minimum'] = '1';
				$data['subtract'] = '';
				$data['date_available'] = '';
				$data['points'] = '';
				$data['weight'] = '';
				$data['weight_class_id'] = '';
				$data['length'] = '';
				$data['width'] = '';
				$data['height'] = '';
				$data['length_class_id'] = '';
				$data['status'] = 1;
				$data['tax_class_id'] = '';
				$data['sort_order'] = '';

				$data['product_layout'] = array('0' => '0');
				$data['product_store'] = array('0');

				$products = $this->model_extension_module_xml_module->getProductIdByModel($data['model']);
				$product_id = 0;
				if( !empty($products) ) {
					$product_id = $products['product_id'];
					$this->model_catalog_product->editProduct($product_id, $data);
				}else{
					$product_id = $this->model_catalog_product->addProduct($data);
				}
			}
		}
	}

	/**
	* Parsing XML and adding categories to DB
	* @param SimpleXMLElement Object
	*/
	private function parseCategories($xml){
		$catalogue = $xml->shop->categories;
		$data = array();
		foreach ($catalogue as $categories) {
			foreach ($categories as $category) {
				$data['name'] = $category;
				foreach ($category->attributes() as $key => $value) {
					$data['parent_id'] = 0;
					if ($key == 'id') $data['id'] = $value;
					elseif ($key == 'parentId') $data['parent_id'] = (int)$value;
				}
				$this->addCategory($data);
				$this->addAttributeGroup($data);
			}
		}
	}

	/**
	* Adding attribute group to DB
	* Attribute group name = category name
	* @param [] $data
	* @return Int
	*/
	protected function addAttributeGroup($data) {
		$result = $this->model_extension_module_xml_module->getIdAttributeGroupByName($data['name']);
		$attribute_group_id = 0;
		if(empty($result)){
			$data['attribute_group_description'] = array(
				$this->language_id => 
				array(
					'language_id' => $this->language_id,
					'name' => $data['name']
				)
			);
			$data['sort_order'] = 0;
			$attribute_group_id = $this->model_catalog_attribute_group->addAttributeGroup($data);
		}else $attribute_group_id = $result['attribute_group_id'];
		return $attribute_group_id;
	}

	/**
	* Adding category to DB
	* @param [] $data
	*/
	protected function addCategory($data) {
		$category_id = (int)$data['id'];
		if( !empty( $this->model_extension_module_xml_module->getCategory($category_id) ) ) { //category already exist, UPDATE!
			$this->updateCategory($category_id, $data);
		}else { //category not found, INSERT!
			$this->insertCategory($data);
		}
	}

	/**
	* Preparing data array for category
	* @param [] $data
	* @return [] $data
	*/
	protected function prepareCategoryData($data) {
		$data['top'] = $data['parent_id'] == 0 ? 1 : 0;
		$data['column'] = 0;
		$data['sort_order'] = 0;
		$data['status'] = 1;
		$data['category_description'] = array(
			$this->language_id => 
			array(
				'category_id' => (int)$data['id'],
				'language_id' => $this->language_id,
				'name' => $data['name'],
				'description' => '',
				'meta_title' => $data['name'],
				'meta_description' => '',
				'meta_keyword' => ''
			)
		);
		$data['category_store'] = array(0);
		$data['category_layout'] = array(0 => 0);
		return $data;
	}

	/**
	* Updating category to DB
	* @param [] $data
	*/
	protected function updateCategory($category_id, $data) {
		$data = $this->prepareCategoryData($data);
		$this->model_extension_module_xml_module->editCategory($category_id, $data);
	}

	/**
	* Inserting category to DB
	* @param [] $data
	* @return Int - ID of inserted category
	*/
	protected function insertCategory($data) {
		$data = $this->prepareCategoryData($data);
		return $this->model_extension_module_xml_module->addCategory($data);
	}

	protected function validate() {
		if (!$this->user->hasPermission('modify', 'extension/module/xml_module')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}

		return !$this->error;
	}
}
