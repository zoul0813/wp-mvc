<?php

abstract class MvcLoader {

	protected $admin_controller_names = array();
	protected $core_path = '';
	protected $dispatcher = null;
	protected $file_includer = null;
	protected $model_names = array();
	protected $public_controller_names = array();
	protected $query_vars = array();
	protected $has_add_rewrite_rules = false;

	function __construct() {
	
		if (!defined('MVC_CORE_PATH')) {
			define('MVC_CORE_PATH', MVC_PLUGIN_PATH.'core/');
		}
		
		$this->core_path = MVC_CORE_PATH;
		
		$this->load_core();
		$this->load_plugins();
		
		$this->file_includer = new MvcFileIncluder();
		$this->file_includer->include_all_app_files('config/bootstrap.php');
		$this->file_includer->include_all_app_files('config/routes.php');
		
		$this->dispatcher = new MvcDispatcher();
		
	}
	
	protected function load_core() {
		
		$files = array(
			'mvc_error',
			'mvc_configuration',
			'mvc_directory',
			'mvc_dispatcher',
			'mvc_file',
			'mvc_file_includer',
			'mvc_model_registry',
			'mvc_object_registry',
			'mvc_settings_registry',
			'mvc_plugin_loader',
			'mvc_templater',
			'mvc_inflector',
			'mvc_router',
			'mvc_settings',
			'controllers/mvc_controller',
			'controllers/mvc_admin_controller',
			'controllers/mvc_public_controller',
			'functions/functions',
			'models/mvc_database_adapter',
			'models/mvc_database',
			'models/mvc_data_validation_error',
			'models/mvc_data_validator',
			'models/mvc_model_object',
			'models/mvc_model',
			'models/wp_models/mvc_comment',
			'models/wp_models/mvc_comment_meta',
			'models/wp_models/mvc_post_adapter',
			'models/wp_models/mvc_post',
			'models/wp_models/mvc_post_meta',
			'models/wp_models/mvc_user',
			'helpers/mvc_helper',
			'helpers/mvc_form_tags_helper',
			'helpers/mvc_form_helper',
			'helpers/mvc_html_helper',
			'shells/mvc_shell',
			'shells/mvc_shell_dispatcher'
		);
		
		foreach ($files as $file) {
			require_once $this->core_path.$file.'.php';
		}
		
	}
	
	protected function load_plugins() {
	
		$plugins = $this->get_ordered_plugins();
		$plugin_app_paths = array();
		foreach ($plugins as $plugin) {
			$plugin_app_paths[$plugin] = rtrim(WP_PLUGIN_DIR, '/').'/'.$plugin.'/app/';
		}

		MvcConfiguration::set(array(
			'Plugins' => $plugins,
			'PluginAppPaths' => $plugin_app_paths
		));

		$this->plugin_app_paths = $plugin_app_paths;
	
	}
	
	protected function get_ordered_plugins() {
	
		$plugins = get_option('mvc_plugins', array());
		$plugin_app_paths = array();
		
		// Allow plugins to be loaded in a specific order by setting a PluginOrder config value like
		// this ('all' is an optional token; it includes all unenumerated plugins):
		// MvcConfiguration::set(array(
		//		'PluginOrder' => array('my-first-plugin', 'my-second-plugin', 'all', 'my-last-plugin')
		// );
		$plugin_order = MvcConfiguration::get('PluginOrder');
		if (!empty($plugin_order)) {
			$ordered_plugins = array();
			$index_of_all = array_search('all', $plugin_order);
			if ($index_of_all !== false) {
				$first_plugins = array_slice($plugin_order, 0, $index_of_all - 1);
				$last_plugins = array_slice($plugin_order, $index_of_all);
				$middle_plugins = array_diff($plugins, $first_plugins, $last_plugins);
				$plugins = array_merge($first_plugins, $middle_plugins, $last_plugins);
			} else {
				$unordered_plugins = array_diff($plugins, $plugin_order);
				$plugins = array_merge($plugin_order, $unordered_plugins);
			}
		}
		
		return $plugins;
		
	}
	
	public function init() {
	
		$this->load_controllers();
		$this->load_libs();
		$this->load_models();
		$this->load_settings();
		$this->load_functions();
	
	}

	public function flush_rewrite_rules($rules) {
		global $wp_rewrite;
		$wp_rewrite->flush_rules( false );
		if ( get_option( MVC_FLUSH_NEEDED_OPTION ) !== false ) {
			update_option( MVC_FLUSH_NEEDED_OPTION, 0 );
		} else {
			add_option( MVC_FLUSH_NEEDED_OPTION, 0, '', 'yes' );
		}
	}
	
	public function add_rewrite_rules($rules) {
		global $wp_rewrite;
		
		$new_rules = array();
		
		$routes = MvcRouter::get_public_routes();
		
		// Use default routes if none have been defined
		if (empty($routes)) {
			MvcRouter::public_connect('{:controller}', array('action' => 'index'));
			MvcRouter::public_connect('{:controller}/{:id:[\d]+}', array('action' => 'show'));
			MvcRouter::public_connect('{:controller}/{:action}/{:id:[\d]+}');
			$routes = MvcRouter::get_public_routes();
		}
		
		foreach ($routes as $route) {
			
			$route_path = $route[0];
			$route_defaults = $route[1];
			
			if (strpos($route_path, '{:controller}') !== false) {
				foreach ($this->public_controller_names as $controller) {
					$route_rules = $this->get_rewrite_rules($route_path, $route_defaults, $controller);
					$new_rules = array_merge($route_rules, $new_rules);
				}
			} else if (!empty($route_defaults['controller'])) {
				$route_rules = $this->get_rewrite_rules($route_path, $route_defaults, $route_defaults['controller'], 1);
				$new_rules = array_merge($route_rules, $new_rules);
			}
		}
		
		$rules = array_merge($new_rules, $rules);
		$rules = apply_filters('mvc_public_rewrite_rules', $rules);
		$this->has_add_rewrite_rules = true;
		return $rules;
	}
	
	public function get_rewrite_rules($route_path, $route_defaults, $controller, $first_query_var_match_index=0) {

		add_rewrite_tag('%'.$controller.'%', '(.+)');
		
		$rewrite_path = $route_path;
		$query_vars = array();
		$query_var_counter = $first_query_var_match_index;
		$query_var_match_string = '';
		
		// Add any route params from the route path (e.g. '{:controller}/{:id:[\d]+}') to $query_vars
		// and append them to the match string for use in a WP rewrite rule
		preg_match_all('/{:(.+?)(:.*?)?}/', $rewrite_path, $matches);
		foreach ($matches[1] as $query_var) {
			$query_var = 'mvc_'.$query_var;
			if ($query_var != 'mvc_controller') {
				$query_var_match_string .= '&'.$query_var.'=$matches['.$query_var_counter.']';
			}
			$query_vars[] = $query_var;
			$query_var_counter++;
		}
		
		// Do the same as above for route params that are defined as route defaults (e.g. array('action' => 'show'))
		if (!empty($route_defaults)) {
			foreach ($route_defaults as $query_var => $value) {
				$query_var = 'mvc_'.$query_var;
				if ($query_var != 'mvc_controller') {
					$query_var_match_string .= '&'.$query_var.'='.$value;
					$query_vars[] = $query_var;
				}
			}
		}
		
		$this->query_vars = array_unique(array_merge($this->query_vars, $query_vars));
		$rewrite_path = str_replace('{:controller}', $controller, $route_path);
		
		// Replace any route params (e.g. {:param_name}) in the route path with the default pattern ([^/]+)
		$rewrite_path = preg_replace('/({:[\w_-]+})/', '([^/]+)', $rewrite_path);
		// Replace any route params with defined patterns (e.g. {:param_name:[\d]+}) in the route path with
		// their pattern (e.g. ([\d]+))
		$rewrite_path = preg_replace('/({:[\w_-]+:)(.*?)}/', '(\2)', $rewrite_path);
		$rewrite_path = '^'.$rewrite_path.'/?$';
		
		$controller_value = empty($route_defaults['controller']) ? $controller : $route_defaults['controller'];
		$controller_rules = array();
		$controller_rules[$rewrite_path] = 'index.php?mvc_controller='.$controller_value.$query_var_match_string;
		
		return $controller_rules;
	}
	
	public function filter_post_link($permalink, $post) {
		if (substr($post->post_type, 0, 4) == 'mvc_') {
			$model_name = substr($post->post_type, 4);
			$controller = MvcInflector::tableize($model_name);
			$model_name = MvcInflector::camelize($model_name);
			$model = MvcModelRegistry::get_model($model_name);
			$object = $model->find_one(array('post_id' => $post->ID));
			if ($object) {
				$url = MvcRouter::public_url(array('object' => $object));
				if ($url) {
					return $url;
				}
			}
		}
		return $permalink;
	}
	
	public function register_widgets() {
		foreach ($this->plugin_app_paths as $plugin_app_path) {
			$directory = $plugin_app_path.'widgets/';
			$widget_filenames = $this->file_includer->require_php_files_in_directory($directory);
  
			$path_segments_to_remove = array(WP_CONTENT_DIR, '/plugins/', '/app/');
			$plugin = str_replace($path_segments_to_remove, '', $plugin_app_path);

			foreach ($widget_filenames as $widget_file) {
				$widget_name = str_replace('.php', '', $widget_file);
				$widget_class = MvcInflector::camelize($plugin).'_'.MvcInflector::camelize($widget_name);
				register_widget($widget_class);
			}
		}
	}
	
	protected function load_controllers() {
	
		foreach ($this->plugin_app_paths as $plugin_app_path) {
		
			$admin_controller_filenames = $this->file_includer->require_php_files_in_directory($plugin_app_path.'controllers/admin/');
			$public_controller_filenames = $this->file_includer->require_php_files_in_directory($plugin_app_path.'controllers/');
			
			foreach ($admin_controller_filenames as $filename) {
				if (preg_match('/admin_([^\/]+)_controller\.php/', $filename, $match)) {
					$this->admin_controller_names[] = $match[1];
				}
			}
			
			foreach ($public_controller_filenames as $filename) {
				if (preg_match('/([^\/]+)_controller\.php/', $filename, $match)) {
					$this->public_controller_names[] = $match[1];
				}
			}
		
		}
		
	}
	
	protected function load_libs() {
		
		foreach ($this->plugin_app_paths as $plugin_app_path) {
		
			$this->file_includer->require_php_files_in_directory($plugin_app_path.'libs/');
			
		}
		
	}
	
	protected function load_models() {
		
		$models = array();
		
		foreach ($this->plugin_app_paths as $plugin_app_path) {
		
			$model_filenames = $this->file_includer->require_php_files_in_directory($plugin_app_path.'models/');
			
			foreach ($model_filenames as $filename) {
				$models[] = MvcInflector::class_name_from_filename($filename);
			}
		
		}
		
		$this->model_names = array();
		
		foreach ($models as $model) {
			$this->model_names[] = $model;
			$model_class = MvcInflector::camelize($model);
			$model_instance = new $model_class();
			MvcModelRegistry::add_model($model, $model_instance);
		}
		
	}
	
	protected function load_settings() {
		
		$settings_names = array();
		
		foreach ($this->plugin_app_paths as $plugin_app_path) {
		
			$settings_filenames = $this->file_includer->require_php_files_in_directory($plugin_app_path.'settings/');
			
			foreach ($settings_filenames as $filename) {
				$settings_names[] = MvcInflector::class_name_from_filename($filename);
			}
		
		}
		
		$this->settings_names = $settings_names;
		
	}
	
	protected function load_functions() {
		
		foreach ($this->plugin_app_paths as $plugin_app_path) {
		
			$this->file_includer->require_php_files_in_directory($plugin_app_path.'functions/');
			
		}
	
	}

}

?>