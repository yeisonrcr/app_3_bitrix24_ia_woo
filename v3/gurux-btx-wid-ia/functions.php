<?php
/**
 * GuruX BTX IA - Funciones Helper Globales
 * 
 * Funciones utilitarias consolidadas para todo el plugin
 * 
 * @package GuruX_BTX_IA
 * @version 1.0.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// ============================================================================
// FUNCIONES DE CONFIGURACIÓN
// ============================================================================

/**
 * Obtener opción del plugin
 */
function gurux_btx_ia_get_option($option, $default = null) {
    $options = get_option('gurux_btx_ia_settings', array());
    return isset($options[$option]) ? $options[$option] : $default;
}

/**
 * Actualizar opción del plugin
 */
function gurux_btx_ia_update_option($option, $value) {
    $options = get_option('gurux_btx_ia_settings', array());
    $options[$option] = $value;
    return update_option('gurux_btx_ia_settings', $options);
}

/**
 * Verificar si está configurado
 */
function gurux_btx_ia_is_configured() {
    $domain = gurux_btx_ia_get_option('bitrix_domain');
    $client_id = gurux_btx_ia_get_option('client_id');
    $client_secret = gurux_btx_ia_get_option('client_secret');
    $claude_key = gurux_btx_ia_get_option('claude_api_key');
    
    return !empty($domain) && !empty($client_id) && !empty($client_secret) && !empty($claude_key);
}

/**
 * Verificar tokens válidos
 */
function gurux_btx_ia_has_valid_tokens() {
    $access_token = gurux_btx_ia_get_option('access_token');
    $refresh_token = gurux_btx_ia_get_option('refresh_token');
    
    return !empty($access_token) && !empty($refresh_token);
}

/**
 * Obtener configuración general
 */
function gurux_btx_ia_get_setting($key, $default = null) {
    return gurux_btx_ia_get_option($key, $default);
}

/**
 * Actualizar múltiples configuraciones
 */
function gurux_btx_ia_update_settings($settings) {
    if (!is_array($settings)) {
        return new WP_Error('invalid_settings', 'Configuraciones deben ser un array');
    }
    
    foreach ($settings as $key => $value) {
        gurux_btx_ia_update_option($key, $value);
    }
    
    return true;
}

// ============================================================================
// FUNCIONES DE VALIDACIÓN
// ============================================================================

/**
 * Validar número de teléfono
 */
function gurux_btx_ia_is_valid_phone($phone) {
    // Remover espacios y caracteres especiales
    $clean_phone = preg_replace('/[^0-9+]/', '', $phone);
    
    // Verificar longitud mínima (7 dígitos) y máxima (15 con código país)
    if (strlen($clean_phone) < 7 || strlen($clean_phone) > 15) {
        return false;
    }
    
    // Verificar formato válido
    return preg_match('/^\+?[0-9]{7,15}$/', $clean_phone);
}

/**
 * Validar puntuación de sentimiento
 */
function gurux_btx_ia_is_valid_sentiment_score($score) {
    return is_numeric($score) && $score >= 0.0 && $score <= 10.0;
}

/**
 * Validar nivel de urgencia
 */
function gurux_btx_ia_is_valid_urgency_level($level) {
    $valid_levels = array('low', 'medium', 'high', 'critical');
    return in_array($level, $valid_levels);
}

/**
 * Validar satisfacción del cliente
 */
function gurux_btx_ia_is_valid_client_satisfaction($satisfaction) {
    $valid_values = array('risk', 'help', 'neutral', 'satisfied');
    return in_array($satisfaction, $valid_values);
}

/**
 * Validar estructura de análisis
 */
function gurux_btx_ia_validate_analysis_structure($analysis) {
    $required_fields = array(
        'sentiment_score',
        'urgency_level',
        'category',
        'client_satisfaction',
        'opportunities',
        'recommendations',
        'next_action',
        'keywords'
    );
    
    foreach ($required_fields as $field) {
        if (!isset($analysis[$field])) {
            return false;
        }
    }
    
    return true;
}

// ============================================================================
// FUNCIONES DE FORMATO Y LIMPIEZA
// ============================================================================

/**
 * Limpiar número de teléfono
 */
function gurux_btx_ia_clean_phone($phone) {
    // Remover todo excepto números y +
    $clean = preg_replace('/[^0-9+]/', '', $phone);
    
    // Si no tiene código de país, agregar el por defecto (Costa Rica)
    if (!empty($clean) && substr($clean, 0, 1) !== '+') {
        $default_code = gurux_btx_ia_get_setting('default_country_code', '+506');
        $clean = $default_code . $clean;
    }
    
    return $clean;
}

/**
 * Limpiar texto para análisis IA
 */
function gurux_btx_ia_clean_text_for_ai($text) {
    // Remover HTML
    $text = wp_strip_all_tags($text);
    
    // Normalizar espacios en blanco
    $text = preg_replace('/\s+/', ' ', $text);
    
    // Remover caracteres de control
    $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text);
    
    // Trim
    $text = trim($text);
    
    // Limitar longitud
    if (strlen($text) > 10000) {
        $text = substr($text, 0, 10000) . '...';
    }
    
    return $text;
}

/**
 * Formatear nombre de cliente
 */
function gurux_btx_ia_format_client_name($name) {
    $name = trim($name);
    
    if (empty($name)) {
        return 'Cliente Sin Nombre';
    }
    
    // Capitalizar palabras
    $name = ucwords(strtolower($name));
    
    // Limitar longitud
    if (strlen($name) > 100) {
        $name = substr($name, 0, 97) . '...';
    }
    
    return $name;
}

/**
 * Formatear conversación para mostrar
 */
function gurux_btx_ia_format_conversation($messages) {
    if (!is_array($messages)) {
        return $messages;
    }
    
    $formatted = array();
    
    foreach ($messages as $message) {
        $sender = isset($message['sender']) ? $message['sender'] : 'Desconocido';
        $text = isset($message['text']) ? $message['text'] : '';
        $time = isset($message['timestamp']) ? date('H:i', strtotime($message['timestamp'])) : '';
        
        $formatted[] = "[{$time}] {$sender}: {$text}";
    }
    
    return implode("\n", $formatted);
}

/**
 * Truncar texto
 */
function gurux_btx_ia_truncate_text($text, $length = 100, $suffix = '...') {
    if (strlen($text) <= $length) {
        return $text;
    }
    
    return substr($text, 0, $length - strlen($suffix)) . $suffix;
}

// ============================================================================
// FUNCIONES DE CÁLCULO Y ANÁLISIS
// ============================================================================

/**
 * Calcular puntuación de oportunidad
 */
function gurux_btx_ia_calculate_opportunity_score($analysis, $client_data) {
    $score = 5.0; // Base
    
    // Ajustar por sentimiento
    if ($analysis['sentiment_score'] >= 8) {
        $score += 3;
    } elseif ($analysis['sentiment_score'] >= 6) {
        $score += 1;
    } elseif ($analysis['sentiment_score'] < 4) {
        $score -= 2;
    }
    
    // Ajustar por satisfacción
    $satisfaction_modifiers = array(
        'satisfied' => 2,
        'neutral' => 0,
        'help' => -1,
        'risk' => -3
    );
    
    if (isset($satisfaction_modifiers[$analysis['client_satisfaction']])) {
        $score += $satisfaction_modifiers[$analysis['client_satisfaction']];
    }
    
    // Ajustar por palabras clave de oportunidad
    $opportunity_keywords = array('comprar', 'adquirir', 'interesado', 'precio', 'cotización', 'presupuesto');
    $found_keywords = 0;
    
    foreach ($opportunity_keywords as $keyword) {
        if (stripos($analysis['opportunities'], $keyword) !== false) {
            $found_keywords++;
        }
    }
    
    $score += min($found_keywords, 3); // Máximo 3 puntos por keywords
    
    // Limitar rango 0-10
    return max(0, min(10, $score));
}

/**
 * Determinar tipo de seguimiento
 */
function gurux_btx_ia_determine_followup_type($analysis) {
    // Urgencia crítica
    if ($analysis['urgency_level'] === 'critical' || $analysis['client_satisfaction'] === 'risk') {
        return 'immediate';
    }
    
    // Alta oportunidad
    if ($analysis['sentiment_score'] >= 8 && $analysis['client_satisfaction'] === 'satisfied') {
        return 'sales_opportunity';
    }
    
    // Necesita ayuda
    if ($analysis['client_satisfaction'] === 'help') {
        return 'support_needed';
    }
    
    // Seguimiento estándar
    return 'standard';
}

/**
 * Generar recomendaciones automáticas
 */
function gurux_btx_ia_generate_auto_recommendations($analysis, $client_data) {
    $recommendations = array();
    
    // Basado en satisfacción
    switch ($analysis['client_satisfaction']) {
        case 'risk':
            $recommendations[] = 'Contactar inmediatamente para retención';
            $recommendations[] = 'Ofrecer compensación o descuento';
            break;
            
        case 'help':
            $recommendations[] = 'Escalar a soporte técnico especializado';
            $recommendations[] = 'Hacer seguimiento en 24 horas';
            break;
            
        case 'satisfied':
            $recommendations[] = 'Aprovechar para upselling/cross-selling';
            $recommendations[] = 'Solicitar referidos o testimonios';
            break;
    }
    
    // Basado en urgencia
    if ($analysis['urgency_level'] === 'critical') {
        array_unshift($recommendations, '🚨 ATENCIÓN INMEDIATA REQUERIDA');
    }
    
    return $recommendations;
}

/**
 * Verificar horario laboral
 */
function gurux_btx_ia_is_business_hours() {
    $timezone = gurux_btx_ia_get_setting('timezone', 'America/Costa_Rica');
    $start_hour = gurux_btx_ia_get_setting('working_hours_start', '08:00');
    $end_hour = gurux_btx_ia_get_setting('working_hours_end', '18:00');
    
    // Establecer timezone
    $original_tz = date_default_timezone_get();
    date_default_timezone_set($timezone);
    
    $current_time = time();
    $current_hour = date('H:i', $current_time);
    $is_weekday = date('N', $current_time) <= 5; // Lunes a Viernes
    
    // Restaurar timezone original
    date_default_timezone_set($original_tz);
    
    return $is_weekday && $current_hour >= $start_hour && $current_hour <= $end_hour;
}

/**
 * Obtener prioridad de tarea por urgencia
 */
function gurux_btx_ia_get_task_priority_by_urgency($urgency) {
    $priority_map = array(
        'critical' => 2, // Alta
        'high' => 1,     // Normal
        'medium' => 1,   // Normal
        'low' => 0       // Baja
    );
    
    return isset($priority_map[$urgency]) ? $priority_map[$urgency] : 1;
}

// ============================================================================
// FUNCIONES DE CACHÉ
// ============================================================================

/**
 * Obtener de caché
 */
function gurux_btx_ia_get_cache($key) {
    return get_transient('gurux_btx_ia_' . $key);
}

/**
 * Guardar en caché
 */
function gurux_btx_ia_set_cache($key, $value, $expiration = 3600) {
    return set_transient('gurux_btx_ia_' . $key, $value, $expiration);
}

/**
 * Limpiar caché específico
 */
function gurux_btx_ia_clear_cache($key) {
    return delete_transient('gurux_btx_ia_' . $key);
}

/**
 * Limpiar todo el caché del plugin
 */
function gurux_btx_ia_clear_all_cache() {
    global $wpdb;
    
    $wpdb->query(
        "DELETE FROM {$wpdb->options} 
        WHERE option_name LIKE '_transient_gurux_btx_ia_%' 
        OR option_name LIKE '_transient_timeout_gurux_btx_ia_%'"
    );
}

/**
 * Limpiar caché expirado
 */
function gurux_btx_ia_clear_expired_cache() {
    // WordPress maneja esto automáticamente
    // Esta función es para futuras expansiones
}

// ============================================================================
// FUNCIONES DE LÍMITES Y CUOTAS
// ============================================================================

/**
 * Verificar límites diarios
 */
function gurux_btx_ia_check_daily_limits() {
    $today = current_time('Y-m-d');
    $count_key = 'daily_analysis_count_' . $today;
    $current_count = gurux_btx_ia_get_cache($count_key) ?: 0;
    $daily_limit = gurux_btx_ia_get_setting('daily_analysis_limit', GURUX_BTX_IA_DAILY_ANALYSIS_LIMIT);
    
    return array(
        'current' => $current_count,
        'limit' => $daily_limit,
        'remaining' => max(0, $daily_limit - $current_count),
        'exceeded' => $current_count >= $daily_limit,
        'percentage' => $daily_limit > 0 ? round(($current_count / $daily_limit) * 100, 2) : 0
    );
}

/**
 * Incrementar contador diario
 */
function gurux_btx_ia_increment_daily_counter() {
    $today = current_time('Y-m-d');
    $count_key = 'daily_analysis_count_' . $today;
    $current_count = gurux_btx_ia_get_cache($count_key) ?: 0;
    
    gurux_btx_ia_set_cache($count_key, $current_count + 1, 86400); // 24 horas
    
    return $current_count + 1;
}

/**
 * Obtener estadísticas diarias
 */
function gurux_btx_ia_get_daily_stats() {
    global $wpdb;
    
    $today = current_time('Y-m-d');
    
    $stats = $wpdb->get_row($wpdb->prepare(
        "SELECT 
            COUNT(*) as total_analyses,
            AVG(sentiment_score) as average_sentiment,
            SUM(analysis_cost) as total_cost
        FROM {$wpdb->prefix}gurux_btx_ia_analysis 
        WHERE DATE(analyzed_at) = %s",
        $today
    ), ARRAY_A);
    
    return array(
        'date' => $today,
        'total_analyses' => intval($stats['total_analyses'] ?? 0),
        'average_sentiment' => round(floatval($stats['average_sentiment'] ?? 0), 2),
        'total_cost' => round(floatval($stats['total_cost'] ?? 0), 4),
        'limits' => gurux_btx_ia_check_daily_limits()
    );
}

// ============================================================================
// FUNCIONES DE COMPATIBILIDAD
// ============================================================================

/**
 * Verificar compatibilidad con otros plugins GuruX
 */
function gurux_btx_ia_check_plugin_compatibility() {
    $compatible_plugins = array(
        'gurux-btx-widget/gurux-btx-widget.php',
        'yeison-btx-widget/yeison-btx-widget.php'
    );
    
    $active_compatible = array();
    
    foreach ($compatible_plugins as $plugin) {
        if (is_plugin_active($plugin)) {
            $active_compatible[] = $plugin;
        }
    }
    
    return $active_compatible;
}

/**
 * Migrar configuración desde plugins hermanos
 */
function gurux_btx_ia_migrate_settings_from_siblings() {
    // Intentar obtener configuración de yeison-btx-widget
    $yeison_settings = get_option('yeison_btx_widget_settings', array());
    
    if (!empty($yeison_settings)) {
        // Migrar credenciales Bitrix24
        if (!gurux_btx_ia_get_option('bitrix_domain') && isset($yeison_settings['bitrix_domain'])) {
            gurux_btx_ia_update_option('bitrix_domain', $yeison_settings['bitrix_domain']);
        }
        
        if (!gurux_btx_ia_get_option('client_id') && isset($yeison_settings['client_id'])) {
            gurux_btx_ia_update_option('client_id', $yeison_settings['client_id']);
        }
        
        if (!gurux_btx_ia_get_option('client_secret') && isset($yeison_settings['client_secret'])) {
            gurux_btx_ia_update_option('client_secret', $yeison_settings['client_secret']);
        }
        
        return true;
    }
    
    return false;
}

// ============================================================================
// FUNCIONES DE UTILIDAD
// ============================================================================

/**
 * Sanitizar entrada según tipo
 */
function gurux_btx_ia_sanitize_input($input, $type = 'text') {
    switch ($type) {
        case 'text':
            return sanitize_text_field($input);
            
        case 'textarea':
            return sanitize_textarea_field($input);
            
        case 'email':
            return sanitize_email($input);
            
        case 'url':
            return esc_url_raw($input);
            
        case 'int':
            return intval($input);
            
        case 'float':
        case 'number':
            return floatval($input);
            
        case 'bool':
            return filter_var($input, FILTER_VALIDATE_BOOLEAN);
            
        case 'json':
            return json_encode(json_decode($input, true));
            
        default:
            return sanitize_text_field($input);
    }
}

/**
 * Obtener API instance helper
 */
function gurux_btx_ia_api() {
    if (class_exists('GuruX_BTX_AI_Bitrix_API')) {
        return GuruX_BTX_AI_Bitrix_API::get_instance();
    }
    return null;
}

/**
 * Log helper rápido
 */
function gurux_btx_ia_log($level, $message, $context = array()) {
    if (class_exists('GuruX_Logger')) {
        GuruX_Logger::get_instance()->log($level, $message, $context);
    }
}





//FIN DE FUNCIONES
// ============================================================================
// FUNCIONES HELPER PARA DASHBOARD - FASE 2
// ============================================================================

/**
 * Formatear métricas para dashboard
 */
function gurux_btx_ia_format_metric($value, $type = 'number') {
    switch ($type) {
        case 'percentage':
            return round(floatval($value), 1) . '%';
            
        case 'currency':
            return '$' . number_format(floatval($value), 2);
            
        case 'decimal':
            return number_format(floatval($value), 2);
            
        case 'integer':
            return number_format(intval($value));
            
        case 'sentiment':
            $score = floatval($value);
            return $score . '/10 (' . gurux_btx_ia_get_sentiment_label($score) . ')';
            
        case 'time_ago':
            return human_time_diff(strtotime($value), current_time('timestamp')) . ' ago';
            
        default:
            return $value;
    }
}

/**
 * Obtener etiqueta de sentimiento
 */
function gurux_btx_ia_get_sentiment_label($score) {
    $score = floatval($score);
    
    if ($score >= 8.0) return 'Excelente';
    if ($score >= 6.5) return 'Bueno';
    if ($score >= 5.0) return 'Regular';
    if ($score >= 3.0) return 'Malo';
    return 'Muy Malo';
}

/**
 * Obtener color para sentimiento
 */
function gurux_btx_ia_get_sentiment_color($score) {
    $score = floatval($score);
    
    if ($score >= 8.0) return '#28a745'; // Verde
    if ($score >= 6.5) return '#17a2b8'; // Azul
    if ($score >= 5.0) return '#ffc107'; // Amarillo
    if ($score >= 3.0) return '#fd7e14'; // Naranja
    return '#dc3545'; // Rojo
}

/**
 * Obtener icono para urgencia
 */
function gurux_btx_ia_get_urgency_icon($urgency_level) {
    $icons = array(
        'critical' => '🚨',
        'high' => '⚠️',
        'medium' => '📋',
        'low' => '📝'
    );
    
    return $icons[$urgency_level] ?? '📋';
}

/**
 * Obtener color para urgencia
 */
function gurux_btx_ia_get_urgency_color($urgency_level) {
    $colors = array(
        'critical' => '#dc3545',
        'high' => '#fd7e14',
        'medium' => '#ffc107',
        'low' => '#6c757d'
    );
    
    return $colors[$urgency_level] ?? '#6c757d';
}

/**
 * Formatear estado del agente
 */
function gurux_btx_ia_format_agent_status($status) {
    $labels = array(
        'excellent' => array('label' => 'Excelente', 'color' => '#28a745', 'icon' => '⭐'),
        'good' => array('label' => 'Bueno', 'color' => '#17a2b8', 'icon' => '👍'),
        'average' => array('label' => 'Promedio', 'color' => '#ffc107', 'icon' => '👌'),
        'needs_attention' => array('label' => 'Necesita Atención', 'color' => '#dc3545', 'icon' => '⚡')
    );
    
    return $labels[$status] ?? $labels['average'];
}

/**
 * Calcular KPI de satisfacción
 */
function gurux_btx_ia_calculate_satisfaction_kpi($current_avg, $previous_avg = null) {
    $kpi = array(
        'current' => round($current_avg, 2),
        'status' => 'stable',
        'change' => 0,
        'color' => gurux_btx_ia_get_sentiment_color($current_avg)
    );
    
    if ($previous_avg !== null) {
        $change = $current_avg - $previous_avg;
        $kpi['change'] = round($change, 2);
        
        if ($change > 0.5) {
            $kpi['status'] = 'improving';
        } elseif ($change < -0.5) {
            $kpi['status'] = 'declining';
        }
    }
    
    return $kpi;
}

/**
 * Generar datos para gráfico de tendencias
 */
function gurux_btx_ia_format_trend_data($raw_data, $date_field = 'date', $value_field = 'value') {
    $formatted = array();
    
    foreach ($raw_data as $item) {
        $formatted[] = array(
            'x' => $item[$date_field],
            'y' => floatval($item[$value_field])
        );
    }
    
    return $formatted;
}

/**
 * Calcular distribución porcentual
 */
function gurux_btx_ia_calculate_distribution($data, $total_field = 'total') {
    $total = array_sum(array_column($data, $total_field));
    $distribution = array();
    
    foreach ($data as $item) {
        $percentage = $total > 0 ? round(($item[$total_field] / $total) * 100, 1) : 0;
        $distribution[] = array_merge($item, array('percentage' => $percentage));
    }
    
    return $distribution;
}

/**
 * Generar colores para gráficos
 */
function gurux_btx_ia_get_chart_colors($count = 5) {
    $base_colors = array(
        '#007bff', '#28a745', '#dc3545', '#ffc107', '#17a2b8',
        '#6f42c1', '#fd7e14', '#20c997', '#6c757d', '#e83e8c'
    );
    
    $colors = array();
    for ($i = 0; $i < $count; $i++) {
        $colors[] = $base_colors[$i % count($base_colors)];
    }
    
    return $colors;
}

/**
 * Formatear tiempo de respuesta
 */
function gurux_btx_ia_format_response_time($hours) {
    $hours = floatval($hours);
    
    if ($hours < 1) {
        return round($hours * 60) . ' minutos';
    } elseif ($hours < 24) {
        return round($hours, 1) . ' horas';
    } else {
        return round($hours / 24, 1) . ' días';
    }
}

/**
 * Obtener prioridad de acción
 */
function gurux_btx_ia_get_action_priority($urgency, $satisfaction, $sentiment_score) {
    if ($urgency === 'critical' || $satisfaction === 'risk' || $sentiment_score < 2.0) {
        return array(
            'level' => 'critical',
            'label' => 'Crítica',
            'color' => '#dc3545',
            'time_limit' => '1 hora'
        );
    } elseif ($urgency === 'high' || $satisfaction === 'help' || $sentiment_score < 4.0) {
        return array(
            'level' => 'high',
            'label' => 'Alta',
            'color' => '#fd7e14',
            'time_limit' => '4 horas'
        );
    } elseif ($urgency === 'medium') {
        return array(
            'level' => 'medium',
            'label' => 'Media',
            'color' => '#ffc107',
            'time_limit' => '24 horas'
        );
    } else {
        return array(
            'level' => 'low',
            'label' => 'Baja',
            'color' => '#6c757d',
            'time_limit' => '3 días'
        );
    }
}

/**
 * Generar resumen ejecutivo de métricas
 */
function gurux_btx_ia_generate_executive_summary($metrics) {
    $summary = array();
    
    // Análisis de satisfacción
    $avg_sentiment = floatval($metrics['avg_sentiment']);
    if ($avg_sentiment >= 7.0) {
        $summary[] = array(
            'type' => 'positive',
            'metric' => 'Satisfacción',
            'message' => 'Excelente nivel de satisfacción general (' . $avg_sentiment . '/10)'
        );
    } elseif ($avg_sentiment < 5.0) {
        $summary[] = array(
            'type' => 'negative',
            'metric' => 'Satisfacción',
            'message' => 'Satisfacción por debajo del objetivo (' . $avg_sentiment . '/10)'
        );
    }
    
    // Análisis de casos críticos
    $total_analyses = intval($metrics['total_analyses']);
    $critical_cases = intval($metrics['critical_cases']);
    $critical_percentage = $total_analyses > 0 ? ($critical_cases / $total_analyses) * 100 : 0;
    
    if ($critical_percentage > 15) {
        $summary[] = array(
            'type' => 'warning',
            'metric' => 'Casos Críticos',
            'message' => 'Alto porcentaje de casos críticos (' . round($critical_percentage, 1) . '%)'
        );
    }
    
    return $summary;
}

/**
 * Obtener recomendaciones automáticas del dashboard
 */
function gurux_btx_ia_get_dashboard_recommendations($metrics, $critical_cases) {
    $recommendations = array();
    
    // Satisfacción baja
    if (floatval($metrics['avg_sentiment']) < 6.0) {
        $recommendations[] = array(
            'priority' => 'high',
            'category' => 'Satisfacción',
            'action' => 'Implementar programa de mejora de experiencia del cliente',
            'estimated_impact' => 'Alto'
        );
    }
    
    // Muchos casos críticos pendientes
    if (count($critical_cases['cases']) > 10) {
        $recommendations[] = array(
            'priority' => 'critical',
            'category' => 'Operaciones',
            'action' => 'Asignar recursos adicionales para resolver casos críticos',
            'estimated_impact' => 'Inmediato'
        );
    }
    
    // Casos de riesgo sin atender
    $risk_cases = array_filter($critical_cases['cases'], function($case) {
        return $case['client_satisfaction'] === 'risk';
    });
    
    if (count($risk_cases) > 5) {
        $recommendations[] = array(
            'priority' => 'high',
            'category' => 'Retención',
            'action' => 'Activar protocolo de retención para clientes en riesgo',
            'estimated_impact' => 'Alto'
        );
    }
    
    return $recommendations;
}

/**
 * Formatear datos para widgets del dashboard
 */
function gurux_btx_ia_format_widget_data($data, $widget_type) {
    switch ($widget_type) {
        case 'satisfaction_gauge':
            return array(
                'value' => floatval($data),
                'max' => 10,
                'color' => gurux_btx_ia_get_sentiment_color($data),
                'label' => gurux_btx_ia_get_sentiment_label($data)
            );
            
        case 'cases_donut':
            return gurux_btx_ia_calculate_distribution($data, 'count');
            
        case 'trend_line':
            return gurux_btx_ia_format_trend_data($data, 'date', 'avg_sentiment');
            
        default:
            return $data;
    }
}

/**
 * Generar alertas automáticas del dashboard
 */
function gurux_btx_ia_generate_dashboard_alerts($metrics, $queue_size, $critical_cases_count) {
    $alerts = array();
    
    // Alerta por cola grande
    if ($queue_size > 20) {
        $alerts[] = array(
            'type' => 'warning',
            'title' => 'Cola de Análisis Elevada',
            'message' => "Hay {$queue_size} conversaciones pendientes de análisis",
            'action' => 'Revisar configuración de procesamiento automático'
        );
    }
    
    // Alerta por muchos casos críticos
    if ($critical_cases_count > 15) {
        $alerts[] = array(
            'type' => 'danger',
            'title' => 'Múltiples Casos Críticos',
            'message' => "Se detectaron {$critical_cases_count} casos que requieren atención inmediata",
            'action' => 'Asignar agentes para resolución prioritaria'
        );
    }
    
    // Alerta por satisfacción muy baja
    if (isset($metrics['avg_sentiment']) && floatval($metrics['avg_sentiment']) < 4.0) {
        $alerts[] = array(
            'type' => 'danger',
            'title' => 'Satisfacción Crítica',
            'message' => 'La satisfacción promedio está en niveles críticos',
            'action' => 'Revisar procesos de atención al cliente'
        );
    }
    
    return $alerts;
}


//FIN DE FUNCIONES