<?php
/**
 * GuruX Deals Manager - Automatización de Deals y Tareas
 * 
 * Automatiza creación de deals en Bitrix24 basado en análisis IA
 * Gestiona asignación inteligente y creación de tareas
 * 
 * @package GuruX_BTX_IA
 * @version 1.0.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class GuruX_Deals_Manager {
    
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
     * Reglas de negocio
     */
    private $business_rules;
    
    /**
     * Cache de datos Bitrix24
     */
    private $bitrix_cache = array();
    
    /**
     * Constructor privado para Singleton
     */
    private function __construct() {
        $this->logger = GuruX_Logger::get_instance();
        $this->bitrix_api = GuruX_BTX_AI_Bitrix_API::get_instance();
        
        $this->load_config();
        $this->load_business_rules();
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
    // MÉTODOS PRINCIPALES DE DEALS
    // ============================================================================
    
    /**
     * Crear deal desde análisis IA
     */
    public function create_deal_from_analysis($analysis, $client_data) {
        try {
            // Verificar si debe crear deal
            if (!$this->should_create_deal($analysis, $client_data)) {
                return array(
                    'success' => false,
                    'message' => 'Análisis no cumple criterios para crear deal',
                    'criteria_failed' => $this->get_failed_criteria($analysis, $client_data)
                );
            }
            
            // Verificar duplicados recientes
            $duplicate_check = $this->check_recent_deals($client_data);
            if (!$duplicate_check['allow_creation']) {
                return array(
                    'success' => false,
                    'message' => 'Deal duplicado detectado',
                    'existing_deal_id' => $duplicate_check['existing_deal_id']
                );
            }
            
            // Obtener o crear contacto en Bitrix24
            $contact_result = $this->ensure_bitrix_contact($client_data);
            if (!$contact_result['success']) {
                return $contact_result;
            }
            
            // Preparar datos del deal
            $deal_data = $this->prepare_deal_data($analysis, $client_data, $contact_result['contact_id']);
            
            // Crear deal en Bitrix24
            $bitrix_result = $this->bitrix_api->create_deal($deal_data);
            
            if ($bitrix_result['success']) {
                // Registrar en BD local
                $local_deal_id = $this->save_local_deal_record($bitrix_result['deal_id'], $analysis, $client_data, $deal_data);
                
                // Crear tareas asociadas si es necesario
                $tasks_result = $this->create_associated_tasks($bitrix_result['deal_id'], $analysis, $client_data);
                
                $this->logger->log_deal_creation(array(
                    'id' => $bitrix_result['deal_id'],
                    'local_id' => $local_deal_id,
                    'client_name' => $client_data['name'],
                    'value' => $deal_data['OPPORTUNITY'],
                    'stage' => $deal_data['STAGE_ID'],
                    'sentiment_score' => $analysis['sentiment_score']
                ), true);
                
                return array(
                    'success' => true,
                    'deal_id' => $bitrix_result['deal_id'],
                    'local_deal_id' => $local_deal_id,
                    'deal_data' => $deal_data,
                    'tasks_created' => $tasks_result,
                    'message' => 'Deal creado exitosamente'
                );
            } else {
                return $bitrix_result;
            }
            
        } catch (Exception $e) {
            $this->logger->error('Error creando deal desde análisis', array(
                'error' => $e->getMessage(),
                'client_name' => $client_data['name'] ?? 'N/A',
                'sentiment_score' => $analysis['sentiment_score'] ?? 'N/A'
            ));
            
            return array(
                'success' => false,
                'message' => 'Error interno: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * Actualizar deal basado en nuevo análisis
     */
    public function update_deal_from_analysis($deal_id, $analysis, $client_data) {
        // Obtener deal actual
        $current_deal = $this->get_deal_info($deal_id);
        if (!$current_deal['success']) {
            return $current_deal;
        }
        
        // Calcular nueva etapa basada en análisis
        $new_stage = $this->calculate_stage_from_analysis($analysis, $current_deal['deal']);
        
        // Preparar actualizaciones
        $update_data = array();
        
        if ($new_stage !== $current_deal['deal']['STAGE_ID']) {
            $update_data['STAGE_ID'] = $new_stage;
            $update_data['COMMENTS'] = "Etapa actualizada automáticamente por IA. Sentimiento: {$analysis['sentiment_score']}/10";
        }
        
        // Actualizar valor si hay nueva oportunidad
        if (!empty($analysis['opportunity_score']) && $analysis['opportunity_score'] > 7) {
            $current_value = floatval($current_deal['deal']['OPPORTUNITY'] ?? 0);
            $new_value = $this->estimate_deal_value($analysis, $client_data);
            
            if ($new_value > $current_value) {
                $update_data['OPPORTUNITY'] = $new_value;
            }
        }
        
        if (empty($update_data)) {
            return array(
                'success' => true,
                'message' => 'No se requieren actualizaciones',
                'deal_id' => $deal_id
            );
        }
        
        // Actualizar en Bitrix24
        $result = $this->bitrix_api->update_deal($deal_id, $update_data);
        
        if ($result['success']) {
            $this->logger->info('Deal actualizado desde análisis IA', array(
                'deal_id' => $deal_id,
                'updates' => array_keys($update_data),
                'new_sentiment' => $analysis['sentiment_score']
            ));
        }
        
        return $result;
    }
    
    /**
     * Crear tarea desde análisis
     */
    public function create_task_from_analysis($analysis, $client_data) {
        try {
            // Determinar tipo de tarea necesaria
            $task_type = $this->determine_task_type($analysis);
            
            // Obtener plantilla de tarea
            $task_template = $this->get_task_template($task_type);
            
            // Preparar datos de la tarea
            $task_data = $this->prepare_task_data($task_template, $analysis, $client_data);
            
            // Crear tarea en Bitrix24
            $result = $this->bitrix_api->create_task($task_data);
            
            if ($result['success']) {
                // Registrar tarea local
                $this->save_local_task_record($result['task_id'], $analysis, $client_data, $task_data);
                
                $this->logger->info('Tarea creada desde análisis IA', array(
                    'task_id' => $result['task_id'],
                    'task_type' => $task_type,
                    'client_name' => $client_data['name'],
                    'urgency' => $analysis['urgency_level']
                ));
            }
            
            return $result;
            
        } catch (Exception $e) {
            $this->logger->error('Error creando tarea desde análisis', array(
                'error' => $e->getMessage(),
                'client_name' => $client_data['name'] ?? 'N/A'
            ));
            
            return array(
                'success' => false,
                'message' => 'Error creando tarea: ' . $e->getMessage()
            );
        }
    }
    
    // ============================================================================
    // MÉTODOS DE CONSULTA Y GESTIÓN
    // ============================================================================
    
    /**
     * Obtener deals creados automáticamente
     */
    public function get_auto_created_deals($limit = 50, $filters = array()) {
        global $wpdb;
        
        $where_conditions = array('auto_created = 1');
        $params = array();
        
        if (!empty($filters['date_from'])) {
            $where_conditions[] = 'DATE(created_at) >= %s';
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['sentiment_min'])) {
            $where_conditions[] = 'sentiment_score >= %f';
            $params[] = floatval($filters['sentiment_min']);
        }
        
        if (!empty($filters['stage'])) {
            $where_conditions[] = 'current_stage = %s';
            $params[] = $filters['stage'];
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        $params[] = $limit;
        
        $query = "SELECT * FROM {$wpdb->prefix}gurux_btx_ia_deals 
                  WHERE {$where_clause} 
                  ORDER BY created_at DESC 
                  LIMIT %d";
        
        $results = $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A);
        
        return array(
            'success' => true,
            'deals' => $results,
            'total_found' => count($results)
        );
    }
    
    /**
     * Obtener estadísticas de deals
     */
    public function get_deals_stats($days = 30) {
        global $wpdb;
        
        $date_from = date('Y-m-d', strtotime("-{$days} days"));
        
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_deals,
                COUNT(CASE WHEN auto_created = 1 THEN 1 END) as auto_created_deals,
                AVG(estimated_value) as avg_deal_value,
                SUM(estimated_value) as total_pipeline_value,
                COUNT(CASE WHEN current_stage LIKE '%WON%' THEN 1 END) as won_deals
            FROM {$wpdb->prefix}gurux_btx_ia_deals 
            WHERE DATE(created_at) >= %s",
            $date_from
        ), ARRAY_A);
        
        // Distribución por etapas
        $stage_distribution = $wpdb->get_results($wpdb->prepare(
            "SELECT current_stage, COUNT(*) as count
            FROM {$wpdb->prefix}gurux_btx_ia_deals 
            WHERE DATE(created_at) >= %s
            GROUP BY current_stage",
            $date_from
        ), ARRAY_A);
        
        return array(
            'period_days' => $days,
            'summary' => $stats,
            'stage_distribution' => $stage_distribution
        );
    }
    
    /**
     * Sincronizar deals con Bitrix24
     */
    public function sync_deals_with_bitrix() {
        global $wpdb;
        
        // Obtener deals pendientes de sincronización
        $pending_deals = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}gurux_btx_ia_deals 
            WHERE sync_status = 'pending' OR last_sync_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
            LIMIT 20",
            ARRAY_A
        );
        
        $sync_results = array(
            'processed' => 0,
            'updated' => 0,
            'errors' => 0,
            'details' => array()
        );
        
        foreach ($pending_deals as $local_deal) {
            try {
                // Obtener estado actual en Bitrix24
                $bitrix_deals = $this->bitrix_api->find_deals_by_contact($local_deal['bitrix_contact_id'], 5);
                
                if ($bitrix_deals['success']) {
                    // Buscar deal correspondiente
                    $matching_deal = null;
                    foreach ($bitrix_deals['deals'] as $deal) {
                        if ($deal['ID'] == $local_deal['bitrix_deal_id']) {
                            $matching_deal = $deal;
                            break;
                        }
                    }
                    
                    if ($matching_deal) {
                        // Actualizar datos locales
                        $wpdb->update(
                            $wpdb->prefix . 'gurux_btx_ia_deals',
                            array(
                                'current_stage' => $matching_deal['STAGE_ID'],
                                'current_value' => floatval($matching_deal['OPPORTUNITY']),
                                'sync_status' => 'synced',
                                'last_sync_at' => current_time('mysql')
                            ),
                            array('id' => $local_deal['id'])
                        );
                        
                        $sync_results['updated']++;
                    }
                }
                
                $sync_results['processed']++;
                
            } catch (Exception $e) {
                $sync_results['errors']++;
                $sync_results['details'][] = array(
                    'deal_id' => $local_deal['id'],
                    'error' => $e->getMessage()
                );
            }
        }
        
        $this->logger->info('Sincronización de deals completada', $sync_results);
        
        return $sync_results;
    }
    
    // ============================================================================
    // MÉTODOS PRIVADOS
    // ============================================================================
    
    /**
     * Cargar configuración
     */
    private function load_config() {
        $this->config = array(
            'auto_create_enabled' => gurux_btx_ia_get_setting('auto_create_deals', true),
            'min_sentiment_threshold' => gurux_btx_ia_get_setting('sentiment_threshold', 3.0),
            'duplicate_window_hours' => 24,
            'default_pipeline' => gurux_btx_ia_get_setting('default_pipeline', ''),
            'default_currency' => 'USD',
            'auto_assign_enabled' => true,
            'create_follow_tasks' => gurux_btx_ia_get_setting('auto_create_tasks', true)
        );
    }
    
    /**
     * Cargar reglas de negocio
     */
    private function load_business_rules() {
        $this->business_rules = array(
            'creation_criteria' => array(
                'min_sentiment' => $this->config['min_sentiment_threshold'],
                'exclude_categories' => array('spam', 'test', 'cancelacion'),
                'require_phone' => true,
                'business_hours_only' => false
            ),
            'stage_mapping' => array(
                'risk' => 'NEW',                    // Cliente en riesgo -> Atención inmediata
                'help' => 'PREPARATION',            // Necesita ayuda -> Soporte
                'neutral' => 'PREPAYMENT_INVOICE',  // Neutral -> Seguimiento comercial
                'satisfied' => 'EXECUTING'          // Satisfecho -> Oportunidad activa
            ),
            'assignment_rules' => array(
                'critical_urgency' => 'supervisor',
                'high_value_client' => 'senior_sales',
                'technical_category' => 'support_team',
                'sales_opportunity' => 'sales_team',
                'default' => 'available_agent'
            ),
            'value_estimation' => array(
                'base_value' => 100,
                'sentiment_multiplier' => array(
                    'high' => 2.0,      // Sentimiento > 8
                    'medium' => 1.2,    // Sentimiento 5-8
                    'low' => 0.5        // Sentimiento < 5
                ),
                'urgency_modifier' => array(
                    'critical' => 0.8,  // Urgencia crítica reduce valor
                    'high' => 1.1,
                    'medium' => 1.0,
                    'low' => 1.0
                )
            )
        );
    }
    
    /**
     * Verificar si debe crear deal
     */
    private function should_create_deal($analysis, $client_data) {
        if (!$this->config['auto_create_enabled']) {
            return false;
        }
        
        $criteria = $this->business_rules['creation_criteria'];
        
        // Verificar sentimiento mínimo
        if ($analysis['sentiment_score'] < $criteria['min_sentiment']) {
            return false;
        }
        
        // Verificar categorías excluidas
        if (in_array(strtolower($analysis['category']), $criteria['exclude_categories'])) {
            return false;
        }
        
        // Verificar teléfono si es requerido
        if ($criteria['require_phone'] && empty($client_data['phone'])) {
            return false;
        }
        
        // Verificar horario laboral si es requerido
        if ($criteria['business_hours_only'] && !gurux_btx_ia_is_business_hours()) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Obtener criterios fallidos
     */
    private function get_failed_criteria($analysis, $client_data) {
        $failed = array();
        $criteria = $this->business_rules['creation_criteria'];
        
        if ($analysis['sentiment_score'] < $criteria['min_sentiment']) {
            $failed[] = "Sentimiento muy bajo: {$analysis['sentiment_score']} < {$criteria['min_sentiment']}";
        }
        
        if (in_array(strtolower($analysis['category']), $criteria['exclude_categories'])) {
            $failed[] = "Categoría excluida: {$analysis['category']}";
        }
        
        if ($criteria['require_phone'] && empty($client_data['phone'])) {
            $failed[] = "Teléfono requerido pero no proporcionado";
        }
        
        return $failed;
    }
    
    /**
     * Verificar deals duplicados recientes
     */
    private function check_recent_deals($client_data) {
        if (empty($client_data['phone'])) {
            return array('allow_creation' => true);
        }
        
        global $wpdb;
        
        $hours_ago = date('Y-m-d H:i:s', strtotime("-{$this->config['duplicate_window_hours']} hours"));
        
        $recent_deal = $wpdb->get_row($wpdb->prepare(
            "SELECT bitrix_deal_id FROM {$wpdb->prefix}gurux_btx_ia_deals 
            WHERE client_phone = %s AND created_at > %s
            ORDER BY created_at DESC LIMIT 1",
            $client_data['phone'],
            $hours_ago
        ));
        
        if ($recent_deal) {
            return array(
                'allow_creation' => false,
                'existing_deal_id' => $recent_deal->bitrix_deal_id
            );
        }
        
        return array('allow_creation' => true);
    }
    
    /**
     * Asegurar contacto en Bitrix24
     */
    private function ensure_bitrix_contact($client_data) {
        // Buscar contacto existente
        $existing_contact = $this->bitrix_api->find_contact_by_phone($client_data['phone']);
        
        if ($existing_contact['success']) {
            return array(
                'success' => true,
                'contact_id' => $existing_contact['contact']['ID'],
                'action' => 'found_existing'
            );
        }
        
        // Crear nuevo contacto
        $contact_data = array(
            'NAME' => $client_data['name'],
            'PHONE' => $client_data['phone'],
            'EMAIL' => $client_data['email'] ?? '',
            'SOURCE_ID' => 'GURUX_IA',
            'COMMENTS' => 'Contacto creado automáticamente por GuruX IA'
        );
        
        $create_result = $this->bitrix_api->create_contact($contact_data);
        
        if ($create_result['success']) {
            return array(
                'success' => true,
                'contact_id' => $create_result['contact_id'],
                'action' => 'created_new'
            );
        }
        
        return $create_result;
    }
    
    /**
     * Preparar datos del deal
     */
    private function prepare_deal_data($analysis, $client_data, $contact_id) {
        $stage_id = $this->map_satisfaction_to_stage($analysis['client_satisfaction']);
        $estimated_value = $this->estimate_deal_value($analysis, $client_data);
        $responsible_id = $this->assign_responsible($analysis, $client_data);
        
        $deal_data = array(
            'TITLE' => $this->generate_deal_title($analysis, $client_data),
            'CONTACT_ID' => $contact_id,
            'STAGE_ID' => $stage_id,
            'OPPORTUNITY' => $estimated_value,
            'CURRENCY_ID' => $this->config['default_currency'],
            'PROBABILITY' => $this->calculate_probability($analysis),
            'ASSIGNED_BY_ID' => $responsible_id,
            'SOURCE_ID' => 'GURUX_IA',
            'OPENED' => 'Y',
            'COMMENTS' => $this->generate_deal_comments($analysis)
        );
        
        // Agregar pipeline si está configurado
        if (!empty($this->config['default_pipeline'])) {
            $deal_data['CATEGORY_ID'] = $this->config['default_pipeline'];
        }
        
        return $deal_data;
    }
    
    /**
     * Mapear satisfacción a etapa
     */
    private function map_satisfaction_to_stage($satisfaction) {
        $mapping = $this->business_rules['stage_mapping'];
        return $mapping[$satisfaction] ?? $mapping['neutral'];
    }
    
    /**
     * Estimar valor del deal
     */
    private function estimate_deal_value($analysis, $client_data) {
        $base_value = $this->business_rules['value_estimation']['base_value'];
        
        // Multiplicador por sentimiento
        $sentiment_score = $analysis['sentiment_score'];
        if ($sentiment_score >= 8) {
            $sentiment_multiplier = $this->business_rules['value_estimation']['sentiment_multiplier']['high'];
        } elseif ($sentiment_score >= 5) {
            $sentiment_multiplier = $this->business_rules['value_estimation']['sentiment_multiplier']['medium'];
        } else {
            $sentiment_multiplier = $this->business_rules['value_estimation']['sentiment_multiplier']['low'];
        }
        
        // Modificador por urgencia
        $urgency_modifier = $this->business_rules['value_estimation']['urgency_modifier'][$analysis['urgency_level']] ?? 1.0;
        
        // Bonus por cliente recurrente
        $client_bonus = 1.0;
        if (!empty($client_data['total_purchases']) && $client_data['total_purchases'] > 3) {
            $client_bonus = 1.5;
        }
        
        // Bonus por oportunidades detectadas
        $opportunity_bonus = 1.0;
        if (stripos($analysis['opportunities'], 'compra') !== false || 
            stripos($analysis['opportunities'], 'adquirir') !== false) {
            $opportunity_bonus = 1.8;
        }
        
        $final_value = $base_value * $sentiment_multiplier * $urgency_modifier * $client_bonus * $opportunity_bonus;
        
        return round($final_value, 2);
    }
    
    /**
     * Asignar responsable
     */
    private function assign_responsible($analysis, $client_data) {
        $rules = $this->business_rules['assignment_rules'];
        
        // Urgencia crítica -> Supervisor
        if ($analysis['urgency_level'] === 'critical') {
            return $this->get_user_id_by_role('supervisor');
        }
        
        // Cliente VIP -> Ventas senior
        if (!empty($client_data['is_vip']) && $client_data['is_vip']) {
            return $this->get_user_id_by_role('senior_sales');
        }
        
        // Problema técnico -> Soporte
        if (stripos($analysis['category'], 'técnico') !== false || 
            stripos($analysis['category'], 'soporte') !== false) {
            return $this->get_user_id_by_role('support_team');
        }
        
        // Oportunidad de venta -> Ventas
        if ($analysis['client_satisfaction'] === 'satisfied' && $analysis['sentiment_score'] > 7) {
            return $this->get_user_id_by_role('sales_team');
        }
        
        // Por defecto
        return $this->get_user_id_by_role('available_agent');
    }
    
    /**
     * Obtener ID de usuario por rol
     */
    private function get_user_id_by_role($role) {
        // Esta función se puede expandir con mapeo real de usuarios
        // Por ahora retornar el usuario actual
        return $this->bitrix_api->get_current_user_id();
    }
    
    /**
     * Generar título del deal
     */
    private function generate_deal_title($analysis, $client_data) {
        $sentiment_label = $analysis['sentiment_score'] >= 7 ? 'Oportunidad' : 'Seguimiento';
        $urgency_prefix = $analysis['urgency_level'] === 'critical' ? 'URGENTE: ' : '';
        
        return $urgency_prefix . $sentiment_label . ' - ' . $client_data['name'] . ' (' . $analysis['category'] . ')';
    }
    
    /**
     * Calcular probabilidad
     */
    private function calculate_probability($analysis) {
        $base_probability = 50;
        
        // Ajustar por sentimiento
        $sentiment_adjustment = ($analysis['sentiment_score'] - 5) * 10;
        
        // Ajustar por satisfacción
        $satisfaction_adjustments = array(
            'risk' => -30,
            'help' => -10,
            'neutral' => 0,
            'satisfied' => +20
        );
        
        $satisfaction_adjustment = $satisfaction_adjustments[$analysis['client_satisfaction']] ?? 0;
        
        $final_probability = $base_probability + $sentiment_adjustment + $satisfaction_adjustment;
        
        return max(1, min(100, round($final_probability)));
    }
    
    /**
     * Generar comentarios del deal
     */
    private function generate_deal_comments($analysis) {
        $comments = array();
        
        $comments[] = "Deal creado automáticamente por GuruX IA";
        $comments[] = "Sentimiento del cliente: {$analysis['sentiment_score']}/10";
        $comments[] = "Nivel de urgencia: {$analysis['urgency_level']}";
        $comments[] = "Satisfacción: {$analysis['client_satisfaction']}";
        
        if (!empty($analysis['opportunities'])) {
            $comments[] = "Oportunidades: " . gurux_btx_ia_truncate_text($analysis['opportunities'], 100);
        }
        
        if (!empty($analysis['recommendations'])) {
            $comments[] = "Recomendaciones IA: " . gurux_btx_ia_truncate_text($analysis['recommendations'], 100);
        }
        
        return implode("\n", $comments);
    }
    
    /**
     * Guardar registro local del deal
     */
    private function save_local_deal_record($bitrix_deal_id, $analysis, $client_data, $deal_data) {
        global $wpdb;
        
        $local_record = array(
            'bitrix_deal_id' => $bitrix_deal_id,
            'bitrix_contact_id' => $deal_data['CONTACT_ID'],
            'client_phone' => $client_data['phone'],
            'client_name' => $client_data['name'],
            'sentiment_score' => $analysis['sentiment_score'],
            'urgency_level' => $analysis['urgency_level'],
            'client_satisfaction' => $analysis['client_satisfaction'],
            'category' => $analysis['category'],
            'estimated_value' => $deal_data['OPPORTUNITY'],
            'current_stage' => $deal_data['STAGE_ID'],
            'current_value' => $deal_data['OPPORTUNITY'],
            'responsible_id' => $deal_data['ASSIGNED_BY_ID'],
            'auto_created' => 1,
            'sync_status' => 'synced',
            'created_at' => current_time('mysql'),
            'last_sync_at' => current_time('mysql')
        );
        
        $wpdb->insert(
            $wpdb->prefix . 'gurux_btx_ia_deals',
            $local_record
        );
        
        return $wpdb->insert_id;
    }
    
    /**
     * Crear tareas asociadas
     */
    private function create_associated_tasks($deal_id, $analysis, $client_data) {
        if (!$this->config['create_follow_tasks']) {
            return array('tasks_created' => 0);
        }
        
        $tasks_created = array();
        
        // Tarea de seguimiento inmediato si es crítico
        if ($analysis['urgency_level'] === 'critical' || $analysis['client_satisfaction'] === 'risk') {
            $task_result = $this->create_task_from_analysis($analysis, $client_data);
            if ($task_result['success']) {
                $tasks_created[] = $task_result['task_id'];
            }
        }
        
        // Tarea de seguimiento estándar
        $follow_task = $this->create_standard_followup_task($deal_id, $analysis, $client_data);
        if ($follow_task['success']) {
            $tasks_created[] = $follow_task['task_id'];
        }
        
        return array(
            'tasks_created' => count($tasks_created),
            'task_ids' => $tasks_created
        );
    }
    
    /**
     * Crear tarea de seguimiento estándar
     */
    private function create_standard_followup_task($deal_id, $analysis, $client_data) {
        $deadline = $this->calculate_followup_deadline($analysis['urgency_level']);
        
        $task_data = array(
            'TITLE' => "Seguimiento - {$client_data['name']} (Deal #{$deal_id})",
            'DESCRIPTION' => "Realizar seguimiento basado en análisis IA.\n\nRecomendaciones: {$analysis['recommendations']}",
            'RESPONSIBLE_ID' => $this->assign_responsible($analysis, $client_data),
            'DEADLINE' => $deadline,
            'PRIORITY' => gurux_btx_ia_get_task_priority_by_urgency($analysis['urgency_level']),
            'UF_CRM_TASK' => array("D_{$deal_id}") // Asociar con deal
        );
        
        return $this->bitrix_api->create_task($task_data);
    }
    
    /**
     * Calcular deadline de seguimiento
     */
    private function calculate_followup_deadline($urgency_level) {
        $deadlines = array(
            'critical' => '+30 minutes',
            'high' => '+4 hours',
            'medium' => '+1 day',
            'low' => '+3 days'
        );
        
        $deadline_offset = $deadlines[$urgency_level] ?? '+1 day';
        
        return date('Y-m-d H:i:s', strtotime($deadline_offset));
    }
    
    /**
     * Determinar tipo de tarea
     */
    private function determine_task_type($analysis) {
        if ($analysis['urgency_level'] === 'critical' || $analysis['client_satisfaction'] === 'risk') {
            return 'urgent_intervention';
        }
        
        if ($analysis['client_satisfaction'] === 'help') {
            return 'technical_support';
        }
        
        if ($analysis['client_satisfaction'] === 'satisfied' && $analysis['sentiment_score'] > 7) {
            return 'sales_opportunity';
        }
        
        return 'standard_followup';
    }
    
    /**
     * Obtener plantilla de tarea
     */
    private function get_task_template($task_type) {
        $templates = array(
            'urgent_intervention' => array(
                'title_prefix' => 'URGENTE: Intervención requerida',
                'priority' => 2,
                'deadline_offset' => '+30 minutes'
            ),
            'technical_support' => array(
                'title_prefix' => 'Soporte técnico',
                'priority' => 1,
                'deadline_offset' => '+2 hours'
            ),
            'sales_opportunity' => array(
                'title_prefix' => 'Oportunidad de venta',
                'priority' => 1,
                'deadline_offset' => '+4 hours'
            ),
            'standard_followup' => array(
                'title_prefix' => 'Seguimiento estándar',
                'priority' => 1,
                'deadline_offset' => '+1 day'
            )
        );
        
        return $templates[$task_type] ?? $templates['standard_followup'];
    }
    
    /**
     * Preparar datos de tarea
     */
    private function prepare_task_data($template, $analysis, $client_data) {
        $deadline = date('Y-m-d H:i:s', strtotime($template['deadline_offset']));
        
        return array(
            'TITLE' => $template['title_prefix'] . ' - ' . $client_data['name'],
            'DESCRIPTION' => $this->generate_task_description($analysis, $client_data),
            'RESPONSIBLE_ID' => $this->assign_responsible($analysis, $client_data),
            'DEADLINE' => $deadline,
            'PRIORITY' => $template['priority']
        );
    }
    
    /**
     * Generar descripción de tarea
     */
    private function generate_task_description($analysis, $client_data) {
        $description = array();
        
        $description[] = "Cliente: {$client_data['name']} ({$client_data['phone']})";
        $description[] = "Sentimiento: {$analysis['sentiment_score']}/10";
        $description[] = "Urgencia: {$analysis['urgency_level']}";
        $description[] = "Categoría: {$analysis['category']}";
        $description[] = "";
        $description[] = "Recomendaciones IA:";
        $description[] = $analysis['recommendations'];
        
        if (!empty($analysis['next_action'])) {
            $description[] = "";
            $description[] = "Siguiente acción sugerida:";
            $description[] = $analysis['next_action'];
        }
        
        return implode("\n", $description);
    }
    
    /**
     * Obtener información del deal
     */
    private function get_deal_info($deal_id) {
        // Esta función se puede expandir para obtener info detallada del deal
        return array(
            'success' => true,
            'deal' => array('STAGE_ID' => 'NEW', 'OPPORTUNITY' => 100)
        );
    }
    
    /**
     * Calcular etapa desde análisis
     */
    private function calculate_stage_from_analysis($analysis, $current_deal) {
        return $this->map_satisfaction_to_stage($analysis['client_satisfaction']);
    }
    
    /**
     * Guardar registro de tarea local
     */
    private function save_local_task_record($task_id, $analysis, $client_data, $task_data) {
        global $wpdb;
        
        $wpdb->insert(
            $wpdb->prefix . 'gurux_btx_ia_tasks',
            array(
                'bitrix_task_id' => $task_id,
                'client_phone' => $client_data['phone'],
                'task_type' => $this->determine_task_type($analysis),
                'priority' => $task_data['PRIORITY'],
                'created_from_analysis' => 1,
                'created_at' => current_time('mysql')
            )
        );
    }
}

// Función helper global
if (!function_exists('gurux_deals_manager')) {
    function gurux_deals_manager() {
        return GuruX_Deals_Manager::get_instance();
    }
}