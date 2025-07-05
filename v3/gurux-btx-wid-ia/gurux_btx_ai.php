<?php
/**
 * Plugin Name: GuruX BTX IA
 * Plugin URI: https://guruxglobal.com
 * Description: Análisis IA de conversaciones con integración Bitrix24
 * Version: 1.0.0
 * Author: GuruX
 * Text Domain: gurux-btx-ia
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}


// Constantes del plugin
define('GURUX_BTX_IA_VERSION', '1.0.0');
define('GURUX_BTX_IA_DB_VERSION', '1.0.0');
define('GURUX_BTX_IA_PLUGIN_FILE', __FILE__);
define('GURUX_BTX_IA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GURUX_BTX_IA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('GURUX_BTX_IA_LOGS_DIR', WP_CONTENT_DIR . '/gurux-btx-ia-logs/');

// Constantes de configuración
define('GURUX_BTX_IA_DAILY_ANALYSIS_LIMIT', 1000);
define('GURUX_BTX_IA_LOG_RETENTION_DAYS', 30);

// Constantes API Claude
define('GURUX_BTX_IA_CLAUDE_API_URL', 'https://api.anthropic.com/v1/messages');
define('GURUX_BTX_IA_CLAUDE_MODEL', 'claude-3-haiku-20240307');
define('GURUX_BTX_IA_CLAUDE_MAX_TOKENS', 1024);
define('GURUX_BTX_IA_CLAUDE_VERSION', '2023-06-01');



// Cargar funciones globales
require_once GURUX_BTX_IA_PLUGIN_DIR . 'functions.php';

// Cargar clases
require_once GURUX_BTX_IA_PLUGIN_DIR . 'includes/class-gurux-logger.php';
require_once GURUX_BTX_IA_PLUGIN_DIR . 'includes/class-gurux-bitrix-api.php';
require_once GURUX_BTX_IA_PLUGIN_DIR . 'includes/class-gurux-claude-api.php';
require_once GURUX_BTX_IA_PLUGIN_DIR . 'includes/class-gurux-chat-analyzer.php';
require_once GURUX_BTX_IA_PLUGIN_DIR . 'includes/class-gurux-deals-manager.php';
require_once GURUX_BTX_IA_PLUGIN_DIR . 'includes/class-gurux-client-manager.php';
require_once GURUX_BTX_IA_PLUGIN_DIR . 'includes/class-gurux-btx-ia-main.php';

/**
 * Clase principal del plugin
 */
class GuruX_BTX_IA {
    
    private static $instance = null;
    private $main_controller;
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Obtener instancia única
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Inicializar hooks
     */
    private function init_hooks() {
        // Hooks de activación/desactivación
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Inicializar plugin
        add_action('plugins_loaded', array($this, 'init'), 10);
        
        // Admin hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        
        // AJAX hooks para OAuth callback
        add_action('admin_init', array($this, 'handle_oauth_callback'));

        // AJAX hooks para testing
        add_action('wp_ajax_gurux_btx_ia_run_test', array($this, 'ajax_run_test'));
        add_action('wp_ajax_gurux_btx_ia_clear_tokens', array($this, 'ajax_clear_tokens'));
        add_action('wp_ajax_gurux_btx_ia_get_stats', array($this, 'ajax_get_stats'));


        // Agregar estos hooks en init_hooks():
        add_action('wp_ajax_gurux_btx_ia_test_bitrix_connection', array($this, 'ajax_test_bitrix_connection'));
        add_action('wp_ajax_gurux_btx_ia_clear_tokens', array($this, 'ajax_clear_tokens'));


    }





    /**
     * AJAX: Test conexión Bitrix24 mejorado
     */
    public function ajax_test_bitrix_connection() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sin permisos'));
        }
        
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'gurux_btx_ia_nonce')) {
            wp_send_json_error(array('message' => 'Nonce inválido'));
        }
        
        $api = gurux_btx_ia_api();
        if (!$api) {
            wp_send_json_error(array('message' => 'API no disponible'));
        }
        
        $status = $api->get_connection_status();
        
        if ($status['connected']) {
            wp_send_json_success(array(
                'message' => $status['message'],
                'status' => $status,
                'timestamp' => current_time('mysql')
            ));
        } else {
            wp_send_json_error(array(
                'message' => $status['message'],
                'status' => $status,
                'needs_reauth' => !$status['authorized']
            ));
        }
    }

    /**
     * AJAX: Limpiar tokens mejorado
     */
    public function ajax_clear_tokens() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sin permisos'));
        }
        
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'gurux_btx_ia_nonce')) {
            wp_send_json_error(array('message' => 'Nonce inválido'));
        }
        
        $api = gurux_btx_ia_api();
        if (!$api) {
            wp_send_json_error(array('message' => 'API no disponible'));
        }
        
        $result = $api->clear_tokens();
        
        if ($result) {
            wp_send_json_success(array(
                'message' => 'Tokens eliminados correctamente',
                'action' => 'tokens_cleared'
            ));
        } else {
            wp_send_json_error(array('message' => 'Error eliminando tokens'));
        }
    }


    
    /**
     * AJAX: Ejecutar test
     */
    public function ajax_run_test() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sin permisos'));
        }
        
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'gurux_btx_ia_nonce')) {
            wp_send_json_error(array('message' => 'Nonce inválido'));
        }
        
        $test_type = sanitize_text_field($_POST['test_type'] ?? '');
        
        // Delegar al controlador principal
        if ($this->main_controller) {
            $result = $this->main_controller->run_test($test_type);
            
            if ($result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result);
            }
        } else {
            wp_send_json_error(array('message' => 'Sistema no inicializado'));
        }
    }



    
    /**
     * AJAX: Obtener estadísticas
     */
    public function ajax_get_stats() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sin permisos'));
        }
        
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'gurux_btx_ia_nonce')) {
            wp_send_json_error(array('message' => 'Nonce inválido'));
        }
        
        $stats = gurux_btx_ia_get_daily_stats();
        
        wp_send_json_success(array(
            'analyses_today' => $stats['total_analyses'],
            'average_sentiment' => $stats['average_sentiment'],
            'deals_created' => 0, // TODO: Implementar
            'estimated_cost' => number_format($stats['total_cost'], 2)
        ));
    }

    
    /**
     * Activar plugin
     */
    public function activate() {
        // Crear tablas
        $this->create_tables();
        
        // Crear directorio de logs
        if (!file_exists(GURUX_BTX_IA_LOGS_DIR)) {
            wp_mkdir_p(GURUX_BTX_IA_LOGS_DIR);
            file_put_contents(GURUX_BTX_IA_LOGS_DIR . '.htaccess', 'Deny from all');
        }
        
        // Opciones por defecto
        add_option('gurux_btx_ia_version', GURUX_BTX_IA_VERSION);
        add_option('gurux_btx_ia_db_version', GURUX_BTX_IA_DB_VERSION);
        
        // Programar cron
        if (!wp_next_scheduled('gurux_btx_ia_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'gurux_btx_ia_daily_cleanup');
        }
    }
    
    /**
     * Desactivar plugin
     */
    public function deactivate() {
        // Limpiar cron
        wp_clear_scheduled_hook('gurux_btx_ia_daily_cleanup');
    }
    
    /**
     * Inicializar plugin
     */
    public function init() {
        // Inicializar controlador principal
        $this->main_controller = GuruX_BTX_IA_Main::get_instance();
        
        // Verificar actualizaciones de BD
        $this->check_db_updates();
    }
    
    /**
     * Agregar menú admin
     */
    public function add_admin_menu() {
        add_menu_page(
            'GuruX BTX IA',
            'BTX IA',
            'manage_options',
            'gurux-btx-ia',
            array($this, 'admin_page'),
            'dashicons-analytics',
            30
        );
        
        // Submenús
        add_submenu_page(
            'gurux-btx-ia',
            'Configuración',
            'Configuración',
            'manage_options',
            'gurux-btx-ia',
            array($this, 'admin_page')
        );
        
        add_submenu_page(
            'gurux-btx-ia',
            'Testing',
            'Testing',
            'manage_options',
            'gurux-btx-ia-testing',
            array($this, 'testing_page')
        );
    }
    
    /**
     * Página de administración
     */
    public function admin_page() {
        include GURUX_BTX_IA_PLUGIN_DIR . 'admin/admin-page.php';
    }
    
    /**
     * Página de testing
     */
    public function testing_page() {
        // Testing está integrado en admin-page.php con tabs
        wp_redirect(admin_url('admin.php?page=gurux-btx-ia&tab=testing'));
        exit;
    }
    
    /**
     * Scripts y estilos admin
     */



    public function admin_scripts($hook) {
        if (strpos($hook, 'gurux-btx-ia') === false) return;
        
        // CSS optimizado con variables y selectores agrupados
        ?>
        <style>
            :root { --gurux-primary: #0073aa; --gurux-success: #28a745; --gurux-error: #dc3232; --gurux-border: #e1e4e8; --gurux-bg: #f8f9fa; }
            .gurux-wrap { max-width: 1200px; margin: 20px 0; }
            .gurux-card, .gurux-status-panel, .gurux-auth-section, .gurux-connection-info { background: #fff; border: 1px solid var(--gurux-border); border-radius: 4px; padding: 20px; margin-bottom: 20px; }
            .gurux-status-panel { background: var(--gurux-bg); padding: 15px; }
            .gurux-auth-section { background: #e7f3ff; border-left: 4px solid var(--gurux-primary); }
            .gurux-connection-info { background: #d4edda; border-left: 4px solid var(--gurux-success); }
            .gurux-status-panel h4, .gurux-auth-section h3, .gurux-connection-info h4 { margin-top: 0; margin-bottom: 10px; }
            .gurux-auth-section h3 { color: var(--gurux-primary); }
            .gurux-connection-info h4 { color: #155724; }
            .gurux-status.success, .gurux-status { padding: 8px 12px; border-radius: 3px; display: inline-block; margin: 5px 0; }
            .gurux-status.success { background: #d4edda; color: #155724; }
            .gurux-status.error { background: #f8d7da; color: #721c24; }
            .gurux-form-row { margin-bottom: 15px; }
            .gurux-form-row label { display: block; margin-bottom: 5px; font-weight: 600; }
            .gurux-form-row input[type="text"], .gurux-form-row input[type="password"] { width: 100%; max-width: 400px; }
            .gurux-form-row .description { color: #666; font-style: italic; margin-top: 5px; }
            .button-large { font-size: 14px; padding: 8px 20px; height: auto; }
            details summary { cursor: pointer; font-weight: bold; padding: 5px 0; }
            details[open] summary { margin-bottom: 10px; }
            code { display: block; padding: 8px; background: white; border-radius: 2px; border: 1px solid #ddd; font-family: monospace; word-break: break-all; }
            .button:disabled { opacity: 0.6; cursor: not-allowed; }
            .gurux-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0; }
            .gurux-stat-box { background: var(--gurux-bg); border: 1px solid var(--gurux-border); border-radius: 4px; padding: 15px; text-align: center; }
            .gurux-stat-number { font-size: 32px; font-weight: bold; color: var(--gurux-primary); }
            .gurux-stat-label { color: #666; margin-top: 5px; }
            .gurux-test-button { margin: 5px 0; }
            .gurux-log { background: #f1f1f1; padding: 10px; border-radius: 4px; margin: 10px 0; font-family: monospace; font-size: 12px; max-height: 300px; overflow-y: auto; }
            .gurux-success { color: #46b450; }
            .gurux-error { color: var(--gurux-error); }
            .gurux-warning { color: #ffb900; }
            .gurux-info { color: #00a0d2; }
            .gurux-tab-nav { display: flex; border-bottom: 1px solid #ccd0d4; margin-bottom: 20px; }
            .gurux-tab { padding: 10px 20px; cursor: pointer; border-bottom: 2px solid transparent; }
            .gurux-tab.active { border-bottom-color: var(--gurux-primary); font-weight: bold; }
            .gurux-tab-content { display: none; }
            .gurux-tab-content.active { display: block; }
            .gurux-button-group { margin: 20px 0; }
            .gurux-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
            .gurux-table th, .gurux-table td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
            .gurux-table th { background: #f5f5f5; font-weight: 600; }
            .test-buttons-row { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
            .gurux-test-button { min-width: 200px; position: relative; transition: all 0.3s ease; }
            .gurux-test-button .button-icon { margin-right: 5px; }
            .gurux-test-button.test-running { opacity: 0.7; transform: scale(0.98); }
            .gurux-test-button.test-success { background: #46b450; color: white; }
            .gurux-test-button.test-error { background: #dc3232; color: white; }
            .gurux-status-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 15px 0; }
            .status-item { display: flex; justify-content: space-between; align-items: center; padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
            .status-indicator { font-weight: bold; }
            .status-indicator.status-success { color: var(--gurux-success); }
            .status-indicator.status-error { color: var(--gurux-error); }
            .test-progress-section { margin: 20px 0; padding: 15px; background: #f9f9f9; border-radius: 4px; }
            .progress-bar { width: 100%; height: 20px; background: #e1e4e8; border-radius: 10px; overflow: hidden; margin: 10px 0; }
            .progress-fill { height: 100%; background: linear-gradient(90deg, #28a745, #20c997); transition: width 0.5s ease; width: 0%; }
            .test-summary { text-align: center; font-weight: bold; margin-top: 10px; }
            .log-controls { margin-bottom: 10px; }
            .log-controls button { margin-right: 5px; }
            .gurux-log { max-height: 400px; overflow-y: auto; border: 1px solid #ddd; background: #fff; }
            .log-entry { padding: 8px; border-bottom: 1px solid #eee; font-family: monospace; font-size: 12px; }
            .log-entry.log-success { background: #d4edda; }
            .log-entry.log-error { background: #f8d7da; }
            .log-entry.log-warning { background: #fff3cd; }
            .log-entry.log-info { background: #d1ecf1; }
            .log-timestamp { color: #666; font-weight: bold; }
            .log-icon { margin: 0 5px; }
            .log-details { margin-top: 5px; padding: 5px; background: rgba(0,0,0,0.05); border-radius: 3px; }
            .log-details pre { margin: 0; font-size: 11px; }
            .log-stats { padding: 10px; background: #f5f5f5; border-top: 1px solid #ddd; font-size: 11px; text-align: right; }
            @media (max-width: 768px) {
                .test-buttons-row { flex-direction: column; }
                .gurux-test-button { min-width: auto; width: 100%; }
                .gurux-status-grid { grid-template-columns: 1fr; }
            }

            @media (max-width: 768px) {
                .gurux-auth-section, .gurux-connection-info, .gurux-status-panel { margin: 15px 0; padding: 15px; }
                .button-large { width: 100%; text-align: center; }
            }
            
            
            /* ESTILOS PARA DASHBOARD */
            
            /* Gráficos simples */
            .simple-line-chart {
                display: flex;
                align-items: end;
                height: 250px;
                padding: 20px;
                background: #f8f9fa;
                border-radius: 4px;
                position: relative;
            }
            
            .simple-line-chart .chart-header {
                position: absolute;
                top: 10px;
                left: 20px;
                font-weight: bold;
                color: #495057;
                font-size: 12px;
            }
            
            .chart-bar {
                width: 20px;
                margin: 0 2px;
                border-radius: 2px 2px 0 0;
                transition: all 0.3s ease;
                cursor: pointer;
            }
            
            .chart-bar:hover {
                opacity: 0.8;
                transform: translateY(-2px);
            }
            
            /* Grid de agentes */
            .agents-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                gap: 15px;
                margin-top: 15px;
            }
            
            .agent-card {
                background: #fff;
                border: 1px solid #e1e4e8;
                border-radius: 6px;
                padding: 15px;
                transition: all 0.3s ease;
            }
            
            .agent-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            }
            
            .agent-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 12px;
                border-bottom: 1px solid #eee;
                padding-bottom: 8px;
            }
            
            .agent-status {
                font-weight: bold;
                font-size: 14px;
            }
            
            .performance-score {
                background: var(--gurux-primary);
                color: white;
                padding: 4px 8px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: bold;
            }
            
            .agent-metrics {
                display: grid;
                grid-template-columns: 1fr 1fr 1fr;
                gap: 10px;
                margin-bottom: 12px;
            }
            
            .agent-metrics .metric {
                text-align: center;
                padding: 8px;
                background: #f8f9fa;
                border-radius: 4px;
            }
            
            .agent-metrics .label {
                display: block;
                font-size: 11px;
                color: #6c757d;
                margin-bottom: 2px;
            }
            
            .agent-metrics .value {
                font-weight: bold;
                font-size: 14px;
            }
            
            .performance-bar-container {
                width: 100%;
                height: 6px;
                background: #e9ecef;
                border-radius: 3px;
                overflow: hidden;
            }
            
            .performance-bar {
                height: 100%;
                transition: width 0.5s ease;
                border-radius: 3px;
            }
            
            /* Insights y recomendaciones */
            .insight-item {
                display: flex;
                align-items: center;
                padding: 12px;
                margin-bottom: 8px;
                background: #f8f9fa;
                border-radius: 4px;
                border-left: 4px solid var(--gurux-primary);
            }
            
            .insight-icon {
                margin-right: 10px;
                font-size: 16px;
            }
            
            .insight-text {
                flex: 1;
                font-size: 14px;
                line-height: 1.4;
            }
            
            .recommendation-item {
                display: flex;
                align-items: flex-start;
                padding: 12px;
                margin-bottom: 10px;
                background: #fff;
                border: 1px solid #e1e4e8;
                border-radius: 4px;
                transition: all 0.2s ease;
            }
            
            .recommendation-item:hover {
                border-color: var(--gurux-primary);
                background: #f8f9ff;
            }
            
            .rec-content {
                margin-left: 10px;
                flex: 1;
            }
            
            .rec-content strong {
                color: var(--gurux-primary);
                font-size: 14px;
            }
            
            .rec-content span {
                color: #6c757d;
                font-size: 13px;
                line-height: 1.4;
            }
            
            /* Badges y etiquetas */
            .priority-badge {
                font-size: 11px;
                font-weight: bold;
                padding: 4px 8px;
                border-radius: 12px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            .status-badge {
                padding: 3px 8px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: bold;
                text-transform: uppercase;
            }
            
            .status-badge.excellent { background: #d4edda; color: #155724; }
            .status-badge.good { background: #d1ecf1; color: #0c5460; }
            .status-badge.average { background: #fff3cd; color: #856404; }
            .status-badge.needs-attention { background: #f8d7da; color: #721c24; }
            
            /* Animaciones de carga */
            .loading-spinner {
                display: inline-block;
                width: 20px;
                height: 20px;
                border: 3px solid #f3f3f3;
                border-top: 3px solid var(--gurux-primary);
                border-radius: 50%;
                animation: spin 1s linear infinite;
            }
            
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            
            .pulse-animation {
                animation: pulse 2s infinite;
            }
            
            @keyframes pulse {
                0% { opacity: 1; }
                50% { opacity: 0.5; }
                100% { opacity: 1; }
            }
            
            /* Alertas del dashboard */
            #dashboard-alerts {
                margin-bottom: 20px;
            }
            
            #dashboard-alerts .notice {
                margin: 5px 0;
                padding: 12px;
                border-radius: 4px;
            }
            
            #dashboard-alerts .notice-error {
                border-left-color: #dc3545;
                background: #f8d7da;
                color: #721c24;
            }
            
            #dashboard-alerts .notice-warning {
                border-left-color: #ffc107;
                background: #fff3cd;
                color: #856404;
            }
            
            /* Responsividad para dashboard */
            @media (max-width: 1200px) {
                .agents-grid {
                    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                }
            }
            
            @media (max-width: 768px) {
                .gurux-stats {
                    grid-template-columns: repeat(2, 1fr);
                }
                
                .agents-grid {
                    grid-template-columns: 1fr;
                }
                
                .agent-metrics {
                    grid-template-columns: 1fr;
                }
                
                .simple-line-chart {
                    height: 200px;
                    padding: 15px;
                }
                
                .chart-bar {
                    width: 15px;
                    margin: 0 1px;
                }
            }
            
            /* Mejoras visuales */
            .gurux-stat-box {
                position: relative;
                overflow: hidden;
            }
            
            .gurux-stat-box::before {
                content: '';
                position: absolute;
                top: 0;
                left: -100%;
                width: 100%;
                height: 100%;
                background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
                transition: left 0.5s;
            }
            
            .gurux-stat-box:hover::before {
                left: 100%;
            }
            
            .gurux-card {
                position: relative;
                transition: all 0.3s ease;
            }
            
            .gurux-card:hover {
                transform: translateY(-1px);
                box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            }
            
            /* Indicadores de estado en tiempo real */
            .real-time-indicator {
                display: inline-block;
                width: 8px;
                height: 8px;
                background: var(--gurux-success);
                border-radius: 50%;
                margin-right: 5px;
                animation: pulse 2s infinite;
            }
            
            .real-time-indicator.warning {
                background: #ffc107;
            }
            
            .real-time-indicator.error {
                background: var(--gurux-error);
            }
            
            /* Tooltips simples */
            [data-tooltip] {
                position: relative;
                cursor: help;
            }
            
            [data-tooltip]:hover::after {
                content: attr(data-tooltip);
                position: absolute;
                bottom: 100%;
                left: 50%;
                transform: translateX(-50%);
                background: #333;
                color: white;
                padding: 8px 12px;
                border-radius: 4px;
                font-size: 12px;
                white-space: nowrap;
                z-index: 1000;
            }
            
            [data-tooltip]:hover::before {
                content: '';
                position: absolute;
                bottom: 100%;
                left: 50%;
                transform: translateX(-50%) translateY(100%);
                border: 5px solid transparent;
                border-top-color: #333;
                z-index: 1000;
            }







            
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            $('.gurux-tab').on('click', function() {
                var tabId = $(this).data('tab');
                $('.gurux-tab').removeClass('active');
                $(this).addClass('active');
                $('.gurux-tab-content').removeClass('active');
                $('#' + tabId).addClass('active');
            });
            
            window.guruxAjax = function(action, data, callback) {
                data.action = action;
                data.nonce = '<?php echo wp_create_nonce('gurux_btx_ia_nonce'); ?>';
                $.post(ajaxurl, data, callback);
            };
            
            window.guruxLog = function(message, type = 'info') {
                var logEntry = '<div class="gurux-' + type + '">[' + new Date().toLocaleTimeString() + '] ' + message + '</div>';
                $('#gurux-log-output').append(logEntry).scrollTop($('#gurux-log-output')[0].scrollHeight);
            };
        });
        </script>
        <?php
    }


    
    /**
     * Manejar OAuth callback
     */
    /**
     * Manejar OAuth callback mejorado
     */
    public function handle_oauth_callback() {
        if (isset($_GET['page']) && $_GET['page'] === 'gurux-btx-ia' && 
            isset($_GET['action']) && $_GET['action'] === 'oauth') {
            
            $api = gurux_btx_ia_api();
            if (!$api) {
                wp_die('Error: API no disponible');
            }
            
            $result = $api->handle_oauth_callback();
            
            if ($result['success']) {
                // Redirigir con mensaje de éxito
                $redirect_url = isset($result['redirect_url']) ? 
                    $result['redirect_url'] : 
                    admin_url('admin.php?page=gurux-btx-ia&tab=bitrix&auth=success');
                    
                wp_redirect($redirect_url);
                exit;
            } else {
                // Redirigir con mensaje de error
                $error_url = admin_url('admin.php?page=gurux-btx-ia&tab=bitrix&auth=error&msg=' . urlencode($result['message']));
                wp_redirect($error_url);
                exit;
            }
        }
    }







    /**
     * Crear tablas de BD
     */
    private function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Array con todas las tablas
        $tables = array();
        
        // Tabla conversaciones
        $tables[] = "CREATE TABLE {$wpdb->prefix}gurux_btx_ia_conversations (
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
            KEY status (status)
        ) $charset_collate;";
        
        // Tabla análisis
        $tables[] = "CREATE TABLE {$wpdb->prefix}gurux_btx_ia_analysis (
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
            KEY conversation_id (conversation_id)
        ) $charset_collate;";
        
        // Tabla clientes
        $tables[] = "CREATE TABLE {$wpdb->prefix}gurux_btx_ia_clients (
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
            UNIQUE KEY phone (phone)
        ) $charset_collate;";
        
        // Tabla deals
        $tables[] = "CREATE TABLE {$wpdb->prefix}gurux_btx_ia_deals (
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
            KEY bitrix_deal_id (bitrix_deal_id)
        ) $charset_collate;";
        
        // Tabla templates
        $tables[] = "CREATE TABLE {$wpdb->prefix}gurux_btx_ia_templates (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            prompt_text longtext NOT NULL,
            category varchar(50) DEFAULT 'general',
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY name (name)
        ) $charset_collate;";
        
        // Tabla settings
        $tables[] = "CREATE TABLE {$wpdb->prefix}gurux_btx_ia_settings (
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
        
        // Tabla logs
        $tables[] = "CREATE TABLE {$wpdb->prefix}gurux_btx_ia_logs (
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
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // NUEVA TABLA: Agentes de venta
        $tables[] = "CREATE TABLE {$wpdb->prefix}gurux_btx_ia_agentes_venta (
            id int(11) NOT NULL AUTO_INCREMENT,
            bitrix_user_id int(11) NOT NULL,
            name varchar(255) NOT NULL,
            email varchar(255) DEFAULT '',
            phone varchar(20) DEFAULT '',
            total_conversations int(11) DEFAULT 0,
            satisfaction_average decimal(4,2) DEFAULT 0.00,
            deals_created int(11) DEFAULT 0,
            deals_won int(11) DEFAULT 0,
            total_revenue decimal(10,2) DEFAULT 0.00,
            performance_score decimal(4,2) DEFAULT 0.00,
            ranking int(11) DEFAULT NULL,
            is_active tinyint(1) DEFAULT 1,
            last_activity datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY bitrix_user_id (bitrix_user_id),
            KEY performance_score (performance_score)
        ) $charset_collate;";
        
        // Crear todas las tablas
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        foreach ($tables as $sql) {
            dbDelta($sql);
        }
    }
    
    /**
     * Verificar actualizaciones de BD
     */
    private function check_db_updates() {
        $current_db_version = get_option('gurux_btx_ia_db_version', '0.0.0');
        
        if (version_compare($current_db_version, GURUX_BTX_IA_DB_VERSION, '<')) {
            $this->create_tables();
            update_option('gurux_btx_ia_db_version', GURUX_BTX_IA_DB_VERSION);
        }
    }
}

// Inicializar plugin
GuruX_BTX_IA::get_instance();