<?php
if (!defined('ABSPATH')) exit;

class WPMPS_Payments {
  
  /**
   * Obtiene los pagos de MercadoPago con mapeo a suscriptores
   */
  public static function get_payments_with_mapping($use_cache = true, $limit = 50) {
    // Intentar obtener datos del caché
    if ($use_cache) {
      $cached_data = get_transient('wpmps_payments_cache');
      if ($cached_data !== false) {
        if (function_exists('wpmps_log_admin')){
          wpmps_log_admin('get_payments_from_cache', [
            'count' => count($cached_data),
            'source' => 'transient_cache'
          ]);
        }
        return $cached_data;
      }
    }
    
    $start_time = microtime(true);
    $token = function_exists('wpmps_get_access_token') ? wpmps_get_access_token() : '';
    
    if (!$token) {
      return [
        'success' => false,
        'message' => 'No hay token de acceso configurado',
        'payments' => []
      ];
    }
    
    // Log start
    if (function_exists('wpmps_log_admin')){
      wpmps_log_admin('get_payments_start', [
        'has_token' => !empty($token),
        'limit' => $limit,
        'use_cache' => $use_cache
      ]);
    }
    
    try {
      // OBTENER TODOS LOS PAGOS CON PAGINACIÓN ITERATIVA
      $all_payments = [];
      $batch_size = 20; // Consultar de a 20 como sugeriste
      $offset = 0;
      $total_payments = null;
      $requests_made = 0;
      $max_requests = 30; // Límite de seguridad aumentado
      
      do {
        // Consultar SUSCRIPCIONES (preapprovals) en lugar de pagos
        $url = "https://api.mercadopago.com/preapproval/search?" . http_build_query([
          'limit' => $batch_size,
          'offset' => $offset
        ]);
        
        $response = wp_remote_get($url, [
          'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json'
          ],
          'timeout' => 30
        ]);
        
        $requests_made++;
        
        if (is_wp_error($response)) {
          break;
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($http_code !== 200 || empty($data['results'])) {
          break;
        }
        
        // En el primer request, obtener el total
        if ($total_payments === null) {
          $total_payments = $data['paging']['total'] ?? 0;
          if (function_exists('wpmps_log_admin')){
            wpmps_log_admin('preapprovals_pagination_start', [
              'total_preapprovals' => $total_payments,
              'batch_size' => $batch_size
            ]);
          }
        }
        
        // Agregar las suscripciones de este batch
        $batch_preapprovals = $data['results'];
        $all_payments = array_merge($all_payments, $batch_preapprovals);
        
        // Actualizar offset para el siguiente batch
        $offset += $batch_size;
        
        // Condiciones de salida
        if (count($batch_preapprovals) < $batch_size) {
          // Ya no hay más suscripciones
          break;
        }
        
        if ($requests_made >= $max_requests) {
          // Límite de seguridad alcanzado
          break;
        }
        
        if ($total_payments && count($all_payments) >= $total_payments) {
          // Ya obtuvimos todas las suscripciones
          break;
        }
        
      } while (true);
      
      if (empty($all_payments)) {
        return [
          'success' => false,
          'message' => 'No se encontraron suscripciones en MercadoPago',
          'payments' => [],
          'requests_made' => $requests_made
        ];
      }
      
      // Convertir suscripciones a formato de "pagos" para mostrar en la tabla
      $subscription_payments = [];
      foreach ($all_payments as $preapproval) {
        // Convertir suscripción a formato de pago para la tabla
        $fake_payment = [
          'id' => $preapproval['id'] ?? '',
          'status' => $preapproval['status'] ?? '',
          'status_detail' => $preapproval['status'] ?? '',
          'transaction_amount' => $preapproval['auto_recurring']['transaction_amount'] ?? 0,
          'currency_id' => $preapproval['auto_recurring']['currency_id'] ?? 'ARS',
          'date_created' => $preapproval['date_created'] ?? '',
          'date_approved' => $preapproval['date_created'] ?? '',
          'payment_method_id' => 'subscription',
          'payment_type_id' => 'subscription',
          'description' => 'Suscripción: ' . ($preapproval['reason'] ?? 'Sin descripción'),
          'external_reference' => $preapproval['external_reference'] ?? '',
          'payer' => [
            'email' => $preapproval['payer_email'] ?? '',
            'id' => $preapproval['payer_id'] ?? ''
          ],
          'collector_id' => $preapproval['collector_id'] ?? '',
          'operation_type' => 'subscription',
          'preapproval_plan_id' => $preapproval['preapproval_plan_id'] ?? '',
          'preapproval_id' => $preapproval['id'] ?? '',
          // Agregar metadata para que el mapeo funcione correctamente
          'metadata' => [
            'preapproval_id' => $preapproval['id'] ?? '',
            'plan_id' => $preapproval['preapproval_plan_id'] ?? ''
          ],
          'is_preapproval' => true // Marcar que es una suscripción, no un pago
        ];
        
        $subscription_payments[] = $fake_payment;
      }
      
      // Log de procesamiento de suscripciones
      if (function_exists('wpmps_log_admin')){
        wpmps_log_admin('preapprovals_processed', [
          'total_preapprovals_fetched' => count($all_payments),
          'converted_to_payments' => count($subscription_payments),
          'conversion_ratio' => '100%', // Convertimos todas las suscripciones
          'requests_made' => $requests_made,
          'total_from_api' => $total_payments
        ]);
      }
      
      $mapped_payments = [];
      
      // Obtener todos los suscriptores para hacer el mapeo
      $subscribers = WPMPS_Subscribers::get_subscribers();
      $subscribers_by_email = [];
      $subscribers_by_preapproval = [];
      $subscribers_by_plan = [];
      
      foreach ($subscribers as $sub) {
        if (!empty($sub['email'])) {
          $subscribers_by_email[$sub['email']] = $sub;
        }
        if (!empty($sub['preapproval_id'])) {
          $subscribers_by_preapproval[$sub['preapproval_id']] = $sub;
        }
        if (!empty($sub['plan_id'])) {
          if (!isset($subscribers_by_plan[$sub['plan_id']])) {
            $subscribers_by_plan[$sub['plan_id']] = [];
          }
          $subscribers_by_plan[$sub['plan_id']][] = $sub;
        }
      }
      
      // También obtener TODAS las suscripciones de MP para mapeo por plan_id
      $all_mp_preapprovals = WPMPS_Subscribers::get_all_mp_preapprovals();
      foreach ($all_mp_preapprovals as $preapproval) {
        $email = $preapproval['payer_email'] ?? '';
        $preapproval_id = $preapproval['id'] ?? '';
        $plan_id = $preapproval['preapproval_plan_id'] ?? '';
        
        if (!empty($email) && !empty($preapproval_id)) {
          // Crear suscriptor temporal si no existe en WP
          if (!isset($subscribers_by_email[$email])) {
            $temp_subscriber = [
              'user_id' => null,
              'email' => $email,
              'preapproval_id' => $preapproval_id,
              'plan_id' => $plan_id,
              'plan_name' => 'Plan MP: ' . substr($plan_id, 0, 8),
              'status' => $preapproval['status'] ?? 'unknown',
              'amount' => $preapproval['auto_recurring']['transaction_amount'] ?? 0,
              'currency' => $preapproval['auto_recurring']['currency_id'] ?? 'ARS',
              'is_temp' => true // Marcar como temporal
            ];
            
            $subscribers_by_email[$email] = $temp_subscriber;
          }
          
          // Agregar a mapeo por preapproval_id
          if (!isset($subscribers_by_preapproval[$preapproval_id])) {
            $subscribers_by_preapproval[$preapproval_id] = $subscribers_by_email[$email];
          }
          
          // Agregar a mapeo por plan_id
          if (!empty($plan_id)) {
            if (!isset($subscribers_by_plan[$plan_id])) {
              $subscribers_by_plan[$plan_id] = [];
            }
            $subscribers_by_plan[$plan_id][] = $subscribers_by_email[$email];
          }
        }
      }
      
      // Procesar cada pago de suscripción
      foreach ($subscription_payments as $payment) {
        $mapped_payment = self::map_payment_to_subscriber($payment, $subscribers_by_email, $subscribers_by_preapproval, $subscribers_by_plan);
        $mapped_payments[] = $mapped_payment;
      }
      
      $result = [
        'success' => true,
        'payments' => $mapped_payments,
        'total_found' => $total_payments ?? count($all_payments),
        'total_preapprovals_fetched' => count($all_payments),
        'converted_payments' => count($subscription_payments),
        'requests_made' => $requests_made,
        'processing_time' => round(microtime(true) - $start_time, 2)
      ];
      
      // Guardar en caché por 10 minutos
      if ($use_cache) {
        set_transient('wpmps_payments_cache', $result, 600);
      }
      
      if (function_exists('wpmps_log_admin')){
        wpmps_log_admin('get_payments_complete', [
          'payments_count' => count($mapped_payments),
          'processing_time' => $result['processing_time']
        ]);
      }
      
      return $result;
      
    } catch (\Throwable $e) {
      if (function_exists('wpmps_log_error')){
        wpmps_log_error('payments', 'get_payments_error', $e->getMessage());
      }
      
      return [
        'success' => false,
        'message' => 'Error interno: ' . $e->getMessage(),
        'payments' => []
      ];
    }
  }
  
  /**
   * Determina si un pago es de suscripción
   */
  public static function is_subscription_payment($payment) {
    // 1. Verificar operation_type
    if (isset($payment['operation_type']) && $payment['operation_type'] === 'subscription_payment') {
      return true;
    }
    
    // 2. Verificar si tiene preapproval_plan_id (MUY común en pagos de suscripciones)
    if (!empty($payment['preapproval_plan_id'])) {
      return true;
    }
    
    // 3. Verificar si tiene preapproval_id en additional_info
    if (!empty($payment['additional_info']['items'][0]['id']) && 
        strlen($payment['additional_info']['items'][0]['id']) === 32) {
      return true;
    }
    
    // 4. Verificar si tiene preapproval_id en metadata
    if (!empty($payment['metadata']['preapproval_id'])) {
      return true;
    }
    
    // 5. Verificar si tiene plan_id en metadata
    if (!empty($payment['metadata']['plan_id'])) {
      return true;
    }
    
    // 6. Verificar external_reference que parezca preapproval_id o plan_id
    if (!empty($payment['external_reference']) && 
        (strlen($payment['external_reference']) === 32 || 
         strpos($payment['external_reference'], 'preapproval') !== false ||
         strpos($payment['external_reference'], 'plan') !== false)) {
      return true;
    }
    
    // 7. Verificar descripción que mencione suscripción
    if (!empty($payment['description'])) {
      $description = strtolower($payment['description']);
      $subscription_keywords = ['suscri', 'subscription', 'recurring', 'mensual', 'anual', 'plan'];
      foreach ($subscription_keywords as $keyword) {
        if (strpos($description, $keyword) !== false) {
          return true;
        }
      }
    }
    
    // 8. Verificar si el monto coincide con montos típicos de suscripciones
    $amount = floatval($payment['transaction_amount'] ?? 0);
    $subscription_amounts = [4800, 15, 1500, 2999, 25990]; // Montos comunes de tus suscripciones
    if (in_array($amount, $subscription_amounts)) {
      return true;
    }
    
    // 9. TEMPORAL: Mostrar todos los pagos para análisis (relajar filtro)
    // Si no cumple ningún criterio específico, pero tiene datos básicos, considerarlo potencial suscripción
    if (!empty($payment['payer']['email']) && $amount > 0) {
      return true;
    }
    
    return false;
  }

  /**
   * Mapea un pago individual a un suscriptor
   */
  private static function map_payment_to_subscriber($payment, $subscribers_by_email, $subscribers_by_preapproval, $subscribers_by_plan = []) {
    $mapped = [
      'id' => $payment['id'] ?? '',
      'status' => $payment['status'] ?? '',
      'status_detail' => $payment['status_detail'] ?? '',
      'amount' => $payment['transaction_amount'] ?? 0,
      'currency' => $payment['currency_id'] ?? '',
      'date_created' => $payment['date_created'] ?? '',
      'date_approved' => $payment['date_approved'] ?? '',
      'payment_method' => $payment['payment_method_id'] ?? '',
      'payment_type' => $payment['payment_type_id'] ?? '',
      'description' => $payment['description'] ?? '',
      'external_reference' => $payment['external_reference'] ?? '',
      'payer_email' => $payment['payer']['email'] ?? '',
      'payer_id' => $payment['payer']['id'] ?? '',
      'collector_id' => $payment['collector_id'] ?? '',
      'operation_type' => $payment['operation_type'] ?? '',
      'preapproval_id' => '',
      'plan_id' => '',
      'subscription_id' => '',
      'matched_subscriber' => null,
      'match_type' => 'none',
      'match_confidence' => 'none'
    ];
    
    // PRIORIDAD 1: Para preapprovals convertidos, usar directamente los datos
    if (!empty($payment['preapproval_id'])) {
      $mapped['preapproval_id'] = $payment['preapproval_id'];
    }
    
    if (!empty($payment['preapproval_plan_id'])) {
      $mapped['plan_id'] = $payment['preapproval_plan_id'];
    }
    
    // PRIORIDAD 2: Buscar en metadata
    if (!empty($payment['metadata']['preapproval_id'])) {
      $mapped['preapproval_id'] = $payment['metadata']['preapproval_id'];
    }
    
    if (!empty($payment['metadata']['plan_id'])) {
      $mapped['plan_id'] = $payment['metadata']['plan_id'];
    }
    
    // PRIORIDAD 3: Buscar preapproval_id en diferentes lugares (para pagos normales)
    if (empty($mapped['preapproval_id']) && !empty($payment['additional_info']['items'][0]['id'])) {
      $item_id = $payment['additional_info']['items'][0]['id'];
      if (strlen($item_id) === 32) {
        $mapped['preapproval_id'] = $item_id;
        // Si no tenemos plan_id, usar este como plan_id también
        if (empty($mapped['plan_id'])) {
          $mapped['plan_id'] = $item_id;
        }
      }
    }
    
    // Buscar en external_reference
    if (empty($mapped['preapproval_id']) && !empty($payment['external_reference'])) {
      if (strlen($payment['external_reference']) === 32) {
        $mapped['preapproval_id'] = $payment['external_reference'];
        if (empty($mapped['plan_id'])) {
          $mapped['plan_id'] = $payment['external_reference'];
        }
      }
    }
    
    // Buscar en additional_info->external_reference
    if (empty($mapped['preapproval_id']) && !empty($payment['additional_info']['external_reference'])) {
      $mapped['preapproval_id'] = $payment['additional_info']['external_reference'];
      if (empty($mapped['plan_id'])) {
        $mapped['plan_id'] = $payment['additional_info']['external_reference'];
      }
    }
    
    // ALGORITMO DE MAPEO MEJORADO
    
    // 1. Intentar mapear por preapproval_id exacto (máxima confianza)
    if (!empty($mapped['preapproval_id']) && isset($subscribers_by_preapproval[$mapped['preapproval_id']])) {
      $mapped['matched_subscriber'] = $subscribers_by_preapproval[$mapped['preapproval_id']];
      $mapped['match_type'] = 'preapproval_id';
      $mapped['match_confidence'] = 'high';
    }
    // 2. Intentar mapear por plan_id + email (muy confiable)
    elseif (!empty($mapped['plan_id']) && !empty($mapped['payer_email']) && isset($subscribers_by_plan[$mapped['plan_id']])) {
      // Buscar suscriptor con el mismo plan_id y email
      foreach ($subscribers_by_plan[$mapped['plan_id']] as $subscriber) {
        if (strtolower($subscriber['email']) === strtolower($mapped['payer_email'])) {
          $mapped['matched_subscriber'] = $subscriber;
          $mapped['match_type'] = 'plan_id_email';
          $mapped['match_confidence'] = 'high';
          break;
        }
      }
    }
    
    // 3. Si no se mapeó por plan_id + email, intentar solo por email exacto
    if (empty($mapped['matched_subscriber']) && !empty($mapped['payer_email'])) {
      $email_lower = strtolower($mapped['payer_email']);
      foreach ($subscribers_by_email as $sub_email => $subscriber) {
        if (strtolower($sub_email) === $email_lower) {
          $mapped['matched_subscriber'] = $subscriber;
          $mapped['match_type'] = 'email';
          $mapped['match_confidence'] = 'medium';
          break;
        }
      }
    }
    
    // 4. Buscar por email con coincidencia parcial (menos confiable)
    if (empty($mapped['matched_subscriber']) && !empty($mapped['payer_email'])) {
      foreach ($subscribers_by_email as $email => $subscriber) {
        if (stripos($email, $mapped['payer_email']) !== false || stripos($mapped['payer_email'], $email) !== false) {
          $mapped['matched_subscriber'] = $subscriber;
          $mapped['match_type'] = 'email_partial';
          $mapped['match_confidence'] = 'low';
          break;
        }
      }
    }
    
    return $mapped;
  }
  
  /**
   * Obtiene estadísticas de los pagos
   */
  public static function get_payments_stats($payments_data = null) {
    if ($payments_data === null) {
      $payments_data = self::get_payments_with_mapping();
    }
    
    if (!$payments_data['success']) {
      return [
        'success' => false,
        'message' => $payments_data['message'] ?? 'Error desconocido'
      ];
    }
    
    $payments = $payments_data['payments'];
    $stats = [
      'total_payments' => count($payments),
      'matched_payments' => 0,
      'unmatched_payments' => 0,
      'total_amount' => 0,
      'matched_amount' => 0,
      'unmatched_amount' => 0,
      'by_status' => [],
      'by_match_type' => [],
      'by_currency' => [],
      'by_payment_method' => []
    ];
    
    foreach ($payments as $payment) {
      $amount = floatval($payment['amount']);
      $stats['total_amount'] += $amount;
      
      // Contadores de matching
      if ($payment['match_type'] !== 'none') {
        $stats['matched_payments']++;
        $stats['matched_amount'] += $amount;
      } else {
        $stats['unmatched_payments']++;
        $stats['unmatched_amount'] += $amount;
      }
      
      // Por status
      $status = $payment['status'];
      if (!isset($stats['by_status'][$status])) {
        $stats['by_status'][$status] = ['count' => 0, 'amount' => 0];
      }
      $stats['by_status'][$status]['count']++;
      $stats['by_status'][$status]['amount'] += $amount;
      
      // Por tipo de match
      $match_type = $payment['match_type'];
      if (!isset($stats['by_match_type'][$match_type])) {
        $stats['by_match_type'][$match_type] = ['count' => 0, 'amount' => 0];
      }
      $stats['by_match_type'][$match_type]['count']++;
      $stats['by_match_type'][$match_type]['amount'] += $amount;
      
      // Por moneda
      $currency = $payment['currency'];
      if (!isset($stats['by_currency'][$currency])) {
        $stats['by_currency'][$currency] = ['count' => 0, 'amount' => 0];
      }
      $stats['by_currency'][$currency]['count']++;
      $stats['by_currency'][$currency]['amount'] += $amount;
      
      // Por método de pago
      $method = $payment['payment_method'];
      if (!isset($stats['by_payment_method'][$method])) {
        $stats['by_payment_method'][$method] = ['count' => 0, 'amount' => 0];
      }
      $stats['by_payment_method'][$method]['count']++;
      $stats['by_payment_method'][$method]['amount'] += $amount;
    }
    
    $stats['success'] = true;
    return $stats;
  }
  
  /**
   * Obtiene la lista de planes de suscripción disponibles
   */
  public static function get_available_plans() {
    $subscribers = WPMPS_Subscribers::get_subscribers();
    $plans = [];
    
    // Agregar planes de suscriptores de WordPress
    foreach ($subscribers as $sub) {
      if (!empty($sub['plan_id']) && !empty($sub['plan_name'])) {
        $plans[$sub['plan_id']] = [
          'id' => $sub['plan_id'],
          'name' => $sub['plan_name'],
          'count' => isset($plans[$sub['plan_id']]) ? $plans[$sub['plan_id']]['count'] + 1 : 1
        ];
      }
    }
    
    // También agregar planes de preapprovals de MercadoPago
    $all_mp_preapprovals = WPMPS_Subscribers::get_all_mp_preapprovals();
    foreach ($all_mp_preapprovals as $preapproval) {
      $plan_id = $preapproval['preapproval_plan_id'] ?? '';
      if (!empty($plan_id)) {
        // Intentar obtener el nombre del plan desde el reason
        $plan_name = $preapproval['reason'] ?? ('Plan MP: ' . substr($plan_id, 0, 8));
        
        if (isset($plans[$plan_id])) {
          $plans[$plan_id]['count']++;
        } else {
          $plans[$plan_id] = [
            'id' => $plan_id,
            'name' => $plan_name,
            'count' => 1
          ];
        }
      }
    }
    
    // Ordenar por nombre
    uasort($plans, function($a, $b) {
      return strcmp($a['name'], $b['name']);
    });
    
    return array_values($plans);
  }

  /**
   * Limpia el caché de pagos
   */
  public static function clear_cache() {
    delete_transient('wpmps_payments_cache');
    if (function_exists('wpmps_log_admin')){
      wpmps_log_admin('payments_cache_cleared', []);
    }
  }
  
  /**
   * Obtiene pagos filtrados
   */
  public static function get_filtered_payments($filters = []) {
    $payments_data = self::get_payments_with_mapping();
    
    if (!$payments_data['success']) {
      return $payments_data;
    }
    
    $payments = $payments_data['payments'];
    
    // Aplicar filtros
    if (!empty($filters['status'])) {
      $status = sanitize_text_field($filters['status']);
      $payments = array_filter($payments, function($payment) use ($status) {
        return $payment['status'] === $status;
      });
    }
    
    if (!empty($filters['match_type'])) {
      $match_type = sanitize_text_field($filters['match_type']);
      if ($match_type === 'preapproval_id') {
        $payments = array_filter($payments, function($payment) {
          return $payment['match_type'] === 'preapproval_id';
        });
      } elseif ($match_type === 'plan_id_email') {
        $payments = array_filter($payments, function($payment) {
          return $payment['match_type'] === 'plan_id_email';
        });
      } else {
        $payments = array_filter($payments, function($payment) use ($match_type) {
          return $payment['match_type'] === $match_type;
        });
      }
    }
    
    if (!empty($filters['email'])) {
      $email = sanitize_text_field($filters['email']);
      $payments = array_filter($payments, function($payment) use ($email) {
        return stripos($payment['payer_email'], $email) !== false;
      });
    }
    
    if (!empty($filters['amount_min'])) {
      $amount_min = floatval($filters['amount_min']);
      $payments = array_filter($payments, function($payment) use ($amount_min) {
        return floatval($payment['amount']) >= $amount_min;
      });
    }
    
    if (!empty($filters['amount_max'])) {
      $amount_max = floatval($filters['amount_max']);
      $payments = array_filter($payments, function($payment) use ($amount_max) {
        return floatval($payment['amount']) <= $amount_max;
      });
    }
    
    if (!empty($filters['plan_id'])) {
      $plan_id = sanitize_text_field($filters['plan_id']);
      $payments = array_filter($payments, function($payment) use ($plan_id) {
        return !empty($payment['matched_subscriber']) && 
               $payment['matched_subscriber']['plan_id'] === $plan_id;
      });
    }
    
    if (!empty($filters['plan_name'])) {
      $plan_name = sanitize_text_field($filters['plan_name']);
      $payments = array_filter($payments, function($payment) use ($plan_name) {
        return !empty($payment['matched_subscriber']) && 
               stripos($payment['matched_subscriber']['plan_name'], $plan_name) !== false;
      });
    }
    
    $payments_data['payments'] = array_values($payments);
    return $payments_data;
  }
}