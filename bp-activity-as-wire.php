<?php

/**
 * Plugin Name: BuddyPress Activity as Wire
 * Plugin URI: http://buddydev.com/plugins/bp-activity-as-wire/
 * Version: 1.0.2
 * Author: Brajesh Singh ( BuddyDev )
 * Author URI: http://buddydev.com
 * License: GPL
 * Description: BuddyPress Activity as wire allows you to use the @mention feature of BuddyPress activity to emulate the wall/wire experience for the users
 */
class BP_Activity_Wire_Helper {

	private static $instance = null;

	private function __construct() {

		$this->setup();
	}

	public static function get_instance() {

		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function setup() {
		
		//show post form
		add_action( 'bp_after_member_activity_post_form', array( $this, 'show_post_form' ) );

		add_filter( 'gettext', array( $this, 'translate_whats_new_text' ), 10, 3 );

		add_action( 'wp_ajax_post_update', array( $this, 'post_update' ), 0 ); //high priority

		add_filter( 'bp_activity_new_update_action', array( $this, 'filter_update_action' ), 10, 2 );
	}

	public function show_post_form() {

		if ( is_user_logged_in() && bp_is_user() && ! bp_is_my_profile() && ( ! bp_current_action() || bp_is_current_action( 'just-me' ) || bp_is_current_action( 'mentions' ) ) ) {
			bp_locate_template( array( 'activity/post-form.php' ), true );
		}
	}

	/**
	 * Filter and translate text
	 * @param type $translated_text
	 * @param type $text
	 * @param type $domain
	 * @return type
	 */
	public function translate_whats_new_text( $translated_text, $text, $domain ) {

		if ( $text == "What's new, %s?" && $domain == 'buddypress' && ! bp_is_my_profile() && bp_is_user() ) {

			$translated_text = sprintf( __( "Write something to %s?", 'buddypress' ), bp_get_displayed_user_fullname() );
		}
		return $translated_text;
	}

	public function post_update() {

		if ( ! bp_is_user() || bp_is_my_profile() ) {
			return; //let the theme's ajax handler deal with it
		}

		//check nonce
		check_admin_referer( 'post_update', '_wpnonce_post_update' );

		if ( ! is_user_logged_in() ) {
			echo '-1';
			exit( 0 );
		}

		if ( empty( $_POST['content'] ) ) {
			echo '-1<div id="message"><p>' . __( 'Please enter some content to post.', 'buddypress' ) . '</p></div>';
			exit( 0 );
		}

		if ( empty( $_POST['object'] ) && function_exists( 'bp_activity_post_update' ) ) {

			//let us prepend @mention thing
			$content = '@' . bp_get_displayed_user_username() . ' ' . $_POST['content'];

			//let us get the last activity id, we will use it to reset user's last activity
			$last_update = bp_get_user_meta( bp_loggedin_user_id(), 'bp_latest_update', true );
			add_filter( 'bp_activity_new_update_action', array( $this, 'filter_update_action_before_write' ) );

			$activity_id = bp_activity_post_update( array( 'content' => $content ) );

			remove_filter( 'bp_activity_new_update_action', array( $this, 'filter_update_action_before_write' ) );

			if ( $activity_id ) {
				bp_activity_update_meta( $activity_id, 'is_wire_post', 1 ); //let us remember it for future
				//for 2.0 Let us add the mentioned user in the meta, so in future if we plan eo extend the wall beyond mention, we can do that easily
				bp_activity_update_meta( $activity_id, 'wire_user_id', bp_displayed_user_id() ); //let us remember it for future
			}
		}

		//restore the last update

		bp_update_user_meta( get_current_user_id(), 'bp_latest_update', $last_update );

		if ( ! $activity_id ) {
			echo '-1<div id="message"><p>' . __( 'There was a problem posting your update, please try again.', 'buddypress' ) . '</p></div>';
			exit( 0 );
		}

		if ( bp_has_activities( 'include=' . $activity_id ) ) :
		?>
			<?php while ( bp_activities() ) : bp_the_activity(); ?>
				<?php bp_locate_template( array( 'activity/entry.php' ), true ) ?>
			<?php endwhile; ?>
			<?php

		endif;
		exit( 0 );
	}

	public function filter_update_action( $action, $activity ) {

		//check if this is a wall post?
		if ( ! bp_activity_get_meta( $activity->id, 'is_wire_post', true ) ) {
			return $action;
		}

		//if we are here, It must be a wire post
		//since bp 2.0, I have added a meta key to store the user id on whose wall we are posting

		if ( ! $user_id = bp_activity_get_meta( $activity->id, 'wire_user_id', true ) ) {
			//before 2.0, since the id did not exist, we will be using the @mention finding username

			$usernames = bp_activity_find_mentions( $activity->content );

			if ( is_array( $usernames ) ) {
				$usernames = array_pop( $usernames );
			}

			if ( $usernames ) {
				$user_id = bp_core_get_userid( $usernames );
			}
		}

		if ( ! $user_id ) { //we don't have info about the person on whose wall this poat was made
			return $action;
		}

		//if we are here, let us say something nice really nice
		$action = sprintf( __( '%s posted on %s\'s wall', 'buddypress' ), bp_core_get_userlink( $activity->user_id ), bp_core_get_userlink( $user_id ) );

		return $action;
	}

	//filters activity action to say posted on xyz's wall
	public function filter_update_action_before_write( $action ) {
		return  sprintf( __( '%s posted on %s\'s wall', 'buddypress' ), bp_core_get_userlink( get_current_user_id() ), bp_core_get_userlink( bp_displayed_user_id() ) );
	}

}

//init
BP_Activity_Wire_Helper::get_instance();
