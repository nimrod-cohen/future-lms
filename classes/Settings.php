<?php

namespace FutureLMS\classes;

class Settings {
  private const OPTIONS = ['flms_default_lobby_page' => 'mycourses',
                           'flms_store_currency' => 'ILS',
                           'flms_auto_create_woocommerce_products' => 'no',
                           'flms_auto_create_products_for_drafts' => 'no'];
  private const PREFIX = 'flms_';

  public const CURRENCIES = [
    'ILS' => '₪',
    'USD' => '$',
    'EUR' => '€',
    'GBP' => '£'
  ];

  public static function all(){
    $result = [];
    foreach (self::OPTIONS as $option_name => $default_value) {      
      $value = get_option($option_name, $default_value);
      $option_name = str_replace(self::PREFIX, "", $option_name);
      $result[$option_name] = $value;
    }
    return $result;
  }

  public static function set_many($options) {
    foreach ($options as $key => $value) {
      $option_name = self::PREFIX . sanitize_text_field($key);
      if(!in_array($option_name, array_keys(self::OPTIONS))) {
        continue; // Skip if the option is not in the predefined list
      }
      update_option($option_name, $value, true);
    }
  }

  public static function get($key) {
      $key = self::PREFIX . sanitize_text_field($key);
      $default = self::OPTIONS[$key] ?? null; // Get default value if exists
      if ($default === null) {
          return false; // Return false if the key is not in the predefined list
      }

      $value = get_option($key, $default);
      return $value;
  }
}