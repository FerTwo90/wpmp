<?php
if (!defined('ABSPATH')) exit;

class WPMPS_Payments_Subscriptions {
  const TABLE_SLUG    = 'wpmps_mapping';
  const OPTION_DB_VER = 'wpmps_mapping_db_version';
  const DB_VERSION    = '1.0.0';

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
      KEY status (status)
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
}
