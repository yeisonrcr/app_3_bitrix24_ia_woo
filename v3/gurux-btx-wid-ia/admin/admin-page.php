<?php
/**
 * GuruX BTX IA - Página de Administración
 * Interface con tabs para configuración y testing
 */

if (!defined('ABSPATH')) {
    exit;
}

// Procesar formularios y mensajes
$message = '';
$message_type = '';
$auth_message = '';
$auth_type = '';

// Manejar mensajes de autorización OAuth
if (isset($_GET['auth'])) {
    switch ($_GET['auth']) {
        case 'success':
            $auth_message = 'Autorización exitosa con Bitrix24';
            $auth_type = 'success';
            break;
        case 'error':
            $auth_message = 'Error en autorización: ' . (isset($_GET['msg']) ? sanitize_text_field($_GET['msg']) : 'Error desconocido');
            $auth_type = 'error';
            break;
    }
}

// Guardar configuración Bitrix24
if (isset($_POST['save_bitrix']) && current_user_can('manage_options')) {
    check_admin_referer('gurux_btx_ia_config');
    
    $domain = sanitize_text_field($_POST['bitrix_domain'] ?? '');
    $client_id = sanitize_text_field($_POST['client_id'] ?? '');
    $client_secret = sanitize_text_field($_POST['client_secret'] ?? '');
    
    gurux_btx_ia_update_option('bitrix_domain', $domain);
    gurux_btx_ia_update_option('client_id', $client_id);
    gurux_btx_ia_update_option('client_secret', $client_secret);
    
    $message = 'Configuración de Bitrix24 guardada correctamente';
    $message_type = 'success';
}

// Guardar configuración Claude AI
if (isset($_POST['save_claude']) && current_user_can('manage_options')) {
    check_admin_referer('gurux_btx_ia_claude_config');
    
    $claude_api_key = sanitize_text_field($_POST['claude_api_key'] ?? '');
    $daily_limit = intval($_POST['daily_analysis_limit'] ?? 1000);
    
    gurux_btx_ia_update_option('claude_api_key', $claude_api_key);
    gurux_btx_ia_update_option('daily_analysis_limit', $daily_limit);
    
    $message = 'Configuración de Claude AI guardada correctamente';
    $message_type = 'success';
}

// Guardar configuración general
if (isset($_POST['save_general']) && current_user_can('manage_options')) {
    check_admin_referer('gurux_btx_ia_general_config');
    
    gurux_btx_ia_update_option('auto_create_deals', isset($_POST['auto_create_deals']));
    gurux_btx_ia_update_option('auto_create_contacts', isset($_POST['auto_create_contacts']));
    gurux_btx_ia_update_option('sentiment_threshold', floatval($_POST['sentiment_threshold'] ?? 3.0));
    gurux_btx_ia_update_option('default_country_code', sanitize_text_field($_POST['default_country_code'] ?? '+506'));
    gurux_btx_ia_update_option('timezone', sanitize_text_field($_POST['timezone'] ?? 'America/Costa_Rica'));
    gurux_btx_ia_update_option('delete_data_on_uninstall', isset($_POST['delete_data_on_uninstall']));
    
    $message = 'Configuración general guardada correctamente';
    $message_type = 'success';
}

// Obtener valores actuales de configuración
$domain = gurux_btx_ia_get_option('bitrix_domain', '');
$client_id = gurux_btx_ia_get_option('client_id', '');
$client_secret = gurux_btx_ia_get_option('client_secret', '');
$access_token = gurux_btx_ia_get_option('access_token', '');
$claude_api_key = gurux_btx_ia_get_option('claude_api_key', '');
$daily_limit = gurux_btx_ia_get_option('daily_analysis_limit', 1000);

// Verificar estado de configuración
$is_configured = gurux_btx_ia_is_configured();
$has_tokens = gurux_btx_ia_has_valid_tokens();

// Obtener estado de conexión con Bitrix24
$bitrix_status = array(
    'configured' => false,
    'authorized' => false,
    'connected' => false,
    'message' => 'Configuración incompleta'
);

if (gurux_btx_ia_api()) {
    $bitrix_status = gurux_btx_ia_api()->get_connection_status();
}

// Generar URL de autorización OAuth
$auth_url = '';
$show_auth_button = false;

if (!empty($domain) && !empty($client_id) && !empty($client_secret)) {
    $api = gurux_btx_ia_api();
    if ($api) {
        $auth_url = $api->get_auth_url();
        $show_auth_button = !$has_tokens;
    }
}

// Determinar tab activo
$current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'bitrix';
?>

<div class="wrap gurux-wrap">
    <h1>GuruX BTX IA - Configuración</h1>
    
    <?php if ($message): ?>
    <div class="notice notice-<?php echo $message_type; ?> is-dismissible">
        <p><?php echo esc_html($message); ?></p>
    </div>
    <?php endif; ?>
    
    <?php if ($auth_message): ?>
    <div class="notice notice-<?php echo $auth_type; ?> is-dismissible">
        <p><?php echo esc_html($auth_message); ?></p>
    </div>
    <?php endif; ?>
    
    <!-- Navegación por tabs -->
    <div class="gurux-tab-nav">
        <div class="gurux-tab <?php echo $current_tab === 'bitrix' ? 'active' : ''; ?>" data-tab="tab-bitrix">
            Bitrix24
        </div>
        <div class="gurux-tab <?php echo $current_tab === 'claude' ? 'active' : ''; ?>" data-tab="tab-claude">
            Claude AI
        </div>
        <div class="gurux-tab <?php echo $current_tab === 'general' ? 'active' : ''; ?>" data-tab="tab-general">
            General
        </div>
        <div class="gurux-tab <?php echo $current_tab === 'testing' ? 'active' : ''; ?>" data-tab="tab-testing">
            Testing
        </div>
        <div class="gurux-tab <?php echo $current_tab === 'status' ? 'active' : ''; ?>" data-tab="tab-status">
            Estado
        </div>
        <div class="gurux-tab <?php echo $current_tab === 'dashboard' ? 'active' : ''; ?>" data-tab="tab-dashboard">
            Dashboard
        </div>


    </div>





    
    <!-- TAB: Configuración Bitrix24 -->
    <div id="tab-bitrix" class="gurux-tab-content <?php echo $current_tab === 'bitrix' ? 'active' : ''; ?>">
        <div class="gurux-card">
            <h2>Configuración de Bitrix24</h2>
            
            <!-- Panel de estado de conexión -->
            <div class="gurux-status-panel">
                <h4>Estado de Conexión</h4>
                <div id="bitrix-status-indicator">
                    <?php if ($bitrix_status['connected']): ?>
                        <p class="gurux-status success">✅ Conectado correctamente</p>
                        <small>Autorizado el: <?php echo $bitrix_status['authorized_at'] ? date('d/m/Y H:i', strtotime($bitrix_status['authorized_at'])) : 'N/A'; ?></small>
                    <?php elseif ($bitrix_status['authorized']): ?>
                        <p class="gurux-status error">⚠️ Autorizado pero sin conexión</p>
                        <small><?php echo esc_html($bitrix_status['message']); ?></small>
                    <?php elseif ($bitrix_status['configured']): ?>
                        <p class="gurux-status">⏳ Configurado - Requiere autorización</p>
                    <?php else: ?>
                        <p class="gurux-status">❌ Sin configurar</p>
                    <?php endif; ?>
                </div>
                
                <!-- Botones de estado -->
                <div style="margin-top: 10px;">
                    <button type="button" class="button" onclick="checkBitrixStatus()" id="btn-check-status">
                        Verificar Estado
                    </button>
                    
                    <?php if ($bitrix_status['authorized']): ?>
                    <button type="button" class="button" onclick="testBitrixConnection()" id="btn-test-connection">
                        Probar Conexión
                    </button>
                    <button type="button" class="button button-secondary" onclick="clearBitrixTokens()" id="btn-clear-tokens">
                        Revocar Autorización
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Formulario de configuración -->
            <form method="post" action="">
                <?php wp_nonce_field('gurux_btx_ia_config'); ?>
                
                <div class="gurux-form-row">
                    <label for="bitrix_domain">Dominio de Bitrix24 *</label>
                    <input type="text" id="bitrix_domain" name="bitrix_domain" value="<?php echo esc_attr($domain); ?>" class="regular-text" placeholder="miempresa.bitrix24.es" required />
                    <p class="description">Tu dominio de Bitrix24 (sin https://)</p>
                </div>
                
                <div class="gurux-form-row">
                    <label for="client_id">Client ID *</label>
                    <input type="text" id="client_id" name="client_id" value="<?php echo esc_attr($client_id); ?>" class="regular-text" required />
                    <p class="description">ID de la aplicación en Bitrix24</p>
                </div>
                
                <div class="gurux-form-row">
                    <label for="client_secret">Client Secret *</label>
                    <input type="password" id="client_secret" name="client_secret" value="<?php echo esc_attr($client_secret); ?>" class="regular-text" required />
                    <p class="description">Clave secreta de la aplicación</p>
                </div>
                
                <p class="submit">
                    <input type="submit" name="save_bitrix" class="button button-primary" value="Guardar Configuración" />
                </p>
            </form>
            
            <!-- Sección de autorización OAuth -->
            <?php if ($bitrix_status['configured'] && !$bitrix_status['authorized'] && $auth_url): ?>
            <hr />
            <div class="gurux-auth-section">
                <h3>🔐 Autorización Requerida</h3>
                <p>Para conectar con Bitrix24, necesitas autorizar el plugin. Este proceso es seguro y solo se hace una vez.</p>
                
                <div style="margin: 15px 0;">
                    <a href="<?php echo esc_url($auth_url); ?>" class="button button-primary button-large" id="btn-authorize">
                        🚀 Autorizar con Bitrix24
                    </a>
                </div>
                
                <!-- Información técnica plegable -->
                <details style="margin-top: 15px;">
                    <summary>Información técnica</summary>
                    <div style="margin-top: 10px; padding: 10px; background: #f1f1f1; border-radius: 4px;">
                        <p><strong>Redirect URI configurada:</strong></p>
                        <code>
                            <?php echo admin_url('admin.php?page=gurux-btx-ia&action=oauth'); ?>
                        </code>
                        <p style="margin-top: 10px;"><small>Asegúrate de que esta URL esté configurada exactamente igual en tu aplicación Bitrix24.</small></p>
                    </div>
                </details>
            </div>
            <?php endif; ?>
            
            <!-- Confirmación de conexión activa -->
            <?php if ($bitrix_status['connected']): ?>
            <hr />
            <div class="gurux-connection-info">
                <h4>✅ Conexión Activa</h4>
                <p>Plugin conectado correctamente con tu Bitrix24. Ya puedes usar todas las funcionalidades.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- TAB: Configuración Claude AI -->
    <div id="tab-claude" class="gurux-tab-content <?php echo $current_tab === 'claude' ? 'active' : ''; ?>">
        <div class="gurux-card">
            <h2>Configuración de Claude AI</h2>
            
            <form method="post" action="">
                <?php wp_nonce_field('gurux_btx_ia_claude_config'); ?>
                
                <div class="gurux-form-row">
                    <label for="claude_api_key">API Key de Claude</label>
                    <input type="password" id="claude_api_key" name="claude_api_key" value="<?php echo esc_attr($claude_api_key); ?>" class="regular-text" placeholder="sk-ant-api03-..." />
                    <p class="description">Tu clave API de Anthropic Claude (modelo Haiku)</p>
                </div>
                
                <div class="gurux-form-row">
                    <label for="daily_analysis_limit">Límite diario de análisis</label>
                    <input type="number" id="daily_analysis_limit" name="daily_analysis_limit" value="<?php echo esc_attr($daily_limit); ?>" min="1" max="10000" />
                    <p class="description">Número máximo de análisis permitidos por día</p>
                </div>
                
                <p class="submit">
                    <input type="submit" name="save_claude" class="button button-primary" value="Guardar Configuración" />
                </p>
            </form>
            
            <!-- Estado y testing de Claude AI -->
            <?php if (!empty($claude_api_key)): ?>
            <hr />
            <h3>Estado de Claude AI</h3>
            <div id="claude-connection-status">
                <p class="gurux-status">API Key configurada</p>
            </div>
            <p>
                <button type="button" class="button" onclick="testClaudeConnection()">Probar Conexión</button>
                <button type="button" class="button" onclick="testClaudeAnalysis()">Test de Análisis</button>
            </p>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- TAB: Configuración General -->
    <div id="tab-general" class="gurux-tab-content <?php echo $current_tab === 'general' ? 'active' : ''; ?>">
        <div class="gurux-card">
            <h2>Configuración General</h2>
            
            <form method="post" action="">
                <?php wp_nonce_field('gurux_btx_ia_general_config'); ?>
                
                <!-- Configuración de automatización -->
                <h3>Automatización</h3>
                
                <div class="gurux-form-row">
                    <label>
                        <input type="checkbox" name="auto_create_deals" <?php checked(gurux_btx_ia_get_option('auto_create_deals', true)); ?> />
                        Crear deals automáticamente
                    </label>
                    <p class="description">Crear deals en Bitrix24 basados en el análisis IA</p>
                </div>
                
                <div class="gurux-form-row">
                    <label>
                        <input type="checkbox" name="auto_create_contacts" <?php checked(gurux_btx_ia_get_option('auto_create_contacts', true)); ?> />
                        Crear contactos automáticamente
                    </label>
                    <p class="description">Crear contactos nuevos si no existen</p>
                </div>
                
                <div class="gurux-form-row">
                    <label for="sentiment_threshold">Umbral de sentimiento para deals</label>
                    <input type="number" id="sentiment_threshold" name="sentiment_threshold" value="<?php echo esc_attr(gurux_btx_ia_get_option('sentiment_threshold', 3.0)); ?>" min="0" max="10" step="0.5" />
                    <p class="description">Puntuación mínima de sentimiento para crear deals (0-10)</p>
                </div>
                
                <!-- Configuración regional -->
                <h3>Configuración Regional</h3>
                
                <div class="gurux-form-row">
                    <label for="default_country_code">Código de país por defecto</label>
                    <input type="text" id="default_country_code" name="default_country_code" value="<?php echo esc_attr(gurux_btx_ia_get_option('default_country_code', '+506')); ?>" />
                    <p class="description">Código de país para números de teléfono</p>
                </div>
                
                <div class="gurux-form-row">
                    <label for="timezone">Zona horaria</label>
                    <select id="timezone" name="timezone">
                        <?php
                        $current_tz = gurux_btx_ia_get_option('timezone', 'America/Costa_Rica');
                        $timezones = timezone_identifiers_list();
                        foreach ($timezones as $tz) {
                            if (strpos($tz, 'America/') === 0) {
                                echo '<option value="' . esc_attr($tz) . '" ' . selected($current_tz, $tz, false) . '>' . esc_html($tz) . '</option>';
                            }
                        }
                        ?>
                    </select>
                </div>
                
                <!-- Configuración de mantenimiento -->
                <h3>Mantenimiento</h3>
                
                <div class="gurux-form-row">
                    <label>
                        <input type="checkbox" name="delete_data_on_uninstall" <?php checked(gurux_btx_ia_get_option('delete_data_on_uninstall', false)); ?> />
                        Eliminar todos los datos al desinstalar
                    </label>
                    <p class="description">⚠️ Esto eliminará permanentemente todas las tablas y datos del plugin</p>
                </div>
                
                <p class="submit">
                    <input type="submit" name="save_general" class="button button-primary" value="Guardar Configuración" />
                </p>
            </form>
        </div>


    </div>
    



    <!-- TAB: Dashboard de Supervisión NUEVO -->
    <div id="tab-dashboard" class="gurux-tab-content <?php echo $current_tab === 'dashboard' ? 'active' : ''; ?>">
        
        <!-- Panel de métricas principales -->
        <div class="gurux-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2>📊 Dashboard de Supervisión</h2>
                <div>
                    <button type="button" class="button" onclick="refreshDashboard()" id="btn-refresh-dashboard">
                        🔄 Actualizar
                    </button>
                    <select id="dashboard-period" onchange="updateDashboardPeriod()">
                        <option value="7">Últimos 7 días</option>
                        <option value="30">Últimos 30 días</option>
                        <option value="90">Últimos 90 días</option>
                    </select>
                </div>
            </div>
            
            <!-- Métricas principales en tiempo real -->
            <div class="gurux-stats" id="main-metrics">
                <div class="gurux-stat-box">
                    <div class="gurux-stat-number" id="metric-total-analyses">...</div>
                    <div class="gurux-stat-label">Análisis Totales</div>
                </div>
                <div class="gurux-stat-box">
                    <div class="gurux-stat-number" id="metric-avg-sentiment">...</div>
                    <div class="gurux-stat-label">Satisfacción Promedio</div>
                </div>
                <div class="gurux-stat-box">
                    <div class="gurux-stat-number" id="metric-critical-cases">...</div>
                    <div class="gurux-stat-label">Casos Críticos</div>
                </div>
                <div class="gurux-stat-box">
                    <div class="gurux-stat-number" id="metric-clients-risk">...</div>
                    <div class="gurux-stat-label">Clientes en Riesgo</div>
                </div>
            </div>
        </div>

        <!-- Alertas del sistema -->
        <div id="dashboard-alerts" class="gurux-card" style="display: none;">
            <h3>🚨 Alertas del Sistema</h3>
            <div id="alerts-container"></div>
        </div>

        <!-- Gráficos y análisis -->
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 20px;">
            
            <!-- Tendencia de satisfacción -->
            <div class="gurux-card">
                <h3>📈 Tendencia de Satisfacción</h3>
                <div id="satisfaction-trend-chart" style="height: 300px;">
                    <div style="text-align: center; padding: 100px 0; color: #666;">
                        Cargando gráfico...
                    </div>
                </div>
            </div>
            
            <!-- Distribución de urgencia -->
            <div class="gurux-card">
                <h3>⚡ Distribución por Urgencia</h3>
                <div id="urgency-distribution" style="height: 300px;">
                    <div style="text-align: center; padding: 100px 0; color: #666;">
                        Cargando datos...
                    </div>
                </div>
            </div>
        </div>

        <!-- Casos críticos pendientes -->
        <div class="gurux-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h3>🚨 Casos Críticos Pendientes</h3>
                <button type="button" class="button button-small" onclick="refreshCriticalCases()">
                    Actualizar
                </button>
            </div>
            
            <div id="critical-cases-container">
                <table class="gurux-table" id="critical-cases-table">
                    <thead>
                        <tr>
                            <th>Cliente</th>
                            <th>Sentimiento</th>
                            <th>Urgencia</th>
                            <th>Tiempo</th>
                            <th>Prioridad</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody id="critical-cases-tbody">
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px; color: #666;">
                                Cargando casos críticos...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Rendimiento de agentes -->
        <div class="gurux-card">
            <h3>👥 Rendimiento de Agentes</h3>
            
            <div id="agents-performance-container">
                <div style="text-align: center; padding: 40px; color: #666;">
                    Cargando datos de agentes...
                </div>
            </div>
        </div>

        <!-- Insights y recomendaciones -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            
            <!-- Insights automáticos -->
            <div class="gurux-card">
                <h3>💡 Insights Automáticos</h3>
                <div id="automated-insights">
                    <div style="text-align: center; padding: 40px; color: #666;">
                        Generando insights...
                    </div>
                </div>
            </div>
            
            <!-- Recomendaciones -->
            <div class="gurux-card">
                <h3>🎯 Recomendaciones</h3>
                <div id="recommendations-container">
                    <div style="text-align: center; padding: 40px; color: #666;">
                        Calculando recomendaciones...
                    </div>
                </div>
            </div>
        </div>
    </div>





    <!-- TAB: Sistema de Testing Completo -->
    <div id="tab-testing" class="gurux-tab-content <?php echo $current_tab === 'testing' ? 'active' : ''; ?>">
        <div class="gurux-card">
            <h2>Centro de Testing Completo</h2>
            <p>Valida todas las conexiones y funcionalidades. Ejecuta tests individuales o en secuencia.</p>
            
            <!-- Panel de estado general del sistema -->
            <div class="gurux-status-panel">
                <h4>Estado General del Sistema</h4>
                <div id="system-status-overview">
                    <div class="gurux-status-grid">
                        <div class="status-item">
                            <span class="status-label">Bitrix24:</span>
                            <span id="status-bitrix-overview" class="status-indicator">⏳ Sin probar</span>
                        </div>
                        <div class="status-item">
                            <span class="status-label">Claude AI:</span>
                            <span id="status-claude-overview" class="status-indicator">⏳ Sin probar</span>
                        </div>
                        <div class="status-item">
                            <span class="status-label">Workflow:</span>
                            <span id="status-workflow-overview" class="status-indicator">⏳ Sin probar</span>
                        </div>
                        <div class="status-item">
                            <span class="status-label">Última limpieza:</span>
                            <span id="status-cleanup-overview" class="status-indicator">⏳ Pendiente</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Tests de Bitrix24 -->
            <div class="gurux-button-group">
                <h3>🔧 Tests de Bitrix24</h3>
                <div class="test-buttons-row">
                    <button class="button gurux-test-button" onclick="runTestWithStatus('bitrix_connection', 'btn-bitrix-connection')" id="btn-bitrix-connection">
                        <span class="button-icon">🔗</span> Test Conexión Básica
                    </button>
                    <button class="button gurux-test-button" onclick="runTestWithStatus('bitrix_permissions', 'btn-bitrix-permissions')" id="btn-bitrix-permissions">
                        <span class="button-icon">🔑</span> Test Permisos API
                    </button>
                    <button class="button gurux-test-button" onclick="runTestWithStatus('bitrix_create_contact', 'btn-bitrix-contact')" id="btn-bitrix-contact">
                        <span class="button-icon">👤</span> Test Crear Contacto
                    </button>
                    <button class="button gurux-test-button" onclick="runTestWithStatus('bitrix_create_deal', 'btn-bitrix-deal')" id="btn-bitrix-deal">
                        <span class="button-icon">💼</span> Test Crear Deal
                    </button>
                    <button class="button gurux-test-button" onclick="runTestWithStatus('bitrix_list_pipelines', 'btn-bitrix-pipelines')" id="btn-bitrix-pipelines">
                        <span class="button-icon">📊</span> Listar Pipelines
                    </button>
                </div>
            </div>
            
            <!-- Tests de Claude AI -->
            <div class="gurux-button-group">
                <h3>🤖 Tests de Claude AI</h3>
                <div class="test-buttons-row">
                    <button class="button gurux-test-button" onclick="runTestWithStatus('claude_connection', 'btn-claude-connection')" id="btn-claude-connection">
                        <span class="button-icon">🔗</span> Test Conexión API
                    </button>
                    <button class="button gurux-test-button" onclick="runTestWithStatus('claude_spanish', 'btn-claude-spanish')" id="btn-claude-spanish">
                        <span class="button-icon">🇪🇸</span> Test Análisis Español
                    </button>
                    <button class="button gurux-test-button" onclick="runTestWithStatus('claude_json_response', 'btn-claude-json')" id="btn-claude-json">
                        <span class="button-icon">📋</span> Test Respuesta JSON
                    </button>
                    <button class="button gurux-test-button" onclick="runTestWithStatus('claude_cost_estimate', 'btn-claude-cost')" id="btn-claude-cost">
                        <span class="button-icon">💰</span> Estimar Costos
                    </button>
                </div>
            </div>
            
            <!-- Tests de Workflow Completo -->
            <div class="gurux-button-group">
                <h3>🔄 Tests de Workflow Completo</h3>
                <div class="test-buttons-row">
                    <button class="button gurux-test-button" onclick="runTestWithStatus('workflow_positive', 'btn-workflow-positive')" id="btn-workflow-positive">
                        <span class="button-icon">😊</span> Test Conversación Positiva
                    </button>
                    <button class="button gurux-test-button" onclick="runTestWithStatus('workflow_negative', 'btn-workflow-negative')" id="btn-workflow-negative">
                        <span class="button-icon">😞</span> Test Conversación Negativa
                    </button>
                    <button class="button gurux-test-button" onclick="runTestWithStatus('workflow_opportunity', 'btn-workflow-opportunity')" id="btn-workflow-opportunity">
                        <span class="button-icon">💡</span> Test Oportunidad de Venta
                    </button>
                    <button class="button gurux-test-button" onclick="runTestWithStatus('workflow_complete', 'btn-workflow-complete')" id="btn-workflow-complete">
                        <span class="button-icon">🚀</span> Test Workflow Completo
                    </button>
                    
                    <button class="button gurux-test-button" onclick="runTestWithStatus('test_dashboard', 'btn-test-dashboard')" id="btn-test-dashboard">
                        <span class="button-icon">📊</span> Test Dashboard Completo
                    </button>
                    
                    <button class="button gurux-test-button" onclick="runTestWithStatus('create_sample_data', 'btn-create-sample')" id="btn-create-sample">
                        <span class="button-icon">📝</span> Crear Datos de Muestra
                    </button>
                    
                </div>
            </div>
            
            <!-- Utilidades y mantenimiento -->
            <div class="gurux-button-group">
                <h3>🛠️ Utilidades y Mantenimiento</h3>
                <div class="test-buttons-row">
                    <button class="button gurux-test-button" onclick="runTestWithStatus('cleanup_test_data', 'btn-cleanup')" id="btn-cleanup">
                        <span class="button-icon">🧹</span> Limpiar Datos de Prueba
                    </button>
                    <button class="button button-secondary" onclick="runAllTests()" id="btn-run-all">
                        <span class="button-icon">⚡</span> Ejecutar Todos los Tests
                    </button>
                    <button class="button button-secondary" onclick="exportTestReport()" id="btn-export">
                        <span class="button-icon">📄</span> Exportar Reporte
                    </button>
                    <button class="button" onclick="clearTestLog()" id="btn-clear-log">
                        <span class="button-icon">🗑️</span> Limpiar Log
                    </button>
                </div>
            </div>
            
            <!-- Barra de progreso de testing -->
            <div class="test-progress-section">
                <h4>Progreso de Testing</h4>
                <div id="test-progress-bar" class="progress-bar">
                    <div id="progress-fill" class="progress-fill"></div>
                </div>
                <div id="test-summary" class="test-summary">
                    <span id="tests-passed">0</span> exitosos | 
                    <span id="tests-failed">0</span> fallidos | 
                    <span id="tests-total">0</span> total
                </div>
            </div>
        </div>
        
        <!-- Panel de logs en tiempo real -->
        <div class="gurux-card">
            <h3>📋 Log de Testing en Tiempo Real</h3>
            <div class="log-controls">
                <button class="button button-small" onclick="toggleAutoScroll()" id="btn-auto-scroll">
                    <span class="button-icon">📜</span> Auto Scroll: ON
                </button>
                <button class="button button-small" onclick="filterLogs('all')" id="btn-filter-all">Todos</button>
                <button class="button button-small" onclick="filterLogs('success')" id="btn-filter-success">Éxitos</button>
                <button class="button button-small" onclick="filterLogs('error')" id="btn-filter-error">Errores</button>
                <button class="button button-small" onclick="filterLogs('info')" id="btn-filter-info">Info</button>
            </div>
            <div id="gurux-log-output" class="gurux-log"></div>
            <div class="log-stats">
                <small id="log-stats">Total de entradas: 0 | Última actualización: Nunca</small>
            </div>
        </div>
    </div>
    
    <!-- TAB: Estado del Sistema -->
    <div id="tab-status" class="gurux-tab-content <?php echo $current_tab === 'status' ? 'active' : ''; ?>">
        <div class="gurux-card">
            <h2>Estado del Sistema</h2>
            
            <!-- Tabla de componentes del sistema -->
            <table class="gurux-table">
                <tr>
                    <th>Componente</th>
                    <th>Estado</th>
                    <th>Detalles</th>
                </tr>
                <tr>
                    <td>Bitrix24 API</td>
                    <td id="status-bitrix"><?php echo $has_tokens ? '✅ Conectado' : '❌ No conectado'; ?></td>
                    <td><?php echo $has_tokens ? 'Tokens configurados' : 'Requiere autorización'; ?></td>
                </tr>
                <tr>
                    <td>Claude AI</td>
                    <td id="status-claude"><?php echo !empty($claude_api_key) ? '✅ Configurado' : '❌ No configurado'; ?></td>
                    <td><?php echo !empty($claude_api_key) ? 'API Key presente' : 'Falta API Key'; ?></td>
                </tr>
                <tr>
                    <td>Base de Datos</td>
                    <td id="status-database">✅ OK</td>
                    <td>Tablas creadas correctamente</td>
                </tr>
                <tr>
                    <td>Logs</td>
                    <td id="status-logs">✅ OK</td>
                    <td>Sistema de logs funcionando</td>
                </tr>
            </table>
        </div>
        
        <!-- Estadísticas de uso -->
        <div class="gurux-card">
            <h2>Estadísticas de Uso</h2>
            <div class="gurux-stats">
                <div class="gurux-stat-box">
                    <div class="gurux-stat-number" id="stat-analyses">0</div>
                    <div class="gurux-stat-label">Análisis Hoy</div>
                </div>
                <div class="gurux-stat-box">
                    <div class="gurux-stat-number" id="stat-sentiment">0.0</div>
                    <div class="gurux-stat-label">Sentimiento Promedio</div>
                </div>
                <div class="gurux-stat-box">
                    <div class="gurux-stat-number" id="stat-deals">0</div>
                    <div class="gurux-stat-label">Deals Creados</div>
                </div>
                <div class="gurux-stat-box">
                    <div class="gurux-stat-number" id="stat-cost">$0.00</div>
                    <div class="gurux-stat-label">Costo Estimado</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    jQuery(document).ready(function($) {
        console.log('GuruX BTX IA Admin iniciado');
        
        // ============================================================================
        // SISTEMA DE NAVEGACIÓN POR TABS - CORREGIDO
        // ============================================================================
        
        // Función para activar un tab específico
        function activateTab(tabId) {
            console.log('Activando tab:', tabId);
            
            // Remover clase active de todos los tabs y contenidos
            $('.gurux-tab').removeClass('active');
            $('.gurux-tab-content').removeClass('active');
            
            // Activar el tab clickeado
            $('.gurux-tab[data-tab="' + tabId + '"]').addClass('active');
            $('#' + tabId).addClass('active');
            
            // Actualizar URL sin recargar página
            if (history.pushState) {
                var newUrl = window.location.protocol + "//" + window.location.host + 
                            window.location.pathname + '?page=gurux-btx-ia&tab=' + tabId.replace('tab-', '');
                window.history.pushState({path: newUrl}, '', newUrl);
            }
        }
        
        // Event handler para clicks en tabs
        $(document).on('click', '.gurux-tab', function(e) {
            e.preventDefault();
            var tabId = $(this).data('tab');
            console.log('Tab clickeado:', tabId);
            
            if (tabId) {
                activateTab(tabId);
            }
        });
        
        // Verificar que el tab inicial esté activo
        var currentTab = $('.gurux-tab.active').data('tab');
        if (!currentTab) {
            // Si no hay tab activo, activar el primero
            var firstTab = $('.gurux-tab').first().data('tab');
            if (firstTab) {
                activateTab(firstTab);
            }
        }
        
        // ============================================================================
        // VARIABLES GLOBALES DE TESTING
        // ============================================================================
        
        window.testResults = {
            passed: 0,
            failed: 0,
            total: 0,
            autoScroll: true,
            logCount: 0
        };
        
        window.dashboardData = {
            period: 7,
            lastUpdate: null,
            autoRefresh: true,
            refreshInterval: null
        };
        
        // ============================================================================
        // FUNCIONES AJAX Y HELPERS
        // ============================================================================
        
        window.guruxAjax = function(action, data, callback) {
            data = data || {};
            data.action = action;
            data.nonce = '<?php echo wp_create_nonce('gurux_btx_ia_nonce'); ?>';
            
            console.log('AJAX Call:', action, data);
            
            $.post(ajaxurl, data, function(response) {
                console.log('AJAX Response:', response);
                if (typeof callback === 'function') {
                    callback(response);
                }
            }).fail(function(xhr, status, error) {
                console.error('AJAX Error:', error);
                if (typeof callback === 'function') {
                    callback({success: false, data: {message: 'Error de conexión: ' + error}});
                }
            });
        };
        
        window.guruxLog = function(message, type = 'info', details = null) {
            var timestamp = new Date().toLocaleTimeString();
            var icon = getLogIcon(type);
            var logEntry = '<div class="log-entry log-' + type + '" data-type="' + type + '">' +
                        '<span class="log-timestamp">[' + timestamp + ']</span> ' +
                        '<span class="log-icon">' + icon + '</span> ' +
                        '<span class="log-message">' + message + '</span>';
            
            if (details) {
                logEntry += '<div class="log-details"><pre>' + JSON.stringify(details, null, 2) + '</pre></div>';
            }
            
            logEntry += '</div>';
            
            $('#gurux-log-output').append(logEntry);
            
            if (window.testResults.autoScroll) {
                $('#gurux-log-output').scrollTop($('#gurux-log-output')[0].scrollHeight);
            }
            
            window.testResults.logCount++;
            updateLogStats();
        };
        
        function getLogIcon(type) {
            const icons = {
                'success': '✅',
                'error': '❌',
                'warning': '⚠️',
                'info': 'ℹ️',
                'debug': '🔍'
            };
            return icons[type] || 'ℹ️';
        }
        
        function updateLogStats() {
            $('#log-stats').text('Total de entradas: ' + window.testResults.logCount + ' | Última actualización: ' + new Date().toLocaleTimeString());
        }
        
        function updateTestProgress() {
            var percentage = window.testResults.total > 0 ? ((window.testResults.passed + window.testResults.failed) / window.testResults.total) * 100 : 0;
            $('#progress-fill').css('width', percentage + '%');
            $('#tests-passed').text(window.testResults.passed);
            $('#tests-failed').text(window.testResults.failed);
            $('#tests-total').text(window.testResults.total);
        }
        
        // ============================================================================
        // FUNCIONES DE TESTING
        // ============================================================================
        
        function setButtonState(buttonId, state) {
            var btn = $('#' + buttonId);
            if (!btn.length) return;
            
            var icon = btn.find('.button-icon');
            var originalIcon = icon.text();
            
            btn.removeClass('test-success test-error test-running');
            
            switch(state) {
                case 'running':
                    btn.addClass('test-running').prop('disabled', true);
                    icon.text('⏳');
                    break;
                case 'success':
                    btn.addClass('test-success').prop('disabled', false);
                    icon.text('✅');
                    setTimeout(() => { 
                        icon.text(originalIcon); 
                        btn.removeClass('test-success'); 
                    }, 3000);
                    break;
                case 'error':
                    btn.addClass('test-error').prop('disabled', false);
                    icon.text('❌');
                    setTimeout(() => { 
                        icon.text(originalIcon); 
                        btn.removeClass('test-error'); 
                    }, 5000);
                    break;
                default:
                    btn.prop('disabled', false);
                    icon.text(originalIcon);
            }
        }
        
        window.runTestWithStatus = function(testType, buttonId) {
            setButtonState(buttonId, 'running');
            guruxLog('🚀 Iniciando test: ' + testType, 'info');
            
            guruxAjax('gurux_btx_ia_run_test', {
                test_type: testType
            }, function(response) {
                if (response.success) {
                    setButtonState(buttonId, 'success');
                    guruxLog('✅ ' + response.data.message, 'success', response.data.details);
                    window.testResults.passed++;
                    updateSystemStatus(testType, true);
                } else {
                    setButtonState(buttonId, 'error');
                    guruxLog('❌ Error en ' + testType + ': ' + (response.data ? response.data.message : 'Error desconocido'), 'error', response.data);
                    window.testResults.failed++;
                    updateSystemStatus(testType, false);
                }
                updateTestProgress();
            });
        };
        
        function updateSystemStatus(testType, success) {
            var statusMap = {
                'bitrix_connection': 'status-bitrix-overview',
                'bitrix_permissions': 'status-bitrix-overview', 
                'claude_connection': 'status-claude-overview',
                'claude_spanish': 'status-claude-overview',
                'workflow_complete': 'status-workflow-overview',
                'cleanup_test_data': 'status-cleanup-overview'
            };
            
            var statusId = statusMap[testType];
            if (statusId) {
                var statusEl = $('#' + statusId);
                if (statusEl.length) {
                    if (success) {
                        statusEl.text('✅ Funcionando').removeClass('status-error').addClass('status-success');
                    } else {
                        statusEl.text('❌ Con errores').removeClass('status-success').addClass('status-error');
                    }
                }
            }
        }
        
        // ============================================================================
        // FUNCIONES DE BITRIX24
        // ============================================================================
        
        window.checkBitrixStatus = function() {
            console.log('Verificando estado de Bitrix24...');
            const btn = $('#btn-check-status');
            if (!btn.length) return;
            
            const originalText = btn.text();
            btn.text('Verificando...').prop('disabled', true);
            
            guruxAjax('gurux_btx_ia_test_bitrix_connection', {}, function(response) {
                btn.text(originalText).prop('disabled', false);
                
                const indicator = $('#bitrix-status-indicator');
                if (!indicator.length) return;
                
                if (response.success) {
                    guruxLog('✅ ' + response.data.message, 'success');
                    indicator.html('<p class="gurux-status success">✅ ' + response.data.message + '</p>');
                    updateBitrixButtons(true);
                } else {
                    guruxLog('❌ ' + response.data.message, 'error');
                    indicator.html('<p class="gurux-status error">❌ ' + response.data.message + '</p>');
                    if (response.data && response.data.needs_reauth) {
                        indicator.append('<small>Requiere nueva autorización</small>');
                    }
                    updateBitrixButtons(false);
                }
            });
        };
        
        window.testBitrixConnection = function() {
            guruxLog('Probando conexión con Bitrix24...', 'info');
            checkBitrixStatus();
        };
        
        window.clearBitrixTokens = function() {
            if (!confirm('¿Estás seguro de revocar la autorización?\n\nEsto eliminará los tokens de acceso y necesitarás autorizar nuevamente el plugin.')) {
                return;
            }
            
            const btn = $('#btn-clear-tokens');
            if (!btn.length) return;
            
            const originalText = btn.text();
            btn.text('Revocando...').prop('disabled', true);
            
            guruxAjax('gurux_btx_ia_clear_tokens', {}, function(response) {
                btn.text(originalText).prop('disabled', false);
                
                if (response.success) {
                    guruxLog('✅ ' + response.data.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    guruxLog('❌ Error: ' + (response.data ? response.data.message : 'Error desconocido'), 'error');
                }
            });
        };
        
        function updateBitrixButtons(connected) {
            const testBtn = $('#btn-test-connection');
            const clearBtn = $('#btn-clear-tokens');
            
            if (testBtn.length) testBtn.toggle(connected);
            if (clearBtn.length) clearBtn.toggle(connected);
        }
        
        // ============================================================================
        // FUNCIONES DE CLAUDE AI
        // ============================================================================
        
        window.testClaudeConnection = function() {
            runTestWithStatus('claude_connection', 'btn-claude-connection');
        };
        
        window.testClaudeAnalysis = function() {
            runTestWithStatus('claude_spanish', 'btn-claude-spanish');
        };
        
        // ============================================================================
        // FUNCIONES DE DASHBOARD
        // ============================================================================
        
        window.refreshDashboard = function() {
            console.log('Refrescando dashboard...');
            loadMainMetrics();
            loadSatisfactionTrend();
            loadCriticalCases();
            loadAgentsPerformance();
            loadInsightsAndRecommendations();
            
            $('#btn-refresh-dashboard').text('🔄 Actualizando...').prop('disabled', true);
            
            setTimeout(() => {
                $('#btn-refresh-dashboard').text('🔄 Actualizar').prop('disabled', false);
            }, 2000);
        };
        
        function loadMainMetrics() {
            guruxAjax('gurux_btx_ia_get_dashboard_metrics', {
                days: window.dashboardData.period
            }, function(response) {
                if (response.success) {
                    updateMainMetrics(response.data);
                    checkForAlerts(response.data);
                }
            });
        }
        
        function updateMainMetrics(data) {
            const summary = data.summary || {};
            
            $('#metric-total-analyses').text(formatNumber(summary.total_analyses || 0));
            $('#metric-avg-sentiment').text(formatSentiment(summary.avg_sentiment || 0));
            $('#metric-critical-cases').text(formatNumber(summary.critical_cases || 0));
            $('#metric-clients-risk').text(formatNumber(summary.clients_at_risk || 0));
            
            updateMetricColors(summary);
        }
        
        function updateMetricColors(summary) {
            const sentimentColor = getSentimentColor(summary.avg_sentiment || 0);
            $('#metric-avg-sentiment').css('color', sentimentColor);
            
            if (summary.critical_cases > 10) {
                $('#metric-critical-cases').css('color', '#dc3545');
            } else {
                $('#metric-critical-cases').css('color', '#28a745');
            }
            
            if (summary.clients_at_risk > 5) {
                $('#metric-clients-risk').css('color', '#fd7e14');
            } else {
                $('#metric-clients-risk').css('color', '#6c757d');
            }
        }
        
        // ============================================================================
        // FUNCIONES DE UTILIDAD
        // ============================================================================
        
        function formatNumber(num) {
            return new Intl.NumberFormat().format(num);
        }
        
        function formatSentiment(score) {
            return parseFloat(score).toFixed(1) + '/10';
        }
        
        function getSentimentColor(score) {
            if (score >= 8.0) return '#28a745';
            if (score >= 6.5) return '#17a2b8';
            if (score >= 5.0) return '#ffc107';
            if (score >= 3.0) return '#fd7e14';
            return '#dc3545';
        }
        
        function checkForAlerts(data) {
            // Placeholder para alertas
        }
        
        function loadSatisfactionTrend() {
            // Placeholder
        }
        
        function loadCriticalCases() {
            // Placeholder
        }
        
        function loadAgentsPerformance() {
            // Placeholder
        }
        
        function loadInsightsAndRecommendations() {
            // Placeholder
        }
        
        // ============================================================================
        // FUNCIONES ADICIONALES
        // ============================================================================
        
        window.updateDashboardPeriod = function() {
            window.dashboardData.period = parseInt($('#dashboard-period').val());
            refreshDashboard();
        };
        
        window.runAllTests = function() {
            console.log('Ejecutando todos los tests...');
            guruxLog('🎯 Iniciando secuencia completa de tests', 'info');
        };
        
        window.toggleAutoScroll = function() {
            window.testResults.autoScroll = !window.testResults.autoScroll;
            $('#btn-auto-scroll').text('📜 Auto Scroll: ' + (window.testResults.autoScroll ? 'ON' : 'OFF'));
        };
        
        window.clearTestLog = function() {
            $('#gurux-log-output').html('');
            window.testResults.logCount = 0;
            updateLogStats();
            guruxLog('📋 Log limpiado', 'info');
        };
        
        window.filterLogs = function(type) {
            $('.log-entry').show();
            if (type !== 'all') {
                $('.log-entry:not(.log-' + type + ')').hide();
            }
            
            $('.log-controls button').removeClass('button-primary');
            $('#btn-filter-' + type).addClass('button-primary');
        };
        
        window.exportTestReport = function() {
            guruxLog('📄 Funcionalidad de exportación en desarrollo', 'info');
        };
        
        // ============================================================================
        // INICIALIZACIÓN AUTOMÁTICA
        // ============================================================================
        
        // Auto-verificación al cambiar tabs
        $(document).on('click', '.gurux-tab[data-tab="tab-bitrix"]', function() {
            setTimeout(() => {
                if (typeof checkBitrixStatus === 'function') {
                    checkBitrixStatus();
                }
            }, 500);
        });
        
        // Cargar estadísticas en tab de estado
        $(document).on('click', '.gurux-tab[data-tab="tab-status"]', function() {
            setTimeout(() => {
                loadStatistics();
            }, 300);
        });
        
        // Inicializar dashboard cuando se active
        $(document).on('click', '.gurux-tab[data-tab="tab-dashboard"]', function() {
            setTimeout(() => {
                if (!window.dashboardData.lastUpdate) {
                    refreshDashboard();
                }
            }, 300);
        });
        
        function loadStatistics() {
            guruxAjax('gurux_btx_ia_get_stats', {}, function(response) {
                if (response.success) {
                    $('#stat-analyses').text(response.data.analyses_today || '0');
                    $('#stat-sentiment').text(response.data.average_sentiment || '0.0');
                    $('#stat-deals').text(response.data.deals_created || '0');
                    $('#stat-cost').text('$' + (response.data.estimated_cost || '0.00'));
                }
            });
        }
        
        // Log inicial
        console.log('GuruX BTX IA Admin Scripts cargados correctamente');
    });
</script>
