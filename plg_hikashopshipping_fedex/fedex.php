<?php
defined('_JEXEC') or die('Restricted access');
?>
<?php
class plgHikashopshippingFedEx extends JPlugin
{
	var $packages;    // array of packages
	var $packageCount;    // number of packages in this shipment
	var $fedex_methods = array(
		array('code' => 'FEDEX_GROUND', 'name' => 'FedEx Ground', 'countries' => 'USA, PUERTO RICO', 'zones' => array('country_United_States_of_America_223','country_Puerto_Rico_172') , 'destinations' => array('country_United_States_of_America_223','country_Puerto_Rico_172')),
		array('code' => 'FEDEX_2_DAY', 'name' => 'FedEx 2 Day', 'countries' => 'USA, PUERTO RICO', 'zones' => array('country_United_States_of_America_223','country_Puerto_Rico_172'), 'destinations' => array('country_United_States_of_America_223','country_Puerto_Rico_172')),
		array('code' => 'FEDEX_EXPRESS_SAVER', 'name' => 'FedEx Express Saver Time Pickup', 'countries' => 'USA, PUERTO RICO', 'zones' => array('country_United_States_of_America_223','country_Puerto_Rico_172'), 'destinations' => array('country_United_States_of_America_223','country_Puerto_Rico_172')),
		array('code' => 'FIRST_OVERNIGHT', 'name' => 'FedEx First Overnight', 'countries' => 'USA, PUERTO RICO', 'zones' => array('country_United_States_of_America_223','country_Puerto_Rico_172'), 'destinations' => array('country_United_States_of_America_223','country_Puerto_Rico_172')),
		array('code' => 'GROUND_HOME_DELIVERY', 'name' => 'FedEx Ground (Home Delivery)', 'countries' => 'USA, PUERTO RICO', 'zones' => array('country_United_States_of_America_223','country_Puerto_Rico_172'), 'destinations' => array('country_United_States_of_America_223','country_Puerto_Rico_172')),
		array('code' => 'PRIORITY_OVERNIGHT', 'name' => 'FedEx Priority Overnight', 'countries' => 'USA, PUERTO RICO', 'zones' => array('country_United_States_of_America_223','country_Puerto_Rico_172'), 'destinations' => array('country_United_States_of_America_223','country_Puerto_Rico_172')),
		array('code' => 'SMART_POST', 'name' => 'FedEx Smart Post', 'countries' => 'USA, PUERTO RICO', 'zones' => array('country_United_States_of_America_223','country_Puerto_Rico_172'), 'destinations' => array('country_United_States_of_America_223','country_Puerto_Rico_172')),
		array('code' => 'STANDARD_OVERNIGHT', 'name' => 'FedEx Standard Overnight', 'countries' => 'USA, PUERTO RICO', 'zones' => array('country_United_States_of_America_223','country_Puerto_Rico_172'), 'destinations' => array('country_United_States_of_America_223','country_Puerto_Rico_172')),
		array('code' => 'INTERNATIONAL_GROUND', 'name' => 'FedEx International Ground'),
		array('code' => 'INTERNATIONAL_ECONOMY', 'name' => 'FedEx International Economy'),
		array('code' => 'INTERNATIONAL_ECONOMY_DISTRIBUTION', 'name' => 'FedEx International Economy Distribution'),
		array('code' => 'INTERNATIONAL_FIRST', 'name' => 'FedEx International First'),
		array('code' => 'INTERNATIONAL_PRIORITY', 'name' => 'FedEx International Priority'),
		array('code' => 'INTERNATIONAL_PRIORITY_DISTRIBUTION', 'name' => 'FedEx International Priority Distribution'),
		array('code' => 'EUROPE_FIRST_INTERNATIONAL_PRIORITY', 'name' => 'FedEx Europe First'),
	);
	var $convertUnit=array(
		'kg' => 'KGS',
		'lb' => 'LBS',
		'cm' => 'CM',
		'in' => 'IN',
		'kg2' => 'kg',
		'lb2' => 'lb',
		'cm2' => 'cm',
		'in2' => 'in',
	);

	function onShippingDisplay(&$order,&$dbrates,&$usable_rates,&$messages){

		if(empty($dbrates)){
			$messages['no_rates'] = JText::_('NO_SHIPPING_METHOD_FOUND');
		}else{
			$rates = array();
			foreach($dbrates as $k => $rate){
				if($rate->shipping_type=='fedex')
					$rates[]=$rate;
			}

			if(empty($rates)){
				$messages['no_rates'] = JText::_('NO_SHIPPING_METHOD_FOUND');
				return true;
			}
			$found = true;

			if(bccomp($order->total->prices[0]->price_value,0,5)){

				$zoneClass=hikashop_get('class.zone');
				$zones = $zoneClass->getOrderZones($order);
				foreach($rates as $k => $rate){

					if(!empty($rate->shipping_params->methodsList)){
						$rate->shipping_params->methods=unserialize($rate->shipping_params->methodsList);
					}
					else{
						$messages['no_shipping_methods_configured'] = 'No shipping methods configured in the FedEx shipping plugin options';
						return true;
					}
					if($order->weight<=0 || ($order->volume<=0 && @$rate->shipping_params->use_dimensions == 1)){
						return true;
					}
				}

				$this->freight=false;
				$this->classicMethod=false;
				$heavyProduct=false;
				$weightTotal=0;

				if(!empty($rate->shipping_params->methods)){
					foreach($rate->shipping_params->methods as $method){
						if($method=='TDCB' || $method=='TDA' || $method=='TDO' || $method=='308' || $method=='309' || $method=='310'){
							$this->freight=true;
						}
						else{
							$this->classicMethod=true;
						}
					}
				}

				$data=null;

				if(empty($order->shipping_address)){
					$messages['no_shipping_methods_configured'] = 'No shipping address is configured.';
					return true;
				}

				$this->shipping_currency_id=$currency= hikashop_getCurrency();
				$db = &JFactory::getDBO();
				$query='SELECT currency_code FROM '.hikashop_table('currency').' WHERE currency_id IN ('.$this->shipping_currency_id.')';
				$db->setQuery($query);
				$this->shipping_currency_code = $db->loadResult();
				$cart = hikashop_get('class.cart');
				$null = null;
				$cart->loadAddress($null,$order->shipping_address->address_id,'object', 'shipping');
				$currency = hikashop_get('class.currency');
				$config =& hikashop_config();
				$this->main_currency = $config->get('main_currency',1);
				if(empty($rate->shipping_params->handling_fees)){
					$rate->shipping_params->handling_fees=0;
				}
				if($this->shipping_currency_id==$this->main_currency){
					$price=$order->total->prices[0]->price_value_with_tax;
					$handlingFees=$rate->shipping_params->handling_fees;
				}else{
					$currencyClass = hikashop_get('class.currency');
					$price=$currencyClass->convertUniquePrice($order->total->prices[0]->price_value_with_tax,$this->shipping_currency_id, $this->main_currency);
					$handlingFees=$currencyClass->convertUniquePrice($rate->shipping_params->handling_fees, $this->main_currency, $this->shipping_currency_id);
				}
				if(empty($rate->shipping_params->shipping_min_price)){
					$rate->shipping_params->shipping_min_price=0;
				}else{
					if($price<$rate->shipping_params->shipping_min_price){
						$messages['order_total_too_low'] = JText::_('ORDER_TOTAL_TOO_LOW_FOR_SHIPPING_METHODS');
						return true;
					}
				}

				if(empty($rate->shipping_params->shipping_max_price)){
					$rate->shipping_params->shipping_max_price=0;
				}else{
					if($price>$rate->shipping_params->shipping_max_price){
						$messages['order_total_too_higth'] = JText::_('ORDER_TOTAL_TOO_HIGH_FOR_SHIPPING_METHODS');
						return true;
					}
				}

				foreach($rates as $rate){
					$receivedMethods=$this->_getRates($rate, $order, $heavyProduct, $null);
				}
				if(empty($receivedMethods)){
					$messages['no_rates'] = JText::_('NO_SHIPPING_METHOD_FOUND');
					return true;
				}
				$i=0;
				$rate=(PHP_VERSION < 5) ? $rates[0] : clone($rates[0]);
				foreach($receivedMethods as $method){
					$usableMethods[]=$method;
					$rates[$i]=(PHP_VERSION < 5) ? $rate : clone($rate);
					$rates[$i]->shipping_price=0.0;
					if(!empty($rate->shipping_params->handling_fees_percent)){
						$rates[$i]->shipping_price+=$order->total->prices[0]->price_value_with_tax*($rate->shipping_params->handling_fees_percent/100);
					}
					$rates[$i]->shipping_price+=$method['value']+$handlingFees;
					foreach($this->fedex_methods as $fedex_method){
						if($method['old_currency_code']=='CAD'){
							if($fedex_method['code']== $method['code']){
								$name= $fedex_method['name'];
							}
						}else{
							if($fedex_method['code']== $method['code'] && !isset($fedex_method['double'])){
								$name= $fedex_method['name'];
							}
						}
					}
					$rates[$i]->shipping_name=$name;
					$rates[$i]->shipping_id=$name;

					$sep = '';
					if(@$rate->shipping_params->show_eta) {
						if(@$rate->shipping_params->show_eta_delay) {
							if($method['delivery_delay']!=-1 && $method['day']>0){
								$rates[$i]->shipping_description.=$sep.JText::sprintf( 'ESTIMATED_TIME_AFTER_SEND', $method['delivery_delay']);
							}else{
								$rates[$i]->shipping_description.=$sep.JText::_( 'NO_ESTIMATED_TIME_AFTER_SEND');
							}
						} else {
							if($method['delivery_day']!=-1 && $method['day']>0){
								$rates[$i]->shipping_description.=$sep.JText::sprintf( 'ESTIMATED_TIME_AFTER_SEND', $method['delivery_day']);
							}else{
								$rates[$i]->shipping_description.=$sep.JText::_( 'NO_ESTIMATED_TIME_AFTER_SEND');
							}
						}
						$sep = '<br/>';
						if($method['delivery_time']!=-1 && $method['day']>0){
							$rates[$i]->shipping_description.=$sep.JText::sprintf( 'DELIVERY_HOUR', $method['delivery_time']);
						}else{
							$rates[$i]->shipping_description.=$sep.JText::_( 'NO_DELIVERY_HOUR');
						}
					}
					if(@$rate->shipping_params->show_notes && !empty($method['notes'])) {
						foreach($method['notes'] as $note){
							if($note->Code != '820' && $note->Code != '819' && !empty($note->LocalizedMessage) ) {
								$rates[$i]->shipping_description.=$sep.implode('<br/>', $note->LocalizedMessage);
								$sep = '<br/>';
							}
						}
					}
					$i++;
				}
				foreach($rates as $i => $rate){
					$usable_rates[]=$rate;
				}
			}
		}
	}

	function onShippingConfiguration(&$elements){
		$config =& hikashop_config();
		$this->main_currency = $config->get('main_currency',1);
		$currencyClass = hikashop_get('class.currency');
		$currency = hikashop_getCurrency();
		$this->fedex = JRequest::getCmd('name','fedex');

		$this->currency = hikashop_get('type.currency');
		$this->categoryType = hikashop_get('type.categorysub');
		$this->categoryType->type = 'tax';
		$this->categoryType->field = 'category_id';
		$bar = & JToolBar::getInstance('toolbar');
		if(empty($elements)){
			$element = null;
			$element->shipping_name='FedEx';
			$element->shipping_description='';
			$element->group_package=0;
			$element->shipping_images='fedex';
			$element->shipping_type=$this->fedex;
			$element->shipping_params=null;
			$element->shipping_params->post_code='';
			$element->shipping_currency_id = $this->main_currency;
			$element->shipping_params->pickup_type='01';
			$element->shipping_params->destination_type='auto';
			$elements = array($element);
		}
		JToolBarHelper::save();
		JToolBarHelper::apply();
		$bar->appendButton( 'Link', 'cancel', JText::_('HIKA_CANCEL'), hikashop_completeLink('plugins&plugin_type=shipping') );
		JToolBarHelper::divider();
		$bar->appendButton( 'Pophelp','shipping-'.$this->fedex.'-form');
		hikashop_setTitle(JText::_('HIKASHOP_SHIPPING_METHOD'),'plugin','plugins&plugin_type=shipping&task=edit&name='.$this->fedex);
		$config =& hikashop_config();
		$this->main_currency = $config->get('main_currency',1);
		$currency = hikashop_get('class.currency');
		$this->currency = $currency->get($this->main_currency);
		$key = key($elements);

		if(empty($elements[$key]->shipping_params->lang_file_override)){
			jimport('joomla.filesystem.file');
			jimport('joomla.filesystem.folder');
			$folder = JLanguage::getLanguagePath(JPATH_ROOT).DS.'overrides';

			$content_override = 'FEDEX_METER_ID="Meter #"'."/r/n".'
				FEDEX_ACCOUNT_NUMBER="Account #"'."/r/n".'
				FEDEX_API_KEY="API Key"'."/r/n".'
				FEDEX_API_PASSWORD="API Password"'."/r/n".'
				FEDEX_SHOW_ETA="Show ETA?"'."/r/n".'
				FEDEX_SHOW_ETA_FORMAT="ETA Format"'."/r/n".'
				PACKAGING_TYPE="Packaging Type"'."/r/n".'
				BOX_DIMENSIONS="Box Dimensions"'."/r/n".'
				ORIGINATION_POSTCODE="Ship From Postcode"'."/r/n".'
				SENDER_COMPANY="Sender Company"'."/r/n".'
				SENDER_PHONE="Sender Phone"'."/r/n".'
				SENDER_ADDRESS="Sender Address"'."/r/n".'
				SENDER_CITY="Sender City"'."/r/n".'
				SENDER_STATE="Sender State"'."/r/n".'
				SENDER_POSTCODE="Sender Zip"'."/r/n";

			if(!JFolder::exists($folder)){
				JFolder::create($folder);
			}
			if(JFolder::exists($folder)){
				$path = $folder.DS.'en-GB.override.ini';
				$result = JFile::write($path, $content_override);
				if(!$result){
					hikashop_display(JText::sprintf('FAIL_SAVE',$path),'error');
				}else {
					$elements[$key]->shipping_params->lang_file_override = 1;
				}

			}
		}

		$js = '
		function deleteRow(divName,inputName,rowName){
			var d = document.getElementById(divName);
			var olddiv = document.getElementById(inputName);
			if(d && olddiv){
				d.removeChild(olddiv);
				document.getElementById(rowName).style.display=\'none\';
			}
			return false;
		}
		function deleteZone(zoneName){
			var d = document.getElementById(zoneName);
			if(d){
				d.innerHTML="";
			}
			return false;
		}
		';
		$doc =& JFactory::getDocument();
	 	$doc->addScriptDeclaration($js);
	}

	function onShippingConfigurationSave(&$elements){
		$warehouses = JRequest::getVar( 'warehouse', array(), '', 'array' );
		$cats = array();
		$methods=array();
		$db = &JFactory::getDBO();
		$zone_keys='';
		if(isset($_REQUEST['data']['shipping_methods'])){
			foreach($_REQUEST['data']['shipping_methods'] as $method){
				foreach($this->fedex_methods as $fedexMethod){
					$name=strtolower($fedexMethod['name']);
					$name=str_replace(' ','_', $name);
					if($name==$method['name']){
						$obj = null;
						$methods[strip_tags($method['name'])]=strip_tags($fedexMethod['code']);
					}
				}
			}
		}
		$elements->shipping_params->methodsList = serialize($methods);

		if(empty($cats)){
			$obj->name = '-';
			$obj->zip = '-';
			$obj->country = '-';
			$obj->zone = '-';
			$void[]=$obj;
			$elements->shipping_params->warehousesList = serialize($void);
		}
		return true;
	}

	// add all rates for each package together. apply any rate discounts.
	// returns an array of merged rates
	// TODO: move all fees, discounts, etc. calculations to class.Package

	function _getRates(&$rate, &$order, $heavyProduct, $null){
		$db = JFactory::getDBO();
		$total_price = 0;
		// order total
		foreach($order->products as $k=>$v){
			foreach($v->prices as $price){
				$total_price = $total_price + $price->price_value;
			}
		}

		$data['fedex_account_number']=@$rate->shipping_params->account_number;
		$data['fedex_meter_number']=@$rate->shipping_params->meter_id;
		$data['fedex_api_key']=@$rate->shipping_params->api_key;
		$data['fedex_api_password']=@$rate->shipping_params->api_password;
		$data['show_eta']=@$rate->shipping_params->show_eta;
		$data['show_eta_format']=@$rate->shipping_params->show_eta_format;
		$data['packaging_type']=@$rate->shipping_params->packaging_type;
		$data['include_price']=@$rate->shipping_params->include_price;
		$data['shipping_min_price']=@$rate->shipping_params->shipping_min_price;
		$data['shipping_max_price']=@$rate->shipping_params->shipping_max_price;
		$data['handling_fees']=@$rate->shipping_params->handling_fees;
		$data['handling_fees_percent']=@$rate->shipping_params->handling_fees_percent;
		$data['weight_approximation']=@$rate->shipping_params->handling_fees_percent;
		$data['use_dimensions']=@$rate->shipping_params->use_dimensions;
		$data['dim_approximation_l']=@$rate->shipping_params->dim_approximation_l;
		$data['dim_approximation_w']=@$rate->shipping_params->dim_approximation_w;
		$data['dim_approximation_h']=@$rate->shipping_params->dim_approximation_h;
		$data['methods']=@$rate->shipping_params->methods;
		$data['destZip']=@$null->shipping_address->address_post_code;
		$data['destCountry']=@$null->shipping_address->address_country->zone_code_2;
		$data['zip']=@$rate->shipping_params->origination_postcode;
		$data['total_insured']=@$total_price;
		$data['sender_company']=@$rate->shipping_params->sender_company;
		$data['sender_phone']=@$rate->shipping_params->sender_phone;
		$data['sender_address']=@$rate->shipping_params->sender_address;
		$data['sender_city']=@$rate->shipping_params->sender_city;
		$state_zone = '';
		$state_zone=@$rate->shipping_params->sender_state;
		$query="SELECT zone_id, zone_code_3 FROM ".hikashop_table('zone')." WHERE zone_namekey IN ('".$state_zone."')";
		$db->setQuery($query);
		$state = $db->loadObject();
		$data['sender_state']=$state->zone_code_3;
		$data['sender_postcode']=$rate->shipping_params->sender_postcode;
		$data['recipient']=$null->shipping_address;
		$czone_code = '';
		$czone_code=$rate->shipping_zone_namekey;
		$query="SELECT zone_id, zone_code_2 FROM ".hikashop_table('zone')." WHERE zone_namekey IN ('".$czone_code."')";
		$db->setQuery($query);
		$czone = $db->loadObject();
		$data['country'] = $czone->zone_code_2;

		$data['XMLpackage']='';
		$data['destType']='';
		if(@$rate->shipping_params->destination_type=='res'){
			$data['destType']='<ResidentialAddressIndicator/>';
		}
		if(@$rate->shipping_params->destination_type=='auto' && !isset($order->shipping_address->address_company)){
			$data['destType']='<ResidentialAddressIndicator/>';
		}
		$data['pickup_type']=@$rate->shipping_params->pickup_type;
		$totalPrice=0;
		if(($this->freight==true && $this->classicMethod==false) || ($heavyProduct==true && $this->freight==true)){
			$data['weight']=0;
			$data['height']=0;
			$data['length']=0;
			$data['width']=0;
			$data['price']=0;
			foreach($order->products as $product){
				if($product->product_parent_id==0){
					if(isset($product->variants)){
						foreach($product->variants as $variant){
							$caracs=$this->_convertCharacteristics($variant, $data);
							$data['weight_unit']=$caracs['weight_unit'];
							$data['dimension_unit']=$caracs['dimension_unit'];
							$data['weight']+=round($caracs['weight'],2)*$variant->cart_product_quantity;
							if($caracs['height'] != '' && $caracs['height'] != '0.00' && $caracs['height'] != 0){
								$data['height']+=round($caracs['height'],2)*$variant->cart_product_quantity;
								$data['length']+=round($caracs['length'],2)*$variant->cart_product_quantity;
								$data['width']+=round($caracs['width'],2)*$variant->cart_product_quantity;
							}

							$data['price']+=$variant->prices[0]->unit_price->price_value_with_tax*$variant->cart_product_quantity;
						}
					}
					else{
						$caracs=$this->_convertCharacteristics($product,$data);
						$data['weight_unit']=$caracs['weight_unit'];
						$data['dimension_unit']=$caracs['dimension_unit'];
						$data['weight']+=round($caracs['weight'],2)*$product->cart_product_quantity;
						if($caracs['height'] != '' && $caracs['height'] != '0.00' && $caracs['height'] != 0){
							$data['height']+=round($caracs['height'],2)*$product->cart_product_quantity;
							$data['length']+=round($caracs['length'],2)*$product->cart_product_quantity;
							$data['width']+=round($caracs['width'],2)*$product->cart_product_quantity;
						}
						$data['price']+=$product->prices[0]->unit_price->price_value_with_tax*$product->cart_product_quantity;
					}
				}
			}
			$data['XMLpackage'].=$this->_createPackage($data, $product, $rate, $order );

			$usableMethods=$this->_FEDEXrequestMethods($data);
			return $usableMethods;
		}

		if(@$rate->shipping_params->group_package){
			$data['weight']=0;
			$data['height']=0;
			$data['length']=0;
			$data['width']=0;
			$data['price']=0;
			foreach($order->products as $product){
				if($product->product_parent_id==0){
					if(isset($product->variants)){
						foreach($product->variants as $variant){
							for($i=0;$i<$variant->cart_product_quantity;$i++){
								$caracs=$this->_convertCharacteristics($variant, $data);
								$data['weight_unit']=$caracs['weight_unit'];
								$data['dimension_unit']=$caracs['dimension_unit'];
								$tmpHeight=$data['height']+round($caracs['height'],2);
								$tmpLength=$data['length']+round($caracs['length'],2);
								$tmpWidth=$data['width']+round($caracs['width'],2);
								$dim=$tmpLength+2*$tmpWidth+2*$tmpHeight;
								//if the package is too big with the last product, we create a package without this one
								$x=min($caracs['width'],$caracs['height'],$caracs['length']);
								if($x==$caracs['width']){
									$y=min($caracs['height'],$caracs['length']);
									if($y==$caracs['height']) $z=$caracs['length'];
									else $z=$caracs['height'];
								}
								if($x==$caracs['height']){
									$y=min($caracs['width'],$caracs['length']);
									if($y==$caracs['width']) $z=$caracs['length'];
									else $z=$caracs['width'];
								}
								if($x==$caracs['length']){
									$y=min($caracs['height'],$caracs['width']);
									if($y==$caracs['height']) $z=$caracs['width'];
									else $z=$caracs['height'];
								}
								if($data['weight']+round($caracs['weight'],2)>150 || $dim>165){
									$data['XMLpackage'].=$this->_createPackage($data, $product, $rate, $order );
									//size and weight are reseted to the last package we didn't include
									$data['weight']=round($caracs['weight'],2);
									$data['height']=max($data['height'],$y);
									$data['length']=max($data['length'],$z);
									$data['width']=max($data['width'],$x);
									$data['price']=$variant->prices[0]->unit_price->price_value_with_tax;
								}
								else{
									$data['weight']+=round($caracs['weight'],2);
									$data['height']=max($data['height'],$y);
									$data['length']=max($data['length'],$z);
									$data['width']+=$x;
									$data['price']+=$variant->prices[0]->unit_price->price_value_with_tax;
								}
							}
						}
					}
					else{
						for($i=0;$i<$product->cart_product_quantity;$i++){
							$caracs=$this->_convertCharacteristics($product, $data);
							$x=min($caracs['width'],$caracs['height'],$caracs['length']);
							if($x==$caracs['width']){
								$y=min($caracs['height'],$caracs['length']);
								if($y==$caracs['height']) $z=$caracs['length'];
								else $z=$caracs['height'];
							}
							if($x==$caracs['height']){
								$y=min($caracs['width'],$caracs['length']);
								if($y==$caracs['width']) $z=$caracs['length'];
								else $z=$caracs['width'];
							}
							if($x==$caracs['length']){
								$y=min($caracs['height'],$caracs['width']);
								if($y==$caracs['height']) $z=$caracs['width'];
								else $z=$caracs['height'];
							}
							$caracs=$this->_convertCharacteristics($product, $data);
							$data['weight_unit']=$caracs['weight_unit'];
							$data['dimension_unit']=$caracs['dimension_unit'];
							$tmpHeight=$data['height']+round($caracs['height'],2);
							$tmpLength=$data['length']+round($caracs['length'],2);
							$tmpWidth=$data['width']+round($caracs['width'],2);
							$dim=$tmpLength+2*$tmpWidth+2*$tmpHeight;
							if($data['weight']+round($caracs['weight'],2)>150 || $dim>165){
								$data['XMLpackage'].=$this->_createPackage($data, $product, $rate, $order );
								//size and weight are reseted to the last package we didn't include
								$data['weight']=round($caracs['weight'],2);
								$data['height']=max($data['height'],$y);
								$data['length']=max($data['length'],$z);
								$data['width']=max($data['width'],$x);
								$data['price']=$product->prices[0]->unit_price->price_value_with_tax;
							}
							else{
								$data['weight']+=round($caracs['weight'],2);
								$data['height']=max($data['height'],$y);
								$data['length']=max($data['length'],$z);
								$data['width']+=$x;
								$data['price']+=$product->prices[0]->unit_price->price_value_with_tax;
							}
						}
					}
				}
			}
			$data['XMLpackage'].=$this->_createPackage($data, $product, $rate, $order);
			$usableMethods=$this->_FEDEXrequestMethods($data);
		}
		else{
			foreach($order->products as $product){
				$data['weight']=0;
				$data['height']=0;
				$data['length']=0;
				$data['width']=0;
				//$data['price']=0;
				if($product->product_parent_id==0){
					if(isset($product->variants)){
						foreach($product->variants as $variant){
							for($i=0;$i<$variant->cart_product_quantity;$i++){
								$data['XMLpackage'].=$this->_createPackage($data, $variant, $rate, $order, true);
							}
						}
					}
					else{
						for($i=0;$i<$product->cart_product_quantity;$i++){
							$data['XMLpackage'].=$this->_createPackage($data, $product, $rate, $order, true );
						}
					}
				}
			}

			$usableMethods=$this->_FEDEXrequestMethods($data);

		}
		if(empty($usableMethods)){
			return false;
		}
		$currencies=array();
		foreach($usableMethods as $method){
			$currencies[$method['currency_code']]='"'.$method['currency_code'].'"';
		}
		$db = &JFactory::getDBO();
		$query='SELECT currency_code, currency_id FROM '.hikashop_table('currency').' WHERE currency_code IN ('.implode(',',$currencies).')';
		$db->setQuery($query);
		$currencyList = $db->loadObjectList();
		$currencyList=reset($currencyList);
		foreach($usableMethods as $i => $method){
			$usableMethods[$i]['currency_id']=$currencyList->currency_id;
		}
		$usableMethods=$this->_currencyConversion($usableMethods, $order);

		//print_r($usableMethods); exit;

		return $usableMethods;
	}

	function _createPackage(&$data, &$product, &$rate, &$order, $includeDimension=false){

		if(empty($data['weight'])){
			$caracs=$this->_convertCharacteristics($product, $data);
			$data['weight_unit']=$caracs['weight_unit'];
			$data['dimension_unit']=$caracs['dimension_unit'];
			$data['weight']=round($caracs['weight'],2);
			if($caracs['height'] != '' && $caracs['height'] != '0.00' && $caracs['height'] != 0){
				$data['height']=round($caracs['height'],2);
				$data['length']=round($caracs['length'],2);
				$data['width']=round($caracs['width'],2);
			}
		}

		$currencyClass=hikashop_get('class.currency');
		$config =& hikashop_config();
		$this->main_currency = $config->get('main_currency',1);
		$currency = hikashop_getCurrency();
		if(isset($data['price'])){
			$price=$data['price'];
		}
		else{
			$price=$product->prices[0]->unit_price->price_value;
		}
		if(@$this->shipping_currency_id!=@$data['currency'] && !empty($data['currency'])){
			$price=$currencyClass->convertUniquePrice($price, $this->shipping_currency_id,@$data['currency']);
		}
		if(!empty($rate->shipping_params->weight_approximation)){
			$data['weight']=$data['weight']+$data['weight']*$rate->shipping_params->weight_approximation/100;
		}
		if(@$data['weight']<1){
			$data['weight']=1;
		}
		if(!empty($rate->shipping_params->dim_approximation_h) && @$rate->shipping_params->use_dimensions == 1){
			$data['height']=$data['height']+$data['height']*$rate->shipping_params->dim_approximation_h/100;
		}
		if(!empty($rate->shipping_params->dim_approximation_l) && @$rate->shipping_params->use_dimensions == 1){
			$data['length']=$data['length']+$data['length']*$rate->shipping_params->dim_approximation_l/100;
		}
		if(!empty($rate->shipping_params->dim_approximation_w) && @$rate->shipping_params->use_dimensions == 1){
			$data['width']=$data['width']+$data['width']*$rate->shipping_params->dim_approximation_w/100;
		}
		$options='';
		$dimension='';
		if(@$rate->shipping_params->include_price){
			$options='<PackageServiceOptions>
						<InsuredValue>
							<CurrencyCode>'.$data['currency_code'].'</CurrencyCode>
							<MonetaryValue>'.$price.'</MonetaryValue>
						</InsuredValue>
					</PackageServiceOptions>';
		}
		if($includeDimension){
			if($data['height'] != '' && $data['height'] != 0 && $data['height'] != '0.00'){
				$dimension='<Dimensions>
							<UnitOfMeasurement>
								<Code>'.$data['dimension_unit'].'</Code>
							</UnitOfMeasurement>
							<Length>'.$data['length'].'</Length>
							<Width>'.$data['width'].'</Width>
							<Height>'.$data['height'].'</Height>
						</Dimensions>';
			}
		}
		static $id = 0;
		$xml='<Package'.$id.'>
				<PackagingType>
					<Code>02</Code>
				</PackagingType>
				<Description>Shop</Description>
				'.$dimension.'
				<PackageWeight>
					<UnitOfMeasurement>
						<Code>'.$data['weight_unit'].'</Code>
					</UnitOfMeasurement>
					<Weight>'.$data['weight'].'</Weight>
				</PackageWeight>
				'.$options.'
			</Package'.$id.'>';
		$id++;
		return $xml;
	}
	function _convertCharacteristics(&$product, $data, $forceUnit=false){
		$weightClass=hikashop_get('helper.weight');
		$volumeClass=hikashop_get('helper.volume');
		if(!isset($product->product_dimension_unit_orig)) $product->product_dimension_unit_orig = $product->product_dimension_unit;
		if(!isset($product->product_weight_unit_orig)) $product->product_weight_unit_orig = $product->product_weight_unit;
		if($forceUnit){
			$carac['weight']=$weightClass->convert($product->product_weight_orig, $product->product_weight_unit_orig, 'lb');
			$carac['weight_unit']='LBS';
			$carac['height']=$volumeClass->convert($product->product_height, $product->product_dimension_unit_orig, 'in' , 'dimension');
			$carac['length']=$volumeClass->convert($product->product_length, $product->product_dimension_unit_orig, 'in', 'dimension' );
			$carac['width']=$volumeClass->convert($product->product_width, $product->product_dimension_unit_orig, 'in', 'dimension' );
			$carac['dimension_unit']='IN';
			return $carac;
		}
		if(@$data['units']=='kg'){
			if($product->product_weight_unit_orig=='kg'){
				$carac['weight']=$product->product_weight_orig;
				$carac['weight_unit']=$this->convertUnit[$product->product_weight_unit_orig];
			}else{
				$carac['weight']=$weightClass->convert($product->product_weight_orig, $product->product_weight_unit_orig, 'kg');
				$carac['weight_unit']='KGS';
			}
			if($product->product_dimension_unit_orig=='cm'){
				$carac['height']=$product->product_height;
				$carac['length']=$product->product_length;
				$carac['width']=$product->product_width;
				$carac['dimension_unit']=$this->convertUnit[$product->product_dimension_unit_orig];
			}else{
				$carac['height']=$volumeClass->convert($product->product_height, $product->product_dimension_unit_orig, 'cm' , 'dimension');
				$carac['length']=$volumeClass->convert($product->product_length, $product->product_dimension_unit_orig, 'cm', 'dimension' );
				$carac['width']=$volumeClass->convert($product->product_width, $product->product_dimension_unit_orig, 'cm', 'dimension' );
				$carac['dimension_unit']='CM';
			}
		}else{
			if($product->product_weight_unit_orig=='lb'){
				$carac['weight']=$product->product_weight_orig;
				$carac['weight_unit']=$this->convertUnit[$product->product_weight_unit_orig];
			}else{
				$carac['weight']=$weightClass->convert($product->product_weight, $product->product_weight_unit_orig, 'lb');
				$carac['weight_unit']='LBS';
			}
			if($product->product_dimension_unit_orig=='in'){
				$carac['height']=$product->product_height;
				$carac['length']=$product->product_length;
				$carac['width']=$product->product_width;
				$carac['dimension_unit']=$this->convertUnit[$product->product_dimension_unit_orig];
			}else{
				$carac['height']=$volumeClass->convert($product->product_height, $product->product_dimension_unit_orig, 'in' , 'dimension');
				$carac['length']=$volumeClass->convert($product->product_length, $product->product_dimension_unit_orig, 'in', 'dimension' );
				$carac['width']=$volumeClass->convert($product->product_width, $product->product_dimension_unit_orig, 'in', 'dimension' );
				$carac['dimension_unit']='IN';
			}
		}
		return $carac;
	}
	function _FEDEXrequestMethods($data){
		global $fedex_methods;

		$path_to_wsdl = dirname(__FILE__).DS.'fedex_rate.wsdl';

		ini_set("soap.wsdl_cache_enabled","0");
		$client = new SoapClient($path_to_wsdl, array('exceptions' => false));


		$shipment= array();
		foreach($data['methods'] as $k=>$v){
			$request['WebAuthenticationDetail'] = array(
				'UserCredential' =>array(
					'Key' => $data['fedex_api_key'],
					'Password' => $data['fedex_api_password']
				)
			);
			$request['ClientDetail'] = array(
				'AccountNumber' => $data['fedex_account_number'],
				'MeterNumber' => $data['fedex_meter_number']
			);
			$request['TransactionDetail'] = array('CustomerTransactionId' => ' *** Rate Request v10 using PHP ***');
			$request['Version'] = array(
				'ServiceId' => 'crs',
				'Major' => '10',
				'Intermediate' => '0',
				'Minor' => '0'
			);

			$request['ReturnTransitAndCommit'] = true;
			$request['RequestedShipment']['DropoffType'] = 'REGULAR_PICKUP'; // valid values REGULAR_PICKUP, REQUEST_COURIER, ...
			$request['RequestedShipment']['ShipTimestamp'] = date('c');
			$request['RequestedShipment']['ServiceType'] = $v; // valid values STANDARD_OVERNIGHT, PRIORITY_OVERNIGHT, FEDEX_GROUND, ...
			$request['RequestedShipment']['PackagingType'] = $data['packaging_type']; // valid values FEDEX_BOX, FEDEX_PAK, FEDEX_TUBE, YOUR_PACKAGING, ...
			$request['RequestedShipment']['TotalInsuredValue']=array('Ammount'=>$data['total_insured'],'Currency'=>'USD');
			$request['RequestedPackageDetailType'] = 'PACKAGE_SUMMARY';

			$shipper = array(
				'Contact' => array(
					'PersonName' => $data['sender_company'],
					'CompanyName' => $data['sender_company'],
					'PhoneNumber' => $data['sender_phone']),
				'Address' => array(
					'StreetLines' => array($data['sender_address']),
					'City' => $data['sender_city'],
					'StateOrProvinceCode' => $data['sender_state'],
					'PostalCode' => $data['sender_postcode'],
					'CountryCode' => $data['country'])
			);


			$recipient = array(
				'Contact' => array(
					'PersonName' => $data['recipient']->address_title." ".$data['recipient']->address_firstname." ".$data['recipient']->address_lastname,
					'CompanyName' => $data['recipient']->address_company,
					'PhoneNumber' => $data['recipient']->address_telephone
				),
				'Address' => array(
					'StreetLines' => array($data['recipient']->address_street),
					'City' => $data['recipient']->address_city,
					'StateOrProvinceCode' => $data['recipient']->address_state->zone_code_3,
					'PostalCode' => $data['recipient']->address_post_code,
					'CountryCode' => $data['recipient']->address_country->zone_code_2,
					'Residential' => true)
			);
			$shippingChargesPayment = array(
				'PaymentType' => 'SENDER', // valid values RECIPIENT, SENDER and THIRD_PARTY
				'Payor' => array(
					'AccountNumber' => $data['fedex_account_number'],
					'CountryCode' => $data['country'])
			);

			$pkg_values = $this->xml2array('<root>'.$data['XMLpackage'].'</root>');
			$pkg_values = $pkg_values['root'];
			$pkg_count = count($pkg_values);

	//	echo( '<pre>'.htmlentities($data['XMLpackage']).'</pre>' );
	//	echo( '<pre>'.var_export($pkg_values, true).'</pre>' );
	//	exit;

			$request['RequestedShipment']['Shipper'] = $shipper;
			$request['RequestedShipment']['Recipient'] = $recipient;
			$request['RequestedShipment']['ShippingChargesPayment'] = $shippingChargesPayment;
			$request['RequestedShipment']['RateRequestTypes'] = 'ACCOUNT';
			$request['RequestedShipment']['RateRequestTypes'] = 'LIST';
			$request['RequestedShipment']['PackageCount'] = $pkg_count;
			$request['RequestedShipment']['RequestedPackageLineItems'] = $this->addPackageLineItem($pkg_values);

	//		echo( '<pre>'.var_export($request, true).'</pre>' );

	//		try {
				$response = $client->getRates($request);
	//		}
	//		catch(SoapFault $e) { }

	//		echo( '<pre>'.var_export($response, true).'</pre>' );

			if(isset($response->HighestSeverity) && $response->HighestSeverity == "ERROR") {
				static $notif = false;
				if(!$notif && isset($response->Notifications->Message) && $response->Notifications->Message == 'Authentication Failed') {
					$app =& JFactory::getApplication();
					$app->enqueueMessage('FEDEX Authentication Failed');
					$notif = true;
				}
			//	if($response->Notifications->Message != 'Authentication Failed') {
			//		$app =& JFactory::getApplication();
			//		$app->enqueueMessage($response->Notifications->Message);
			//	}
			}

			//print_r($response); echo "<BR><BR>";

			if(!empty($response->HighestSeverity) && ($response->HighestSeverity == "SUCCESS" || $response->HighestSeverity == "NOTE"))
			{
				$code = '';
				//echo "<BR><BR><BR>";
				//echo "<pre>";
				//print_r($response);
				//echo "</pre>";
				//exit;

				$notes = array();
				if($response->HighestSeverity == "NOTE") {
					$notes = $response->Notifications;
				}

				foreach($this->fedex_methods as $k=>$v){

					if($v['code'] == $response->RateReplyDetails->ServiceType){
						$code = $v['code'];
					}
				}
				$delayType = hikashop_get('type.delay');
				$timestamp = strtotime($response->RateReplyDetails->DeliveryTimestamp);

				$shipment[] = array(
					'value' => $response->RateReplyDetails->RatedShipmentDetails[0]->ShipmentRateDetail->TotalNetCharge->Amount,
					'code' => $code,
					'delivery_timestamp' => $timestamp,
					'delivery_day' => date("m/d/Y", $timestamp),
					'delivery_delay' => $delayType->displayDelay($timestamp - strtotime('now')),
					'delivery_time' => date("H:i:s", $timestamp),
					'currency_code' => $response->RateReplyDetails->RatedShipmentDetails[0]->ShipmentRateDetail->TotalNetCharge->Currency,
					'old_currency_code' => $response->RateReplyDetails->RatedShipmentDetails[0]->ShipmentRateDetail->TotalNetCharge->Currency,
					'notes' => $notes
				);
			}

		}

		return $shipment;
	}
	function _currencyConversion(&$usableMethods, &$order){
		$currency= $this->shipping_currency_id;
		$currencyClass = hikashop_get('class.currency');
		foreach($usableMethods as $i => $method){
			if($method['currency_id']!=$currency){
				$usableMethods[$i]['value']=$currencyClass->convertUniquePrice($method['value'],$method['currency_id'], $currency);
				$usableMethods[$i]['old_currency_id']=$usableMethods[$i]['currency_id'];
				$usableMethods[$i]['old_currency_code']=$usableMethods[$i]['currency_code'];
				$usableMethods[$i]['currency_id']=$currency;
				$usableMethods[$i]['currency_code']=$this->shipping_currency_code;
			}
		}
		return $usableMethods;
	}
	function onShippingSave(&$cart,&$methods,&$shipping_id){
		$usable_mehtods = array();
		$errors = array();
		$this->onShippingDisplay($cart,$methods,$usable_mehtods,$errors);
		foreach($usable_mehtods as $k => $usable_method){
			if($usable_method->shipping_id==$shipping_id){
				return $usable_method;
			}
		}
		return false;
	}
	function onAfterOrderConfirm(&$order,&$methods,$method_id){
		return true;
	}

	function printSuccess($client, $response) {
		echo '<h2>Transaction Successful</h2>';
		echo "\n";
		printRequestResponse($client);
	}
	function printRequestResponse($client){
		echo '<h2>Request</h2>' . "\n";
		echo '<pre>' . htmlspecialchars($client->__getLastRequest()). '</pre>';
		echo "\n";

		echo '<h2>Response</h2>'. "\n";
		echo '<pre>' . htmlspecialchars($client->__getLastResponse()). '</pre>';
		echo "\n";
	}

	/**
	 *  Print SOAP Fault
	 */  
	function printFault($exception, $client) {
		echo '<h2>Fault</h2>' . "<br>\n";
		echo "<b>Code:</b>{$exception->faultcode}<br>\n";
		echo "<b>String:</b>{$exception->faultstring}<br>\n";
		writeToLog($client);
	}

	/**
	 * SOAP request/response logging to a file
	 */                                  
	function writeToLog($client){
	if (!$logfile = fopen(TRANSACTIONS_LOG_FILE, "a"))
	{
		 error_func("Cannot open " . TRANSACTIONS_LOG_FILE . " file.\n", 0);
		 exit(1);
	}

	fwrite($logfile, sprintf("\r%s:- %s",date("D M j G:i:s T Y"), $client->__getLastRequest(). "\n\n" . $client->__getLastResponse()));
	}

	/**
	 * This section provides a convenient place to setup many commonly used variables
	 * needed for the php sample code to function.
	 */
	function getProperty($var){
		if($var == 'check') Return true;
		if($var == 'shipaccount') Return 'XXX';
		if($var == 'billaccount') Return 'XXX';
		if($var == 'dutyaccount') Return 'XXX';
		if($var == 'accounttovalidate') Return 'XXX';
		if($var == 'meter') Return 'XXX';
		if($var == 'key') Return 'XXX';
		if($var == 'password') Return '';
		if($var == 'shippingChargesPayment') Return 'SENDER';
		if($var == 'internationalPaymentType') Return 'SENDER';
		if($var == 'readydate') Return '2010-05-31T08:44:07';
		if($var == 'readytime') Return '12:00:00-05:00';
		if($var == 'closetime') Return '20:00:00-05:00';
		if($var == 'closedate') Return date("Y-m-d");
		if($var == 'pickupdate') Return date("Y-m-d", mktime(8, 0, 0, date("m")  , date("d")+1, date("Y")));
		if($var == 'pickuptimestamp') Return mktime(8, 0, 0, date("m")  , date("d")+1, date("Y"));
		if($var == 'pickuplocationid') Return 'XXX';
		if($var == 'pickupconfirmationnumber') Return '00';
		if($var == 'dispatchdate') Return date("Y-m-d", mktime(8, 0, 0, date("m")  , date("d")+1, date("Y")));
		if($var == 'dispatchtimestamp') Return mktime(8, 0, 0, date("m")  , date("d")+1, date("Y"));
		if($var == 'dispatchlocationid') Return 'XXX';
		if($var == 'dispatchconfirmationnumber') Return '00';
		if($var == 'shiptimestamp') Return mktime(10, 0, 0, date("m"), date("d")+1, date("Y"));
		if($var == 'tag_readytimestamp') Return mktime(10, 0, 0, date("m"), date("d")+1, date("Y"));
		if($var == 'tag_latesttimestamp') Return mktime(15, 0, 0, date("m"), date("d")+1, date("Y"));
		if($var == 'trackingnumber') Return 'XXX';
		if($var == 'trackaccount') Return 'XXX';
		if($var == 'shipdate') Return '2010-06-06';
		if($var == 'account') Return 'XXX';
		if($var == 'phonenumber') Return '1234567890';
		if($var == 'closedate') Return '2010-05-30';
		if($var == 'expirationdate') Return '2011-06-15';
		if($var == 'hubid') Return '5531';
		if($var == 'begindate') Return '2011-05-20';
		if($var == 'enddate') Return '2011-05-31';
		if($var == 'address1') Return array('StreetLines' => array('10 Fed Ex Pkwy'),
				'City' => 'Memphis',
				'StateOrProvinceCode' => 'TN',
				'PostalCode' => '38115',
				'CountryCode' => 'US');
		if($var == 'address2') Return array('StreetLines' => array('13450 Farmcrest Ct'),
				'City' => 'Herndon',
				'StateOrProvinceCode' => 'VA',
				'PostalCode' => '20171',
				'CountryCode' => 'US');
		if($var == 'locatoraddress') Return array(array('StreetLines'=>'240 Central Park S'),
				'City'=>'Austin',
				'StateOrProvinceCode'=>'TX',
				'PostalCode'=>'78701',
				'CountryCode'=>'US');
		if($var == 'recipientcontact') Return array('ContactId' => 'arnet',
				'PersonName' => 'Recipient Contact',
				'PhoneNumber' => '1234567890');
		if($var == 'freightaccount') Return 'XXX';
		if($var == 'freightbilling') Return array(
			'Contact'=>array(
				'ContactId' => 'freight1',
				'PersonName' => 'Big Shipper',
				'Title' => 'Manager',
				'CompanyName' => 'Freight Shipper Co',
				'PhoneNumber' => '1234567890'
			),
			'Address'=>array(
				'StreetLines'=>array('1202 Chalet Ln', 'Do Not Delete - Test Account'),
				'City' =>'Harrison',
				'StateOrProvinceCode' => 'AR',
				'PostalCode' => '72601-6353',
				'CountryCode' => 'US'
			)
		);
	}

	function setEndpoint($var){
		if($var == 'changeEndpoint') Return false;
		if($var == 'endpoint') Return '';
	}

	function printNotifications($notes){
		foreach($notes as $noteKey => $note){
			if(is_string($note)){
				echo $noteKey . ': ' . $note . Newline;
			}
			else{
				printNotifications($note);
			}
		}
		echo Newline;
	}

	function printError($client, $response){
		echo '<h2>Error returned in processing transaction</h2>';
		echo "\n";
		printNotifications($response -> Notifications);
		printRequestResponse($client, $response);
	}

	function addPackageLineItem($pkg_values){

		$packageLineItem[] = array();
		$ct = count($pkg_values);
		$x = 1;
		foreach($pkg_values as $pkg)
		{
			if($pkg['PackageWeight']['UnitOfMeasurement']['Code'] == "LBS"){
				$uom = "LB";
			} else {
				$uom = $pkg["PackageWeight"]["UnitOfMeasurement"]['Code'];
			}

			if(is_array($pkg['Dimensions'])){
				$dimensions = array("Dimensions"=>array(
					'Length' => $pkg['Dimensions']['Length'],
					'Width' => $pkg['Dimensions']['Width'],
					'Height' => $pkg['Dimensions']['Height'],
					'Units' => $pkg['Dimensions']['UnitOfMeasurement']['Code'])
				);
			}

			$packageLineItem = array(
				'SequenceNumber'=>$x,
				'GroupPackageCount'=>$ct,
				'Weight' => array(
					'Value' => $pkg['PackageWeight']['Weight'],
					'Units' => $uom
				),
				$dimensions
			);
			$x++;
		}

		return $packageLineItem;
	}

	function xml2array($contents, $get_attributes = 1, $priority = 'tag')
	{
		//$contents = "";
		if (!function_exists('xml_parser_create'))
		{
			return array ();
		}
		$parser = xml_parser_create('');

		xml_parser_set_option($parser, XML_OPTION_TARGET_ENCODING, "UTF-8");
		xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
		xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
		xml_parse_into_struct($parser, trim($contents), $xml_values);
		xml_parser_free($parser);
		if (!$xml_values)
			return; //Hmm...
		$xml_array = array ();
		$parents = array ();
		$opened_tags = array ();
		$arr = array ();
		$current = & $xml_array;
		$repeated_tag_index = array ();
		foreach ($xml_values as $data)
		{
			unset ($attributes, $value);
			extract($data);
			$result = array ();
			$attributes_data = array ();
			if (isset ($value))
			{
				if ($priority == 'tag')
					$result = $value;
				else
					$result['value'] = $value;
			}
			if (isset ($attributes) and $get_attributes)
			{
				foreach ($attributes as $attr => $val)
				{
					if ($priority == 'tag')
						$attributes_data[$attr] = $val;
					else
						$result['attr'][$attr] = $val; //Set all the attributes in a array called 'attr'
				}
			}
			if ($type == "open")
			{
				$parent[$level -1] = & $current;
				if (!is_array($current) or (!in_array($tag, array_keys($current))))
				{
					$current[$tag] = $result;
					if ($attributes_data)
						$current[$tag . '_attr'] = $attributes_data;
					$repeated_tag_index[$tag . '_' . $level] = 1;
					$current = & $current[$tag];
				}
				else
				{
					if (isset ($current[$tag][0]))
					{
						$current[$tag][$repeated_tag_index[$tag . '_' . $level]] = $result;
						$repeated_tag_index[$tag . '_' . $level]++;
					}
					else
					{
						$current[$tag] = array (
							$current[$tag],
							$result
						);
						$repeated_tag_index[$tag . '_' . $level] = 2;
						if (isset ($current[$tag . '_attr']))
						{
							$current[$tag]['0_attr'] = $current[$tag . '_attr'];
							unset ($current[$tag . '_attr']);
						}
					}
					$last_item_index = $repeated_tag_index[$tag . '_' . $level] - 1;
					$current = & $current[$tag][$last_item_index];
				}
			}
			elseif ($type == "complete")
			{
				if (!isset ($current[$tag]))
				{
					$current[$tag] = $result;
					$repeated_tag_index[$tag . '_' . $level] = 1;
					if ($priority == 'tag' and $attributes_data)
						$current[$tag . '_attr'] = $attributes_data;
				}
				else
				{
					if (isset ($current[$tag][0]) and is_array($current[$tag]))
					{
						$current[$tag][$repeated_tag_index[$tag . '_' . $level]] = $result;
						if ($priority == 'tag' and $get_attributes and $attributes_data)
						{
							$current[$tag][$repeated_tag_index[$tag . '_' . $level] . '_attr'] = $attributes_data;
						}
						$repeated_tag_index[$tag . '_' . $level]++;
					}
					else
					{
						$current[$tag] = array (
							$current[$tag],
							$result
						);
						$repeated_tag_index[$tag . '_' . $level] = 1;
						if ($priority == 'tag' and $get_attributes)
						{
							if (isset ($current[$tag . '_attr']))
							{
								$current[$tag]['0_attr'] = $current[$tag . '_attr'];
								unset ($current[$tag . '_attr']);
							}
							if ($attributes_data)
							{
								$current[$tag][$repeated_tag_index[$tag . '_' . $level] . '_attr'] = $attributes_data;
							}
						}
						$repeated_tag_index[$tag . '_' . $level]++; //0 and 1 index is already taken
					}
				}
			}
			elseif ($type == 'close')
			{
				$current = & $parent[$level -1];
			}
		}
		return ($xml_array);
	}
}