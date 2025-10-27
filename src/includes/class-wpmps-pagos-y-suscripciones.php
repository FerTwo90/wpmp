<?php
if (!defined('ABSPATH')) exit;

class WPMPS_Payments_Subscriptions {
  const TABLE_SLUG    = 'wpmps_mapping';
  const OPTION_DB_VER = 'wpmps_mapping_db_version';
  const DB_VERSION    = '1.2.0';

  /**
   * Inicializa hooks para asegurar la tabla.
   */
  public static function init(){
    add_action('init', [__CLASS__, 'maybe_install_table']);
  }

  /**
   * Retorna el nombre completo de la tabla.
   */
  public static function table_name(){
    global $wpdb;
    return $wpdb->prefix . self::TABLE_SLUG;
  }

  /**
   * Verifica y crea la tabla si corresponde.
   */
  public static function maybe_install_table(){
    global $wpdb;
    $table   = self::table_name();
    $exists  = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    $version = get_option(self::OPTION_DB_VER);

    if ($exists !== $table || $version !== self::DB_VERSION) {
      self::install_table();
    }
  }

  /**
   * Define el esquema consolidado de pagos/suscripciones.
   */
  public static function install_table(){
    global $wpdb;
    $table   = self::table_name();
    $collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      preapproval_id varchar(64) NOT NULL,
      plan_id varchar(64) DEFAULT NULL,
      plan_name varchar(255) DEFAULT NULL,
      amount decimal(10,2) DEFAULT NULL,
      payer_first_name varchar(100) DEFAULT NULL,
      payer_last_name varchar(100) DEFAULT NULL,
      payer_email varchar(191) DEFAULT NULL,
      payer_identification varchar(64) DEFAULT NULL,
      user_id bigint(20) unsigned DEFAULT NULL,
      status varchar(32) DEFAULT NULL,
      created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      payer_id varchar(32) DEFAULT NULL,
      payment_ids TEXT DEFAULT NULL,
      PRIMARY KEY  (id),
      UNIQUE KEY preapproval_id (preapproval_id),
      KEY plan_id (plan_id),
      KEY status (status),
      KEY payer_id (payer_id),
      KEY payer_email (payer_email)
    ) {$collate};";

    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    update_option(self::OPTION_DB_VER, self::DB_VERSION, false);
  }

  /**
   * Devuelve todas las suscripciones con sus pagos asociados.
   */
  public static function get_subscriptions($filters = []) {
    global $wpdb;
    self::maybe_install_table();

    $table  = self::table_name();
    $limit  = isset($filters['limit']) ? max(1, intval($filters['limit'])) : 25;
    $offset = isset($filters['offset']) ? max(0, intval($filters['offset'])) : 0;

    $where   = [];
    $params  = [];

    if (!empty($filters['status'])) {
      $where[] = 'status = %s';
      $params[] = sanitize_text_field($filters['status']);
    }

    if (!empty($filters['plan_id'])) {
      $where[] = 'plan_id = %s';
      $params[] = sanitize_text_field($filters['plan_id']);
    }

    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    $order_sql = 'ORDER BY created_at DESC';
    $query_sql = "SELECT * FROM {$table} {$where_sql} {$order_sql} LIMIT %d OFFSET %d";
    $query_params = array_merge($params, [$limit, $offset]);

    $prepared_query = $wpdb->prepare($query_sql, $query_params);
    $rows = $wpdb->get_results($prepared_query, ARRAY_A);

    $count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
    $prepared_count = !empty($params) ? $wpdb->prepare($count_sql, $params) : $count_sql;
    $total = intval($wpdb->get_var($prepared_count));

    return [
      'success' => true,
      'subscriptions' => $rows ?: [],
      'total'   => $total,
      'limit'   => $limit,
      'offset'  => $offset,
    ];
  }



  /**
   * Si la tabla no tiene suscripciones, obtiene las últimas desde MP y las inserta.
   */
  public static function bootstrap_subscriptions_if_empty($limit = 25){
    global $wpdb;
    self::maybe_install_table();
    $table = self::table_name();

    $existing = intval($wpdb->get_var("SELECT COUNT(*) FROM {$table}"));

    if ($existing > 0) {
      return [
        'seeded'  => false,
        'message' => ''
      ];
    }

    if (!class_exists('WPMPS_Subscribers')) {
      return [
        'seeded'  => false,
        'message' => __('No se encontró la clase de suscriptores para poblar la tabla.', 'wp-mp-subscriptions')
      ];
    }

    $latest = WPMPS_Subscribers::get_latest_subscriptions($limit);
    if (empty($latest['success']) || empty($latest['subscriptions'])) {
      return [
        'seeded'  => false,
        'message' => !empty($latest['message'])
          ? $latest['message']
          : __('Mercado Pago no devolvió suscripciones para inicializar la tabla.', 'wp-mp-subscriptions')
      ];
    }

    // Obtener planes para mapear nombres
    $plans_map = [];
    if (class_exists('WPMPS_Sync')) {
      $plans = WPMPS_Sync::get_plans();
      foreach ($plans as $plan) {
        $plans_map[$plan['id']] = $plan['name'];
      }
    }

    $inserted = 0;
    foreach ($latest['subscriptions'] as $sub) {
      $created_at = self::normalize_datetime($sub['date_created'] ?? ($sub['created_at'] ?? ''));

      // Extraer payer_id correctamente desde la estructura de la suscripción
      $payer_id = '';
      if (isset($sub['payer']['id'])) {
        $payer_id = sanitize_text_field((string)$sub['payer']['id']);
      } elseif (isset($sub['payer_id'])) {
        $payer_id = sanitize_text_field((string)$sub['payer_id']);
      }

      $plan_id = sanitize_text_field($sub['preapproval_plan_id'] ?? ($sub['plan_id'] ?? ''));
      $plan_name = isset($plans_map[$plan_id]) ? $plans_map[$plan_id] : '';

      $data = [
        'preapproval_id'=> sanitize_text_field($sub['id'] ?? ($sub['preapproval_id'] ?? '')),
        'plan_id'       => $plan_id,
        'plan_name'     => $plan_name,
        'amount'        => isset($sub['auto_recurring']['transaction_amount']) ? floatval($sub['auto_recurring']['transaction_amount']) : (isset($sub['amount']) ? floatval($sub['amount']) : null),
        'payer_first_name' => sanitize_text_field($sub['payer']['first_name'] ?? ($sub['payer_first_name'] ?? '')),
        'payer_last_name'  => sanitize_text_field($sub['payer']['last_name'] ?? ($sub['payer_last_name'] ?? '')),
        'payer_email'      => sanitize_email($sub['payer']['email'] ?? ($sub['payer_email'] ?? '')),
        'payer_identification' => sanitize_text_field($sub['payer']['identification']['number'] ?? ''),
        'user_id'       => null,
        'status'        => sanitize_text_field($sub['status'] ?? ''),
        'created_at'    => $created_at,
        'payer_id'      => $payer_id,
        'payment_ids'   => '', // Se llenará con los pagos
      ];

      $result = $wpdb->insert($table, $data);
      if ($result !== false) {
        $inserted++;
      }
    }

    return [
      'seeded'  => $inserted > 0,
      'inserted'=> $inserted,
      'message' => $inserted > 0
        ? sprintf(__('Se cargaron %d suscripciones recientes desde Mercado Pago.', 'wp-mp-subscriptions'), $inserted)
        : __('No se pudieron insertar suscripciones en la tabla.', 'wp-mp-subscriptions')
    ];
  }



  /**
   * Sincroniza pagos para las suscripciones existentes
   */
  public static function force_sync_all_payments($limit = 100) {
    global $wpdb;
    self::maybe_install_table();
    $table = self::table_name();

    $subscriptions = $wpdb->get_results(
      $wpdb->prepare("SELECT * FROM {$table} WHERE payer_id <> '' LIMIT %d", max(1, intval($limit))),
      ARRAY_A
    );

    if (empty($subscriptions)) {
      return [
        'seeded'  => false,
        'message' => __('No hay suscripciones con payer_id para sincronizar pagos.', 'wp-mp-subscriptions')
      ];
    }

    $token = function_exists('wpmps_get_access_token') ? wpmps_get_access_token() : '';
    if (empty($token)) {
      return [
        'seeded'  => false,
        'message' => __('No se encontró Access Token de Mercado Pago para consultar pagos.', 'wp-mp-subscriptions')
      ];
    }

    try {
      $client = new WPMPS_MP_Client($token);
    } catch (\Throwable $e) {
      if (function_exists('wpmps_log_error')){
        wpmps_log_error('payments', 'client_init_error', $e->getMessage());
      }
      return [
        'seeded'  => false,
        'message' => __('No se pudo inicializar el cliente de Mercado Pago para pagos.', 'wp-mp-subscriptions')
      ];
    }

    $updated = 0;
    $processed_subs = 0;

    foreach ($subscriptions as $subscription) {
      $processed_subs++;
      $payer_id = $subscription['payer_id'];
      
      // Buscar pagos por payer.id usando la API de Mercado Pago
      $response = $client->search_payments([
        'sort'     => 'date_created',
        'criteria' => 'desc',
        'limit'    => 100,
        'payer.id' => $payer_id
      ]);

      $http_code = $response['http'] ?? 0;
      $body = $response['body'] ?? [];
      $results = isset($body['results']) && is_array($body['results']) ? $body['results'] : [];

      if ($http_code !== 200 || empty($results)) {
        continue;
      }

      $payment_ids = [];
      foreach ($results as $payment) {
        $payment_id = sanitize_text_field((string)($payment['id'] ?? ''));
        if ($payment_id) {
          $payment_ids[] = $payment_id;
        }
      }

      if (!empty($payment_ids)) {
        // Obtener datos del pagador del primer pago para actualizar la suscripción
        $first_payment = $results[0];
        $payer_email = sanitize_email($first_payment['payer']['email'] ?? '');
        $payer_identification = '';
        if (!empty($first_payment['payer']['identification']['number'])) {
          $payer_identification = sanitize_text_field(
            trim(($first_payment['payer']['identification']['type'] ?? '').' '.$first_payment['payer']['identification']['number'])
          );
        }

        $update_data = ['payment_ids' => implode(',', $payment_ids)];
        
        // Solo actualizar email y documento si están vacíos en la suscripción
        if (empty($subscription['payer_email']) && !empty($payer_email)) {
          $update_data['payer_email'] = $payer_email;
        }
        if (empty($subscription['payer_identification']) && !empty($payer_identification)) {
          $update_data['payer_identification'] = $payer_identification;
        }

        $wpdb->update($table, $update_data, ['id' => $subscription['id']]);
        $updated++;
      }
    }

    return [
      'seeded'  => $updated > 0,
      'updated' => $updated,
      'processed_subs' => $processed_subs,
      'message' => $updated > 0
        ? sprintf(__('Se actualizaron %1$d suscripciones con pagos consultando %2$d payer_id desde la API de Mercado Pago.', 'wp-mp-subscriptions'), $updated, $processed_subs)
        : sprintf(__('No se encontraron pagos nuevos consultando %d suscripciones desde la API de Mercado Pago.', 'wp-mp-subscriptions'), $processed_subs)
    ];
  }

  /**
   * Obtiene estadísticas básicas de la tabla
   */
  public static function get_stats() {
    global $wpdb;
    self::maybe_install_table();
    $table = self::table_name();

    $stats = [
      'total_subscriptions' => 0,
      'total_payments' => 0,
      'matched_payments' => 0,
      'unmatched_payments' => 0,
      'unique_payers' => 0,
      'last_sync' => get_transient('wpmps_payments_last_sync')
    ];

    $stats['total_subscriptions'] = intval($wpdb->get_var(
      $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE event_type = %s", 'subscription')
    ));

    $stats['total_payments'] = intval($wpdb->get_var(
      $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE event_type = %s", 'payment')
    ));

    $stats['matched_payments'] = intval($wpdb->get_var(
      $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE event_type = %s AND preapproval_id <> ''", 'payment')
    ));

    $stats['unmatched_payments'] = $stats['total_payments'] - $stats['matched_payments'];

    $stats['unique_payers'] = intval($wpdb->get_var(
      $wpdb->prepare("SELECT COUNT(DISTINCT payer_id) FROM {$table} WHERE payer_id <> ''")
    ));

    return $stats;
  }

  /**
   * Limpia el caché de sincronización
   */
  public static function clear_sync_cache() {
    delete_transient('wpmps_payments_last_sync');
    return true;
  }

  /**
   * Borra toda la tabla para reiniciar el proceso
   */
  public static function reset_table() {
    global $wpdb;
    $table = self::table_name();
    
    $result = $wpdb->query("TRUNCATE TABLE {$table}");
    
    if ($result !== false) {
      self::clear_sync_cache();
      return [
        'success' => true,
        'message' => __('Tabla reiniciada correctamente. Se volverán a cargar los datos en la próxima sincronización.', 'wp-mp-subscriptions')
      ];
    } else {
      return [
        'success' => false,
        'message' => __('Error al reiniciar la tabla.', 'wp-mp-subscriptions')
      ];
    }
  }

  private static function normalize_datetime($value){
    if (empty($value)) {
      return current_time('mysql');
    }
    $ts = strtotime($value);
    if (!$ts) {
      return current_time('mysql');
    }
    return gmdate('Y-m-d H:i:s', $ts);
  }
}
