<?php
/**
* Gerencianet Perfex CRM
* @version 1.0
* @autor Allan Lima <contato@allanlima.com>
* @website www.allanlima.com
*/

defined('BASEPATH') or exit('No direct script access allowed');

class Gerencianet extends CRM_Controller {
    public function __construct(){
        parent::__construct();
    }

    public function callback(){
		$invoiceid = $this->input->get('invoiceid');
        $hash = $this->input->get('hash');

        check_invoice_restrictions($invoiceid, $hash);
		
        $this->db->where('id', $invoiceid);
		$this->db->where('hash', $hash);
		$invoice = $this->db->get('tblinvoices')->row();
		
		if(count($invoice) > 0){
			$data = array('invoice' => $invoice);
				
			$charge = $this->gerencianet_gateway->fetch_payment($data);
			
			if($invoice->status != 2){
				if ($charge["code"] == 200) {
					if($charge["data"]["status"] == "paid"){
						$this->gerencianet_gateway->addPayment(
						[
						  'amount'        => $invoice->total,
						  'invoiceid'     => $invoiceid,
						  'paymentmode'     => 'gerencianet',
						  'paymentmethod' => 'Boleto',
						  'transactionid' => $charge["data"]["charge_id"],
						]);
						
						logActivity('Gerencianet: Confirmação de pagamento para a fatura ' . $data['invoice']->id . ', com o ID: ' . $charge["data"]["charge_id"]);
						echo'Gerencianet: Confirmação de pagamento para a fatura ' . $data['invoice']->id . ', com o ID: ' . $charge["data"]["charge_id"];
					}else{
						logActivity('Gerencianet: Estado do pagamento da fatura ' . $data['invoice']->id . ', com o ID: ' . $charge["data"]["charge_id"] . ', Status: ' . $charge["data"]["status"] );
						echo'Gerencianet: Estado do pagamento da fatura ' . $data['invoice']->id . ', com o ID: ' . $charge["data"]["charge_id"] . ', Status: ' . $charge["data"]["status"];
					}
				}else{
					logActivity('Gerencianet: Falha ao receber callback para a fatura ' . $data['invoice']->id . ', com o ID: ' . $charge["data"]["charge_id"] . " CODE: " . $charge["code"]);
					echo'Gerencianet: Falha ao receber callback para a fatura ' . $data['invoice']->id . ', com o ID: ' . $charge["data"]["charge_id"];
				}
			}else{
				logActivity('Gerencianet: Estado do pagamento da fatura ' . $data['invoice']->id . ', com o ID: ' . $charge["data"]["charge_id"] . ', Status: ' . $charge["data"]["status"] );
				echo'Gerencianet: Estado do pagamento da fatura ' . $data['invoice']->id . ', com o ID: ' . $charge["data"]["charge_id"] . ', Status: ' . $charge["data"]["status"];
			}			
		}else{
			logActivity('Gerencianet: Falha ao receber callback para a fatura ' . $invoiceid . ', com o hash: ' . $hash . ', fatura não encontrada.');
			echo'Gerencianet: Falha ao receber callback para a fatura ' . $invoiceid . ', com o hash: ' . $hash . ', fatura não encontrada.';
		}
    }
}
