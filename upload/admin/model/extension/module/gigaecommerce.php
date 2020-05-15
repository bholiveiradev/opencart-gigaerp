<?php

class ModelExtensionModuleGigaecommerce extends Model
{
    public function auth()
    {
        $store_id = 0;
        
        $gigaecommerce_company = $this->db->query("SELECT value FROM " . DB_PREFIX . "setting WHERE store_id = '" . (int)$store_id . "' AND `key` = 'config_gigaecommerce_company'");
        $gigaecommerce_key = $this->db->query("SELECT value FROM " . DB_PREFIX . "setting WHERE store_id = '" . (int)$store_id . "' AND `key` = 'config_gigaecommerce_key'");
        
        $config['codEmpresa'] = $gigaecommerce_company->row['value'];
        $config['chaveAPI'] = $gigaecommerce_key->row['value'];

        return $this->call("/authSessao", "POST", $config);
    }

    public function call($ep, $method, $data = array())
    {
        if (!isset($_SESSION)) {
            session_start();
        }

        $store_id = 0;
        $config_type_host = $this->db->query("SELECT value FROM " . DB_PREFIX . "setting WHERE store_id = '" . (int)$store_id . "' AND `key` = 'config_gigaecommerce_type_host'");
        $config_protocol = $this->db->query("SELECT value FROM " . DB_PREFIX . "setting WHERE store_id = '" . (int)$store_id . "' AND `key` = 'config_gigaecommerce_protocol'");
        $config_url = $this->db->query("SELECT value FROM " . DB_PREFIX . "setting WHERE store_id = '" . (int)$store_id . "' AND `key` = 'config_gigaecommerce_url'");
        $config_port = $this->db->query("SELECT value FROM " . DB_PREFIX . "setting WHERE store_id = '" . (int)$store_id . "' AND `key` = 'config_gigaecommerce_port'");

        if ($config_type_host->row['value'] == 'DNS') {
            $url = $config_protocol->row['value'] . '://' . gethostbyname($config_url->row['value']) . ':' . $config_port->row['value'] . $ep;
        } else {
            $url = $config_protocol->row['value'] . '://' . $config_url->row['value'] . ':' . $config_port->row['value'] . $ep;
        }
       
        $ch = curl_init();

        if ($method == "POST") {
            curl_setopt($ch, CURLOPT_POST, 1);
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        if ($method == "GET") {
            if (!empty($data)) {
                $url = sprintf("%s?%s", $url, http_build_query($data));
            }
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=' . session_id());
        curl_setopt($ch, CURLOPT_FAILONERROR, true);

        session_write_close();
        $output = curl_exec($ch);
        if (curl_error($ch)) {
            return curl_error($ch);
        }
        curl_close($ch);

        return json_decode($output);
    }

    public function getProduct($data)
    {
        $query = $this->db->query("SELECT sku FROM " . DB_PREFIX . "product WHERE sku = '". $this->db->escape($data['cod_produto']) ."'");

        if ($query->num_rows > 0){
            return $query->row['sku'];
        } else {
            return false;
        }
    }

    public function addProduct($data)
    {
        $i = 0;
        $data['estoque'] = 0;
        foreach ($data['grade'] as $gr) {
            $data['preco'][$i] = $gr->preco;
            $data['estoque'] += $gr->estoque;
            $i++;
        }

        try{
            // INSERE O PRODUTO
            $this->db->query("INSERT INTO " . DB_PREFIX . "product SET model = '" . (int)$data['cod_produto'] . "', sku = '" . $this->db->escape($data['cod_produto']) . "', quantity = '". (float) $data['estoque'] ."', date_available = NOW(), shipping = '1', subtract = '1', stock_status_id = '5', price = '" . (float) $data['preco'][0] . "', points = '0', weight = '" . (float) $data['peso'] . "', length = '".(float) $data['grade'][0]->comprimento."', width = '".(float) $data['grade'][0]->largura."', height='". (float) $data['grade'][0]->altura ."', weight_class_id = 1, length_class_id = 1, status = '1', date_added = NOW(), date_modified = NOW()");

            $product_id = $this->db->getLastId();

            if ($product_id) {
                // INSERE OS DADOS DA SINCRONIZAÇÃO
                $this->db->query("INSERT INTO ". DB_PREFIX ."gigaecommerce_product_sync SET product_id = '". (int) $product_id. "', product_gigaerp_code = '". $this->db->escape($data['cod_produto']) ."'");

                // INSERE A DESCRIÇÃO DO PRODUTO
                $this->db->query("INSERT INTO " . DB_PREFIX . "product_description SET product_id = '" . (int) $product_id. "', language_id = ". $data['language_id'] .", name = '" . $this->db->escape($data['nome']) . "', description = '" . $this->db->escape($data['descricao']) . "', meta_title = '" . $this->db->escape(strip_tags($data['nome'])) . "', meta_description = '" . $this->db->escape(strip_tags($data['descricao'])) . "'");

                // RELACIONA O PRODUTO A LOJA
                $this->db->query("INSERT INTO " . DB_PREFIX . "product_to_store SET product_id = '" . (int) $product_id . "', store_id = '0'");

                // VERIFICA SE JÁ EXISTE O SLUG
                $slug = $this->db->query("SELECT seo_url_id FROM " . DB_PREFIX . "seo_url WHERE language_id = ". $data['language_id'] . " AND keyword = '". $this->db->escape($data['slug']) ."'");
                
                // SE EXISITIR, DELETA O SLUG EXISTENTE
                if ($slug->num_rows > 0) {
                    $this->db->query("DELETE FROM " . DB_PREFIX . "seo_url WHERE seo_url_id = " . $slug->row['seo_url_id']);
                }

                // INSERE O NOVO SLUG
                $this->db->query("INSERT INTO " . DB_PREFIX . "seo_url SET store_id = 0, language_id = ". $data['language_id'] .", query = 'product_id=" . (int) $product_id . "', keyword = '". $this->db->escape($data['slug']) ."'");

                // VERIFICA SE JÁ EXISTE UMA CATEGORIA (GRUPO) PELO NOME
                $category = $this->db->query("SELECT * FROM " . DB_PREFIX . "category_description WHERE name = '". $data['grupo'] ."'");

                // SE NÃO EXISTIR, INSERE A CATEGORIA E RETORNA O ID. SE EXISTIR RETORNA O ID DA CATEGORIA EXISTENTE
                if ($category->num_rows == 0) {
                    $this->db->query("INSERT INTO " . DB_PREFIX . "category SET parent_id = 0, top = 0, `column` = 1, sort_order = 0, status = 1, date_added = NOW(), date_modified = NOW()");
                    $category_id = $this->db->getLastId();

                    $this->db->query("INSERT INTO " . DB_PREFIX ."category_description SET category_id = '".$category_id."', language_id = ". $data['language_id'] .", name = '". $this->db->escape($data['grupo']) ."', meta_title = '". $this->db->escape($data['grupo']) ."'");

                    $this->db->query("INSERT INTO " . DB_PREFIX ."category_path SET category_id = '". $category_id ."', path_id = '". $category_id ."', level = 0");

                    $this->db->query("INSERT INTO " . DB_PREFIX ."category_to_store SET category_id = '". $category_id ."', store_id = 0");

                    $slug = $this->db->query("SELECT seo_url_id FROM " . DB_PREFIX . "seo_url WHERE language_id = ". $data['language_id'] . " AND keyword = '". $this->db->escape($data['slug_grupo']) ."'");
                    
                    if ($slug->num_rows > 0) {
                        $this->db->query("DELETE FROM " . DB_PREFIX . "seo_url WHERE seo_url_id = " . $slug->row['seo_url_id']);
                    }

                    $this->db->query("INSERT INTO " . DB_PREFIX . "seo_url SET store_id = 0, language_id = ". $data['language_id'] .", query = 'category_id=" . (int)$category_id . "', keyword = '". $this->db->escape($data['slug_grupo']) ."'");
                } else {
                    $category_id = $category->row['category_id'];
                }

                // RELACIONA A CATEGORIA AO PRODUTO
                $this->db->query("INSERT INTO " . DB_PREFIX ."product_to_category SET product_id = '". $product_id ."', category_id = '". $category_id ."'");

                // VERIFICA SE EXISTE MAIS DE UMA OPÇÃO (GRADE), SE EXISTIR CADASTRA E RETORNA O ID DAS OPÇÕES
                if (count($data['grade']) > 1) {
                    $this->db->query("INSERT INTO " . DB_PREFIX . "option SET type = 'select', sort_order = 0");

                    $option_id = $this->db->getLastId();

                    $this->db->query("INSERT INTO " . DB_PREFIX . "option_description SET option_id = '" . $option_id . "', language_id = ". $data['language_id'] .", name = 'Grade/Tam: ". (int)$data['cod_produto'] ."'");

                    $this->db->query("INSERT INTO " . DB_PREFIX . "product_option SET product_id = '". $product_id ."', option_id = '".$option_id."', required = 1");

                    $product_option_id = $this->db->getLastId();

                    $i = 0;
                    foreach ($data['grade'] as $gr) {
                        // VERIFICA SE EXISTE A OPÇÃO PELO NOME
                        $options = $this->db->query("SELECT * FROM " . DB_PREFIX . "option_value_description WHERE option_id = '" . $option_id . "' AND language_id = '". $data['language_id'] ."' AND name = '". $gr->codGrade ."'");

                        if ($options->num_rows == 0) {
                            // SE NÃO EXISTIR, CADASTRA A OPÇÃO
                            $this->db->query("INSERT INTO " . DB_PREFIX . "option_value SET option_id = '" . $option_id . "', sort_order = '" . $i . "'");
                            $option_value_id = $this->db->getLastId();

                            $this->db->query("INSERT INTO " . DB_PREFIX . "option_value_description SET option_value_id = '". $option_value_id."', language_id = ". $data['language_id'] .", option_id = '" . $option_id . "', name = '". $gr->codGrade ."'");

                            $this->db->query("INSERT INTO " . DB_PREFIX . "product_option_value SET product_option_id = '".$product_option_id."', product_id = '".$product_id ."', option_id = '".$option_id."', option_value_id = '". $option_value_id ."', quantity = '". $gr->estoque ."', subtract = 1, price = '". $gr->preco ."', price_prefix = '=', points = 0, points_prefix = '+', weight = 0, weight_prefix = '+'");
                            
                            $i++;
                        } else {
                            $this->db->query("INSERT INTO " . DB_PREFIX . "product_option_value SET product_option_id = '".$product_option_id."', product_id = '".$product_id ."', option_id = '".$option_id."', option_value_id = '".$options->row['option_value_id']."', quantity = '". $gr->estoque ."', subtract = 1, price = '". $gr->preco ."', price_prefix = '=', points = 0, points_prefix = '=', weight = 0, weight_prefix = '+'");
                        }
                    }
                }
            }            
            $this->cache->delete('product');
        } catch (\Exception $e) {
            echo 'Erro: ' . $e->getMessage() . '<br>Código: ' . $e->getCode();
        }
    }

    public function editProduct($data)
    {
        $product = $this->db->query("SELECT product_id FROM " . DB_PREFIX . "product WHERE sku = '". $this->db->escape($data['cod_produto']) ."'");
        $product_id = $product->row['product_id'];

        $i = 0;
        $data['estoque'] = 0;
        foreach ($data['grade'] as $gr) {
            $data['preco'][$i] = $gr->preco;
            $data['estoque'] += $gr->estoque;
            $i++;
        }

        try{
            // ATUALIZA O PRODUTO
            $this->db->query("UPDATE " . DB_PREFIX . "product SET quantity = '". (float) $data['estoque'] ."', price = '" . (float)$data['preco'][0] . "', weight = '" . (float)$data['peso'] . "', length = '".(float) $data['grade'][0]->comprimento."', width = '".(float) $data['grade'][0]->largura."', height='". (float) $data['grade'][0]->altura ."', date_modified = NOW() WHERE product_id = '". (int) $product_id ."'");

            // INSERE OS DADOS DA SINCRONIZAÇÃO
            $this->db->query("INSERT INTO ". DB_PREFIX ."gigaecommerce_product_sync SET product_id = '". (int) $product_id. "', product_gigaerp_code = '". $this->db->escape($data['cod_produto']) ."'");

            // ATUALIZA A DESCRIÇÃO DO PRODUTO
            $this->db->query("UPDATE " . DB_PREFIX . "product_description SET language_id = ". $data['language_id'] .", name = '" . $this->db->escape($data['nome']) . "', description = '" . $this->db->escape($data['descricao']) . "', meta_title = '" . $this->db->escape(strip_tags($data['nome'])) . "', meta_description = '" . $this->db->escape(strip_tags($data['descricao'])) . "' WHERE product_id = '". (int) $product_id ."'");

            // VERIFICA SE JÁ EXISTE O SLUG
            $slug = $this->db->query("SELECT seo_url_id FROM " . DB_PREFIX . "seo_url WHERE language_id = ". $data['language_id'] . " AND keyword = '". $this->db->escape($data['slug']) ."'");
            
            // SE EXISITIR, DELETA O SLUG EXISTENTE
            if ($slug->num_rows > 0) {
                $this->db->query("DELETE FROM " . DB_PREFIX . "seo_url WHERE seo_url_id = " . $slug->row['seo_url_id']);
            }

            // INSERE O NOVO SLUG
            $this->db->query("INSERT INTO " . DB_PREFIX . "seo_url SET store_id = 0, language_id = ". $data['language_id'] .", query = 'product_id=" . (int) $product_id . "', keyword = '". $this->db->escape($data['slug']) ."'");

            // VERIFICA SE JÁ EXISTE UMA CATEGORIA (GRUPO) PELO NOME
            $category = $this->db->query("SELECT * FROM " . DB_PREFIX . "category_description WHERE name = '". $data['grupo'] ."'");

            // SE NÃO EXISTIR, INSERE A CATEGORIA E RETORNA O ID. SE EXISTIR RETORNA O ID DA CATEGORIA EXISTENTE
            if ($category->num_rows == 0) {
                $this->db->query("INSERT INTO " . DB_PREFIX . "category SET parent_id = 0, top = 0, `column` = 1, sort_order = 0, status = 1, date_added = NOW(), date_modified = NOW()");
                $category_id = $this->db->getLastId();

                $this->db->query("INSERT INTO " . DB_PREFIX ."category_description SET category_id = '".$category_id."', language_id = ". $data['language_id'] .", name = '". $this->db->escape($data['grupo']) ."', meta_title = '". $this->db->escape($data['grupo']) ."'");

                $this->db->query("INSERT INTO " . DB_PREFIX ."category_path SET category_id = '". $category_id ."', path_id = '". $category_id ."', level = 0");

                $this->db->query("INSERT INTO " . DB_PREFIX ."category_to_store SET category_id = '". $category_id ."', store_id = 0");

                $slug = $this->db->query("SELECT seo_url_id FROM " . DB_PREFIX . "seo_url WHERE language_id = ". $data['language_id'] . " AND keyword = '". $this->db->escape($data['slug_grupo']) ."'");
                
                if ($slug->num_rows > 0) {
                    $this->db->query("DELETE FROM " . DB_PREFIX . "seo_url WHERE seo_url_id = " . $slug->row['seo_url_id']);
                }

                $this->db->query("INSERT INTO " . DB_PREFIX . "seo_url SET store_id = 0, language_id = ". $data['language_id'] .", query = 'category_id=" . (int)$category_id . "', keyword = '". $this->db->escape($data['slug_grupo']) ."'");
            } else {
                $category_id = $category->row['category_id'];
            }

            // DELETA AS CATEGORIAS RELACIONADAS AO PRODUTO
            $this->db->query("DELETE FROM ". DB_PREFIX ."product_to_category WHERE product_id = '". $product_id ."'");

            // INSERE A CATEGORIA DO PRODUTO
            $this->db->query("INSERT INTO " . DB_PREFIX ."product_to_category SET product_id = '". $product_id ."', category_id = '". $category_id ."'");

            // VERIFICA SE EXISTE MAIS DE UMA OPÇÃO (GRADE), SE EXISTIR CADASTRA E RETORNA O ID DAS OPÇÕES
            if (count($data['grade']) > 1) {
                $opt = $this->db->query("SELECT option_id FROM " . DB_PREFIX . "product_option WHERE product_id = '". $product_id ."'");

                $this->db->query("DELETE FROM ". DB_PREFIX ."product_option WHERE product_id = '". $product_id ."'");
                $this->db->query("DELETE FROM ". DB_PREFIX ."option_description WHERE option_id = '". $opt->row['option_id'] ."'");
                $this->db->query("DELETE FROM ". DB_PREFIX ."option WHERE option_id = '". $opt->row['option_id'] ."'");

                $this->db->query("INSERT INTO " . DB_PREFIX . "option SET type = 'select', sort_order = 0");

                $option_id = $this->db->getLastId();

                $this->db->query("INSERT INTO " . DB_PREFIX . "option_description SET option_id = '" . $option_id . "', language_id = ". $data['language_id'] .", name = 'Grade/Tam: ". (int)$data['cod_produto'] ."'");

                $this->db->query("INSERT INTO " . DB_PREFIX . "product_option SET product_id = '". $product_id ."', option_id = '".$option_id."', required = 1");

                $product_option_id = $this->db->getLastId();

                $i = 0;
                foreach ($data['grade'] as $gr) {
                    // VERIFICA SE EXISTE A OPÇÃO PELO NOME
                    $options = $this->db->query("SELECT * FROM " . DB_PREFIX . "option_value_description WHERE option_id = '" . $option_id . "' AND language_id = '". $data['language_id'] ."' AND name = '". $gr->codGrade ."'");

                    if ($options->num_rows == 0) {
                        // SE NÃO EXISTIR, CADASTRA A OPÇÃO
                        $this->db->query("INSERT INTO " . DB_PREFIX . "option_value SET option_id = '" . $option_id . "', sort_order = '" . $i . "'");
                        $option_value_id = $this->db->getLastId();

                        $this->db->query("INSERT INTO " . DB_PREFIX . "option_value_description SET option_value_id = '". $option_value_id."', language_id = ". $data['language_id'] .", option_id = '" . $option_id . "', name = '". $gr->codGrade ."'");

                        $this->db->query("INSERT INTO " . DB_PREFIX . "product_option_value SET product_option_id = '".$product_option_id."', product_id = '".$product_id ."', option_id = '".$option_id."', option_value_id = '". $option_value_id ."', quantity = '". $gr->estoque ."', subtract = 1, price = '". $gr->preco ."', price_prefix = '=', points = 0, points_prefix = '+', weight = 0, weight_prefix = '+'");
                        
                        $i++;
                    } else {
                        $this->db->query("INSERT INTO " . DB_PREFIX . "product_option_value SET product_option_id = '".$product_option_id."', product_id = '".$product_id ."', option_id = '".$option_id."', option_value_id = '".$options->row['option_value_id']."', quantity = '". $gr->estoque ."', subtract = 1, price = '". $gr->preco ."', price_prefix = '=', points = 0, points_prefix = '=', weight = 0, weight_prefix = '+'");
                    }
                }
            }
            $this->cache->delete('product');
        } catch (\Exception $e) {
            echo 'Erro: ' . $e->getMessage() . '<br>Código: ' . $e->getCode();
        }
    }

    public function getOrders()
    {
        $query = $this->db->query("
            SELECT o.order_id, o.customer_id, CONCAT(o.firstname, ' ', o.lastname) AS customer, o.custom_field, o.telephone, o.email, o.payment_address_1, o.payment_address_2, o.payment_city, o.payment_postcode, o.payment_country, o.payment_zone, o.shipping_address_1, o.shipping_address_2, o.shipping_city, o.shipping_postcode, o.shipping_zone, o.comment, o.payment_custom_field, o.shipping_custom_field, (SELECT os.name FROM " . DB_PREFIX . "order_status os WHERE os.order_status_id = o.order_status_id AND os.language_id = '" . (int) $this->config->get('config_language_id') . "') AS order_status, o.total, o.currency_code, o.currency_value, o.date_added, o.date_modified, gos.order_gigaerp_code, gos.date_sync FROM `" . DB_PREFIX . "order` o
            LEFT JOIN ". DB_PREFIX ."gigaecommerce_order_sync gos ON
            (gos.order_id = o.order_id)
        ");

        return $query->rows;
    }

    public function getOrderProducts($order_id)
    {
        $query = $this->db->query("
            SELECT p.sku, op.* FROM ". DB_PREFIX ."order_product op
            LEFT JOIN ". DB_PREFIX ."product p ON
            (p.model = op.model)
            WHERE op.order_id = '". $order_id ."'
        ");

        return $query->rows;
    }

    public function getProductOption($order_id, $order_product_id)
    {
		$query = $this->db->query("SELECT `value` FROM " . DB_PREFIX . "order_option WHERE order_id = '" . (int)$order_id . "' AND order_product_id = '" . (int)$order_product_id . "'");

		return isset($query->row['value']) ? $query->row['value'] : '*';
	}

    public function getOrderTotal($order_id)
    {
        $query = $this->db->query("
            SELECT code, title, value FROM ". DB_PREFIX ."order_total WHERE order_id = '". (int) $order_id."'
        ");
        return $query->rows;
    }
    
    public function updateOrderSync($order_id, $order_gigaerp_code)
    {
        $query = $this->db->query("
            INSERT INTO ". DB_PREFIX ."gigaecommerce_order_sync SET order_id = '". (int) $order_id ."', order_gigaerp_code = '". $order_gigaerp_code ."', date_sync = NOW()
        ");

        return $query->num_rows;
    }

    public function slugfy($var)
    {
        $var = preg_replace('/[\t\n]/', ' ', $var);
        $var = preg_replace('/\s{2,}/', ' ', $var);
        $list = array('Š' => 's', 'š' => 's', 'Đ' => 'dj', 'đ' => 'dj', 'Ž' => 'z', 'ž' => 'z', 'Č' => 'c', 'č' => 'c', 'Ć' => 'c', 'ć' => 'c', 'À' => 'a', 'Á' => 'a', 'Â' => 'a', 'Ã' => 'a', 'Ä' => 'a', 'Å' => 'a', 'Æ' => 'a', 'Ç' => 'c', 'È' => 'e', 'É' => 'e', 'Ê' => 'e', 'Ë' => 'e', 'Ì' => 'i', 'Í' => 'i', 'Î' => 'i', 'Ï' => 'i', 'Ñ' => 'n', 'Ò' => 'o', 'Ó' => 'o', 'Ô' => 'o', 'Õ' => 'o', 'Ö' => 'o', 'Ø' => 'o', 'Ù' => 'u', 'Ú' => 'u', 'Û' => 'u', 'Ü' => 'u', 'Ý' => 'y', 'Þ' => 'b', 'ß' => 'ss', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'a','ç' => 'c','è' => 'e','é' => 'e','ê' => 'e','ë' => 'e','ì' => 'i','í' => 'i','î' => 'i','ï' => 'i','ð' => 'o','ñ' => 'n','ò' => 'o','ó' => 'o','ô' => 'o','õ' => 'o','ö' => 'o','ø' => 'o','ù' => 'u','ú' => 'u','û' => 'u','ý' => 'y','ý' => 'y','þ' => 'b','ÿ' => 'y','Ŕ' => 'r','ŕ' => 'r','/' => '-',' ' => '-','*' => '-','.' => '-',',' => '-','"' => '-',"'" => '-'
        );

        $var = strtr($var, $list);
        $var = preg_replace('/-{2,}/', '-', $var);
        $var = strtolower($var);

        return $var;
    }

    public function install()
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `". DB_PREFIX ."gigaecommerce_product_sync` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `product_id` INT(11) NOT NULL,
                `product_gigaerp_code` VARCHAR(200) NOT NULL,
                `date_sync` DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(`id`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS `". DB_PREFIX ."gigaecommerce_order_sync` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `order_id` INT(11) NOT NULL,
                `order_gigaerp_code` VARCHAR(200) NOT NULL,
                `date_sync` DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(`id`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
        ");
    }

    public function uninstall()
    {
        $this->db->query("DROP TABLE IF EXISTS `". DB_PREFIX ."gigaecommerce_product_sync`");
        $this->db->query("DROP TABLE IF EXISTS `". DB_PREFIX ."gigaecommerce_order_sync`");
        $this->db->query("DELETE FROM `". DB_PREFIX ."setting` WHERE `key` IN ('config_gigaecommerce_company', 'config_gigaecommerce_key', 'config_gigaecommerce_url')");
    }
}