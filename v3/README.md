# GuruX BTX IA

**Versión:** 1.0  
**Autor:** [GuruX Devs]  
**Licencia:** GPLv2 o superior  
**Requiere:** WordPress 6.0+, PHP 7.4+, WooCommerce (opcional), Cuenta en Bitrix24 y clave API Claude (Anthropic)

---

## 🧠 ¿Qué es GuruX BTX IA?

GuruX BTX IA es un plugin avanzado de WordPress que actúa como un **puente inteligente entre las conversaciones de atención al cliente** y las **acciones automatizadas en el CRM Bitrix24**, utilizando para ello inteligencia artificial (Claude AI de Anthropic).

El plugin **analiza automáticamente conversaciones de chat/soporte**, **detecta oportunidades de negocio**, **segmenta clientes** y **automatiza tareas en Bitrix24**, generando un flujo comercial más inteligente y eficiente.

---

## 🎯 Funcionalidades Principales

### 📊 Análisis Inteligente de Conversaciones
- Clasificación del **sentimiento** del cliente (0–10)
- Evaluación del **nivel de urgencia** (crítico, alto, medio, bajo)
- Medición de la **satisfacción** (en riesgo, ayuda, satisfecho)
- Detección de **categoría de la consulta** y **palabras clave relevantes**
- Identificación de **oportunidades comerciales** y generación de **recomendaciones de acción**

### 🔁 Automatización Comercial (Bitrix24 CRM)
- Creación automática de **contactos**
- Generación de **negocios (deals)** y asignación a usuarios específicos
- **Asignación de tareas** de seguimiento según urgencia
- Cálculo automatizado del **valor de la oportunidad**
- Determinación de la **etapa** del pipeline adecuada

### 👥 Gestión Inteligente de Clientes
- Segmentación dinámica: VIP, leales, en riesgo, nuevos
- Registro de historial de satisfacción y métricas
- Cálculo del **Customer Lifetime Value (CLV)**
- Detección de clientes con riesgo de abandono

### 📈 Supervisión y Analytics
- Dashboard con **métricas clave en tiempo real**
- Detección de tendencias y **alertas proactivas**
- Análisis del **rendimiento de agentes de soporte**

---

## ⚙️ Arquitectura del Sistema

El sistema está diseñado con orientación a objetos (OOP), implementando el patrón **Singleton** para evitar instancias múltiples. Se utilizan ganchos de WordPress (`hooks`) y técnicas de integración API modernas.

### 🧩 Componentes Principales

- `GuruX_BTX_IA_Main`: Controlador central del plugin.
- `GuruX_Chat_Analyzer`: Analizador IA de conversaciones, usando Claude AI.
- `Bitrix24_API_Manager`: Interfaz con el CRM Bitrix24 (OAuth2, REST).
- `Client_Manager`: Segmentación y cálculo de métricas.
- `Deals_Manager`: Gestión automatizada de negocios y oportunidades.

---

## 🔄 Flujo de Procesamiento

```plaintext
Conversación + Teléfono →
  Limpieza y Formateo →
    Envío a Claude AI →
      Análisis Estructurado (JSON) →
        Enriquecimiento con contexto histórico →
          Automatización en Bitrix24 →
            Almacenamiento en BD local →
              Dashboard & Métricas
```


🧠 Lógica de Análisis IA (Claude)
Uso de Plantillas Personalizadas
Claude AI requiere contexto preciso para responder en formato estructurado (JSON). Se usan prompts dinámicos con plantillas para distintos sectores (e-commerce, telecom, salud, etc.).

⚠️ Nota: Se incluye validación de la respuesta JSON y control de errores para evitar malformaciones o sobrecostos de API.

Cache Multinivel
Cache de análisis (TTL 30 minutos)

Cache de respuestas Bitrix24

Cache de clientes y contactos

🔐 Seguridad y Buenas Prácticas
Validación estricta de entradas (sanitize, validate)

Uso de nonces y comprobación de capacidades (current_user_can)

Manejo robusto de errores (try/catch, logs rotativos)

Protección contra accesos no autorizados y fugas de datos

📡 Integración con Bitrix24
Autenticación
Soporte completo para OAuth2 (access_token, refresh_token)

Manejo de tokens seguros y almacenamiento cifrado

Funcionalidades API
crm.contact.add / update / list

crm.deal.add / update

tasks.task.add

Sincronización parcial de leads/contactos (mejorable)

Referencia Oficial:
Bitrix24 REST API Docs

📦 Estado Actual del Proyecto
Área	Estado	Observaciones
Análisis IA	✅ Completo	Claude API funcional
Integración Bitrix24	✅ Completa (básica)	CRUD + tareas
Dashboard y UI	⚠️ Parcial	HTML base, sin JS
Notificaciones críticas	❌ Faltante	No hay integración con email/SMS
Sincronización Bidireccional	⚠️ Parcial	Principalmente de Woo ➜ Bitrix
Optimización de rendimiento	❌ Faltante	Sin colas o procesamiento asíncrono
Multiidioma	❌ Faltante	Hardcoded en español

🚀 Instalación y Requisitos
Clonar el repositorio o subir el plugin como .zip

Activar desde el panel de WordPress

Ir a GuruX BTX IA > Configuración

Ingresar claves de Bitrix24 y Claude API

Probar con conversaciones de ejemplo

🛠️ Recomendaciones de Mejora
Área	Siguiente Paso
📊 Dashboard UI	Conectar backend + frontend usando Chart.js o D3.js
📨 Notificaciones	Implementar envío por email y Webhooks
🔄 Async Processing	Integrar WP-Cron + workers o Redis Queue
🌍 Multiidioma	Implementar load_plugin_textdomain() y .pot
📘 Documentación	Generar OpenAPI o Postman collection para endpoints

🧪 Testing y Logs
Logs rotativos en /wp-content/uploads/gurux-logs/

Sistema de pruebas de conexión Bitrix24/Claude

Exportación de logs en CSV

🤝 Contribuciones
¿Te gustaría colaborar? Bienvenido.

Usa issues para reportar bugs o solicitar mejoras

Envía pull requests con código documentado y probado

Respeta el estándar de codificación de WordPress

📚 Recursos y Referencias
Documentación de Claude API (Anthropic)

WordPress Plugin Developer Handbook

Bitrix24 REST API Reference

PHP: OOP y Patrones de diseño

🧩 Licencia
Este plugin está licenciado bajo GPLv2 o superior.
Puedes usarlo, modificarlo y distribuirlo bajo los términos de dicha licencia.

