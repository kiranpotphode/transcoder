<?php /*

**************************************************************************

Retranscode media

**************************************************************************/

class RetranscodeMedia {
	public $menu_id;

	// Functinallity initialization
	public function __construct() {

		add_action( 'admin_menu',                       	array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts',               	array( $this, 'admin_enqueues' ) );
		add_action( 'wp_ajax_regeneratethumbnail',        	array( $this, 'ajax_process_retranscode_request' ) );
		add_filter( 'media_row_actions',                   	array( $this, 'add_media_row_action' ), 10, 2 );
		add_action( 'admin_head-upload.php',              	array( $this, 'add_bulk_actions_via_javascript' ) );
		add_action( 'admin_action_bulk_retranscode_media', 	array( $this, 'bulk_action_handler' ) ); // Top drowndown
		add_action( 'admin_action_-1',                     	array( $this, 'bulk_action_handler' ) ); // Bottom dropdown (assumes top dropdown = default value)

		// Allow people to change what capability is required to use this feature
		$this->capability = apply_filters( 'retranscode_media_cap', 'manage_options' );
	}


	// Register the management page
	public function add_admin_menu() {
		add_submenu_page(
			'rt-transcoder',
			'Transcoder',
			'Transcoder',
		    'manage_options',
		    'rt-transcoder',
		    array( 'RT_Transcoder_Admin', 'settings_page' )
		);
		$this->menu_id = add_submenu_page(
			'rt-transcoder',
			__( 'Retranscode Media' , 'transcoder'),
			__( 'Retranscode Media', 'transcoder' ),
		    $this->capability,
		    'rt-retranscoder',
			array($this, 'retranscode_interface')
		);
	}


	// Enqueue the needed Javascript and CSS
	public function admin_enqueues( $hook_suffix ) {
		if ( $hook_suffix != $this->menu_id )
			return;

		// WordPress 3.1 vs older version compatibility
		if ( wp_script_is( 'jquery-ui-widget', 'registered' ) )
			wp_enqueue_script( 'jquery-ui-progressbar', plugins_url( 'js/jquery.ui.progressbar.min.js', __FILE__ ), array( 'jquery-ui-core', 'jquery-ui-widget' ), '1.8.6' );
		else
			wp_enqueue_script( 'jquery-ui-progressbar', plugins_url( 'js/jquery.ui.progressbar.min.1.7.2.js', __FILE__ ), array( 'jquery-ui-core' ), '1.7.2' );

		wp_enqueue_style( 'jquery-ui-retranscodemedia', plugins_url( 'css/jquery-ui-1.7.2.custom.css', __FILE__ ), array(), '1.7.2' );
	}


	// Add a "Retranscode Media" link to the media row actions
	public function add_media_row_action( $actions, $post ) {
		if ( ( 'audio/' != substr( $post->post_mime_type, 0, 6 ) && 'video/' != substr( $post->post_mime_type, 0, 6 ) ) || ! current_user_can( $this->capability ) )
			return $actions;

		$url = wp_nonce_url( admin_url( 'admin.php?page=rt-retranscoder&goback=1&ids=' . $post->ID ), 'rt-retranscoder' );
		$actions['retranscode_media'] = '<a href="' . esc_url( $url ) . '" title="' . esc_attr( __( "Retranscode this single media", 'transcoder' ) ) . '">' . __( 'Retranscode Media', 'transcoder' ) . '</a>';

		return $actions;
	}


	// Add "Retranscode Media" to the Bulk Actions media dropdown
	public function add_bulk_actions( $actions ) {
		$delete = false;
		if ( ! empty( $actions['delete'] ) ) {
			$delete = $actions['delete'];
			unset( $actions['delete'] );
		}

		$actions['bulk_retranscode_media'] = __( 'Retranscode Media', 'transcoder' );

		if ( $delete )
			$actions['delete'] = $delete;

		return $actions;
	}


	// Add new items to the Bulk Actions using Javascript
	// A last minute change to the "bulk_actions-xxxxx" filter in 3.1 made it not possible to add items using that
	public function add_bulk_actions_via_javascript() {
		if ( ! current_user_can( $this->capability ) )
			return;
?>
		<script type="text/javascript">
			jQuery(document).ready(function($){
				$('select[name^="action"] option:last-child').before('<option value="bulk_retranscode_media"><?php echo esc_attr( __( 'Retranscode Media', 'transcoder' ) ); ?></option>');
			});
		</script>
<?php
	}


	// Handles the bulk actions POST
	public function bulk_action_handler() {
		if ( empty( $_REQUEST['action'] ) || ( 'bulk_retranscode_media' != $_REQUEST['action'] && 'bulk_retranscode_media' != $_REQUEST['action2'] ) )
			return;

		if ( empty( $_REQUEST['media'] ) || ! is_array( $_REQUEST['media'] ) )
			return;

		check_admin_referer( 'bulk-media' );

		$ids = implode( ',', array_map( 'intval', $_REQUEST['media'] ) );

		// Can't use wp_nonce_url() as it escapes HTML entities
		wp_redirect( add_query_arg( '_wpnonce', wp_create_nonce( 'rt-retranscoder' ), admin_url( 'admin.php?page=rt-retranscoder&goback=1&ids=' . $ids ) ) );
		exit();
	}


	// The user interface plus thumbnail regenerator
	public function retranscode_interface() {
		global $wpdb;

		?>

<div id="message" class="updated fade" style="display:none"></div>

<div class="wrap retranscodemedia">
	<h2><?php _e('Retranscode Media', 'transcoder'); ?></h2>

<?php

		// If the button was clicked
		if ( ! empty( $_POST['rt-retranscoder'] ) || ! empty( $_REQUEST['ids'] ) ) {
			// Capability check
			if ( ! current_user_can( $this->capability ) )
				wp_die( __( 'Cheatin&#8217; uh?' ) );

			// Form nonce check
			check_admin_referer( 'rt-retranscoder' );

			// Create the list of image IDs
			if ( ! empty( $_REQUEST['ids'] ) ) {
				$media = array_map( 'intval', explode( ',', trim( $_REQUEST['ids'], ',' ) ) );
				$ids = implode( ',', $media );
			} else {
				// Directly querying the database is normally frowned upon, but all
				// of the API functions will return the full post objects which will
				// suck up lots of memory. This is best, just not as future proof.
				if ( ! $media = $wpdb->get_results( "SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND ( post_mime_type LIKE 'audio/%' OR post_mime_type LIKE 'video/%' ) ORDER BY ID DESC" ) ) {
					echo '	<p>' . sprintf( __( "Unable to find any media. Are you sure <a href='%s'>some exist</a>?", 'transcoder' ), admin_url( 'upload.php?post_mime_type=image,video' ) ) . "</p></div>";
					return;
				}

				// Generate the list of IDs
				$ids = array();
				foreach ( $media as $each )
					$ids[] = $each->ID;
				$ids = implode( ',', $ids );
			}

			echo '	<p>' . __( "Please be patient while the media are getting sent for the retranscoding. This can take a while if your server is slow (inexpensive hosting) or if you have many media files. Do not navigate away from this page until this script is done or the media files wont get sent for the retranscoding. You will be notified via this page when the operation is completed.", 'transcoder' ) . '</p>';

			$count = count( $media );

			$text_goback = ( ! empty( $_GET['goback'] ) ) ? sprintf( __( 'To go back to the previous page, <a href="%s">click here</a>.', 'transcoder' ), 'javascript:history.go(-1)' ) : '';
			$text_failures = sprintf( __( 'All done! %1$s media file(s) were successfully sent for retranscoding in %2$s seconds and there were %3$s failure(s). To try retranscoding the failed media again, <a href="%4$s">click here</a>. %5$s', 'transcoder' ), "' + rt_successes + '", "' + rt_totaltime + '", "' + rt_errors + '", esc_url( wp_nonce_url( admin_url( 'admin.php?page=rt-retranscoder&goback=1' ), 'transcoder' ) . '&ids=' ) . "' + rt_failedlist + '", $text_goback );
			$text_nofailures = sprintf( __( 'All done! %1$s media file(s) were successfully sent for retranscoding in %2$s seconds and there were 0 failures. %3$s', 'transcoder' ), "' + rt_successes + '", "' + rt_totaltime + '", $text_goback );
?>


	<noscript><p><em><?php _e( 'You must enable Javascript in order to proceed!', 'transcoder' ) ?></em></p></noscript>

	<div id="retranscodemedia-bar" style="position:relative;height:25px;">
		<div id="retranscodemedia-bar-percent" style="position:absolute;left:50%;top:50%;width:300px;margin-left:-150px;height:25px;margin-top:-9px;font-weight:bold;text-align:center;"></div>
	</div>

	<p><input type="button" class="button hide-if-no-js" name="retranscodemedia-stop" id="retranscodemedia-stop" value="<?php _e( 'Abort the Operation', 'transcoder' ) ?>" /></p>

	<h3 class="title"><?php _e( 'Debugging Information', 'transcoder' ) ?></h3>

	<p>
		<?php printf( __( 'Total Media: %s', 'transcoder' ), $count ); ?><br />
		<?php printf( __( 'Media Sent for Retranscoding: %s', 'transcoder' ), '<span id="retranscodemedia-debug-successcount">0</span>' ); ?><br />
		<?php printf( __( 'Failed While Sending: %s', 'transcoder' ), '<span id="retranscodemedia-debug-failurecount">0</span>' ); ?>
	</p>

	<ol id="retranscodemedia-debuglist">
		<li style="display:none"></li>
	</ol>

	<script type="text/javascript">
	// <![CDATA[
		jQuery(document).ready(function($){
			var i;
			var rt_media = [<?php echo $ids; ?>];
			var rt_total = rt_media.length;
			var rt_count = 1;
			var rt_percent = 0;
			var rt_successes = 0;
			var rt_errors = 0;
			var rt_failedlist = '';
			var rt_resulttext = '';
			var rt_timestart = new Date().getTime();
			var rt_timeend = 0;
			var rt_totaltime = 0;
			var rt_continue = true;

			// Create the progress bar
			$("#retranscodemedia-bar").progressbar();
			$("#retranscodemedia-bar-percent").html( "0%" );

			// Stop button
			$("#retranscodemedia-stop").click(function() {
				rt_continue = false;
				$('#retranscodemedia-stop').val("<?php echo $this->esc_quotes( __( 'Stopping...', 'transcoder' ) ); ?>");
			});

			// Clear out the empty list element that's there for HTML validation purposes
			$("#retranscodemedia-debuglist li").remove();

			// Called after each resize. Updates debug information and the progress bar.
			function RetranscodeMediaUpdateStatus( id, success, response ) {
				$("#retranscodemedia-bar").progressbar( "value", ( rt_count / rt_total ) * 100 );
				$("#retranscodemedia-bar-percent").html( Math.round( ( rt_count / rt_total ) * 1000 ) / 10 + "%" );
				rt_count = rt_count + 1;

				if ( success ) {
					rt_successes = rt_successes + 1;
					$("#retranscodemedia-debug-successcount").html(rt_successes);
					$("#retranscodemedia-debuglist").append("<li>" + response.success + "</li>");
				}
				else {
					rt_errors = rt_errors + 1;
					rt_failedlist = rt_failedlist + ',' + id;
					$("#retranscodemedia-debug-failurecount").html(rt_errors);
					$("#retranscodemedia-debuglist").append("<li>" + response.error + "</li>");
				}
			}

			// Called when all images have been processed. Shows the results and cleans up.
			function RetranscodeMediaFinishUp() {
				rt_timeend = new Date().getTime();
				rt_totaltime = Math.round( ( rt_timeend - rt_timestart ) / 1000 );

				$('#retranscodemedia-stop').hide();

				if ( rt_errors > 0 ) {
					rt_resulttext = '<?php echo $text_failures; ?>';
				} else {
					rt_resulttext = '<?php echo $text_nofailures; ?>';
				}

				$("#message").html("<p><strong>" + rt_resulttext + "</strong></p>");
				$("#message").show();
			}

			// Regenerate a specified image via AJAX
			function RetranscodeMedia( id ) {
				$.ajax({
					type: 'POST',
					url: ajaxurl,
					data: { action: "regeneratethumbnail", id: id },
					success: function( response ) {
						if ( response !== Object( response ) || ( typeof response.success === "undefined" && typeof response.error === "undefined" ) ) {
							response = new Object;
							response.success = false;
							response.error = "<?php printf( esc_js( __( 'The resize request was abnormally terminated (ID %s). This is likely due to the image exceeding available memory or some other type of fatal error.', 'transcoder' ) ), '" + id + "' ); ?>";
						}

						if ( response.success ) {
							RetranscodeMediaUpdateStatus( id, true, response );
						}
						else {
							RetranscodeMediaUpdateStatus( id, false, response );
						}

						if ( rt_media.length && rt_continue ) {
							RetranscodeMedia( rt_media.shift() );
						}
						else {
							RetranscodeMediaFinishUp();
						}
					},
					error: function( response ) {
						RetranscodeMediaUpdateStatus( id, false, response );

						if ( rt_media.length && rt_continue ) {
							RetranscodeMedia( rt_media.shift() );
						}
						else {
							RetranscodeMediaFinishUp();
						}
					}
				});
			}

			RetranscodeMedia( rt_media.shift() );
		});
	// ]]>
	</script>
<?php
		}

		// No button click? Display the form.
		else {
?>
	<form method="post" action="">
<?php wp_nonce_field('rt-retranscoder') ?>

	<p><?php printf( __( "Use this tool to retranscode media for all media (Audio/Video) files that you have uploaded to your blog. This is useful if you've old media files which are not transcoding. Old thumbnails generated will be kept to avoid any broken images due to hard-coded URLs.", 'transcoder' ) ); ?></p>

	<p><?php printf( __( "You can regenerate specific media (rather than all media) from the <a href='%s'>Media</a> page. Hover over an media row and click the link to send just that one media for retranscoding or use the checkboxes and the &quot;Bulk Actions&quot; dropdown to send multiple media (WordPress 3.1+ only) for retranscode.", 'transcoder' ), admin_url( 'upload.php' ) ); ?></p>

	<p><?php _e( "Sending media for retranscoding is not reversible, your allowed bandwidth will get utilised for each media that you will be sending for the retranscoding.", 'transcoder' ); ?></p>

	<p><?php _e( 'To begin, just press the button below.', 'transcoder' ); ?></p>

	<p><input type="submit" class="button hide-if-no-js" name="rt-retranscoder" id="rt-retranscoder" value="<?php _e( 'Retranscode All Media', 'transcoder' ) ?>" /></p>

	<noscript><p><em><?php _e( 'You must enable Javascript in order to proceed!', 'transcoder' ) ?></em></p></noscript>

	</form>
<?php
		} // End if button
?>
</div>

<?php
	}


	// Process a single image ID (this is an AJAX handler)
	public function ajax_process_retranscode_request() {
		@error_reporting( 0 ); // Don't break the JSON result

		header( 'Content-type: application/json' );

		$id = (int) $_REQUEST['id'];
		$media = get_post( $id );

		if ( ! $media || 'attachment' != $media->post_type || ( 'audio/' != substr( $media->post_mime_type, 0, 6 ) && 'video/' != substr( $media->post_mime_type, 0, 6 ) ) )
			die( json_encode( array( 'error' => sprintf( __( 'Failed resize: %s is an invalid image ID.', 'transcoder' ), esc_html( $_REQUEST['id'] ) ) ) ) );

		if ( ! current_user_can( $this->capability ) )
			$this->die_json_error_msg( $media->ID, __( "Your user account doesn't have permission to resize images", 'transcoder' ) );

		// Check if media is already being transcoded

		if ( is_file_being_transcoded( $media->ID ) ) {
			$this->die_json_error_msg( $media->ID, sprintf( __( 'The media is already being transcoded', 'transcoder' ) ) );
		}

		/**
		 * Check if `_rt_transcoding_job_id` meta is present for the media
		 * if it's present then media won't get sent to the transcoder
		 * so we need to delete `_rt_transcoding_job_id` meta before we send
		 * media back for the retranscoding
		 */
		$already_sent = get_post_meta( $media->ID, '_rt_transcoding_job_id', true );

		if ( ! empty( $already_sent ) ) {
			$delete_meta = delete_post_meta( $media->ID, '_rt_transcoding_job_id' );
		}

		// Get the transcoder object
		$transcoder = new RT_Transcoder_Handler( $no_init = true );

		$attachment_meta['mime_type'] = $media->post_mime_type;

		// Send media for (Re)transcoding
		$sent = $transcoder->wp_media_transcoding( $attachment_meta, $media->ID );

		if ( ! $sent )
			$this->die_json_error_msg( $media->ID, __( 'Unknown failure reason.', 'transcoder' ) );

		die( json_encode( array( 'success' => sprintf( __( '&quot;%1$s&quot; (ID %2$s) was successfully resized in %3$s seconds.', 'transcoder' ), esc_html( get_the_title( $media->ID ) ), $media->ID, timer_stop() ) ) ) );
	}


	// Helper to make a JSON error message
	public function die_json_error_msg( $id, $message ) {
		die( json_encode( array( 'error' => sprintf( __( '&quot;%1$s&quot; (ID %2$s) failed to resize. The error message was: %3$s', 'transcoder' ), esc_html( get_the_title( $id ) ), $id, $message ) ) ) );
	}


	// Helper function to escape quotes in strings for use in Javascript
	public function esc_quotes( $string ) {
		return str_replace( '"', '\"', $string );
	}
}

// Start up this plugin
add_action( 'init', 'RetranscodeMedia' );
function RetranscodeMedia() {
	global $RetranscodeMedia;
	$RetranscodeMedia = new RetranscodeMedia();
}

?>