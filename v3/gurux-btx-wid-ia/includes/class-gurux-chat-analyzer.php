<?php
/**
 * GuruX Chat Analyzer - Motor Integrador Principal
 * 
 * Procesa conversaciones, ejecuta análisis IA y triggerea automatización
 * Punto central de integración entre Claude AI, Bitrix24 y base de datos
 * 
 * @package GuruX_BTX_IA
 * @version 1.0.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class GuruX_Chat_Analyzer {
    
    /**
     * Instancia única (Singleton)
     */
    private static $instance = null;
    
    /**
     * APIs y managers
     */
    private $claude_api;
    private $deals_manager;
    private $client_manager;
    private $logger;
    
    /**
     * Configuración del analyzer
     */
    private $config;
    
    /**
     * Cache de análisis recientes
     */
    private $analysis_cache = array();
    private $max_cache_size = 100;



    /**
     * Constructor privado para Singleton
     */
    private function __construct() {
        $this->logger = GuruX_Logger::get_instance();
        $this->claude_api = GuruX_Claude_API::get_instance();
        $this->deals_manager = GuruX_Deals_Manager::get_instance();
        $this->client_manager = GuruX_Client_Manager::get_instance();
        
        $this->load_config();
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
    // MÉTODOS PRINCIPALES DE ANÁLISIS
    // ============================================================================
    
    /**
     * Analizar conversación completa (método principal)
     */
    public function analyze($conversation_data, $client_phone = null, $options = array()) {
        $start_time = microtime(true);
        
        try {
            // Validar entrada
            $validation = $this->validate_input($conversation_data, $client_phone);
            if (!$validation['success']) {
                return $validation;
            }
            
            // Preparar conversación
            $processed_conversation = $this->prepare_conversation($conversation_data);
            
            // Obtener o registrar cliente
            $client_data = $this->get_or_create_client($client_phone, $options);
            
            // Verificar análisis reciente duplicado
            if ($this->has_recent_analysis($client_phone, $processed_conversation['text'])) {
                $this->logger->debug('Análisis duplicado detectado', array(
                    'client_phone' => $client_phone
                ));
                
                return $this->get_recent_analysis($client_phone);
            }
            
            // Guardar conversación en BD
            $conversation_id = $this->save_conversation($processed_conversation, $client_data);
            
            // Seleccionar plantilla de análisis
            $template = $this->select_analysis_template($options, $client_data);
            
            // Ejecutar análisis IA
            $ai_result = $this->claude_api->analyze_conversation(
                $processed_conversation['text'], 
                $template,
                $this->build_client_context($client_data)
            );
            
            if (!$ai_result['success']) {
                $this->logger->error('Análisis IA falló', array(
                    'conversation_id' => $conversation_id,
                    'error' => $ai_result['message']
                ));
                
                return $ai_result;
            }
            
            // Enriquecer análisis con datos adicionales
            $enriched_analysis = $this->enrich_analysis($ai_result['analysis'], $client_data, $processed_conversation);
            
            // Guardar análisis en BD
            $analysis_id = $this->save_analysis($conversation_id, $enriched_analysis, $ai_result['metadata']);
            
            // Actualizar métricas del cliente
            $this->client_manager->update_client_metrics($client_data['id'], $enriched_analysis);
            
            // Triggerar automatización si está habilitada
            $automation_result = null;
            if ($this->config['enable_automation']) {
                $automation_result = $this->trigger_automation($analysis_id, $enriched_analysis, $client_data);
            }
            
            // Preparar resultado final
            $execution_time = round((microtime(true) - $start_time) * 1000, 2);
            
            $result = array(
                'success' => true,
                'analysis_id' => $analysis_id,
                'conversation_id' => $conversation_id,
                'client_id' => $client_data['id'],
                'analysis' => $enriched_analysis,
                'automation' => $automation_result,
                'metadata' => array(
                    'execution_time' => $execution_time,
                    'template_used' => $template,
                    'ai_cost' => $ai_result['metadata']['cost'],
                    'processed_at' => current_time('mysql')
                )
            );
            
            // Cache para evitar duplicados
            $this->cache_analysis_result($client_phone, $result);
            
            $this->logger->info('Análisis completo exitoso', array(
                'analysis_id' => $analysis_id,
                'client_phone' => $client_phone,
                'sentiment_score' => $enriched_analysis['sentiment_score'],
                'execution_time' => $execution_time . 'ms'
            ));
            
            return $result;
            
        } catch (Exception $e) {
            $this->logger->error('Error en análisis de conversación', array(
                'error' => $e->getMessage(),
                'client_phone' => $client_phone,
                'trace' => $e->getTraceAsString()
            ));
            
            return array(
                'success' => false,
                'message' => 'Error interno en análisis: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * Re-analizar conversación existente
     */
    public function reanalyze($conversation_id, $force_new_template = null) {
        global $wpdb;
        
        // Obtener conversación original
        $conversation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}gurux_btx_ia_conversations WHERE id = %d",
            $conversation_id
        ), ARRAY_A);
        
        if (!$conversation) {
            return array(
                'success' => false,
                'message' => 'Conversación no encontrada'
            );
        }
        
        // Re-analizar con nuevos parámetros
        $options = array();
        if ($force_new_template) {
            $options['force_template'] = $force_new_template;
        }
        
        $result = $this->analyze(
            json_decode($conversation['conversation_data'], true),
            $conversation['client_phone'],
            $options
        );
        
        if ($result['success']) {
            $this->logger->info('Re-análisis completado', array(
                'original_conversation_id' => $conversation_id,
                'new_analysis_id' => $result['analysis_id']
            ));
        }
        
        return $result;
    }
    
    /**
     * Análisis masivo de conversaciones pendientes
     */
    public function analyze_pending_conversations($limit = 10) {
        global $wpdb;
        
        $pending_conversations = $wpdb->get_results($wpdb->prepare(
            "SELECT c.* FROM {$wpdb->prefix}gurux_btx_ia_conversations c
            LEFT JOIN {$wpdb->prefix}gurux_btx_ia_analysis a ON c.id = a.conversation_id
            WHERE a.id IS NULL AND c.status = 'pending'
            ORDER BY c.created_at ASC
            LIMIT %d",
            $limit
        ), ARRAY_A);
        
        $results = array(
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'details' => array()
        );
        
        foreach ($pending_conversations as $conversation) {
            $conversation_data = json_decode($conversation['conversation_data'], true);
            
            $result = $this->analyze($conversation_data, $conversation['client_phone']);
            
            $results['processed']++;
            
            if ($result['success']) {
                $results['successful']++;
                
                // Marcar como procesada
                $wpdb->update(
                    $wpdb->prefix . 'gurux_btx_ia_conversations',
                    array('status' => 'analyzed'),
                    array('id' => $conversation['id'])
                );
            } else {
                $results['failed']++;
            }
            
            $results['details'][] = array(
                'conversation_id' => $conversation['id'],
                'success' => $result['success'],
                'message' => $result['message'] ?? 'Procesado'
            );
        }
        
        $this->logger->info('Análisis masivo completado', $results);
        
        return $results;
    }
    
    // ============================================================================
    // MÉTODOS DE CONSULTA Y REPORTES
    // ============================================================================
    
    /**
     * Obtener historial de análisis por cliente
     */
    public function get_client_analysis_history($client_phone, $limit = 20) {
        global $wpdb;
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, c.conversation_data, c.created_at as conversation_date
            FROM {$wpdb->prefix}gurux_btx_ia_analysis a
            INNER JOIN {$wpdb->prefix}gurux_btx_ia_conversations c ON a.conversation_id = c.id
            WHERE c.client_phone = %s
            ORDER BY a.analyzed_at DESC
            LIMIT %d",
            $client_phone,
            $limit
        ), ARRAY_A);
        
        $history = array();
        
        foreach ($results as $row) {
            $history[] = array(
                'analysis_id' => $row['id'],
                'conversation_id' => $row['conversation_id'],
                'sentiment_score' => floatval($row['sentiment_score']),
                'urgency_level' => $row['urgency_level'],
                'category' => $row['category'],
                'client_satisfaction' => $row['client_satisfaction'],
                'opportunities' => $row['opportunities'],
                'recommendations' => $row['recommendations'],
                'analyzed_at' => $row['analyzed_at'],
                'conversation_date' => $row['conversation_date'],
                'conversation_preview' => $this->get_conversation_preview($row['conversation_data'])
            );
        }
        
        return array(
            'success' => true,
            'history' => $history,
            'total_found' => count($history)
        );
    }
    
    /**
     * Obtener estadísticas de análisis
     */
    public function get_analysis_stats($days = 30) {
        global $wpdb;
        
        $date_from = date('Y-m-d', strtotime("-{$days} days"));
        
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_analyses,
                AVG(sentiment_score) as avg_sentiment,
                COUNT(CASE WHEN client_satisfaction = 'risk' THEN 1 END) as clients_at_risk,
                COUNT(CASE WHEN urgency_level = 'critical' THEN 1 END) as critical_cases,
                SUM(analysis_cost) as total_cost
            FROM {$wpdb->prefix}gurux_btx_ia_analysis 
            WHERE DATE(analyzed_at) >= %s",
            $date_from
        ), ARRAY_A);
        
        // Distribución por satisfacción
        $satisfaction_dist = $wpdb->get_results($wpdb->prepare(
            "SELECT client_satisfaction, COUNT(*) as count
            FROM {$wpdb->prefix}gurux_btx_ia_analysis 
            WHERE DATE(analyzed_at) >= %s
            GROUP BY client_satisfaction",
            $date_from
        ), ARRAY_A);
        
        // Top categorías
        $top_categories = $wpdb->get_results($wpdb->prepare(
            "SELECT category, COUNT(*) as count
            FROM {$wpdb->prefix}gurux_btx_ia_analysis 
            WHERE DATE(analyzed_at) >= %s AND category != ''
            GROUP BY category
            ORDER BY count DESC
            LIMIT 10",
            $date_from
        ), ARRAY_A);
        
        return array(
            'period_days' => $days,
            'summary' => $stats,
            'satisfaction_distribution' => $satisfaction_dist,
            'top_categories' => $top_categories
        );
    }
    
    /**
     * Buscar análisis por criterios
     */
    public function search_analyses($criteria = array(), $limit = 50) {
        global $wpdb;
        
        $where_conditions = array('1=1');
        $params = array();
        
        if (!empty($criteria['client_phone'])) {
            $where_conditions[] = 'c.client_phone = %s';
            $params[] = $criteria['client_phone'];
        }
        
        if (!empty($criteria['sentiment_min'])) {
            $where_conditions[] = 'a.sentiment_score >= %f';
            $params[] = floatval($criteria['sentiment_min']);
        }
        
        if (!empty($criteria['sentiment_max'])) {
            $where_conditions[] = 'a.sentiment_score <= %f';
            $params[] = floatval($criteria['sentiment_max']);
        }
        
        if (!empty($criteria['urgency_level'])) {
            $where_conditions[] = 'a.urgency_level = %s';
            $params[] = $criteria['urgency_level'];
        }
        
        if (!empty($criteria['satisfaction'])) {
            $where_conditions[] = 'a.client_satisfaction = %s';
            $params[] = $criteria['satisfaction'];
        }
        
        if (!empty($criteria['category'])) {
            $where_conditions[] = 'a.category LIKE %s';
            $params[] = '%' . $criteria['category'] . '%';
        }
        
        if (!empty($criteria['date_from'])) {
            $where_conditions[] = 'DATE(a.analyzed_at) >= %s';
            $params[] = $criteria['date_from'];
        }
        
        if (!empty($criteria['date_to'])) {
            $where_conditions[] = 'DATE(a.analyzed_at) <= %s';
            $params[] = $criteria['date_to'];
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        $params[] = $limit;
        
        $query = "SELECT a.*, c.client_phone, c.client_name, c.created_at as conversation_date
                  FROM {$wpdb->prefix}gurux_btx_ia_analysis a
                  INNER JOIN {$wpdb->prefix}gurux_btx_ia_conversations c ON a.conversation_id = c.id
                  WHERE {$where_clause}
                  ORDER BY a.analyzed_at DESC
                  LIMIT %d";
        
        $results = $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A);
        
        return array(
            'success' => true,
            'results' => $results,
            'total_found' => count($results),
            'criteria_used' => $criteria
        );
    }
    
    // ============================================================================
    // MÉTODOS PRIVADOS
    // ============================================================================
    
    /**
     * Cargar configuración
     */
    private function load_config() {
        $this->config = array(
            'enable_automation' => gurux_btx_ia_get_setting('auto_create_deals', true),
            'min_sentiment_for_analysis' => gurux_btx_ia_get_setting('min_sentiment_threshold', 0.0),
            'cache_analysis_time' => 1800, // 30 minutos
            'default_template' => gurux_btx_ia_get_setting('default_analysis_template', 'default'),
            'skip_recent_duplicates' => true,
            'duplicate_window_minutes' => 30
        );
    }
    
    /**
     * Validar entrada
     */
    private function validate_input($conversation_data, $client_phone) {
        if (empty($conversation_data)) {
            return array(
                'success' => false,
                'message' => 'Datos de conversación vacíos'
            );
        }
        
        if ($client_phone && !gurux_btx_ia_is_valid_phone($client_phone)) {
            return array(
                'success' => false,
                'message' => 'Número de teléfono inválido'
            );
        }
        
        return array('success' => true);
    }
    
    /**
     * Preparar conversación para análisis
     */
    private function prepare_conversation($conversation_data) {
        if (is_string($conversation_data)) {
            return array(
                'text' => gurux_btx_ia_clean_text_for_ai($conversation_data),
                'type' => 'text',
                'message_count' => 1,
                'raw_data' => $conversation_data
            );
        }
        
        if (is_array($conversation_data)) {
            $formatted_text = gurux_btx_ia_format_conversation($conversation_data);
            
            return array(
                'text' => gurux_btx_ia_clean_text_for_ai($formatted_text),
                'type' => 'structured',
                'message_count' => count($conversation_data),
                'raw_data' => $conversation_data
            );
        }
        
        return array(
            'text' => 'Conversación no procesable',
            'type' => 'unknown',
            'message_count' => 0,
            'raw_data' => $conversation_data
        );
    }
    
    /**
     * Obtener o crear cliente
     */
    private function get_or_create_client($client_phone, $options = array()) {
        if (empty($client_phone)) {
            // Cliente anónimo
            return array(
                'id' => null,
                'phone' => null,
                'name' => 'Cliente Anónimo',
                'is_anonymous' => true
            );
        }
        
        $client_data = $this->client_manager->get_client_by_phone($client_phone);
        
        if ($client_data) {
            return $client_data;
        }
        
        // Crear nuevo cliente
        $new_client_data = array(
            'phone' => $client_phone,
            'name' => $options['client_name'] ?? gurux_btx_ia_format_client_name('Cliente ' . substr($client_phone, -4)),
            'email' => $options['client_email'] ?? '',
            'source' => 'chat_analyzer'
        );
        
        return $this->client_manager->create_client($new_client_data);
    }
    
    /**
     * Seleccionar plantilla de análisis
     */
    private function select_analysis_template($options, $client_data) {
        // Template forzado desde opciones
        if (!empty($options['force_template'])) {
            return $options['force_template'];
        }
        
        // Template por segmento de cliente
        if (!empty($client_data['segment'])) {
            $segment_templates = array(
                'ecommerce' => 'ecommerce',
                'telecomunicaciones' => 'telecomunicaciones',
                'finanzas' => 'servicios_financieros'
            );
            
            if (isset($segment_templates[$client_data['segment']])) {
                return $segment_templates[$client_data['segment']];
            }
        }
        
        // Template por defecto
        return $this->config['default_template'];
    }
    
    /**
     * Construir contexto del cliente para IA
     */
    private function build_client_context($client_data) {
        if ($client_data['is_anonymous'] ?? false) {
            return array();
        }
        
        $context = array(
            'client_name' => $client_data['name'],
            'client_segment' => $client_data['segment'] ?? 'general'
        );
        
        // Agregar historial si existe
        if (!empty($client_data['previous_sentiment'])) {
            $context['previous_sentiment'] = $client_data['previous_sentiment'];
        }
        
        if (!empty($client_data['total_interactions'])) {
            $context['interaction_count'] = $client_data['total_interactions'];
        }
        
        return $context;
    }
    
    /**
     * Enriquecer análisis con datos adicionales
     */
    private function enrich_analysis($base_analysis, $client_data, $conversation_data) {
        $enriched = $base_analysis;
        
        // Agregar puntuación de oportunidad
        $enriched['opportunity_score'] = gurux_btx_ia_calculate_opportunity_score($base_analysis, $client_data);
        
        // Determinar tipo de seguimiento
        $enriched['followup_type'] = gurux_btx_ia_determine_followup_type($base_analysis);
        
        // Generar recomendaciones automáticas
        $enriched['auto_recommendations'] = gurux_btx_ia_generate_auto_recommendations($base_analysis, $client_data);
        
        // Calcular urgencia real basada en contexto
        $enriched['calculated_urgency'] = $this->calculate_contextual_urgency($base_analysis, $client_data);
        
        // Metadata adicional
        $enriched['analysis_metadata'] = array(
            'conversation_length' => strlen($conversation_data['text']),
            'message_count' => $conversation_data['message_count'],
            'client_type' => $client_data['is_anonymous'] ?? false ? 'anonymous' : 'registered',
            'analysis_version' => '1.0'
        );
        
        return $enriched;
    }
    
    /**
     * Calcular urgencia contextual
     */
    private function calculate_contextual_urgency($analysis, $client_data) {
        $base_urgency = $analysis['urgency_level'];
        
        // Escalar urgencia si cliente VIP o recurrente
        if (!empty($client_data['is_vip']) && $client_data['is_vip']) {
            $urgency_scale = array(
                'low' => 'medium',
                'medium' => 'high',
                'high' => 'critical',
                'critical' => 'critical'
            );
            
            return $urgency_scale[$base_urgency] ?? $base_urgency;
        }
        
        // Reducir urgencia si cliente problemático frecuente
        if (!empty($client_data['complaint_count']) && $client_data['complaint_count'] > 5) {
            $urgency_scale = array(
                'critical' => 'high',
                'high' => 'medium',
                'medium' => 'low',
                'low' => 'low'
            );
            
            return $urgency_scale[$base_urgency] ?? $base_urgency;
        }
        
        return $base_urgency;
    }
    
    /**
     * Guardar conversación en BD
     */
    private function save_conversation($conversation_data, $client_data) {
        global $wpdb;
        
        $conversation_record = array(
            'client_phone' => $client_data['phone'] ?? '',
            'client_name' => $client_data['name'] ?? '',
            'client_email' => $client_data['email'] ?? '',
            'bitrix_contact_id' => $client_data['bitrix_contact_id'] ?? null,
            'conversation_data' => json_encode($conversation_data['raw_data']),
            'last_message' => gurux_btx_ia_truncate_text($conversation_data['text'], 500),
            'status' => 'pending',
            'created_at' => current_time('mysql')
        );
        
        $wpdb->insert(
            $wpdb->prefix . 'gurux_btx_ia_conversations',
            $conversation_record
        );
        
        return $wpdb->insert_id;
    }
    
    /**
     * Guardar análisis en BD
     */
    private function save_analysis($conversation_id, $analysis, $metadata) {
        global $wpdb;
        
        $analysis_record = array(
            'conversation_id' => $conversation_id,
            'claude_response' => json_encode($analysis),
            'sentiment_score' => $analysis['sentiment_score'],
            'urgency_level' => $analysis['urgency_level'],
            'category' => $analysis['category'],
            'client_satisfaction' => $analysis['client_satisfaction'],
            'opportunities' => $analysis['opportunities'],
            'recommendations' => $analysis['recommendations'],
            'analyzed_at' => current_time('mysql'),
            'analysis_cost' => $metadata['cost']
        );
        
        $wpdb->insert(
            $wpdb->prefix . 'gurux_btx_ia_analysis',
            $analysis_record
        );
        
        return $wpdb->insert_id;
    }
    
    /**
     * Triggear automatización
     */
    private function trigger_automation($analysis_id, $analysis, $client_data) {
        $automation_results = array();
        
        try {
            // Crear deal si cumple criterios
            if ($this->should_create_deal($analysis, $client_data)) {
                $deal_result = $this->deals_manager->create_deal_from_analysis($analysis, $client_data);
                $automation_results['deal'] = $deal_result;
            }
            
            // Crear tarea si es necesario
            if ($this->should_create_task($analysis)) {
                $task_result = $this->deals_manager->create_task_from_analysis($analysis, $client_data);
                $automation_results['task'] = $task_result;
            }
            
            // Notificaciones si es crítico
            if ($analysis['urgency_level'] === 'critical' || $analysis['client_satisfaction'] === 'risk') {
                $notification_result = $this->send_urgent_notification($analysis, $client_data);
                $automation_results['notification'] = $notification_result;
            }
            
            return array(
                'success' => true,
                'actions_triggered' => array_keys($automation_results),
                'results' => $automation_results
            );
            
        } catch (Exception $e) {
            $this->logger->error('Error en automatización', array(
                'analysis_id' => $analysis_id,
                'error' => $e->getMessage()
            ));
            
            return array(
                'success' => false,
                'message' => 'Error en automatización: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * Determinar si crear deal
     */
    private function should_create_deal($analysis, $client_data) {
        $min_sentiment = gurux_btx_ia_get_setting('sentiment_threshold', 3.0);
        
        return $analysis['sentiment_score'] >= $min_sentiment && 
               !($client_data['is_anonymous'] ?? false) &&
               gurux_btx_ia_get_setting('auto_create_deals', true);
    }
    
    /**
     * Determinar si crear tarea
     */
    private function should_create_task($analysis) {
        $critical_conditions = array(
            $analysis['urgency_level'] === 'critical',
            $analysis['client_satisfaction'] === 'risk',
            $analysis['urgency_level'] === 'high' && $analysis['sentiment_score'] < 4.0
        );
        
        return in_array(true, $critical_conditions);
    }
    
    /**
     * Enviar notificación urgente
     */
    private function send_urgent_notification($analysis, $client_data) {
        // Implementación de notificaciones urgentes
        // Esto se puede expandir con email, SMS, webhook, etc.
        
        $this->logger->warning('Situación crítica detectada', array(
            'client_name' => $client_data['name'],
            'client_phone' => $client_data['phone'],
            'sentiment_score' => $analysis['sentiment_score'],
            'urgency_level' => $analysis['urgency_level'],
            'satisfaction' => $analysis['client_satisfaction']
        ));
        
        return array(
            'success' => true,
            'notification_sent' => 'logged',
            'urgency' => $analysis['urgency_level']
        );
    }
    
    /**
     * Verificar análisis reciente duplicado
     */
    private function has_recent_analysis($client_phone, $conversation_text) {
        if (!$this->config['skip_recent_duplicates'] || empty($client_phone)) {
            return false;
        }
        
        $cache_key = md5($client_phone . $conversation_text);
        return isset($this->analysis_cache[$cache_key]);
    }
    
    /**
     * Obtener análisis reciente
     */
    private function get_recent_analysis($client_phone) {
        // Buscar en cache o BD el análisis más reciente
        global $wpdb;
        
        $recent = $wpdb->get_row($wpdb->prepare(
            "SELECT a.* FROM {$wpdb->prefix}gurux_btx_ia_analysis a
            INNER JOIN {$wpdb->prefix}gurux_btx_ia_conversations c ON a.conversation_id = c.id
            WHERE c.client_phone = %s
            ORDER BY a.analyzed_at DESC
            LIMIT 1",
            $client_phone
        ), ARRAY_A);
        
        if ($recent) {
            return array(
                'success' => true,
                'analysis_id' => $recent['id'],
                'analysis' => json_decode($recent['claude_response'], true),
                'cached' => true
            );
        }
        
        return array(
            'success' => false,
            'message' => 'No hay análisis reciente'
        );
    }
    
    /**
     * Cache resultado de análisis
     */

    private function cache_analysis_result($client_phone, $result) {
        if (empty($client_phone)) return; // ✅ Verificar primero


        if (count($this->analysis_cache) >= $this->max_cache_size) {
            array_shift($this->analysis_cache);
        }

        $cache_key = md5($client_phone . serialize($result['analysis']));


        $this->analysis_cache[$cache_key] = $result;
        
        // Limpiar cache automáticamente después del tiempo configurado
        wp_schedule_single_event(
            time() + $this->config['cache_analysis_time'], 
            'gurux_clear_analysis_cache', 
            array($cache_key)
        );
    }
    
    /**
     * Obtener preview de conversación
     */
    private function get_conversation_preview($conversation_data) {
        $data = json_decode($conversation_data, true);
        
        if (is_string($data)) {
            return gurux_btx_ia_truncate_text($data, 100);
        }
        
        if (is_array($data)) {
            $formatted = gurux_btx_ia_format_conversation($data);
            return gurux_btx_ia_truncate_text($formatted, 100);
        }
        
        return 'Conversación no disponible';
    }








    // FIN DE FUNCIUONES


    /**
     * NUEVAS FUNCIONES PARA DASHBOARD - FASE 2
     */

    /**
     * Obtener cola de análisis en tiempo real
     */
    public function get_real_time_analysis_queue() {
        global $wpdb;
        
        // Análisis pendientes
        $pending_analyses = $wpdb->get_results(
            "SELECT 
                c.id as conversation_id,
                c.client_name,
                c.client_phone,
                c.created_at,
                c.status,
                TIMESTAMPDIFF(MINUTE, c.created_at, NOW()) as minutes_waiting
            FROM {$wpdb->prefix}gurux_btx_ia_conversations c
            LEFT JOIN {$wpdb->prefix}gurux_btx_ia_analysis a ON c.id = a.conversation_id
            WHERE a.id IS NULL OR c.status = 'pending'
            ORDER BY c.created_at ASC
            LIMIT 50",
            ARRAY_A
        );

        // Análisis recientes (últimas 2 horas)
        $recent_analyses = $wpdb->get_results(
            "SELECT 
                a.id as analysis_id,
                a.sentiment_score,
                a.urgency_level,
                a.client_satisfaction,
                a.analyzed_at,
                c.client_name,
                c.client_phone,
                TIMESTAMPDIFF(MINUTE, a.analyzed_at, NOW()) as minutes_ago
            FROM {$wpdb->prefix}gurux_btx_ia_analysis a
            INNER JOIN {$wpdb->prefix}gurux_btx_ia_conversations c ON a.conversation_id = c.id
            WHERE a.analyzed_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
            ORDER BY a.analyzed_at DESC
            LIMIT 20",
            ARRAY_A
        );

        // Estadísticas de la cola
        $queue_stats = array(
            'total_pending' => count($pending_analyses),
            'average_wait_time' => 0,
            'critical_pending' => 0,
            'recent_processed' => count($recent_analyses)
        );

        if (!empty($pending_analyses)) {
            $queue_stats['average_wait_time'] = round(
                array_sum(array_column($pending_analyses, 'minutes_waiting')) / count($pending_analyses), 
                1
            );
            
            // Identificar críticos basado en tiempo de espera
            $queue_stats['critical_pending'] = count(array_filter($pending_analyses, function($item) {
                return $item['minutes_waiting'] > 30; // Más de 30 minutos esperando
            }));
        }

        return array(
            'pending_analyses' => $pending_analyses,
            'recent_analyses' => $recent_analyses,
            'queue_stats' => $queue_stats,
            'last_updated' => current_time('mysql')
        );
    }

    /**
     * Obtener estadísticas de análisis expandidas
     */
    public function get_expanded_analysis_stats($days = 30) {
        global $wpdb;
        
        $date_from = date('Y-m-d', strtotime("-{$days} days"));
        
        // Estadísticas básicas expandidas
        $basic_stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_analyses,
                AVG(sentiment_score) as avg_sentiment,
                STDDEV(sentiment_score) as sentiment_stddev,
                MIN(sentiment_score) as min_sentiment,
                MAX(sentiment_score) as max_sentiment,
                COUNT(CASE WHEN client_satisfaction = 'risk' THEN 1 END) as clients_at_risk,
                COUNT(CASE WHEN client_satisfaction = 'satisfied' THEN 1 END) as satisfied_clients,
                COUNT(CASE WHEN urgency_level = 'critical' THEN 1 END) as critical_cases,
                COUNT(CASE WHEN urgency_level = 'high' THEN 1 END) as high_urgency_cases,
                SUM(analysis_cost) as total_cost,
                AVG(analysis_cost) as avg_cost_per_analysis
            FROM {$wpdb->prefix}gurux_btx_ia_analysis 
            WHERE DATE(analyzed_at) >= %s",
            $date_from
        ), ARRAY_A);

        // Distribución por satisfacción detallada
        $satisfaction_distribution = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                client_satisfaction, 
                COUNT(*) as count,
                AVG(sentiment_score) as avg_sentiment,
                COUNT(CASE WHEN urgency_level = 'critical' THEN 1 END) as critical_count
            FROM {$wpdb->prefix}gurux_btx_ia_analysis 
            WHERE DATE(analyzed_at) >= %s
            GROUP BY client_satisfaction
            ORDER BY count DESC",
            $date_from
        ), ARRAY_A);

        // Análisis por urgencia
        $urgency_analysis = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                urgency_level,
                COUNT(*) as count,
                AVG(sentiment_score) as avg_sentiment,
                AVG(analysis_cost) as avg_cost
            FROM {$wpdb->prefix}gurux_btx_ia_analysis 
            WHERE DATE(analyzed_at) >= %s
            GROUP BY urgency_level
            ORDER BY FIELD(urgency_level, 'critical', 'high', 'medium', 'low')",
            $date_from
        ), ARRAY_A);

        // Top categorías con más detalles
        $detailed_categories = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                category, 
                COUNT(*) as count,
                AVG(sentiment_score) as avg_sentiment,
                COUNT(CASE WHEN client_satisfaction = 'satisfied' THEN 1 END) as satisfied_count,
                COUNT(CASE WHEN urgency_level IN ('critical', 'high') THEN 1 END) as urgent_count,
                MAX(analyzed_at) as last_occurrence
            FROM {$wpdb->prefix}gurux_btx_ia_analysis 
            WHERE DATE(analyzed_at) >= %s AND category != ''
            GROUP BY category
            ORDER BY count DESC
            LIMIT 10",
            $date_from
        ), ARRAY_A);

        // Análisis temporal (por hora del día)
        $hourly_analysis = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                HOUR(analyzed_at) as hour_of_day,
                COUNT(*) as analyses_count,
                AVG(sentiment_score) as avg_sentiment,
                COUNT(CASE WHEN urgency_level = 'critical' THEN 1 END) as critical_count
            FROM {$wpdb->prefix}gurux_btx_ia_analysis 
            WHERE DATE(analyzed_at) >= %s
            GROUP BY HOUR(analyzed_at)
            ORDER BY hour_of_day",
            $date_from
        ), ARRAY_A);

        // Análisis de eficiencia (tiempo de procesamiento y costos)
        $efficiency_metrics = $this->calculate_efficiency_metrics($days);

        // Patrones de conversación detectados
        $conversation_patterns = $this->detect_conversation_patterns($days);

        return array(
            'period_days' => $days,
            'summary' => $basic_stats,
            'satisfaction_distribution' => $satisfaction_distribution,
            'urgency_analysis' => $urgency_analysis,
            'detailed_categories' => $detailed_categories,
            'hourly_analysis' => $hourly_analysis,
            'efficiency_metrics' => $efficiency_metrics,
            'conversation_patterns' => $conversation_patterns,
            'recommendations' => $this->generate_analysis_recommendations($basic_stats, $satisfaction_distribution)
        );
    }

    /**
     * Obtener análisis de rendimiento en tiempo real
     */
    public function get_real_time_performance_metrics() {
        global $wpdb;
        
        // Métricas de las últimas 24 horas
        $last_24h = $wpdb->get_row(
            "SELECT 
                COUNT(*) as analyses_24h,
                AVG(sentiment_score) as avg_sentiment_24h,
                COUNT(CASE WHEN client_satisfaction = 'risk' THEN 1 END) as risk_cases_24h,
                SUM(analysis_cost) as cost_24h
            FROM {$wpdb->prefix}gurux_btx_ia_analysis 
            WHERE analyzed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            ARRAY_A
        );

        // Métricas de la última hora
        $last_hour = $wpdb->get_row(
            "SELECT 
                COUNT(*) as analyses_1h,
                AVG(sentiment_score) as avg_sentiment_1h,
                COUNT(CASE WHEN urgency_level = 'critical' THEN 1 END) as critical_1h
            FROM {$wpdb->prefix}gurux_btx_ia_analysis 
            WHERE analyzed_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            ARRAY_A
        );

        // Cola actual
        $current_queue = $this->get_current_queue_size();

        // Tendencia de satisfacción (últimas 12 horas)
        $satisfaction_trend = $wpdb->get_results(
            "SELECT 
                HOUR(analyzed_at) as hour,
                AVG(sentiment_score) as avg_sentiment,
                COUNT(*) as count
            FROM {$wpdb->prefix}gurux_btx_ia_analysis 
            WHERE analyzed_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR)
            GROUP BY HOUR(analyzed_at)
            ORDER BY analyzed_at DESC",
            ARRAY_A
        );

        return array(
            'current_time' => current_time('mysql'),
            'last_24h' => $last_24h,
            'last_hour' => $last_hour,
            'current_queue' => $current_queue,
            'satisfaction_trend' => $satisfaction_trend,
            'system_health' => $this->assess_system_health($last_24h, $current_queue)
        );
    }

    /**
     * Detectar patrones de conversación
     */
    public function detect_conversation_patterns($days = 30) {
        global $wpdb;
        
        $date_from = date('Y-m-d', strtotime("-{$days} days"));
        
        // Patrones por palabras clave más frecuentes
        $keyword_patterns = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                keywords,
                COUNT(*) as frequency,
                AVG(sentiment_score) as avg_sentiment,
                urgency_level
            FROM {$wpdb->prefix}gurux_btx_ia_analysis 
            WHERE DATE(analyzed_at) >= %s 
            AND keywords IS NOT NULL 
            AND keywords != ''
            GROUP BY keywords, urgency_level
            HAVING frequency > 2
            ORDER BY frequency DESC
            LIMIT 20",
            $date_from
        ), ARRAY_A);

        // Patrones de escalación (conversaciones que se vuelven críticas)
        $escalation_patterns = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                c.client_phone,
                COUNT(a.id) as conversation_count,
                MIN(a.sentiment_score) as min_sentiment,
                MAX(a.sentiment_score) as max_sentiment,
                AVG(a.sentiment_score) as avg_sentiment,
                COUNT(CASE WHEN a.urgency_level = 'critical' THEN 1 END) as critical_escalations
            FROM {$wpdb->prefix}gurux_btx_ia_conversations c
            INNER JOIN {$wpdb->prefix}gurux_btx_ia_analysis a ON c.id = a.conversation_id
            WHERE DATE(a.analyzed_at) >= %s
            GROUP BY c.client_phone
            HAVING conversation_count > 3 
            AND critical_escalations > 0
            ORDER BY critical_escalations DESC, avg_sentiment ASC
            LIMIT 15",
            $date_from
        ), ARRAY_A);

        // Patrones temporales (horarios problemáticos)
        $temporal_patterns = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                HOUR(analyzed_at) as hour,
                COUNT(CASE WHEN client_satisfaction = 'risk' THEN 1 END) as risk_count,
                COUNT(*) as total_count,
                ROUND((COUNT(CASE WHEN client_satisfaction = 'risk' THEN 1 END) / COUNT(*)) * 100, 1) as risk_percentage
            FROM {$wpdb->prefix}gurux_btx_ia_analysis 
            WHERE DATE(analyzed_at) >= %s
            GROUP BY HOUR(analyzed_at)
            HAVING total_count > 5
            ORDER BY risk_percentage DESC",
            $date_from
        ), ARRAY_A);

        return array(
            'keyword_patterns' => $keyword_patterns,
            'escalation_patterns' => $escalation_patterns,
            'temporal_patterns' => $temporal_patterns,
            'pattern_insights' => $this->generate_pattern_insights($keyword_patterns, $escalation_patterns, $temporal_patterns)
        );
    }

    /**
     * Calcular métricas de eficiencia
     */
    private function calculate_efficiency_metrics($days) {
        global $wpdb;
        
        $date_from = date('Y-m-d', strtotime("-{$days} days"));
        
        // Eficiencia de costos
        $cost_efficiency = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                AVG(analysis_cost) as avg_cost,
                MIN(analysis_cost) as min_cost,
                MAX(analysis_cost) as max_cost,
                STDDEV(analysis_cost) as cost_stddev
            FROM {$wpdb->prefix}gurux_btx_ia_analysis 
            WHERE DATE(analyzed_at) >= %s",
            $date_from
        ), ARRAY_A);

        // Eficiencia por plantilla
        $template_efficiency = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                template_used,
                COUNT(*) as usage_count,
                AVG(analysis_cost) as avg_cost,
                AVG(sentiment_score) as avg_sentiment
            FROM {$wpdb->prefix}gurux_btx_ia_analysis 
            WHERE DATE(analyzed_at) >= %s 
            AND template_used IS NOT NULL
            GROUP BY template_used",
            $date_from
        ), ARRAY_A);

        return array(
            'cost_efficiency' => $cost_efficiency,
            'template_efficiency' => $template_efficiency,
            'cost_per_insight' => $this->calculate_cost_per_insight($days)
        );
    }

    /**
     * Obtener tamaño actual de la cola
     */
    private function get_current_queue_size() {
        global $wpdb;
        
        $pending_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}gurux_btx_ia_conversations c
            LEFT JOIN {$wpdb->prefix}gurux_btx_ia_analysis a ON c.id = a.conversation_id
            WHERE a.id IS NULL OR c.status = 'pending'"
        );

        return intval($pending_count);
    }

    /**
     * Evaluar salud del sistema
     */
    private function assess_system_health($metrics_24h, $queue_size) {
        $health_score = 100;
        $issues = array();

        // Verificar cola
        if ($queue_size > 50) {
            $health_score -= 30;
            $issues[] = 'Cola de análisis muy grande';
        } elseif ($queue_size > 20) {
            $health_score -= 15;
            $issues[] = 'Cola de análisis elevada';
        }

        // Verificar satisfacción promedio
        $avg_sentiment = floatval($metrics_24h['avg_sentiment_24h']);
        if ($avg_sentiment < 4.0) {
            $health_score -= 25;
            $issues[] = 'Satisfacción general muy baja';
        } elseif ($avg_sentiment < 6.0) {
            $health_score -= 10;
            $issues[] = 'Satisfacción general baja';
        }

        // Verificar casos de riesgo
        $risk_percentage = intval($metrics_24h['analyses_24h']) > 0 ? 
            (intval($metrics_24h['risk_cases_24h']) / intval($metrics_24h['analyses_24h'])) * 100 : 0;
        
        if ($risk_percentage > 20) {
            $health_score -= 20;
            $issues[] = 'Alto porcentaje de casos de riesgo';
        }

        $health_status = 'excellent';
        if ($health_score < 60) $health_status = 'poor';
        elseif ($health_score < 80) $health_status = 'warning';
        elseif ($health_score < 90) $health_status = 'good';

        return array(
            'score' => max(0, $health_score),
            'status' => $health_status,
            'issues' => $issues,
            'recommendations' => $this->get_health_recommendations($issues)
        );
    }

    /**
     * Calcular costo por insight
     */
    private function calculate_cost_per_insight($days) {
        global $wpdb;
        
        $total_cost = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(analysis_cost) FROM {$wpdb->prefix}gurux_btx_ia_analysis 
            WHERE DATE(analyzed_at) >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));

        $actionable_insights = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}gurux_btx_ia_analysis 
            WHERE DATE(analyzed_at) >= DATE_SUB(NOW(), INTERVAL %d DAY)
            AND (urgency_level IN ('high', 'critical') OR client_satisfaction IN ('risk', 'help'))",
            $days
        ));

        return $actionable_insights > 0 ? round(floatval($total_cost) / $actionable_insights, 4) : 0;
    }

    /**
     * Generar insights de patrones
     */
    private function generate_pattern_insights($keyword_patterns, $escalation_patterns, $temporal_patterns) {
        $insights = array();

        // Insights de palabras clave
        if (!empty($keyword_patterns)) {
            $top_pattern = $keyword_patterns[0];
            $insights[] = array(
                'type' => 'keyword',
                'message' => "Patrón más frecuente: '{$top_pattern['keywords']}' con sentimiento promedio {$top_pattern['avg_sentiment']}"
            );
        }

        // Insights de escalación
        if (!empty($escalation_patterns)) {
            $high_escalation = array_filter($escalation_patterns, function($p) { return $p['critical_escalations'] > 2; });
            if (!empty($high_escalation)) {
                $insights[] = array(
                    'type' => 'escalation',
                    'message' => count($high_escalation) . ' clientes con múltiples escalaciones críticas'
                );
            }
        }

        // Insights temporales
        if (!empty($temporal_patterns)) {
            $problematic_hours = array_filter($temporal_patterns, function($p) { return $p['risk_percentage'] > 30; });
            if (!empty($problematic_hours)) {
                $hours = array_column($problematic_hours, 'hour');
                $insights[] = array(
                    'type' => 'temporal',
                    'message' => 'Horarios con más riesgo: ' . implode(', ', $hours) . ':00'
                );
            }
        }

        return $insights;
    }

    /**
     * Generar recomendaciones de análisis
     */
    private function generate_analysis_recommendations($basic_stats, $satisfaction_distribution) {
        $recommendations = array();

        // Recomendación por satisfacción baja
        if (floatval($basic_stats['avg_sentiment']) < 6.0) {
            $recommendations[] = array(
                'type' => 'satisfaction',
                'priority' => 'high',
                'message' => 'Implementar mejoras en el proceso de atención al cliente'
            );
        }

        // Recomendación por muchos casos críticos
        $total_analyses = intval($basic_stats['total_analyses']);
        $critical_percentage = $total_analyses > 0 ? 
            (intval($basic_stats['critical_cases']) / $total_analyses) * 100 : 0;

        if ($critical_percentage > 15) {
            $recommendations[] = array(
                'type' => 'urgency',
                'priority' => 'critical',
                'message' => 'Alto porcentaje de casos críticos. Revisar procesos de escalación.'
            );
        }

        return $recommendations;
    }

    /**
     * Obtener recomendaciones de salud del sistema
     */
    private function get_health_recommendations($issues) {
        $recommendations = array();

        foreach ($issues as $issue) {
            switch ($issue) {
                case 'Cola de análisis muy grande':
                    $recommendations[] = 'Considerar procesamiento por lotes o aumentar frecuencia de análisis';
                    break;
                case 'Satisfacción general muy baja':
                    $recommendations[] = 'Revisar estrategias de atención al cliente y capacitación del equipo';
                    break;
                case 'Alto porcentaje de casos de riesgo':
                    $recommendations[] = 'Implementar alertas tempranas y protocolos de retención';
                    break;
            }
        }

        return $recommendations;
    }







    //FIN DE FUNCIONES
    
}

// Función helper global
if (!function_exists('gurux_chat_analyzer')) {
    function gurux_chat_analyzer() {
        return GuruX_Chat_Analyzer::get_instance();
    }
}