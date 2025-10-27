<?php
if (!defined('ABSPATH')) exit;

class WPMPS_Payments_Subscriptions {
  const TABLE_SLUG    = 'wpmps_mapping';
  const OPTION_DB_VER = 'wpmps_mapping_db_version';
  const DB_VERSION    = '1.1.0';

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
      event_type varchar(32) NOT NULL,
      preapproval_id varchar(64) DEFAULT NULL,
      payment_id varchar(64) DEFAULT NULL,
      plan_id varchar(64) DEFAULT NULL,
      user_id bigint(20) unsigned DEFAULT NULL,
      payer_id varchar(32) DEFAULT NULL,
      payer_first_name varchar(100) DEFAULT NULL,
      payer_last_name varchar(100) DEFAULT NULL,
      payer_email varchar(191) DEFAULT NULL,
      payer_identification varchar(64) DEFAULT NULL,
      amount decimal(10,2) DEFAULT NULL,
      currency varchar(8) DEFAULT NULL,
      status varchar(32) DEFAULT NULL,
      created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at datetime DEFAULT NULL,
      PRIMARY KEY  (id),
      KEY event_type (event_type),
      KEY preapproval_id (preapproval_id),
      KEY payment_id (payment_id),
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
   * Devuelve registros marcados como pagos.
   */
  public static function get_payments($filters = []) {
    $result = self::get_records_by_type('payment', $filters);
    $result['payments'] = $result['rows'];
    return $result;
  }

  /**
   * Devuelve registros marcados como suscripciones.
   */
  public static function get_subscriptions($filters = []) {
    $result = self::get_records_by_type('subscription', $filters);
    $result['subscriptions'] = $result['rows'];
    return $result;
  }

  /**
   * Consulta rápida a la tabla consolidada.
   */
  private static function get_records_by_type($type, $filters = []) {
    global $wpdb;
    self::maybe_install_table();

    $table  = self::table_name();
    $limit  = isset($filters['limit']) ? max(1, intval($filters['limit'])) : 25;
    $offset = isset($filters['offset']) ? max(0, intval($filters['offset'])) : 0;

    $where   = ['event_type = %s'];
    $params  = [$type];

    if (!empty($filters['status'])) {
      $where[] = 'status = %s';
      $params[] = sanitize_text_field($filters['status']);
    }

    if (!empty($filters['plan_id'])) {
      $where[] = 'plan_id = %s';
      $params[] = sanitize_text_field($filters['plan_id']);
    }

    if (!empty($filters['preapproval_id'])) {
      $where[] = 'preapproval_id = %s';
      $params[] = sanitize_text_field($filters['preapproval_id']);
    }

    if (!empty($filters['payment_id'])) {
      $where[] = 'payment_id = %s';
      $params[] = sanitize_text_field($filters['payment_id']);
    }

    $where_sql = 'WHERE ' . implode(' AND ', $where);

    $order_sql = 'ORDER BY created_at DESC';
    $query_sql = "SELECT * FROM {$table} {$where_sql} {$order_sql} LIMIT %d OFFSET %d";
    $query_params = array_merge($params, [$limit, $offset]);

    $prepared_query = $wpdb->prepare($query_sql, $query_params);
    $rows = $wpdb->get_results($prepared_query, ARRAY_A);

    $count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
    $prepared_count = $wpdb->prepare($count_sql, $params);
    $total = intval($wpdb->get_var($prepared_count));

    return [
      'success' => true,
      'rows'    => $rows ?: [],
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

    $existing = intval($wpdb->get_var(
      $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE event_type = %s", 'subscription')
    ));

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

    $inserted = 0;
    foreach ($latest['subscriptions'] as $sub) {
      $created_at = self::normalize_datetime($sub['created_at'] ?? '');
      $updated_at = self::normalize_datetime($sub['updated_at'] ?? '');

      $data = [
        'event_type'    => 'subscription',
        'preapproval_id'=> sanitize_text_field($sub['preapproval_id'] ?? ''),
        'payment_id'    => null,
        'plan_id'       => sanitize_text_field($sub['plan_id'] ?? ''),
        'user_id'       => null,
        'payer_id'      => sanitize_text_field((string)($sub['payer_id'] ?? '')),
        'payer_first_name' => sanitize_text_field($sub['payer_first_name'] ?? ''),
        'payer_last_name'  => sanitize_text_field($sub['payer_last_name'] ?? ''),
        'payer_email'      => sanitize_email($sub['payer_email'] ?? ''),
        'payer_identification' => '',
        'amount'        => isset($sub['amount']) ? floatval($sub['amount']) : null,
        'currency'      => sanitize_text_field($sub['currency'] ?? ''),
        'status'        => sanitize_text_field($sub['status'] ?? ''),
        'created_at'    => $created_at,
        'updated_at'    => $updated_at,
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

  public static function sync_payments_for_subscriptions($limit = 25){
    global $wpdb;
    self::maybe_install_table();
    $table = self::table_name();

    $preapproval_ids = $wpdb->get_col(
      $wpdb->prepare(
        "SELECT DISTINCT preapproval_id FROM {$table} WHERE event_type = %s AND preapproval_id <> '' ORDER BY created_at DESC LIMIT %d",
        'subscription',
        max(1, intval($limit))
      )
    );

    $payer_ids = $wpdb->get_col(
      $wpdb->prepare(
        "SELECT DISTINCT payer_id FROM {$table} WHERE event_type = %s AND payer_id <> '' ORDER BY id DESC LIMIT %d",
        'subscription',
        max(1, intval($limit))
      )
    );

    if (empty($preapproval_ids) && empty($payer_ids)) {
      return [
        'seeded'  => false,
        'message' => __('No hay suscripciones con datos suficientes para mapear pagos.', 'wp-mp-subscriptions')
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

    $normalized_pre_ids = array_filter(array_map('strval', $preapproval_ids));
    $inserted = 0;
    $processed_payers = 0;

    foreach ($payer_ids as $payer_id) {
      $processed_payers++;
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
        if (function_exists('wpmps_log_error')){
          $body_preview = is_scalar($body) ? $body : wp_json_encode($body);
          if (is_string($body_preview) && strlen($body_preview) > 500) {
            $body_preview = substr($body_preview, 0, 500) . '...';
          }
          wpmps_log_error('payments', 'mp_fetch_error', 'No se pudieron obtener pagos para un payer id', [
            'http_code' => $http_code,
            'payer_id'  => $payer_id,
            'body'      => $body_preview
          ]);
        }
        continue;
      }

      foreach ($results as $payment) {
        $pre_id = sanitize_text_field($payment['metadata']['preapproval_id'] ?? ($payment['preapproval_id'] ?? ''));
        $matches_preapproval = $pre_id && in_array($pre_id, $normalized_pre_ids, true);

        $payment_id = sanitize_text_field((string)($payment['id'] ?? ''));
        if (!$payment_id) continue;

        $exists = $wpdb->get_var($wpdb->prepare(
          "SELECT id FROM {$table} WHERE event_type = %s AND payment_id = %s LIMIT 1",
          'payment',
          $payment_id
        ));
        if (!$exists) {
          $amount   = isset($payment['transaction_amount']) ? floatval($payment['transaction_amount']) : null;
          $currency = sanitize_text_field($payment['currency_id'] ?? '');
          $status   = sanitize_text_field($payment['status'] ?? '');
          $plan_id  = sanitize_text_field($payment['metadata']['plan_id'] ?? ($payment['plan_id'] ?? ''));
          $created_at = self::normalize_datetime($payment['date_created'] ?? '');
          $updated_at = self::normalize_datetime($payment['date_last_updated'] ?? '');
          $payer_email = sanitize_email($payment['payer']['email'] ?? '');
          $payer_identification = '';
          if (!empty($payment['payer']['identification']['number'])) {
            $payer_identification = sanitize_text_field(
              trim(($payment['payer']['identification']['type'] ?? '').' '.$payment['payer']['identification']['number'])
            );
          }

          $data = [
            'event_type'    => 'payment',
            'preapproval_id'=> $matches_preapproval ? $pre_id : '',
            'payment_id'    => $payment_id,
            'plan_id'       => $plan_id,
            'user_id'       => null,
            'payer_id'      => sanitize_text_field($payer_id),
            'payer_first_name' => sanitize_text_field($payment['payer']['first_name'] ?? ''),
            'payer_last_name'  => sanitize_text_field($payment['payer']['last_name'] ?? ''),
            'payer_email'      => $payer_email,
            'payer_identification' => $payer_identification,
            'amount'        => $amount,
            'currency'      => $currency,
            'status'        => $status,
            'created_at'    => $created_at,
            'updated_at'    => $updated_at,
          ];

          $insert = $wpdb->insert($table, $data);
          if ($insert !== false) {
            $inserted++;
          }
        }

        $update_data = array_filter([
          'payer_email' => sanitize_email($payment['payer']['email'] ?? '') ?: null,
          'payer_identification' => !empty($payment['payer']['identification']['number'])
            ? sanitize_text_field(trim(($payment['payer']['identification']['type'] ?? '').' '.$payment['payer']['identification']['number']))
            : null,
          'payer_first_name' => sanitize_text_field($payment['payer']['first_name'] ?? ''),
          'payer_last_name'  => sanitize_text_field($payment['payer']['last_name'] ?? ''),
        ], function($value){
          return $value !== null && $value !== '';
        });

        if ($update_data) {
          $where = ['event_type' => 'subscription'];
          if ($matches_preapproval) {
            $where['preapproval_id'] = $pre_id;
          } else {
            $where['payer_id'] = sanitize_text_field($payer_id);
          }
          $wpdb->update($table, $update_data, $where);
        }
      }
    }

    return [
      'seeded'  => $inserted > 0,
      'inserted'=> $inserted,
      'message' => $inserted > 0
        ? sprintf(__('Se cargaron %1$d pagos vinculados consultando %2$d payer_id.', 'wp-mp-subscriptions'), $inserted, $processed_payers)
        : __('No se encontraron pagos nuevos para los payer_id actuales.', 'wp-mp-subscriptions')
    ];
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
