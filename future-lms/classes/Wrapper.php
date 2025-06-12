<?php

/**
 * Wrapper - A lightweight Pods-like implementation
 */
class Wrapper {
    private $pod_name;
    private $pod_id;
    private $data = [];
    private $fields = [];
    private $is_collection = false;
    private $collection = [];
    private $query_args = [];
    private $pod_config = [];

    /**
     * Constructor
     * @param string $pod_name Pod/Post type name
     * @param mixed $id_or_params ID or query parameters
     */
    public function __construct($pod_name, $id_or_params = null) {
        $this->pod_name = $pod_name;

        // Load pod configuration
        $this->load_pod_config();

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
    public static function factory($pod_name, $id_or_params = null) {
        return new self($pod_name, $id_or_params);
    }

    /**
     * Load pod configuration
     */
    private function load_pod_config() {
        // In a real implementation, you might load this from options or a custom table
        $this->pod_config = [
            'type' => 'post_type', // post_type, taxonomy, settings, etc.
            'fields' => [] // Would contain field definitions
        ];
    }

    /**
     * Load a single item
     */
    private function load($id) {
        $this->pod_id = $id;
        $this->is_collection = false;

        if ($this->is_post_type()) {
            $this->load_post($id);
        } elseif ($this->is_taxonomy()) {
            $this->load_term($id);
        } else {
            $this->load_custom($id);
        }
    }

    /**
     * Check if this pod is a post type
     */
    private function is_post_type() {
        return $this->pod_config['type'] === 'post_type' || post_type_exists($this->pod_name);
    }

    /**
     * Check if this pod is a taxonomy
     */
    private function is_taxonomy() {
        return $this->pod_config['type'] === 'taxonomy' || taxonomy_exists($this->pod_name);
    }

    /**
     * Load post data
     */
    private function load_post($id) {
        $post = get_post($id);

        if ($post && ($post->post_type === $this->pod_name || $this->pod_config['type'] === 'post_type')) {
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
     * Query items
     */
    public function query($params = []) {
        $this->is_collection = true;
        $this->query_args = $params;

        if ($this->is_post_type()) {
            $this->query_posts($params);
        } elseif ($this->is_taxonomy()) {
            $this->query_terms($params);
        } else {
            $this->query_custom($params);
        }
        return $this;
    }

    /**
     * Query posts
     */
    private function query_posts($params) {
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
    private function convert_query_args($params) {
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
    private function parse_where_string($where) {
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

    /**
     * Magic getter
     */
    public function __get($name) {
        // First check fields
        if (isset($this->fields[$name])) {
            return $this->fields[$name];
        }

        // Then check post object properties
        if (isset($this->data->$name)) {
            return $this->data->$name;
        }

        return null;
    }

    /**
     * Field access
     */
    public function field($name, $value = null, $options = null) {
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
    public function display($name) {
        $value = $this->field($name);
        return apply_filters('wrapper_display', $value, $name, $this);
    }

    /**
     * Save item
     */
    public function save() {
        if ($this->is_collection) {
            return false;
        }

        if ($this->is_post_type()) {
            return $this->save_post();
        }

        // Implement other save methods for taxonomies, etc.
        return false;
    }

    /**
     * Save post
     */
    private function save_post() {
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
    public function total() {
        return $this->is_collection ? count($this->collection) : 0;
    }

    public function count() {
        return $this->total();
    }

    public function results() {
        return $this->collection;
    }

    public function first() {
        return !empty($this->collection) ? $this->collection[0] : null;
    }

    public function fetch() {
        return $this->first();
    }

    /**
     * Relationship support
     */
    public function related($field_name, $params = []) {
        $related_ids = $this->field($field_name);
        if (empty($related_ids)) return null;

        if (is_array($related_ids)) {
            // Handle multiple relationships
            $collection = [];
            foreach ($related_ids as $id) {
                $related_pod = $this->get_related_pod_type($field_name);
                if ($related_pod) {
                    $collection[] = new self($related_pod, $id);
                }
            }
            return $collection;
        } else {
            // Single relationship
            $related_pod = $this->get_related_pod_type($field_name);
            return $related_pod ? new self($related_pod, $related_ids) : null;
        }
    }

    private function get_related_pod_type($field_name) {
        // In a real implementation, you'd check your field configuration
        // This is a simplified version
        $relationship_fields = [
            'author' => 'author',
            'category' => 'category'
        ];

        return $relationship_fields[$field_name] ?? null;
    }
}