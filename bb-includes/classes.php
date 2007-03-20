<?php

class BB_Dir_Map {
	var $root;
	var $callback;
	var $keep_empty;
	var $apply_to;
	var $recurse;
	var $dots;
	var $flat = array();
	var $error = false;

	var $_current_root;
	var $_current_file;

	function BB_DIR_MAP( $root, $args = '' ) {
		if ( !is_dir( $root ) ) {
			$this->error = new WP_Error( 'bb_dir_map', __('Not a valid directory') );
			return;
		}

		$this->parse_args( $args );
		if ( is_null($this->apply_to) || is_null($this->dots) ) {
			$this->error = new WP_Error( 'bb_dir_map', __('Invalid arguments') );
			return;
		}
		$this->_current_root = $this->root = rtrim($root, '/\\');
		$this->map();
	}

	function parse_args( $args ) {
		// callback: should be callable
		// apply_to: all, files, dirs
		// keep_empty: (bool)
		// recurse: (int) depth, -1 = infinite
		// dots: true (everything), false (nothing), nosvn
		$defaults = array( 'callback' => false, 'keep_empty' => false, 'apply_to' => 'files', 'recurse' => true, 'dots' => false );
		$args = bb_parse_args( $args, $defaults );

		foreach ( array('callback', 'keep_empty', 'dots') as $a )
			if ( 'false' == $args[$a] )
				$args[$a] = false;
			elseif ( 'true' == $args[$a] )
				$args[$a] = true;

		$this->callback = is_callable($args['callback']) ? $args['callback'] : false;

		$this->keep_empty = (bool) $args['keep_empty'];

		$_apply_to = array( 'files' => 1, 'dirs' => 2, 'all' => 3 ); // This begs to be bitwise
		$this->apply_to = @$_apply_to[$args['apply_to']];

		$this->recurse = (int) $args['recurse'];

		$_dots = array( 1 => 3, 0 => 0, 'nosvn' => 1 ); // bitwise here is a little silly
		$this->dots = @$_dots[$args['dots']];
	}

	function map( $root = false ) {
		$return = array();
		$_dir = dir($root ? $root : $this->_current_root);
		while ( false !== ( $this->_current_file = $_dir->read() ) ) {
			if ( in_array($this->_current_file, array('.', '..')) )
				continue;
			if ( !$this->dots && '.' == $this->_current_file{0} )
				continue;

			$item = $_dir->path . DIRECTORY_SEPARATOR . $this->_current_file;
			$_item = substr( $item, strlen($this->root) + 1 );

			if ( is_dir($item) )  { // dir stuff
				if ( 1 & $this->dots && in_array($this->_current_file, array('.svn', 'CVS')) )
					continue;
				if ( 2 & $this->apply_to ) {
					$result = $this->callback ? call_user_func($this->callback, $item) : true;
					if ( $result || $this->keep_empty )
						$this->flat[$_item] = $result;
				}
				if ( 0 > $this->recurse || $this->recurse ) {
					$this->recurse--;
					$this->map( $item );
					$this->recurse++;
				}
			} else { // file stuff
				if ( !(1 & $this->apply_to) )
					continue;
				$result = $this->callback ? call_user_func($this->callback, $item) : true;
				if ( $result || $this->keep_empty )
					$this->flat[$_item] = $result;
			}
		}
	}

	function get_results() {
		return is_wp_error( $this->error ) ? $this->error : $this->flat;
	}
}

?>
