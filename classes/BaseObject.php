<?php

namespace FutureLMS\classes;

use WP_Query;

/**
 * Wrapper - A lightweight Pods-like implementation
 */
class BaseObject
{
  private $pod_name;
  private $pod_id;
  private $data = null;
  private $fields = [];
  private $is_collection = false;
  private $collection = [];
  private $query_args = [];
  private $current_item = 0; // For collection iteration
  private $current_item_index = 0;

  /**
   * Constructor
   * @param string $pod_name Pod/Post type name
   * @param mixed $id_or_params ID or query parameters
   */
  public function __construct($pod_name, $id_or_params = null)
  {
      $this->pod_name = $pod_name;

      if (is_numeric($id_or_params)) {
          // Single item by ID
          $this->load($id_or_params);
      } elseif (is_array($id_or_params)) {
          // Query multiple items
          $this->query($id_or_params);
      } elseif (is_object($id_or_params)) {
          // Existing WP object
          $this->load_from_object($id_or_params);
      }
  }


  /**
   * Factory method
   */
  public static function factory($pod_name, $id_or_params = null)
  {
      return new self($pod_name, $id_or_params);
  }

  /**
   * Load a single item
   */
  private function load($id)
  {
      $this->pod_id = $id;
      $this->is_collection = false;
      $this->load_post($id);
  }

  /**
   * Load post data
   */
  private function load_post($id)
  {
      $post = get_post($id);

      if ($post && $post->post_type === $this->pod_name) {
          $this->data = $post;
          $this->fields = get_post_meta($id);

          // Flatten single meta values
          foreach ($this->fields as $key => $value) {
              if (is_array($value) && count($value) === 1) {
                  $this->fields[$key] = maybe_unserialize($value[0]);
              }
          }
          return true;
      }
      return false;
  }

  /**
   * Load post object
   */
  private function load_from_object($post)
  {
      if ($post && $post->post_type === $this->pod_name) {
          $this->data = $post;
          $this->fields = get_post_meta($post->ID);

          // Flatten single meta values
          foreach ($this->fields as $key => $value) {
              if (is_array($value) && count($value) === 1) {
                  $this->fields[$key] = maybe_unserialize($value[0]);
              }
          }
          return true;
      }
      return false;
  }



  /**
   * Query items
   */
  public function query($params = [])
  {
    if (isset($params['where'])) {
      $params['where'] = $this->convert_relationship_where($params['where']);
    }

    $this->is_collection = true;
    $this->query_args = $params;

    $this->query_posts($params);
    return $this;
  }

  /**
   * Query posts
   */
  private function query_posts($params)
  {
    $defaults = [
        'post_type' => $this->pod_name,
        'posts_per_page' => -1,
        'post_status' => 'publish'
    ];

    $args = wp_parse_args($this->convert_query_args($params), $defaults);
    $query = new WP_Query($args);

    $this->collection = [];
    foreach ($query->posts as $post) {
        $this->collection[] = new self($this->pod_name, $post->ID);
    }
  }

  /**
   * Convert query arguments
   */
  private function convert_query_args($params)
  {
      $converted = [];

      // Map Pods-like params to WP_Query params
      $mappings = [
          'limit' => 'posts_per_page',
          'orderby' => 'orderby',
          'order' => 'order',
          'where' => 'meta_query',
          'page' => 'paged'
      ];

      foreach ($params as $key => $value) {
          if (isset($mappings[$key])) {
              $converted[$mappings[$key]] = $value;
          } else {
              $converted[$key] = $value;
          }
      }

      // Convert where clauses
      if (isset($converted['meta_query']) && is_string($converted['meta_query'])) {
          $converted['meta_query'] = $this->parse_where_string($converted['meta_query']);
      }

      return $converted;
  }

  /**
   * Parse where string
   */
  private function parse_where_string($where)
  {
      $conditions = explode(' AND ', $where);
      $meta_query = ['relation' => 'AND'];

      foreach ($conditions as $condition) {
          if (preg_match('/(\w+)\s*([!=<>]+)\s*[\'"]?([^\'"\s]+)[\'"]?/', $condition, $matches)) {
              $meta_query[] = [
                  'key' => $matches[1],
                  'value' => $matches[3],
                  'compare' => $matches[2]
              ];
          }
      }

      return $meta_query;
  }

  private function convert_relationship_where($where) {

    if (preg_match_all('/(\w+)\.(\w+)/', $where, $matches)) {
      foreach ($matches[0] as $key => $full_match) {
        $relationship = $matches[1][$key];
        $field = $matches[2][$key];

        if ($field === 'id') {
          $where = str_replace($full_match, $relationship, $where);
        }

      }
    }
    return $where;
  }

  /**
   * Magic getter
   */
  public function __get($name)
  {
    // First check fields
    if (isset($this->fields[$name])) {
        return $this->fields[$name];
    }

    // Then check post object properties
    return $this->data->$name ?? null;
  }

  public static function get_field(string $pod_name, int $post_id, string $field_name, bool $single = true)
  {
    if (!post_type_exists($pod_name)) {
        return null;
    }

    if (!get_post($post_id)) {
        return null;
    }

    return get_post_meta($post_id, $field_name, $single);
  }


  /**
  * Field access
  */
  public function field($name, $value = null, $options = null)
  {
    if (func_num_args() > 1) {
        // Setter
        $this->fields[$name] = $value;
        return $this;
    }

    // Getter
    $value = $this->__get($name);

    // Simple formatting
    if ($options === true) {
        return $this->display($name);
    }

    return $value;
  }

  /**
   * Display field
   */
  public function display($name)
  {
    $value = $this->field($name);
    return apply_filters('wrapper_display', $value, $name, $this);
  }

  /**
   * Save item
   */
  public function save()
  {
      if ($this->is_collection) {
          return false;
      }

      return $this->save_post();
  }

  /**
   * Save post
   */
  private function save_post()
  {
      $post_data = (array)$this->data;

      if ($this->pod_id) {
          // Update existing
          $post_data['ID'] = $this->pod_id;
          $result = wp_update_post($post_data, true);
      } else {
          // Create new
          $post_data['post_type'] = $this->pod_name;
          $result = wp_insert_post($post_data, true);
      }

      if (!is_wp_error($result)) {
          $this->pod_id = $result;

          // Save meta fields
          foreach ($this->fields as $key => $value) {
              update_post_meta($this->pod_id, $key, $value);
          }

          return true;
      }

      return false;
  }

  /**
   * Collection methods
   */
  public function total()
  {
      return $this->is_collection ? count($this->collection) : 0;
  }

  public function count()
  {
      return $this->total();
  }

  public function results()
  {
      return $this->collection;
  }

  public function first()
  {
      return !empty($this->collection) ? $this->collection[0] : null;
  }


  /**
   * Fetch the next item in the collection
   * @return BaseObject|false
   */
  public function fetch() {
    if (!$this->is_collection || empty($this->collection)) {
      $this->current_item = null; // Reset
      return false;
    }

    if ($this->current_item_index < count($this->collection)) {
      $this->current_item = $this->collection[$this->current_item_index];
      $this->current_item_index++;
      return $this->current_item; // Return true to indicate a successful fetch
    }

    // Reset for next iteration if needed
    $this->current_item_index = 0;
    $this->current_item = null;
    return false;
  }
  /**
   * Reset the collection pointer
   */
  public function reset() {
    $this->current_item = 0;
    return $this;
  }

  public function raw($field_name)
  {

    // handle fetch iterations
    if ($this->current_item !== null) {
      if (is_object($this->current_item)) {

        // check if current_item has a data array and look for property
        if (isset($this->current_item->data)) {
          if (is_array($this->current_item->data) && array_key_exists($field_name, $this->current_item->data)) {
            return $this->current_item->data[$field_name];
          } elseif (is_object($this->current_item->data) && property_exists($this->current_item->data, $field_name)) {
            return $this->current_item->data->$field_name;
          }
        }

        // check if current_item has this property directly
        if (property_exists($this->current_item, $field_name)) {
          return $this->current_item->$field_name;
        }
      }
    }


    // Handle core fields
    switch ($field_name) {
      case 'ID':
        return $this->pod_id;
      case 'name':
        return $this->data->post_title ?? null;
      case 'title':
        return $this->data->post_title ?? null;
    }

    return $this->field($field_name);
  }


  public function exists() {
      return !empty($this->data);
  }
}