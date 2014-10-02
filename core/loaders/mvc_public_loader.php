<?php

require_once 'mvc_loader.php';

class MvcPublicLoader extends MvcLoader {
	
	public function add_query_vars($vars) {
		//Check to see if the rewrite rules function has run
		if ( ! $this->has_add_rewrite_rules ) {
			//If not we need to run it to make sure missing data is added
			$rewrite_rules = $this->add_rewrite_rules( array() );
		}
		$vars = array_merge($vars, $this->query_vars);
		return $vars;
	}
	
	public function template_redirect() {
		global $wp_query, $mvc_params;

		$routing_params = $this->get_routing_params();
		
		if ($routing_params) {
			$mvc_params = $routing_params;
			do_action('mvc_public_init', $routing_params);
			$this->dispatcher->dispatch($routing_params);
		}
	}
	
	protected function get_routing_params() {
		global $wp_query;
		
		$controller = $wp_query->get('mvc_controller');
		
		if ($controller) {
			$query_params = $wp_query->query;
			$params = array();
			foreach ($query_params as $key => $value) {
				$key = preg_replace('/^(mvc_)/', '', $key);
				$params[$key] = $value;
			}
			return $params;
		}
		
		return false;
	}

}

?>