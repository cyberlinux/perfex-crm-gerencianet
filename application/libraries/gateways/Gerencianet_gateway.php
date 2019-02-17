<?php
/**
* Gerencianet Perfex CRM
* @version 1.0
* @autor Allan Lima <contato@allanlima.com>
* @website www.allanlima.com
*/

defined('BASEPATH') or exit('No direct script access allowed');

use Gerencianet\Exception\GerencianetException;
use Gerencianet\Gerencianet;

class Gerencianet_gateway extends App_gateway{
    public function __construct(){

         /**
         * Call App_gateway __construct function
         */
        parent::__construct();
        /**
         * REQUIRED
         * Gateway unique id
         * The ID must be alpha/alphanumeric
         */
        $this->setId('gerencianet');

        /**
         * REQUIRED
         * Gateway name
         */
        $this->setName('Gerencianet');

        /**
         * Add gateway settings
        */
        $this->setSettings([
            [
                'name'      => 'production_client_id',
                'encrypted' => true,
                'label'     => 'Produção Client ID',
            ],
			[
                'name'      => 'production_client_secret',
                'encrypted' => true,
                'label'     => 'Produção Client Secret',
            ],
			[
                'name'      => 'dev_client_id',
                'encrypted' => true,
                'label'     => 'Dev Client ID',
            ],
			[
                'name'      => 'dev_client_secret',
                'encrypted' => true,
                'label'     => 'Dev Client Secret',
            ],
			[
                'name'          => 'check_billet',
                'type'          => 'yes_no',
                'default_value' => 1,
                'label'         => 'Verificar boleto existente, caso positivo, redirecionar, do contrário um novo será gerado',
            ],
            [
                'name'          => 'description_dashboard',
                'label'         => 'settings_paymentmethod_description',
                'type'          => 'textarea',
                'default_value' => 'Payment for Invoice {invoice_number}',
            ],
            [
                'name'          => 'currencies',
                'label'         => 'currency',
                'default_value' => 'BRL',
                // 'field_attributes'=>['disabled'=>true],
            ],
            [
                'name'          => 'test_mode_enabled',
                'type'          => 'yes_no',
                'default_value' => 1,
                'label'         => 'settings_paymentmethod_testing_mode',
            ],
        ]);

        /**
         * REQUIRED
         * Hook gateway with other online payment modes
         */
        add_action('before_add_online_payment_modes', [ $this, 'initMode' ]);
    }

    public function process_payment($data){
		if($this->getSetting('check_billet') == 1){
			if(!empty($data["invoice"]->token)){
				$charge = $this->fetch_payment($data);
				
				if ($charge["code"] == 200) {
					if($charge["data"]["status"] == "expired"){
						$pay_charge = $this->create_billet($data);
						redirect($pay_charge["data"]["link"]);
					}else{
						redirect($charge["data"]["payment"]["banking_billet"]["link"]);
					}
				}else{
					// var_dump($charge);
				}
			}else{
				$pay_charge = $this->create_billet($data);
				redirect($pay_charge["data"]["link"]);
			}
		}else{
			$pay_charge = $this->create_billet($data);
			redirect($pay_charge["data"]["link"]);
		}
    }
	
	
	/**
     * Each time a customer click PAY NOW button on the invoice HTML area, the script will process the payment via this function.
     * You can show forms here, redirect to gateway website, redirect to Codeigniter controller etc..
     * @param  array $data - Contains the total amount to pay and the invoice information
     * @return mixed
     */
    public function fetch_payment($data = null){
		if(empty($data)){ return; }
		
		if($this->getSetting('test_mode_enabled') == 1){
			$clientId = $this->decryptSetting('dev_client_id');
			$clientSecret = $this->decryptSetting('dev_client_secret');
			$sandbox = true;
		}else{
			$clientId = $this->decryptSetting('production_client_id');
			$clientSecret = $this->decryptSetting('production_client_secret');
			$sandbox = false;
		}
		 
		$options = [
		  'client_id' => $clientId,
		  'client_secret' => $clientSecret,
		  'sandbox' => $sandbox
		];
		
		$gateway = new Gerencianet($options);
		
		$params = [
			'id' => $data["invoice"]->token
		];
		
		$charge = $gateway->detailCharge($params, []);
		
		logActivity('Gerencianet: Um boleto foi solicitado da fatura ' . $data['invoice']->id . ', com o ID: ' . $data['invoice']->token);
		
		return $charge;
    }
	
	private function create_billet($data = null){
		if(empty($data)){ return; }
		
		if($this->getSetting('test_mode_enabled') == 1){
			$clientId = $this->decryptSetting('dev_client_id');
			$clientSecret = $this->decryptSetting('dev_client_secret');
			$sandbox = true;
		}else{
			$clientId = $this->decryptSetting('production_client_id');
			$clientSecret = $this->decryptSetting('production_client_secret');
			$sandbox = false;
		}
		 
		$options = [
		  'client_id' => $clientId,
		  'client_secret' => $clientSecret,
		  'sandbox' => $sandbox
		];
		 
		$invoiceNumber = format_invoice_number($data['invoice']->id);
        $description = str_replace('{invoice_number}', $invoiceNumber, $this->getSetting('description_dashboard'));
        $callbackUrl = site_url('gateways/gerencianet/callback?invoiceid=' . $data['invoice']->id . '&hash=' . $data['invoice']->hash);
		 
		$item_1 = [
			'name' =>  $description,
			'amount' => 1,
			'value' => (int) number_format($data['amount'], 2, '', '')
		];
		 
		$items =  [
			$item_1
		];
		
		$metadata =  [
			'custom_id' => $data['invoice']->id,
			'notification_url' =>  $callbackUrl
		];

		$body  =  [
			'items' => $items,
			'metadata' => $metadata
		];
		
		try {
			$gateway = new Gerencianet($options);
			
			$charge = $gateway->createCharge([], $body);

			if ($charge["code"] == 200) {
				$params = ['id' => $charge["data"]["charge_id"]];
				
				$vat = $data["invoice"]->client->vat;
				$vat = preg_replace("/[^0-9]/", "", $vat);
				
				$phone_number = $data["invoice"]->client->phonenumber;
				$phone_number = preg_replace("/[^0-9]/", "", $phone_number);
				
				if(strlen($vat) == 11){
					$customer = [
						'name' => $data["invoice"]->client->company,
						'cpf' => $vat,
						'phone_number' => $phone_number
					];
				}elseif(strlen($vat) == 14){
					$customer = [
						'phone_number' => $phone_number,
						'juridical_person' => [
							'corporate_name' => $data["invoice"]->client->company,
							'cnpj' => $vat
						]
					];
				}
				
				if(strtotime(date("Y-m-d")) > strtotime($data["invoice"]->duedate)){
					$expire_at = date('Y-m-d', strtotime("+1 days",strtotime(date("Y-m-d"))));
				}else{
					$expire_at = $data["invoice"]->duedate;
				}
			
				$bankingBillet = [
					'expire_at' => $expire_at,
					'customer' => $customer
				];
				
				$payment = ['banking_billet' => $bankingBillet];
				$body = ['payment' => $payment];

				$gateway = new Gerencianet($options);
				$pay_charge = $gateway->payCharge($params, $body);
				
				if ($pay_charge["code"] == 200) {
					$this->ci->db->where('id', $data['invoiceid']);
					$this->ci->db->update('tblinvoices', [
						'token' => $pay_charge["data"]["charge_id"],
					]);
					
					logActivity('Gerencianet: Um novo boleto foi gerado para a fatura ' . $data['invoice']->id . ', com o ID: ' . $pay_charge["data"]["charge_id"]);
					
					return $pay_charge;
				}else{
					return $charge;
				}	
			} else {
				return $charge;
			}
		} catch (GerencianetException $e) {
			print_r($e->code);
			print_r($e->error);
			print_r($e->errorDescription);
		} catch (Exception $e) {
			print_r($e->getMessage());
		}
	}
}
