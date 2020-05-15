<?php

class ControllerExtensionModuleGigaecommerce extends Controller
{
    private $error = array();
    private $auth;

    public function index()
    {
        $this->load->language('extension/module/gigaecommerce');
        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');
          
		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
			$this->model_setting_setting->editSetting('module_gigaecommerce', $this->request->post);

			$this->session->data['success'] = $this->language->get('text_success');

			$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true));
        }
        
        $data['heading_title'] = $this->language->get('heading_title');
        $data['text_edit'] = $this->language->get('text_edit');
		$data['text_enabled'] = $this->language->get('text_enabled');
		$data['text_disabled'] = $this->language->get('text_disabled');
        $data['entry_status'] = $this->language->get('entry_status');
        $data['button_save'] = $this->language->get('button_save');
		$data['button_cancel'] = $this->language->get('button_cancel');
				
		
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
			'href' => $this->url->link('extension/module/gigaecommerce', 'user_token=' . $this->session->data['user_token'], true)
		);

		$data['action'] = $this->url->link('extension/module/gigaecommerce', 'user_token=' . $this->session->data['user_token'], true);

		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true);

        if (isset($this->request->post['module_gigaecommerce_status'])) {
			$data['module_gigaecommerce_status'] = $this->request->post['module_gigaecommerce_status'];
		} else {
			$data['module_gigaecommerce_status'] = $this->config->get('module_gigaecommerce_status');
        }
        
        $data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/module/gigaecommerce', $data));
    }

    public function product()
    {
        $json = array();
        
        $this->load->language('extension/module/gigaecommerce');

        if (!$this->validate()) {
            $json['warning'] = $this->language->get('error_permission');
        } else {
            $this->load->model('extension/module/gigaecommerce');
            $this->load->model('localisation/language');

            $this->auth = $this->model_extension_module_gigaecommerce->auth();

            if (isset($this->auth->status) && $this->auth->status == 'ok') {
                $language = $this->model_localisation_language->getLanguageByCode('pt-br');
                $products = $this->model_extension_module_gigaecommerce->call("/produtos", "GET");

                foreach ($products->produtos as $item) {

                    $data = [
                        'cod_produto' => $item->codProduto,
                        'nome' => $item->descProduto,
                        'descricao' => $item->textoProduto,
                        'grupo' => $item->descGrupo,
                        'peso' => $item->peso,
                        'grade' => $item->grade,
                        'slug' => $this->model_extension_module_gigaecommerce->slugfy($item->descProduto),
                        'slug_grupo' => $this->model_extension_module_gigaecommerce->slugfy($item->descGrupo),
                        'language_id' => $language['language_id']
                    ];
                    
                    if (!$this->model_extension_module_gigaecommerce->getProduct($data)) {
                        $this->model_extension_module_gigaecommerce->addProduct($data);
                    } else {
                        $this->model_extension_module_gigaecommerce->editProduct($data);
                    }
                }
                $json['success'] = $this->language->get('text_success_sync');
            } else {
                $json['warning'] = $this->language->get('text_auth_error');
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function order()
    {
        $json = array();
        
        $this->load->language('extension/module/gigaecommerce');

        if (!$this->validate()) {
            $json['warning'] = $this->language->get('error_permission');
        } else {
            $this->load->model('extension/module/gigaecommerce');
            $this->load->model('localisation/language');
            $this->load->model('customer/custom_field');

            $this->auth = $this->model_extension_module_gigaecommerce->auth();

            if (isset($this->auth->status) && $this->auth->status == 'ok') {
                $language = $this->model_localisation_language->getLanguageByCode('pt-br');

                $orders = $this->model_extension_module_gigaecommerce->getOrders();

                foreach ($orders as $order) {
                    if (empty($order['order_gigaerp_code'])) {
                        $order['custom_field'] = json_decode($order['custom_field'], true);
                        $order['payment_custom_field'] = json_decode($order['payment_custom_field'], true);
                        $order['shipping_custom_field'] = json_decode($order['shipping_custom_field'], true);

                        $customerData = [
                            "cliente" => [
                                "razaoSocial" => $order['customer'],
                                "nomeFantasia" => $order['customer'],
                                "cnpjCpf" => $order['custom_field'][1],
                                "tipoClassificacao" => strlen($order['custom_field'][1]) > 11 ? 0 : 1,
                                "email" => $order['email'],
                                "enderecos" => [
                                    [
                                        "flagPrincipal" => 1,
                                        "flagCobranca" => 1,
                                        "endereco" => $order['payment_address_1'],
                                        "numero" => $order['payment_custom_field'][3],
                                        "bairro" => $order['payment_address_2'],
                                        "cep" => $order['payment_postcode'],
                                        "telefone" => $order['telephone'],
                                    ],
                                    [
                                        "flagPrincipal" => 0,
                                        "endereco" => $order['shipping_address_1'],
                                        "numero" => $order['shipping_custom_field'][3],
                                        "bairro" => $order['shipping_address_2'], 
                                        "cep" => $order['shipping_postcode'],
                                        "telefone" => $order['telephone'], 
                                    ]
                        
                                ]
                            ],
                        ];

                        $customer = $this->model_extension_module_gigaecommerce->call("/cliente/buscarPorCnpj?cnpj={$order['custom_field'][1]}", "GET");
                        
                        if (!empty($customer->clientes)) {
                            $c = $this->model_extension_module_gigaecommerce->call("/cliente/editar/{$customer->clientes[0]->codPessoa}", "POST", $customerData);
                        } else {
                            $c = $this->model_extension_module_gigaecommerce->call("/cliente/editar/nova", "POST", $customerData);
                        }

                        $order['totals'] = array();

                        $totals = $this->model_extension_module_gigaecommerce->getOrderTotal($order['order_id']);

                        foreach ($totals as $total) {
                            $order['totals'][$total['code']] = $total['value'];
                        }

                        $order['products'] = array();

                        $products = $this->model_extension_module_gigaecommerce->getOrderProducts($order['order_id']);

                        foreach ($products as $product) {
                            $order['products'][] = array(
                                'codProduto' => $product['sku'],
                                'codGrade' => $this->model_extension_module_gigaecommerce->getProductOption($order['order_id'], $product['order_product_id']),
                                'qtd' => (float) $product['quantity'],
                                'vrUnitario' => (float) $product['price'],
                                'vrBruto' => (float) $product['total']
                            );
                        }

                        $orderData = [
                            "venda" => [
                                // "codVenda" => 1923,
                                "data" => date('Y-m-d', strtotime($order['date_added'])),
                                "hora" => date('H:i:s', strtotime($order['date_added'])),
                                "codCliente" => $c->codCliente,
                                "vrBruto" => (float) $order['totals']['sub_total'],
                                "vrAcrescimo" => (float) $order['totals']['shipping'],
                                "vrLiquido" => (float) $order['totals']['total'],
                                "itens" => $order['products']
                            ]
                        ];

                        $venda = $this->model_extension_module_gigaecommerce->call("/venda/editar/nova", "POST", $orderData);

                        if (isset($venda->codVenda)) {
                            $sync = $this->model_extension_module_gigaecommerce->updateOrderSync($order['order_id'], $venda->codVenda);
                        }
                    }
                }
                $json['success'] = $this->language->get('text_success_sync');
            } else {
                $json['warning'] = $this->language->get('text_auth_error');
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    protected function validate()
    {
		if (!$this->user->hasPermission('modify', 'extension/module/gigaecommerce')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}

		return !$this->error;
	}

    public function install()
    {
        $this->load->model('extension/module/gigaecommerce');
        $this->model_extension_module_gigaecommerce->install();
    }

    public function uninstall()
    {
        $this->load->model('extension/module/gigaecommerce');
        $this->model_extension_module_gigaecommerce->uninstall();
    }
}