<?php
/*
Модуль оплаты Экссперс платежи: Интернет-эквайринг
 */
class Shop_Payment_System_Handler52 extends Shop_Payment_System_Handler
{
	// Идентификатор валюты в hostCMS
	private $_currency_id = 4;
	//тестовый режим (1 - да, 0 - нет)
	private $isTest = 1;
	/*
		Номер услуги
		Можно узнать в личном кабинете сервиса "Экспресс Платежи" в настройках услуги.
	*/
	private $serviceId = 6;
	/*
		Токен
		Можно узнать в личном кабинете сервиса "Экспресс Платежи" в настройках услуги.
	*/
	private $token = "a75b74cbcfe446509e8ee874f421bd68";
	/*
		Использовать цифровую подпись для выставления счетов (1 - да, 0 - нет)
		Значение должно совпадать со значением, установленным в личном кабинете сервиса "Экспресс Платежи".
	*/
	private $isUseSignature = 1;
	/*
		Секретное слово
		Задается в личном кабинете, секретное слово должно совпадать с секретным словом, установленным в личном кабинете сервиса "Экспресс Платежи".
	*/
	private $secretWord = "sandbox.expresspay.by";
	/*
		Использовать цифровую подпись для уведомлений (1 - да, 0 - нет)
		Значение должно совпадать со значением, установленным в личном кабинете сервиса "Экспресс Платежи".
	*/
	private $isUseSignatureForNotif = 0;
	/*
		Секретное слово для уведомлений
		Задается в личном кабинете, секретное слово должно совпадать с секретным словом, установленным в личном кабинете сервиса "Экспресс Платежи".
	*/
	private $secretWordForNotif = "";


	/**
	 * Метод, вызываемый в коде настроек ТДС через Shop_Payment_System_Handler::checkBeforeContent($oShop);
	 */
	public function checkPaymentBeforeContent()
	{
		if (isset($_REQUEST['Data'])) {

			// Преобразуем из JSON в Array
			$data = json_decode($_REQUEST['Data'], true);

			$id = $data['AccountNo'];
			// Получаем ID заказа
			$order_id = intval($id);

			$oShop_Order = Core_Entity::factory('Shop_Order')->find($order_id);

			if (!is_null($oShop_Order->id)) {
				// Вызов обработчика платежной системы
				Shop_Payment_System_Handler::factory($oShop_Order->Shop_Payment_System)
					->shopOrder($oShop_Order)
					->paymentProcessing();
			}
		}
	}

	/* Вызывается на 4-ом шаге оформления заказа*/
	public function execute()
	{
		parent::execute();

		$this->printNotification();

		return $this;
	}

	protected function _processOrder()
	{
		parent::_processOrder();
		// Установка XSL-шаблонов в соответствии с настройками в узле структуры
		$this->setXSLs();
		// Отправка писем клиенту и пользователю
		$this->send();
		return $this;
	}

	// вычисление суммы товаров заказа 
	public function getSumWithCoeff()
	{
		return Shop_Controller::instance()->round(($this->_currency_id > 0
			&& $this->_shopOrder->shop_currency_id > 0
			? Shop_Controller::instance()->getCurrencyCoefficientInShopCurrency(
				$this->_shopOrder->Shop_Currency,
				Core_Entity::factory('Shop_Currency', $this->_currency_id)
			)
			: 0) * $this->_shopOrder->getAmount());
	}

	// обработка ответа от платёжной системы
	public function paymentProcessing()
	{
		$this->ProcessResult();
		return TRUE;
	}

	// оплачивает заказ 
	function ProcessResult()
	{
		$json = Core_Array::getPost('Data');
		$notify_signature = Core_Array::getPost('Signature');

		// Преобразуем из JSON в Array
		$data = json_decode($json, true);

		$id = $data['AccountNo'];

		if ($this->isUseSignatureForNotif) {

			$secretWord = $this->secretWordForNotif;

			if ($notify_signature == $this->computeSignature($json, $secretWord)) {
				if ($data['CmdType'] == '3' && $data['Status'] == '3' || $data['Status'] == '6') { // Оплачен
					$this->_shopOrder->system_information  = "Товар оплачен через Эксперес платежи.\n";
					$this->_shopOrder->paid();
					$this->setXSLs();
					$this->send();
					header("HTTP/1.0 200 OK");
					print 'OK | the notice is processed';
					die();
				} elseif ($data['CmdType'] == '3' && $data['Status'] == '5') {
					$this->_shopOrder->system_information  = 'Эксперес платежи счёт отменён!';
					$this->_shopOrder->save();
					header("HTTP/1.0 400 Bad Request");
					print 'OK | payment aborted';
					die();
				}
			} else {
				$this->_shopOrder->system_information = 'Эксперес платежи хэш не совпал!';
				$this->_shopOrder->save();
				header("HTTP/1.0 400 Bad Request");
				print 'FAILED | wrong notify signature  '; //Ошибка в параметрах
				die();
			}
		} elseif ($data['CmdType'] == '3' && $data['Status'] == '3' || $data['Status'] == '6') {
			$this->_shopOrder->system_information = "Товар оплачен через Эксперес платежи.\n";
			$this->_shopOrder->paid();
			$this->setXSLs();
			$this->send();
			header("HTTP/1.0 200 OK");
			print 'OK | the notice is processed';
			die();
		} elseif ($data['CmdType'] == '3' && $data['Status'] == '5') {
			$this->_shopOrder->system_information = 'Эксперес платежи счёт отменён!';
			$this->_shopOrder->save();
			header("HTTP/1.0 400 Bad Request");
			print 'OK | payment aborted';
			die();
		}
	}

	// печатает форму отправки запроса на сайт платёжной системы
	public function getNotification()
	{
		$baseUrl = "https://api.express-pay.by/v1/";

		if ($this->isTest)
			$baseUrl = "https://sandbox-api.express-pay.by/v1/";

		$url = $baseUrl . "web_cardinvoices";

		$request_params = $this->getInvoiceParam();

		$oShop_Currency = Core_Entity::factory('Shop_Currency')->find($this->_currency_id);

		if (!is_null($oShop_Currency->id)) {

			$button         = '<form method="POST" action="' . $url . '">';

			foreach ($request_params as $key => $value) {
				$button .= "<input type='hidden' name='$key' value='$value'/>";
			}

			$button .= '<input type="submit" class="checkout_button" name="submit_button" value="Оплатить" />';
			$button .= '</form>';

			echo $button;
		}
	}

	public function getInvoice()
	{
		return $this->getNotification();
	}

	//Получение данных для JSON
	public function getInvoiceParam()
	{
		$id = $this->_shopOrder->id;
		$out_summ = number_format($this->getSumWithCoeff(), 2, ',', '');

		$oSite_Alias = $this->_shopOrder->Shop->Site->getCurrentAlias();
		$site_alias = !is_null($oSite_Alias) ? $oSite_Alias->name : '';
		$shop_path = $this->_shopOrder->Shop->Structure->getPath();
		$result_url = 'http://' . $site_alias . $shop_path . 'cart/'; //url на который будет отправлено уведомление о состоянии платежа
		$success_url = $result_url . '?order_id=' . $id . '&payment=success'; //url на который будет перенаправлен плательщик после успешной оплаты
		$fail_url = $result_url . '?order_id=' . $id . "&payment=fail"; //url на который будет перенаправлен плательщик при отказе от оплаты

		$request_params = array(
			'ServiceId'         => $this->serviceId,
			'AccountNo'         => $id,
			'Amount'            => $out_summ,
			'Currency'          => 933,
			'ReturnType'        => 'redirect',
			'ReturnUrl'         => $success_url ,
			'FailUrl'           => $fail_url,
			'Expiration'        => '',
			'Info'              => "Покупка в интернет-магазине",
		);

		$secretWord = $this->isUseSignature ? $this->secretWord : "";

		$request_params['Signature'] = $this->compute_signature($request_params, $secretWord);

		return $request_params;
	}

	//Вычисление цифровой подписи
	public function compute_signature($request_params, $secret_word)
	{
		$secret_word = trim($secret_word);
		$normalized_params = array_change_key_case($request_params, CASE_LOWER);
		$api_method = array(
			"serviceid",
			"accountno",
			"expiration",
			"amount",
			"currency",
			"info",
			"returnurl",
			"failurl",
			"language",
			"sessiontimeoutsecs",
			"expirationdate",
			"returntype"
		);

		$result = $this->token;

		foreach ($api_method as $item)
			$result .= (isset($normalized_params[$item])) ? $normalized_params[$item] : '';

		$hash = strtoupper(hash_hmac('sha1', $result, $secret_word));

		return $hash;
	}

	// Проверка электронной подписи
	function computeSignature($json, $secretWord)
	{
		$hash = NULL;

		$secretWord = trim($secretWord);

		if (empty($secretWord))
			$hash = strtoupper(hash_hmac('sha1', $json, ""));
		else
			$hash = strtoupper(hash_hmac('sha1', $json, $secretWord));
		return $hash;
	}


	private function log_info($name, $message)
	{
		$this->log($name, "INFO", $message);
	}

	private function log($name, $type, $message)
	{
		$log_url = dirname(__FILE__) . '/log';

		if (!file_exists($log_url)) {
			$is_created = mkdir($log_url, 0777);

			if (!$is_created)
				return;
		}

		$log_url .= '/express-pay-' . date('Y.m.d') . '.log';

		file_put_contents($log_url, $type . " - IP - " . $_SERVER['REMOTE_ADDR'] . "; DATETIME - " . date("Y-m-d H:i:s") . "; USER AGENT - " . $_SERVER['HTTP_USER_AGENT'] . "; FUNCTION - " . $name . "; MESSAGE - " . $message . ';' . PHP_EOL, FILE_APPEND);
	}
}
