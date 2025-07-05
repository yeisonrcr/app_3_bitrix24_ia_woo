<?php
/**
 * GuruX Logger - Sistema de Logs Avanzado
 * 
 * Maneja todos los logs del sistema con niveles, archivos rotativos
 * y integración completa con WordPress
 * 
 * @package GuruX_BTX_IA
 * @version 1.0.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class GuruX_Logger {
    
    /**
     * Instancia única (Singleton)
     */
    private static $instance = null;
    
    /**
     * Directorio de logs
     */
    private $logs_dir;
    
    /**
     * Nivel mínimo de logging
     */
    private $log_level;
    
    /**
     * Días de retención de logs
     */
    private $retention_days;
    
    /**
     * Niveles de log disponibles
     */
    private $levels = array(
        'debug' => 0,
        'info' => 1,
        'warning' => 2,
        'error' => 3
    );
    
    /**
     * Constructor privado para Singleton
     */
    private function __construct() {
        $this->logs_dir = defined('GURUX_BTX_IA_LOGS_DIR') ? 
            GURUX_BTX_IA_LOGS_DIR : 
            WP_CONTENT_DIR . '/plugins/gurux-btx-ia/logs/';
        
        $this->log_level = gurux_btx_ia_get_setting('log_level', 'info');
        $this->retention_days = gurux_btx_ia_get_setting('log_retention_days', GURUX_BTX_IA_LOG_RETENTION_DAYS);
        
        // Crear directorio si no existe
        $this->ensure_logs_directory();
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
     * Log nivel DEBUG
     */
    public function debug($message, $context = array()) {
        $this->log('debug', $message, $context);
    }
    
    /**
     * Log nivel INFO
     */
    public function info($message, $context = array()) {
        $this->log('info', $message, $context);
    }
    
    /**
     * Log nivel WARNING
     */
    public function warning($message, $context = array()) {
        $this->log('warning', $message, $context);
    }
    
    /**
     * Log nivel ERROR
     */
    public function error($message, $context = array()) {
        $this->log('error', $message, $context);
    }
    
    /**
     * Método principal de logging
     */
    public function log($level, $message, $context = array()) {
        // Verificar si el nivel debe ser loggeado
        if (!$this->should_log($level)) {
            return;
        }
        
        // Formatear mensaje
        $formatted_message = $this->format_message($level, $message, $context);
        
        // Escribir a archivo
        $this->write_to_file($formatted_message, $level);
        
        // También usar error_log de WordPress como respaldo
        if ($level === 'error') {
            error_log('[GuruX BTX IA] ' . $message);
        }
    }
    
    /**
     * Log de actividad de análisis IA
     */
    public function log_analysis($conversation_id, $result, $execution_time, $cost) {
        $context = array(
            'conversation_id' => $conversation_id,
            'sentiment_score' => $result['sentiment_score'] ?? 'N/A',
            'urgency_level' => $result['urgency_level'] ?? 'N/A',
            'execution_time' => $execution_time . 'ms',
            'cost' => '$' . number_format($cost, 4),
            'type' => 'analysis'
        );
        
        $this->info('Análisis IA completado', $context);
    }
    
    /**
     * Log de creación de deals
     */
    public function log_deal_creation($deal_data, $success = true) {
        $level = $success ? 'info' : 'error';
        $message = $success ? 'Deal creado automáticamente' : 'Error creando deal';
        
        $context = array(
            'deal_id' => $deal_data['id'] ?? 'N/A',
            'client_name' => $deal_data['client_name'] ?? 'N/A',
            'value' => $deal_data['value'] ?? 'N/A',
            'stage' => $deal_data['stage'] ?? 'N/A',
            'type' => 'deal_creation'
        );
        
        $this->log($level, $message, $context);
    }
    
    /**
     * Log de errores de API
     */
    public function log_api_error($api_name, $endpoint, $error_message, $http_code = null) {
        $context = array(
            'api' => $api_name,
            'endpoint' => $endpoint,
            'http_code' => $http_code,
            'type' => 'api_error'
        );
        
        $this->error($error_message, $context);
    }
    
    /**
     * Log de métricas de rendimiento
     */
    public function log_performance($operation, $execution_time, $memory_usage = null) {
        if ($execution_time > 5000) { // Más de 5 segundos
            $level = 'warning';
        } else {
            $level = 'debug';
        }
        
        $context = array(
            'operation' => $operation,
            'execution_time' => $execution_time . 'ms',
            'memory_usage' => $memory_usage ? round($memory_usage / 1024 / 1024, 2) . 'MB' : 'N/A',
            'type' => 'performance'
        );
        
        $this->log($level, 'Métricas de rendimiento', $context);
    }
    
    /**
     * Obtener logs del día
     */
    public function get_daily_logs($date = null, $level = null) {
        if ($date === null) {
            $date = current_time('Y-m-d');
        }
        
        $log_file = $this->get_log_file_path($level ?: 'all', $date);
        
        if (!file_exists($log_file)) {
            return array();
        }
        
        $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $logs = array();
        
        foreach ($lines as $line) {
            $parsed = $this->parse_log_line($line);
            if ($parsed) {
                $logs[] = $parsed;
            }
        }
        
        return array_reverse($logs); // Más recientes primero
    }
    
    /**
     * Obtener estadísticas de logs
     */
    public function get_log_stats($days = 7) {
        $stats = array(
            'total_entries' => 0,
            'by_level' => array(
                'debug' => 0,
                'info' => 0,
                'warning' => 0,
                'error' => 0
            ),
            'by_type' => array(),
            'recent_errors' => array()
        );
        
        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $daily_logs = $this->get_daily_logs($date);
            
            foreach ($daily_logs as $log_entry) {
                $stats['total_entries']++;
                
                // Contar por nivel
                if (isset($stats['by_level'][$log_entry['level']])) {
                    $stats['by_level'][$log_entry['level']]++;
                }
                
                // Contar por tipo
                $type = $log_entry['context']['type'] ?? 'general';
                if (!isset($stats['by_type'][$type])) {
                    $stats['by_type'][$type] = 0;
                }
                $stats['by_type'][$type]++;
                
                // Recopilar errores recientes
                if ($log_entry['level'] === 'error' && count($stats['recent_errors']) < 10) {
                    $stats['recent_errors'][] = $log_entry;
                }
            }
        }
        
        return $stats;
    }
    
    /**
     * Limpiar logs antiguos
     */
    public function cleanup_old_logs() {
        $deleted_files = 0;
        $cutoff_date = date('Y-m-d', strtotime("-{$this->retention_days} days"));
        
        $files = glob($this->logs_dir . '*.log');
        
        foreach ($files as $file) {
            $filename = basename($file);
            
            // Extraer fecha del nombre del archivo
            if (preg_match('/(\d{4}-\d{2}-\d{2})/', $filename, $matches)) {
                $file_date = $matches[1];
                
                if ($file_date < $cutoff_date) {
                    if (unlink($file)) {
                        $deleted_files++;
                    }
                }
            }
        }
        
        $this->info("Limpieza de logs completada", array(
            'files_deleted' => $deleted_files,
            'cutoff_date' => $cutoff_date
        ));
        
        return $deleted_files;
    }
    
    /**
     * Exportar logs como CSV
     */
    public function export_logs_csv($start_date, $end_date, $level = null) {
        $logs = array();
        
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($start, $interval, $end->add($interval));
        
        foreach ($period as $date) {
            $daily_logs = $this->get_daily_logs($date->format('Y-m-d'), $level);
            $logs = array_merge($logs, $daily_logs);
        }
        
        // Generar CSV
        $csv_content = "Timestamp,Level,Message,Context\n";
        
        foreach ($logs as $log_entry) {
            $context = json_encode($log_entry['context']);
            $csv_content .= sprintf(
                '"%s","%s","%s","%s"' . "\n",
                $log_entry['timestamp'],
                $log_entry['level'],
                str_replace('"', '""', $log_entry['message']),
                str_replace('"', '""', $context)
            );
        }
        
        return $csv_content;
    }
    
    // ============================================================================
    // MÉTODOS PRIVADOS
    // ============================================================================
    
    /**
     * Verificar si un nivel debe ser loggeado
     */
    private function should_log($level) {
        if (!isset($this->levels[$level])) {
            return false;
        }
        
        $min_level = $this->levels[$this->log_level];
        $current_level = $this->levels[$level];
        
        return $current_level >= $min_level;
    }
    
    /**
     * Formatear mensaje de log
     */
    private function format_message($level, $message, $context) {
        $timestamp = current_time('Y-m-d H:i:s');
        $level_upper = strtoupper($level);
        
        $formatted = "[{$timestamp}] [{$level_upper}] {$message}";
        
        if (!empty($context)) {
            $context_str = $this->format_context($context);
            $formatted .= " | {$context_str}";
        }
        
        return $formatted;
    }
    
    /**
     * Formatear contexto
     */
    private function format_context($context) {
        if (empty($context)) {
            return '';
        }
        
        $formatted_parts = array();
        
        foreach ($context as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value);
            }
            $formatted_parts[] = "{$key}={$value}";
        }
        
        return implode(' ', $formatted_parts);
    }
    
    /**
     * Escribir a archivo
     */
    private function write_to_file($message, $level) {
        $log_file = $this->get_log_file_path($level);
        
        if (!is_writable(dirname($log_file))) {
            return false;
        }
        
        return file_put_contents($log_file, $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Obtener ruta del archivo de log
     */
    private function get_log_file_path($level, $date = null) {
        if ($date === null) {
            $date = current_time('Y-m-d');
        }
        
        return $this->logs_dir . "gurux-btx-ia-{$level}-{$date}.log";
    }
    
    /**
     * Asegurar que el directorio de logs existe
     */
    private function ensure_logs_directory() {
        if (!file_exists($this->logs_dir)) {
            wp_mkdir_p($this->logs_dir);
            
            // Crear .htaccess para proteger logs
            $htaccess_content = "Order deny,allow\nDeny from all\n";
            file_put_contents($this->logs_dir . '.htaccess', $htaccess_content);
        }
    }
    
    /**
     * Parsear línea de log
     */
    private function parse_log_line($line) {
        // Formato: [2024-01-15 14:30:22] [INFO] Mensaje | contexto
        $pattern = '/^\[([^\]]+)\] \[([^\]]+)\] (.+?)(?:\s\|\s(.+))?$/';
        
        if (preg_match($pattern, $line, $matches)) {
            $context = array();
            
            if (isset($matches[4])) {
                // Parsear contexto
                $context_parts = explode(' ', $matches[4]);
                foreach ($context_parts as $part) {
                    if (strpos($part, '=') !== false) {
                        list($key, $value) = explode('=', $part, 2);
                        $context[$key] = $value;
                    }
                }
            }
            
            return array(
                'timestamp' => $matches[1],
                'level' => strtolower($matches[2]),
                'message' => $matches[3],
                'context' => $context
            );
        }
        
        return null;
    }
    
    /**
     * Obtener tamaño total de logs
     */
    public function get_logs_size() {
        $total_size = 0;
        $files = glob($this->logs_dir . '*.log');
        
        foreach ($files as $file) {
            $total_size += filesize($file);
        }
        
        return $total_size;
    }
    
    /**
     * Rotar logs si son muy grandes
     */
    public function rotate_logs_if_needed($max_size_mb = 50) {
        $max_size_bytes = $max_size_mb * 1024 * 1024;
        $files = glob($this->logs_dir . '*.log');
        
        foreach ($files as $file) {
            if (filesize($file) > $max_size_bytes) {
                $rotated_name = $file . '.' . time() . '.rotated';
                rename($file, $rotated_name);
                
                $this->info('Log rotado por tamaño', array(
                    'original_file' => basename($file),
                    'rotated_file' => basename($rotated_name),
                    'size_mb' => round(filesize($rotated_name) / 1024 / 1024, 2)
                ));
            }
        }
    }
}

// Función helper global para facilitar el uso
if (!function_exists('gurux_logger')) {
    function gurux_logger() {
        return GuruX_Logger::get_instance();
    }
}

// Función helper para log rápido
if (!function_exists('gurux_log')) {
    function gurux_log($level, $message, $context = array()) {
        gurux_logger()->log($level, $message, $context);
    }
}