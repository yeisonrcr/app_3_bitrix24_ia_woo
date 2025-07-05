<?php

if (!defined('ABSPATH')) {
    exit;
}

class GuruX_BTX_AI_Bitrix_API {
    
    private static $instance = null;
    private $config = array();
    
    private function __construct() {
        $this->load_config();
    }
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function load_config() {
        $this->config = array(
            'domain' => gurux_btx_ia_get_option('bitrix_domain'),
            'client_id' => gurux_btx_ia_get_option('client_id'),
            'client_secret' => gurux_btx_ia_get_option('client_secret'),
            'access_token' => gurux_btx_ia_get_option('access_token'),
            'refresh_token' => gurux_btx_ia_get_option('refresh_token'),
            'redirect_uri' => admin_url('admin.php?page=gurux-btx-ai&action=oauth')
        );
    }
    
    public function get_auth_url() {
        if (empty($this->config['domain']) || empty($this->config['client_id'])) {
            return false;
        }
        
        $params = array(
            'response_type' => 'code',
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'scope' => 'crm',
            'state' => wp_create_nonce('GURUX_BTX_widget_oauth')
        );
        
        return 'https://' . $this->config['domain'] . '/oauth/authorize/?' . http_build_query($params);
    }
    
    public function exchange_code_for_tokens($code, $state) {
        // Temporalmente desactivar verificación de nonce para debugging
        // if (!wp_verify_nonce($state, 'GURUX_BTX_widget_oauth')) {
        //     return false;
        // }
        
        $url = 'https://' . $this->config['domain'] . '/oauth/token/';
        
        $data = array(
            'grant_type' => 'authorization_code',
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'redirect_uri' => $this->config['redirect_uri'],
            'code' => $code
        );
        
        $response = $this->make_request($url, $data, 'POST', false);
        
        if ($response && isset($response['access_token'])) {
            gurux_btx_ia_update_option('access_token', $response['access_token']);
            gurux_btx_ia_update_option('refresh_token', $response['refresh_token']);
            
            $this->config['access_token'] = $response['access_token'];
            $this->config['refresh_token'] = $response['refresh_token'];
            
            return true;
        }
        
        return false;
    }
    
    public function refresh_access_token() {
        if (empty($this->config['refresh_token'])) {
            return false;
        }
        
        $url = 'https://' . $this->config['domain'] . '/oauth/token/';
        
        $data = array(
            'grant_type' => 'refresh_token',
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'refresh_token' => $this->config['refresh_token']
        );
        
        $response = $this->make_request($url, $data, 'POST', false);
        
        if ($response && isset($response['access_token'])) {
            gurux_btx_ia_update_option('access_token', $response['access_token']);
            gurux_btx_ia_update_option('refresh_token', $response['refresh_token']);
            
            $this->config['access_token'] = $response['access_token'];
            $this->config['refresh_token'] = $response['refresh_token'];
            
            return true;
        }
        
        return false;
    }
    
    public function api_call($method, $params = array()) {
        if (empty($this->config['access_token'])) {
            return false;
        }
        
        $url = 'https://' . $this->config['domain'] . '/rest/' . $method . '.json';
        
        $data = array_merge($params, array(
            'auth' => $this->config['access_token']
        ));
        
        $response = $this->make_request($url, $data);
        
        if (isset($response['error']) && $response['error'] === 'expired_token') {
            if ($this->refresh_access_token()) {
                $data['auth'] = $this->config['access_token'];
                $response = $this->make_request($url, $data);
            }
        }
        
        return $response;
    }
    
    public function test_connection() {
        $result = array(
            'success' => false,
            'message' => '',
            'needs_reauth' => false
        );
        
        if (empty($this->config['domain'])) {
            $result['message'] = 'Dominio de Bitrix24 no configurado';
            return $result;
        }
        
        if (empty($this->config['client_id']) || empty($this->config['client_secret'])) {
            $result['message'] = 'Client ID o Client Secret no configurados';
            return $result;
        }
        
        if (empty($this->config['access_token'])) {
            $result['message'] = 'No hay access token. Debes autorizar primero.';
            $result['needs_reauth'] = true;
            return $result;
        }
        
        $response = $this->api_call('app.info');
        
        if ($response && isset($response['result'])) {
            $result['success'] = true;
            $result['message'] = 'Conexión exitosa con Bitrix24';
        } else if (isset($response['error'])) {
            switch ($response['error']) {
                case 'invalid_token':
                case 'expired_token':
                    $result['message'] = 'Token inválido o expirado.';
                    $result['needs_reauth'] = true;
                    break;
                default:
                    $result['message'] = 'Error: ' . ($response['error_description'] ?? $response['error']);
            }
        } else {
            $result['message'] = 'Respuesta inválida del servidor';
        }
        
        return $result;
    }
    
    private function make_request($url, $data = array(), $method = 'POST', $use_wp_remote = true) {
        if ($use_wp_remote) {
            $args = array(
                'timeout' => 30,
                'body' => $data,
                'method' => $method
            );
            
            $response = wp_remote_request($url, $args);
            
            if (is_wp_error($response)) {
                return false;
            }
            
            $body = wp_remote_retrieve_body($response);
            return json_decode($body, true);
        } else {
            if (!extension_loaded('curl')) {
                return false;
            }
            
            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => ($method === 'POST'),
                CURLOPT_POSTFIELDS => http_build_query($data),
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false
            ));
            
            $response = curl_exec($ch);
            curl_close($ch);
            
            return json_decode($response, true);
        }
    }
    
    public function is_configured() {
        return !empty($this->config['domain']) && 
               !empty($this->config['client_id']) && 
               !empty($this->config['client_secret']);
    }
    
    public function is_authorized() {
        return $this->is_configured() && !empty($this->config['access_token']);
    }




    /**
     * Manejar callback de OAuth desde Bitrix24
     */
    public function handle_oauth_callback() {
        if (!isset($_GET['code']) || !isset($_GET['state'])) {
            return array(
                'success' => false,
                'message' => 'Parámetros de autorización faltantes'
            );
        }
        
        $code = sanitize_text_field($_GET['code']);
        $state = sanitize_text_field($_GET['state']);
        
        // Intercambiar código por tokens
        $token_result = $this->exchange_code_for_tokens($code, $state);
        
        if ($token_result) {
            // Verificar que los tokens funcionen
            $test_result = $this->test_connection();
            
            if ($test_result['success']) {
                // Guardar timestamp de autorización
                gurux_btx_ia_update_option('bitrix_authorized_at', current_time('mysql'));
                
                return array(
                    'success' => true,
                    'message' => 'Autorización exitosa con Bitrix24',
                    'redirect_url' => admin_url('admin.php?page=gurux-btx-ia&tab=bitrix&auth=success')
                );
            } else {
                // Limpiar tokens inválidos
                $this->clear_tokens();
                
                return array(
                    'success' => false,
                    'message' => 'Tokens obtenidos pero conexión falló: ' . $test_result['message']
                );
            }
        }
        
        return array(
            'success' => false,
            'message' => 'Error intercambiando código por tokens'
        );
    }

    /**
     * Limpiar tokens de autorización
     */
    public function clear_tokens() {
        gurux_btx_ia_update_option('access_token', '');
        gurux_btx_ia_update_option('refresh_token', '');
        gurux_btx_ia_update_option('bitrix_authorized_at', '');
        
        // Limpiar config en memoria
        $this->config['access_token'] = '';
        $this->config['refresh_token'] = '';
        
        return true;
    }

    /**
     * Obtener estado detallado de la conexión
     */
    public function get_connection_status() {
        $status = array(
            'configured' => false,
            'authorized' => false,
            'connected' => false,
            'last_test' => null,
            'authorized_at' => null,
            'message' => ''
        );
        
        // Verificar configuración básica
        if (empty($this->config['domain']) || empty($this->config['client_id']) || empty($this->config['client_secret'])) {
            $status['message'] = 'Configuración incompleta';
            return $status;
        }
        
        $status['configured'] = true;
        
        // Verificar autorización
        if (empty($this->config['access_token']) || empty($this->config['refresh_token'])) {
            $status['message'] = 'Requiere autorización';
            return $status;
        }
        
        $status['authorized'] = true;
        $status['authorized_at'] = gurux_btx_ia_get_option('bitrix_authorized_at');
        
        // Test de conexión en tiempo real
        $test_result = $this->test_connection();
        
        if ($test_result['success']) {
            $status['connected'] = true;
            $status['message'] = 'Conectado correctamente';
        } else {
            $status['message'] = 'Error de conexión: ' . $test_result['message'];
            
            // Si es error de token, marcar como no autorizado
            if (isset($test_result['needs_reauth']) && $test_result['needs_reauth']) {
                $status['authorized'] = false;
            }
        }
        
        return $status;
    }







}