<?php

error_reporting(0);

if (!defined('_VALID_MOS') && !defined('_JEXEC'))
    die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');

if (!class_exists('vmPSPlugin')) require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');

class plgVMPaymentApirone extends vmPSPlugin
{
	public static $_this = false;
	public static $flag = false;

	function __construct(& $subject, $config)
	{
		parent::__construct($subject, $config);
		$varsToPush = $this->getVarsToPush();
		$this->_loggable = true;
    	$this->tableFields = array_keys($this->getTableSQLFields());
		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
	}

	protected function getVmPluginCreateTableSQL() {
		return $this->createTableSQL('Payment Apirone Table');
	}
    
    function getTableSQLFields() {
		$SQLfields = array(
	    	'id'				=> 'int(11) unsigned NOT NULL AUTO_INCREMENT'
		);
		return $SQLfields;
    }

    function plgVmConfirmedOrder($cart, $order) { 
    	if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {return null;}
		if (!$this->selectedThisElement($method->payment_element)) {return false;}

		$session = JFactory::getSession();

		if (!class_exists ('VirtueMartModelOrders'))
      			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');

		$html = '';

            $test = $method->test;
            
            if ($test) {
                $apirone_adr = 'https://apirone.com/api/v1/receive';
            } else {
                $apirone_adr = 'https://apirone.com/api/v1/receive';
            }

			$currency = $this->abf_currency($order['details']['BT']->order_currency);

			$order_id = $order['details']['BT']->virtuemart_order_id;

			$pm = $order['details']['BT']->virtuemart_paymentmethod_id;
            
            $response_btc = $this->abf_convert_to_btc($currency, $order['details']['BT']->order_total);

           if ($this->abf_is_valid_for_use($currency) && $response_btc > 0) {          
                $sales = $this->abf_getSales($order_id);
                $error_message = false;  
                if (is_null($sales)) {
                    $secret = $this->abf_getKey($order_id);
                    $args           = array(
                        'address' => $method->merchantwallet,
                        'callback' => urlencode(JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&method=apirone&callback=1&format=raw&pm=' . $pm . '&secret=' . $secret . '&order_id=' . $order_id))
                    );
                    if ($test){
                        echo "<p>" . $args['callback'] . "</p>";
                    }
                    $apirone_create = $apirone_adr . '?method=create&address=' . $args['address'] . '&callback=' . $args['callback'];
                    $response_create = $this->do_request( $apirone_create );
                    $response_create = json_decode($response_create, true);
                    if ($response_create['input_address'] != null){
                        $this->abf_addSale($order_id, $response_create['input_address']);
                    } else{
                        $error_message =  "No Input Address from Apirone :(";
                    }
                } else {
                    $response_create['input_address'] = $sales->address;
                }             
                if ($test && !is_null($response_create)) {
                    $this->logData("Request:" . $apirone_create . ", Response:" . $response_btc);
                }
            } else {
                $error_message = "Apirone couldn't exchange " . $order['currency_code'] . " to BTC :(";
            }

			if ($error_message == false) {
              $html .='  <div id="bitcoin">
	<div style="float:left; width: 30%;">
            <img src="https://apirone.com/api/v1/qr?message=bitcoin:' . $response_create['input_address'] . '?amount='. $response_btc .'&format=svg" width="100%" alt="QR code">
    </div>
    <div class="billing-block" style="float:right; width:calc(70% - 1.2em); padding-top:1.2em; margin-left:1.2em;">
            <p><span class="text-muted">Amount to pay:</span><strong class="text-info"> '. $response_btc .' BTC</strong></p>
            <p>
                <span>Pay to Bitcoin address:</span><br>
                <a href="bitcoin:' . $response_create['input_address'] . '?amount='. $response_btc .'">' . $response_create['input_address'] . '</a>
            </p>
            <p>
            <span class="insertion"></span>
            Arrived amount: <strong class="arrived">0</strong> <strong>BTC</strong><br>
            Remains to pay<span class="with-uncomfirmed"></span>: <strong class="remains">'. $response_btc .'</strong> <strong>BTC</strong><br>
            Status: <strong class="status">Waiting payment...</strong>
            </p>
            <p>If you are unable to complete your payment, you can try again later to place a new order with saved cart.<br>
You can pay partially, but please do not close this window before next payment to prevent lose of bitcoin address and invoice number.</p>
    </div>
    <div style="clear:both"></div>';
   		$html .=' 
<script type="text/javascript">
if(window.jQuery) {
	if(interval){
		clearInterval(interval);
	}
	function apirone_query(){
	var order = \'' . $order_id . '\';
    if (order != undefined) {
    abf_get_query=\'index.php?view=pluginresponse&task=pluginnotification&format=raw&method=apirone&checkpay=1&pm=' . $pm . '&order_id=' . $order_id . '\';
	jQuery.ajax({
    url: abf_get_query,
    dataType : "text",
    success: function (data, textStatus) {
        data = JSON.parse(data);
        if (data.Status == "complete") {
            complete = 1; 
            jQuery(".with-uncomfirmed, .uncomfirmed").empty();
            statusText = "Payment complete";
        }
        if (data.Status == "innetwork") {
            innetwork = 1;
            complete = 0;
            jQuery(".with-uncomfirmed").text("(with uncomfirmed)");
            statusText = "Transaction in network (income amount: "+ data.innetwork_amount +" BTC)";
        }
        if (data.Status == "waiting") {
            complete = 0;
            jQuery(".with-uncomfirmed, .uncomfirmed").empty();
            statusText = "Waiting payment...";
        }
        if (!jQuery("div").is(".last_transaction") && data.last_transaction && data.Status != \'innetwork\'){
            jQuery(".insertion").empty();
            jQuery(".insertion").prepend(\'<small>Last transaction: <strong class="last_transaction">\'+ data.last_transaction +\'</strong></small><br>\');
        }
        if (jQuery("div").is(".last_transaction")){ jQuery( ".last_transaction" ).text(data.last_transaction); }
        jQuery( ".last_transaction" ).text(data.last_transaction);
        jQuery( ".arrived" ).text(data.arrived_amount);
        remains = parseFloat(data.remains_to_pay);
        remains = remains.toFixed(8);
        if( remains < 0 ) remains = 0;
        jQuery( ".remains" ).text(remains);
        jQuery( ".status" ).text(statusText);
        complete_block = \'<div class="complete-block"><div>Payment done. Order finished.</div></div>\';
        if (!jQuery("div").is(".complete-block") && complete){ jQuery( ".billing-block" ).after(complete_block); } 
    } ,
    error: function(xhr, ajaxOptions, thrownError){
    }
    });
	}
    }
	var interval = setInterval(apirone_query, 5000);
}
</script>';
}         	
			else {
				$html .= '<div>' . $error_message . '</div>';	
			}
			$html .= '</div></table>' . "\n";

            $this->processConfirmedOrderPaymentResponse(2, $cart, $order, $html, $method->payment_name);
            return null;
    } 

    protected function checkConditions($cart, $method, $cart_prices) {return true;}
 	  function plgVmOnPaymentNotification() {	
		$payment_data = JRequest::get('get');
  		//print_r($payment_data);  		

		if(!class_exists('VirtueMartModelOrders'))
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');  		

		if(JRequest::getVar('method','')!='apirone') {return NULL;}
		if(self::$flag) return NULL;

		$virtuemart_order_id = $payment_data["order_id"];

		if(!($order = $this->abf_getOrder($virtuemart_order_id)))
			return NULL;
    
        $virtuemart_paymentmethod_id = $payment_data["pm"]; //from get query
		$pm = $order->virtuemart_paymentmethod_id; //from order
		if(!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id)) || ($pm != $virtuemart_paymentmethod_id))
		{
			return NULL;
		}

  		if (isset($payment_data['checkpay']) && $payment_data['checkpay'] == '1') { //check payment part

        	$safe_order = $virtuemart_order_id;
            $order = $this->abf_getOrder($safe_order);
            /*print_r( $order );*/
            if (!empty($safe_order)) {
                $transactions = $this->abf_getTransactions($safe_order);
            }
            $empty = 0;
            $value = 0;
            $paid_value = 0;

            $payamount = 0;
            $innetwotk_pay = 0;
            $last_transaction = '';
            $confirmed = '';
            $status = 'waiting';
            //print_r($sales);
            if($transactions != '')
            foreach ($transactions as $transaction) {
                if($transaction->thash == 'empty') {
                            $status = 'innetwork';
                            $innetwotk_pay = $transaction->paid;
                }
                if($transaction->thash != 'empty') 
                    $payamount += $transaction->paid;      
               //print_r($transaction);

                if ($transaction->thash == "empty"){
                    $empty = 1; // has empty value in thash
                    $value = $transaction->paid;
                } else{
                    $paid_value = $transaction->paid;
                    $confirmed = $transaction->thash;
                }              
            }
            if ($order == '') {
                echo '';
                exit;
            }
            $response_btc = $this->abf_convert_to_btc($this->abf_currency($order->order_currency), $order->order_total);
            if ($order->order_status == $method->status_success && $this->abf_check_remains($safe_order)) {
                $status = 'complete';
            }
                $remains_to_pay = number_format($this->abf_remains_to_pay($safe_order), 8, '.', '');
                $last_transaction = $confirmed;
                $payamount = number_format($payamount/1E8, 8, '.', '');
                $innetwotk_pay = number_format($innetwotk_pay/1E8, 8, '.', '');


            echo '{"innetwork_amount": "' .$innetwotk_pay. '" , "arrived_amount": "' .$payamount. '" , "remains_to_pay": "' .$remains_to_pay. '" , "last_transaction": "' .$last_transaction. '", "Status": "' .$status. '"}';
            return true;		

  		}
  		
  		if (isset($payment_data['callback']) && $payment_data['callback'] == '1') { //callback part
        define("ABF_COUNT_CONFIRMATIONS", $method->confirmations); // number of confirmations
        define("ABF_MAX_CONFIRMATIONS", 150); // max confirmations count

        $test = $method->test;

        $abf_api_output = 0; //Nothing to do (empty callback, wrong order Id or Input Address)
        if ($test) {
            $this->logData("Callback:" . $_SERVER['REQUEST_URI']);
        }
        if (isset($payment_data['secret'])) {
            $safe_key = $payment_data['secret'];
        }

        if ( ! $safe_key ) {
            $safe_key = '';
        }

        if ( strlen( $safe_key ) > 32 ) {
            $safe_key = substr( $safe_key, 0, 32 );
        }

        $safe_order_id = $virtuemart_order_id;
        
        if ( strlen( $safe_order_id ) > 25 ) {
            $safe_order_id = substr( $safe_order_id, 0, 25 );
        }
        if (isset($payment_data['confirmations'])) {
            $safe_confirmations = intval( $payment_data['confirmations'] );

            if ( strlen( $safe_confirmations ) > 5 ) {
             $safe_confirmations = substr( $safe_confirmations, 0, 5 );
            }
        }

        if ( !isset($safe_confirmations) ) {
            $safe_confirmations = 0;
        }

        if (isset($payment_data['value'])) {
            $safe_value = intval( $payment_data['value'] );
            if ( strlen( $safe_value ) > 16 ) {
              $safe_value = substr( $safe_value, 0, 16 );
            }
        }

        if ( !isset($safe_value) ) {
               $safe_value = '';
        }

        if (isset($payment_data['input_address'])) {
        $safe_input_address = $payment_data['input_address'];
            if ( strlen( $safe_input_address ) > 64 ) {
               $safe_input_address = substr( $safe_input_address, 0, 64 );
            }
        }
        if ( !isset($safe_input_address) ) {
            $safe_input_address = '';
        }
        if (isset($payment_data['transaction_hash'])) {
        $safe_transaction_hash = $payment_data['transaction_hash'];
            if ( strlen( $safe_transaction_hash ) > 65 ) {
                $safe_transaction_hash = substr( $safe_transaction_hash, 0, 65 );
            }
        }
        if ( !isset($safe_transaction_hash) ) {
            $safe_transaction_hash = '';
        }
        $apirone_order = array(
            'confirmations' => $safe_confirmations,
            'orderId' => $safe_order_id, // order id
            'key' => $safe_key,
            'value' => $safe_value,
            'transaction_hash' => $safe_transaction_hash,
            'input_address' => $safe_input_address
        );
        if (($safe_confirmations >= 0) AND !empty($safe_value) AND $this->abf_sale_exists($safe_order_id, $safe_input_address)) {
            $abf_api_output = 1; //transaction exists
            //get test mode status
            if ($test){
                /*$apirone_adr =$this->language->get('test_url');*/
                $apirone_adr = 'https://apirone.com/api/v1/receive';
            }
            else{
                $apirone_adr = 'https://apirone.com/api/v1/receive';
            }
            if (!empty($apirone_order['value']) && !empty($apirone_order['input_address']) && empty($apirone_order['transaction_hash'])) {
                $order = $this->abf_getOrder($safe_order_id);
                if ($apirone_order['key'] == $this->abf_getKey($safe_order_id)) {
                $sales = $this->abf_getSales($apirone_order['orderId']);
                $transactions = $this->abf_getTransactions($apirone_order['orderId']);
                $flag = 1; //no simular transactions
                if($transactions != ''){
                    foreach ($transactions as $transaction) {
                    if(($transaction->thash == 'empty') && ($transaction->paid == $apirone_order['value'])){
                        $flag = 0; //simular transaction detected
                        break;
                    }
                    }  
                }
                if($flag){
                    $empty = "empty";
                    $this->abf_addTransaction($apirone_order['orderId'], $empty, $apirone_order['value'], $apirone_order['confirmations']);
                    $abf_api_output = 2; //insert new transaction in DB without transaction hash
                } else {
                    $this->abf_updateTransaction($apirone_order['value'], $apirone_order['confirmations']);
                    $abf_api_output = 3; //update existing transaction
                    }
                }
            }

                if (!empty($apirone_order['value']) && !empty($apirone_order['input_address']) && !empty($apirone_order['transaction_hash'])) {
                $abf_api_output = 4; // callback with transaction_hash
                $sales = $this->abf_getSales($apirone_order['orderId']);
                $transactions = $this->abf_getTransactions($apirone_order['orderId']);
                $order = $this->abf_getOrder($safe_order_id);
                if ($sales == null) $abf_api_output = 5; //no such information about input_address
                $flag = 1; //new transaction
                $empty = 0; //unconfirmed transaction]
                   if ($apirone_order['key'] == $this->abf_getKey($order->virtuemart_order_id)) {
                        $abf_api_output = 6; //WP key is valid but confirmations smaller that value from config or input_address not equivalent from DB
                        if (($apirone_order['confirmations'] >= ABF_COUNT_CONFIRMATIONS) && ($apirone_order['input_address'] == $sales->address)) {
                            $abf_api_output = 7; //valid transaction
                            $payamount = 0;
                            if($transactions != '')
                                foreach ($transactions as $transaction) {
                                if($transaction->thash != 'empty')
                                        $payamount += $transaction->paid;                                 
                                    $abf_api_output = 8; //finding same transaction in DB
                                    if($apirone_order['transaction_hash'] == $transaction->thash){
                                        $abf_api_output = 9; // same transaction was in DB
                                        $flag = 0; // same transaction was in DB
                                        break;
                                    }
                                    if(($apirone_order['value'] == $transaction->paid) && ($transaction->thash == 'empty')){
                                        $empty = 1; //empty find
                                    }
                                }
                        }

                $response_btc = $this->abf_convert_to_btc($this->abf_currency($order->order_currency), $order->order_total, '1');                 
                if($flag && $apirone_order['confirmations'] >= ABF_COUNT_CONFIRMATIONS && $response_btc > 0){
                    $abf_api_output = 10; //writing into DB, taking notes
                    $notes        = 'Input Address: ' . $apirone_order['input_address'] . '; Transaction Hash: ' . $apirone_order['transaction_hash'] . '; Payment: ' . number_format($apirone_order['value']/1E8, 8, '.', '') . ' BTC; ';
                    $notes .= 'Total paid: '.number_format(($payamount + $apirone_order['value'])/1E8, 8, '.', '').' BTC; ';
                    if (($payamount + $apirone_order['value'])/1E8 < $response_btc)
                        $notes .= 'User trasfrer not enough money in your shop currency. Waiting for next payment; ';
                    if (($payamount + $apirone_order['value'])/1E8 > $response_btc)
                        $notes .= 'User trasfrer more money than You need in your shop currency; ';


                    if($empty){
                        $this->abf_updateTransaction($apirone_order['value'], $apirone_order['confirmations'], $apirone_order['transaction_hash'], $apirone_order['orderId']);
                    } else {
                        $this->abf_addTransaction($apirone_order['orderId'], $apirone_order['transaction_hash'], $apirone_order['value'], $apirone_order['confirmations']);
                    } 
                    $notes .= 'Order total: '.$response_btc . ' BTC; ';
					if(!class_exists('VirtueMartModelOrders'))
						require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
					$modelOrder = new VirtueMartModelOrders();

					$updateOrder["virtuemart_order_id"] = $safe_order_id;
					$updateOrder["customer_notified"] = 1;                 

                    if ($this->abf_check_remains($apirone_order['orderId'])){ //checking that payment is complete, if not enough money on payment it's not completed 
                        $notes .= 'Successfully paid.';   
                        $updateOrder["order_status"] = $method->status_success;
                        $updateOrder['comments'] = $notes;   
                        $modelOrder->updateStatusForOneOrder($safe_order_id, $updateOrder, true);
                    } else {
                    	$updateOrder["order_status"] = $method->status_pending;
                        $updateOrder['comments'] = $notes;   
                        $modelOrder->updateStatusForOneOrder($safe_order_id, $updateOrder, true);
                    }

                    $abf_api_output = '*ok*';
                } else {
                    $abf_api_output = '11'; //No currency or small confirmations count or same transaction in DB
                }
            }
            }
        }

        if(($apirone_order['confirmations'] >= ABF_MAX_CONFIRMATIONS) && (ABF_MAX_CONFIRMATIONS != 0)) {// if callback's confirmations count more than ABF_MAX_CONFIRMATIONS we answer *ok*
            $abf_api_output="*ok*";
            if($test) {
                $this->logData("Skipped transaction: " . $apirone_order['transaction_hash'] . " with confirmations: " . $apirone_order['confirmations']);
            };
        };
        if($test) {
        print_r($abf_api_output);//global output
        } else{
            if($abf_api_output === '*ok*') echo '*ok*';
        }
        exit;
  		}
  	}
    function plgVmOnPaymentResponseReceived(&$html)
    {
    	    if (!class_exists ('VirtueMartCart'))
      require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');

    if (!class_exists ('shopFunctionsF'))
      require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');

    if (!class_exists ('VirtueMartModelOrders'))
      require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');

    $virtuemart_paymentmethod_id = JRequest::getInt('pm', 0);
    $order_number                = JRequest::getString('on', 0);
    $vendorId                    = 0;

    if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id)))
      return NULL;

    if (!$this->selectedThisElement($method->payment_element))
      return NULL;

    if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number)))
      return NULL;

    if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id)))
      return '';

    $payment_name = $this->renderPluginName($method);
    $html         = $this->_getPaymentResponseHtml($paymentTable, $payment_name);

    return TRUE;
    }

	function plgVmOnUserPaymentCancel() {return null;}
    
    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) {return $this->onStoreInstallPluginTable($jplugin_id);}
    
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart) {return $this->OnSelectCheck($cart);}
    
	public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) {
		$this->cart = $cart;
		return $this->displayListFE($cart, $selected, $htmlIn);
    }
	
    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);}
    
    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {

		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {return null;}
		if (!$this->selectedThisElement($method->payment_element)) {return false;}
		$this->getPaymentCurrency($method);
		$paymentCurrencyId = $method->payment_currency;
    }
    
    function logData($params)
    {
    	jimport('joomla.log.log');
        JLog::addLogger(array());
        JLog::add($params);
    }
    
    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array()) {return $this->onCheckAutomaticSelected($cart, $cart_prices);}
     
    function plgVmonShowOrderPrintPayment($order_number, $method_id) {return $this->onShowOrderPrint($order_number, $method_id);}

    function plgVmDeclarePluginParamsPayment($name, $id, &$data) {return $this->declarePluginParams('payment', $name, $id, $data);}

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) {return $this->setOnTablePluginParams($name, $id, $table);}

	function plgVmDeclarePluginParamsPaymentVM3( &$data) {return $this->declarePluginParams('payment', $data);}
	
 	function do_request($url, $params=array()) {
 		// init curl object
 		$ch = curl_init();
 		curl_setopt($ch, CURLOPT_URL, $url);
 		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
 		
 		// prepare post array if available
 		$params_string = '';
 		if (is_array($params) && count($params)) {
 		foreach($params as $key=>$value) {
 		$params_string .= $key.'='.$value.'&';
 		}
 		rtrim($params_string, '&');
 		
 		curl_setopt($ch, CURLOPT_POST, count($params));
 		curl_setopt($ch, CURLOPT_POSTFIELDS, $params_string);
 		}
 		
 		// execute request
 		$result = curl_exec($ch);
 		
 		// close connection
 		curl_close($ch);
 		
 		return $result;
 	}

	function abf_is_valid_for_use($currency = NULL){
		    if($currency != NULL){
                $check_currency = $currency;
            } else {
                $check_currency = NULL; // TEST DATA!
            }
            if (!in_array($check_currency, array(
                'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BCH', 'BDT', 'BGN', 'BHD', 'BIF', 'BMD', 'BND', 'BOB', 'BRL', 'BSD', 'BTC', 'BTN', 'BWP', 'BYN', 'BYR', 'BZD', 'CAD', 'CDF', 'CHF', 'CLF', 'CLP', 'CNH', 'CNY', 'COP', 'CRC', 'CUC', 'CVE', 'CZK', 'DJF', 'DKK', 'DOP', 'DZD', 'EEK', 'EGP', 'ERN', 'ETB', 'ETH', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GGP', 'GHS', 'GIP', 'GMD', 'GNF', 'GTQ', 'GYD', 'HKD', 'HNL', 'HRK', 'HTG', 'HUF', 'IDR', 'ILS', 'IMP', 'INR', 'IQD', 'ISK', 'JEP', 'JMD', 'JOD', 'JPY', 'KES', 'KGS', 'KHR', 'KMF', 'KRW', 'KWD', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'LSL', 'LTC', 'LTL', 'LVL', 'LYD', 'MAD', 'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MRO', 'MTL', 'MUR', 'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'OMR', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'PYG', 'QAR', 'RON', 'RSD', 'RUB', 'RWF', 'SAR', 'SBD', 'SCR', 'SEK', 'SGD', 'SHP', 'SLL', 'SOS', 'SRD', 'SSP', 'STD', 'SVC', 'SZL', 'THB', 'TJS', 'TMT', 'TND', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'UGX', 'USD', 'UYU', 'UZS', 'VEF', 'VND', 'VUV', 'WST', 'XAF', 'XAG', 'XAU', 'XCD', 'XDR', 'XOF', 'XPD', 'XPF', 'XPT', 'YER', 'ZAR', 'ZMK', 'ZMW', 'ZWL'
            ))) {
                return false;
            }
            return true;
        }
      function abf_convert_to_btc($currency, $value) {           
            if ($currency == 'BTC') {
                return $value;
            } else { 
            	if ( $currency == 'BTC' || $currency == 'USD' || $currency == 'EUR' || $currency == 'GBP') {
            		$response_btc =  $this->do_request('https://apirone.com/api/v1/tobtc?currency=' . $currency . '&value=' . $value);
            		return $response_btc;      
            	} else {
            		$response_coinbase = $this->do_request('https://api.coinbase.com/v2/prices/BTC-'. $currency .'/buy');
            		$response_coinbase = json_decode($response_coinbase, true);
            		$response_coinbase = $response_coinbase['amount'];
            		if (is_numeric($response_coinbase)) {
               			return round($value / $response_coinbase, 8);
            		} else {
                		return 0;
            		}    
           		}     
            }
        }

        //checks that order has sale
        function abf_sale_exists($order_id, $input_address) {
            $sales = $this->abf_getSales($order_id, $input_address);
            if (isset($sales->address) && $sales->address == $input_address) {return true;} else {return false;};
        }

        // function that checks what user complete full payment for order
        function abf_check_remains($order_id) {
            $order = $this->abf_getOrder($order_id);
            $total = $this->abf_convert_to_btc($this->abf_currency($order->order_currency), $order->order_total);
            $transactions = $this->abf_getTransactions($order_id);;
            $remains = 0;
            $total_paid = 0;
            $total_empty = 0;
            if(!is_null($transactions))
            foreach ($transactions as $transaction) {
                if ($transaction->thash == "empty") $total_empty+=$transaction->paid;
                $total_paid+=$transaction->paid;
            }
            $total_paid/=1E8;
            $total_empty/=1E8;
            $remains = $total - $total_paid;
            $remains_wo_empty = $remains + $total_empty;
            if ($remains_wo_empty > 0) {
                return false;
            } else {
                return true;
            };
        }

        function abf_remains_to_pay($order_id) {   
        	$order = $this->abf_getOrder($order_id);
			$transactions = $this->abf_getTransactions($order_id);
            $total_paid = 0;
            if(!is_null($transactions))
            	foreach ($transactions as $transaction) {
                	$total_paid+=$transaction->paid;
            	}
            $response_btc = $this->abf_convert_to_btc($this->abf_currency($order->order_currency), $order->order_total);
            $remains = $response_btc - $total_paid/100000000;
            if($remains < 0) $remains = 0;  
            return $remains;
        }

		function abf_currency($payment_currency){
			$jdb = JFactory::getDBO();
			$q = ' SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="'  .	 $payment_currency . '" ';
			$jdb->setQuery($q);
			$currency_code_3 = $jdb->loadResult();
			return $currency_code_3;		
		} 
		function abf_getOrder($order_id){
            $jdb = JFactory::getDBO();
            $orders_table = '#__virtuemart_orders';
           	$query = $jdb->getQuery(true);
       		$query
            	->select('*')
				->from($jdb->quoteName($orders_table))
            	->where($jdb->quoteName('virtuemart_order_id')." = ".$jdb->quote($order_id));  
        	$jdb->setQuery($query);
        	$order = $jdb->loadObject();
        	return $order;
		} 


		function abf_getSales($order_id, $address = NULL) {
			$jdb = JFactory::getDBO();
			$sale_table = '#__apirone_sale';
			$query = $jdb->getQuery(true);
			if (is_null($address)) {
				$query
            		->select('*')
					->from($jdb->quoteName($sale_table))
            		->where($jdb->quoteName('order_id')." = ".$jdb->quote($order_id));
			} else {
				$query
            		->select('*')
					->from($jdb->quoteName($sale_table))
					->where('('.$jdb->quoteName('order_id')." = ".$jdb->quote($order_id).' AND '.$jdb->quoteName('address')." = ".$jdb->quote($address).')');
			}
			$jdb->setQuery($query);
        	$order = $jdb->loadObject();		
			return $order;
		}

		function abf_getTransactions($order_id){
			$jdb = JFactory::getDBO();
			$transactions_table = '#__apirone_transactions';
			$query = $jdb->getQuery(true);
			$query
            	->select('*')
				->from($jdb->quoteName($transactions_table))
            	->where($jdb->quoteName('order_id')." = ".$jdb->quote($order_id));
            $jdb->setQuery($query);
            $transactions = $jdb->loadObjectList();
            return $transactions;
		}	
		
		function abf_addTransaction($order_id, $thash, $paid, $confirmations) {
			$jdb = JFactory::getDBO();
			$transactions_table = '#__apirone_transactions';
			$query = $jdb->getQuery(true);
			$columns = array('time', 'order_id', 'thash', 'paid', 'confirmations');
			$date = new JDate('now');
			$date = $date->toSql(true);
			$values = array($jdb->quote($date), $order_id, $jdb->quote($thash), $jdb->quote($paid) ,$jdb->quote($confirmations));
			$query
			    ->insert($jdb->quoteName($transactions_table))
			    ->columns($jdb->quoteName($columns))
			    ->values(implode(',', $values));

			$jdb->setQuery($query);
			$jdb->execute();
		}

		function abf_addSale($order_id, $address) {
			$jdb = JFactory::getDBO();
			$sale_table = '#__apirone_sale';
			$query = $jdb->getQuery(true);

			// Insert columns.
			$columns = array('time', 'order_id', 'address');
			// Insert values.
			$date = new JDate('now');
			$date = $date->toSql(true);
			$values = array($jdb->quote($date), $order_id, $jdb->quote($address));

			$query
			    ->insert($jdb->quoteName($sale_table))
			    ->columns($jdb->quoteName($columns))
			    ->values(implode(',', $values));

			// Set the query using our newly populated query object and execute it.
			$jdb->setQuery($query);
			$jdb->execute();
		}

	function abf_updateTransaction($where_paid, $confirmations, $thash = NULL, $where_order_id = NULL, $where_thash = 'empty') {
		$jdb = JFactory::getDbo();
		$query = $jdb->getQuery(true);
		$transactions_table = '#__apirone_transactions';
		$date = new JDate('now');
		$date = $date->toSql(true);		

		if (is_null($thash) || is_null($where_order_id)) {
			$fields = array(
			    $jdb->quoteName('time') . ' = ' . $jdb->quote($date),
			    $jdb->quoteName('confirmations') . ' = ' . $jdb->quote($confirmations)
			);
			$conditions = array(
			    $jdb->quoteName('paid') . ' = ' . $jdb->quote($where_paid), 
			    $jdb->quoteName('thash') . ' = ' . $jdb->quote($where_thash)
			);

		} else{

			$fields = array(
				$jdb->quoteName('thash') . ' = ' . $jdb->quote($thash),
			    $jdb->quoteName('time') . ' = ' . $jdb->quote($date),
			    $jdb->quoteName('confirmations') . ' = ' . $jdb->quote($confirmations)
			);
			$conditions = array(
			    $jdb->quoteName('order_id') . ' = ' . $jdb->quote($where_order_id),
			    $jdb->quoteName('paid') . ' = ' . $jdb->quote($where_paid),
			    $jdb->quoteName('thash') . ' = ' . $jdb->quote($where_thash)
			);
		}

		$query->update($jdb->quoteName($transactions_table))->set($fields)->where($conditions);
		$jdb->setQuery($query);
		$result = $jdb->execute();
	}

		function abf_getKey($order_id){
			$jdb = JFactory::getDBO();
			$key_table = '#__apirone_key';
			$query = $jdb->getQuery(true);
			$query
            	->select('*')
				->from($jdb->quoteName($key_table));
            $jdb->setQuery($query);
            $key = $jdb->loadObject();
            return md5($key->mdkey . $order_id);
		}	
}