<?php
function wpwhosonline_enqueue() {
	add_action( 'wp_head', 'wpwhosonline_pageoptions_js', 20 );

	wp_enqueue_script( 'wpwhosonline', plugins_url('wp-whos-online.js', __FILE__), array('jquery'), 1 );
	wp_enqueue_style(  'wpwhosonline_css', plugins_url('wp-whos-online.css', __FILE__), null, 1 );
}
add_action('wp_enqueue_scripts', 'wpwhosonline_enqueue');

// our own ajax call
add_action( 'wp_ajax_wpwhosonline_ajax_update', 'wpwhosonline_ajax_update' );

// hook into p2 ajax calls, if they're there
add_action( 'wp_ajax_prologue_latest_posts', 'wpwhosonline_update' );
add_action( 'wp_ajax_prologue_latest_comments', 'wpwhosonline_update' );

/**
 * Update a user's "last online" timestamp.
 */
function wpwhosonline_update() {
	if( !is_user_logged_in() )
		return null;

	global $user_ID;

	update_user_meta( $user_ID, 'wpwhosonline_timestamp', time() );
}//end wpwhosonline_update
add_action('template_redirect', 'wpwhosonline_update');

/**
 * Echo json listing all authors who have had their "last online" timestamp updated
 * since the client's last update.
 */
function wpwhosonline_ajax_update() {
	global $wpdb;

	// update timestamp of user who is checking
	wpwhosonline_update();

	$load_time = strtotime($_GET['load_time'] . ' GMT');
	$users = wpwhosonline_recents( "meta_value=$load_time" );

	if( count($users) == 0 ) {
		die( '0' );
	}

	$now = time();

	$latest = 0;
	$return = array();
	foreach($users as $user) {
		$row = array();

		$last_online_ts = get_user_meta( $user->ID, 'wpwhosonline_timestamp', true );
		if( $last_online_ts > $latest )
			$latest = $last_online_ts;

		$row['user_id'] = $user->ID;
		$row['html'] = wpwhosonline_user( $last_online_ts, $user );
		$row['timestamp'] = $last_online_ts;

		$return[] = $row;
	}

	echo json_encode( array('users' => $return, 'latestupdate' => gmdate('Y-m-d H:i:s', $latest)) );
	exit;
}

function wpwhosonline_pageoptions_js() {
	global $page_options;
?><script type='text/javascript'>
var wpwhosonline = {
	'ajaxUrl': "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>",
	'wpwhosonlineLoadTime': "<?php echo gmdate( 'Y-m-d H:i:s' ); ?>",
	'getwpwhosonlineUpdate': '0',
	'isFirstFrontPage': "<?php echo is_home(); ?>"
};
</script><?php
}

function wpwhosonline_usersort( $a, $b ) {
	$ts_a = get_user_meta( $a->ID, 'wpwhosonline_timestamp', true );
	$ts_b = get_user_meta( $b->ID, 'wpwhosonline_timestamp', true );

	if( $ts_a == $ts_b ) {
		return 0;
	}

	return ($ts_a < $ts_b) ? 1 : -1;
}

function wpwhosonline_recents( $args = array() ) {
	$args = wp_parse_args( $args, array(
		'meta_key' => 'wpwhosonline_timestamp',
		'meta_value' => time() - 604800, // 1 week
		'meta_compare' => '>',
		'count_total' => false,
	));

	$users = get_users( $args );
	foreach( $users as $user ) {
		// grab all these values, or you'll anger usort by modifying
		// an array mid-execution.
		get_user_meta( $user->ID, 'wpwhosonline_timestamp', true );
	}
	usort( $users, 'wpwhosonline_usersort' );

	return $users;
}

function wpwhosonline_list_authors() {
	$users = wpwhosonline_recents();

	$html = '';

	foreach( $users as $user ) {
		$last_online_ts = get_user_meta( $user->ID, 'wpwhosonline_timestamp', true );
		$item = wpwhosonline_user( $last_online_ts, $user );
		$class = wpwhosonline_class( $last_online_ts,$user );	
		$item = '<li id="wpwhosonline-' . $user->ID . '" class="wpwhosonline-row ' . $class . '" data-wpwhosonline="' .
			esc_attr( $last_online_ts ) . '">' . $item . '<br />';
		if($user->ID != 1) {
			$html .= $item;
		}
	}
	echo $html.'</div></li>';
}

/**
 * Return HTML for a single blog user for the widget.
 *
 * @uses apply_filters() Calls 'wpwhosonline_author_link' on the author link element
 * @return string HTML for the user row
 */
function wpwhosonline_user( $last_online_ts, $user ) {
	$sql = mysql_query("SELECT * FROM tumblr_account WHERE user_id = ".$user->ID."");
	$row = mysql_fetch_array($sql);	
	$name = $user->display_name;
	$avatar = '<img src="'.$row['tumblr_avatar'].'" title="'.$name.'" alt="'.$name.'" width="100" />'. $user->display_name;

	$link =  $name ;

	$link = apply_filters( 'wpwhosonline_author_link', $link, $user );

	// this should always exist; we queried using this meta
	if ( ! $last_online_ts ) {
		continue;
	}

	$now = time();
	if ( $now - $last_online_ts < 120 ) {
		$last_online = 'Online now!';
	} else {
		$last_online = human_time_diff( $now, $last_online_ts ) . ' ago';
	}

	$last_online_title = date_i18n( get_option('date_format') . ' ' . get_option('time_format'), $last_online_ts );

	if ( $last_online ) {
		$last_online = '<span title="Last online: ' . esc_attr( $last_online_title ) . '">' . $last_online . '</a>';
	}
	$current_user = wp_get_current_user();
	$sql = mysql_query("SELECT * FROM tumblr_account WHERE user_id = ".$user->ID."");
	$row = mysql_fetch_array($sql);	
	$token = substr(md5($user->ID),0, 10).'a'.substr(md5($link),0, 9).'f'.substr(md5($link),10, 10);	
	return '<div class="image"><a target="_blank" href="'.get_bloginfo('home').'/tumblr/tumblrlink.php?follow='. $link .'&id=' . $current_user->ID . '&token='.$token.'" title="'.$user->display_name. '" class="username">'.$avatar .'</a><br /><span>'. number_format($row['points'], 0, '', ',').' points</span>';
}

function wpwhosonline_class( $lastonline, $user ) {
	$diff = time() - $lastonline;
	if( $diff > 7200 ) {
		mysql_query("UPDATE tumblr_account SET online = 'no' WHERE user_id = ".$user->ID."");
		return 'wpwhosonline-ancient';
	} elseif( $diff > 600 ) {
		mysql_query("UPDATE tumblr_account SET online = 'no' WHERE user_id = ".$user->ID."");
		return 'wpwhosonline-recent';
	} else {
		mysql_query("UPDATE tumblr_account SET online = 'yes' WHERE user_id = ".$user->ID."");
		return 'wpwhosonline-active';
	}
}

function widget_wpwhosonline_init() {

  // Check for the required plugin functions. This will prevent fatal
  // errors occurring when you deactivate the dynamic-sidebar plugin.
  if ( !function_exists('wp_register_sidebar_widget') )
    return;

  // This is the function that outputs the Authors code.
  function widget_wpwhosonline($args) {
    extract($args);	
	echo $before_widget . $before_title . "Users" . $after_title;
?>
<ul class="wpwhosonline-list pics-top-users">
<?php wpwhosonline_list_authors(); ?>
</ul>
<?php
    echo $after_widget;
  }

  // This registers our widget so it appears with the other available
  // widgets and can be dragged and dropped into any active sidebars.
  wp_register_sidebar_widget( 'widget_wpwhosonline', "Who's Online", 'widget_wpwhosonline' );
}

// Run our code later in case this loads prior to any required plugins.
add_action('plugins_loaded', 'widget_wpwhosonline_init');
