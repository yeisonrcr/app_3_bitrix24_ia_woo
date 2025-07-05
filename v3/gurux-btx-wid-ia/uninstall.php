<?php
/**
 * GuruX BTX IA - Script de Desinstalación
 * 
 * Se ejecuta cuando el plugin es eliminado desde WordPress
 * Limpia todas las tablas, opciones y archivos creados
 * 
 * @package GuruX_BTX_IA
 * @version 1.0.0
 */

// Verificar que WordPress esté desinstalando el plugin
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Cargar funciones helper si es necesario
if (file_exists(dirname(__FILE__) . '/functions-ia.php')) {
    require_once dirname(__FILE__) . '/functions-ia.php';
}

/**
 * Función principal de desinstalación
 */
function gurux_btx_ia_uninstall() {
    global $wpdb;
    
    // Obtener configuración de desinstalación
    $delete_data = get_option('gurux_btx_ia_delete_data_on_uninstall', false);
    
    // Si el usuario no quiere eliminar datos, solo limpiar cache
    if (!$delete_data) {
        gurux_btx_ia_uninstall_clear_cache();
        return;
    }
    
    // Eliminar todas las tablas del plugin
    gurux_btx_ia_uninstall_drop_tables();
    
    // Eliminar todas las opciones
    gurux_btx_ia_uninstall_delete_options();
    
    // Eliminar archivos de logs
    gurux_btx_ia_uninstall_delete_logs();
    
    // Limpiar cron jobs
    gurux_btx_ia_uninstall_clear_cron();
    
    // Limpiar cache y transients
    gurux_btx_ia_uninstall_clear_cache();
    
    // Limpiar capacidades de usuario si se agregaron
    gurux_btx_ia_uninstall_remove_capabilities();
}

/**
 * Eliminar todas las tablas del plugin
 */
function gurux_btx_ia_uninstall_drop_tables() {
    global $wpdb;
    
    // Lista de tablas a eliminar
    $tables = array(
        $wpdb->prefix . 'gurux_btx_ia_conversations',
        $wpdb->prefix . 'gurux_btx_ia_analysis',
        $wpdb->prefix . 'gurux_btx_ia_clients',
        $wpdb->prefix . 'gurux_btx_ia_deals',
        $wpdb->prefix . 'gurux_btx_ia_templates',
        $wpdb->prefix . 'gurux_btx_ia_settings',
        $wpdb->prefix . 'gurux_btx_ia_logs',
        $wpdb->prefix . 'gurux_btx_ia_agentes_venta'
    );
    
    // Desactivar verificación de foreign keys temporalmente
    $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
    
    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS $table");
    }
    
    // Reactivar verificación de foreign keys
    $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
}

/**
 * Eliminar todas las opciones del plugin
 */
function gurux_btx_ia_uninstall_delete_options() {
    $options_to_delete = array(
        'gurux_btx_ia_version',
        'gurux_btx_ia_db_version',
        'gurux_btx_ia_settings',
        'gurux_btx_ia_activated_at',
        'gurux_btx_ia_daily_analyses_count',
        'gurux_btx_ia_daily_cost',
        'gurux_btx_ia_claude_daily_stats',
        'gurux_btx_ia_prompt_templates',
        'gurux_btx_ia_delete_data_on_uninstall'
    );
    
    foreach ($options_to_delete as $option) {
        delete_option($option);
    }
    
    // Eliminar opciones de sitios en multisite
    if (is_multisite()) {
        $sites = get_sites();
        foreach ($sites as $site) {
            switch_to_blog($site->blog_id);
            
            foreach ($options_to_delete as $option) {
                delete_option($option);
            }
            
            restore_current_blog();
        }
    }
}

/**
 * Eliminar archivos de logs
 */
function gurux_btx_ia_uninstall_delete_logs() {
    $logs_dir = WP_CONTENT_DIR . '/gurux-btx-ia-logs/';
    
    if (is_dir($logs_dir)) {
        // Eliminar todos los archivos de log
        $files = glob($logs_dir . '*.log');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        
        // Eliminar archivos rotados
        $rotated_files = glob($logs_dir . '*.rotated');
        foreach ($rotated_files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        
        // Eliminar .htaccess
        if (file_exists($logs_dir . '.htaccess')) {
            unlink($logs_dir . '.htaccess');
        }
        
        // Eliminar index.php
        if (file_exists($logs_dir . 'index.php')) {
            unlink($logs_dir . 'index.php');
        }
        
        // Eliminar directorio
        rmdir($logs_dir);
    }
}

/**
 * Limpiar cron jobs
 */
function gurux_btx_ia_uninstall_clear_cron() {
    $cron_hooks = array(
        'gurux_btx_ia_daily_cleanup',
        'gurux_btx_ia_process_queue',
        'gurux_btx_ia_sync_clients',
        'gurux_clear_analysis_cache'
    );
    
    foreach ($cron_hooks as $hook) {
        $timestamp = wp_next_scheduled($hook);
        if ($timestamp) {
            wp_unschedule_event($timestamp, $hook);
        }
        
        // Limpiar todas las instancias del cron
        wp_clear_scheduled_hook($hook);
    }
}

/**
 * Limpiar cache y transients
 */
function gurux_btx_ia_uninstall_clear_cache() {
    global $wpdb;
    
    // Eliminar todos los transients del plugin
    $wpdb->query(
        "DELETE FROM {$wpdb->options} 
        WHERE option_name LIKE '_transient_gurux_btx_ia_%' 
        OR option_name LIKE '_transient_timeout_gurux_btx_ia_%'"
    );
    
    // Limpiar cache de objetos si está disponible
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
}

/**
 * Remover capacidades de usuario personalizadas
 */
function gurux_btx_ia_uninstall_remove_capabilities() {
    // Si en el futuro se agregan capacidades personalizadas
    // se pueden remover aquí
    $capabilities = array(
        'gurux_btx_ia_view_analytics',
        'gurux_btx_ia_manage_settings',
        'gurux_btx_ia_create_deals'
    );
    
    $roles = array('administrator', 'editor');
    
    foreach ($roles as $role_name) {
        $role = get_role($role_name);
        if ($role) {
            foreach ($capabilities as $cap) {
                $role->remove_cap($cap);
            }
        }
    }
}

// Ejecutar desinstalación
gurux_btx_ia_uninstall();