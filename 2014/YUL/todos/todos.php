<?php

/*
 * Plugin Name: ToDos Widget 
 */

function todos_widgets_init() {
	register_widget( 'ToDos_Widget' );
}
add_action( 'widgets_init', 'todos_widgets_init' );

class ToDos_Widget extends WP_Widget {
	// Useful strings
	public static $completed_by = '_todos_completed_by';
	public static $post_type    = 'todo';

	public function __construct() {

		// Custom post type for todos
		add_action( 'init', array( $this, 'register_post_type' ) );

		// Widget constructor
		$widget_ops = array(
			'classname'   => 'todos',
			'description' => __( 'Shared tasklist', 'todos' ),
		);
		parent::__construct( 'todos', __( 'ToDos', 'todos' ), $widget_ops );

		// Active widget check
		if ( is_active_widget( false, false, $this->id_base, true ) ) {

			// AJAX inline setup
			add_action( 'wp_head', array( $this, 'wp_head' ) );
			add_action( 'wp_footer', array( $this, 'wp_footer' ) );

			// Scripts and styles
			if ( ! is_admin() ) {
				add_action( 'wp_enqueue_scripts', array( $this, 'wp_enqueue_scripts' ) );
			}

			// AJAX request handling
			add_action( 'wp_ajax_todos_poll', array( 'ToDos_Widget', 'poll' ) );
			add_action( 'wp_ajax_todos_check', array( 'ToDos_Widget', 'check' ) );

			// Scripts and styles
			if ( ! is_admin() ) {
				add_action( 'wp_enqueue_scripts', array( $this, 'wp_enqueue_scripts' ) );
			}
		}
	}

	/**
	 * Register the todo custom post type
	 */
	public function register_post_type() {
		register_post_type(
			'todo',
			array(
				'labels' => array(
					'name'          => __( 'ToDos', 'todos' ),
					'singular_name' => __( 'ToDo', 'todos' ),
					'add_new_item'  => __( 'Add New ToDo', 'todos' ),
					'edit_item'     => __( 'Edit ToDo', 'todos' ),
					'new_item'      => __( 'New ToDo', 'todos' ),
				),
				'public' => true,
				'has_archive' => false,
				'supports' => array(
					'title',
					'author',
				),
			)
		);
	}

	/**
	 * Display widget
	 */
	public function widget( $args, $instance ) {
		$title = apply_filters( 'widget_title', $instance['title'] );

		echo $args['before_widget'];
		if ( ! empty( $title ) )
			echo $args['before_title'] . $title . $args['after_title'];
		echo $args['after_widget'];
	}

	/**
	 * Display widget options form
	 */
	public function form( $instance ) {
		if ( isset( $instance['title'] ) ) {
			$title = $instance['title'];
		} else {
			$title = __( 'New title', 'todos' );
		}
		?>
		<p>
		<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title:' ); ?></label>
		<input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<?php
	}

	/**
	 * Update widget options form
	 */
	public function update( $new_instance, $old_instance ) {
		$instance = array();
		$instance['title'] = ( ! empty( $new_instance['title'] ) ) ? strip_tags( $new_instance['title'] ) : '';

		return $instance;
	}

	/**
	 * Add the ajaxurl
	 */
	public function wp_head() {
		?>
		<script type="text/javascript">
		var ToDo = ToDo || {};
		ToDo.ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
		</script>
		<?php
	}

	/**
	 * Add templates and priming data
	 */
	public static function wp_footer() {
		?>
		<script type="html/template" id="todos-template">
		<?php
		if ( is_user_logged_in() ) {
			?>
			<input type="checkbox" id="todos-todo-{{- id }}" class="on-completion" {{- checked }}>
			{{ if ( completedBy ) { }}
			<span class="completed completed-by-{{- completedBy.username }}">
			{{ } }}{{= title }}{{ if ( completedBy ) { }}
			</span>
			<br /><img src="{{= completedBy.avatar }}" class="avatar avatar-16 photo" height="16" width="16" /> {{= completedBy.username }}
			{{ } }}
			<?php
		} else {
			?>
			<input type="checkbox" id="todos-todo-{{- id }}" {{= checked }} disabled="disabled"> {{= title }}
			<?php
		}
		?>
		</script>

		<div style="display:none;" id="todos-data"><?php
		// Prime for widgets with existing todos
		$todos = self::get_todos_since();
		echo json_encode( $todos, JSON_UNESCAPED_SLASHES );
		?></div>

		<div style="display:none;" id="todos-current-user"><?php
		// Set the current user for use in Model updates
		if ( is_user_logged_in() ) {
			$current_user = wp_get_current_user();
		} else {
			$current_user = false;
		}
		$userdata = self::userdata( $current_user );
		echo json_encode( $userdata, JSON_UNESCAPED_SLASHES );
		?></div>
		<?php
	}

	/**
	 * Add plugin scripts and styles
	 */
	public function wp_enqueue_scripts() {
		wp_enqueue_script( 'backbone', null, array( 'jquery' ) );
		wp_enqueue_script( 'todos', plugins_url( 'todos/js/app.js' ), array( 'jquery', 'backbone' ), null, true );
		wp_enqueue_style( 'todos', plugins_url( 'todos/todos.css' ) );
	}

	public static function poll() {
		$todos = array();
		if ( isset( $_GET['since'] ) ) {
			// Allow some clock fudging and convert from JS Date.now() milliseconds
			$since = absint( substr( $_GET['since'], 0, 10 ) ) - 3;

			// Avoid loading enormous intervals just in case
			$min = time() - 24 * 60 * 60;
			if ( $since < $min ) {
				$since = $min;
			}

			$todos = self::get_todos_since( $since );
		}

		wp_send_json_success( $todos );
	}

	public static function check() {
		$current_user = wp_get_current_user();
		if ( is_user_logged_in() ) {
			$todo_id = absint( $_GET['id'] );
			$checked = $_GET['checked'] == 'checked' ? true : false;

			if ( get_post_type( $todo_id ) !== self::$post_type ) {
				wp_send_json_error();
			}

			$current = get_post_meta( $todo_id, self::$completed_by, true );

			// Only allow if not already claimed
			if ( $checked && $current === '' ) {
				update_post_meta( $todo_id, self::$completed_by, array(
					'user_id' => $current_user->ID,
					'at'      => time(),
				) );
			} elseif ( ! $checked ) {
				delete_post_meta( $todo_id, self::$completed_by );
			}

			// Bump last updated time on post
			wp_update_post( array(
				'ID' => $todo_id,
			) );

			$todos = self::format( array( get_post( $todo_id ) ) );
			wp_send_json_success( $todos );
		} else {
			wp_send_json_error();
		}
	}
	
	public static function get_todos_since( $since = 0 ) {
		$args = array(
			'post_type'      => 'todo',
			'posts_per_page' => -1,
			'order'          => 'ASC',
		);
		if ( empty( $since ) ) {
			$posts = get_posts( $args );
		} else {
			global $todos_since;
			$todos_since = $since;

			// Don't suppress filters
			$args['suppress_filters'] = false;

			add_filter( 'posts_where', array( 'ToDos_Widget', 'posts_where' ) );
			$posts = get_posts( $args );
			remove_filter( 'posts_where', array( 'ToDos_Widget', 'posts_where' ) );
		}

		$todos = self::format( $posts );

		wp_reset_postdata();

		return $todos;
	}

	public static function posts_where( $where = '' ) {
		global $wpdb, $todos_since;

		if ( $todos_since > 0 ) {
			$holdoff = 3; // Give posts time to set meta on insert or update
			$since = date( 'Y-m-d H:i:s', $todos_since );      // Begin range
			$until = date( 'Y-m-d H:i:s', time() - $holdoff ); // End range

			$where .= $wpdb->prepare( " AND post_modified_gmt >= %s AND post_modified_gmt < %s", $since, $until );
		}
		return $where;
	}

	/**
	 * Format an array of posts as todo Models
	 */
	public static function format( $posts ) {
		$todos = array();
		foreach ( $posts as $post ) {
			$meta = get_post_meta( $post->ID, self::$completed_by, true );
			if ( $meta !== '' ) {
				$user_id = $meta['user_id'];
				$at      = $meta['at'];
			} else {
				$user_id = '';
				$at      = 0;
			}
			$checked = ( $user_id === '' ) ? '' : 'checked';

			// Get a little user data
			$user = get_user_by( 'id', $user_id );
			$userdata = self::userdata( $user );

			$todo = array(
				'id'           => $post->ID,
				'title'        => utf8_encode( apply_filters( 'the_title', $post->post_title ) ),
				'checked'      => $checked,
				'completedBy'  => $userdata,
				'latestChange' => $at,
			);
			$todos[] = $todo;
		}
		return $todos;
	}

	/**
	 * Format a user as todo Model user data
	 */
	public static function userdata( $user ) {
		$userdata = false;
		if ( ! empty( $user ) ) {
			// Pass the avatar URL
			$avatar = get_avatar( $user->user_email, 16 );
			preg_match("/src='(.*?)'/i", $avatar, $matches);
			if ( isset( $matches[1] ) ) {
				$avatar = $matches[1];
			} else {
				$avatar = '';
			}

			// Create the userdata array
			$userdata = array(
				'username' => $user->user_nicename,
				'avatar'   => $avatar,
			);
		}
		return $userdata;
	}
}
