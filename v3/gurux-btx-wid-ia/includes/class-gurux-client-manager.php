<?php
/**
 * GuruX Client Manager - Gestión Inteligente de Clientes
 * 
 * Maneja registro, métricas y segmentación de clientes
 * Sincronización bidireccional con Bitrix24
 * 
 * @package GuruX_BTX_IA
 * @version 1.0.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class GuruX_Client_Manager {
    
    /**
     * Instancia única (Singleton)
     */
    private static $instance = null;
    
    /**
     * APIs y servicios
     */
    private $bitrix_api;
    private $logger;
    
    /**
     * Configuración del manager
     */
    private $config;
    
    /**
     * Reglas de segmentación
     */
    private $segmentation_rules;
    
    /**
     * Cache de clientes
     */
    private $client_cache = array();
    
    /**
     * Constructor privado para Singleton
     */
    private function __construct() {
        $this->logger = GuruX_Logger::get_instance();
        $this->bitrix_api = GuruX_BTX_AI_Bitrix_API::get_instance();
        
        $this->load_config();
        $this->load_segmentation_rules();
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
    
    // ============================================================================
    // MÉTODOS PRINCIPALES DE GESTIÓN
    // ============================================================================
    
    /**
     * Obtener cliente por teléfono
     */
    public function get_client_by_phone($phone) {
        $clean_phone = gurux_btx_ia_clean_phone($phone);
        
        // Verificar cache
        if (isset($this->client_cache[$clean_phone])) {
            return $this->client_cache[$clean_phone];
        }
        
        global $wpdb;
        
        $client = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}gurux_btx_ia_clients WHERE phone = %s",
            $clean_phone
        ), ARRAY_A);
        
        if ($client) {
            // Enriquecer con datos calculados
            $enriched_client = $this->enrich_client_data($client);
            
            // Guardar en cache
            $this->client_cache[$clean_phone] = $enriched_client;
            
            return $enriched_client;
        }
        
        return null;
    }
    
    /**
     * Crear nuevo cliente
     */
    public function create_client($client_data) {
        try {
            // Validar datos requeridos
            $validation = $this->validate_client_data($client_data);
            if (!$validation['success']) {
                return $validation;
            }
            
            // Limpiar y formatear datos
            $processed_data = $this->process_client_data($client_data);
            
            // Verificar si ya existe
            $existing = $this->get_client_by_phone($processed_data['phone']);
            if ($existing) {
                return array(
                    'success' => false,
                    'message' => 'Cliente ya existe',
                    'existing_client' => $existing
                );
            }
            
            // Crear en base de datos local
            $local_client_id = $this->save_local_client($processed_data);
            
            // Crear en Bitrix24 si está habilitado
            $bitrix_contact_id = null;
            if ($this->config['sync_with_bitrix']) {
                $bitrix_result = $this->create_bitrix_contact($processed_data);
                if ($bitrix_result['success']) {
                    $bitrix_contact_id = $bitrix_result['contact_id'];
                    
                    // Actualizar ID de Bitrix24 en registro local
                    $this->update_bitrix_id($local_client_id, $bitrix_contact_id);
                }
            }
            
            // Obtener cliente completo creado
            $complete_client = $this->get_client_by_id($local_client_id);
            
            $this->logger->info('Cliente creado exitosamente', array(
                'client_id' => $local_client_id,
                'phone' => $processed_data['phone'],
                'name' => $processed_data['name'],
                'bitrix_contact_id' => $bitrix_contact_id
            ));
            
            return array(
                'success' => true,
                'client_id' => $local_client_id,
                'bitrix_contact_id' => $bitrix_contact_id,
                'client_data' => $complete_client,
                'message' => 'Cliente creado exitosamente'
            );
            
        } catch (Exception $e) {
            $this->logger->error('Error creando cliente', array(
                'error' => $e->getMessage(),
                'client_data' => $client_data
            ));
            
            return array(
                'success' => false,
                'message' => 'Error interno: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * Actualizar métricas del cliente desde análisis
     */
    public function update_client_metrics($client_id, $analysis) {
        if (empty($client_id)) {
            return array(
                'success' => false,
                'message' => 'ID de cliente requerido'
            );
        }
        
        try {
            global $wpdb;
            
            // Obtener datos actuales del cliente
            $current_client = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}gurux_btx_ia_clients WHERE id = %d",
                $client_id
            ), ARRAY_A);
            
            if (!$current_client) {
                return array(
                    'success' => false,
                    'message' => 'Cliente no encontrado'
                );
            }
            
            // Calcular nuevas métricas
            $new_metrics = $this->calculate_updated_metrics($current_client, $analysis);
            
            // Actualizar historial de satisfacción
            $updated_history = $this->update_satisfaction_history($current_client, $analysis);
            
            // Detectar cambios significativos
            $alerts = $this->detect_metric_alerts($current_client, $new_metrics);
            
            // Actualizar en base de datos
            $update_data = array(
                'total_interactions' => $new_metrics['total_interactions'],
                'satisfaction_average' => $new_metrics['satisfaction_average'],
                'last_sentiment_score' => $analysis['sentiment_score'],
                'last_interaction_date' => current_time('mysql'),
                'satisfaction_history' => json_encode($updated_history),
                'client_segment' => $new_metrics['client_segment'],
                'risk_level' => $new_metrics['risk_level'],
                'lifetime_value_estimated' => $new_metrics['lifetime_value_estimated'],
                'updated_at' => current_time('mysql')
            );
            
            $wpdb->update(
                $wpdb->prefix . 'gurux_btx_ia_clients',
                $update_data,
                array('id' => $client_id)
            );
            
            // Limpiar cache
            $this->clear_client_cache($current_client['phone']);
            
            // Procesar alertas si las hay
            if (!empty($alerts)) {
                $this->process_metric_alerts($client_id, $alerts);
            }
            
            $this->logger->info('Métricas de cliente actualizadas', array(
                'client_id' => $client_id,
                'new_sentiment' => $analysis['sentiment_score'],
                'new_average' => $new_metrics['satisfaction_average'],
                'alerts_generated' => count($alerts)
            ));
            
            return array(
                'success' => true,
                'metrics_updated' => array_keys($update_data),
                'alerts_generated' => $alerts,
                'new_segment' => $new_metrics['client_segment']
            );
            
        } catch (Exception $e) {
            $this->logger->error('Error actualizando métricas del cliente', array(
                'client_id' => $client_id,
                'error' => $e->getMessage()
            ));
            
            return array(
                'success' => false,
                'message' => 'Error actualizando métricas: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * Sincronizar cliente con Bitrix24
     */
    public function sync_client_with_bitrix($client_id, $direction = 'bidirectional') {
        global $wpdb;
        
        $client = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}gurux_btx_ia_clients WHERE id = %d",
            $client_id
        ), ARRAY_A);
        
        if (!$client) {
            return array(
                'success' => false,
                'message' => 'Cliente no encontrado'
            );
        }
        
        $sync_results = array(
            'local_to_bitrix' => null,
            'bitrix_to_local' => null
        );
        
        try {
            // Sincronizar desde local a Bitrix24
            if (in_array($direction, array('to_bitrix', 'bidirectional'))) {
                $sync_results['local_to_bitrix'] = $this->sync_local_to_bitrix($client);
            }
            
            // Sincronizar desde Bitrix24 a local
            if (in_array($direction, array('from_bitrix', 'bidirectional'))) {
                $sync_results['bitrix_to_local'] = $this->sync_bitrix_to_local($client);
            }
            
            // Actualizar timestamp de sincronización
            $wpdb->update(
                $wpdb->prefix . 'gurux_btx_ia_clients',
                array('last_sync_at' => current_time('mysql')),
                array('id' => $client_id)
            );
            
            $this->logger->info('Sincronización de cliente completada', array(
                'client_id' => $client_id,
                'direction' => $direction,
                'results' => $sync_results
            ));
            
            return array(
                'success' => true,
                'sync_results' => $sync_results,
                'message' => 'Sincronización completada'
            );
            
        } catch (Exception $e) {
            $this->logger->error('Error en sincronización de cliente', array(
                'client_id' => $client_id,
                'error' => $e->getMessage()
            ));
            
            return array(
                'success' => false,
                'message' => 'Error en sincronización: ' . $e->getMessage()
            );
        }
    }
    
    // ============================================================================
    // MÉTODOS DE ANÁLISIS Y SEGMENTACIÓN
    // ============================================================================
    
    /**
     * Segmentar clientes automáticamente
     */
    public function segment_clients($force_resegment = false) {
        global $wpdb;
        
        $segmentation_results = array(
            'processed' => 0,
            'updated' => 0,
            'segments' => array()
        );
        
        // Obtener clientes para segmentar
        $where_clause = $force_resegment ? '' : "WHERE (client_segment IS NULL OR client_segment = '')";
        
        $clients = $wpdb->get_results(
            "SELECT id, satisfaction_average, total_interactions, lifetime_value_estimated, 
                    last_sentiment_score, risk_level, satisfaction_history
            FROM {$wpdb->prefix}gurux_btx_ia_clients {$where_clause}
            LIMIT 100",
            ARRAY_A
        );
        
        foreach ($clients as $client) {
            $new_segment = $this->calculate_client_segment($client);
            
            if ($new_segment !== ($client['client_segment'] ?? '')) {
                $wpdb->update(
                    $wpdb->prefix . 'gurux_btx_ia_clients',
                    array('client_segment' => $new_segment),
                    array('id' => $client['id'])
                );
                
                $segmentation_results['updated']++;
                
                if (!isset($segmentation_results['segments'][$new_segment])) {
                    $segmentation_results['segments'][$new_segment] = 0;
                }
                $segmentation_results['segments'][$new_segment]++;
            }
            
            $segmentation_results['processed']++;
        }
        
        $this->logger->info('Segmentación de clientes completada', $segmentation_results);
        
        return $segmentation_results;
    }
    
    /**
     * Detectar clientes en riesgo
     */
    public function detect_at_risk_clients($threshold_days = 7) {
        global $wpdb;
        
        $risk_threshold_date = date('Y-m-d H:i:s', strtotime("-{$threshold_days} days"));
        
        $at_risk_clients = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, phone, satisfaction_average, last_sentiment_score, 
                    last_interaction_date, risk_level
            FROM {$wpdb->prefix}gurux_btx_ia_clients 
            WHERE (satisfaction_average < 4.0 
                   OR last_sentiment_score < 3.0 
                   OR risk_level = 'high')
            AND last_interaction_date >= %s
            ORDER BY satisfaction_average ASC, last_sentiment_score ASC
            LIMIT 50",
            $risk_threshold_date
        ), ARRAY_A);
        
        $risk_analysis = array();
        
        foreach ($at_risk_clients as $client) {
            $risk_factors = $this->analyze_risk_factors($client);
            
            $risk_analysis[] = array(
                'client_id' => $client['id'],
                'client_name' => $client['name'],
                'client_phone' => $client['phone'],
                'risk_score' => $this->calculate_risk_score($client),
                'risk_factors' => $risk_factors,
                'recommended_actions' => $this->get_risk_mitigation_actions($risk_factors)
            );
        }
        
        // Ordenar por puntuación de riesgo
        usort($risk_analysis, function($a, $b) {
            return $b['risk_score'] - $a['risk_score'];
        });
        
        return array(
            'total_at_risk' => count($risk_analysis),
            'risk_analysis' => $risk_analysis,
            'threshold_days' => $threshold_days
        );
    }
    
    /**
     * Obtener insights de clientes
     */
    public function get_client_insights($days = 30) {
        global $wpdb;
        
        $date_from = date('Y-m-d', strtotime("-{$days} days"));
        
        // Métricas generales
        $general_stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_clients,
                AVG(satisfaction_average) as avg_satisfaction,
                COUNT(CASE WHEN client_segment = 'vip' THEN 1 END) as vip_clients,
                COUNT(CASE WHEN risk_level = 'high' THEN 1 END) as high_risk_clients,
                AVG(lifetime_value_estimated) as avg_lifetime_value
            FROM {$wpdb->prefix}gurux_btx_ia_clients 
            WHERE created_at >= %s",
            $date_from
        ), ARRAY_A);
        
        // Distribución por segmentos
        $segment_distribution = $wpdb->get_results($wpdb->prepare(
            "SELECT client_segment, COUNT(*) as count, AVG(satisfaction_average) as avg_satisfaction
            FROM {$wpdb->prefix}gurux_btx_ia_clients 
            WHERE created_at >= %s AND client_segment IS NOT NULL
            GROUP BY client_segment",
            $date_from
        ), ARRAY_A);
        
        // Tendencias de satisfacción
        $satisfaction_trends = $this->calculate_satisfaction_trends($days);
        
        // Top clientes por valor
        $top_value_clients = $wpdb->get_results($wpdb->prepare(
            "SELECT name, phone, lifetime_value_estimated, satisfaction_average, client_segment
            FROM {$wpdb->prefix}gurux_btx_ia_clients 
            WHERE lifetime_value_estimated > 0
            ORDER BY lifetime_value_estimated DESC 
            LIMIT 10"
        ), ARRAY_A);
        
        return array(
            'period_days' => $days,
            'general_stats' => $general_stats,
            'segment_distribution' => $segment_distribution,
            'satisfaction_trends' => $satisfaction_trends,
            'top_value_clients' => $top_value_clients
        );
    }
    
    // ============================================================================
    // MÉTODOS DE CONSULTA
    // ============================================================================
    
    /**
     * Buscar clientes por criterios
     */
    public function search_clients($criteria = array(), $limit = 50) {
        global $wpdb;
        
        $where_conditions = array('1=1');
        $params = array();
        
        if (!empty($criteria['name'])) {
            $where_conditions[] = 'name LIKE %s';
            $params[] = '%' . $criteria['name'] . '%';
        }
        
        if (!empty($criteria['phone'])) {
            $where_conditions[] = 'phone LIKE %s';
            $params[] = '%' . gurux_btx_ia_clean_phone($criteria['phone']) . '%';
        }
        
        if (!empty($criteria['segment'])) {
            $where_conditions[] = 'client_segment = %s';
            $params[] = $criteria['segment'];
        }
        
        if (!empty($criteria['risk_level'])) {
            $where_conditions[] = 'risk_level = %s';
            $params[] = $criteria['risk_level'];
        }
        
        if (isset($criteria['satisfaction_min'])) {
            $where_conditions[] = 'satisfaction_average >= %f';
            $params[] = floatval($criteria['satisfaction_min']);
        }
        
        if (isset($criteria['satisfaction_max'])) {
            $where_conditions[] = 'satisfaction_average <= %f';
            $params[] = floatval($criteria['satisfaction_max']);
        }
        
        if (!empty($criteria['last_interaction_days'])) {
            $days_ago = date('Y-m-d H:i:s', strtotime("-{$criteria['last_interaction_days']} days"));
            $where_conditions[] = 'last_interaction_date >= %s';
            $params[] = $days_ago;
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        $params[] = $limit;
        
        $query = "SELECT * FROM {$wpdb->prefix}gurux_btx_ia_clients 
                  WHERE {$where_clause} 
                  ORDER BY last_interaction_date DESC 
                  LIMIT %d";
        
        $results = $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A);
        
        // Enriquecer resultados
        $enriched_results = array();
        foreach ($results as $client) {
            $enriched_results[] = $this->enrich_client_data($client);
        }
        
        return array(
            'success' => true,
            'results' => $enriched_results,
            'total_found' => count($enriched_results),
            'criteria_used' => $criteria
        );
    }
    
    /**
     * Obtener cliente por ID
     */
    public function get_client_by_id($client_id) {
        global $wpdb;
        
        $client = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}gurux_btx_ia_clients WHERE id = %d",
            $client_id
        ), ARRAY_A);
        
        if ($client) {
            return $this->enrich_client_data($client);
        }
        
        return null;
    }
    
    // ============================================================================
    // MÉTODOS PRIVADOS
    // ============================================================================
    
    /**
     * Cargar configuración
     */
    private function load_config() {
        $this->config = array(
            'sync_with_bitrix' => gurux_btx_ia_get_setting('auto_create_contacts', true),
            'auto_segment' => true,
            'satisfaction_history_limit' => 30,
            'risk_calculation_enabled' => true,
            'cache_client_data' => true,
            'sync_interval_hours' => 24
        );
    }
    
    /**
     * Cargar reglas de segmentación
     */
    private function load_segmentation_rules() {
        $this->segmentation_rules = array(
            'vip' => array(
                'satisfaction_min' => 8.0,
                'lifetime_value_min' => 1000,
                'interactions_min' => 5
            ),
            'loyal' => array(
                'satisfaction_min' => 7.0,
                'interactions_min' => 10
            ),
            'at_risk' => array(
                'satisfaction_max' => 4.0,
                'interactions_min' => 2
            ),
            'new' => array(
                'interactions_max' => 2,
                'days_since_first_interaction' => 7
            ),
            'churned' => array(
                'days_since_last_interaction' => 90,
                'satisfaction_max' => 5.0
            )
        );
    }
    
    /**
     * Validar datos del cliente
     */
    private function validate_client_data($client_data) {
        $required_fields = array('phone');
        
        foreach ($required_fields as $field) {
            if (empty($client_data[$field])) {
                return array(
                    'success' => false,
                    'message' => "Campo requerido faltante: {$field}"
                );
            }
        }
        
        // Validar teléfono
        if (!gurux_btx_ia_is_valid_phone($client_data['phone'])) {
            return array(
                'success' => false,
                'message' => 'Formato de teléfono inválido'
            );
        }
        
        // Validar email si se proporciona
        if (!empty($client_data['email']) && !is_email($client_data['email'])) {
            return array(
                'success' => false,
                'message' => 'Formato de email inválido'
            );
        }
        
        return array('success' => true);
    }
    
    /**
     * Procesar datos del cliente
     */
    private function process_client_data($client_data) {
        return array(
            'phone' => gurux_btx_ia_clean_phone($client_data['phone']),
            'name' => gurux_btx_ia_format_client_name($client_data['name'] ?? 'Cliente Sin Nombre'),
            'email' => sanitize_email($client_data['email'] ?? ''),
            'source' => sanitize_text_field($client_data['source'] ?? 'unknown'),
            'initial_segment' => $client_data['segment'] ?? 'new',
            'notes' => sanitize_textarea_field($client_data['notes'] ?? '')
        );
    }
    
    /**
     * Guardar cliente en BD local
     */
    
    private function save_local_client($client_data) {
        global $wpdb;
        
        $record = array(
            'phone' => $client_data['phone'],
            'name' => $client_data['name'],
            'email' => $client_data['email'],
            'source' => $client_data['source'],
            'client_segment' => $client_data['initial_segment'],
            'total_interactions' => 0,
            'satisfaction_average' => 0.0,
            'last_sentiment_score' => 0.0,
            'satisfaction_history' => json_encode(array()),
            'risk_level' => 'low',
            'lifetime_value_estimated' => 0.0,
            'notes' => $client_data['notes'],
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        );
        
        $wpdb->insert(
            $wpdb->prefix . 'gurux_btx_ia_clients',
            $record
        );
        
        return $wpdb->insert_id;
    }

    /**
     * Crear contacto en Bitrix24
     */
    private function create_bitrix_contact($client_data) {
        $contact_data = array(
            'NAME' => $client_data['name'],
            'PHONE' => $client_data['phone'],
            'EMAIL' => $client_data['email'],
            'SOURCE_ID' => 'GURUX_IA',
            'COMMENTS' => 'Cliente registrado por GuruX IA desde ' . $client_data['source']
        );
        
        return $this->bitrix_api->create_contact($contact_data);
    }
    
    /**
     * Actualizar ID de Bitrix24
     */
    private function update_bitrix_id($local_client_id, $bitrix_contact_id) {
        global $wpdb;
        
        $wpdb->update(
            $wpdb->prefix . 'gurux_btx_ia_clients',
            array('bitrix_contact_id' => $bitrix_contact_id),
            array('id' => $local_client_id)
        );
    }
    
    /**
     * Enriquecer datos del cliente
     */
    private function enrich_client_data($client) {
        // Calcular métricas adicionales
        $client['satisfaction_status'] = $this->get_satisfaction_status($client['satisfaction_average']);
        $client['interaction_frequency'] = $this->calculate_interaction_frequency($client);
        $client['days_since_last_interaction'] = $this->calculate_days_since_last_interaction($client);
        $client['satisfaction_trend'] = $this->calculate_satisfaction_trend($client);
        $client['is_vip'] = $client['client_segment'] === 'vip';
        $client['risk_score'] = $this->calculate_risk_score($client);
        
        return $client;
    }
    
    /**
     * Calcular métricas actualizadas
     */
    private function calculate_updated_metrics($current_client, $analysis) {
        $total_interactions = intval($current_client['total_interactions']) + 1;
        
        // Calcular nuevo promedio de satisfacción
        $current_average = floatval($current_client['satisfaction_average']);
        $new_sentiment = floatval($analysis['sentiment_score']);
        
        $satisfaction_average = (($current_average * ($total_interactions - 1)) + $new_sentiment) / $total_interactions;
        $satisfaction_average = round($satisfaction_average, 2);
        
        // Calcular nuevo segmento
        $temp_client = $current_client;
        $temp_client['satisfaction_average'] = $satisfaction_average;
        $temp_client['total_interactions'] = $total_interactions;
        $client_segment = $this->calculate_client_segment($temp_client);
        
        // Calcular nuevo nivel de riesgo
        $risk_level = $this->calculate_risk_level($satisfaction_average, $new_sentiment, $analysis);
        
        // Estimar valor de por vida actualizado
        $lifetime_value_estimated = $this->estimate_lifetime_value($temp_client, $analysis);
        
        return array(
            'total_interactions' => $total_interactions,
            'satisfaction_average' => $satisfaction_average,
            'client_segment' => $client_segment,
            'risk_level' => $risk_level,
            'lifetime_value_estimated' => $lifetime_value_estimated
        );
    }
    
    /**
     * Actualizar historial de satisfacción
     */
    private function update_satisfaction_history($client, $analysis) {
        $current_history = json_decode($client['satisfaction_history'] ?? '[]', true);
        if (!is_array($current_history)) {
            $current_history = array();
        }
        
        // Agregar nueva entrada
        $current_history[] = array(
            'date' => current_time('Y-m-d H:i:s'),
            'sentiment_score' => $analysis['sentiment_score'],
            'category' => $analysis['category'],
            'urgency' => $analysis['urgency_level'],
            'satisfaction' => $analysis['client_satisfaction']
        );
        
        // Mantener solo las últimas N entradas
        $limit = $this->config['satisfaction_history_limit'];
        if (count($current_history) > $limit) {
            $current_history = array_slice($current_history, -$limit);
        }
        
        return $current_history;
    }
    
    /**
     * Detectar alertas en métricas
     */
    private function detect_metric_alerts($old_client, $new_metrics) {
        $alerts = array();
        
        // Alerta por caída drástica en satisfacción
        $satisfaction_drop = $old_client['satisfaction_average'] - $new_metrics['satisfaction_average'];
        if ($satisfaction_drop > 2.0) {
            $alerts[] = array(
                'type' => 'satisfaction_drop',
                'severity' => 'high',
                'message' => "Satisfacción cayó {$satisfaction_drop} puntos"
            );
        }
        
        // Alerta por cambio a segmento de riesgo
        if ($old_client['client_segment'] !== 'at_risk' && $new_metrics['client_segment'] === 'at_risk') {
            $alerts[] = array(
                'type' => 'segment_downgrade',
                'severity' => 'high',
                'message' => 'Cliente movido a segmento de riesgo'
            );
        }
        
        // Alerta por nuevo riesgo alto
        if ($old_client['risk_level'] !== 'high' && $new_metrics['risk_level'] === 'high') {
            $alerts[] = array(
                'type' => 'risk_elevation',
                'severity' => 'critical',
                'message' => 'Cliente elevado a riesgo alto'
            );
        }
        
        return $alerts;
    }
    
    /**
     * Procesar alertas de métricas
     */
    private function process_metric_alerts($client_id, $alerts) {
        foreach ($alerts as $alert) {
            $this->logger->warning("Alerta de cliente: {$alert['message']}", array(
                'client_id' => $client_id,
                'alert_type' => $alert['type'],
                'severity' => $alert['severity']
            ));
            
            // Aquí se pueden agregar más acciones como notificaciones, etc.
        }
    }
    
    /**
     * Calcular segmento del cliente
     */
    private function calculate_client_segment($client) {
        $satisfaction = floatval($client['satisfaction_average']);
        $interactions = intval($client['total_interactions']);
        $lifetime_value = floatval($client['lifetime_value_estimated'] ?? 0);
        
        // Verificar cada segmento según las reglas
        foreach ($this->segmentation_rules as $segment => $rules) {
            $matches_segment = true;
            
            if (isset($rules['satisfaction_min']) && $satisfaction < $rules['satisfaction_min']) {
                $matches_segment = false;
            }
            
            if (isset($rules['satisfaction_max']) && $satisfaction > $rules['satisfaction_max']) {
                $matches_segment = false;
            }
            
            if (isset($rules['interactions_min']) && $interactions < $rules['interactions_min']) {
                $matches_segment = false;
            }
            
            if (isset($rules['interactions_max']) && $interactions > $rules['interactions_max']) {
                $matches_segment = false;
            }
            
            if (isset($rules['lifetime_value_min']) && $lifetime_value < $rules['lifetime_value_min']) {
                $matches_segment = false;
            }
            
            if ($matches_segment) {
                return $segment;
            }
        }
        
        return 'standard'; // Segmento por defecto
    }
    
    /**
     * Calcular nivel de riesgo
     */
    private function calculate_risk_level($satisfaction_avg, $last_sentiment, $analysis) {
        if ($satisfaction_avg < 3.0 || $last_sentiment < 2.0 || $analysis['client_satisfaction'] === 'risk') {
            return 'high';
        }
        
        if ($satisfaction_avg < 5.0 || $last_sentiment < 4.0 || $analysis['urgency_level'] === 'critical') {
            return 'medium';
        }
        
        return 'low';
    }
    
    /**
     * Estimar valor de por vida
     */
    private function estimate_lifetime_value($client, $analysis) {
        $base_value = 100; // Valor base
        
        // Multiplicador por satisfacción
        $satisfaction_multiplier = max(0.1, $client['satisfaction_average'] / 10);
        
        // Multiplicador por interacciones
        $interactions_multiplier = min(3.0, 1 + ($client['total_interactions'] * 0.1));
        
        // Bonus por oportunidades detectadas
        $opportunity_bonus = 1.0;
        if (isset($analysis['opportunity_score']) && $analysis['opportunity_score'] > 7) {
            $opportunity_bonus = 1.5;
        }
        
        return round($base_value * $satisfaction_multiplier * $interactions_multiplier * $opportunity_bonus, 2);
    }
    
    /**
     * Obtener estado de satisfacción
     */
    private function get_satisfaction_status($satisfaction_avg) {
        if ($satisfaction_avg >= 8) return 'excellent';
        if ($satisfaction_avg >= 6) return 'good';
        if ($satisfaction_avg >= 4) return 'average';
        return 'poor';
    }
    
    /**
     * Calcular frecuencia de interacción
     */
    private function calculate_interaction_frequency($client) {
        $total_interactions = intval($client['total_interactions']);
        $days_since_created = $this->calculate_days_since_created($client);
        
        if ($days_since_created <= 0) return 0;
        
        return round($total_interactions / $days_since_created, 2);
    }
    
    /**
     * Calcular días desde última interacción
     */
    private function calculate_days_since_last_interaction($client) {
        if (empty($client['last_interaction_date'])) {
            return null;
        }
        
        $last_interaction = strtotime($client['last_interaction_date']);
        $now = time();
        
        return floor(($now - $last_interaction) / (24 * 60 * 60));
    }
    
    /**
     * Calcular días desde creación
     */
    private function calculate_days_since_created($client) {
        $created = strtotime($client['created_at']);
        $now = time();
        
        return max(1, floor(($now - $created) / (24 * 60 * 60)));
    }
    
    /**
     * Calcular tendencia de satisfacción
     */
    private function calculate_satisfaction_trend($client) {
        $history = json_decode($client['satisfaction_history'] ?? '[]', true);
        
        if (!is_array($history) || count($history) < 2) {
            return 'stable';
        }
        
        $recent = array_slice($history, -5); // Últimas 5 interacciones
        $scores = array_column($recent, 'sentiment_score');
        
        $first_half = array_slice($scores, 0, floor(count($scores) / 2));
        $second_half = array_slice($scores, floor(count($scores) / 2));
        
        $avg_first = array_sum($first_half) / count($first_half);
        $avg_second = array_sum($second_half) / count($second_half);
        
        $difference = $avg_second - $avg_first;
        
        if ($difference > 0.5) return 'improving';
        if ($difference < -0.5) return 'declining';
        return 'stable';
    }
    
    /**
     * Calcular puntuación de riesgo
     */
    private function calculate_risk_score($client) {
        $score = 0;
        
        // Puntuación por satisfacción baja
        if ($client['satisfaction_average'] < 4.0) {
            $score += (4.0 - $client['satisfaction_average']) * 25;
        }
        
        // Puntuación por último sentimiento bajo
        if ($client['last_sentiment_score'] < 3.0) {
            $score += (3.0 - $client['last_sentiment_score']) * 20;
        }
        
        // Puntuación por días sin interacción
        $days_since_last = $this->calculate_days_since_last_interaction($client);
        if ($days_since_last > 30) {
            $score += min(50, $days_since_last - 30);
        }
        
        return min(100, round($score));
    }
    
    /**
     * Analizar factores de riesgo
     */
    private function analyze_risk_factors($client) {
        $factors = array();
        
        if ($client['satisfaction_average'] < 4.0) {
            $factors[] = 'low_satisfaction';
        }
        
        if ($client['last_sentiment_score'] < 3.0) {
            $factors[] = 'negative_last_interaction';
        }
        
        $days_since_last = $this->calculate_days_since_last_interaction($client);
        if ($days_since_last > 30) {
            $factors[] = 'long_silence';
        }
        
        if ($client['risk_level'] === 'high') {
            $factors[] = 'high_risk_classification';
        }
        
        return $factors;
    }
    
    /**
     * Obtener acciones de mitigación de riesgo
     */
    private function get_risk_mitigation_actions($risk_factors) {
        $actions = array();
        
        if (in_array('low_satisfaction', $risk_factors)) {
            $actions[] = 'Contactar para entender problemas y mejorar experiencia';
        }
        
        if (in_array('negative_last_interaction', $risk_factors)) {
            $actions[] = 'Hacer seguimiento inmediato de última interacción negativa';
        }
        
        if (in_array('long_silence', $risk_factors)) {
            $actions[] = 'Reactivar comunicación con oferta especial o check-in';
        }
        
        if (empty($actions)) {
            $actions[] = 'Monitorear de cerca próximas interacciones';
        }
        
        return $actions;
    }
    
    /**
     * Calcular tendencias de satisfacción
     */
    private function calculate_satisfaction_trends($days) {
        global $wpdb;
        
        $trends = array();
        
        for ($i = $days; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            
            $avg = $wpdb->get_var($wpdb->prepare(
                "SELECT AVG(satisfaction_average) 
                FROM {$wpdb->prefix}gurux_btx_ia_clients 
                WHERE DATE(last_interaction_date) = %s",
                $date
            ));
            
            $trends[] = array(
                'date' => $date,
                'avg_satisfaction' => round(floatval($avg), 2)
            );
        }
        
        return $trends;
    }
    
    /**
     * Sincronizar de local a Bitrix24
     */
    private function sync_local_to_bitrix($client) {
        if (empty($client['bitrix_contact_id'])) {
            // Crear nuevo contacto
            return $this->create_bitrix_contact($client);
        } else {
            // Actualizar contacto existente
            $update_data = array(
                'NAME' => $client['name'],
                'EMAIL' => $client['email'],
                'COMMENTS' => "Satisfacción promedio: {$client['satisfaction_average']}/10\nSegmento: {$client['client_segment']}\nRiesgo: {$client['risk_level']}"
            );
            
            return $this->bitrix_api->update_contact($client['bitrix_contact_id'], $update_data);
        }
    }
    
    /**
     * Sincronizar de Bitrix24 a local
     */
    private function sync_bitrix_to_local($client) {
        if (empty($client['bitrix_contact_id'])) {
            return array(
                'success' => false,
                'message' => 'No hay ID de Bitrix24 para sincronizar'
            );
        }
        
        // Esta funcionalidad se puede expandir para obtener datos actualizados de Bitrix24
        return array(
            'success' => true,
            'message' => 'Sincronización desde Bitrix24 completada'
        );
    }
    
    /**
     * Limpiar cache del cliente
     */
    private function clear_client_cache($phone) {
        $clean_phone = gurux_btx_ia_clean_phone($phone);
        unset($this->client_cache[$clean_phone]);
    }

    //FIN DE FUNCIONES 

    

    /**
     * Obtener línea de tiempo de satisfacción del cliente
     */
    public function get_client_satisfaction_timeline($client_id, $days = 30) {
        global $wpdb;
        
        $date_from = date('Y-m-d', strtotime("-{$days} days"));
        
        // Obtener cliente
        $client = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}gurux_btx_ia_clients WHERE id = %d",
            $client_id
        ), ARRAY_A);
        
        if (!$client) {
            return array(
                'success' => false,
                'message' => 'Cliente no encontrado'
            );
        }
        
        // Obtener análisis del cliente
        $analyses = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                a.sentiment_score,
                a.urgency_level,
                a.client_satisfaction,
                a.category,
                a.analyzed_at,
                c.last_message
            FROM {$wpdb->prefix}gurux_btx_ia_analysis a
            INNER JOIN {$wpdb->prefix}gurux_btx_ia_conversations c ON a.conversation_id = c.id
            WHERE c.client_phone = %s 
            AND DATE(a.analyzed_at) >= %s
            ORDER BY a.analyzed_at ASC",
            $client['phone'],
            $date_from
        ), ARRAY_A);
        
        $timeline_data = array();
        $satisfaction_trend = array();
        
        foreach ($analyses as $analysis) {
            $timeline_data[] = array(
                'date' => $analysis['analyzed_at'],
                'sentiment_score' => floatval($analysis['sentiment_score']),
                'urgency_level' => $analysis['urgency_level'],
                'satisfaction' => $analysis['client_satisfaction'],
                'category' => $analysis['category'],
                'message_preview' => gurux_btx_ia_truncate_text($analysis['last_message'], 60)
            );
            
            $satisfaction_trend[] = array(
                'x' => date('Y-m-d', strtotime($analysis['analyzed_at'])),
                'y' => floatval($analysis['sentiment_score'])
            );
        }
        
        return array(
            'success' => true,
            'client_info' => array(
                'id' => $client['id'],
                'name' => $client['name'],
                'phone' => $client['phone'],
                'segment' => $client['client_segment'],
                'current_satisfaction' => $client['satisfaction_average']
            ),
            'timeline' => $timeline_data,
            'trend_data' => $satisfaction_trend,
            'period_days' => $days,
            'total_interactions' => count($timeline_data)
        );
    }

    /**
     * Detección mejorada de clientes en riesgo
     */
    public function detect_at_risk_clients_enhanced($threshold_days = 7, $limit = 20) {
        global $wpdb;
        
        $risk_threshold_date = date('Y-m-d H:i:s', strtotime("-{$threshold_days} days"));
        
        // Query más compleja para detectar clientes en riesgo
        $at_risk_clients = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                c.id,
                c.name,
                c.phone,
                c.email,
                c.satisfaction_average,
                c.last_sentiment_score,
                c.last_interaction_date,
                c.risk_level,
                c.total_interactions,
                c.client_segment,
                TIMESTAMPDIFF(HOUR, c.last_interaction_date, NOW()) as hours_since_last,
                (SELECT COUNT(*) FROM {$wpdb->prefix}gurux_btx_ia_analysis a2 
                 INNER JOIN {$wpdb->prefix}gurux_btx_ia_conversations c2 ON a2.conversation_id = c2.id 
                 WHERE c2.client_phone = c.phone 
                 AND a2.client_satisfaction = 'risk' 
                 AND a2.analyzed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as recent_risk_contacts,
                (SELECT a3.category FROM {$wpdb->prefix}gurux_btx_ia_analysis a3 
                 INNER JOIN {$wpdb->prefix}gurux_btx_ia_conversations c3 ON a3.conversation_id = c3.id 
                 WHERE c3.client_phone = c.phone 
                 ORDER BY a3.analyzed_at DESC LIMIT 1) as last_category
            FROM {$wpdb->prefix}gurux_btx_ia_clients c
            WHERE (c.satisfaction_average < 4.0 
                   OR c.last_sentiment_score < 3.0 
                   OR c.risk_level = 'high'
                   OR TIMESTAMPDIFF(DAY, c.last_interaction_date, NOW()) > 14)
            AND c.last_interaction_date >= %s
            ORDER BY 
                CASE c.risk_level 
                    WHEN 'high' THEN 1 
                    WHEN 'medium' THEN 2 
                    ELSE 3 
                END,
                c.satisfaction_average ASC,
                c.last_sentiment_score ASC
            LIMIT %d",
            $risk_threshold_date,
            $limit
        ), ARRAY_A);
        
        $enhanced_risk_analysis = array();
        
        foreach ($at_risk_clients as $client) {
            $risk_factors = $this->analyze_enhanced_risk_factors($client);
            $risk_score = $this->calculate_enhanced_risk_score($client);
            $recommended_actions = $this->get_enhanced_risk_mitigation_actions($risk_factors, $client);
            
            $enhanced_risk_analysis[] = array(
                'client_id' => $client['id'],
                'client_name' => $client['name'],
                'client_phone' => $client['phone'],
                'client_email' => $client['email'],
                'current_segment' => $client['client_segment'],
                'satisfaction_average' => floatval($client['satisfaction_average']),
                'last_sentiment' => floatval($client['last_sentiment_score']),
                'hours_since_last_contact' => intval($client['hours_since_last']),
                'recent_risk_contacts' => intval($client['recent_risk_contacts']),
                'last_category' => $client['last_category'],
                'risk_score' => $risk_score,
                'risk_level' => $client['risk_level'],
                'risk_factors' => $risk_factors,
                'recommended_actions' => $recommended_actions,
                'urgency' => $this->determine_intervention_urgency($risk_score, $client)
            );
        }
        
        // Ordenar por puntuación de riesgo
        usort($enhanced_risk_analysis, function($a, $b) {
            return $b['risk_score'] - $a['risk_score'];
        });
        
        return array(
            'total_at_risk' => count($enhanced_risk_analysis),
            'risk_analysis' => $enhanced_risk_analysis,
            'threshold_days' => $threshold_days,
            'distribution' => array(
                'critical_risk' => count(array_filter($enhanced_risk_analysis, function($c) { return $c['risk_score'] >= 80; })),
                'high_risk' => count(array_filter($enhanced_risk_analysis, function($c) { return $c['risk_score'] >= 60 && $c['risk_score'] < 80; })),
                'medium_risk' => count(array_filter($enhanced_risk_analysis, function($c) { return $c['risk_score'] >= 40 && $c['risk_score'] < 60; })),
                'low_risk' => count(array_filter($enhanced_risk_analysis, function($c) { return $c['risk_score'] < 40; }))
            )
        );
    }

    /**
     * Insights expandidos de clientes
     */
    public function get_expanded_client_insights($days = 30) {
        global $wpdb;
        
        $date_from = date('Y-m-d', strtotime("-{$days} days"));
        
        // Métricas generales expandidas
        $general_stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_clients,
                AVG(satisfaction_average) as avg_satisfaction,
                COUNT(CASE WHEN client_segment = 'vip' THEN 1 END) as vip_clients,
                COUNT(CASE WHEN client_segment = 'loyal' THEN 1 END) as loyal_clients,
                COUNT(CASE WHEN client_segment = 'at_risk' THEN 1 END) as at_risk_segment,
                COUNT(CASE WHEN risk_level = 'high' THEN 1 END) as high_risk_clients,
                COUNT(CASE WHEN risk_level = 'medium' THEN 1 END) as medium_risk_clients,
                AVG(lifetime_value_estimated) as avg_lifetime_value,
                SUM(lifetime_value_estimated) as total_estimated_value,
                AVG(total_interactions) as avg_interactions_per_client
            FROM {$wpdb->prefix}gurux_btx_ia_clients 
            WHERE created_at >= %s",
            $date_from
        ), ARRAY_A);
        
        // Distribución detallada por segmentos
        $segment_distribution = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                client_segment, 
                COUNT(*) as count, 
                AVG(satisfaction_average) as avg_satisfaction,
                AVG(lifetime_value_estimated) as avg_lifetime_value,
                AVG(total_interactions) as avg_interactions
            FROM {$wpdb->prefix}gurux_btx_ia_clients 
            WHERE created_at >= %s AND client_segment IS NOT NULL
            GROUP BY client_segment
            ORDER BY count DESC",
            $date_from
        ), ARRAY_A);
        
        // Análisis de retención
        $retention_analysis = $this->calculate_client_retention($days);
        
        // Clientes más valiosos
        $top_value_clients = $wpdb->get_results(
            "SELECT 
                name, 
                phone, 
                lifetime_value_estimated, 
                satisfaction_average, 
                client_segment,
                total_interactions,
                last_interaction_date
            FROM {$wpdb->prefix}gurux_btx_ia_clients 
            WHERE lifetime_value_estimated > 0
            ORDER BY lifetime_value_estimated DESC 
            LIMIT 10",
            ARRAY_A
        );
        
        // Análisis de crecimiento
        $growth_analysis = $this->calculate_client_growth($days);
        
        return array(
            'period_days' => $days,
            'general_stats' => $general_stats,
            'segment_distribution' => $segment_distribution,
            'retention_analysis' => $retention_analysis,
            'growth_analysis' => $growth_analysis,
            'top_value_clients' => $top_value_clients,
            'recommendations' => $this->generate_client_insights_recommendations($general_stats, $segment_distribution)
        );
    }

    /**
     * Analizar factores de riesgo mejorados
     */
    private function analyze_enhanced_risk_factors($client) {
        $factors = array();
        
        if ($client['satisfaction_average'] < 4.0) {
            $factors[] = array(
                'factor' => 'low_satisfaction',
                'severity' => 'high',
                'value' => $client['satisfaction_average'],
                'description' => 'Satisfacción promedio muy baja'
            );
        }
        
        if ($client['last_sentiment_score'] < 3.0) {
            $factors[] = array(
                'factor' => 'negative_last_interaction',
                'severity' => 'high',
                'value' => $client['last_sentiment_score'],
                'description' => 'Última interacción muy negativa'
            );
        }
        
        if ($client['hours_since_last'] > 336) { // 14 días
            $factors[] = array(
                'factor' => 'long_silence',
                'severity' => 'medium',
                'value' => round($client['hours_since_last'] / 24, 1),
                'description' => 'Sin contacto por más de 14 días'
            );
        }
        
        if ($client['recent_risk_contacts'] > 2) {
            $factors[] = array(
                'factor' => 'repeated_risk_contacts',
                'severity' => 'critical',
                'value' => $client['recent_risk_contacts'],
                'description' => 'Múltiples contactos de riesgo recientes'
            );
        }
        
        if ($client['risk_level'] === 'high') {
            $factors[] = array(
                'factor' => 'high_risk_classification',
                'severity' => 'high',
                'value' => $client['risk_level'],
                'description' => 'Clasificado como alto riesgo'
            );
        }
        
        return $factors;
    }

    /**
     * Calcular puntuación de riesgo mejorada
     */
    private function calculate_enhanced_risk_score($client) {
        $score = 0;
        
        // Satisfacción baja (0-40 puntos)
        if ($client['satisfaction_average'] < 4.0) {
            $score += (4.0 - $client['satisfaction_average']) * 10;
        }
        
        // Último sentimiento negativo (0-30 puntos)
        if ($client['last_sentiment_score'] < 3.0) {
            $score += (3.0 - $client['last_sentiment_score']) * 10;
        }
        
        // Días sin contacto (0-20 puntos)
        $days_since = $client['hours_since_last'] / 24;
        if ($days_since > 7) {
            $score += min(20, ($days_since - 7) * 2);
        }
        
        // Contactos de riesgo recientes (0-30 puntos)
        $score += min(30, $client['recent_risk_contacts'] * 10);
        
        // Penalización por segmento (0-10 puntos)
        if ($client['client_segment'] === 'at_risk') {
            $score += 10;
        }
        
        return min(100, round($score));
    }

    /**
     * Acciones de mitigación mejoradas
     */
    private function get_enhanced_risk_mitigation_actions($risk_factors, $client) {
        $actions = array();
        
        foreach ($risk_factors as $factor) {
            switch ($factor['factor']) {
                case 'low_satisfaction':
                    $actions[] = array(
                        'action' => 'satisfaction_recovery',
                        'priority' => 'high',
                        'description' => 'Contactar para programa de recuperación de satisfacción',
                        'estimated_time' => '30 minutos'
                    );
                    break;
                    
                case 'negative_last_interaction':
                    $actions[] = array(
                        'action' => 'immediate_follow_up',
                        'priority' => 'critical',
                        'description' => 'Seguimiento inmediato de interacción negativa',
                        'estimated_time' => '15 minutos'
                    );
                    break;
                    
                case 'long_silence':
                    $actions[] = array(
                        'action' => 'reactivation_campaign',
                        'priority' => 'medium',
                        'description' => 'Campaña de reactivación personalizada',
                        'estimated_time' => '20 minutos'
                    );
                    break;
                    
                case 'repeated_risk_contacts':
                    $actions[] = array(
                        'action' => 'escalate_to_supervisor',
                        'priority' => 'critical',
                        'description' => 'Escalar a supervisor para intervención especializada',
                        'estimated_time' => '45 minutos'
                    );
                    break;
            }
        }
        
        if (empty($actions)) {
            $actions[] = array(
                'action' => 'standard_monitoring',
                'priority' => 'low',
                'description' => 'Monitorear próximas interacciones',
                'estimated_time' => '5 minutos'
            );
        }
        
        return $actions;
    }

    /**
     * Determinar urgencia de intervención
     */
    private function determine_intervention_urgency($risk_score, $client) {
        if ($risk_score >= 80 || $client['recent_risk_contacts'] > 2) {
            return 'immediate'; // Dentro de 1 hora
        } elseif ($risk_score >= 60) {
            return 'urgent'; // Dentro de 4 horas
        } elseif ($risk_score >= 40) {
            return 'high'; // Dentro de 24 horas
        } else {
            return 'normal'; // Dentro de 3 días
        }
    }

    /**
     * Calcular retención de clientes
     */
    private function calculate_client_retention($days) {
        global $wpdb;
        
        $total_clients = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}gurux_btx_ia_clients"
        );
        
        $active_clients = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}gurux_btx_ia_clients 
            WHERE last_interaction_date >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
        
        $retention_rate = $total_clients > 0 ? round(($active_clients / $total_clients) * 100, 1) : 0;
        
        return array(
            'total_clients' => $total_clients,
            'active_clients' => $active_clients,
            'retention_rate' => $retention_rate,
            'period_days' => $days
        );
    }

    /**
     * Calcular crecimiento de clientes
     */
    private function calculate_client_growth($days) {
        global $wpdb;
        
        $new_clients = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}gurux_btx_ia_clients 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
        
        $churned_clients = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}gurux_btx_ia_clients 
            WHERE last_interaction_date < DATE_SUB(NOW(), INTERVAL %d DAY)
            AND client_segment != 'churned'",
            $days * 2
        ));
        
        return array(
            'new_clients' => $new_clients,
            'churned_clients' => $churned_clients,
            'net_growth' => $new_clients - $churned_clients,
            'period_days' => $days
        );
    }

    /**
     * Generar recomendaciones de insights
     */
    private function generate_client_insights_recommendations($general_stats, $segment_distribution) {
        $recommendations = array();
        
        if ($general_stats['avg_satisfaction'] < 6.0) {
            $recommendations[] = array(
                'type' => 'satisfaction',
                'priority' => 'high',
                'message' => 'Satisfacción general baja. Implementar programa de mejora de experiencia.'
            );
        }
        
        $at_risk_count = 0;
        foreach ($segment_distribution as $segment) {
            if ($segment['client_segment'] === 'at_risk') {
                $at_risk_count = $segment['count'];
                break;
            }
        }
        
        if ($at_risk_count > 0) {
            $recommendations[] = array(
                'type' => 'retention',
                'priority' => 'critical',
                'message' => "Hay {$at_risk_count} clientes en riesgo. Activar plan de retención."
            );
        }
        
        return $recommendations;
    }

}






// Función helper global
if (!function_exists('gurux_client_manager')) {
    function gurux_client_manager() {
        return GuruX_Client_Manager::get_instance();
    }
}