<?php
/**
 * Plugin Name: BuddyPress Activity as Wire
 * Plugin URI: http://buddydev.com/plugins/bp-activity-as-wire/
 * Version: 1.0.1
 * Author: Brajesh Singh ( BuddyDev )
 * Author URI: http://buddydev.com
 * License: GPL
 * Description: BuddyPress Activity as wire allows you to use the @mention feature of BuddyPress activity to emulate the wall/wire experience for the users
 */

//step1: show activity post form on other user's profile
//we can use the 'bp_before_member_activity_post_form' or 'bp_after_member_activity_post_form' action here
function devb_aawire_show_post_form(){
   
    if ( is_user_logged_in() && bp_is_user() && !bp_is_my_profile() && (! bp_current_action() || bp_is_current_action( 'just-me') || bp_is_current_action( 'mentions' ) )  )
            bp_locate_template( array( 'activity/post-form.php'), true ) ;
}
add_action( 'bp_after_member_activity_post_form', 'devb_aawire_show_post_form');

//Step2: //we will translate what's new %s to write something about %s


function devb_aawire_translate_whats_new_text( $translated_text, $text, $domain ){
    
    if( $text == "What's new, %s?" && $domain == 'buddypress' && !bp_is_my_profile() && bp_is_user() ){
        
       $translated_text = sprintf( __( "Write something to %s?", 'buddypress' ), bp_get_displayed_user_fullname() ); 
       
    }
    return $translated_text;
}
add_filter( 'gettext', 'devb_aawire_translate_whats_new_text', 10, 3 );

//Step3: Remove buddypress functions that save the activity update and hook our custom update function
 
function devb_aawire_update_activity_posting_hooks() {
    //pre 1.7 themes that copied it
    if( has_action( 'wp_ajax_post_update', 'bp_dtheme_post_update' ) )
            remove_action('wp_ajax_post_update', 'bp_dtheme_post_update');
    //if the theme is using bp1.7+ template pack support
    if( has_action( 'wp_ajax_post_update', 'bp_legacy_theme_post_update' ) )
            remove_action('wp_ajax_post_update', 'bp_legacy_theme_post_update');
    
    //add our own handler for activity posting
    add_action( 'wp_ajax_post_update', 'devb_aawire_post_update' );
}
 
add_action( 'bp_init', 'devb_aawire_update_activity_posting_hooks' );
 
/* AJAX update posting */
 
function devb_aawire_post_update() {
    global $bp;
    $is_wire_post = false;
    /* Check the nonce */
     check_admin_referer( 'post_update', '_wpnonce_post_update' );

    if ( !is_user_logged_in() ) {
        echo '-1';
        exit(0);
     }
 
    if ( empty($_POST['content'] ) ) {
        echo '-1<div id="message"><p>' . __('Please enter some content to post.', 'buddypress') . '</p></div>';
        exit( 0 );
     }

    if ( empty( $_POST['object'] ) && function_exists( 'bp_activity_post_update' ) ) {

        //this is what I have changed
        if ( !bp_is_my_profile() && bp_is_user() ){
            $content = '@' . bp_get_displayed_user_username() . ' ' . $_POST['content'];
            $is_wire_post = true;
        }else{
         
            $content = $_POST['content'];

        }    
        //let us get the last activity id, we will use it to reset user's last activity
        $last_update = bp_get_user_meta( bp_loggedin_user_id(), 'bp_latest_update', true );
        if( $is_wire_post )
            add_filter ( 'bp_activity_new_update_action', 'devb_aawire_filter_action' );
        
        $activity_id = bp_activity_post_update( array( 'content' => $content ) );
        
        if( $is_wire_post ) {
            remove_filter ( 'bp_activity_new_update_action', 'devb_aawire_filter_action' );

            //add activity meta to remember that it was a wire post and we may use it in future
            
         if( $activity_id )
             bp_activity_update_meta ($activity_id, 'is_wire_post', 1 );//let us remember it for future
             //for 2.0 Let us add the mentioned user in the meta, so in future if we plan eo extend the wall beyond mention, we can do that easily
                 bp_activity_update_meta ( $activity_id, 'wire_user_id', bp_displayed_user_id() );//let us remember it for future
         
        }   
         //reset the last update

         bp_update_user_meta(get_current_user_id(), 'bp_latest_update', $last_update );

    //end of my changes
     } elseif ($_POST['object'] == 'groups') {
        
         if ( !empty($_POST['item_id'] ) && function_exists( 'groups_post_update' ) )
            $activity_id = groups_post_update( array( 'content' => $_POST['content'], 'group_id' => $_POST['item_id'] ) );
     
        
     } else{
         
        $activity_id = apply_filters('bp_activity_custom_update', $_POST['object'], $_POST['item_id'], $_POST['content']);
     }
     
     
    if ( !$activity_id ) {
        echo '-1<div id="message"><p>' . __('There was a problem posting your update, please try again.', 'buddypress') . '</p></div>';
        exit(0);
    }
 
    if ( bp_has_activities( 'include=' . $activity_id ) ) :
    ?>
     <?php while ( bp_activities() ) : bp_the_activity(); ?>
        <?php bp_locate_template( array( 'activity/entry.php' ), true ) ?>
     <?php endwhile; ?>
     <?php

    endif;
 exit(0);
}
//filters activity action to say posted on xyz's wall
function devb_aawire_filter_action( $action ){
    $action = sprintf( __( '%s posted on %s\'s wall', 'buddypress' ), bp_core_get_userlink( get_current_user_id() ), bp_core_get_userlink( bp_displayed_user_id() ) );
    return $action;
}

//for BuddyPress 2.0 dynamic action astring, we need to filter the update action and see if it is a wire/wall post

 add_filter( 'bp_activity_new_update_action',  'devb_aawire_update_activity_action', 10, 2 );
 
 function devb_aawire_update_activity_action( $action, $activity ){
     //check if this is a wall post?
     if( !bp_activity_get_meta( $activity->id, 'is_wire_post', true ) )
          return $action;
     
     //if we are here, It must be a wire post
     //since bp 2.0, I have added a meta key to store the user id on whose wall we are posting
     
     if( !$user_id = bp_activity_get_meta( $activity->id, 'wire_user_id', true) ){
          //before 2.0, since the id did not exist, we will be using the @mention finding username
         $usernames =  bp_activity_find_mentions ( $activity->content );
         
         if( is_array( $usernames ) )
             $usernames = array_pop ( $usernames );
         
         if( $usernames )
             $user_id = bp_core_get_userid ( $usernames );
         
     }
     
     if( !$user_id ) //we don't have info about the person on whose wall this poat was made
         return $action;
     
     //if we are here, let us say something nice really nice
      $action = sprintf( __( '%s posted on %s\'s wall', 'buddypress' ), bp_core_get_userlink( $activity->user_id ), bp_core_get_userlink( $user_id ) );
    
    
     
     return $action;
     
 }