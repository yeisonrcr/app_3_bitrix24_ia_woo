<?php
/**
 * GuruX BTX IA Main - Controlador Principal del Sistema
 * 
 * Coordina todas las clases, maneja WordPress hooks y workflow completo
 * Punto central de inicialización y gestión del plugin
 * 
 * @package GuruX_BTX_IA
 * @version 1.0.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}


class GuruX_BTX_IA_Main {
    
    /**
     * Instancia única (Singleton)
     */
    private static $instance = null;
    
    /**
     * Componentes del sistema
     */
    private $logger;
    private $bitrix_api;
    private $claude_api;
    private $chat_analyzer;
    private $deals_manager;
    private $client_manager;
    
    /**
     * Estado del plugin
     */
    private $plugin_loaded = false;
    private $components_initialized = false;
    
    /**
     * Configuración principal
     */
    private $config;
    
    /**
     * Constructor privado para Singleton
     */
    private function __construct() {
        $this->load_config();
        $this->init_hooks();
    }
    
    /**
     * Obtener instancia única
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Inicializar el plugin
     */
    
    public function init() {
        if ($this->plugin_loaded) {
            return;
        }
        
        try {
            // Verificar requisitos del sistema
            if (!$this->check_system_requirements()) {
                error_log('GuruX BTX IA: Requisitos del sistema no cumplidos');
                return false;
            }
            
            // Intentar inicializar componentes
            try {
                $this->init_components();
            } catch (Exception $e) {
                // Si falla la inicialización normal, usar lazy loading
                error_log('GuruX BTX IA: Usando inicialización lazy debido a: ' . $e->getMessage());
                $this->init_components_lazy();
            }
            
            // Configurar hooks adicionales
            $this->setup_additional_hooks();
            
            // Registrar APIs públicas
            $this->register_public_apis();
            
            // Configurar cron jobs
            $this->setup_cron_jobs();
            
            $this->plugin_loaded = true;
            
            // Log de éxito
            if ($this->logger) {
                $this->logger->info('GuruX BTX IA inicializado correctamente', array(
                    'version' => defined('GURUX_BTX_IA_VERSION') ? GURUX_BTX_IA_VERSION : 'unknown',
                    'components_loaded' => $this->components_initialized,
                    'lazy_loading' => !$this->components_initialized
                ));
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log('Error crítico inicializando GuruX BTX IA: ' . $e->getMessage());
            return false;
        }
    }


    // ============================================================================
    // MÉTODOS PRINCIPALES DEL WORKFLOW
    // ============================================================================
    
    /**
     * Procesar nueva conversación (método principal público)
     */
    
    public function process_conversation($conversation_data, $client_phone = null, $options = array()) {
        try {
            if (!$this->is_system_ready()) {
                return array(
                    'success' => false,
                    'message' => 'Sistema no está listo para procesar conversaciones',
                    'error_code' => 'SYSTEM_NOT_READY'
                );
            }
            
            // Verificar límites de uso
            $limits_check = $this->check_usage_limits();
            if (!$limits_check['allowed']) {
                return array(
                    'success' => false,
                    'message' => $limits_check['message'],
                    'limits_exceeded' => true,
                    'error_code' => 'LIMITS_EXCEEDED'
                );
            }
            
            // Obtener analizador de chat con lazy loading
            $chat_analyzer = $this->get_chat_analyzer();
            if (!$chat_analyzer) {
                return array(
                    'success' => false,
                    'message' => 'Analizador de chat no disponible',
                    'error_code' => 'ANALYZER_NOT_AVAILABLE'
                );
            }
            
            // Procesar con el analizador de chat
            $analysis_result = $chat_analyzer->analyze($conversation_data, $client_phone, $options);
            
            // Registrar métricas de uso
            $this->update_usage_metrics($analysis_result);
            
            // Ejecutar hooks post-procesamiento
            do_action('gurux_btx_ia_conversation_processed', $analysis_result, $conversation_data, $client_phone);
            
            return $analysis_result;
            
        } catch (Exception $e) {
            $logger = $this->get_logger();
            if ($logger) {
                $logger->error('Error procesando conversación', array(
                    'error' => $e->getMessage(),
                    'client_phone' => $client_phone,
                    'trace' => $e->getTraceAsString()
                ));
            }
            
            return array(
                'success' => false,
                'message' => 'Error interno procesando conversación',
                'error_code' => 'PROCESSING_ERROR'
            );
        }
    }






    /**
     * Webhook para recibir conversaciones externas
     */

    public function webhook_conversation_receiver() {
        // Verificar rate limiting por IP
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $rate_key = 'gurux_webhook_rate_' . md5($ip);
        $current_requests = get_transient($rate_key) ?: 0;
        
        if ($current_requests > 100) { // 100 requests/hora
            wp_die('Rate limit exceeded', 'Too Many Requests', array('response' => 429));
        }
        
        set_transient($rate_key, $current_requests + 1, 3600);
        


        // Verificar autenticación
        if (!$this->verify_webhook_auth()) {
            wp_die('Unauthorized', 'Unauthorized', array('response' => 401));
        }
        
        // Obtener datos del webhook
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(array('message' => 'JSON inválido'));
        }
        
        // Validar estructura de datos
        $validation = $this->validate_webhook_data($data);
        if (!$validation['valid']) {
            wp_send_json_error(array('message' => $validation['message']));
        }
        
        // Procesar conversación
        $result = $this->process_conversation(
            $data['conversation'],
            $data['client_phone'] ?? null,
            $data['options'] ?? array()
        );
        
        // Responder con resultado
        if ($result['success']) {
            wp_send_json_success(array(
                'analysis_id' => $result['analysis_id'],
                'message' => 'Conversación procesada exitosamente'
            ));
        } else {
            wp_send_json_error(array(
                'message' => $result['message'],
                'error_code' => $result['error_code'] ?? 'UNKNOWN_ERROR'
            ));
        }
    }
    
    /**
     * Procesar cola de análisis pendientes
     */
    public function process_analysis_queue($batch_size = 10) {
        if (!$this->is_system_ready()) {
            return array(
                'success' => false,
                'message' => 'Sistema no disponible'
            );
        }
        
        $results = $this->chat_analyzer->analyze_pending_conversations($batch_size);
        
        $this->get_logger()->info('Cola de análisis procesada', array(
            'batch_size' => $batch_size,
            'processed' => $results['processed'],
            'successful' => $results['successful'],
            'failed' => $results['failed']
        ));
        
        // Reprogramar si hay más pendientes
        if ($results['processed'] === $batch_size) {
            wp_schedule_single_event(time() + 60, 'gurux_btx_ia_process_queue');
        }
        
        return $results;
    }
    
    // ============================================================================
    // MÉTODOS DE CONFIGURACIÓN Y GESTIÓN
    // ============================================================================

    public function ajax_analyze_conversation() {
        try {
            // 1. Verificar nonce de seguridad
            if (!check_ajax_referer('gurux_btx_ia_nonce', 'nonce', false)) {
                wp_send_json_error(array(
                    'message' => 'Token de seguridad inválido',
                    'code' => 'INVALID_NONCE'
                ));
            }
            
            // 2. Verificar permisos de usuario
            if (!current_user_can('manage_options')) {
                wp_send_json_error(array(
                    'message' => 'No tienes permisos para realizar esta acción',
                    'code' => 'INSUFFICIENT_PERMISSIONS'
                ));
            }
            
            // 3. Validar y sanitizar datos de entrada
            $raw_conversation = wp_unslash($_POST['conversation'] ?? '');
            
            // Validar longitud ANTES de sanitizar
            if (strlen($raw_conversation) < 10) {
                wp_send_json_error(array(
                    'message' => 'La conversación es demasiado corta (mínimo 10 caracteres)',
                    'code' => 'CONVERSATION_TOO_SHORT'
                ));
            }
            
            if (strlen($raw_conversation) > 50000) {
                wp_send_json_error(array(
                    'message' => 'La conversación es demasiado larga (máximo 50,000 caracteres)',
                    'code' => 'CONVERSATION_TOO_LONG'
                ));
            }
            
            // Sanitizar datos
            $conversation = sanitize_textarea_field($raw_conversation);
            $template = sanitize_text_field($_POST['template'] ?? 'default');
            $client_phone = sanitize_text_field($_POST['client_phone'] ?? '');
            
            // Validar que la conversación no esté vacía después de sanitizar
            if (empty(trim($conversation))) {
                wp_send_json_error(array(
                    'message' => 'La conversación no puede estar vacía',
                    'code' => 'EMPTY_CONVERSATION'
                ));
            }
            
            // 4. Validar teléfono si se proporciona
            if (!empty($client_phone) && !gurux_btx_ia_is_valid_phone($client_phone)) {
                wp_send_json_error(array(
                    'message' => 'Formato de teléfono inválido',
                    'code' => 'INVALID_PHONE_FORMAT'
                ));
            }
            
            // 5. Validar plantilla
            $valid_templates = array('default', 'ecommerce', 'telecomunicaciones', 'servicios_financieros', 'salud');
            if (!in_array($template, $valid_templates)) {
                $template = 'default'; // Fallback seguro
            }
            
            // 6. Preparar opciones para el análisis
            $options = array();
            if (!empty($template) && $template !== 'default') {
                $options['force_template'] = $template;
            }
            
            // Agregar metadatos de la solicitud
            $options['source'] = 'manual_admin';
            $options['user_id'] = get_current_user_id();
            $options['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            
            // 7. Log de la solicitud
            $this->get_logger()->info('Análisis manual solicitado desde admin', array(
                'user_id' => get_current_user_id(),
                'template' => $template,
                'conversation_length' => strlen($conversation),
                'has_phone' => !empty($client_phone)
            ));
            
            // 8. Procesar la conversación
            $result = $this->process_conversation($conversation, $client_phone, $options);
            
            // 9. Preparar respuesta
            if ($result['success']) {
                // Enriquecer respuesta con datos adicionales para el admin
                $response_data = array(
                    'analysis' => $result['analysis'],
                    'metadata' => $result['metadata'],
                    'analysis_id' => $result['analysis_id'],
                    'conversation_id' => $result['conversation_id'],
                    'client_id' => $result['client_id'],
                    'processing_info' => array(
                        'template_used' => $result['metadata']['template_used'] ?? $template,
                        'execution_time' => $result['metadata']['execution_time'] ?? 0,
                        'ai_cost' => number_format($result['metadata']['ai_cost'] ?? 0, 4),
                        'processed_at' => current_time('mysql')
                    )
                );
                
                // Log de éxito
                $this->get_logger()->info('Análisis manual completado exitosamente', array(
                    'analysis_id' => $result['analysis_id'],
                    'sentiment_score' => $result['analysis']['sentiment_score'],
                    'urgency_level' => $result['analysis']['urgency_level'],
                    'execution_time' => $result['metadata']['execution_time'] . 'ms'
                ));
                
                wp_send_json_success($response_data);
                
            } else {
                // Log de error
                $this->get_logger()->error('Error en análisis manual', array(
                    'error_message' => $result['message'],
                    'error_code' => $result['error_code'] ?? 'UNKNOWN_ERROR',
                    'user_id' => get_current_user_id(),
                    'conversation_length' => strlen($conversation)
                ));
                
                // Preparar mensaje de error amigable
                $error_messages = array(
                    'PROCESSING_ERROR' => 'Error interno procesando la conversación',
                    'API_ERROR' => 'Error de conectividad con el servicio de IA',
                    'INVALID_RESPONSE' => 'Respuesta inválida del servicio de análisis',
                    'RATE_LIMIT_EXCEEDED' => 'Límite de análisis diarios alcanzado'
                );
                
                $user_friendly_message = $error_messages[$result['error_code'] ?? ''] ?? $result['message'];
                
                wp_send_json_error(array(
                    'message' => $user_friendly_message,
                    'code' => $result['error_code'] ?? 'ANALYSIS_FAILED',
                    'details' => WP_DEBUG ? $result['message'] : null // Solo mostrar detalles en debug
                ));
            }
            
        } catch (Exception $e) {
            // Manejo de excepciones no capturadas
            $this->get_logger()->error('Excepción no manejada en análisis manual', array(
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => get_current_user_id()
            ));
            
            wp_send_json_error(array(
                'message' => 'Error interno del servidor',
                'code' => 'INTERNAL_SERVER_ERROR',
                'details' => WP_DEBUG ? $e->getMessage() : null
            ));
        }
    }
    
    /**
     * Activar plugin
     */
    public function activate_plugin() {
        try {
            // Verificar requisitos
            if (!$this->check_system_requirements()) {
                deactivate_plugins(plugin_basename(GURUX_BTX_IA_PLUGIN_DIR . 'gurux-btx-ia.php'));
                wp_die('GuruX BTX IA requiere WordPress 5.0+ y PHP 7.4+');
            }
            
            // Crear tablas de base de datos
            $this->create_database_tables();
            
            // Crear directorio de logs
            $this->setup_logs_directory();
            
            // Configuración inicial
            $this->setup_initial_configuration();
            
            // Programar cron jobs
            $this->schedule_cron_jobs();
            
            // Migrar desde plugins hermanos si es necesario
            $this->migrate_from_sibling_plugins();
            
            // Guardar versiones
            update_option('gurux_btx_ia_version', GURUX_BTX_IA_VERSION);
            update_option('gurux_btx_ia_db_version', GURUX_BTX_IA_DB_VERSION);
            update_option('gurux_btx_ia_activated_at', current_time('mysql'));
            
            $this->get_logger()->info('Plugin activado exitosamente', array(
                'version' => GURUX_BTX_IA_VERSION
            ));
            
        } catch (Exception $e) {
            error_log('Error activando GuruX BTX IA: ' . $e->getMessage());
            deactivate_plugins(plugin_basename(GURUX_BTX_IA_PLUGIN_DIR . 'gurux-btx-ia.php'));
            wp_die('Error activando plugin: ' . $e->getMessage());
        }
    }
    
    /**
     * Desactivar plugin
     */
    public function deactivate_plugin() {
        // Limpiar cron jobs
        $this->clear_cron_jobs();
        
        // Limpiar cache
        $this->clear_all_caches();
        
        $this->get_logger()->info('Plugin desactivado');
    }
    
    /**
     * Verificar y actualizar base de datos
     */
    public function check_database_updates() {
        $current_db_version = get_option('gurux_btx_ia_db_version', '0.0.0');
        
        if (version_compare($current_db_version, GURUX_BTX_IA_DB_VERSION, '<')) {
            $this->get_logger()->info('Actualizando base de datos', array(
                'from_version' => $current_db_version,
                'to_version' => GURUX_BTX_IA_DB_VERSION
            ));
            
            $this->create_database_tables();
            update_option('gurux_btx_ia_db_version', GURUX_BTX_IA_DB_VERSION);
        }
    }
    
    // ============================================================================
    // HANDLERS AJAX
    // ============================================================================
    
    /**
     * Test de conexiones
     */
    public function ajax_test_connection() {
        if (!check_ajax_referer('gurux_btx_ia_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Nonce inválido'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sin permisos'));
        }
        
        $test_type = sanitize_text_field($_POST['type'] ?? '');
        
        switch ($test_type) {
            case 'claude_api':
                $result = $this->claude_api->test_connection();
                break;
                
            case 'bitrix_api':
                $result = $this->bitrix_api->test_connection();
                break;
                
            case 'database':
                $result = $this->test_database_connection();
                break;
                
            default:
                wp_send_json_error(array('message' => 'Tipo de test inválido'));
        }
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    
    /**
     * Obtener datos del dashboard
     */
    public function ajax_get_dashboard_data() {
        if (!check_ajax_referer('gurux_btx_ia_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Nonce inválido'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sin permisos'));
        }
        
        try {
            $dashboard_data = array(
                'metrics' => $this->get_dashboard_metrics(),
                'recent_analyses' => $this->get_recent_analyses(10),
                'alerts' => $this->get_system_alerts(),
                'usage_stats' => $this->get_usage_statistics()
            );
            
            wp_send_json_success($dashboard_data);
            
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'Error obteniendo datos del dashboard',
                'error' => $e->getMessage()
            ));
        }
    }
    
    /**
     * Guardar configuración
     */
    public function ajax_save_settings() {
        if (!check_ajax_referer('gurux_btx_ia_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Nonce inválido'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sin permisos'));
        }
        
        $settings = array();
        $allowed_settings = array(
            'claude_api_key',
            'daily_analysis_limit',
            'auto_create_deals',
            'auto_create_contacts',
            'sentiment_threshold',
            'urgency_notifications',
            'default_pipeline'
        );
        
        foreach ($allowed_settings as $setting) {
            if (isset($_POST[$setting])) {
                $settings[$setting] = gurux_btx_ia_sanitize_input($_POST[$setting], $this->get_setting_type($setting));
            }
        }
        
        $result = gurux_btx_ia_update_settings($settings);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        } else {
            wp_send_json_success(array('message' => 'Configuración guardada exitosamente'));
        }
    }
    
    // ============================================================================
    // MÉTODOS PRIVADOS DE INICIALIZACIÓN
    // ============================================================================
    
    /**
     * Cargar configuración principal
     */
    private function load_config() {
        $this->config = array(
            'debug_mode' => WP_DEBUG,
            'webhook_auth_required' => true,
            'auto_process_queue' => true,
            'batch_size' => 10,
            'webhook_endpoint' => 'gurux-btx-ia-webhook',
            'api_endpoint' => 'gurux-btx-ia-api'
        );
    }
    
    /**
     * Inicializar hooks de WordPress
     */
    private function init_hooks() {
        // Hooks de activación/desactivación
        register_activation_hook(GURUX_BTX_IA_PLUGIN_DIR . 'gurux-btx-ia.php', array($this, 'activate_plugin'));
        register_deactivation_hook(GURUX_BTX_IA_PLUGIN_DIR . 'gurux-btx-ia.php', array($this, 'deactivate_plugin'));
        
        // Hooks de inicialización
        add_action('plugins_loaded', array($this, 'check_database_updates'));
        add_action('init', array($this, 'init'));
        
        // AJAX hooks
        add_action('wp_ajax_gurux_btx_ia_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_gurux_btx_ia_analyze_conversation', array($this, 'ajax_analyze_conversation'));
        add_action('wp_ajax_gurux_btx_ia_get_dashboard_data', array($this, 'ajax_get_dashboard_data'));
        add_action('wp_ajax_gurux_btx_ia_save_settings', array($this, 'ajax_save_settings'));
        
        // Cron hooks
        add_action('gurux_btx_ia_daily_cleanup', array($this, 'daily_cleanup_task'));
        add_action('gurux_btx_ia_process_queue', array($this, 'process_analysis_queue'));
        add_action('gurux_btx_ia_sync_clients', array($this, 'sync_clients_task'));


        // AJAX hooks para Dashboard - Fase 2
        add_action('wp_ajax_gurux_btx_ia_get_dashboard_metrics', array($this, 'ajax_get_dashboard_metrics'));
        add_action('wp_ajax_gurux_btx_ia_get_satisfaction_trends', array($this, 'ajax_get_satisfaction_trends'));
        add_action('wp_ajax_gurux_btx_ia_get_critical_cases', array($this, 'ajax_get_critical_cases'));
        add_action('wp_ajax_gurux_btx_ia_get_agent_performance', array($this, 'ajax_get_agent_performance'));
        add_action('wp_ajax_gurux_btx_ia_get_insights', array($this, 'ajax_get_insights'));

    }
    
    /**
     * Verificar requisitos del sistema
     */
    private function check_system_requirements() {
        global $wp_version;
        
        // Verificar WordPress
        if (version_compare($wp_version, '5.0', '<')) {
            return false;
        }
        
        // Verificar PHP
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            return false;
        }
        
        // Verificar extensiones PHP
        $required_extensions = array('curl', 'json', 'mbstring');
        foreach ($required_extensions as $extension) {
            if (!extension_loaded($extension)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Inicializar componentes del sistema
     */
    private function debug_classes() {
        $classes = array(
            'GuruX_Logger',
            'GuruX_BTX_AI_Bitrix_API', 
            'GuruX_Claude_API',
            'GuruX_Chat_Analyzer',
            'GuruX_Deals_Manager',
            'GuruX_Client_Manager'
        );
        
        foreach ($classes as $class) {
            error_log("Clase $class existe: " . (class_exists($class) ? 'SÍ' : 'NO'));
        }
    }

    private function init_components() {
        try {
            // Verificar que las clases existen antes de instanciarlas
            if (!class_exists('GuruX_Logger')) {
                throw new Exception('Clase GuruX_Logger no encontrada');
            }
            $this->logger = GuruX_Logger::get_instance();
            

            if (!class_exists('GuruX_BTX_AI_Bitrix_API')) {
                throw new Exception('Clase GuruX_BTX_AI_Bitrix_API no encontrada');
            }
            $this->bitrix_api = GuruX_BTX_AI_Bitrix_API::get_instance();

            
            if (!class_exists('GuruX_Claude_API')) {
                throw new Exception('Clase GuruX_Claude_API no encontrada');
            }
            $this->claude_api = GuruX_Claude_API::get_instance();
            
            if (!class_exists('GuruX_Chat_Analyzer')) {
                throw new Exception('Clase GuruX_Chat_Analyzer no encontrada');
            }
            $this->chat_analyzer = GuruX_Chat_Analyzer::get_instance();
            
            if (!class_exists('GuruX_Deals_Manager')) {
                throw new Exception('Clase GuruX_Deals_Manager no encontrada');
            }
            $this->deals_manager = GuruX_Deals_Manager::get_instance();
            
            if (!class_exists('GuruX_Client_Manager')) {
                throw new Exception('Clase GuruX_Client_Manager no encontrada');
            }
            $this->client_manager = GuruX_Client_Manager::get_instance();
            
            $this->components_initialized = true;
            
            // Log de éxito con logger ya inicializado
            $this->logger->info('Componentes inicializados exitosamente');
            
        } catch (Exception $e) {
            // Log de error (puede que logger no esté disponible)
            error_log('Error inicializando componentes GuruX BTX IA: ' . $e->getMessage());
            
            // Resetear estado
            $this->components_initialized = false;
            $this->plugin_loaded = false;
            
            throw $e;
        }
    }


    /**
     * Método alternativo con carga lazy (perezosa)
     */
    private function init_components_lazy() {
        // Solo inicializar logger por ahora
        if (class_exists('GuruX_Logger')) {
            $this->logger = GuruX_Logger::get_instance();
            $this->logger->info('Inicializando componentes con carga lazy');
        }
        
        // Los demás componentes se inicializarán cuando se necesiten
        $this->components_initialized = true;
    }

    /**
     * Getter con inicialización lazy para Bitrix API
     */
    public function get_bitrix_api() {
        if (!$this->bitrix_api && class_exists('GuruX_BTX_AI_Bitrix_API')) {
            $this->bitrix_api = GuruX_BTX_AI_Bitrix_API::get_instance();
        }
        return $this->bitrix_api;
    }






    //NUEVAS FUNCIONES
/**
     * FUNCIONES AJAX PARA DASHBOARD - FASE 2
     */

    /**
     * AJAX: Obtener métricas del dashboard
     */
    public function ajax_get_dashboard_metrics() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sin permisos'));
        }
        
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'gurux_btx_ia_nonce')) {
            wp_send_json_error(array('message' => 'Nonce inválido'));
        }
        
        $days = intval($_POST['days'] ?? 7);
        $days = max(1, min(365, $days)); // Limitar entre 1 y 365 días
        
        try {
            $metrics = $this->get_dashboard_metrics($days);
            
            wp_send_json_success(array(
                'metrics' => $metrics,
                'timestamp' => current_time('mysql')
            ));
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'Error obteniendo métricas: ' . $e->getMessage()
            ));
        }
    }

    /**
     * AJAX: Obtener tendencias de satisfacción
     */
    public function ajax_get_satisfaction_trends() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sin permisos'));
        }
        
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'gurux_btx_ia_nonce')) {
            wp_send_json_error(array('message' => 'Nonce inválido'));
        }
        
        $days = intval($_POST['days'] ?? 30);
        $days = max(7, min(365, $days));
        
        try {
            $trends = $this->get_satisfaction_trends($days);
            
            wp_send_json_success(array(
                'trends' => $trends,
                'timestamp' => current_time('mysql')
            ));
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'Error obteniendo tendencias: ' . $e->getMessage()
            ));
        }
    }

    /**
     * AJAX: Obtener casos críticos pendientes
     */
    public function ajax_get_critical_cases() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sin permisos'));
        }
        
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'gurux_btx_ia_nonce')) {
            wp_send_json_error(array('message' => 'Nonce inválido'));
        }
        
        try {
            $critical_cases = $this->get_critical_cases_pending();
            
            wp_send_json_success(array(
                'cases' => $critical_cases,
                'timestamp' => current_time('mysql')
            ));
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'Error obteniendo casos críticos: ' . $e->getMessage()
            ));
        }
    }

    /**
     * AJAX: Obtener rendimiento de agentes
     */
    public function ajax_get_agent_performance() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sin permisos'));
        }
        
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'gurux_btx_ia_nonce')) {
            wp_send_json_error(array('message' => 'Nonce inválido'));
        }
        
        $days = intval($_POST['days'] ?? 7);
        $days = max(1, min(90, $days));
        
        try {
            $performance = $this->get_agent_performance_metrics($days);
            
            wp_send_json_success(array(
                'agents' => $performance,
                'timestamp' => current_time('mysql')
            ));
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'Error obteniendo rendimiento de agentes: ' . $e->getMessage()
            ));
        }
    }

    /**
     * AJAX: Obtener insights y recomendaciones
     */
    public function ajax_get_insights() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sin permisos'));
        }
        
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'gurux_btx_ia_nonce')) {
            wp_send_json_error(array('message' => 'Nonce inválido'));
        }
        
        $days = intval($_POST['days'] ?? 7);
        
        try {
            // Obtener métricas básicas para generar insights
            $metrics = $this->get_dashboard_metrics($days);
            $critical_cases = $this->get_critical_cases_pending();
            
            // Generar insights automáticos
            $insights = $this->generate_dashboard_insights($metrics, $critical_cases);
            
            // Generar recomendaciones
            $recommendations = $this->generate_dashboard_recommendations($metrics, $critical_cases);
            
            wp_send_json_success(array(
                'insights' => $insights,
                'recommendations' => $recommendations,
                'timestamp' => current_time('mysql')
            ));
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'Error generando insights: ' . $e->getMessage()
            ));
        }
    }

    /**
     * Generar insights automáticos del dashboard
     */
    private function generate_dashboard_insights($metrics, $critical_cases) {
        $insights = array();
        
        // Insight sobre satisfacción
        $avg_sentiment = floatval($metrics['summary']['avg_sentiment'] ?? 0);
        if ($avg_sentiment >= 8.0) {
            $insights[] = array(
                'type' => 'positive',
                'message' => 'Excelente nivel de satisfacción general (' . number_format($avg_sentiment, 1) . '/10)'
            );
        } elseif ($avg_sentiment < 5.0) {
            $insights[] = array(
                'type' => 'warning',
                'message' => 'Satisfacción por debajo del objetivo (' . number_format($avg_sentiment, 1) . '/10)'
            );
        }
        
        // Insight sobre casos críticos
        $critical_count = intval($critical_cases['total_critical'] ?? 0);
        if ($critical_count === 0) {
            $insights[] = array(
                'type' => 'positive',
                'message' => 'No hay casos críticos pendientes - excelente trabajo del equipo'
            );
        } elseif ($critical_count > 10) {
            $insights[] = array(
                'type' => 'warning',
                'message' => "Hay {$critical_count} casos críticos que requieren atención inmediata"
            );
        }
        
        // Insight sobre tendencia
        if (isset($metrics['daily_trend']) && count($metrics['daily_trend']) >= 3) {
            $recent_data = array_slice($metrics['daily_trend'], -3);
            $trend_direction = $this->calculate_trend_direction($recent_data);
            
            if ($trend_direction['direction'] === 'improving') {
                $insights[] = array(
                    'type' => 'positive',
                    'message' => 'Tendencia de satisfacción en mejora continua'
                );
            } elseif ($trend_direction['direction'] === 'declining') {
                $insights[] = array(
                    'type' => 'warning',
                    'message' => 'Tendencia de satisfacción en declive - revisar procesos'
                );
            }
        }
        
        // Insight sobre volumen de análisis
        $total_analyses = intval($metrics['summary']['total_analyses'] ?? 0);
        $avg_daily = $total_analyses / $metrics['period_days'];
        if ($avg_daily > 50) {
            $insights[] = array(
                'type' => 'info',
                'message' => 'Alto volumen de análisis diarios (' . round($avg_daily) . ' promedio)'
            );
        }
        
        return $insights;
    }

    /**
     * Generar recomendaciones del dashboard
     */
    private function generate_dashboard_recommendations($metrics, $critical_cases) {
        $recommendations = array();
        
        // Recomendación por satisfacción baja
        $avg_sentiment = floatval($metrics['summary']['avg_sentiment'] ?? 0);
        if ($avg_sentiment < 6.0) {
            $recommendations[] = array(
                'priority' => 'high',
                'category' => 'Satisfacción',
                'action' => 'Implementar programa de mejora de experiencia del cliente',
                'estimated_impact' => 'Alto'
            );
        }
        
        // Recomendación por casos críticos
        $critical_count = intval($critical_cases['total_critical'] ?? 0);
        if ($critical_count > 10) {
            $recommendations[] = array(
                'priority' => 'critical',
                'category' => 'Operaciones',
                'action' => 'Asignar recursos adicionales para resolver casos críticos',
                'estimated_impact' => 'Inmediato'
            );
        }
        
        // Recomendación por clientes en riesgo
        $clients_at_risk = intval($metrics['summary']['clients_at_risk'] ?? 0);
        if ($clients_at_risk > 5) {
            $recommendations[] = array(
                'priority' => 'high',
                'category' => 'Retención',
                'action' => 'Activar protocolo de retención para clientes en riesgo',
                'estimated_impact' => 'Alto'
            );
        }
        
        // Recomendación por eficiencia
        $total_cost = floatval($metrics['summary']['total_cost'] ?? 0);
        $cost_per_analysis = $total_cost / max(1, intval($metrics['summary']['total_analyses'] ?? 1));
        if ($cost_per_analysis > 0.001) {
            $recommendations[] = array(
                'priority' => 'medium',
                'category' => 'Eficiencia',
                'action' => 'Optimizar plantillas de análisis para reducir costos',
                'estimated_impact' => 'Medio'
            );
        }
        
        return $recommendations;
    }

    /**
     * Calcular dirección de tendencia
     */
    private function calculate_trend_direction($recent_data) {
        if (count($recent_data) < 2) {
            return array('direction' => 'stable', 'change' => 0);
        }
        
        $first_avg = floatval($recent_data[0]['avg_sentiment']);
        $last_avg = floatval(end($recent_data)['avg_sentiment']);
        $change = $last_avg - $first_avg;
        
        $direction = 'stable';
        if ($change > 0.5) {
            $direction = 'improving';
        } elseif ($change < -0.5) {
            $direction = 'declining';
        }
        
        return array(
            'direction' => $direction,
            'change' => round($change, 2)
        );
    }
    //FIN FUNCIONES




    /**
     * Getter con inicialización lazy para Claude API
     */
    public function get_claude_api() {
        if (!$this->claude_api && class_exists('GuruX_Claude_API')) {
            $this->claude_api = GuruX_Claude_API::get_instance();
        }
        return $this->claude_api;
    }

    /**
     * Getter con inicialización lazy para Chat Analyzer
     */
    public function get_chat_analyzer() {
        if (!$this->chat_analyzer && class_exists('GuruX_Chat_Analyzer')) {
            $this->chat_analyzer = GuruX_Chat_Analyzer::get_instance();
        }
        return $this->chat_analyzer;
    }

    /**
     * Getter con inicialización lazy para Deals Manager
     */
    public function get_deals_manager() {
        if (!$this->deals_manager && class_exists('GuruX_Deals_Manager')) {
            $this->deals_manager = GuruX_Deals_Manager::get_instance();
        }
        return $this->deals_manager;
    }

    /**
     * Getter con inicialización lazy para Client Manager
     */
    public function get_client_manager() {
        if (!$this->client_manager && class_exists('GuruX_Client_Manager')) {
            $this->client_manager = GuruX_Client_Manager::get_instance();
        }
        return $this->client_manager;
    }















    
    
    /**
     * Configurar hooks adicionales
     */
    private function setup_additional_hooks() {
        // Webhook para conversaciones externas
        add_action('wp_ajax_nopriv_' . $this->config['webhook_endpoint'], array($this, 'webhook_conversation_receiver'));
        add_action('wp_ajax_' . $this->config['webhook_endpoint'], array($this, 'webhook_conversation_receiver'));
        
        // REST API endpoints
        add_action('rest_api_init', array($this, 'register_rest_endpoints'));
        
        // Limpieza en shutdown
        add_action('shutdown', array($this, 'on_shutdown'));
    }
    
    /**
     * Registrar APIs públicas
     */
    private function register_public_apis() {
        // Esta funcionalidad se puede expandir con APIs públicas
    }
    
    /**
     * Configurar cron jobs
     */
    private function setup_cron_jobs() {
        // Los cron jobs se configuran en la activación
    }
    
    /**
     * Verificar si el sistema está listo
     */
    
    private function is_system_ready() {
        // Verificar que el plugin esté cargado
        if (!$this->plugin_loaded) {
            return false;
        }
        
        // Verificar APIs críticas usando lazy loading
        $claude_api = $this->get_claude_api();
        $bitrix_api = $this->get_bitrix_api();
        
        return ($claude_api && $claude_api->is_configured()) && 
            ($bitrix_api && $bitrix_api->is_configured());
    }



    
    
    /**
     * Verificar límites de uso
     */
    private function check_usage_limits() {
        $daily_limits = gurux_btx_ia_check_daily_limits();
        
        if ($daily_limits['exceeded']) {
            return array(
                'allowed' => false,
                'message' => 'Límite diario de análisis alcanzado (' . $daily_limits['limit'] . ')'
            );
        }
        
        return array('allowed' => true);
    }
    
    /**
     * Actualizar métricas de uso
     */
    private function update_usage_metrics($analysis_result) {
        if ($analysis_result['success']) {
            // Incrementar contador de análisis exitosos
            $current_count = get_option('gurux_btx_ia_daily_analyses_count', 0);
            update_option('gurux_btx_ia_daily_analyses_count', $current_count + 1);
            
            // Actualizar costo total
            $current_cost = get_option('gurux_btx_ia_daily_cost', 0.0);
            $new_cost = $current_cost + ($analysis_result['metadata']['ai_cost'] ?? 0);
            update_option('gurux_btx_ia_daily_cost', $new_cost);
        }
    }
    
    /**
     * Crear tablas de base de datos
     */
    private function create_database_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Tabla de conversaciones
        $table_conversations = $wpdb->prefix . 'gurux_btx_ia_conversations';
        $sql_conversations = "CREATE TABLE $table_conversations (
            id int(11) NOT NULL AUTO_INCREMENT,
            client_phone varchar(20) NOT NULL,
            client_name varchar(255) DEFAULT '',
            client_email varchar(255) DEFAULT '',
            bitrix_contact_id int(11) DEFAULT NULL,
            conversation_data longtext,
            last_message text,
            last_message_date datetime DEFAULT CURRENT_TIMESTAMP,
            status enum('pending','analyzing','analyzed','error') DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY client_phone (client_phone),
            KEY bitrix_contact_id (bitrix_contact_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // Tabla de análisis
        $table_analysis = $wpdb->prefix . 'gurux_btx_ia_analysis';
        $sql_analysis = "CREATE TABLE $table_analysis (
            id int(11) NOT NULL AUTO_INCREMENT,
            conversation_id int(11) NOT NULL,
            claude_response longtext,
            sentiment_score decimal(4,2) DEFAULT 0.00,
            urgency_level enum('low','medium','high','critical') DEFAULT 'medium',
            category varchar(100) DEFAULT '',
            client_satisfaction enum('risk','help','neutral','satisfied') DEFAULT 'neutral',
            opportunities text,
            recommendations text,
            next_action text,
            keywords text,
            analyzed_at datetime DEFAULT CURRENT_TIMESTAMP,
            analysis_cost decimal(10,6) DEFAULT 0.000000,
            template_used varchar(50) DEFAULT 'default',
            PRIMARY KEY (id),
            KEY conversation_id (conversation_id),
            KEY sentiment_score (sentiment_score),
            KEY urgency_level (urgency_level),
            KEY client_satisfaction (client_satisfaction),
            KEY analyzed_at (analyzed_at),
            FOREIGN KEY (conversation_id) REFERENCES $table_conversations(id) ON DELETE CASCADE
        ) $charset_collate;";
        
        // Tabla de clientes
        $table_clients = $wpdb->prefix . 'gurux_btx_ia_clients';
        $sql_clients = "CREATE TABLE $table_clients (
            id int(11) NOT NULL AUTO_INCREMENT,
            phone varchar(20) NOT NULL UNIQUE,
            name varchar(255) NOT NULL,
            email varchar(255) DEFAULT '',
            bitrix_contact_id int(11) DEFAULT NULL,
            source varchar(50) DEFAULT 'unknown',
            client_segment varchar(50) DEFAULT 'standard',
            total_interactions int(11) DEFAULT 0,
            satisfaction_average decimal(4,2) DEFAULT 0.00,
            last_sentiment_score decimal(4,2) DEFAULT 0.00,
            last_interaction_date datetime DEFAULT NULL,
            satisfaction_history longtext,
            risk_level enum('low','medium','high') DEFAULT 'low',
            lifetime_value_estimated decimal(10,2) DEFAULT 0.00,
            notes text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            last_sync_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY phone (phone),
            KEY bitrix_contact_id (bitrix_contact_id),
            KEY client_segment (client_segment),
            KEY satisfaction_average (satisfaction_average),
            KEY risk_level (risk_level),
            KEY last_interaction_date (last_interaction_date)
        ) $charset_collate;";
        
        // Tabla de deals
        $table_deals = $wpdb->prefix . 'gurux_btx_ia_deals';
        $sql_deals = "CREATE TABLE $table_deals (
            id int(11) NOT NULL AUTO_INCREMENT,
            bitrix_deal_id int(11) NOT NULL,
            bitrix_contact_id int(11) NOT NULL,
            client_phone varchar(20) NOT NULL,
            client_name varchar(255) NOT NULL,
            sentiment_score decimal(4,2) DEFAULT 0.00,
            urgency_level varchar(20) DEFAULT 'medium',
            client_satisfaction varchar(20) DEFAULT 'neutral',
            category varchar(100) DEFAULT '',
            estimated_value decimal(10,2) DEFAULT 0.00,
            current_stage varchar(50) DEFAULT 'NEW',
            current_value decimal(10,2) DEFAULT 0.00,
            responsible_id int(11) DEFAULT NULL,
            auto_created tinyint(1) DEFAULT 0,
            sync_status enum('pending','synced','error') DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            last_sync_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY bitrix_deal_id (bitrix_deal_id),
            KEY client_phone (client_phone),
            KEY auto_created (auto_created),
            KEY sync_status (sync_status),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // Tabla de configuraciones
        $table_settings = $wpdb->prefix . 'gurux_btx_ia_settings';
        $sql_settings = "CREATE TABLE $table_settings (
            id int(11) NOT NULL AUTO_INCREMENT,
            setting_key varchar(100) NOT NULL UNIQUE,
            setting_value longtext,
            setting_type varchar(20) DEFAULT 'string',
            is_encrypted tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY setting_key (setting_key)
        ) $charset_collate;";
        
        // Tabla de logs estructurados
        $table_logs = $wpdb->prefix . 'gurux_btx_ia_logs';
        $sql_logs = "CREATE TABLE $table_logs (
            id int(11) NOT NULL AUTO_INCREMENT,
            level enum('debug','info','warning','error') DEFAULT 'info',
            message text NOT NULL,
            context longtext,
            user_id int(11) DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            user_agent text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY level (level),
            KEY created_at (created_at),
            KEY user_id (user_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        dbDelta($sql_conversations);
        dbDelta($sql_analysis);
        dbDelta($sql_clients);
        dbDelta($sql_deals);
        dbDelta($sql_settings);
        dbDelta($sql_logs);
        
        $this->get_logger()->info('Tablas de base de datos creadas/actualizadas');
    }
    
    /**
     * Configurar directorio de logs
     */
    private function setup_logs_directory() {
        $logs_dir = GURUX_BTX_IA_LOGS_DIR;
        
        if (!file_exists($logs_dir)) {
            wp_mkdir_p($logs_dir);
        }
        
        // Crear .htaccess para proteger logs
        $htaccess_content = "Order deny,allow\nDeny from all\n";
        file_put_contents($logs_dir . '.htaccess', $htaccess_content);
        
        // Crear index.php vacío
        file_put_contents($logs_dir . 'index.php', '<?php // Silence is golden');
    }
    
    /**
     * Configuración inicial del plugin
     */
    private function setup_initial_configuration() {
        $default_settings = array(
            'claude_api_key' => '',
            'daily_analysis_limit' => GURUX_BTX_IA_DAILY_ANALYSIS_LIMIT,
            'auto_create_deals' => true,
            'auto_create_contacts' => true,
            'sentiment_threshold' => 3.0,
            'urgency_notifications' => true,
            'default_pipeline' => '',
            'default_country_code' => '+506',
            'notification_emails' => array(),
            'working_hours_start' => '08:00',
            'working_hours_end' => '18:00',
            'timezone' => 'America/Costa_Rica'
        );
        
        add_option('gurux_btx_ia_settings', $default_settings);
    }
    
    /**
     * Programar cron jobs
     */
    private function schedule_cron_jobs() {
        // Limpieza diaria
        if (!wp_next_scheduled('gurux_btx_ia_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'gurux_btx_ia_daily_cleanup');
        }
        
        // Procesamiento de cola cada 5 minutos
        if (!wp_next_scheduled('gurux_btx_ia_process_queue')) {
            wp_schedule_event(time(), 'hourly', 'gurux_btx_ia_process_queue');
        }
        
        // Sincronización de clientes cada 6 horas
        if (!wp_next_scheduled('gurux_btx_ia_sync_clients')) {
            wp_schedule_event(time(), 'twicedaily', 'gurux_btx_ia_sync_clients');
        }
    }
    
    /**
     * Migrar desde plugins hermanos
     */
    private function migrate_from_sibling_plugins() {
        $compatible_plugins = gurux_btx_ia_check_plugin_compatibility();
        
        if (!empty($compatible_plugins)) {
            gurux_btx_ia_migrate_settings_from_siblings();
        }
    }
    
    /**
     * Limpiar cron jobs
     */
    private function clear_cron_jobs() {
        wp_clear_scheduled_hook('gurux_btx_ia_daily_cleanup');
        wp_clear_scheduled_hook('gurux_btx_ia_process_queue');
        wp_clear_scheduled_hook('gurux_btx_ia_sync_clients');
    }
    
    /**
     * Limpiar todos los caches
     */
    private function clear_all_caches() {
        gurux_btx_ia_clear_all_cache();
        
        // Limpiar cache de componentes
        if ($this->bitrix_api) {
            $this->bitrix_api->clear_response_cache();
        }
    }
    
    /**
     * Obtener logger (lazy loading)
     */
    private function get_logger() {
        if (!$this->logger) {
            $this->logger = GuruX_Logger::get_instance();
        }
        return $this->logger;
    }
    
    /**
     * Test de conexión a base de datos
     */
    private function test_database_connection() {
        global $wpdb;
        
        try {
            $result = $wpdb->get_var("SELECT 1");
            
            if ($result === '1') {
                return array(
                    'success' => true,
                    'message' => 'Conexión a base de datos exitosa'
                );
            } else {
                return array(
                    'success' => false,
                    'message' => 'Respuesta inesperada de base de datos'
                );
            }
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Error de conexión: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * Verificar autenticación de webhook
     */
    private function verify_webhook_auth() {
        if (!$this->config['webhook_auth_required']) {
            return true;
        }
        
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $expected_token = gurux_btx_ia_get_setting('webhook_auth_token', '');
        
        if (empty($expected_token)) {
            return true; // Si no hay token configurado, permitir acceso
        }
        
        return $auth_header === "Bearer {$expected_token}";
    }
    
    /**
     * Validar datos de webhook
     */
    private function validate_webhook_data($data) {
        if (!isset($data['conversation']) || empty($data['conversation'])) {
            return array(
                'valid' => false,
                'message' => 'Campo conversation requerido'
            );
        }
        
        return array('valid' => true);
    }
    
    /**
     * Obtener métricas del dashboard
     */

    public function get_dashboard_metrics($days = 7) {
        global $wpdb;
        
        $date_from = date('Y-m-d', strtotime("-{$days} days"));
        
        // Métricas básicas
        $basic_stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(DISTINCT a.id) as total_analyses,
                AVG(a.sentiment_score) as avg_sentiment,
                COUNT(DISTINCT c.client_phone) as unique_clients,
                COUNT(CASE WHEN a.urgency_level = 'critical' THEN 1 END) as critical_cases,
                COUNT(CASE WHEN a.client_satisfaction = 'risk' THEN 1 END) as clients_at_risk,
                COUNT(CASE WHEN a.client_satisfaction = 'satisfied' THEN 1 END) as satisfied_clients,
                SUM(a.analysis_cost) as total_cost
            FROM {$wpdb->prefix}gurux_btx_ia_analysis a
            INNER JOIN {$wpdb->prefix}gurux_btx_ia_conversations c ON a.conversation_id = c.id
            WHERE DATE(a.analyzed_at) >= %s",
            $date_from
        ), ARRAY_A);

        // Distribución por urgencia
        $urgency_distribution = $wpdb->get_results($wpdb->prepare(
            "SELECT urgency_level, COUNT(*) as count
            FROM {$wpdb->prefix}gurux_btx_ia_analysis 
            WHERE DATE(analyzed_at) >= %s
            GROUP BY urgency_level",
            $date_from
        ), ARRAY_A);

        // Tendencia diaria
        $daily_trend = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                DATE(analyzed_at) as date,
                COUNT(*) as analyses,
                AVG(sentiment_score) as avg_sentiment
            FROM {$wpdb->prefix}gurux_btx_ia_analysis 
            WHERE DATE(analyzed_at) >= %s
            GROUP BY DATE(analyzed_at)
            ORDER BY date ASC",
            $date_from
        ), ARRAY_A);

        // Categorías más frecuentes
        $top_categories = $wpdb->get_results($wpdb->prepare(
            "SELECT category, COUNT(*) as count
            FROM {$wpdb->prefix}gurux_btx_ia_analysis 
            WHERE DATE(analyzed_at) >= %s AND category != ''
            GROUP BY category
            ORDER BY count DESC
            LIMIT 5",
            $date_from
        ), ARRAY_A);

        return array(
            'period_days' => $days,
            'summary' => $basic_stats,
            'urgency_distribution' => $urgency_distribution,
            'daily_trend' => $daily_trend,
            'top_categories' => $top_categories,
            'last_updated' => current_time('mysql')
        );
    }

    
    
    /**
     * Obtener análisis recientes
     */
    private function get_recent_analyses($limit = 10) {
        global $wpdb;
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, c.client_phone, c.client_name 
            FROM {$wpdb->prefix}gurux_btx_ia_analysis a
            INNER JOIN {$wpdb->prefix}gurux_btx_ia_conversations c ON a.conversation_id = c.id
            ORDER BY a.analyzed_at DESC
            LIMIT %d",
            $limit
        ), ARRAY_A);
        
        return $results ?: array();
    }
    
    /**
     * Obtener alertas del sistema
     */
    private function get_system_alerts() {
        $alerts = array();
        
        // Verificar límites de uso
        $usage_limits = gurux_btx_ia_check_daily_limits();
        if ($usage_limits['percentage'] > 80) {
            $alerts[] = array(
                'type' => 'usage_warning',
                'message' => "Uso diario al {$usage_limits['percentage']}%"
            );
        }
        
        return $alerts;
    }
    
    /**
     * Obtener estadísticas de uso
     */
    private function get_usage_statistics() {
        if ($this->claude_api) {
            return $this->claude_api->get_daily_usage();
        }
        
        return array();
    }
    
    /**
     * Obtener tipo de configuración
     */
    private function get_setting_type($setting_key) {
        $types = array(
            'claude_api_key' => 'text',
            'daily_analysis_limit' => 'int',
            'auto_create_deals' => 'bool',
            'sentiment_threshold' => 'number'
        );
        
        return $types[$setting_key] ?? 'text';
    }
    
    /**
     * Tarea de limpieza diaria
     */
    public function daily_cleanup_task() {
        // Limpiar logs antiguos
        if ($this->logger) {
            $this->logger->cleanup_old_logs();
        }
        
        // Limpiar cache expirado
        gurux_btx_ia_clear_expired_cache();
        
        // Resetear contadores diarios
        delete_option('gurux_btx_ia_daily_analyses_count');
        delete_option('gurux_btx_ia_daily_cost');
        
        $this->get_logger()->info('Limpieza diaria completada');
    }
    
    /**
     * Tarea de sincronización de clientes
     */
    public function sync_clients_task() {
        if ($this->client_manager) {
            // Sincronizar clientes recientes
            $this->client_manager->segment_clients();
        }
    }
    
    /**
     * Registrar endpoints REST API
     */
    public function register_rest_endpoints() {
        register_rest_route('gurux-btx-ia/v1', '/analyze', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_analyze_conversation'),
            'permission_callback' => array($this, 'rest_permission_check')
        ));
    }
    
    /**
     * Callback REST API para análisis
     */
    public function rest_analyze_conversation($request) {
        $conversation = $request->get_param('conversation');
        $client_phone = $request->get_param('client_phone');
        
        $result = $this->process_conversation($conversation, $client_phone);
        
        if ($result['success']) {
            return new WP_REST_Response($result, 200);
        } else {
            return new WP_Error('analysis_failed', $result['message'], array('status' => 400));
        }
    }
    
    /**
     * Verificar permisos REST API
     */
    public function rest_permission_check() {
        return current_user_can('manage_options');
    }
    
    /**
     * Callback en shutdown
     */
    public function on_shutdown() {
        // Limpiar recursos si es necesario
    }

    
    /**
     * ESTO ES LO NUEVO 
     */
    public function run_test($test_type) {
        $this->logger->info('Ejecutando test', array('type' => $test_type));
        
        switch ($test_type) {
            // Tests de Bitrix24
            case 'bitrix_connection':
                return $this->test_bitrix_connection();
                
            case 'bitrix_permissions':
                return $this->test_bitrix_permissions();
                
            case 'bitrix_create_contact':
                return $this->test_bitrix_create_contact();
                
            case 'bitrix_create_deal':
                return $this->test_bitrix_create_deal();
                
            case 'bitrix_list_pipelines':
                return $this->test_bitrix_list_pipelines();
                
            // Tests de Claude AI
            case 'claude_connection':
                return $this->test_claude_connection();
                
            case 'claude_spanish':
                return $this->test_claude_spanish();
                
            case 'claude_json_response':
                return $this->test_claude_json_response();
                
            case 'claude_cost_estimate':
                return $this->test_claude_cost_estimate();
                
            // Tests de Workflow
            case 'workflow_positive':
                return $this->test_workflow('positive');
                
            case 'workflow_negative':
                return $this->test_workflow('negative');
                
            case 'workflow_opportunity':
                return $this->test_workflow('opportunity');
                
            case 'workflow_complete':
                return $this->test_workflow_complete();
                
            // Utilidades
            case 'cleanup_test_data':
                return $this->cleanup_test_data();
                
            case 'test_dashboard':
                return $this->test_dashboard_functionality();
                
            case 'create_sample_data':
                return $this->create_sample_dashboard_data();

            default:
                return array(
                    'success' => false,
                    'message' => 'Tipo de test no válido'
                );
        }
    }

    /**
     * Test conexión Bitrix24
     */
    private function test_bitrix_connection() {
        $result = $this->bitrix_api->test_connection();
        
        return array(
            'success' => $result['success'],
            'message' => $result['message'],
            'details' => $result
        );
    }

    /**
     * Test permisos Bitrix24
     */
    //NUEVAS FUNCIONALIDADES


    private function get_test_endpoints() {
        return array(
            'app.info' => 'Información de la aplicación',
            'crm.contact.list' => 'Listar contactos',
            'crm.contact.add' => 'Crear contactos',
            'crm.deal.list' => 'Listar deals',
            'crm.deal.add' => 'Crear deals',
            'tasks.task.add' => 'Crear tareas',
            'user.get' => 'Obtener usuarios',
            'crm.status.list' => 'Obtener etapas'
        );
    }

    /**
     * Crear tarea de prueba - Función separada
     */
    private function create_test_task() {
        return $this->bitrix_api->api_call('tasks.task.add', array(
            'fields' => array(
                'TITLE' => 'Test Task - ' . date('Y-m-d H:i:s'),
                'DESCRIPTION' => 'Tarea de prueba creada por GuruX IA',
                'RESPONSIBLE_ID' => 1
            )
        ));
    }

    /**
     * Test de permisos Bitrix24 - VERSIÓN CORREGIDA
     */
    private function test_bitrix_permissions() {
        // Obtener endpoints limpios
        $endpoints = $this->get_test_endpoints();
        
        $results = array();
        $all_success = true;
        
        foreach ($endpoints as $endpoint => $description) {
            try {
                $test_params = array();
                
                // Configurar parámetros específicos para cada endpoint
                switch ($endpoint) {
                    case 'crm.contact.list':
                    case 'crm.deal.list':
                        $test_params = array('select' => array('ID'), 'start' => 0);
                        break;
                        
                    case 'user.get':
                        $test_params = array('ID' => 1);
                        break;
                        
                    case 'crm.status.list':
                        $test_params = array('entity_id' => 'DEAL_STAGE');
                        break;
                        
                    case 'tasks.task.add':
                        // Para tasks.task.add, usar la función separada
                        $response = $this->create_test_task();
                        break;
                        
                    case 'crm.contact.add':
                        // Test de crear contacto (sin crear realmente)
                        $test_params = array(
                            'fields' => array(
                                'NAME' => 'Test Permission Check',
                                'COMMENTS' => 'Test de permisos - NO CREAR'
                            )
                        );
                        // Solo verificar sin crear realmente
                        $response = $this->bitrix_api->api_call('crm.contact.fields');
                        break;
                        
                    case 'crm.deal.add':
                        // Test de crear deal (sin crear realmente)
                        $response = $this->bitrix_api->api_call('crm.deal.fields');
                        break;
                        
                    default:
                        // Para otros endpoints, usar llamada normal
                        $response = null;
                        break;
                }
                
                // Si no se hizo llamada especial, hacer la llamada normal
                if (!isset($response)) {
                    $response = $this->bitrix_api->api_call($endpoint, $test_params);
                }
                
                // Evaluar resultado
                if ($response && !isset($response['error'])) {
                    $results[$description] = array(
                        'status' => 'success', 
                        'message' => 'OK',
                        'endpoint' => $endpoint
                    );
                } else {
                    $error_message = 'Sin permisos';
                    if (isset($response['error_description'])) {
                        $error_message = $response['error_description'];
                    } elseif (isset($response['error'])) {
                        $error_message = $response['error'];
                    }
                    
                    $results[$description] = array(
                        'status' => 'error', 
                        'message' => $error_message,
                        'endpoint' => $endpoint
                    );
                    $all_success = false;
                }
                
            } catch (Exception $e) {
                $results[$description] = array(
                    'status' => 'error', 
                    'message' => 'Excepción: ' . $e->getMessage(),
                    'endpoint' => $endpoint
                );
                $all_success = false;
            }
        }
        
        return array(
            'success' => $all_success,
            'message' => $all_success ? 'Todos los permisos verificados' : 'Algunos permisos faltan',
            'details' => $results,
            'summary' => array(
                'total_endpoints' => count($endpoints),
                'successful' => count(array_filter($results, function($r) { return $r['status'] === 'success'; })),
                'failed' => count(array_filter($results, function($r) { return $r['status'] === 'error'; }))
            )
        );
    }





    /**
     * Test crear contacto Bitrix24
     */
    
    public function test_bitrix_create_contact() {
        $api = gurux_btx_ia_api();
        if (!$api) {
            return array(
                'success' => false,
                'message' => 'API no disponible'
            );
        }
        
        $test_contact = array(
            'NAME' => 'Test Contact GuruX',
            'PHONE' => array(array('VALUE' => '+50612345678', 'VALUE_TYPE' => 'WORK')),
            'EMAIL' => array(array('VALUE' => 'test@gurux.com', 'VALUE_TYPE' => 'WORK')),
            'COMMENTS' => 'Contacto de prueba creado por GuruX IA - ' . date('Y-m-d H:i:s')
        );
        
        try {
            $response = $api->api_call('crm.contact.add', array('fields' => $test_contact));
            
            if ($response && isset($response['result'])) {
                $contact_id = $response['result'];
                
                // Limpiar el contacto de prueba
                $api->api_call('crm.contact.delete', array('id' => $contact_id));
                
                return array(
                    'success' => true,
                    'message' => "Contacto creado y eliminado exitosamente (ID: {$contact_id})",
                    'contact_id' => $contact_id
                );
            } elseif (isset($response['error'])) {
                return array(
                    'success' => false,
                    'message' => 'Error de Bitrix: ' . ($response['error_description'] ?? $response['error']),
                    'error_details' => $response
                );
            } else {
                return array(
                    'success' => false,
                    'message' => 'Respuesta inesperada de Bitrix24',
                    'response' => $response
                );
            }
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Excepción: ' . $e->getMessage()
            );
        }
    }

    

    /**
     * Test conexión Claude AI
     */
    private function test_claude_connection() {
        $result = $this->claude_api->test_connection();
        
        return array(
            'success' => $result['success'],
            'message' => $result['message'],
            'details' => $result
        );
    }

    /**
     * Test análisis en español
     */
    private function test_claude_spanish() {
        $test_conversation = "Cliente: Estoy muy satisfecho con el servicio, excelente atención.\nAgente: Muchas gracias por sus comentarios.\nCliente: Definitivamente los recomendaré.";
        
        $result = $this->claude_api->analyze_conversation($test_conversation);
        
        if ($result['success']) {
            return array(
                'success' => true,
                'message' => 'Análisis en español completado',
                'details' => array(
                    'sentiment' => $result['analysis']['sentiment_score'],
                    'urgency' => $result['analysis']['urgency_level'],
                    'satisfaction' => $result['analysis']['client_satisfaction'],
                    'cost' => '$' . number_format($result['metadata']['cost'], 4)
                )
            );
        }
        
        return $result;
    }

    /**
     * Limpiar datos de prueba CAMBIOS REALIZADOS
     */
    
    private function cleanup_test_data() {
        $cleaned = array();
        $errors = array();
        
        $test_contact_id = get_transient('gurux_test_contact_id');
        if ($test_contact_id) {
            $delete_result = $this->bitrix_api->api_call('crm.contact.delete', array('id' => $test_contact_id));
            if ($delete_result && !isset($delete_result['error'])) {
                $cleaned[] = "Contacto de prueba #{$test_contact_id} eliminado";
                delete_transient('gurux_test_contact_id');
            } else {
                $errors[] = "Error eliminando contacto #{$test_contact_id}: " . ($delete_result['error_description'] ?? 'Error desconocido');
            }
        }
        
        $test_deal_id = get_transient('gurux_test_deal_id');
        if ($test_deal_id) {
            $delete_result = $this->bitrix_api->api_call('crm.deal.delete', array('id' => $test_deal_id));
            if ($delete_result && !isset($delete_result['error'])) {
                $cleaned[] = "Deal de prueba #{$test_deal_id} eliminado";
                delete_transient('gurux_test_deal_id');
            } else {
                $errors[] = "Error eliminando deal #{$test_deal_id}: " . ($delete_result['error_description'] ?? 'Error desconocido');
            }
        }
        
        $workflow_contact_id = get_transient('gurux_test_workflow_contact_id');
        if ($workflow_contact_id) {
            $delete_result = $this->bitrix_api->api_call('crm.contact.delete', array('id' => $workflow_contact_id));
            if ($delete_result && !isset($delete_result['error'])) {
                $cleaned[] = "Contacto workflow #{$workflow_contact_id} eliminado";
                delete_transient('gurux_test_workflow_contact_id');
            } else {
                $errors[] = "Error eliminando contacto workflow #{$workflow_contact_id}: " . ($delete_result['error_description'] ?? 'Error desconocido');
            }
        }
        
        $workflow_deal_id = get_transient('gurux_test_workflow_deal_id');
        if ($workflow_deal_id) {
            $delete_result = $this->bitrix_api->api_call('crm.deal.delete', array('id' => $workflow_deal_id));
            if ($delete_result && !isset($delete_result['error'])) {
                $cleaned[] = "Deal workflow #{$workflow_deal_id} eliminado";
                delete_transient('gurux_test_workflow_deal_id');
            } else {
                $errors[] = "Error eliminando deal workflow #{$workflow_deal_id}: " . ($delete_result['error_description'] ?? 'Error desconocido');
            }
        }
        
        $test_task_id = get_transient('gurux_test_task_id');
        if ($test_task_id) {
            $delete_result = $this->bitrix_api->api_call('tasks.task.delete', array('taskId' => $test_task_id));
            if ($delete_result && !isset($delete_result['error'])) {
                $cleaned[] = "Tarea de prueba #{$test_task_id} eliminada";
                delete_transient('gurux_test_task_id');
            } else {
                $errors[] = "Error eliminando tarea #{$test_task_id}: " . ($delete_result['error_description'] ?? 'Error desconocido');
            }
        }
        
        global $wpdb;
        $test_conversations = $wpdb->get_results(
            "SELECT id FROM {$wpdb->prefix}gurux_btx_ia_conversations 
            WHERE client_name LIKE '%Test%' OR client_name LIKE '%Workflow%' OR client_name LIKE '%Prueba%'",
            ARRAY_A
        );
        
        foreach ($test_conversations as $conversation) {
            $wpdb->delete(
                $wpdb->prefix . 'gurux_btx_ia_conversations',
                array('id' => $conversation['id']),
                array('%d')
            );
            $cleaned[] = "Conversación de prueba #{$conversation['id']} eliminada de BD local";
        }
        
        $test_analyses = $wpdb->get_results(
            "SELECT a.id FROM {$wpdb->prefix}gurux_btx_ia_analysis a 
            INNER JOIN {$wpdb->prefix}gurux_btx_ia_conversations c ON a.conversation_id = c.id 
            WHERE c.client_name LIKE '%Test%' OR c.client_name LIKE '%Workflow%' OR c.client_name LIKE '%Prueba%'",
            ARRAY_A
        );
        
        foreach ($test_analyses as $analysis) {
            $wpdb->delete(
                $wpdb->prefix . 'gurux_btx_ia_analysis',
                array('id' => $analysis['id']),
                array('%d')
            );
            $cleaned[] = "Análisis de prueba #{$analysis['id']} eliminado de BD local";
        }
        
        $test_clients = $wpdb->get_results(
            "SELECT id FROM {$wpdb->prefix}gurux_btx_ia_clients 
            WHERE name LIKE '%Test%' OR name LIKE '%Workflow%' OR name LIKE '%Prueba%' OR phone LIKE '%999%'",
            ARRAY_A
        );
        
        foreach ($test_clients as $client) {
            $wpdb->delete(
                $wpdb->prefix . 'gurux_btx_ia_clients',
                array('id' => $client['id']),
                array('%d')
            );
            $cleaned[] = "Cliente de prueba #{$client['id']} eliminado de BD local";
        }
        
        $test_deals_local = $wpdb->get_results(
            "SELECT id FROM {$wpdb->prefix}gurux_btx_ia_deals 
            WHERE client_name LIKE '%Test%' OR client_name LIKE '%Workflow%' OR client_name LIKE '%Prueba%'",
            ARRAY_A
        );
        
        foreach ($test_deals_local as $deal) {
            $wpdb->delete(
                $wpdb->prefix . 'gurux_btx_ia_deals',
                array('id' => $deal['id']),
                array('%d')
            );
            $cleaned[] = "Deal local de prueba #{$deal['id']} eliminado de BD local";
        }
        
        $wpdb->delete(
            $wpdb->prefix . 'gurux_btx_ia_logs',
            array('message' => 'LIKE', '%Test%'),
            array('%s')
        );
        
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}gurux_btx_ia_logs 
            WHERE message LIKE '%Test%' OR message LIKE '%Workflow%' OR message LIKE '%Prueba%'"
        );
        $cleaned[] = "Logs de prueba eliminados";
        
        $all_transients = array(
            'gurux_test_contact_id',
            'gurux_test_deal_id', 
            'gurux_test_task_id',
            'gurux_test_workflow_contact_id',
            'gurux_test_workflow_deal_id'
        );
        
        foreach ($all_transients as $transient) {
            delete_transient($transient);
        }
        $cleaned[] = "Transients de prueba limpiados";
        
        $total_cleaned = count($cleaned);
        $total_errors = count($errors);
        
        return array(
            'success' => $total_errors === 0,
            'message' => $total_cleaned > 0 ? 
                "Limpieza completada: {$total_cleaned} elementos eliminados" . 
                ($total_errors > 0 ? " con {$total_errors} errores" : "") :
                "No hay datos de prueba para limpiar",
            'details' => array(
                'cleaned' => $cleaned,
                'errors' => $errors,
                'total_cleaned' => $total_cleaned,
                'total_errors' => $total_errors
            )
        );
    }



    //NUEVAS FUNCIONALIDADES

    public function test_bitrix_create_deal() {
        $api = gurux_btx_ia_api();
        if (!$api) {
            return array(
                'success' => false,
                'message' => 'API no disponible'
            );
        }
        
        try {
            // Primero crear un contacto para el deal
            $test_contact = array(
                'NAME' => 'Test Contact for Deal',
                'PHONE' => array(array('VALUE' => '+50612345679', 'VALUE_TYPE' => 'WORK')),
                'COMMENTS' => 'Contacto de prueba para deal - ' . date('Y-m-d H:i:s')
            );
            
            $contact_response = $api->api_call('crm.contact.add', array('fields' => $test_contact));
            
            if (!$contact_response || !isset($contact_response['result'])) {
                return array(
                    'success' => false,
                    'message' => 'No se pudo crear contacto de prueba',
                    'error' => $contact_response['error'] ?? 'Unknown error'
                );
            }
            
            $contact_id = $contact_response['result'];
            
            // Crear el deal
            $test_deal = array(
                'TITLE' => 'Test Deal GuruX IA',
                'CONTACT_ID' => $contact_id,
                'STAGE_ID' => 'NEW',
                'OPPORTUNITY' => 100,
                'CURRENCY_ID' => 'USD',
                'COMMENTS' => 'Deal de prueba creado por GuruX IA - ' . date('Y-m-d H:i:s'),
                'SOURCE_ID' => 'OTHER'
            );
            
            $deal_response = $api->api_call('crm.deal.add', array('fields' => $test_deal));
            
            if ($deal_response && isset($deal_response['result'])) {
                $deal_id = $deal_response['result'];
                
                // Limpiar datos de prueba
                $api->api_call('crm.deal.delete', array('id' => $deal_id));
                $api->api_call('crm.contact.delete', array('id' => $contact_id));
                
                return array(
                    'success' => true,
                    'message' => "Deal creado y eliminado exitosamente (ID: {$deal_id})",
                    'deal_id' => $deal_id,
                    'contact_id' => $contact_id
                );
            } elseif (isset($deal_response['error'])) {
                // Limpiar contacto si falló el deal
                $api->api_call('crm.contact.delete', array('id' => $contact_id));
                
                return array(
                    'success' => false,
                    'message' => 'Error creando deal: ' . ($deal_response['error_description'] ?? $deal_response['error']),
                    'error_details' => $deal_response
                );
            } else {
                // Limpiar contacto si la respuesta fue inesperada
                $api->api_call('crm.contact.delete', array('id' => $contact_id));
                
                return array(
                    'success' => false,
                    'message' => 'Respuesta inesperada al crear deal',
                    'response' => $deal_response
                );
            }
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Excepción: ' . $e->getMessage()
            );
        }
    }

    private function test_bitrix_list_pipelines() {
        $response = $this->bitrix_api->api_call('crm.status.list', array('entity_id' => 'DEAL_STAGE'));
        
        if ($response && isset($response['result'])) {
            $pipelines = array();
            $stages_count = 0;
            
            foreach ($response['result'] as $stage) {
                $pipeline_id = 'C' . ($stage['CATEGORY_ID'] ?? '0');
                
                if (!isset($pipelines[$pipeline_id])) {
                    $pipelines[$pipeline_id] = array(
                        'name' => 'Pipeline ' . ($stage['CATEGORY_ID'] ?? 'Default'),
                        'stages' => array()
                    );
                }
                
                $pipelines[$pipeline_id]['stages'][] = array(
                    'id' => $stage['STATUS_ID'],
                    'name' => $stage['NAME'],
                    'sort' => $stage['SORT'] ?? 0
                );
                
                $stages_count++;
            }
            
            return array(
                'success' => true,
                'message' => "Encontrados " . count($pipelines) . " pipelines con {$stages_count} etapas",
                'details' => array(
                    'pipelines_count' => count($pipelines),
                    'total_stages' => $stages_count,
                    'pipelines' => $pipelines
                )
            );
        }
        
        return array(
            'success' => false,
            'message' => 'Error obteniendo pipelines: ' . ($response['error_description'] ?? 'Error desconocido')
        );
    }

    private function test_claude_json_response() {
        $test_conversation = "Cliente: Estoy muy satisfecho con el servicio, excelente atención.\nAgente: Muchas gracias por sus comentarios positivos.\nCliente: Definitivamente los recomendaré a mis amigos.";
        
        $result = $this->claude_api->analyze_conversation($test_conversation, 'default');
        
        if (!$result['success']) {
            return array(
                'success' => false,
                'message' => 'Error en análisis: ' . $result['message']
            );
        }
        
        $analysis = $result['analysis'];
        $required_fields = array('sentiment_score', 'urgency_level', 'category', 'client_satisfaction', 'opportunities', 'recommendations', 'next_action', 'keywords');
        $missing_fields = array();
        $valid_values = true;
        $validation_errors = array();
        
        foreach ($required_fields as $field) {
            if (!isset($analysis[$field])) {
                $missing_fields[] = $field;
            }
        }
        
        if (isset($analysis['sentiment_score'])) {
            $score = floatval($analysis['sentiment_score']);
            if ($score < 0 || $score > 10) {
                $valid_values = false;
                $validation_errors[] = "sentiment_score fuera de rango: {$score}";
            }
        }
        
        if (isset($analysis['urgency_level'])) {
            $valid_urgency = array('low', 'medium', 'high', 'critical');
            if (!in_array($analysis['urgency_level'], $valid_urgency)) {
                $valid_values = false;
                $validation_errors[] = "urgency_level inválido: {$analysis['urgency_level']}";
            }
        }
        
        if (isset($analysis['client_satisfaction'])) {
            $valid_satisfaction = array('risk', 'help', 'neutral', 'satisfied');
            if (!in_array($analysis['client_satisfaction'], $valid_satisfaction)) {
                $valid_values = false;
                $validation_errors[] = "client_satisfaction inválido: {$analysis['client_satisfaction']}";
            }
        }
        
        if (!empty($missing_fields) || !$valid_values) {
            return array(
                'success' => false,
                'message' => 'Estructura JSON inválida o valores fuera de rango',
                'details' => array(
                    'missing_fields' => $missing_fields,
                    'validation_errors' => $validation_errors,
                    'received_analysis' => $analysis
                )
            );
        }
        
        return array(
            'success' => true,
            'message' => 'Respuesta JSON válida y completa',
            'details' => array(
                'sentiment_score' => $analysis['sentiment_score'],
                'urgency_level' => $analysis['urgency_level'],
                'client_satisfaction' => $analysis['client_satisfaction'],
                'category' => $analysis['category'],
                'execution_time' => $result['metadata']['execution_time'] . 'ms',
                'cost' => '$' . number_format($result['metadata']['cost'], 4)
            )
        );
    }

    private function test_claude_cost_estimate() {
        $test_conversations = array(
            'short' => "Cliente: Hola\nAgente: ¿En qué puedo ayudarle?",
            'medium' => "Cliente: Tengo un problema con mi pedido\nAgente: ¿Cuál es el número de orden?\nCliente: Es el 12345\nAgente: Revisando...\nCliente: Gracias por la ayuda",
            'long' => str_repeat("Cliente: Esta es una conversación muy larga para probar el costo de análisis con mucho texto. ", 20) . "\nAgente: Entendido, procesando toda esta información detalladamente."
        );
        
        $cost_results = array();
        $total_cost = 0;
        $total_time = 0;
        
        foreach ($test_conversations as $type => $conversation) {
            $start_time = microtime(true);
            $result = $this->claude_api->analyze_conversation($conversation, 'default');
            $execution_time = round((microtime(true) - $start_time) * 1000, 2);
            
            if ($result['success']) {
                $cost = $result['metadata']['cost'];
                $total_cost += $cost;
                $total_time += $execution_time;
                
                $cost_results[$type] = array(
                    'length' => strlen($conversation),
                    'cost' => $cost,
                    'execution_time' => $execution_time,
                    'cost_per_char' => $cost / strlen($conversation)
                );
            } else {
                $cost_results[$type] = array('error' => $result['message']);
            }
        }
        
        $daily_limit = gurux_btx_ia_get_setting('daily_analysis_limit', 1000);
        $estimated_daily_cost = $total_cost / 3 * $daily_limit;
        $estimated_monthly_cost = $estimated_daily_cost * 30;
        
        return array(
            'success' => true,
            'message' => 'Análisis de costos completado',
            'details' => array(
                'individual_tests' => $cost_results,
                'average_cost' => round($total_cost / 3, 6),
                'average_time' => round($total_time / 3, 2),
                'estimated_daily_cost' => round($estimated_daily_cost, 2),
                'estimated_monthly_cost' => round($estimated_monthly_cost, 2),
                'daily_limit' => $daily_limit
            )
        );
    }

    private function test_workflow_complete() {
        $workflow_steps = array();
        $test_conversation = "Cliente: Estoy interesado en sus servicios premium\nAgente: Perfecto, ¿qué información necesita?\nCliente: Precios y disponibilidad\nAgente: Le envío la información\nCliente: Excelente, me interesa contratar";
        
        $workflow_steps['step_1'] = 'Iniciando análisis de conversación completa';
        
        $analysis_result = $this->claude_api->analyze_conversation($test_conversation, 'default');
        if (!$analysis_result['success']) {
            return array(
                'success' => false,
                'message' => 'Fallo en Step 1 - Análisis IA: ' . $analysis_result['message'],
                'failed_step' => 'claude_analysis'
            );
        }
        $workflow_steps['step_2'] = 'Análisis IA completado exitosamente';
        
        $test_contact_data = array(
            'NAME' => 'Cliente Test Workflow',
            'PHONE' => array(array('VALUE' => '+50699999999', 'VALUE_TYPE' => 'WORK')),
            'EMAIL' => array(array('VALUE' => 'workflow@test.gurux', 'VALUE_TYPE' => 'WORK')),
            'COMMENTS' => 'Contacto de prueba workflow completo - ELIMINAR'
        );
        
        $contact_response = $this->bitrix_api->api_call('crm.contact.add', array('fields' => $test_contact_data));
        if (!$contact_response || isset($contact_response['error'])) {
            return array(
                'success' => false,
                'message' => 'Fallo en Step 2 - Crear contacto: ' . ($contact_response['error_description'] ?? 'Error desconocido'),
                'failed_step' => 'create_contact'
            );
        }
        $contact_id = $contact_response['result'];
        $workflow_steps['step_3'] = 'Contacto creado: ID ' . $contact_id;
        
        $deal_data = array(
            'TITLE' => 'Deal Workflow Test - ELIMINAR',
            'CONTACT_ID' => $contact_id,
            'STAGE_ID' => 'NEW',
            'OPPORTUNITY' => 500,
            'CURRENCY_ID' => 'USD',
            'OPENED' => 'Y',
            'COMMENTS' => 'Deal de workflow test - Sentimiento: ' . $analysis_result['analysis']['sentiment_score'] . '/10'
        );
        
        $deal_response = $this->bitrix_api->api_call('crm.deal.add', array('fields' => $deal_data));
        if (!$deal_response || isset($deal_response['error'])) {
            return array(
                'success' => false,
                'message' => 'Fallo en Step 3 - Crear deal: ' . ($deal_response['error_description'] ?? 'Error desconocido'),
                'failed_step' => 'create_deal'
            );
        }
        $deal_id = $deal_response['result'];
        $workflow_steps['step_4'] = 'Deal creado: ID ' . $deal_id;
        
        $task_data = array(
            'TITLE' => 'Seguimiento Workflow Test - ELIMINAR',
            'DESCRIPTION' => 'Tarea generada por workflow test\nSentimiento: ' . $analysis_result['analysis']['sentiment_score'] . '/10\nUrgencia: ' . $analysis_result['analysis']['urgency_level'],
            'RESPONSIBLE_ID' => 1,
            'DEADLINE' => date('Y-m-d H:i:s', strtotime('+1 day')),
            'UF_CRM_TASK' => array("D_{$deal_id}")
        );
        
        $task_response = $this->bitrix_api->api_call('tasks.task.add', array('fields' => $task_data));
        if (!$task_response || isset($task_response['error'])) {
            $workflow_steps['step_5'] = 'Advertencia: Tarea no creada - ' . ($task_response['error_description'] ?? 'Permisos de tareas requeridos');
        } else {
            $task_id = $task_response['result']['task']['id'];
            $workflow_steps['step_5'] = 'Tarea creada: ID ' . $task_id;
            set_transient('gurux_test_task_id', $task_id, 3600);
        }
        
        set_transient('gurux_test_workflow_contact_id', $contact_id, 3600);
        set_transient('gurux_test_workflow_deal_id', $deal_id, 3600);
        
        return array(
            'success' => true,
            'message' => 'Workflow completo ejecutado exitosamente',
            'details' => array(
                'steps_completed' => $workflow_steps,
                'analysis_result' => array(
                    'sentiment' => $analysis_result['analysis']['sentiment_score'],
                    'urgency' => $analysis_result['analysis']['urgency_level'],
                    'satisfaction' => $analysis_result['analysis']['client_satisfaction']
                ),
                'created_objects' => array(
                    'contact_id' => $contact_id,
                    'deal_id' => $deal_id,
                    'task_id' => isset($task_id) ? $task_id : 'No creada'
                ),
                'execution_time' => $analysis_result['metadata']['execution_time'] . 'ms',
                'ai_cost' => '$' . number_format($analysis_result['metadata']['cost'], 4)
            )
        );
    }

    /**
     * Test completo del dashboard - Fase 2 MEJORADO
     */
    public function test_dashboard_functionality() {
        $results = array(
            'success' => true,
            'tests_completed' => array(),
            'errors' => array(),
            'warnings' => array(),
            'summary' => array()
        );
        
        try {
            // Test 1: Métricas del dashboard
            $this->logger->info('Testing dashboard metrics...');
            $metrics = $this->get_dashboard_metrics(7);
            
            if (!empty($metrics) && isset($metrics['summary'])) {
                $results['tests_completed'][] = 'Dashboard metrics loaded successfully';
                $total_analyses = intval($metrics['summary']['total_analyses'] ?? 0);
                
                if ($total_analyses === 0) {
                    $results['warnings'][] = 'No analysis data found - dashboard will show empty state';
                }
            } else {
                $results['errors'][] = 'Dashboard metrics returned invalid structure';
                $results['success'] = false;
            }
            
            // Test 2: Casos críticos
            $this->logger->info('Testing critical cases...');
            $critical_cases = $this->get_critical_cases_pending();
            
            if (isset($critical_cases['total_critical'])) {
                $critical_count = intval($critical_cases['total_critical']);
                $results['tests_completed'][] = "Critical cases check: {$critical_count} found";
                
                if ($critical_count === 0) {
                    $results['warnings'][] = 'No critical cases found - this is actually good!';
                }
            } else {
                $results['errors'][] = 'Critical cases check failed - invalid structure';
                $results['success'] = false;
            }
            
            // Test 3: Rendimiento de agentes
            $this->logger->info('Testing agent performance...');
            $agent_performance = $this->get_agent_performance_metrics(7);
            
            if (isset($agent_performance['total_agents'])) {
                $agent_count = intval($agent_performance['total_agents']);
                $results['tests_completed'][] = "Agent performance: {$agent_count} agents analyzed";
                
                if ($agent_count === 0) {
                    $results['warnings'][] = 'No agents data found - create some deals to populate agent metrics';
                }
            } else {
                $results['errors'][] = 'Agent performance analysis failed - invalid structure';
                $results['success'] = false;
            }
            
            // Test 4: Tendencias de satisfacción (MEJORADO)
            $this->logger->info('Testing satisfaction trends...');
            $trends = $this->get_satisfaction_trends(30);
            
            if (is_array($trends) && isset($trends['trend_analysis'])) {
                $trend_status = $trends['trend_analysis']['trend'] ?? 'unknown';
                $days_analyzed = count($trends['daily_trends'] ?? array());
                
                if ($trend_status === 'error') {
                    $error_msg = $trends['trend_analysis']['error_message'] ?? 'Unknown error';
                    $results['errors'][] = "Satisfaction trends error: {$error_msg}";
                    $results['success'] = false;
                } elseif ($trend_status === 'insufficient_data') {
                    $results['tests_completed'][] = "Satisfaction trends: structure OK, but insufficient data ({$days_analyzed} days)";
                    $results['warnings'][] = 'Need more analysis data to show meaningful trends';
                } else {
                    $results['tests_completed'][] = "Satisfaction trends: {$days_analyzed} days analyzed, trend: {$trend_status}";
                }
            } else {
                $results['errors'][] = 'Satisfaction trends analysis failed - invalid response structure';
                $results['success'] = false;
            }
            
            // Test 5: Insights y recomendaciones
            $this->logger->info('Testing insights generation...');
            try {
                $insights = $this->generate_dashboard_insights($metrics, $critical_cases);
                $recommendations = $this->generate_dashboard_recommendations($metrics, $critical_cases);
                
                if (is_array($insights) && is_array($recommendations)) {
                    $insights_count = count($insights);
                    $rec_count = count($recommendations);
                    $results['tests_completed'][] = "Insights generated: {$insights_count} insights, {$rec_count} recommendations";
                } else {
                    $results['errors'][] = 'Insights/recommendations generation returned invalid format';
                    $results['success'] = false;
                }
            } catch (Exception $e) {
                $results['errors'][] = 'Insights generation exception: ' . $e->getMessage();
                $results['success'] = false;
            }
            
            // Test 6: Verificar funciones helper
            $this->logger->info('Testing helper functions...');
            $helper_functions = array(
                'gurux_btx_ia_format_metric',
                'gurux_btx_ia_get_sentiment_color',
                'gurux_btx_ia_get_urgency_icon',
                'gurux_btx_ia_format_agent_status'
            );
            
            $missing_functions = array();
            foreach ($helper_functions as $func) {
                if (!function_exists($func)) {
                    $missing_functions[] = $func;
                }
            }
            
            if (empty($missing_functions)) {
                $results['tests_completed'][] = 'All helper functions available (' . count($helper_functions) . ' checked)';
            } else {
                $results['errors'][] = 'Missing helper functions: ' . implode(', ', $missing_functions);
                $results['success'] = false;
            }
            
            // Test 7: Verificar tablas de base de datos
            $this->logger->info('Testing database tables...');
            global $wpdb;
            
            $required_tables = array(
                $wpdb->prefix . 'gurux_btx_ia_conversations',
                $wpdb->prefix . 'gurux_btx_ia_analysis',
                $wpdb->prefix . 'gurux_btx_ia_clients',
                $wpdb->prefix . 'gurux_btx_ia_deals'
            );
            
            $missing_tables = array();
            foreach ($required_tables as $table) {
                $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
                if (!$table_exists) {
                    $missing_tables[] = basename($table);
                }
            }
            
            if (empty($missing_tables)) {
                $results['tests_completed'][] = 'All required database tables exist';
            } else {
                $results['errors'][] = 'Missing database tables: ' . implode(', ', $missing_tables);
                $results['success'] = false;
            }
            
            // Resumen final
            $results['summary'] = array(
                'total_tests' => 7,
                'passed' => count($results['tests_completed']),
                'failed' => count($results['errors']),
                'warnings' => count($results['warnings']),
                'dashboard_ready' => $results['success'],
                'metrics_available' => !empty($metrics['summary']),
                'critical_cases_count' => $critical_cases['total_critical'] ?? 0,
                'agents_tracked' => $agent_performance['total_agents'] ?? 0,
                'data_status' => $this->assess_data_status($metrics, $critical_cases, $agent_performance)
            );
            
            if ($results['success']) {
                if (empty($results['warnings'])) {
                    $results['message'] = 'Dashboard completamente funcional con datos completos';
                } else {
                    $results['message'] = 'Dashboard funcional - ' . count($results['warnings']) . ' advertencias sobre datos';
                }
                $this->logger->info('Dashboard functionality test PASSED', $results['summary']);
            } else {
                $results['message'] = 'Dashboard tiene problemas: ' . implode(', ', $results['errors']);
                $this->logger->error('Dashboard functionality test FAILED', $results['errors']);
            }
            
        } catch (Exception $e) {
            $results['success'] = false;
            $results['message'] = 'Error crítico en test del dashboard: ' . $e->getMessage();
            $results['errors'][] = 'Exception: ' . $e->getMessage();
            
            $this->logger->error('Dashboard test critical exception', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
        }
        
        return $results;
    }
    
    /**
     * Crear datos de muestra para testing del dashboard
     */
    public function create_sample_dashboard_data() {
        global $wpdb;
        
        $results = array(
            'success' => true,
            'created' => array(),
            'errors' => array()
        );
        
        try {
            $this->logger->info('Creating sample data for dashboard testing...');
            
            // Crear conversaciones de muestra
            $sample_conversations = array(
                array(
                    'client_phone' => '+50688887777',
                    'client_name' => 'María González',
                    'client_email' => 'maria@ejemplo.com',
                    'conversation_data' => json_encode(array(
                        array('sender' => 'Cliente', 'text' => 'Hola, estoy muy satisfecha con el servicio', 'timestamp' => date('Y-m-d H:i:s', strtotime('-2 hours')))
                    )),
                    'last_message' => 'Hola, estoy muy satisfecha con el servicio',
                    'status' => 'analyzed'
                ),
                array(
                    'client_phone' => '+50699998888',
                    'client_name' => 'Carlos Rodríguez',
                    'client_email' => 'carlos@ejemplo.com',
                    'conversation_data' => json_encode(array(
                        array('sender' => 'Cliente', 'text' => 'Tengo un problema urgente con mi pedido', 'timestamp' => date('Y-m-d H:i:s', strtotime('-1 hour')))
                    )),
                    'last_message' => 'Tengo un problema urgente con mi pedido',
                    'status' => 'analyzed'
                ),
                array(
                    'client_phone' => '+50677776666',
                    'client_name' => 'Ana López',
                    'client_email' => 'ana@ejemplo.com',
                    'conversation_data' => json_encode(array(
                        array('sender' => 'Cliente', 'text' => 'El servicio está regular, podría mejorar', 'timestamp' => date('Y-m-d H:i:s', strtotime('-30 minutes')))
                    )),
                    'last_message' => 'El servicio está regular, podría mejorar',
                    'status' => 'analyzed'
                )
            );
            
            $conversation_ids = array();
            foreach ($sample_conversations as $conv) {
                $wpdb->insert($wpdb->prefix . 'gurux_btx_ia_conversations', $conv);
                if ($wpdb->insert_id) {
                    $conversation_ids[] = $wpdb->insert_id;
                    $results['created'][] = "Conversación: {$conv['client_name']}";
                }
            }
            
            // Crear análisis de muestra
            $sample_analyses = array(
                array(
                    'conversation_id' => $conversation_ids[0] ?? 1,
                    'claude_response' => json_encode(array('analysis' => 'Cliente satisfecho')),
                    'sentiment_score' => 8.5,
                    'urgency_level' => 'low',
                    'category' => 'Consulta General',
                    'client_satisfaction' => 'satisfied',
                    'opportunities' => 'Cliente satisfecho, posible upselling',
                    'recommendations' => 'Mantener el buen servicio',
                    'next_action' => 'Seguimiento en 1 semana',
                    'keywords' => '["satisfecha", "servicio", "bueno"]',
                    'analyzed_at' => date('Y-m-d H:i:s', strtotime('-2 hours')),
                    'analysis_cost' => 0.0003
                ),
                array(
                    'conversation_id' => $conversation_ids[1] ?? 2,
                    'claude_response' => json_encode(array('analysis' => 'Cliente con problema urgente')),
                    'sentiment_score' => 2.5,
                    'urgency_level' => 'critical',
                    'category' => 'Soporte Técnico',
                    'client_satisfaction' => 'risk',
                    'opportunities' => 'Resolver problema para retener cliente',
                    'recommendations' => 'Atención inmediata requerida',
                    'next_action' => 'Contactar en menos de 1 hora',
                    'keywords' => '["problema", "urgente", "pedido"]',
                    'analyzed_at' => date('Y-m-d H:i:s', strtotime('-1 hour')),
                    'analysis_cost' => 0.0004
                ),
                array(
                    'conversation_id' => $conversation_ids[2] ?? 3,
                    'claude_response' => json_encode(array('analysis' => 'Cliente neutral con sugerencias')),
                    'sentiment_score' => 6.0,
                    'urgency_level' => 'medium',
                    'category' => 'Feedback',
                    'client_satisfaction' => 'neutral',
                    'opportunities' => 'Implementar mejoras sugeridas',
                    'recommendations' => 'Agradecer feedback y seguir mejorando',
                    'next_action' => 'Seguimiento en 3 días',
                    'keywords' => '["regular", "mejorar", "servicio"]',
                    'analyzed_at' => date('Y-m-d H:i:s', strtotime('-30 minutes')),
                    'analysis_cost' => 0.0003
                )
            );
            
            foreach ($sample_analyses as $analysis) {
                $wpdb->insert($wpdb->prefix . 'gurux_btx_ia_analysis', $analysis);
                if ($wpdb->insert_id) {
                    $results['created'][] = "Análisis: sentimiento {$analysis['sentiment_score']}, urgencia {$analysis['urgency_level']}";
                }
            }
            
            // Crear clientes de muestra
            $sample_clients = array(
                array(
                    'phone' => '+50688887777',
                    'name' => 'María González',
                    'email' => 'maria@ejemplo.com',
                    'source' => 'dashboard_sample',
                    'client_segment' => 'loyal',
                    'total_interactions' => 3,
                    'satisfaction_average' => 8.5,
                    'last_sentiment_score' => 8.5,
                    'last_interaction_date' => date('Y-m-d H:i:s', strtotime('-2 hours')),
                    'satisfaction_history' => json_encode(array(
                        array('date' => date('Y-m-d H:i:s'), 'sentiment_score' => 8.5)
                    )),
                    'risk_level' => 'low',
                    'lifetime_value_estimated' => 850.00
                ),
                array(
                    'phone' => '+50699998888',
                    'name' => 'Carlos Rodríguez',
                    'email' => 'carlos@ejemplo.com',
                    'source' => 'dashboard_sample',
                    'client_segment' => 'at_risk',
                    'total_interactions' => 2,
                    'satisfaction_average' => 2.5,
                    'last_sentiment_score' => 2.5,
                    'last_interaction_date' => date('Y-m-d H:i:s', strtotime('-1 hour')),
                    'satisfaction_history' => json_encode(array(
                        array('date' => date('Y-m-d H:i:s'), 'sentiment_score' => 2.5)
                    )),
                    'risk_level' => 'high',
                    'lifetime_value_estimated' => 120.00
                ),
                array(
                    'phone' => '+50677776666',
                    'name' => 'Ana López',
                    'email' => 'ana@ejemplo.com',
                    'source' => 'dashboard_sample',
                    'client_segment' => 'standard',
                    'total_interactions' => 1,
                    'satisfaction_average' => 6.0,
                    'last_sentiment_score' => 6.0,
                    'last_interaction_date' => date('Y-m-d H:i:s', strtotime('-30 minutes')),
                    'satisfaction_history' => json_encode(array(
                        array('date' => date('Y-m-d H:i:s'), 'sentiment_score' => 6.0)
                    )),
                    'risk_level' => 'medium',
                    'lifetime_value_estimated' => 320.00
                )
            );
            
            foreach ($sample_clients as $client) {
                $wpdb->insert($wpdb->prefix . 'gurux_btx_ia_clients', $client);
                if ($wpdb->insert_id) {
                    $results['created'][] = "Cliente: {$client['name']} (segmento: {$client['client_segment']})";
                }
            }
            
            // Crear datos históricos para tendencias (últimos 7 días)
            for ($i = 7; $i >= 1; $i--) {
                $date = date('Y-m-d H:i:s', strtotime("-{$i} days"));
                $sentiment = 5.0 + (sin($i * 0.5) * 2); // Crear variación en sentimientos
                
                $historical_analysis = array(
                    'conversation_id' => $conversation_ids[0] ?? 1,
                    'claude_response' => json_encode(array('analysis' => 'Datos históricos día ' . $i)),
                    'sentiment_score' => round($sentiment, 1),
                    'urgency_level' => $sentiment > 7 ? 'low' : ($sentiment > 4 ? 'medium' : 'high'),
                    'category' => 'Histórico',
                    'client_satisfaction' => $sentiment > 7 ? 'satisfied' : ($sentiment > 4 ? 'neutral' : 'help'),
                    'opportunities' => 'Datos históricos',
                    'recommendations' => 'Análisis de tendencia',
                    'analyzed_at' => $date,
                    'analysis_cost' => 0.0002
                );
                
                $wpdb->insert($wpdb->prefix . 'gurux_btx_ia_analysis', $historical_analysis);
                if ($wpdb->insert_id) {
                    $results['created'][] = "Datos históricos día {$i}: sentimiento " . round($sentiment, 1);
                }
            }
            
            $results['message'] = 'Datos de muestra creados exitosamente: ' . count($results['created']) . ' elementos';
            $this->logger->info('Sample dashboard data created', $results);
            
        } catch (Exception $e) {
            $results['success'] = false;
            $results['message'] = 'Error creando datos de muestra: ' . $e->getMessage();
            $results['errors'][] = $e->getMessage();
            
            $this->logger->error('Error creating sample dashboard data', array(
                'error' => $e->getMessage()
            ));
        }
        
        return $results;
    }



    //FIN DE CAMBIOS

    /**
     * Evaluar estado de los datos
     */
    private function assess_data_status($metrics, $critical_cases, $agent_performance) {
        $total_analyses = intval($metrics['summary']['total_analyses'] ?? 0);
        $critical_count = intval($critical_cases['total_critical'] ?? 0);
        $agent_count = intval($agent_performance['total_agents'] ?? 0);
        
        if ($total_analyses === 0) {
            return 'no_data';
        } elseif ($total_analyses < 10) {
            return 'minimal_data';
        } elseif ($total_analyses < 50) {
            return 'partial_data';
        } else {
            return 'full_data';
        }
    }



    /**
     * Obtener casos críticos pendientes
     */
    public function get_critical_cases_pending() {
        global $wpdb;
        
        $critical_analyses = $wpdb->get_results(
            "SELECT 
                a.id as analysis_id,
                a.conversation_id,
                a.sentiment_score,
                a.urgency_level,
                a.client_satisfaction,
                a.category,
                a.analyzed_at,
                c.client_name,
                c.client_phone,
                c.last_message,
                d.id as deal_id,
                d.current_stage,
                d.responsible_id
            FROM {$wpdb->prefix}gurux_btx_ia_analysis a
            INNER JOIN {$wpdb->prefix}gurux_btx_ia_conversations c ON a.conversation_id = c.id
            LEFT JOIN {$wpdb->prefix}gurux_btx_ia_deals d ON c.client_phone = d.client_phone
            WHERE (a.urgency_level = 'critical' 
                OR a.client_satisfaction = 'risk' 
                OR a.sentiment_score < 3.0)
            AND a.analyzed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY 
                CASE a.urgency_level 
                    WHEN 'critical' THEN 1 
                    WHEN 'high' THEN 2 
                    WHEN 'medium' THEN 3 
                    ELSE 4 
                END,
                a.sentiment_score ASC,
                a.analyzed_at DESC
            LIMIT 20",
            ARRAY_A
        );

        $critical_cases = array();
        
        foreach ($critical_analyses as $case) {
            $time_since = human_time_diff(strtotime($case['analyzed_at']), current_time('timestamp'));
            $priority = $this->calculate_case_priority($case);
            
            $critical_cases[] = array(
                'analysis_id' => $case['analysis_id'],
                'client_name' => $case['client_name'],
                'client_phone' => $case['client_phone'],
                'sentiment_score' => floatval($case['sentiment_score']),
                'urgency_level' => $case['urgency_level'],
                'client_satisfaction' => $case['client_satisfaction'],
                'category' => $case['category'],
                'time_since' => $time_since,
                'priority' => $priority,
                'deal_id' => $case['deal_id'],
                'current_stage' => $case['current_stage'],
                'responsible_id' => $case['responsible_id'],
                'last_message_preview' => gurux_btx_ia_truncate_text($case['last_message'], 80),
                'action_required' => $this->determine_required_action($case)
            );
        }

        return array(
            'total_critical' => count($critical_cases),
            'cases' => $critical_cases,
            'summary' => array(
                'critical_urgency' => count(array_filter($critical_cases, function($c) { return $c['urgency_level'] === 'critical'; })),
                'at_risk_clients' => count(array_filter($critical_cases, function($c) { return $c['client_satisfaction'] === 'risk'; })),
                'low_sentiment' => count(array_filter($critical_cases, function($c) { return $c['sentiment_score'] < 3.0; }))
            )
        );
    }





    //FIN DE LOS CAMBIOS
    

    /**
     * Obtener métricas de rendimiento de agentes
     */
    
    public function get_agent_performance_metrics($days = 7) {
        global $wpdb;
        
        $date_from = date('Y-m-d', strtotime("-{$days} days"));
        
        // Métricas por agente (usando responsible_id de deals)
        $agent_stats = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                d.responsible_id,
                COUNT(d.id) as deals_created,
                AVG(d.sentiment_score) as avg_sentiment,
                COUNT(CASE WHEN d.current_stage LIKE '%WON%' THEN 1 END) as deals_won,
                SUM(d.current_value) as total_revenue,
                AVG(TIMESTAMPDIFF(HOUR, d.created_at, d.updated_at)) as avg_response_time
            FROM {$wpdb->prefix}gurux_btx_ia_deals d
            WHERE DATE(d.created_at) >= %s AND d.responsible_id IS NOT NULL
            GROUP BY d.responsible_id
            ORDER BY deals_created DESC",
            $date_from
        ), ARRAY_A);

        $enriched_agents = array();
        
        foreach ($agent_stats as $agent) {
            $conversion_rate = $agent['deals_created'] > 0 ? 
                round(($agent['deals_won'] / $agent['deals_created']) * 100, 1) : 0;
            
            $performance_score = $this->calculate_agent_performance_score($agent);
            
            $enriched_agents[] = array(
                'agent_id' => $agent['responsible_id'],
                'deals_created' => intval($agent['deals_created']),
                'deals_won' => intval($agent['deals_won']),
                'avg_sentiment' => round(floatval($agent['avg_sentiment']), 2),
                'conversion_rate' => $conversion_rate,
                'total_revenue' => round(floatval($agent['total_revenue']), 2),
                'avg_response_time' => round(floatval($agent['avg_response_time']), 1),
                'performance_score' => $performance_score,
                'status' => $this->determine_agent_status($agent)
            );
        }

        // Ordenar por puntuación de rendimiento
        usort($enriched_agents, function($a, $b) {
            return $b['performance_score'] - $a['performance_score'];
        });

        return array(
            'period_days' => $days,
            'total_agents' => count($enriched_agents),
            'agents' => $enriched_agents,
            'team_averages' => $this->calculate_team_averages($enriched_agents)
        );
    }












    /**
     * Obtener tendencias de satisfacción
     */
    
    public function get_satisfaction_trends($days = 30) {
        global $wpdb;
        
        try {
            $date_from = date('Y-m-d', strtotime("-{$days} days"));
            
            // Verificar si existen datos en la tabla
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}gurux_btx_ia_analysis'");
            if (!$table_exists) {
                return array(
                    'period_days' => $days,
                    'daily_trends' => array(),
                    'hourly_distribution' => array(),
                    'category_breakdown' => array(),
                    'trend_analysis' => array('trend' => 'no_data', 'direction' => 'unknown'),
                    'insights' => array()
                );
            }
            
            // Tendencias diarias con manejo de errores
            $daily_trends = $wpdb->get_results($wpdb->prepare(
                "SELECT 
                    DATE(analyzed_at) as trend_date,
                    AVG(sentiment_score) as avg_sentiment,
                    COUNT(*) as total_analyses,
                    COUNT(CASE WHEN client_satisfaction = 'satisfied' THEN 1 END) as satisfied_count,
                    COUNT(CASE WHEN client_satisfaction = 'risk' THEN 1 END) as risk_count,
                    COUNT(CASE WHEN urgency_level = 'critical' THEN 1 END) as critical_count
                FROM {$wpdb->prefix}gurux_btx_ia_analysis 
                WHERE DATE(analyzed_at) >= %s
                GROUP BY DATE(analyzed_at)
                ORDER BY trend_date ASC",
                $date_from
            ), ARRAY_A);

            // Si no hay datos, crear estructura vacía pero válida
            if (empty($daily_trends)) {
                $this->logger->info('No daily trends data found for satisfaction analysis');
                $daily_trends = array();
            }

            // Distribución por horas con manejo de errores
            $hourly_distribution = array();
            if (!empty($daily_trends)) {
                $hourly_distribution = $wpdb->get_results($wpdb->prepare(
                    "SELECT 
                        HOUR(analyzed_at) as hour_of_day,
                        AVG(sentiment_score) as avg_sentiment,
                        COUNT(*) as analyses_count
                    FROM {$wpdb->prefix}gurux_btx_ia_analysis 
                    WHERE DATE(analyzed_at) >= %s
                    GROUP BY HOUR(analyzed_at)
                    ORDER BY hour_of_day",
                    $date_from
                ), ARRAY_A);
            }

            // Desglose por categorías con manejo de errores
            $category_breakdown = array();
            if (!empty($daily_trends)) {
                $category_breakdown = $wpdb->get_results($wpdb->prepare(
                    "SELECT 
                        category,
                        AVG(sentiment_score) as avg_sentiment,
                        COUNT(*) as count,
                        COUNT(CASE WHEN client_satisfaction = 'satisfied' THEN 1 END) as satisfied_count
                    FROM {$wpdb->prefix}gurux_btx_ia_analysis 
                    WHERE DATE(analyzed_at) >= %s AND category != '' AND category IS NOT NULL
                    GROUP BY category
                    ORDER BY count DESC
                    LIMIT 10",
                    $date_from
                ), ARRAY_A);
            }

            // Análisis de tendencia mejorado
            $trend_analysis = $this->analyze_satisfaction_trend_safe($daily_trends);

            // Insights mejorados
            $insights = $this->generate_trend_insights_safe($daily_trends, $category_breakdown);

            $result = array(
                'period_days' => $days,
                'daily_trends' => $daily_trends ?: array(),
                'hourly_distribution' => $hourly_distribution ?: array(),
                'category_breakdown' => $category_breakdown ?: array(),
                'trend_analysis' => $trend_analysis,
                'insights' => $insights
            );

            $this->logger->debug('Satisfaction trends analysis completed', array(
                'days_analyzed' => count($daily_trends),
                'categories_found' => count($category_breakdown),
                'trend_direction' => $trend_analysis['direction'] ?? 'unknown'
            ));

            return $result;
            
        } catch (Exception $e) {
            $this->logger->error('Error in get_satisfaction_trends', array(
                'error' => $e->getMessage(),
                'days' => $days,
                'trace' => $e->getTraceAsString()
            ));
            
            // Retornar estructura válida en caso de error
            return array(
                'period_days' => $days,
                'daily_trends' => array(),
                'hourly_distribution' => array(),
                'category_breakdown' => array(),
                'trend_analysis' => array(
                    'trend' => 'error',
                    'direction' => 'unknown',
                    'error_message' => $e->getMessage()
                ),
                'insights' => array()
            );
        }
    }

/**
     * Analizar tendencia de satisfacción - Versión segura
     */
    private function analyze_satisfaction_trend_safe($daily_data) {
        try {
            if (!is_array($daily_data) || count($daily_data) < 2) {
                return array(
                    'trend' => 'insufficient_data', 
                    'direction' => 'unknown',
                    'data_points' => count($daily_data ?: array())
                );
            }
            
            // Obtener datos válidos
            $valid_data = array_filter($daily_data, function($item) {
                return isset($item['avg_sentiment']) && is_numeric($item['avg_sentiment']);
            });
            
            if (count($valid_data) < 2) {
                return array(
                    'trend' => 'insufficient_valid_data', 
                    'direction' => 'unknown',
                    'valid_points' => count($valid_data)
                );
            }
            
            // Calcular tendencia con datos válidos
            if (count($valid_data) >= 7) {
                $recent_week = array_slice($valid_data, -7);
                $previous_week = count($valid_data) >= 14 ? array_slice($valid_data, -14, 7) : array_slice($valid_data, 0, min(7, count($valid_data) - 7));
            } else {
                $recent_week = array_slice($valid_data, -3);
                $previous_week = array_slice($valid_data, 0, -3);
            }
            
            if (empty($recent_week) || empty($previous_week)) {
                return array(
                    'trend' => 'insufficient_periods', 
                    'direction' => 'stable',
                    'recent_points' => count($recent_week),
                    'previous_points' => count($previous_week)
                );
            }
            
            $recent_avg = array_sum(array_column($recent_week, 'avg_sentiment')) / count($recent_week);
            $previous_avg = array_sum(array_column($previous_week, 'avg_sentiment')) / count($previous_week);
            
            $change_percentage = $previous_avg > 0 ? 
                (($recent_avg - $previous_avg) / $previous_avg) * 100 : 0;
            
            $direction = 'stable';
            if ($change_percentage > 5) {
                $direction = 'improving';
            } elseif ($change_percentage < -5) {
                $direction = 'declining';
            }
            
            return array(
                'trend' => 'calculated',
                'direction' => $direction,
                'change_percentage' => round($change_percentage, 1),
                'recent_avg' => round($recent_avg, 2),
                'previous_avg' => round($previous_avg, 2),
                'data_points' => count($valid_data)
            );
            
        } catch (Exception $e) {
            $this->logger->error('Error in analyze_satisfaction_trend_safe', array(
                'error' => $e->getMessage()
            ));
            
            return array(
                'trend' => 'error',
                'direction' => 'unknown',
                'error' => $e->getMessage()
            );
        }
    }

    /**
     * Generar insights de tendencias - Versión segura
     */
    private function generate_trend_insights_safe($daily_data, $category_data) {
        $insights = array();
        
        try {
            // Insight sobre datos disponibles
            if (empty($daily_data)) {
                $insights[] = array(
                    'type' => 'info',
                    'message' => 'No hay datos suficientes para generar insights de tendencias'
                );
                return $insights;
            }
            
            // Insight sobre satisfacción reciente
            if (count($daily_data) >= 1) {
                $latest_data = end($daily_data);
                $recent_sentiment = floatval($latest_data['avg_sentiment'] ?? 0);
                
                if ($recent_sentiment >= 8.0) {
                    $insights[] = array(
                        'type' => 'positive',
                        'message' => 'Excelente nivel de satisfacción en los datos más recientes (' . number_format($recent_sentiment, 1) . '/10)'
                    );
                } elseif ($recent_sentiment < 5.0 && $recent_sentiment > 0) {
                    $insights[] = array(
                        'type' => 'warning',
                        'message' => 'Satisfacción por debajo del promedio en datos recientes (' . number_format($recent_sentiment, 1) . '/10)'
                    );
                }
            }
            
            // Insight sobre categorías problemáticas
            if (!empty($category_data)) {
                $problematic_categories = array_filter($category_data, function($category) {
                    return floatval($category['avg_sentiment'] ?? 0) < 4.0 && intval($category['count'] ?? 0) > 2;
                });
                
                if (!empty($problematic_categories)) {
                    $category_names = array_column($problematic_categories, 'category');
                    $insights[] = array(
                        'type' => 'alert',
                        'message' => 'Categorías que requieren atención: ' . implode(', ', array_slice($category_names, 0, 3))
                    );
                }
            }
            
            // Insight sobre volumen de datos
            $total_analyses = array_sum(array_column($daily_data, 'total_analyses'));
            if ($total_analyses > 100) {
                $insights[] = array(
                    'type' => 'info',
                    'message' => 'Buen volumen de datos para análisis: ' . number_format($total_analyses) . ' análisis en el período'
                );
            } elseif ($total_analyses < 10) {
                $insights[] = array(
                    'type' => 'warning',
                    'message' => 'Volumen bajo de datos para análisis: solo ' . $total_analyses . ' análisis disponibles'
                );
            }
            
        } catch (Exception $e) {
            $this->logger->error('Error in generate_trend_insights_safe', array(
                'error' => $e->getMessage()
            ));
            
            $insights[] = array(
                'type' => 'error',
                'message' => 'Error generando insights: ' . $e->getMessage()
            );
        }
        
        return $insights;
    }

    //FIN DE CAMBIOS














    /**
     * Calcular puntuación de rendimiento del agente
     */
    private function calculate_agent_performance_score($agent_stats) {
        $sentiment_score = floatval($agent_stats['avg_sentiment']) * 10;
        $deals_score = min(intval($agent_stats['deals_created']) * 2, 30);
        $conversion_score = $agent_stats['deals_created'] > 0 ? 
            (floatval($agent_stats['deals_won']) / floatval($agent_stats['deals_created'])) * 40 : 0;
        $response_score = max(0, 20 - floatval($agent_stats['avg_response_time']));
        
        return round(min(100, $sentiment_score + $deals_score + $conversion_score + $response_score), 1);
    }

    /**
     * Determinar estado del agente
     */
    private function determine_agent_status($agent_stats) {
        $deals_created = intval($agent_stats['deals_created']);
        $avg_sentiment = floatval($agent_stats['avg_sentiment']);
        $response_time = floatval($agent_stats['avg_response_time']);
        
        if ($deals_created >= 10 && $avg_sentiment >= 7.0 && $response_time <= 2) {
            return 'excellent';
        } elseif ($deals_created >= 5 && $avg_sentiment >= 6.0 && $response_time <= 4) {
            return 'good';
        } elseif ($deals_created >= 2 && $avg_sentiment >= 4.0) {
            return 'average';
        } else {
            return 'needs_attention';
        }
    }

    /**
     * Calcular promedios del equipo
     */
    private function calculate_team_averages($agents) {
        if (empty($agents)) {
            return array();
        }
        
        return array(
            'avg_deals_created' => round(array_sum(array_column($agents, 'deals_created')) / count($agents), 1),
            'avg_sentiment' => round(array_sum(array_column($agents, 'avg_sentiment')) / count($agents), 2),
            'avg_conversion_rate' => round(array_sum(array_column($agents, 'conversion_rate')) / count($agents), 1),
            'avg_performance_score' => round(array_sum(array_column($agents, 'performance_score')) / count($agents), 1),
            'total_revenue' => array_sum(array_column($agents, 'total_revenue'))
        );
    }

    /**
     * Calcular prioridad del caso
     */
    private function calculate_case_priority($case) {
        $priority_score = 0;
        
        // Puntuación por urgencia
        if ($case['urgency_level'] === 'critical') $priority_score += 40;
        elseif ($case['urgency_level'] === 'high') $priority_score += 30;
        elseif ($case['urgency_level'] === 'medium') $priority_score += 20;
        else $priority_score += 10;
        
        // Puntuación por satisfacción
        if ($case['client_satisfaction'] === 'risk') $priority_score += 30;
        elseif ($case['client_satisfaction'] === 'help') $priority_score += 20;
        
        // Penalización por sentimiento bajo
        $sentiment_penalty = (3.0 - floatval($case['sentiment_score'])) * 10;
        $priority_score += max(0, $sentiment_penalty);
        
        // Penalización por tiempo transcurrido
        $hours_since = (time() - strtotime($case['analyzed_at'])) / 3600;
        $time_penalty = $hours_since * 2;
        $priority_score += $time_penalty;
        
        return min(100, round($priority_score));
    }

    /**
     * Determinar acción requerida
     */
    private function determine_required_action($case) {
        if ($case['urgency_level'] === 'critical' || $case['client_satisfaction'] === 'risk') {
            return 'immediate_intervention';
        } elseif ($case['sentiment_score'] < 2.0) {
            return 'urgent_follow_up';
        } elseif ($case['client_satisfaction'] === 'help') {
            return 'provide_support';
        } else {
            return 'standard_follow_up';
        }
    }

    /**
     * Analizar tendencia de satisfacción
     */
    private function analyze_satisfaction_trend($daily_data) {
        if (count($daily_data) < 7) {
            return array('trend' => 'insufficient_data', 'direction' => 'unknown');
        }
        
        $recent_week = array_slice($daily_data, -7);
        $previous_week = array_slice($daily_data, -14, 7);
        
        $recent_avg = array_sum(array_column($recent_week, 'avg_sentiment')) / 7;
        $previous_avg = count($previous_week) > 0 ? 
            array_sum(array_column($previous_week, 'avg_sentiment')) / count($previous_week) : $recent_avg;
        
        $change_percentage = $previous_avg > 0 ? 
            (($recent_avg - $previous_avg) / $previous_avg) * 100 : 0;
        
        $direction = 'stable';
        if ($change_percentage > 5) $direction = 'improving';
        elseif ($change_percentage < -5) $direction = 'declining';
        
        return array(
            'trend' => 'calculated',
            'direction' => $direction,
            'change_percentage' => round($change_percentage, 1),
            'recent_avg' => round($recent_avg, 2),
            'previous_avg' => round($previous_avg, 2)
        );
    }

    /**
     * Generar insights de tendencias
     */
    private function generate_trend_insights($daily_data, $category_data) {
        $insights = array();
        
        if (count($daily_data) >= 7) {
            $recent_sentiment = end($daily_data)['avg_sentiment'];
            if ($recent_sentiment < 5.0) {
                $insights[] = array(
                    'type' => 'warning',
                    'message' => 'Satisfacción general por debajo del promedio en los últimos días'
                );
            } elseif ($recent_sentiment > 8.0) {
                $insights[] = array(
                    'type' => 'positive',
                    'message' => 'Excelente nivel de satisfacción general reciente'
                );
            }
        }
        
        foreach ($category_data as $category) {
            if ($category['avg_sentiment'] < 4.0 && $category['count'] > 5) {
                $insights[] = array(
                    'type' => 'alert',
                    'message' => "Categoría '{$category['category']}' requiere atención: satisfacción baja"
                );
            }
        }
        
        return $insights;
    }


    //FIN DE LOS CAMBIOS










}


// Función helper global
if (!function_exists('gurux_btx_ia_main')) {
    function gurux_btx_ia_main() {
        return GuruX_BTX_IA_Main::get_instance();
    }
}
