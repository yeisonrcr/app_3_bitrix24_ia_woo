<?php
/**
 * GuruX Claude AI API - Motor de Análisis Inteligente
 * 
 * Maneja toda la comunicación con Claude AI de Anthropic
 * Análisis de conversaciones con prompts optimizados en español
 * 
 * @package GuruX_BTX_IA
 * @version 1.0.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class GuruX_Claude_API {
    
    /**
     * Instancia única (Singleton)
     */
    private static $instance = null;
    
    /**
     * API Key de Claude
     */
    private $api_key;
    
    /**
     * Logger
     */
    private $logger;
    
    /**
     * Configuración de la API
     */
    private $api_config;
    
    /**
     * Límites de uso
     */
    private $usage_limits;
    
    /**
     * Cache de análisis
     */
    private $analysis_cache = array();
    
    /**
     * Plantillas de prompts
     */
    private $prompt_templates;
    
    /**
     * Estadísticas de uso del día
     */
    private $daily_stats;
    
    /**
     * Constructor privado para Singleton
     */
    private function __construct() {
        $this->logger = GuruX_Logger::get_instance();
        $this->load_config();
        $this->load_prompt_templates();
        $this->load_daily_stats();
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
     * Verificar si está configurado correctamente
     */
    public function is_configured() {
        return !empty($this->api_key) && $this->is_valid_api_key($this->api_key);
    }
    
    /**
     * Test básico de conectividad
     */
    public function test_connection() {
        if (!$this->is_configured()) {
            return array(
                'success' => false,
                'message' => 'API key de Claude no configurada o inválida'
            );
        }
        
        $test_prompt = "Responde únicamente 'OK' si puedes escucharme.";
        
        $start_time = microtime(true);
        $response = $this->send_request($test_prompt, 10);
        $response_time = round((microtime(true) - $start_time) * 1000, 2);
        
        if ($response['success']) {
            $ai_response = $response['content'];
            $is_valid = stripos($ai_response, 'OK') !== false;
            
            if ($is_valid) {
                $this->logger->info('Test de conexión Claude AI exitoso', array(
                    'response_time' => $response_time . 'ms',
                    'tokens_used' => $response['usage']['total_tokens'] ?? 0
                ));
                
                return array(
                    'success' => true,
                    'message' => "Claude AI conectado exitosamente ({$response_time}ms)",
                    'response' => $ai_response
                );
            }
        }
        
        $this->logger->error('Test de conexión Claude AI falló', array(
            'error' => $response['message'] ?? 'Respuesta inesperada'
        ));
        
        return array(
            'success' => false,
            'message' => $response['message'] ?? 'Respuesta inesperada de Claude AI'
        );
    }
    
    // ============================================================================
    // MÉTODOS PRINCIPALES DE ANÁLISIS
    // ============================================================================
    
    /**
     * Analizar conversación completa
     */
    public function analyze_conversation($conversation_text, $template = 'default', $client_context = array()) {
        // Verificar límites diarios
        if (!$this->check_daily_limits()) {
            return array(
                'success' => false,
                'message' => 'Límite diario de análisis alcanzado'
            );
        }
        
        // Validar entrada
        if (empty($conversation_text) || strlen(trim($conversation_text)) < 10) {
            return array(
                'success' => false,
                'message' => 'Conversación muy corta para analizar'
            );
        }
        
        // Verificar cache
        $cache_key = $this->generate_cache_key($conversation_text, $template);
        $cached_result = $this->get_cached_analysis($cache_key);
        
        if ($cached_result) {
            $this->logger->debug('Análisis servido desde cache', array(
                'cache_key' => substr($cache_key, 0, 8) . '...',
                'template' => $template
            ));
            
            return $cached_result;
        }
        
        // Limpiar y preparar conversación
        $clean_conversation = gurux_btx_ia_clean_text_for_ai($conversation_text);
        
        // Generar prompt usando plantilla
        $prompt = $this->build_prompt($clean_conversation, $template, $client_context);
        
        // Realizar análisis
        $start_time = microtime(true);
        $response = $this->send_request($prompt, $this->api_config['max_tokens']);
        $execution_time = round((microtime(true) - $start_time) * 1000, 2);
        
        if (!$response['success']) {
            $this->logger->log_api_error('claude_ai', 'messages', $response['message']);
            return $response;
        }
        
        // Procesar y validar respuesta
        $analysis_result = $this->process_analysis_response($response['content']);
        
        if (!$analysis_result['success']) {
            $this->logger->warning('Respuesta de Claude AI no procesable', array(
                'raw_response' => substr($response['content'], 0, 200),
                'error' => $analysis_result['message']
            ));
            
            return $analysis_result;
        }
        
        // Calcular costo
        $cost = $this->calculate_cost($response['usage']);
        
        // Actualizar estadísticas
        $this->update_daily_stats($cost, $execution_time);
        
        // Preparar resultado final
        $final_result = array(
            'success' => true,
            'analysis' => $analysis_result['analysis'],
            'metadata' => array(
                'execution_time' => $execution_time,
                'cost' => $cost,
                'tokens_used' => $response['usage'],
                'template_used' => $template,
                'timestamp' => current_time('mysql')
            )
        );
        
        // Guardar en cache
        $this->cache_analysis($cache_key, $final_result);
        
        // Log de análisis exitoso
        $this->logger->log_analysis(
            $conversation_text, 
            $analysis_result['analysis'], 
            $execution_time, 
            $cost
        );
        
        return $final_result;
    }
    
    /**
     * Análisis rápido para validación
     */
    public function quick_analysis($text, $question) {
        $prompt = "Analiza este texto y responde brevemente: {$question}\n\nTexto: {$text}\n\nRespuesta:";
        
        $response = $this->send_request($prompt, 100);
        
        if ($response['success']) {
            return array(
                'success' => true,
                'answer' => trim($response['content']),
                'cost' => $this->calculate_cost($response['usage'])
            );
        }
        
        return $response;
    }
    
    /**
     * Análisis de sentimiento simple
     */
    public function analyze_sentiment_only($text) {
        $prompt = "Analiza únicamente el sentimiento de este texto en una escala del 0.0 al 10.0 (donde 0.0 es muy negativo y 10.0 muy positivo). Responde solo con el número decimal:\n\n{$text}";
        
        $response = $this->send_request($prompt, 20);
        
        if ($response['success']) {
            $sentiment = floatval(trim($response['content']));
            
            if ($sentiment >= 0.0 && $sentiment <= 10.0) {
                return array(
                    'success' => true,
                    'sentiment_score' => $sentiment,
                    'cost' => $this->calculate_cost($response['usage'])
                );
            }
        }
        
        return array(
            'success' => false,
            'message' => 'No se pudo determinar el sentimiento'
        );
    }
    
    // ============================================================================
    // GESTIÓN DE PLANTILLAS
    // ============================================================================
    
    /**
     * Obtener plantilla de prompt
     */
    public function get_prompt_template($template_name) {
        return isset($this->prompt_templates[$template_name]) ? 
            $this->prompt_templates[$template_name] : 
            $this->prompt_templates['default'];
    }
    
    /**
     * Registrar nueva plantilla
     */
    public function register_template($name, $template_data) {
        $this->prompt_templates[$name] = $template_data;
        
        // Guardar en base de datos
        $templates = get_option('gurux_btx_ia_prompt_templates', array());
        $templates[$name] = $template_data;
        update_option('gurux_btx_ia_prompt_templates', $templates);
        
        $this->logger->info('Nueva plantilla de prompt registrada', array(
            'template_name' => $name
        ));
    }
    
    /**
     * Listar plantillas disponibles
     */
    public function list_templates() {
        return array_keys($this->prompt_templates);
    }
    
    // ============================================================================
    // CONTROL DE LÍMITES Y COSTOS
    // ============================================================================
    
    /**
     * Verificar límites diarios
     */
    public function check_daily_limits() {
        $daily_limit = gurux_btx_ia_get_setting('daily_analysis_limit', GURUX_BTX_IA_DAILY_ANALYSIS_LIMIT);
        $today_count = $this->daily_stats['analyses_count'];
        
        return $today_count < $daily_limit;
    }
    
    /**
     * Obtener uso diario
     */
    public function get_daily_usage() {
        return array(
            'analyses_count' => $this->daily_stats['analyses_count'],
            'total_cost' => $this->daily_stats['total_cost'],
            'total_tokens' => $this->daily_stats['total_tokens'],
            'average_response_time' => $this->daily_stats['total_response_time'] > 0 ? 
                round($this->daily_stats['total_response_time'] / max(1, $this->daily_stats['analyses_count']), 2) : 0,
            'limit' => gurux_btx_ia_get_setting('daily_analysis_limit', GURUX_BTX_IA_DAILY_ANALYSIS_LIMIT),
            'remaining' => max(0, gurux_btx_ia_get_setting('daily_analysis_limit', GURUX_BTX_IA_DAILY_ANALYSIS_LIMIT) - $this->daily_stats['analyses_count'])
        );
    }
    
    /**
     * Calcular costo de análisis
     */
    private function calculate_cost($usage) {
        // Precios de Claude Haiku (aproximados)
        $input_cost_per_token = 0.00025 / 1000;   // $0.25 per 1M input tokens
        $output_cost_per_token = 0.00125 / 1000;  // $1.25 per 1M output tokens
        
        $input_tokens = $usage['input_tokens'] ?? 0;
        $output_tokens = $usage['output_tokens'] ?? 0;
        
        $cost = ($input_tokens * $input_cost_per_token) + ($output_tokens * $output_cost_per_token);
        
        return round($cost, 6);
    }
    
    /**
     * Resetear estadísticas diarias
     */
    public function reset_daily_stats() {
        $this->daily_stats = array(
            'date' => current_time('Y-m-d'),
            'analyses_count' => 0,
            'total_cost' => 0.0,
            'total_tokens' => 0,
            'total_response_time' => 0
        );
        
        update_option('gurux_btx_ia_claude_daily_stats', $this->daily_stats);
    }
    
    // ============================================================================
    // MÉTODOS PRIVADOS
    // ============================================================================
    
    /**
     * Cargar configuración
     */
    private function load_config() {
        $this->api_key = gurux_btx_ia_get_setting('claude_api_key', '');
        
        $this->api_config = array(
            'api_url' => GURUX_BTX_IA_CLAUDE_API_URL,
            'model' => GURUX_BTX_IA_CLAUDE_MODEL,
            'max_tokens' => GURUX_BTX_IA_CLAUDE_MAX_TOKENS,
            'version' => GURUX_BTX_IA_CLAUDE_VERSION,
            'timeout' => 30
        );
        
        $this->usage_limits = array(
            'daily_limit' => gurux_btx_ia_get_setting('daily_analysis_limit', GURUX_BTX_IA_DAILY_ANALYSIS_LIMIT),
            'cost_limit' => gurux_btx_ia_get_setting('daily_cost_limit', 10.0) // $10 por día
        );
    }
    
    /**
     * Cargar plantillas de prompts
     */
    private function load_prompt_templates() {
        // Plantilla por defecto
        $default_template = "Analiza esta conversación de soporte al cliente en español y devuelve ÚNICAMENTE un JSON válido con esta estructura exacta:

{
  \"sentiment_score\": [número decimal del 0.0 al 10.0, donde 0.0 es muy negativo y 10.0 muy positivo],
  \"urgency_level\": \"[low, medium, high, o critical]\",
  \"category\": \"[tipo de consulta en español - ej: Soporte Técnico, Consulta Comercial, Reclamo, etc.]\",
  \"client_satisfaction\": \"[risk, help, neutral, o satisfied]\",
  \"opportunities\": \"[oportunidades comerciales detectadas - descripción en español]\",
  \"recommendations\": \"[recomendaciones específicas para el agente en español]\",
  \"next_action\": \"[siguiente acción sugerida en español]\",
  \"keywords\": [\"palabra1\", \"palabra2\", \"palabra3\"]
}

IMPORTANTE: 
- sentiment_score debe estar entre 0.0 y 10.0
- urgency_level debe ser exactamente: low, medium, high, o critical
- client_satisfaction debe ser exactamente: risk, help, neutral, o satisfied
- Todas las descripciones deben estar en español
- Responde SOLO con el JSON, sin texto adicional

Conversación:
{CONVERSATION}

JSON:";

        // Plantilla para e-commerce
        $ecommerce_template = "Analiza esta conversación de un cliente de e-commerce y devuelve un JSON con análisis específico para ventas online:

{
  \"sentiment_score\": [0.0-10.0],
  \"urgency_level\": \"[low, medium, high, critical]\",
  \"category\": \"[Consulta Producto, Problema Envío, Devolución, Soporte Post-venta, etc.]\",
  \"client_satisfaction\": \"[risk, help, neutral, satisfied]\",
  \"opportunities\": \"[oportunidades de upselling, cross-selling o fidelización]\",
  \"recommendations\": \"[acciones para mejorar experiencia de compra]\",
  \"next_action\": \"[próxima acción comercial recomendada]\",
  \"keywords\": [\"términos\", \"relevantes\", \"del\", \"ecommerce\"]
}

Conversación:
{CONVERSATION}

JSON:";

        // Cargar desde base de datos y combinar con defaults
        $saved_templates = get_option('gurux_btx_ia_prompt_templates', array());
        
        $this->prompt_templates = array_merge(array(
            'default' => $default_template,
            'ecommerce' => $ecommerce_template,
            'telecomunicaciones' => $default_template, // Se puede personalizar
            'servicios_financieros' => $default_template, // Se puede personalizar
            'salud' => $default_template // Se puede personalizar
        ), $saved_templates);
    }
    
    /**
     * Construir prompt usando plantilla
     */
    private function build_prompt($conversation, $template_name, $context = array()) {
        $template = $this->get_prompt_template($template_name);
        
        // Reemplazar placeholders básicos
        $prompt = str_replace('{CONVERSATION}', $conversation, $template);
        
        // Agregar contexto del cliente si está disponible
        if (!empty($context)) {
            $context_text = $this->format_client_context($context);
            $prompt = str_replace('{CONVERSATION}', $context_text . "\n\n" . $conversation, $prompt);
        }
        
        return $prompt;
    }
    
    /**
     * Formatear contexto del cliente
     */
    private function format_client_context($context) {
        $context_parts = array();
        
        if (isset($context['client_name'])) {
            $context_parts[] = "Cliente: {$context['client_name']}";
        }
        
        if (isset($context['previous_interactions'])) {
            $context_parts[] = "Interacciones previas: {$context['previous_interactions']}";
        }
        
        if (isset($context['client_segment'])) {
            $context_parts[] = "Segmento: {$context['client_segment']}";
        }
        
        return "CONTEXTO DEL CLIENTE:\n" . implode("\n", $context_parts);
    }
    
    /**
     * Realizar request a Claude AI
     */
    private function send_request($prompt, $max_tokens = null) {
        if ($max_tokens === null) {
            $max_tokens = $this->api_config['max_tokens'];
        }
        
        $data = array(
            'model' => $this->api_config['model'],
            'max_tokens' => $max_tokens,
            'messages' => array(
                array('role' => 'user', 'content' => $prompt)
            )
        );
        
        $headers = array(
            'Content-Type' => 'application/json',
            'x-api-key' => $this->api_key,
            'anthropic-version' => $this->api_config['version']
        );
        
        $response = wp_remote_post($this->api_config['api_url'], array(
            'timeout' => $this->api_config['timeout'],
            'headers' => $headers,
            'body' => json_encode($data)
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'Error de conexión: ' . $response->get_error_message()
            );
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($http_code !== 200) {
            $error_data = json_decode($body, true);
            $error_message = isset($error_data['error']['message']) ? 
                $error_data['error']['message'] : 
                "HTTP Error {$http_code}";
                
            return array(
                'success' => false,
                'message' => $error_message,
                'http_code' => $http_code
            );
        }
        
        $claude_response = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array(
                'success' => false,
                'message' => 'Respuesta JSON inválida de Claude AI'
            );
        }
        
        if (!isset($claude_response['content'][0]['text'])) {
            return array(
                'success' => false,
                'message' => 'Formato de respuesta inesperado'
            );
        }
        
        return array(
            'success' => true,
            'content' => $claude_response['content'][0]['text'],
            'usage' => $claude_response['usage'] ?? array()
        );
    }
    
    /**
     * Procesar respuesta de análisis
     */
    private function process_analysis_response($raw_response) {
        // Limpiar respuesta
        $clean_response = trim($raw_response);
        $clean_response = preg_replace('/```json\s*/', '', $clean_response);
        $clean_response = preg_replace('/```\s*$/', '', $clean_response);
        
        // Intentar decodificar JSON
        $analysis = json_decode($clean_response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Intentar fallback parsing
            return $this->fallback_response_parsing($raw_response);
        }
        
        // Validar campos requeridos
        $required_fields = array(
            'sentiment_score', 'urgency_level', 'category', 
            'client_satisfaction', 'opportunities', 'recommendations', 
            'next_action', 'keywords'
        );
        
        $missing_fields = array();
        foreach ($required_fields as $field) {
            if (!isset($analysis[$field])) {
                $missing_fields[] = $field;
            }
        }
        
        if (!empty($missing_fields)) {
            return array(
                'success' => false,
                'message' => 'Campos faltantes en respuesta: ' . implode(', ', $missing_fields)
            );
        }
        
        // Validar rangos
        if (!gurux_btx_ia_is_valid_sentiment_score($analysis['sentiment_score'])) {
            $analysis['sentiment_score'] = 5.0; // Valor por defecto
        }
        
        if (!gurux_btx_ia_is_valid_urgency_level($analysis['urgency_level'])) {
            $analysis['urgency_level'] = 'medium';
        }
        
        if (!gurux_btx_ia_is_valid_client_satisfaction($analysis['client_satisfaction'])) {
            $analysis['client_satisfaction'] = 'neutral';
        }
        
        return array(
            'success' => true,
            'analysis' => $analysis
        );
    }
    
    /**
     * Parsing de fallback si JSON falla
     */
    private function fallback_response_parsing($response) {
        $fallback = array(
            'sentiment_score' => 5.0,
            'urgency_level' => 'medium',
            'category' => 'Consulta general',
            'client_satisfaction' => 'neutral',
            'opportunities' => 'Sin oportunidades detectadas',
            'recommendations' => 'Realizar seguimiento estándar',
            'next_action' => 'Contactar cliente en 24 horas',
            'keywords' => array()
        );
        
        // Intentar extraer información básica con regex
        if (preg_match('/sentiment.*?(\d+\.?\d*)/i', $response, $matches)) {
            $score = floatval($matches[1]);
            if ($score >= 0 && $score <= 10) {
                $fallback['sentiment_score'] = $score;
            }
        }
        
        if (preg_match('/urgency.*?(low|medium|high|critical)/i', $response, $matches)) {
            $fallback['urgency_level'] = strtolower($matches[1]);
        }
        
        $this->logger->warning('Usando parsing de fallback para respuesta de Claude');
        
        return array(
            'success' => true,
            'analysis' => $fallback
        );
    }
    
    
        
    // POR ESTO:
    private function is_valid_api_key($api_key) {
        return !empty($api_key) && strpos($api_key, 'sk-ant-') === 0;
    }
    /**
     * Generar clave de cache
     */
    private function generate_cache_key($conversation, $template) {
        return md5($conversation . $template . date('H')); // Cache por hora
    }
    
    /**
     * Obtener análisis desde cache
     */
    private function get_cached_analysis($cache_key) {
        $cached = gurux_btx_ia_get_cache('claude_analysis_' . $cache_key);
        
        if ($cached && isset($cached['expires_at']) && $cached['expires_at'] > time()) {
            return $cached['result'];
        }
        
        return false;
    }
    
    /**
     * Guardar análisis en cache
     */
    private function cache_analysis($cache_key, $result) {
        $cache_data = array(
            'result' => $result,
            'expires_at' => time() + 3600 // 1 hora
        );
        
        gurux_btx_ia_set_cache('claude_analysis_' . $cache_key, $cache_data, 3600);
    }
    
    /**
     * Cargar estadísticas diarias
     */
    private function load_daily_stats() {
        $today = current_time('Y-m-d');
        $saved_stats = get_option('gurux_btx_ia_claude_daily_stats', array());
        
        if (!isset($saved_stats['date']) || $saved_stats['date'] !== $today) {
            $this->reset_daily_stats();
        } else {
            $this->daily_stats = $saved_stats;
        }
    }
    
    /**
     * Actualizar estadísticas diarias
     */
    private function update_daily_stats($cost, $response_time) {
        $this->daily_stats['analyses_count']++;
        $this->daily_stats['total_cost'] += $cost;
        $this->daily_stats['total_response_time'] += $response_time;
        
        update_option('gurux_btx_ia_claude_daily_stats', $this->daily_stats);
    }


    //NUEVAS FUNCIONES

    public function get_cost_estimation($analysis_count = null, $avg_conversation_length = null) {
        if ($analysis_count === null) {
            $analysis_count = gurux_btx_ia_get_setting('daily_analysis_limit', 1000);
        }
        
        if ($avg_conversation_length === null) {
            $avg_conversation_length = 500;
        }
        
        $input_cost_per_token = 0.00025 / 1000;
        $output_cost_per_token = 0.00125 / 1000;
        
        $estimated_input_tokens = ($avg_conversation_length * 1.2) + 400;
        $estimated_output_tokens = 150;
        
        $cost_per_analysis = ($estimated_input_tokens * $input_cost_per_token) + 
                            ($estimated_output_tokens * $output_cost_per_token);
        
        $daily_cost = $cost_per_analysis * $analysis_count;
        $weekly_cost = $daily_cost * 7;
        $monthly_cost = $daily_cost * 30;
        $yearly_cost = $daily_cost * 365;
        
        $current_usage = $this->get_daily_usage();
        
        return array(
            'cost_per_analysis' => round($cost_per_analysis, 6),
            'daily_estimate' => round($daily_cost, 2),
            'weekly_estimate' => round($weekly_cost, 2),
            'monthly_estimate' => round($monthly_cost, 2),
            'yearly_estimate' => round($yearly_cost, 2),
            'current_daily_usage' => array(
                'analyses_count' => $current_usage['analyses_count'],
                'cost_so_far' => round($current_usage['total_cost'], 4),
                'remaining_analyses' => $current_usage['remaining'],
                'projected_daily_cost' => $current_usage['analyses_count'] > 0 ? 
                    round(($current_usage['total_cost'] / $current_usage['analyses_count']) * $analysis_count, 2) : 0
            ),
            'assumptions' => array(
                'avg_conversation_length' => $avg_conversation_length,
                'estimated_input_tokens' => $estimated_input_tokens,
                'estimated_output_tokens' => $estimated_output_tokens,
                'daily_analysis_limit' => $analysis_count
            ),
            'price_per_token' => array(
                'input' => $input_cost_per_token,
                'output' => $output_cost_per_token,
                'model' => $this->api_config['model']
            )
        );
    }

    public function get_detailed_usage_stats($days = 7) {
        global $wpdb;
        
        $date_from = date('Y-m-d', strtotime("-{$days} days"));
        
        $daily_stats = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                DATE(analyzed_at) as analysis_date,
                COUNT(*) as analyses_count,
                AVG(sentiment_score) as avg_sentiment,
                SUM(analysis_cost) as total_cost,
                MIN(analysis_cost) as min_cost,
                MAX(analysis_cost) as max_cost
            FROM {$wpdb->prefix}gurux_btx_ia_analysis 
            WHERE DATE(analyzed_at) >= %s
            GROUP BY DATE(analyzed_at)
            ORDER BY analysis_date DESC",
            $date_from
        ), ARRAY_A);
        
        $template_stats = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                template_used,
                COUNT(*) as usage_count,
                AVG(analysis_cost) as avg_cost,
                AVG(sentiment_score) as avg_sentiment
            FROM {$wpdb->prefix}gurux_btx_ia_analysis 
            WHERE DATE(analyzed_at) >= %s AND template_used IS NOT NULL
            GROUP BY template_used
            ORDER BY usage_count DESC",
            $date_from
        ), ARRAY_A);
        
        $hourly_distribution = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                HOUR(analyzed_at) as hour_of_day,
                COUNT(*) as analyses_count,
                AVG(analysis_cost) as avg_cost
            FROM {$wpdb->prefix}gurux_btx_ia_analysis 
            WHERE DATE(analyzed_at) >= %s
            GROUP BY HOUR(analyzed_at)
            ORDER BY hour_of_day",
            $date_from
        ), ARRAY_A);
        
        $total_analyses = 0;
        $total_cost = 0;
        
        foreach ($daily_stats as $day) {
            $total_analyses += intval($day['analyses_count']);
            $total_cost += floatval($day['total_cost']);
        }
        
        return array(
            'period_days' => $days,
            'summary' => array(
                'total_analyses' => $total_analyses,
                'total_cost' => round($total_cost, 4),
                'avg_cost_per_analysis' => $total_analyses > 0 ? round($total_cost / $total_analyses, 6) : 0,
                'avg_analyses_per_day' => round($total_analyses / max(1, $days), 1)
            ),
            'daily_breakdown' => $daily_stats,
            'template_usage' => $template_stats,
            'hourly_distribution' => $hourly_distribution,
            'cost_trends' => $this->calculate_cost_trends($daily_stats)
        );
    }

    private function calculate_cost_trends($daily_stats) {
        if (count($daily_stats) < 2) {
            return array(
                'trend' => 'insufficient_data',
                'direction' => 'unknown',
                'percentage_change' => 0
            );
        }
        
        $recent_days = array_slice($daily_stats, 0, 3);
        $older_days = array_slice($daily_stats, -3);
        
        $recent_avg = array_sum(array_column($recent_days, 'total_cost')) / count($recent_days);
        $older_avg = array_sum(array_column($older_days, 'total_cost')) / count($older_days);
        
        if ($older_avg == 0) {
            return array(
                'trend' => 'no_baseline',
                'direction' => 'unknown',
                'percentage_change' => 0
            );
        }
        
        $percentage_change = (($recent_avg - $older_avg) / $older_avg) * 100;
        
        $direction = 'stable';
        if ($percentage_change > 10) {
            $direction = 'increasing';
        } elseif ($percentage_change < -10) {
            $direction = 'decreasing';
        }
        
        return array(
            'trend' => 'calculated',
            'direction' => $direction,
            'percentage_change' => round($percentage_change, 1),
            'recent_avg_cost' => round($recent_avg, 4),
            'older_avg_cost' => round($older_avg, 4)
        );
    }
    //FIN FUNCIONES
}

// Función helper global
if (!function_exists('gurux_claude_api')) {
    function gurux_claude_api() {
        return GuruX_Claude_API::get_instance();
    }
}